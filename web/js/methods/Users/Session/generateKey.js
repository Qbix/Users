Q.exports(function (Users, priv) {
	/**
	 * Methods for user sessions
	 * @module Users
	 * @class Users.Session
	 */

	// Guard against overlapping concurrent calls
	var _pendingGenerateKey = null;

	/**
	 * Generates a non-extractable session private key and a recovery key,
	 * saves both in IndexedDB, and tells the server to store their public keys.
	 * Handles Safari ITP by requesting Storage Access if needed.
	 * Safe for concurrent or repeated calls — only one generation runs at a time.
	 *
	 * @method generateKey
	 * @static
	 * @param {Function} callback Receives (err, event, key, response)
	 * @return {Promise|Boolean} Promise resolving to {sessionKey, response},
	 * or false if crypto.subtle is unavailable or key already exists.
	 */
	return Q.getter(function Users_Session_generateKey(callback) {
		// Prevent overlapping calls
		if (_pendingGenerateKey) {
			return _pendingGenerateKey.then(function (result) {
				Q.handle(callback, null, [null, null, result.sessionKey, result.response]);
				return result;
			});
		}

		_pendingGenerateKey = new Promise(function (resolveOuter, rejectOuter) {
			// Abort early if crypto.subtle not available
			if (!crypto || !crypto.subtle) {
				resolveOuter(false);
				return;
			}

			// Skip if already set on server
			if (Users.Session.publicKey) {
				Q.handle(callback, null, ["Users.Session.publicKey was already set on server"]);
				resolveOuter(false);
				return;
			}

			var info = Users.Session.key;
			var inIframe = (window.self !== window.top);
			if (inIframe) {
				Q.log("Users.Session: running inside iframe; keys may be transient unless Storage Access granted.");
			}

			// Step 0 — check/request Storage Access if available (Safari ITP)
			var accessPromise = Promise.resolve(true);
			if (document.requestStorageAccess && document.hasStorageAccess) {
				accessPromise = document.hasStorageAccess().then(function (already) {
					if (already) {
						Q.log("Users.Session: already has storage access");
						return true;
					}
					return document.requestStorageAccess().then(function () {
						Q.log("Users.Session: storage access granted");
						return true;
					}).catch(function (e) {
						Q.warn("Users.Session: storage access denied or failed " + e);
						return true;
					});
				}).catch(function (e) {
					Q.warn("Users.Session: hasStorageAccess check failed " + e);
					return true;
				});
			}

			// Step 1 — generate both session and recovery keys (non-extractable)
			accessPromise.then(function () {
				return Promise.all([
					crypto.subtle.generateKey(
						{ name: info.name, namedCurve: info.namedCurve },
						false,
						["sign", "verify"]
					),
					crypto.subtle.generateKey(
						{ name: info.name, namedCurve: info.namedCurve },
						false,
						["sign", "verify"]
					)
				]);
			}).then(function (keys) {
				var sessionKey = keys[0];
				var recoveryKey = keys[1];

				// Step 2 — save both keys in IndexedDB
				return new Promise(function (resolve, reject) {
					var storeName = "Q.Users.keys";
					Q.IndexedDB.open(Q.info.baseUrl, storeName, "id", function (err, db) {
						if (err) {
							reject(err);
							return;
						}
						var tx = db.transaction(storeName, "readwrite");
						var store = tx.objectStore(storeName);
						store.put({ id: "Users.Session", key: sessionKey });
						store.put({ id: "Users.Recovery", key: recoveryKey });
						tx.oncomplete = function () { resolve({ sessionKey: sessionKey, recoveryKey: recoveryKey }); };
						tx.onerror = reject;
					});
				});
			}).then(function (keysObj) {
				var sessionKey = keysObj.sessionKey;
				var recoveryKey = keysObj.recoveryKey;

				// Step 3 — export both public keys
				return Promise.all([
					crypto.subtle.exportKey("jwk", sessionKey.publicKey),
					crypto.subtle.exportKey("jwk", recoveryKey.publicKey)
				]).then(function (exports) {
					return {
						sessionKey: sessionKey,
						recoveryKey: recoveryKey,
						sessionPub: exports[0],
						recoveryPub: exports[1]
					};
				});
			}).then(function (bundle) {
				var sessionKey = bundle.sessionKey;
				var sessionPub = bundle.sessionPub;
				var recoveryPub = bundle.recoveryPub;

				// Step 4 — save on server
				return new Promise(function (resolveSave) {
					_save(sessionKey, sessionPub, recoveryPub, function (err, resp) {
						var errMsg = Q.firstErrorMessage(err, resp);
						Q.handle(callback, null, [errMsg, null, sessionKey, resp]);
						resolveSave({ sessionKey: sessionKey, response: resp });
					});
				}).then(function (result) {
					// Step 5 — optionally post to parent
					if (inIframe) {
						try {
							window.parent.postMessage({
								type: "Q.Users.recoveryKey.generated",
								payload: {
									recoveryKey: recoveryPub
								}
							}, "*");
							Q.log("Users.Session: posted recovery public key to parent via postMessage");
						} catch (e) {
							Q.warn("Users.Session: failed to postMessage keys to parent " + e);
						}
					}
					return result;
				});
			}).then(function (result) {
				_pendingGenerateKey = null;
				resolveOuter(result);
			}).catch(function (e) {
				Q.warn("Users.Session.generateKey error: " + e);
				_pendingGenerateKey = null;
				rejectOuter(e);
			});

			// Helper: sign and send to backend
			function _save(sessionKey, sessionPub, recoveryPub, callback) {
				var fields = {
					info: info,
					publicKey: sessionPub,
					recoveryKey: recoveryPub
				};
				Q.Users.sign(fields, function (err, signedFields) {
					Q.req("Users/key", ["saved"], function (err2) {
						Q.handle(callback, this, arguments);
					}, {
						method: "post",
						fields: signedFields
					});
				}, {
					key: sessionKey,
					fieldNames: ["info"]
				});
			}
		});

		return _pendingGenerateKey;
	});
});
Q.exports(function (Users, priv) {
	/**
	 * Methods for user sessions
	 * @module Users
	 * @class Users.Session
	 */

	var _pendingGenerateKey = null;

	/**
	 * Generates a non-extractable session private key and a recovery key,
	 * saves both in IndexedDB, and tells the server to store their public keys.
	 * If IndexedDB fails, marks the session key as ephemeral via publicKeyIsEphemeral.
	 * Safe for concurrent or repeated calls — only one generation runs at a time.
	 *
	 * @method generateKey
	 * @static
	 * @param {Function} callback Receives (err, event, key, response)
	 * @return {Promise|Boolean} Promise resolving to {sessionKey, response},
	 * or false if crypto.subtle is unavailable or key already exists.
	 */
	return Q.getter(function Users_Session_generateKey(callback) {
		if (_pendingGenerateKey) {
			return _pendingGenerateKey.then(function (result) {
				Q.handle(callback, null, [null, null, result.sessionKey, result.response]);
				return result;
			});
		}

		_pendingGenerateKey = new Promise(function (resolveOuter, rejectOuter) {
			if (!crypto || !crypto.subtle) {
				resolveOuter(false);
				return;
			}

			if (Users.Session.publicKey) {
				Q.handle(callback, null, ["Users.Session.publicKey was already set on server"]);
				resolveOuter(false);
				return;
			}

			var info = Users.Session.key;
			var publicKeyIsEphemeral = false;

			// Step 0 — check/request Storage Access (Safari ITP)
			var accessPromise = Promise.resolve(true);
			if (document.requestStorageAccess && document.hasStorageAccess) {
				accessPromise = Promise.race([
					document.hasStorageAccess().then(function (already) {
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
					}),
					new Promise(function (resolve) {
						setTimeout(function () {
							Q.warn("Users.Session: hasStorageAccess timed out, continuing");
							resolve(true);
						}, 500);
					})
				]);
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

				// Step 2 — attempt to store keys in IndexedDB
				return new Promise(function (resolve) {
					var storeName = "Q.Users.keys";
					Q.IndexedDB.open(Q.info.baseUrl, storeName, "id", function (err, db) {
						if (err || !db) {
							Q.warn("Users.Session: IndexedDB unavailable, marking key ephemeral");
							publicKeyIsEphemeral = true;
							resolve({ sessionKey: sessionKey, recoveryKey: recoveryKey });
							return;
						}
						try {
							var tx = db.transaction(storeName, "readwrite");
							var store = tx.objectStore(storeName);
							store.put({ id: "Users.Session", key: sessionKey });
							store.put({ id: "Users.Recovery", key: recoveryKey });
							tx.oncomplete = function () {
								Q.log("Users.Session: keys saved to IndexedDB successfully");
								resolve({ sessionKey: sessionKey, recoveryKey: recoveryKey });
							};
							tx.onerror = function (e) {
								Q.warn("Users.Session: IndexedDB write failed, marking key ephemeral");
								publicKeyIsEphemeral = true;
								resolve({ sessionKey: sessionKey, recoveryKey: recoveryKey });
							};
						} catch (e) {
							Q.warn("Users.Session: IndexedDB exception, marking key ephemeral " + e);
							publicKeyIsEphemeral = true;
							resolve({ sessionKey: sessionKey, recoveryKey: recoveryKey });
						}
					});
				}).then(function (result) {
					result.publicKeyIsEphemeral = publicKeyIsEphemeral;
					return result;
				});
			}).then(function (keysObj) {
				var sessionKey = keysObj.sessionKey;
				var recoveryKey = keysObj.recoveryKey;
				var publicKeyIsEphemeral = keysObj.publicKeyIsEphemeral;

				// Step 3 — export both public keys
				return Promise.all([
					crypto.subtle.exportKey("jwk", sessionKey.publicKey),
					crypto.subtle.exportKey("jwk", recoveryKey.publicKey)
				]).then(function (exports) {
					return {
						sessionKey: sessionKey,
						recoveryKey: recoveryKey,
						sessionPub: exports[0],
						recoveryPub: exports[1],
						publicKeyIsEphemeral: publicKeyIsEphemeral
					};
				});
			}).then(function (bundle) {
				var sessionKey = bundle.sessionKey;
				var sessionPub = bundle.sessionPub;
				var recoveryPub = bundle.recoveryPub;
				var publicKeyIsEphemeral = bundle.publicKeyIsEphemeral;

				// Step 4 — send to server
				return new Promise(function (resolveSave) {
					_save(sessionKey, sessionPub, recoveryPub, publicKeyIsEphemeral, function (err, resp) {
						var errMsg = Q.firstErrorMessage(err, resp);
						Q.handle(callback, null, [errMsg, null, sessionKey, resp]);
						resolveSave({ sessionKey: sessionKey, response: resp });
					});
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
			function _save(sessionKey, sessionPub, recoveryPub, publicKeyIsEphemeral, callback) {
				var fields = {
					info: info,
					publicKey: sessionPub,
					recoveryKey: recoveryPub
				};
				if (publicKeyIsEphemeral) {
					fields.publicKeyIsEphemeral = true;
					Q.log("Users.Session: sending key marked as ephemeral");
				}

				Q.Users.sign(fields, function (err, signedFields) {
					Q.req("Users/key", ["saved"], function (err2) {
						Q.handle(callback, this, arguments);
					}, {
						method: "post",
						fields: signedFields
					});
				}, {
					key: sessionKey,
					fieldNames: ["info", "publicKey", "recoveryKey"]
				});
			}
		});

		return _pendingGenerateKey;
	});
});
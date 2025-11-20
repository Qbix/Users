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
				Q.handle(callback, null, [null, null, false, null]);
				resolveOuter(false);
				return;
			}

			if (Users.Session.publicKey) {
				Q.handle(callback, null, [null, null, null, null]);
				resolveOuter(false);
				return;
			}

			var info = Object.assign({}, Users.Session.key);
			var publicKeyIsEphemeral = false;

			var accessPromise = Promise.resolve(true);
			if (document.requestStorageAccess && document.hasStorageAccess) {
				accessPromise = Promise.race([
					document.hasStorageAccess().then(function (already) {
						if (already) {
							return true;
						}
						return document.requestStorageAccess().then(function () {
							return true;
						}).catch(function () {
							return true;
						});
					}).catch(function () {
						return true;
					}),
					new Promise(function (resolve) {
						setTimeout(function () {
							resolve(true);
						}, 500);
					})
				]);
			}

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

				return new Promise(function (resolve) {
					var storeName = "Q.Users.keys";
					Q.IndexedDB.open(Q.info.baseUrl, storeName, "id", function (err, db) {
						if (err || !db) {
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
								resolve({ sessionKey: sessionKey, recoveryKey: recoveryKey });
							};
							tx.onerror = function () {
								publicKeyIsEphemeral = true;
								resolve({ sessionKey: sessionKey, recoveryKey: recoveryKey });
							};
						} catch (e) {
							publicKeyIsEphemeral = true;
							resolve({ sessionKey: sessionKey, recoveryKey: recoveryKey });
						}
					});
				}).then(function (obj) {
					obj.publicKeyIsEphemeral = publicKeyIsEphemeral;
					return obj;
				});
			}).then(function (obj) {
				return Promise.all([
					crypto.subtle.exportKey("jwk", obj.sessionKey.publicKey),
					crypto.subtle.exportKey("jwk", obj.recoveryKey.publicKey)
				]).then(function (pubs) {
					return {
						sessionKey: obj.sessionKey,
						recoveryKey: obj.recoveryKey,
						sessionPub: pubs[0],
						recoveryPub: pubs[1],
						publicKeyIsEphemeral: obj.publicKeyIsEphemeral
					};
				});
			}).then(function (bundle) {
				return new Promise(function (resolveSave) {
					_save(
						bundle.sessionKey,
						bundle.sessionPub,
						bundle.recoveryPub,
						bundle.publicKeyIsEphemeral,
						function (err2, resp2) {
							Q.handle(callback, null, [err2, null, bundle.sessionKey, resp2]);
							resolveSave({ sessionKey: bundle.sessionKey, response: resp2 });
						}
					);
				});
			}).then(function (result) {
				_pendingGenerateKey = null;
				resolveOuter(result);
			}).catch(function (e) {
				_pendingGenerateKey = null;
				rejectOuter(e);
			});

			function _save(sessionKey, sessionPub, recoveryPub, publicKeyIsEphemeral, callback) {
				var fields = {
					info: info,
					publicKey: sessionPub,
					recoveryKey: recoveryPub
				};
				if (publicKeyIsEphemeral) {
					fields.publicKeyIsEphemeral = true;
				}

				Q.Users.sign(fields, function (err, signedFields) {
					Q.req("Users/key", ["saved"], callback, {
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
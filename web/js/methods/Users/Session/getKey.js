Q.exports(function (Users, priv) {

	/**
	 * Methods for user sessions
	 * @module Users
	 * @class Users.Session
	 */

	var _pendingGetKey = null;

	/**
	 * Retrieves the non-extractable session private key from IndexedDB.
	 * Caches the result in Users.Session.key.loaded.
	 * Safe for concurrent or repeated calls — returns the same promise if one is already running.
	 *
	 * @method getKey
	 * @static
	 * @param {Function} callback Receives (err, key)
	 */
	return Q.getter(function Users_Session_getKey(callback) {
		// If key already loaded, return immediately
		if (Users.Session.key && Users.Session.key.loaded) {
			Q.handle(callback, null, [null, Users.Session.key.loaded]);
			return Promise.resolve(Users.Session.key.loaded);
		}

		// Avoid parallel IndexedDB reads
		if (_pendingGetKey) {
			return _pendingGetKey.then(function (key) {
				Q.handle(callback, null, [null, key]);
				return key;
			});
		}

		_pendingGetKey = new Promise(function (resolve, reject) {
			var storeName = "Q.Users.keys";

			Q.IndexedDB.open(Q.info.baseUrl, storeName, "id", function (err, db) {
				if (err || !db) {
					Q.warn("Users.Session.getKey: IndexedDB unavailable, trying volatile or fallback sources");
					return fallbackGetKey(err, callback, resolve, reject);
				}

				try {
					var tx = db.transaction(storeName, "readonly");
					var store = tx.objectStore(storeName);
					var request = store.get("Users.Session");

					request.onsuccess = function (event) {
						var record = event.target.result;
						var key = record ? record.key : null;
						if (key) {
							Users.Session.key.loaded = key;
							Q.handle(callback, null, [null, key]);
							resolve(key);
						} else {
							Q.warn("Users.Session.getKey: no key in IndexedDB, trying volatile or fallback sources");
							fallbackGetKey(null, callback, resolve, reject);
						}
					};

					request.onerror = function (event) {
						var e = {
							classname: "Users_Session_getKeyIndexedDB",
							message: "Users.Session.getKey: could not read from IndexedDB",
							error: event
						};
						Q.warn(e.message);
						fallbackGetKey(e, callback, resolve, reject);
					};
				} catch (e) {
					Q.warn("Users.Session.getKey: IndexedDB exception, using fallback " + e);
					fallbackGetKey(e, callback, resolve, reject);
				}
			});
		});

		return _pendingGetKey.then(function (key) {
			_pendingGetKey = null;
			return key;
		}).catch(function (e) {
			_pendingGetKey = null;
			throw e;
		});

		// --- Helper: fallback when IndexedDB is blocked/unavailable ---
		function fallbackGetKey(err, callback, resolve, reject) {
			var key = null;

			// Try in-memory volatile cache
			if (Users.Session._volatileKeys && Users.Session._volatileKeys.sessionKey) {
				key = Users.Session._volatileKeys.sessionKey;
				Q.log("Users.Session.getKey: recovered key from in-memory volatile cache");
			}

			// Try asking Service Worker (async)
			if (!key && navigator.serviceWorker && navigator.serviceWorker.controller) {
				try {
					var channel = new MessageChannel();
					channel.port1.onmessage = function (event) {
						if (event.data && event.data.sessionKey) {
							key = event.data.sessionKey;
							Users.Session.key.loaded = key;
							Q.log("Users.Session.getKey: recovered key from Service Worker");
							Q.handle(callback, null, [null, key]);
							resolve(key);
						} else {
							Q.warn("Users.Session.getKey: no key in Service Worker response");
							finalize();
						}
					};
					navigator.serviceWorker.controller.postMessage(
						{ type: "Q.Users.sessionKeys.request" },
						[channel.port2]
					);
					return;
				} catch (e) {
					Q.warn("Users.Session.getKey: failed contacting Service Worker " + e);
				}
			}

			// Try parent window (if iframe)
			if (!key && window.parent && window.self !== window.top) {
				try {
					var listener = function (ev) {
						var data = ev.data || {};
						if (data.type === "Q.Users.sessionKeys.provide" && data.sessionKey) {
							window.removeEventListener("message", listener);
							key = data.sessionKey;
							Users.Session.key.loaded = key;
							Q.log("Users.Session.getKey: recovered key from parent window");
							Q.handle(callback, null, [null, key]);
							resolve(key);
						}
					};
					window.addEventListener("message", listener, false);
					window.parent.postMessage({ type: "Q.Users.sessionKeys.request" }, "*");
					return;
				} catch (e) {
					Q.warn("Users.Session.getKey: failed contacting parent " + e);
				}
			}

			// If nothing found, fail cleanly
			function finalize() {
				if (key) {
					Q.handle(callback, null, [null, key]);
					resolve(key);
				} else {
					var e = err || { message: "Users.Session.getKey: no key found in any source" };
					Q.handle(callback, null, [e]);
					reject(e);
				}
			}
			finalize();
		}
	});
});

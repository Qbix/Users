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
				if (err) {
					var e = { 
						message: "Users.Session.getKey: failed to open IndexedDB", 
						error: err 
					};
					Q.handle(callback, null, [e]);
					reject(e);
					return;
				}

				var tx = db.transaction(storeName, "readonly");
				var store = tx.objectStore(storeName);
				var request = store.get("Users.Session");

				request.onsuccess = function (event) {
					var record = event.target.result;
					var key = record ? record.key : null;
					Users.Session.key.loaded = key || null;
					Q.handle(callback, null, [null, key]);
					resolve(key);
				};

				request.onerror = function (event) {
					var e = {
						classname: "Users_Session_getKeyIndexedDB",
						message: "Users.Session.getKey: could not read from IndexedDB",
						error: event
					};
					Q.handle(callback, null, [e]);
					reject(e);
				};
			});
		});

		return _pendingGetKey.then(function (key) {
			_pendingGetKey = null;
			return key;
		}).catch(function (e) {
			_pendingGetKey = null;
			throw e;
		});
	});
});
Q.exports(function (Users, priv) {
	/**
	 * Methods for user sessions
	 * @module Users
	 * @class Users.Session
	 */

	/**
	 * Clears the non-extractable private key, e.g. during logout or session loss.
	 * Deletes it from IndexedDB and, if in an iframe, posts a message to the parent
	 * notifying that the key has been cleared.
	 *
	 * @method clearKey
	 * @static
	 * @param {Function} callback Receives (err, event)
	 * @return {Promise|Boolean} Resolves true if cleared, false if crypto.subtle unavailable.
	 */
	return Q.getter(function Users_Session_clearKey(callback) {
		if (!crypto || !crypto.subtle) {
			Q.warn("Users.Session.clearKey: crypto.subtle unavailable (insecure context?)");
			return false;
		}

		var info = Users.Session.key;
		var inIframe = (window.self !== window.top);
		var storeName = 'Q.Users.keys';

		// Attempt to remove the stored session key
		return Q.IndexedDB.open(Q.info.baseUrl, storeName, 'id').then(function (db) {
			return new Promise(function (resolve, reject) {
				var tx = db.transaction(storeName, 'readwrite');
				var store = tx.objectStore(storeName);
				var request = store.delete('Users.Session');

				request.onsuccess = function (event) {
					Q.log("Users.Session.clearKey: deleted Users.Session from IndexedDB");
					if (inIframe) {
						try {
							window.parent.postMessage({
								type: "Q.Users.recoveryKey.cleared"
							}, "*");
							Q.log("Users.Session: posted clearKey notice to parent via postMessage");
						} catch (e) {
							Q.warn("Users.Session: failed to post clearKey to parent " + e);
						}
					}
					Q.handle(callback, null, [null, event]);
					resolve(true);
				};

				request.onerror = function (event) {
					var err = {
						classname: 'Users_Session_clearKeyIndexedDB',
						message: "Users.Session.clearKey: error clearing IndexedDB"
					};
					Q.handle(callback, null, [err, event]);
					reject(err);
				};
			});
		}).catch(function (err) {
			Q.warn("Users.Session.clearKey: failed to open IndexedDB: " + err);
			if (inIframe) {
				try {
					window.parent.postMessage({
						type: "Q.Users.recoveryKey.cleared"
					}, "*");
					Q.log("Users.Session: posted clearKey notice to parent (fallback)");
				} catch (e) {
					Q.warn("Users.Session: failed to post clearKey to parent " + e);
				}
			}
			Q.handle(callback, null, [err]);
			return false;
		});
	});
});
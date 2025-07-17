Q.exports(function (Users, priv) {

    /**
	 * Methods for user sessions
     * @module Users
	 * @class Users.Session
	 */
    /**
     * Get (or get again) the (non-extractable) cryptographic key from IndexedDB.
     * Saves this key also as Users.Session.key.loaded and then calls the callback.
     * @method getKey
     * @static
     * @param {Function} callback Receives (err, key)
     */
    return Q.getter(function Users_Session_getKey(callback) {
        var storeName = 'Q.Users.keys';
        Q.IndexedDB.open(Q.info.baseUrl, storeName, 'id', function (err, db) {
            if (err) {
                return Q.handle(callback, null, [err]);
            }
            const store = db.transaction(storeName, 'readwrite').objectStore(storeName);
            var request = store.get('Users.Session');
            request.onsuccess = function (event) {
                var key = Users.Session.key.loaded
                = event.target.result ? event.target.result.key : null;
                Q.handle(callback, null, [null, key]);
            };
            request.onerror = function (event) {
                var err = {
                    classname: "Users_Session_getKeyIndexedDB",
                    message: "Users.Session.getKey: could not get store in IndexedDB"
                }
                Q.handle(callback, null, [err]);
            };
        });
    });
});
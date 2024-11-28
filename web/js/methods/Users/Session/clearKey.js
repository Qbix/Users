Q.exports(function (Users, priv) {
    /**
	 * Methods for user sessions
     * @module Users
	 * @class Users.Session
	 */
    /**
     * Clears the non-extractable private key, e.g. during logout.
     * Doesn't tell the server, since it's assumed that a new session has been started.
     * @method clearKey
     * @static
     * @param {Function} callback Receives (err, event)
     * @return {Boolean} returns false if the key is already set or
     *  crypt.subtle is undefined because the page is in insecure context
     */
    return Q.getter(function Users_Session_clearKey(callback) {
        if (!crypto || !crypto.subtle) {
            return false;
        }
        var info = Users.Session.key;
        return crypto.subtle.generateKey({
            name: info.name,
            namedCurve: info.namedCurve
        }, false, ['sign', 'verify'])
        .then(function (key) {
            Q.IndexedDB.open(Q.info.baseUrl, 'Q.Users.keys', 'id', function (err, store) {
                var request = store.delete('Users.Session');
                request.onsuccess = function (event) {
                    Q.handle(callback, null, [null, event, key]);
                };
                request.onerror = function (event) {
                    var err = {
                        classname: 'Users_Session_clearKeyIndexedDB',
                        message: "Users.Session.clearKey: error clearing in IndexedDB"
                    };
                    Q.handle(callback, null, [err, event, key]);
                }
            });
        });
    });
});
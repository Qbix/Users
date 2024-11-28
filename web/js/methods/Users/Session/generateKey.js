Q.exports(function (Users, priv) {
    /**
	 * Methods for user sessions
     * @module Users
	 * @class Users.Session
	 */
    /**
     * Generates a non-extractable private key, saves it in IndexedDB.
     * Then tells the server to save it.
     * @method generateKey
     * @static
     * @param {Function} callback Receives (err, event)
     * @return {Boolean} returns false if the key is already set or
     *  crypt.subtle is undefined because the page is in insecure context
     */
    return Q.getter(function Users_Session_generateKey(callback) {
        if (!crypto || !crypto.subtle) {
            return false;
        }
        if (Users.Session.publicKey) {
            Q.handle(callback, null, ["Users.Session.publicKey was already set on server"]);
            return false;
        }
        var info = Users.Session.key;
        return crypto.subtle.generateKey({
            name: info.name,
            namedCurve: info.namedCurve
        }, false, ['sign', 'verify'])
        .then(function (key) {
            Q.IndexedDB.open(Q.info.baseUrl, 'Q.Users.keys', 'id', function (err, store) {
                var request = store.put({
                    id: 'Users.Session',
                    key: key
                });
                request.onsuccess = function (event) {
                    // if successfully saved on the client,
                    // then tell the server the exported public key
                    _save(key, function (err, response) {
                        var err = Q.firstErrorMessage(err, response);
                        Q.handle(callback, null, [err, event, key, response]);
                    });
                };
                request.onerror = function (event) {
                    var err = {
                        classname: 'Users_Session_generateKeyIndexedDB',
                        message: "Users.Session.generateKey: error saving in IndexedDB"
                    };
                    Q.handle(callback, null, [err, event, key]);
                }
            });
            function _save (key, callback) {
                var fields =  {
                    info: info
                };
                Q.Users.sign(fields, function (err, fields) {
                    Q.req('Users/key', ['saved'], function (err) {
                        // from now on, the server will use it
                        // for validating requests in this session
                        Q.handle(callback, this, arguments);
                    }, {
                        method: 'post',
                        fields: fields
                    });
                }, {
                    key: key,
                    fieldNames: ['info']
                });
            }
        });
    });
});
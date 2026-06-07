Q.exports(function (Users, priv) {

	/**
	 * Attempts to log user in using Facebook
	 *
	 * @method login
	 * @static
	 * @param {Function} [callback]
	 *   Receives (error, user).
	 *
	 * @return {Promise}
	 */
	return function login (callback) {
        return new Q.Promise(function (resolve, reject) {
            var scope = Users.Facebook.scope;
            if (Q.isArrayLike(scope)) {
                scope = scope.join(',');
            }
            switch (Users.Facebook.type) {
            case 'web':
                FB.login(function (response) {
                    Users.Facebook.doLogin(response);
                    callback && callback(response);
                    resolve(response);
                }, scope ? {scope: scope} : undefined);
                break;
            case 'native':
                facebookConnectPlugin.login(["email"], function (response) {
                    Users.Facebook.doLogin(response);
                    callback && callback(response);
                    resolve(response);
                }, function (err) {
                    console.warn(err);
                    reject(response);
                });
                break;
            case 'oauth':
                var url = 'https://www.facebook.com/v2.11/dialog/oauth' +
                    '?client_id=' + Users.Facebook.appId +
                    '&redirect_uri=' + Q.baseUrl() + '/login/facebook%3Fscheme%3D' + Users.Facebook.scheme +
                    '&state=' + _stringGen(10) +
                    '&response_type=token&scope=' + Users.Facebook.scope.join(",");
                cordova.plugins.browsertabs.openUrl(url,
                    {scheme: Users.Facebook.scheme + '://'},
                    function(success) { console.log(success); resolve(err); },
                    function(err) { console.log(err); reject(err); }
                );
            }
        });
        function _stringGen(len) {
            var text = "";
            var charset = "abcdefghijklmnopqrstuvwxyz0123456789";
            for (var i = 0; i < len; i++)
                text += charset.charAt(Math.floor(Math.random() * charset.length));
            return text;
        }
    };
});
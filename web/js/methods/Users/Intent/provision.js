Q.exports(function (Users, priv) {
	/**
	 * Methods for user intents
	 * @module Users
	 * @class Users.Intents
	 */

	/**
	 * Provisions a Users.Intent token ahead of time, that can be used to
     * POST to Users/intent without waiting to see what the token will be.
	 * Good for cases where functions need to synchronously respond to
     * user gestures by e.g. assigning an external openURL schema to window.location.
	 * @method provision
	 * @static
     * @param {String} action for example "Users/authenticate"
     * @param {String} platform for example "telegram"
     * @param {String} appName name of the app in the Users.apps[platform] object
     * @param {String} token needed to interpolate into the URL to redirect to
	 * @param {Function} callback Receives (err, token)
     * @param {Object} [options]
     * @param {Boolean} [options.skip]
     * @param {Boolean} [options.skip.redirect]
     * @param {Boolean} [options.skip.QR]
	 */
	return function Users_Intent_provision(action, platform, appName, token, callback, options) {
        var fields = {
            action: action,
            platform: platform,
            appName: appName,
            token: token
        };
        Q.req(fields, 'Users/intent', ['token', 'capability'], function (err, response) {
            var fem = Q.firstErrorMessage(err, response);
            if (fem) {
                console.warn(fem);
                callback && callback(null);
                return;
            }
            callback && callback(response && response.slots);
        });
    };
});
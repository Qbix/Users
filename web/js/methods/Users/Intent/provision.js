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
     * @param {String} appId key under the Users.apps[platform] object
	 * @param {Function} callback Receives (err, token)
	 */
	return function Users_Intent_provision(action, platform, appId, callback) {
        var fields = {
            action: action,
            platform: platform,
            appId: appId || Q.info.app
        };
        Q.req(fields, 'Users/intent', ['token', 'capability'], function (err, response) {
            var fem = Q.firstErrorMessage(err, response);
            if (fem) {
                console.warn(fem);
                callback && callback(null);
                return;
            }
            Q.setObject(['results', action, platform, appId], {
                token: response.slots.token,
                capability: response.slots.capability
            }, Users.Intent.provision);
            callback && callback(response && response.slots);
        });
    };
});
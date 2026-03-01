Q.exports(function (Users) {

	/**
	 * Get Facebook login status.
	 *
	 * Behavior depends on Users.Facebook.type:
	 *   - 'web'    → Uses FB.getLoginStatus
	 *   - 'native' → Uses facebookConnectPlugin
	 *   - 'oauth'  → Returns stored auth response
	 *
	 * @method getLoginStatus
	 * @static
	 * @param {Boolean} [force=false]
	 *   Passed through to FB.getLoginStatus.
	 *
	 * @param {Function} [callback]
	 *   Receives (response).
	 *
	 * @return {Promise}
	 *   Resolves with (response).
	 */
	return Q.promisify(function _Facebook_getLoginStatus(force, callback) {

		if (typeof force === 'function') {
			callback = force;
			force = false;
		}

		force = !!force;

		switch (Users.Facebook.type) {

			case 'web':

				var timeout = 5000;

				if (!window.FB) {
					return Q.handle(callback, null, [{}]);
				}

				var fired = false;

				var t = setTimeout(function () {
					if (fired) return;
					fired = true;
					console.warn(
						"Facebook did not respond to FB.getLoginStatus within " +
						(timeout / 1000) + " sec."
					);
					Q.handle(callback, null, [{}]);
				}, timeout);

				FB.getLoginStatus(function (response) {
					if (fired) return;
					fired = true;
					clearTimeout(t);
					Q.handle(callback, null, [response]);
				}, force);

				break;

			case 'native':

				facebookConnectPlugin.getLoginStatus(function (response) {
					Q.handle(callback, null, [response]);
				});

				break;

			case 'oauth':

				Q.handle(callback, null, [Users.Facebook.getAuthResponse()]);
				break;

			default:

				Q.handle(callback, null, [{}]);
				break;
		}

	});

});
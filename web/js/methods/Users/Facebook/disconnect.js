Q.exports(function (Users, priv) {

	/**
	 * Disconnect Facebook session for the specified appId.
	 *
	 * This clears Facebook cookies and, if currently logged in,
	 * performs FB.logout().
	 *
	 * @method disconnect
	 * @static
	 * @param {String} [appId]
	 *   Optional Facebook appId alias defined in Users.apps.facebook.
	 *
	 * @param {Function} [callback]
	 *   Receives (error).
	 *
	 * @return {Promise}
	 *   Resolves when logout completes or no session exists.
	 */
	return Q.promisify(function _Facebook_disconnect(appId, callback) {

		var platformAppId = Users.getPlatformAppId('facebook', appId);

		if (!platformAppId) {
			console.warn("Users.Facebook.disconnect: missing Users.apps.facebook." + appId + ".appId");
		}

		// Clear Facebook cookies
		Q.cookie('fbs_' + platformAppId, null, { path: '/' });
		Q.cookie('fbsr_' + platformAppId, null, { path: '/' });

		Users.init.facebook(function logoutCallback(err) {

			if (err) {
				return Q.handle(callback, null, [err]);
			}

			Users.Facebook.getLoginStatus(function (response) {

				setTimeout(function () {
					Users.logout.occurring = false;
				}, 0);

				if (!response || !response.authResponse) {
					return Q.handle(callback, null, [null]);
				}

				if (!window.FB) {
					return Q.handle(callback, null, [null]);
				}

				FB.logout(function () {
					delete Users.connected.facebook;
					Q.handle(callback, null, [null]);
				});

			}, true);

		}, {
			appId: appId
		});

	});

});
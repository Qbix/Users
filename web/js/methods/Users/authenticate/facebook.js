Q.exports(function (Users, priv) {
    
    /**
	 * Facebook platform authentication adapter.
	 * Authenticates user if the user was already connected to it.
	 * It tries to do so by checking a cookie that would have been set by the server.
	 * @method authenticate
	 * @param {String} platform Currently it's `facebook`
     * @param {String} platformAppId platformAppId
	 * @param {Function} onSuccess Called if the user successfully authenticates with the platform, or was already authenticated.
	 *  It is passed the user information if the user changed.
	 * @param {Function} onCancel Called if the authentication was canceled. Receives err, options
	 * @param {Object} [options] object of parameters for authentication function
	 *   @param {Function|Boolean} [options.prompt=null] which shows the usual prompt unless it was already rejected once.
	 *     Can be false, in which case the user is never prompted and the authentication just happens.
	 *     Can be true, in which case the usual prompt is shown even if it was rejected before.
	 *     Can be a function with an onSuccess and onCancel callback, in which case it's used as a prompt.
	 *   @param {Boolean} [options.force] forces the getLoginStatus to refresh its status
	 *   @param {String} [options.appId=Q.info.app] Only needed if you have multiple apps on platform
	 */
    function facebook(platform, platformAppId, onSuccess, onCancel, options) {
		options = options || {};
		
		// make sure facebook is initialized
		Q.Users.init.facebook(function () {

			// check if user is connected to facebook
			Q.Users.Facebook.getLoginStatus(function (response) {

				if (response.status === 'connected') {

					priv.handleXid(
						platform,
						platformAppId,
						response.authResponse.userID,
						onSuccess,
						onCancel,
						Q.extend({ response: response }, options)
					);

				} else if (platformAppId) {

					// let's delete any stale facebook cookies there might be
					// otherwise they might confuse our server-side authentication.
					Q.cookie('fbs_' + platformAppId, null, { path: '/' });
					Q.cookie('fbsr_' + platformAppId, null, { path: '/' });

					priv._doCancel(
						platform,
						platformAppId,
						null,
						onSuccess,
						onCancel,
						options
					);
				}

			}, options.force ? true : false);

		}, {
			appId: options.appId
		});
	}

	/**
	 * Builds authentication fields for server-side authenticate call.
	 * This moves facebook-specific payload shaping out of core.
	 *
	 * In some rare cases, the user may have logged out of facebook
	 * while our prompt was visible, so there is no longer a valid
	 * facebook authResponse. In this case, even though they want
	 * to authenticate, we must cancel it.
	 *
	 * @method buildAuthFields
	 * @param {String} platform
	 * @param {String} platformAppId
	 * @param {Function} onSuccess
	 * @param {Function} onCancel
	 * @param {Object} options
	 * @return {Object|null} fields for priv._doAuthenticate or null if canceled
	 */
	facebook.buildAuthFields = function (
		platform,
		platformAppId,
		onSuccess,
		onCancel,
		options
	) {

		options = options || {};
		var appId = options.appId || Q.info.app;

		var ar = Users.Facebook.getAuthResponse();

		if (!ar) {

			alert("Connection to facebook was lost. Try connecting again.");

			priv._doCancel(
				platform,
				platformAppId,
				null,
				onSuccess,
				onCancel,
				options
			);

			return null;
		}

		ar.expires = Math.floor(Date.now() / 1000) + ar.expiresIn;
		ar.fbAppId = platformAppId;
		ar.appId = appId;

		return {
			'Q.Users.authPayload.facebook': ar
		};
	};
    
    return facebook;
});
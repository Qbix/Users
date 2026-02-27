Q.exports(function (Users, priv) {

	/**
	 * Facebook platform authentication adapter.
	 * Authenticates user if the user was already connected to it.
	 * It tries to do so by checking a cookie that would have been set by the server.
	 *
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

		Q.Users.init.facebook(function (err) {

			if (err || !window.FB) {
				return priv._doCancel(
					platform,
					platformAppId,
					err || "Facebook SDK unavailable",
					onSuccess,
					onCancel,
					options
				);
			}

			Q.Users.Facebook.getLoginStatus(function (response) {

				if (response && response.status === 'connected') {

					priv.handleXid(
						platform,
						platformAppId,
						response.authResponse.userID,
						onSuccess,
						onCancel,
						Q.extend({ response: response }, options)
					);

				} else if (platformAppId) {

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

		if (!Users.Facebook || !Users.Facebook.getAuthResponse) {
			priv._doCancel(
				platform,
				platformAppId,
				"Facebook SDK not ready",
				onSuccess,
				onCancel,
				options
			);
			return null;
		}

		var ar = Users.Facebook.getAuthResponse();

		if (!ar || !ar.userID) {

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

	// ============================================================
	// Prompt Adapter Registration (Replicates Old Prompt Behavior)
	// ============================================================

	Users.prompt = Users.prompt || {};

	Users.prompt.facebook = {

		template: null, // using dynamic render for full legacy parity

		getData: function (context) {
			return context;
		},

		render: function (context, container, done) {

			var platform = context.platform;
			var platformCapitalized = context.Platform;

			var areUsing = Q.text.Users.prompt.areUsing.interpolate({
				platform: platform,
				Platform: platformCapitalized
			});
			var noLongerUsing = Q.text.Users.prompt.noLongerUsing.interpolate({
				platform: platform,
				Platform: platformCapitalized
			});

			var caption;
			var xid2 = Q.getObject(['loggedInUser', 'xids', platform], Users);
			var queries = ['me'];
			if (xid2) queries.push('xid');

			var pipe = new Q.Pipe(queries, function (params) {

				var meName = Q.getObject(['me', 0, 'name'], params);
				var mePicture = Q.getObject(['me', 0, 'picture', 'data', 'url'], params);
				var xidName = Q.getObject(['xid', 0, 'name'], params);
				var xidPicture = Q.getObject(['xid', 0, 'picture', 'data', 'url'], params);

				if (xidName) {
					container.append(_usingInformation(xidPicture, xidName, noLongerUsing));
					caption = Q.text.Users.prompt.doSwitch.interpolate({
						platform: platform,
						Platform: platformCapitalized
					});
				} else {
					caption = Q.text.Users.prompt.doAuth.interpolate({
						platform: platform,
						Platform: platformCapitalized
					});
				}

				container
					.append(_usingInformation(mePicture, meName, areUsing))
					.append(_authenticateActions(caption));

				done && done();
			});

			if (!window.FB || !FB.api) {
				done && done();
				return;
			}

			FB.api("/me?fields=name,picture.width(50).height(50)", pipe.fill('me'));
			if (xid2) {
				FB.api("/" + xid2 + "?fields=name,picture.width(50).height(50)", pipe.fill('xid'));
			}

			function _usingInformation(icon, name, explanation) {
				return $("<table />").append(
					$("<tr />").append(
						$("<td class='Users_profile_pic' />").append(
							$('<img />', { src: icon })
						)
					).append(
						$("<td class='Users_explanation_name' />").append(
							$("<div class='Users_explanation' />").html(explanation)
						).append(name)
					)
				);
			}

			function _authenticateActions(caption) {
				return $("<div class='Users_actions Q_big_prompt' />").append(
					$('<button type="submit" class="Q_button Q_main_button Users_confirm" />')
						.html(caption)
				);
			}
		}
	};

	return facebook;
});
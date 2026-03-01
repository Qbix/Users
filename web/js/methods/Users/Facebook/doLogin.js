Q.exports(function (Users, priv) {

	/**
	 * Complete Facebook login flow after FB.getLoginStatus or FB.login.
	 *
	 * Fetches user profile + picture, prepares registration info,
	 * and either submits the login form or authenticates automatically.
	 *
	 * @method performLogin
	 * @static
	 * @param {Object} response
	 *   Facebook login response containing authResponse.
	 *
	 * @param {Object} [options]
	 *   @param {Function|Q.Event} [options.onConnect]
	 *     Called after successful Users.authenticate('facebook').
	 *     Receives (user).
	 *
	 *   @param {Function|Q.Event} [options.onCancel]
	 *     Called if authentication fails.
	 *
	 * @param {Function} [callback]
	 *   Receives (error, user).
	 *
	 * @return {Promise}
	 */
	return Q.promisify(function _Facebook_doLogin(response, options, callback) {

		options = options || {};

		if (!response || !response.authResponse) {
			return Q.handle(callback, null, ["No Facebook authResponse"]);
		}

		var step1_form = $('#Users_login_step1_form');
		step1_form.data('used', 'facebook');
		step1_form.data('platforms', Users.Facebook.usingPlatforms);

		var p = Q.pipe(['me', 'picture'], function (params) {

			var me = params.me[0];
			var picture = params.picture[0].data;

			Users.Facebook.me = me;

			var $usersLoginIdentifier = $('#Users_login_identifier');

			if (!me.email) {
				step1_form.data('used', null);
				alert(Q.text.Users.login.facebook.noEmail);
				$usersLoginIdentifier.plugin('Q/clickfocus');
				return Q.handle(callback, null, ["Facebook account has no email"]);
			}

			priv.registerInfo = {
				firstName: me.first_name,
				lastName: me.last_name,
				gender: me.gender,
				birthday: me.birthday,
				timezone: me.timezone,
				locale: me.locale,
				verified: me.verified,
				pic: picture.url,
				picWidth: picture.width,
				picHeight: picture.height
			};

			// Case 1: classic form-based login
			if ($usersLoginIdentifier.length) {

				$usersLoginIdentifier
					.val(me.email)
					.closest('form')
					.submit();

				return Q.handle(callback, null, [null]);

			} 

			// Case 2: auto-login via Users.authenticate
			var url = Q.action(Users.login.options.userQueryUri) + '?' + $.param({
				identifier: me.email,
				identifierType: 'email'
			});

			Q.request(url, ['data'], function (err, response) {

				if (err || response.errors) {
					Q.handle(options.onCancel, null, [err]);
					return Q.handle(callback, null, [err || response.errors]);
				}

				Q.Response.processScriptDataAndLines(response);

				Users.authenticate(
					'facebook',
					function (user) {

						priv.login_connected = true;
						priv.login_onConnect && priv.login_onConnect(user);

						Q.handle(options.onConnect, null, [user]);
						Q.handle(callback, null, [null, user]);
					},
					function (error) {

						priv.login_onCancel && priv.login_onCancel();

						Q.handle(options.onCancel, null, [error]);
						Q.handle(callback, null, [error]);
					},
					{ prompt: false }
				);

			}, { xhr: Q.info.useTouchEvents ? 'sync' : {} });

		});

		var paramsPicture = {
			redirect: false,
			height: "200",
			type: "normal",
			width: "200"
		};

		var paramsFields = {};

		if (response.authResponse.accessToken) {
			paramsPicture.access_token = response.authResponse.accessToken;
			paramsFields.access_token = response.authResponse.accessToken;
		}

		FB.api("/me/picture", paramsPicture, p.fill('picture'));
		FB.api(
			'/me?fields=first_name,last_name,gender,birthday,timezone,locale,verified,email',
			paramsFields,
			p.fill('me')
		);

	});

});
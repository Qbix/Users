Q.exports(function (Users, priv) {
	/**
	 * Mints a persisted Users_Intent against the current session and returns
	 * the token. No redirect, no QR, no socket, no UI. Used by flows that
	 * need to hand a token to another browsing context (a parent window, an
	 * opener, etc.) which will then perform the actual handoff.
	 *
	 * Internally provisions a token (reserves it client-side), then POSTs to
	 * Users/intent with the action so the server persists a Users_Intent row
	 * bound to the reserved token. Differs from Users.Intent.start, which
	 * orchestrates redirects, QR codes, and platform-specific URL patterns
	 * intended for sending the user to external platforms.
	 *
	 * @method mint
	 * @static
	 * @param {String} action The intent action, e.g. "Users/bridge"
	 * @param {String} [platform="web"]
	 * @param {String} [appId=Q.info.app]
	 * @param {Function} callback Receives (err, token)
	 */
	return function Users_Intent_mint(action, platform, appId, callback) {
		platform = platform || 'web';
		appId = appId || Q.info.app;

		Users.Intent.provision(action, platform, appId, function (slots) {
			if (!slots || !slots.token || !slots.capability) {
				return callback(new Error('Users.Intent.mint: provision failed'));
			}
			var token = slots.token;
			var capability = slots.capability;

			Q.req('Users/intent', ['token'], function (err, response) {
				var fem = Q.firstErrorMessage(err, response);
				if (fem) {
					return callback(new Error('Users.Intent.mint: ' + fem));
				}
				callback(null, token);
			}, {
				method: 'post',
				fields: {
					capability: capability,
					action: action,
					platform: platform,
					appId: appId
				}
			});
		});
	};
});
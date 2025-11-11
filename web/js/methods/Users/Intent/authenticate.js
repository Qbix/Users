Q.exports(function (Users, priv) {
	/**
	 * Completes an existing Users.Intent by authenticating through an external platform.
	 *
	 * @method authenticate
	 * @static
	 * @param {Object} options
	 *   @param {String} options.platform  Platform name, e.g. "telegram" or "web3"
	 *   @param {String} [options.token]   Intent token (if already provisioned)
	 *   @param {String} [options.payload] Platform-specific signed payload (e.g. Telegram initData)
	 *   @param {Function} [options.callback] Optional callback function (err, response)
	 * @return {void}
	 */
	return function Users_Intent_authenticate(options) {
		options = Q.extend({
			platform: null,
			token: null,
			payload: null,
			callback: null
		}, options);

		if (!options.platform) {
			throw new Error("Users.Intent.authenticate: missing platform");
		}
		if (!options.payload) {
			throw new Error("Users.Intent.authenticate: missing payload");
		}

		var fields = {
			platform: options.platform,
			payload: options.payload
		};
		if (options.token) {
			fields.token = options.token;
		}

		Q.req('Users/intent', function (err, response) {
			if (err) {
				if (console && console.warn)
					console.warn('[Users.Intent.authenticate] failed:', err);
				if (options.callback) {
					options.callback(err);
				}
				return;
			}

			try {
				Q.Response.processScriptDataAndLines(response);
				if (options.callback) {
					options.callback(null, response);
				}
			} catch (e) {
				if (console && console.error)
					console.error('[Users.Intent.authenticate] processing error:', e);
				if (options.callback) {
					options.callback(e);
				}
			}
		}, {
			method: 'PUT',
			fields: fields
		});
	};
});

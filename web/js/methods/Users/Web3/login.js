Q.exports(function (Users, priv) {

	/**
	 * Perform full Web3 login flow.
	 *
	 * This method:
	 *  - Connects the wallet provider (if needed)
	 *  - Ensures a provider session exists
	 *  - Delegates identity binding to Users.authenticate('web3')
	 *  - Wires provider event handlers (idempotent)
	 *
	 * Signing, cookie resume, and authPayload construction
	 * are handled inside the web3 authenticate adapter.
	 *
	 * Global Events Fired:
	 *  - Web3.onSign receives (authPayload)
	 *  - Web3.onConnect receives (user)
	 *
	 * @method login
	 * @static
	 * @param {Object} [options]
	 *   Options passed through to Users.authenticate, plus:
	 *
	 *   @param {Boolean} [options.prompt=false]
	 *     Whether to show authentication prompt UI.
	 *
	 *   @param {Function|Q.Event} [options.onSign]
	 *     Called after signature payload is prepared but before
	 *     server authentication. Receives (authPayload).
	 *
	 *   @param {Function|Q.Event} [options.onConnect]
	 *     Called after successful authentication.
	 *     Receives (user).
	 *
	 *   @param {Function|Q.Event} [options.onCancel]
	 *     Called if authentication fails or is cancelled.
	 *     Receives (error).
	 *
	 *   @param {Boolean} [options.ignoreCookies]
	 *     If true, skips cookie-based resume logic inside the web3 adapter.
	 *
	 *   @param {Object} [options.appIds]
	 *     Optional override of platform appId mapping.
	 *
	 *   @param {Any} [options.*]
	 *     Any additional options supported by Users.authenticate.
	 *
	 * @return {Promise}
	 *   Resolves with (user) or rejects with (error).
	 */
	return Q.promisify(function _Web3_login(options, callback) {

		options = options || {};
		var Web3 = Users.Web3;

		Web3.connect(function (err, provider) {

            // If no web3 provider, that's OK... we'll show QR code for an intent
			// if (err) {
			// 	Web3.onCancel.handle(err);
			// 	Q.handle(options.onCancel, null, [err]);
			// 	return Q.handle(callback, null, [err]);
			// }

			var defaults = {
				prompt: false,
				onSigned: function (authPayload) {
					Web3.onSign.handle(authPayload);
					Q.handle(options.onSign, null, [authPayload]);
				}
			};

			Users.authenticate(
				'web3',
				function (user) {

					priv.login_connected = true;
					priv.login_onConnect && priv.login_onConnect(user);

					Web3.onConnect.handle(user);
					Q.handle(options.onConnect, null, [user]);

					Q.handle(callback, null, [null, user]);
				},
				function (error) {

					priv.login_onCancel && priv.login_onCancel();

					Q.handle(options.onCancel, null, [error]);

					Q.handle(callback, null, [error]);
				},
				Q.extend(defaults, options)
			);

		});

	});

});
Q.exports(function (Users, priv) {

	var Web3 = Users.Web3;

	/**
	 * Web3 platform authentication adapter.
	 * Authenticates using signed message verification.
	 * Supports cookie resume and provider-based signing.
	 *
	 * @method authenticate
	 * @param {String} platform Currently it's `web3`
	 * @param {String} platformAppId platformAppId
	 * @param {Function} onSuccess Called if authentication succeeds
	 * @param {Function} onCancel Called if authentication is canceled
	 * @param {Object} [options]
	 */
	function web3(platform, platformAppId, onSuccess, onCancel, options) {

		options = Q.extend({}, options);
		var cookieName = 'w3sr_' + platformAppId;

		// ============================================================
		// CASE 1: Cookie Resume
		// ============================================================

		try {
			var w3sr_json = Q.cookie(cookieName);

			if (w3sr_json) {

				var w3sr = JSON.parse(w3sr_json);
				var payload = w3sr[0];
				var signature = w3sr[1];

				var hash = ethers.utils.hashMessage(payload);
				var xid = ethers.utils.recoverAddress(hash, signature);

				_setAuthPayload({
					xid: xid,
					payload: payload,
					signature: signature
				});

				priv.handleXid(
					platform,
					platformAppId,
					xid,
					onSuccess,
					onCancel,
					options
				);

				return;
			}
		} catch (e) {
			Q.cookie(cookieName, null, { path: '/' });
		}

		// ============================================================
		// CASE 2: Provider Flow
		// ============================================================

		Users.init.web3(function () {

			var provider = window.ethereum || Web3.provider || null;

			if (provider && provider.request) {
				_signAndAuthenticate(provider);
				return;
			}

			Users.Intent.provision(
				"Users/authenticate",
				"web3",
				Q.app,
				function (slots) {
					if (!slots) {
						return onCancel && onCancel("Provision failed");
					}
					Users.Intent.start(slots.capability, {
						platform: "web3"
					});
				}
			);
		});

		// ============================================================
		// Helpers
		// ============================================================

		function _signAndAuthenticate(p) {

			p.request({ method: 'eth_requestAccounts' })
			.then(function (accounts) {

				var address = accounts[0];

				var payload = Q.text.Users.login.web3.payload.interpolate({
					host: location.host,
					timestamp: Math.floor(Date.now() / 1000)
				});

				var msg = ethers.utils.hexlify(
					ethers.utils.toUtf8Bytes(payload)
				);

				return p.request({
					method: 'personal_sign',
					params: [msg, address.toLowerCase()]
				}).then(function (signature) {

					Q.cookie(
						cookieName,
						JSON.stringify([payload, signature]),
						{ path: '/', maxAge: 86400 }
					);

					_setAuthPayload({
						xid: address,
						payload: payload,
						signature: signature
					});

					priv.handleXid(
						platform,
						platformAppId,
						address,
						onSuccess,
						onCancel,
						options
					);
				});
			})
			.catch(function (ex) {
				if (onCancel) onCancel(ex, options);
			});
		}

		function _setAuthPayload(authPayload) {

			Users.authPayload = Users.authPayload || {};
			Users.authPayload.web3 = {
				xid: authPayload.xid,
				payload: authPayload.payload,
				signature: authPayload.signature,
				platform: 'web3'
			};
		}
	}

	/**
	 * Builds authentication fields for server-side authenticate call.
	 *
	 * @method buildAuthFields
	 * @param {String} platform
	 * @param {String} platformAppId
	 * @param {Function} onSuccess
	 * @param {Function} onCancel
	 * @param {Object} options
	 * @return {Object|null}
	 */
	web3.buildAuthFields = function (
		platform,
		platformAppId,
		onSuccess,
		onCancel,
		options
	) {

		options = options || {};
		var authPayload = Q.getObject(['authPayload', 'web3'], Users);

		if (!authPayload || !authPayload.xid) {
			priv._doCancel(
				platform,
				platformAppId,
				"No Web3 auth payload",
				onSuccess,
				onCancel,
				options
			);
			return null;
		}

		return Q.extend({}, authPayload);
	};

	// ============================================================
	// Prompt Adapter Registration (Legacy Parity + UI Upgrade)
	// ============================================================

	Users.prompt = Users.prompt || {};

	Users.prompt.web3 = {

		template: null,

		getData: function (context) {
			return context;
		},

		render: function (context, container, done) {

			var currentXid = Q.getObject(['loggedInUser', 'xids', 'web3'], Users);
			var newXid = context.xid;

			var icon = Q.url('{{Users}}/img/platforms/web3.png');

			var caption;

			if (currentXid && currentXid !== newXid) {
				caption = Q.text.Users.prompt.doSwitch.interpolate({
					platform: 'web3',
					Platform: 'Web3'
				});
			} else {
				caption = Q.text.Users.prompt.doAuth.interpolate({
					platform: 'web3',
					Platform: 'Web3'
				});
			}

			if (currentXid && currentXid !== newXid) {
				container.append(_addressBlock(
					currentXid,
					icon,
					Q.text.Users.prompt.noLongerUsing.interpolate({
						platform: 'web3',
						Platform: 'Web3'
					})
				));
			}

			container
				.append(_addressBlock(
					newXid,
					icon,
					Q.text.Users.prompt.areUsing.interpolate({
						platform: 'web3',
						Platform: 'Web3'
					})
				))
				.append(_authenticateActions(caption));

			done && done();
		}
	};

	function _addressBlock(address, icon, explanation) {

		var abbr = Q.Users.Web3.abbreviateAddress(address);

		return $("<div class='Users_web3_block' />").append(
			$("<div class='Users_web3_row' />")
				.append($("<img class='Users_web3_icon' />").attr('src', icon))
				.append($("<div class='Users_web3_text' />")
					.append($("<div class='Users_explanation' />").html(explanation))
					.append($("<div class='Users_web3_address' />").text(abbr))
				)
		);
	}

	function _authenticateActions(caption) {
		return $("<div class='Users_actions Q_big_prompt' />").append(
			$('<button type="submit" class="Q_button Q_main_button Users_confirm" />')
				.html(caption)
		);
	}

	return web3;
});
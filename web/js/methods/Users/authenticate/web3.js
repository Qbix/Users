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
	 * @param {String} appId Used for Users.Intent.start()
	 * @param {Function} onSuccess Called if authentication succeeds
	 * @param {Function} onCancel Called if authentication is canceled
	 * @param {Object} [options]
	 * @param {Boolean} [options.ignoreCookies]
	 * @param {Q.Event} [options.onSigned]
	 */
	function web3(platform, platformAppId, onSuccess, onCancel, options) {

		options = Q.extend({}, options);
		var cookieName = 'w3sr_' + platformAppId;

		// ============================================================
		// TITLE / META MANAGEMENT (moved here from login)
		// ============================================================

		var _prevDocumentTitle = document.title;
		var _prevMetaTitle = $('meta[name="title"]').attr('content');
		var _prevOGTitle = $('meta[property="og:title"]').attr('content');

		document.title = Users.communityName;
		$('meta[name="title"]').attr('content', Users.communityName);
		$('meta[property="og:title"]').attr('content', Users.communityName);

		function _restoreTitle() {
			if (_prevDocumentTitle) {
				document.title = _prevDocumentTitle;
			}
			if (_prevMetaTitle) {
				$('meta[name="title"]').attr('content', _prevMetaTitle);
			}
			if (_prevOGTitle) {
				$('meta[property="og:title"]').attr('content', _prevOGTitle);
			}
		}

		function _successWrapper() {
			_restoreTitle();
			if (onSuccess) {
				onSuccess.apply(this, arguments);
			}
		}

		function _cancelWrapper() {
			_restoreTitle();
			if (onCancel) {
				onCancel.apply(this, arguments);
			}
		}

		// ============================================================
		// CASE 1: Cookie Resume
		// ============================================================

		var expires = Q.cookie(cookieName + '_expires');
		var now = Math.floor(Date.now() / 1000);

		if (!options.ignoreCookies) {
			if (expires && parseInt(expires, 10) < now) {
				Q.cookie(cookieName, null, { path: '/' });
				Q.cookie(cookieName + '_expires', null, { path: '/' });
			} else {
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

						var provider = window.ethereum || Web3.provider || null;

						priv.handleXid(
							platform,
							platformAppId,
							xid,
							function () {
								_successWrapper.call(
									this,
									Q.Users.loggedInUser,
									provider
								);
							},
							_cancelWrapper,
							options
						);

						return;
					}
				} catch (e) {
					Q.cookie(cookieName, null, { path: '/' });
					Q.cookie(cookieName + '_expires', null, { path: '/' });
				}
			}
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
						return _cancelWrapper("Provision failed");
					}
					Users.Intent.start(slots.capability, {
						platform: "web3",
						action: "Users/authenticate",
						appId: options.appId || Q.app
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

					var url = new URL(Q.baseUrl());

					Q.cookie(
						cookieName,
						JSON.stringify([payload, signature]),
						{
							path: url.pathname,
							expires: (Q.Users.authenticate.expires || 86400) * 1000
						}
					);

					_setAuthPayload({
						xid: address,
						payload: payload,
						signature: signature
					});

					if (options && options.onSigned) {
						if (false === Q.handle(options.onSigned, Users.Web3, [Users.authPayload.web3, provider])) {
							return _cancelWrapper();
						}
					}

					priv.handleXid(
						platform,
						platformAppId,
						address,
						_successWrapper,
						_cancelWrapper,
						options
					);
				});
			})
			.catch(function (ex) {
				_cancelWrapper(ex, options);
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

	// ============================================================
	// buildAuthFields (unchanged)
	// ============================================================

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
	// Prompt Adapter (unchanged)
	// ============================================================

	Users.prompt = Users.prompt || {};

	Users.prompt.web3 = {
		template: null,
		getData: function (context) { return context; },
		render: function (context, container, done) {

			var currentXid = Q.getObject(['loggedInUser', 'xids', 'web3'], Users);
			var newXid = context.xid;
			var icon = Q.url('{{Users}}/img/platforms/web3.png');

			var caption = (currentXid && currentXid !== newXid)
				? Q.text.Users.prompt.doSwitch.interpolate({ platform:'web3', Platform:'Web3' })
				: Q.text.Users.prompt.doAuth.interpolate({ platform:'web3', Platform:'Web3' });

			if (currentXid && currentXid !== newXid) {
				container.append(_addressBlock(
					currentXid,
					icon,
					Q.text.Users.prompt.noLongerUsing.interpolate({ platform:'web3', Platform:'Web3' })
				));
			}

			container
				.append(_addressBlock(
					newXid,
					icon,
					Q.text.Users.prompt.areUsing.interpolate({ platform:'web3', Platform:'Web3' })
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
		return $("<div class='Users_actions Q_buttons Q_big_prompt' />").append(
			$('<button type="submit" class="Q_button Q_main_button Users_confirm" />')
				.html(caption)
		);
	}

	return web3;
});
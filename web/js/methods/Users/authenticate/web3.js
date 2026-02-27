Q.exports(function (Users, priv) {

	var Web3 = Users.Web3;

	return function web3(platform, platformAppId, onSuccess, onCancel, options) {
		options = options || {};

		var cookieName = 'w3sr_' + platformAppId;
		var xid = null;

		// ============================================================
		// CASE 1: Cookie Resume  (FIXED: now re-validates via Users.authenticate)
		// ============================================================
		try {
			var w3sr_json = Q.cookie(cookieName);
			if (w3sr_json) {
				var w3sr = JSON.parse(w3sr_json);
				var payload = w3sr[0];
				var signature = w3sr[1];

				var hash = ethers.utils.hashMessage(payload);
				xid = ethers.utils.recoverAddress(hash, signature);

				if (!xid) throw new Error("Bad signature");

				var matches = payload.match(/[\d]{8,12}/);
				if (!matches) throw new Error("Missing timestamp");

				var ts = parseInt(matches[0], 10);
				var duration = Q.getObject("Users.authenticate.expires") || 86400;

				if (ts < (Date.now() / 1000) - duration) {
					throw new Error("Expired");
				}

				// Re-send to server exactly like Telegram does
				Users.authPayload = Users.authPayload || {};
				Users.authPayload.web3 = {
					xid: xid,
					payload: payload,
					signature: signature,
					platform: 'web3'
				};

				Users.authenticate('web3',
					function (user) {
						priv.handleXid(
							platform,
							platformAppId,
							xid,
							onSuccess,
							onCancel,
							Q.extend({ prompt: false }, options)
						);
					},
					function (err) {
						Q.cookie(cookieName, null, { path: '/' });
						if (onCancel) onCancel(err);
					},
					Q.extend({ prompt: false }, options)
				);

				return;
			}
		} catch (e) {
			Q.cookie(cookieName, null, { path: '/' });
		}

		// ============================================================
		// CASE 2: Intent-Based Flow
		// ============================================================

		_intentBasedFlow();

		function _intentBasedFlow() {

			var provider = window.ethereum || Web3.provider || null;
			var subscribed = false;

			function _subscribe(p) {
				if (subscribed || !p || !p.on) return;
				p.on("accountsChanged", function () {
					Q.handle(Web3.onAccountsChanged, p);
				});
				p.on("chainChanged", function () {
					Q.handle(Web3.onChainChanged, p);
				});
				subscribed = true;
			}

			function _ensureProvider(callback) {
				if (provider && provider.request) {
					_subscribe(provider);
					return callback(null, provider);
				}

				var tout = setTimeout(function () {
					callback(new Error("No provider"));
				}, 1000);

				window.addEventListener("eip6963:announceProvider", function (ev) {
					provider = ev.detail.provider;
					clearTimeout(tout);
					_subscribe(provider);
					callback(null, provider);
				}, { once: true });

				window.dispatchEvent(new Event('eip6963:requestProvider'));
			}

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

						Q.cookie(cookieName,
							JSON.stringify([payload, signature]),
							{ path: '/', maxAge: 86400 * 7 }
						);

						Users.authPayload = Users.authPayload || {};
						Users.authPayload.web3 = {
							xid: address,
							payload: payload,
							signature: signature,
							platform: 'web3',
							chainId: (typeof p.chainId === 'function')
								? p.chainId()
								: p.chainId
						};

						Users.authenticate('web3',
							function (user) {
								if (onSuccess) {
									onSuccess(user);
								}
							},
							function () {
								if (onCancel) {
									onCancel();
								}
							},
							Q.extend({ prompt: false }, options)
						);
					});
				})
				.catch(function (ex) {
					if (onCancel) {
						onCancel(ex);
					}
				});
			}

			Users.init.web3(function () {

				_ensureProvider(function (err, p) {

					if (p && p.request) {
						Users.Intent.provision(
							"Users/authenticate",
							"web3",
							Q.app,
							function (slots) {
								if (!slots) {
									return onCancel && onCancel("Provision failed");
								}
								_signAndAuthenticate(p);
							}
						);
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
			});

			Q.onVisibilityChange.setOnce(function (isShown) {
				if (!isShown) return;

				Q.req("Users/session", ["result"], function (err, r) {
					if (r && r.slots && r.slots.result === "authenticated") {
						Q.loadUrl(location.href, {
							loadExtras: "all",
							slotNames: Q.info.slotNames,
							ignoreDialogs: true,
							ignorePage: false,
							ignoreHistory: true,
							quiet: true
						});
					}
				});
			}, "Q.Users.authenticate.web3");
		}
	};
});
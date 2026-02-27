Q.exports(function (Users, priv) {

	var Web3 = Users.Web3;

	return function web3(platform, platformAppId, onSuccess, onCancel, options) {

		options = options || {};
		var cookieName = 'w3sr_' + platformAppId;

		// ============================================================
		// COOKIE RESUME (same as original behavior)
		// ============================================================

		try {
			var w3sr_json = Q.cookie(cookieName);

			if (w3sr_json) {
				var w3sr = JSON.parse(w3sr_json);

				var xid = ethers.utils.verifyMessage(w3sr[0], w3sr[1]);
				if (!xid) {
					throw new Error("Bad signature");
				}

				Users.authPayload = Users.authPayload || {};
				Users.authPayload.web3 = {
					xid: xid,
					payload: w3sr[0],
					signature: w3sr[1],
					platform: 'web3'
				};

				Users.authenticate('web3',
					function (user) {
						if (onSuccess) {
							onSuccess(user);
						}
					},
					function () {
						Q.cookie(cookieName, null, { path: '/' });
						_intentFlow();
					},
					Q.extend({ prompt: false }, options)
				);

				return;
			}

		} catch (e) {
			Q.cookie(cookieName, null, { path: '/' });
		}

		// ============================================================
		// INTENT FLOW
		// ============================================================

		_intentFlow();

		function _intentFlow() {

			Web3.connect(function (err, provider) {

				if (err || !provider) {
					if (onCancel) {
						onCancel(err);
					}
					return;
				}

				Web3.provider = provider;

				var payload = Q.text.Users.login.web3.payload.interpolate({
					host: location.host,
					timestamp: Math.floor(Date.now() / 1000)
				});

				(new ethers.providers.Web3Provider(provider, 'any'))
				.listAccounts()
				.then(function (accounts) {

					if (!accounts || !accounts.length) {
						if (onCancel) {
							onCancel("No accounts");
						}
						return;
					}

					var address = accounts[0];

					// Ask intent from server
					Users.Intent.provision(
						"Users/authenticate",
						"web3",
						Q.app,
						function (slots) {

							if (!slots) {
								if (onCancel) {
									onCancel("Provision failed");
								}
								return;
							}

							var intent = slots.capability.token || slots.capability;

							var msg = '0x' + Buffer.from(payload, "utf8").toString("hex");

							provider.request({
								method: "personal_sign",
								params: [msg, address]
							})
							.then(function (signature) {

								// Store resume cookie
								Q.cookie(cookieName, JSON.stringify([payload, signature]), {
									path: '/',
									maxAge: 86400 * 7
								});

								Users.authPayload = Users.authPayload || {};
								Users.authPayload.web3 = {
									xid: address,
									payload: payload,
									signature: signature,
									intent: intent,
									platform: 'web3',
									chainId: (typeof provider.chainId === 'function')
										? provider.chainId()
										: provider.chainId
								};

								if (Q.handle(onSuccess, null, [Users.authPayload.web3]) === false) {
									return;
								}

								Users.authenticate(
									'web3',
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

							})
							.catch(function (ex) {
								if (onCancel) {
									onCancel(ex);
								}
							});
						}
					);
				})
				.catch(function (ex) {
					if (onCancel) {
						onCancel(ex);
					}
				});
			});
		}
	};
});
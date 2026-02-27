Q.exports(function (Users, priv) {

	var Web3 = Users.Web3;

	return function web3(platform, platformAppId, onSuccess, onCancel, options) {

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
	};
});
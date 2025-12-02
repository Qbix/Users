Q.exports(function (Users, priv) {

	var Web3 = Users.Web3;

	/**
	 * Authenticate this session using a connected Web3 wallet.
	 * Works via the intent-based flow (Users/intent → Users/authenticate/web3),
	 * and can also resume via a signed cookie (`w3sr_*`) previously issued by the server.
	 * @method web3
	 * @param {String} platform "web3"
	 * @param {String} platformAppId  appId or "all"
	 * @param {Function} onSuccess  called when authenticated
	 * @param {Function} onCancel   called on cancellation or failure
	 * @param {Object} [options]
	 *   @param {Boolean} [options.force=false]
	 *   @param {String} [options.appId=Q.info.app]
	 */
	return function web3(platform, platformAppId, onSuccess, onCancel, options) {
		options = options || {};

		const cookieName = 'w3sr_' + platformAppId;
		let xid = null;

		// ============================================================
		// CASE 1: Try cookie resume (fast path)
		// ============================================================
		try {
			const w3sr_json = Q.cookie(cookieName);
			if (w3sr_json) {
				const w3sr = JSON.parse(w3sr_json);
				const hash = ethers.utils.hashMessage(w3sr[0]);
				xid = ethers.utils.recoverAddress(hash, w3sr[1]);
				if (!xid) throw new Error("Bad signature");

				const matches = w3sr[0].match(/[\d]{8,12}/);
				if (!matches) throw new Error("w3sr cookie missing timestamp");
				if (Q.Users.authenticate.expires &&
					matches[0] < Date.now() / 1000 - Q.Users.authenticate.expires) {
					throw new Error("w3sr token expired");
				}

				Q.req('Users/authenticate', function (err, response) {
					if (err) {
						console.warn('Web3 authenticate failed (cookie):', err);
						Q.cookie(cookieName, null, { path: '/' });
						return _intentBasedFlow();
					}
					priv.handleXid(
						platform,
						platformAppId,
						(response.user && response.user.id) || xid,
						onSuccess,
						onCancel,
						Q.extend({ response, prompt: false }, options)
					);
				}, {
					method: 'POST',
					fields: {
						platform: 'web3',
						appId: options.appId || platformAppId,
						updateXid: true
					}
				});
				return;
			}
		} catch (e) {
			console.warn('Web3 cookie resume failed:', e);
			Q.cookie(cookieName, null, { path: '/' });
		}

		// ============================================================
		// CASE 2: No valid cookie → Intent-based Web3 authentication
		// ============================================================
		_intentBasedFlow();

		function _intentBasedFlow() {
			let provider = window.ethereum || (window.Web3 && Web3.provider) || null;
			let wallets = Q.getObject("web3.wallets", Users);
			let _subscribed = false;

			function _subscribeToEvents(p) {
				if (_subscribed || !p || !p.on) return;
				p.on("accountsChanged", () => Q.handle(Web3.onAccountsChanged, p));
				p.on("chainChanged", () => Q.handle(Web3.onChainChanged, p));
				_subscribed = true;
			}

			function _ensureProvider(cb) {
				if (provider && provider.request) {
					_subscribeToEvents(provider);
					return cb(null, provider);
				}
				const tout = setTimeout(() => cb(new Error("No provider")), 1000);
				window.addEventListener("eip6963:announceProvider", ev => {
					provider = ev.detail.provider;
					clearTimeout(tout);
					_subscribeToEvents(provider);
					cb(null, provider);
				}, { once: true });
				window.dispatchEvent(new Event('eip6963:requestProvider'));
			}

			function _signAndSend(p, intent) {
				p.request({ method: 'eth_requestAccounts' })
					.then(accounts => {
						const address = accounts[0];
						const message = Q.text.Users.login.web3.SignMessage.interpolate({ intent });
						return p.request({
							method: 'personal_sign',
							params: [message, address]
						}).then(signature => ({ address, signature, message }));
					})
					.then(res => {
						// cache signature cookie for fast resume
						Q.cookie(cookieName, JSON.stringify([res.message, res.signature]), {
							path: '/', maxAge: 86400 * 7
						});
						Q.req('Users/authenticate/web3', function (err, r) {
							if (err) return onCancel && onCancel(err, options);
							priv.handleXid(
								platform,
								platformAppId,
								(r.user && r.user.id) || null,
								onSuccess,
								onCancel,
								Q.extend({ response: r, prompt: false }, options)
							);
						}, {
							method: 'POST',
							fields: {
								intent: intent,
								address: res.address,
								signature: res.signature
							}
						});
					})
					.catch(ex => onCancel && onCancel(ex, options));
			}

			function _walletConnect() {
				const modal = $("w3m-modal");
				modal.addClass("Q_floatAboveDocument").css({
					position: "fixed",
					"z-index": Q.zIndexTopmost() + 1
				});
				Web3.walletConnectProvider.on("connect", info => {
					const p = Web3.walletConnectProvider;
					p.request({ method: 'eth_requestAccounts' }).then(() => {
						Web3.provider = p;
						_subscribeToEvents(p);
						Q.handle(Web3.onConnect, p, [info]);
					});
				});
				Web3.walletConnectProvider.connect();
			}

			Users.init.web3(() => {
				_ensureProvider((err, p) => {
					if (p && p.request) {
						Users.Intent.provision("Users/authenticate", "web3", Q.app, slots => {
							if (!slots) return onCancel && onCancel("Provision failed");
							_signAndSend(p, slots.capability.token || slots.capability);
						});
						return;
					}

					if (!wallets) return onCancel && onCancel(err || "No wallets configured");

					delete wallets.walletconnect;

					Q.Template.set("Users/web3/connect/wallet", `
						<ul>
							{{#each wallets}}
								<li>
									<a 
										{{#if url}}href="{{url}}"{{/if}}
										{{#if data-url}}data-url="{{data-url}}"{{/if}}
										style="background-image:url({{img}})">
										{{name}}
									</a>
								</li>
							{{/each}}
						</ul>
					`);

					let dialog, timeoutId;
					Q.Dialogs.push({
						title: Q.text.Users.login.web3.ConnectWallet,
						className: "Users_connect_wallets",
						content: "",
						stylesheet: "{{Users}}/css/Users/wallets.css",
						onActivate: function (d) {
							dialog = d;
							const u = new URL(location.href);
							Users.Intent.provision("Users/authenticate", "web3", Q.app, slots => {
								if (!slots) return onCancel && onCancel("Provision failed");
								const capability = slots.capability;
								const urlParams = {
									intent: capability.token,
									url: u.href,
									urlEncoded: encodeURIComponent(u.href),
									urlWithoutScheme: u.href.replace(/^[^:]+:\/\//, '')
								};
								const cWallets = Q.extend({}, wallets);
								Q.each(cWallets, (i, val) => {
									cWallets[i].img = Q.url("{{Users}}/img/web3/wallet/" + i + ".png");
									if (val.url)
										cWallets[i].url = val.url.interpolate(urlParams);
									else
										cWallets[i]["data-url"] = i;
								});
								Q.Template.render("Users/web3/connect/wallet", { wallets: cWallets }, (err, html) => {
									Q.replace($(".Q_dialog_content", dialog)[0], html);
									$("a", dialog).on(Q.Pointer.fastclick, function (e) {
										e.preventDefault();
										const key = this.getAttribute("data-url");
										if (key === "walletconnect") return _walletConnect();
										const wallet = cWallets[key];
										if (!wallet) return;
										Users.Intent.start(capability, {
											platform: "web3",
											url: wallet.url,
											skip: { QR: true }
										});
									});
								});
							});

							Web3.onConnect.set(() => {
								setTimeout(() => Q.Dialogs.close(dialog), 500);
							}, "Users_connect_wallets");

							timeoutId = setTimeout(() => {
								Q.Dialogs.close(dialog);
							}, 60000);
						},
						onClose: function () {
							if (timeoutId) clearTimeout(timeoutId);
						}
					});
				});
			});

			// Refresh the page if session becomes authenticated after returning from wallet app
			Q.onVisibilityChange.setOnce(isShown => {
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
					} else if (err) {
						console.warn("Web3 auth refresh failed:", err);
					}
				});
			}, "Q.Users.authenticate.web3");
		}
	};
});
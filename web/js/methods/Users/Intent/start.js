Q.exports(function (Users, priv) {
	/**
	 * Methods for user intents
	 * @module Users
	 * @class Users.Intents
	 */

	/**
	 * Starts or provisions a Users.Intent, optionally showing a QR or
	 * performing a synchronous redirect when no capability is cached.
	 *
	 * @method start
	 * @static
	 * @param {Object|String} capability the capability from Users.Intent.provision,
	 *   otherwise specify options.action (e.g. "Users/authenticate") to trigger fallback
	 * @param {Object} [options]
     * @param {String} [options.action] If capability is empty, specify this
     * @param {String} [options.platform] If capability is empty, specify this
     * @param {String} [options.appId] If capability is empty, specify this
     * @param {String} [options.url] Can be used to override url
     * @param {String} [options.interpolate] Any additional fields to interpolate into url
	 * @param {String} [options.interpolateQR] Optionally provide different fields to interpolate into QR code URL
	 * @param {Boolean} [options.skip] Used to skip one or both actions
	 * @param {Boolean} [options.skip.redirect] skip redirect
	 * @param {Boolean} [options.skip.QR] skip showing QR code
	 * @param {Function} [options.onSuccess] called on intent start success
	 * @param {Function} [options.onFailure] call on intent start failure
	 */
	return function Users_Intent_start(capability, options) {
		return new Q.Promise(function (_resolve, _reject) {
			options = Q.extend({skip: {}}, options);
			var token = options.token;

			if (options.action && options.platform) {
				var appId = options.appId || Q.info.app;
				var info = Q.getObject(
					[options.action, options.platform, appId],
					Users.Intent.provision.results
				) || {};
				capability = capability || info.capability;
				token = token || info.token;
			}
			if (!capability && options.action && options.platform
			&& !options.skip.redirect) {
				_waitAndReload(options);
				// Just perform a synchronous redirect without provisioned capability
				// NOTE: some apps may disallow this for security reasons
				location.href = Q.action('Users/intent', {
					action: options.action,
					platform: options.platform,
					interpolate: options.interpolate
				});
				return;
			}

			// At this point we have a valid capability object and redirect url
			var fields = {
				capability: capability,
				action: options.action || capability.action,
				platform: options.platform || capability.platform,
				appId: options.appId || capability.appId || Q.info.app,
				interpolate: options.interpolate,
				url: location.href,
				'Q.clientId': Q.clientId()
			};

			// Generate intent server-side (idempotent).
			// NOTE: slot names MUST be passed explicitly. Q.request() sets
			// slotNames = [] when the second argument is a function, so
			// omitting them makes the server return no slots at all and
			// response.slots.capability comes back undefined.
			Q.req('Users/intent', ['capability', 'token'], function (err, response) {
				if (err) {
					console.warn('Intent start failed:', err);
					_reject(err);
					return;
				}

				Users.Intent.onStarted(fields.platform).handle.call(Users.Intent, fields);
				_resolve(response);

				token = token || Q.getObject('slots.token', response);

				var socketCapability = Q.getObject('slots.capability', response);
				if (!socketCapability) {
					return;
				}

				if (!Q.Socket.isConnected('/Q')) {
					Q.Socket.connect('/Q', {
						capability: socketCapability
					});
				}
			}, {
				method: 'post',
				fields: fields
			});

			var apps = Users.apps[fields.platform] || {};
			// if (!apps[fields.appId]) {
			// 	return false;
			// }

			var url = options.url || Q.getObject([
				fields.action, fields.platform, 'redirect'
			], Users.Intent.actions);
			if (!url) {
				return false;
			}
			url = Q.interpolateUrl(url, Q.extend({
				token: token
			}, options.interpolate, apps[fields.appId]));

			var _reload = _waitAndReload(options);

			if (!Q.info.isMobile && !options.skip.QR) {
				var dialog = Q.Dialogs.push({
					title: "Scan this code to continue",
					onActivate: function (container) {
						Q.addScript("{{Q}}/js/qrcode/qrcode.js", function () {
							var element = Q.element("div");
							element.style.textAlign = "center";
							element.style.padding = "20px";

							try {
								new QRCode(element, {
									text: Q.url("Users/intent", {
										capability: capability,
										action: fields.action,
										platform: fields.platform,
										interpolate: options.interpolateQR || options.interpolate || {}
									}),
									width: 250,
									height: 250,
									colorDark: "#000000",
									colorLight: "#ffffff",
									correctLevel: QRCode.CorrectLevel.H
								});
							} catch (e) {
								console.error("Error rendering QRCode:", e);
							}
							element.addClass('Q_QR_code');
							container.querySelector('.Q_dialog_content')
								.append(element);
						});

						// Distinct key: this used to reuse 'Q.Intent.start',
						// which silently replaced the handler registered by
						// _waitAndReload(), so on desktop the reload never ran.
						Q.onVisibilityChange.set(function (isShown) {
							if (!isShown) return;
							Q.Dialogs.close(dialog);
							_reload();
						}, 'Q.Intent.start.QR');
					},
					onClose: function () {
						Q.onVisibilityChange.remove('Q.Intent.start.QR');
						_reload();
					}
				});
			}

			if (!options.skip.redirect
			&& !url.startsWith('https://')
			&& !url.startsWith('http://')) {
				// try opening a custom app via its schema
				window.location = url;
			}

			return url;
		});
	};

	function _waitAndReload(options) {
		var key = 'Q.Intent.start';
		// make a debounced function just in case it's hit
		// from more than one approach
		var _reload = Q.debounce(function () {
			if (Q.isDocumentHidden()) {
				return;
			}
			// Check if user changed before reload.
			// NOTE: ['user'] MUST be passed. Without it Q.request() uses
			// slotNames = [], the server returns {} for slots, and the
			// check below silently bailed out every single time --
			// which is why returning to the browser never refreshed.
			Q.req('Users/loggedInUser', ['user'], function (err, response) {
				var user = Q.getObject('slots.user', response);
				if (!user || Users.loggedInUserId() == user.id) {
					return;
				}
				_stopWaiting();
				Users.loggedInUser = new Users.User(user);
				Q.loadUrl(location.href, {
					slotNames: Q.info.slotNames,
					loadExtras: 'all',
					ignoreDialogs: true,
					ignorePage: false,
					ignoreHistory: true,
					quiet: true,
					onActivate: function () {
						Q.handle(Users.onLogin, Users, [Users.loggedInUser]);
						if (options && options.onActivate) {
							Q.handle(options.onActivate, Users.Intent, [options]);
						}
					}
				});
			});
		}, 500);

		function _stopWaiting() {
			Q.onVisibilityChange.remove(key);
			window.removeEventListener('focus', _reload);
			window.removeEventListener('pageshow', _reload);
		}

		// Stay registered until we actually observe a login, not until the
		// first visibility change. On iOS, handing off to another app fires
		// hidden -> visible during the launch animation, which used to
		// consume and remove this handler before the user ever left.
		Q.onVisibilityChange.set(function (isShown) {
			if (!isShown) return;
			_reload();
		}, key);

		// pageshow is the one that reliably fires when iOS Safari restores
		// the page from bfcache on return from another app; visibilitychange
		// and focus are both unreliable in that path.
		window.addEventListener('pageshow', _reload);
		window.addEventListener('focus', _reload);

		Q.Socket.onEvent('Users/intentComplete', '/Q')
			.setOnce(_reload, 'Users.Intent.start');
		return _reload;
	}
});

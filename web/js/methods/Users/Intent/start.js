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
	 *   or just an action name (e.g. "Users/authenticate") to trigger fallback
	 * @param {Object} [options]
     * @param {String} [options.action] If capability is empty, specify this
     * @param {String} [options.platform] If capability is empty, specify this
     * @param {String} [options.appId] If capability is empty, specify this
     * @param {String} [options.url] Can be used to override url
     * @param {String} [options.interpolate] Any additional fields to interpolate into url
	 * @param {String} [options.interpolateQR] Optionally provide different fields to interpolate into QR code URL
	 * @param {Boolean} [options.skip]
	 * @param {Boolean} [options.skip.redirect]
	 * @param {Boolean} [options.skip.QR]
	 */
	return function Users_Intent_start(capability, options) {
		options = Q.extend({skip: {}}, options);
		var token = options.token;

		if (options.action && options.platform) {
			var appId = options.appId || Q.info.app;
			var info = Q.getObject(
				[options.action, options.platform, appId],
				Users.Intent.provision.results
			);
			capability = capability || info.capability;
			token = token || info.token;
		}
		if (!capability && options.action && options.platform
        && !options.skip.redirect) {
			_waitAndReload();
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
			interpolate: options.interpolate
		};

		// Generate intent server-side (idempotent)
		Q.req('Users/intent', function (err) {
			if (err) console.warn('Intent provisioning failed:', err);
		}, {
			method: 'post',
			fields: fields
		});

		Users.Intent.onStarted(fields.platform).handle.call(Users.Intent, fields);

		var apps = Users.apps[fields.platform] || {};
		if (!apps[fields.appId]) {
			return false;
		}

		var url = options.url || Q.getObject([
			fields.action, fields.platform, 'redirect'
		], Users.Intent.actions);
		if (!url) {
			return false;
		}
		url = url.interpolate(Q.extend({
			token: token
		}, options.interpolate, apps[fields.appId]));

		var _reload = _waitAndReload();

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

					Q.onVisibilityChange.set(function (isShown) {
						if (!isShown) return;
						Q.Dialogs.close(dialog);
						_reload();
						Q.onVisibilityChange.remove('Q.Intent.start');
					}, 'Q.Intent.start');
				},
				onClose: _reload
			});
		}

		if (!options.skip.redirect) {
			window.location = url;
		}

        return url;
	};

	function _waitAndReload() {
		// make a debounced function just in case it's hit
		// from more than one approach
		var _reload = Q.debounce(function () {
			if (Q.isDocumentHidden()) {
				return;
			}
			// Check if user changed before reload
			Q.req('Users/loggedInUser', function (err, response) {
				var user = Q.getObject('slots.user', response);
				if (!user || Users.loggedInUserId() == user.id) {
					return;
				}
				Users.loggedInUser = new Users.User(response.slots.user);
				Q.loadUrl(location.href, {
					slotNames: Q.info.slotNames,
					loadExtras: 'all',
					ignoreDialogs: true,
					ignorePage: false,
					ignoreHistory: true,
					quiet: true
				});
			});
			window.removeEventListener('focus', _reload);
		}, 500);
		Q.onVisibilityChange.set(function (isShown) {
			if (!isShown) return;
			_reload();
			Q.onVisibilityChange.remove('Q.Intent.start');
		}, 'Q.Intent.start');
		window.addEventListener('focus', _reload);
		return _reload;
	}
});
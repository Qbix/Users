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
	 * @param {Boolean} [options.skip]
	 * @param {Boolean} [options.skip.redirect]
	 * @param {Boolean} [options.skip.QR]
	 */
	return function Users_Intent_start(capability, options) {
		options = Q.extend({skip: {}}, options);

        if (!capability || !capability.sig) {
            var appId = options.appId || Q.info.app;
            capability = Q.getObject(
                [options.action, options.platform, appId, 'capability'],
                Users.Intent.provision.results
            )
        }
		if (!capability && options.action && options.platform
        && !options.skip.redirect) {
            // Just perform a synchronous redirect without provisioned capability
            // NOTE: some apps may disallow this for security reasons
            location.href = Q.action('Users/intent', {
                action: options.action,
                platform: options.platform,
                interpolate: options.interpolate
            });
            Q.onVisibilityChange.setOnce(function (isShown) {
                if (!isShown) return;
                Q.loadUrl(location.href, {
                    slotNames: Q.info.slotNames,
                    loadExtras: 'all',
                    ignoreDialogs: true,
                    ignorePage: false,
                    ignoreHistory: true,
                    quiet: true
                });
            }, 'Telegram');
            return;
		}

		// At this point we have a valid capability object
		var fields = { capability: capability };
		var action = capability.action;
		var platform = capability.platform;
		var appId = capability.appId || Q.info.appId;

		// Provision intent server-side (idempotent)
		Q.req('Users/intent', function (err) {
			if (err) console.warn('Intent provisioning failed:', err);
		}, {
			method: 'post',
			fields: fields
		});

		var apps = Users.apps[platform] || [];
		if (!apps[appId]) {
			return false;
		}

		var url = options.url || Q.getObject([action, platform, 'redirect'], Users.Intent.actions);
		if (!url) {
			return false;
		}

		if (!options.skip.QR) {
			var dialog = Q.Dialogs.push({
				title: "Scan this code to continue",
				onActivate: function () {
					Q.addScript("{{Q}}/js/qrcode/qrcode.js", function () {
						var element = Q.element("div");
						element.style.textAlign = "center";
						element.style.padding = "20px";

						try {
							new QRCode(element, {
								text: Q.url("Users/intent", capability),
								width: 250,
								height: 250,
								colorDark: "#000000",
								colorLight: "#ffffff",
								correctLevel: QRCode.CorrectLevel.H
							});
						} catch (e) {
							console.error("Error rendering QRCode:", e);
						}
					});

					Q.onVisibilityChange.setOnce(function (isShown) {
						if (!isShown) return;

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

						Q.Dialogs.close(dialog);
					}, 'Telegram');
				}
			});
		}

		if (!options.skip.redirect) {
			url = url.interpolate(apps[appId]);
			window.location = url;
		}

        return url;
	};
});
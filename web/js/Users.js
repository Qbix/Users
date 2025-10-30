Q.exports(function (Users, priv) {
	/**
	 * Authenticates this session with a given platform,
	 * if the user was already connected to it.
	 * It tries to do so by checking a cookie that would have been set by the server,
	 * or Telegram Mini App context if available.
	 * @method authenticate
	 * @param {String} platform Currently it's `telegram`
	 * @param {String} platformAppId platformAppId
	 * @param {Function} onSuccess Called if the user successfully authenticates with the platform, or was already authenticated.
	 *  It is passed the user information if the user changed.
	 * @param {Function} onCancel Called if the authentication was canceled. Receives err, options
	 * @param {Object} [options] object of parameters for authentication function
	 *   @param {Function|Boolean} [options.prompt=null] which shows the usual prompt unless it was already rejected once.
	 *   @param {Boolean} [options.force] forces a status refresh
	 *   @param {String} [options.appId=Q.info.app] Only needed if you have multiple apps on platform
	 *   @param {Boolean} [options.startapp=false] set to true to use the Mini App flow (`startapp`)
	 *   @param {String} [options.startappName] optional Telegram Mini App short name (for future use)
	 */
	function telegram(platform, platformAppId, onSuccess, onCancel, options) {
		options = options || {};

		var initData = null;
		var unsafe = null;
		var xid = null;

		try {
			if (window.Telegram && window.Telegram.WebApp) {
				unsafe = Telegram.WebApp.initDataUnsafe;
				initData = Telegram.WebApp.initData || null;
				if (unsafe && unsafe.user && unsafe.user.id) {
					xid = unsafe.user.id;
				}
			}
		} catch (e) {
			if (console && console.warn) {
				console.warn('Telegram context initialization error:', e);
			}
		}

		var cookieName = 'tgsr_' + platformAppId;
		var hasCookie = !!Q.cookie(cookieName);
		var hasInitData = !!initData;

		// If either cookie or initData exists → ask the server to verify
		if (hasCookie || hasInitData) {
			// Prepare payload for handleXid chain as well
			Q.Users.authPayload = Q.Users.authPayload || {};
			if (hasInitData) {
				Q.Users.authPayload.telegram = {
					xid: xid,
					payload: initData,
					platform: 'telegram'
				};
			} else {
				// even without initData, create minimal payload
				Q.Users.authPayload.telegram = {
					xid: xid,
					platform: 'telegram'
				};
			}

			var fields = { platform: 'telegram' };
			if (hasInitData) {
				fields['Q.Users.authPayload.telegram'] = initData;
			}

			Q.req(
				'Users/authenticate',
				function (err, response) {
					if (err) {
						if (console && console.warn) {
							console.warn('Telegram authenticate failed:', err);
						}
						if (typeof onCancel === 'function') {
							onCancel(err, options);
						}
						Q.cookie(cookieName, null, { path: '/' });
						Q.cookie(cookieName + '_expires', null, { path: '/' });
						return;
					}

					var userId =
						(response && response.user && response.user.id) ||
						xid ||
						null;

					priv.handleXid(
						platform,
						platformAppId,
						userId,
						onSuccess,
						onCancel,
						Q.extend({ response: response }, options)
					);
				},
				{
					method: 'POST',
					fields: fields
				}
			);

			return;
		}

		// CASE 3: No cookie, no initData → synchronous redirect to Telegram intent
		var parameter = options.startapp ? 'startapp' : 'start';
		var interpolate = { parameter: parameter };
		if (options.startappName) {
			interpolate.shortName = options.startappName;
		}

		// Synchronous navigation to Telegram app (must happen in user gesture)
		location.href = Q.action('Users/intent', {
			action: 'Users/authenticate',
			platform: 'telegram',
			interpolate: interpolate
		});

		// After returning from Telegram, refresh page and re-trigger handleXid if needed
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
	}

	return telegram;
});

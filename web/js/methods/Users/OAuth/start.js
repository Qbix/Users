Q.exports(function (Users, priv) {

	/**
	 * Drives an OAuth 2.0 flow over the Users.Intent system and reports the
	 * outcome. Opens the Users/oauth handler in a popup (synchronously, inside the
	 * user gesture so it isn't blocked), creates the server-side intent via
	 * Users.Intent.start, then points the popup at the handler with the intent
	 * token. Pass openWindow:false to run the flow full-page (e.g. mobile, where
	 * popups can't self-close).
	 *
	 * provision() only reserves a token + capability (no DB write); Intent.start()
	 * is what actually creates the intent server-side, so the token resolves only
	 * after start runs. Completion is read server-side from the intent row.
	 *
	 * @method start
	 * @static
	 * @param {String} platform A platform under Q.plugins.Users.apps (e.g. "twitter")
	 * @param {String|Array} [scope] Advisory; the server builds the authorize URL from config.
	 * @param {Function} callback Receives (err, result). result is
	 *   {token, completed:true, xid} on success, or null if canceled.
	 *   Not called on the full-page path (the page navigates away).
	 * @param {Object} [options]
	 *   @param {String} [options.appId=Q.info.app] Internal appId under Users.apps[platform]
	 *   @param {Window} [options.popup] A window opened synchronously by the caller, to reuse
	 *   @param {Object|String|Boolean} [options.openWindow={}] window.open features string,
	 *     or false to run the flow full-page
	 *   @param {String} [options.finalRedirect=location.href] Where the full-page flow returns
	 */
	return function Users_OAuth_start(platform, scope, callback, options) {
		options = options || {};
		var appId = options.appId || Q.info.app;
		var openWindow = ('openWindow' in options) ? options.openWindow : {};
		var action = 'Users/authenticate';

		function _fields(token) {
			return { intent: token, platform: platform, appId: appId };
		}

		function _fullPage(token) {
			var fields = _fields(token);
			fields.finalRedirect = options.finalRedirect || location.href;
			location.href = Q.url('Users/oauth', fields);
		}

		function _poll(token, w) {
			var ival = setInterval(function () {
				if (w && !w.closed) {
					return;
				}
				clearInterval(ival);
				// one status check; the intent row is the source of truth
				Q.req('Users/oauth', ['completed', 'ok', 'xid'], function (err, response) {
					var fem = Q.firstErrorMessage(err, response);
					if (fem) {
						return callback && callback(fem);
					}
					var ok = Q.getObject('slots.ok', response);
					var xid = Q.getObject('slots.xid', response);
					callback && callback(null,
						ok ? { token: token, completed: true, xid: xid } : null
					);
				}, {
					method: 'get',
					fields: { intent: token, check: 1, platform: platform }
				});
			}, 300);
		}

		// Point the (already-open) window at our handler, or go full-page.
		function _drive(token, w) {
			if (openWindow === false || !w) {
				return _fullPage(token); // full-page, or popup was blocked
			}
			var url = Q.url('Users/oauth', _fields(token));
			try { w.location.href = url; }
			catch (e) { w = window.open(url, 'Q_Users_oauth'); }
			if (!w) {
				return _fullPage(token);
			}
			_poll(token, w);
		}

		// provision() only reserves the token; Intent.start() creates the intent
		// server-side. Grab (or provision) the capability, start the intent, proceed.
		function _withIntent(proceed) {
			var info = Q.getObject(
				[action, platform, appId],
				Users.Intent.provision.results
			) || {};

			function go(capability, token) {
				if (!capability || !token) {
					return callback && callback("Users.OAuth.start: could not provision an intent");
				}
				// create the intent server-side (+ wire the completion socket);
				// skip the QR / scheme-redirect UI, since we run our own window
				Users.Intent.start(capability, {
					action: action,
					platform: platform,
					appId: appId,
					skip: { redirect: true, QR: true }
				});
				proceed(token);
			}

			if (info.capability && info.token) {
				go(info.capability, info.token);
			} else {
				Users.Intent.provision(action, platform, appId, function (slots) {
					go(slots && slots.capability, slots && slots.token);
				});
			}
		}

		// Open the window now, inside the gesture, so it isn't blocked; we point it
		// at the handler once the intent exists. The full-page path opens nothing.
		var w = (openWindow === false)
			? null
			: (options.popup || window.open('', 'Q_Users_oauth',
				typeof openWindow === 'string' ? openWindow : 'width=620,height=720'));

		_withIntent(function (token) {
			_drive(token, w);
		});
	};

});
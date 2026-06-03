Q.exports(function (Users, priv) {

	/**
	 * Drives an OAuth 2.0 flow over the Users.Intent system and reports the
	 * outcome. If an intent token is already cached (provisioned ahead of time),
	 * this opens the Users/oauth handler in a popup synchronously — so it must be
	 * called from within the user gesture. If no token is cached, it provisions
	 * one and, since that is async (and a popup opened from an async callback would
	 * be blocked), falls back to a full-page redirect. Pass openWindow:false to
	 * force the full-page path (e.g. on mobile, where popups can't self-close).
	 *
	 * Completion is read server-side from the intent row, so nothing depends on
	 * reading across origins.
	 *
	 * @method start
	 * @static
	 * @param {String} platform A platform under Q.plugins.Users.apps (e.g. "twitter")
	 * @param {String|Array} [scope] Advisory; the server builds the authorize URL
	 *   from config. Arrays are space-joined by the server side.
	 * @param {Function} callback Receives (err, result). result is
	 *   {token, completed:true, xid} on success, or null if the flow was canceled.
	 *   Not called on the full-page path (the page navigates away).
	 * @param {Object} [options]
	 *   @param {String} [options.appId=Q.info.app] Internal appId under Users.apps[platform]
	 *   @param {String} [options.token] An already-provisioned intent token to reuse
	 *   @param {Window} [options.popup] A window opened synchronously by the caller,
	 *     to be reused even on the async (provision) path
	 *   @param {Object|String|Boolean} [options.openWindow={}] window.open features string,
	 *     or false to run the flow full-page
	 *   @param {String} [options.finalRedirect=location.href] Where the full-page flow returns
	 */
	return function Users_OAuth_start(platform, scope, callback, options) {
		options = options || {};
		var appId = options.appId || Q.info.app;
		var openWindow = ('openWindow' in options) ? options.openWindow : {};

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

		function _popup(token, w) {
			var url = Q.url('Users/oauth', _fields(token));
			if (w) {
				try { w.location.href = url; }
				catch (e) { w = window.open(url, 'Q_Users_oauth'); }
			} else {
				w = window.open(url, 'Q_Users_oauth',
					typeof openWindow === 'string' ? openWindow : 'width=620,height=720');
			}
			if (!w) {
				return _fullPage(token); // popup blocked
			}
			_poll(token, w);
		}

		var token = options.token || Q.getObject(
			['Users/authenticate', platform, appId, 'token'],
			Users.Intent.provision.results
		);

		if (token) {
			// synchronous: still inside the gesture, so the popup opens cleanly
			if (openWindow === false) { _fullPage(token); }
			else { _popup(token, options.popup); }
			return;
		}

		// No cached token: we must provision (async). For the popup path we open
		// the window now, while still in the gesture, and fill its URL once the
		// token arrives. Only an explicit openWindow:false goes full-page.
		if (openWindow === false) {
			Users.Intent.provision('Users/authenticate', platform, appId, function (slots) {
				var t = slots && slots.token;
				if (!t) {
					return callback && callback("Users.OAuth.start: could not provision an intent");
				}
				_fullPage(t);
			});
			return;
		}

		var w = options.popup || window.open('', 'Q_Users_oauth',
			typeof openWindow === 'string' ? openWindow : 'width=620,height=720');
		Users.Intent.provision('Users/authenticate', platform, appId, function (slots) {
			var t = slots && slots.token;
			if (!t) {
				if (w && !options.popup) { try { w.close(); } catch (e) {} }
				return callback && callback("Users.OAuth.start: could not provision an intent");
			}
			if (w) { _popup(t, w); }
			else { _fullPage(t); } // popup was blocked
		});
	};

});
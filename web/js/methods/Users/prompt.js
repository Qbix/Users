Q.exports(function (Users, priv) {

	/**
	 * Users plugin's front end code
	 *
	 * @module Users
	 * @class Users
	 */

	/**
	 * Used when platform user is logged in to platform but not to app.
	 * Shows prompt asking if user wants to log in to the app as platform user.
	 *
	 * Platform adapters may register:
	 *
	 *   Users.prompt[platform] = {
	 *     template: "Template/Name",
	 *     getData: function (context) { return {...}; }, // optional
	 *     render: function (context, container, done) {} // optional
	 *   };
	 *
	 * If `render` exists, it takes precedence over `template`.
	 * If `template` exists, it is rendered declaratively.
	 *
	 * @method prompt
	 * @static
	 * @param {String} platform The platform name (e.g. "facebook", "web3")
	 * @param {String} xid The platform xid
	 * @param {Function} authCallback Called after user authentication
	 * @param {Function} cancelCallback Called if user closes dialog
	 * @param {Object} options
	 *   @param {DOMElement} [options.dialogContainer=document.body]
	 *   @param {String} [options.appId=Q.info.app]
	 */
	return function Users_prompt(platform, xid, authCallback, cancelCallback, options) {

		options = Q.extend({}, options);

		var appId = options.appId || Q.info.app;
		var platformAppId = Users.getPlatformAppId(appId);
		var platformCapitalized = platform.toCapitalized();
		var dialogContainer = options.dialogContainer || document.body;

		Q.addStylesheet(Q.url('{{Users}}/css/Users.css'));

		var tookAction = false;
		var content_div = $('<div />');

		var context = {
			platform: platform,
			Platform: platformCapitalized,
			xid: xid,
			appId: appId,
			platformAppId: platformAppId,
			options: options
		};

		var adapter = Q.getObject(['prompt', platform], Users);

		var title = Q.text.Users.prompt.title.interpolate({
			platform: platform,
			Platform: platformCapitalized
		});

		// ============================================================
		// Helper: Confirm Binding
		// ============================================================

		function _attachConfirmHandler(container) {
			container.find('.Users_confirm').on(Q.Pointer.fastclick, function () {
				tookAction = true;
				Q.Dialogs.pop();
				authCallback && authCallback();
			});
		}

		// ============================================================
		// Adapter-driven rendering (render function takes precedence)
		// ============================================================

		if (adapter && typeof adapter.render === 'function') {

			adapter.render(context, content_div, function () {

				Q.Dialogs.push({
					title: title,
					content: content_div,
					onActivate: function () {
						_attachConfirmHandler(content_div);
					},
					onClose: function () {
						if (!tookAction && cancelCallback) {
							cancelCallback(xid);
						}
						tookAction = false;
					}
				});

			});

			return;
		}

		// ============================================================
		// Template-driven adapter
		// ============================================================

		if (adapter && adapter.template) {

			var data = Q.extend({}, context);

			if (typeof adapter.getData === 'function') {
				Q.extend(data, adapter.getData(context));
			}

			Q.Template.render(adapter.template, data, {
				activateInContainer: content_div[0]
			});

			Q.Dialogs.push({
				title: title,
				content: content_div,
				onActivate: function () {
					_attachConfirmHandler(content_div);
				},
				onClose: function () {
					if (!tookAction && cancelCallback) {
						cancelCallback(xid);
					}
					tookAction = false;
				}
			});

			return;
		}

		// ============================================================
		// No adapter? Just cancel cleanly.
		// ============================================================

		cancelCallback && cancelCallback(xid);
	};
});
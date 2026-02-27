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
	 * Platform adapters must register:
	 *
	 *   Users.prompt[platform] = {
	 *     template: "Template/Name",
	 *     getData: function (context) { return {...}; } // optional
	 *   };
	 *
	 * The adapter is purely declarative. It may provide template data,
	 * but cannot execute authentication logic directly.
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

		// ============================================================
		// Adapter Contract
		// ============================================================

		var adapter = Q.getObject(['prompt', platform], Users);

		if (adapter && adapter.template) {

			var data = context;

			if (typeof adapter.getData === 'function') {
				data = Q.extend({}, context, adapter.getData(context));
			}

			Q.Template.render(adapter.template, data, {
				activateInContainer: content_div[0]
			});

			Q.Dialogs.push({
				title: Q.text.Users.prompt.title.interpolate({
					platform: platform,
					Platform: platformCapitalized
				}),
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
		// Legacy Facebook Fallback
		// ============================================================

		var title = Q.text.Users.prompt.title.interpolate({
			platform: platform,
			Platform: platformCapitalized
		});
		var areUsing = Q.text.Users.prompt.areUsing.interpolate({
			platform: platform,
			Platform: platformCapitalized
		});
		var noLongerUsing = Q.text.Users.prompt.noLongerUsing.interpolate({
			platform: platform,
			Platform: platformCapitalized
		});
		var caption;

		if (platform === 'facebook' && window.FB) {

			var xid2 = Q.getObject(['loggedInUser', 'xids', platform], Users);
			var queries = ['me'];
			if (xid2) queries.push('xid');

			var pipe = new Q.Pipe(queries, function (params) {

				var meName = Q.getObject(['me', 0, 'name'], params);
				var mePicture = Q.getObject(['me', 0, 'picture', 'data', 'url'], params);
				var xidName = Q.getObject(['xid', 0, 'name'], params);
				var xidPicture = Q.getObject(['xid', 0, 'picture', 'data', 'url'], params);

				if (xidName) {
					content_div.append(_usingInformation(xidPicture, xidName, noLongerUsing));
					caption = Q.text.Users.prompt.doSwitch.interpolate({
						platform: platform,
						Platform: platformCapitalized
					});
				} else {
					caption = Q.text.Users.prompt.doAuth.interpolate({
						platform: platform,
						Platform: platformCapitalized
					});
				}

				content_div
					.append(_usingInformation(mePicture, meName, areUsing))
					.append(_authenticateActions(caption));
			});

			FB.api("/me?fields=name,picture.width(50).height(50)", pipe.fill('me'));
			if (xid2) {
				FB.api("/" + xid2 + "?fields=name,picture.width(50).height(50)", pipe.fill('xid'));
			}
		}

		Q.Dialogs.push({
			title: title,
			content: content_div,
			onActivate: function () {
				if (platform === 'facebook' && window.FB) {
					Users.init.facebook(function () {
						FB.XFBML.parse(content_div.get(0));
					}, { appId: appId });
				}
			},
			onClose: function () {
				if (!tookAction && cancelCallback) {
					cancelCallback(xid);
				}
				tookAction = false;
			}
		});

		// ============================================================
		// Shared Confirm Handler
		// ============================================================

		function _attachConfirmHandler(container) {
			container.find('.Users_confirm').on(Q.Pointer.fastclick, function () {
				tookAction = true;
				Q.Dialogs.pop();
				authCallback && authCallback();
			});
		}

		// ============================================================
		// Legacy Helpers
		// ============================================================

		function _usingInformation(icon, name, explanation) {
			return $("<table />").append(
				$("<tr />").append(
					$("<td class='Users_profile_pic' />").append(
						$('<img />', { src: icon })
					)
				).append(
					$("<td class='Users_explanation_name' />").append(
						$("<div class='Users_explanation' />").html(explanation)
					).append(name)
				)
			);
		}

		function _authenticateActions(caption) {
			return $("<div class='Users_actions Q_big_prompt' />").append(
				$('<button type="submit" class="Q_button Q_main_button Users_confirm" />')
					.html(caption)
			);
		}
	};

});
(function (Q, $) {

/**
 * Users Tools
 * @module Users-tools
 */

var Users = Q.Users;

/**
 * Renders a dynamic user status area which displays "log in" or the logged-in user's avatar
 * @class Users status
 * @constructor
 * @param {Object} [options] this object contains function parameters
 *   @param {Q.Event} [options.onInvoke] When the tool is clicked
 *   @param {Q.Event} [options.onInvokeOptimistic] When the tool is clicked
 *	 @param {String} [options.avatar] Options for the user avatar
 *	 @param {String} [options.avatar.icon=80] The default size of the avatar icon
 *	 @param {String} [options.avatar.contents=true] Whether to show the name
 *	 @param {String} [options.avatar.short=true] Whether the name shown should be short
 *   @param {String} [options.clickable=falser] Whether to apply Q/clickable effect
 */
Q.Tool.define("Users/status", function (options) {
	var tool = this;

	// react to real login/logout
	Users.onLogin.set(tool.refresh.bind(tool), tool);
	Users.onLogout.set(tool.refresh.bind(tool), tool);

	// listen to optimistic avatar begin
	Q.Optimistic.onBegin("avatar", "@me").set(function (payload) {
		tool.state.optimisticPayload = payload || {};
		tool.refresh();
	}, tool);

	// resolve or reject → optimistic mode ends
	Q.Optimistic.onResolve("avatar", "@me").set(function () {
		tool.state.optimisticPayload = null;
		tool.refresh();
	}, tool);

	Q.Optimistic.onReject("avatar", "@me").set(function () {
		tool.state.optimisticPayload =  null;
		tool.refresh();
	}, tool);

	tool.refresh();
},
{
	avatar: {
		icon: 80,
		contents: true,
		short: true
	},
	clickable: false,
	onInvoke: new Q.Event(),
	onInvokeOptimistic: new Q.Event(),
	optimistic: false,
	optimisticPayload: null
},
{
	refresh: function () {
		var tool = this;
		var state = tool.state;
		var loggedIn = !!Users.loggedInUser;

		$(tool.element).empty();

		// ================
		// CASE 1: REAL USER
		// ================
		if (loggedIn) {
			var $avatar = $('<div />').tool('Users/avatar', state.avatar);

			$(tool.element)
				.append(
					$('<div class="Users_whenLoggedIn Users_status_avatar" />')
						.append($avatar)
				)
				.on(Q.Pointer.click, tool, function () {
					Q.handle(state.onInvoke);
				})
				.activate();

			if (state.clickable) {
				$avatar.plugin('Q/clickable');
			}
			return;
		}

		// ===========================
		// CASE 2: OPTIMISTIC PLACEHOLDER
		// ===========================
		if (!Q.isEmpty(state.optimisticPayload)) {
			var avatarOpts = Q.extend({}, state.avatar, state.optimisticPayload);

			var $avatar = $('<div />')
				.tool('Users/avatar', avatarOpts)
				.addClass('Users_status_avatar_optimistic');

			$(tool.element)
				.append(
					$('<div class="Users_status_avatar Users_status_avatar_optimistic" />')
						.append($avatar)
				)
				.on(Q.Pointer.click, tool, function () {
					Q.handle(state.onInvokeOptimistic, tool, [state.optimisticPayload]);
				})
				.activate();

			if (state.clickable) {
				$avatar.plugin('Q/clickable');
			}

			return;
		}

		// ===========================
		// CASE 3: NOT LOGGED IN AT ALL
		// ===========================
		Q.Text.get('Users/content', function (err, text) {
			var $div = $('<div class="Users_status_login_title" />')
				.html(text.actions.LogIn);

			$(tool.element)
				.append(
					$('<div class="Users_status_login" />')
						.append($div)
				);

			var $status = tool.$('.Users_status_login');
			if (state.clickable) {
				$status.plugin('Q/clickable');
			}
			$status.on(Q.Pointer.fastclick, tool, function () {
				Users.login();
				return false;
			});
		});
	}
});

})(Q, Q.jQuery);
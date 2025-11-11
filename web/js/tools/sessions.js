(function (Q, $, window, undefined) {

var Users = Q.Users;

/**
 * Users Sessions Tool
 * @module Users-tools
 * @class Users/sessions
 * @constructor
 * @param {Object} [options]
 *   @param {String} [options.userId] The user whose sessions to show (defaults to logged-in user)
 *   @param {Boolean} [options.editable] Whether user can delete sessions
 *   @param {Boolean} [options.devices] Whether to show device info
 */
Q.Tool.define("Users/sessions", function Users_sessions_tool(options) {
	var tool = this;
	var state = this.state;

	if (state.userId == null) {
		state.userId = Users.loggedInUserId();
	}

	Q.addStylesheet('{{Users}}/css/tools/sessions.css', 'Users');

	// Handle delete session button
	tool.$("button[name=delete]").on(Q.Pointer.fastclick, function (e) {
		var $this = $(this);
		var $tr = $this.closest("tr");
		var sessionId = $tr.find("td.sessionId").text().trim();

		if (!sessionId) {
			return Q.alert("Missing session ID");
		}

		Q.confirm(state.confirmOptions.title, function (res) {
			if (!res) return;

			$tr.addClass("Q_uploading");

			Q.req("Users/session", [], function (err, data) {
				$tr.removeClass("Q_uploading");

				var fem = Q.firstErrorMessage(err, data && data.errors);
				if (fem) return Q.alert(fem);

				// Remove the row from DOM after success
				$tr.remove();
			}, {
				method: "delete",
				fields: {
					sessionId: sessionId
				}
			});
		});
	});
},
{
	user: null,
	confirmOptions: {
		title: "Are you sure you want to delete this session?"
	}
});

})(Q, Q.jQuery, window);
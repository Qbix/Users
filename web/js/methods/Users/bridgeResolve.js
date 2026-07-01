Q.exports(function (Users) {
	/**
	 * Users plugin's front end code
	 *
	 * @module Users
	 * @class Users
	 */

	/**
	 * Surfaces a bridge conflict to the user when both the first-party context
	 * and the iframe context have distinct verified users, and prompts them to
	 * choose which identity to keep going forward.
	 *
	 * Fired automatically when Q.plugins.Users.bridgeConflict script data is
	 * set by Users_before_Q_responseExtras on token redemption (Case 6 of the
	 * bridge decision matrix). Posts to Users/bridgeResolve with the user's
	 * choice; on 'bridge' the page is reloaded to pick up the new identity,
	 * on 'current' the iframe will switch to the first-party user on its
	 * next request.
	 *
	 * @method bridgeResolve
	 * @static
	 * @param {Object} conflict The conflict descriptor from the server
	 *   @param {String} conflict.token The bridge intent token to resolve
	 *   @param {String} conflict.bridgeUserId The user id from the iframe session
	 *   @param {String} conflict.currentUserId The user id of the first-party session
	 */
	return function Users_bridgeResolve(conflict) {
		Q.Dialogs.push({
			title: Q.text.Users.bridgeConflict.title,
			content: Q.text.Users.bridgeConflict.message,
			apply: false,
			className: 'Users_bridgeConflict_dialog',
			onActivate: function (dialog) {
				var $content = $(dialog).find('.Q_dialog_content');
				var $keep = $('<button class="Q_button">'
					+ Q.text.Users.bridgeConflict.keepCurrent
					+ '</button>');
				var $switch = $('<button class="Q_button">'
					+ Q.text.Users.bridgeConflict.switchToBridge
					+ '</button>');
				$content.append($keep).append($switch);

				$keep.on(Q.Pointer.click, function () {
					_resolve('current', dialog);
				});
				$switch.on(Q.Pointer.click, function () {
					_resolve('bridge', dialog);
				});
			}
		});

		function _resolve(choice, dialog) {
			Q.req('Users/bridgeResolve', ['resolved'], function (err, response) {
				Q.Dialogs.close(dialog);
				var fem = Q.firstErrorMessage(err, response);
				if (fem) return Users.onError.handle(fem);
				if (choice === 'bridge') {
					// we're now the bridge user; reload to pick up new identity
					Q.handle(window.location);
				}
				// For 'current', the iframe will switch on its next request;
				// current page already shows the right user, no reload needed.
			}, {
				method: 'post',
				fields: { token: conflict.token, choice: choice }
			});
		}
	};
});
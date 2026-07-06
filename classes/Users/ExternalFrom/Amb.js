/**
 * Apple Messages for Business (AMB) identity, for delivering notifications.
 *
 * JS counterpart of Users_ExternalFrom_Amb.php and mirror of the Telegram
 * ExternalFrom subclass: the delivery adapter that Streams' Message.deliver()
 * invokes via Users_ExternalFrom.pushNotification().
 *
 * @module Users
 * @class Users.ExternalFrom.Amb
 * @extends Users.ExternalFrom
 */
var Q = require('Q');
var Users = Q.require('Users');
Q.require('Users/Amb');
Q.require('Users/Amb/Client');

/**
 * @constructor
 * @param {Object} fields
 * @param {Boolean} retrieved
 */
function Users_ExternalFrom_Amb(fields, retrieved) {
	// Run constructors of mixed in objects
	Users_ExternalFrom_Amb.constructors.apply(this, arguments);
}

Q.mixin(Users_ExternalFrom_Amb, Users.ExternalFrom);

/**
 * Sends a notification to the user over Apple Messages for Business.
 * Builds a text message and appends any link, like the Telegram adapter;
 * richer messages can be sent directly via Users.Amb.Client.
 * @method handlePushNotification
 * @param {Object} notification (alert, href, ref)
 * @param {Function} [callback] receives (err); "this" is the row
 */
Users_ExternalFrom_Amb.prototype.handlePushNotification = function (notification, callback) {
	var self = this;
	var xid = Q.getObject('fields.xid', this) || this.xid;
	if (!xid) {
		return Q.handle(callback, this, [new Q.Error("Users.ExternalFrom.Amb: missing xid")]);
	}
	var appId = Q.getObject('fields.appId', this) || this.appId;
	if (appId === 'all') {
		appId = Q.app.name;
	}
	var baseUrl = Q.getObject('Users.apps.baseUrl', Q.Config.get(), '');

	// Build text
	var text = '';
	var alert = notification && notification.alert;
	if (typeof alert === 'string') {
		text = alert;
	} else if (alert && alert.body) {
		text = alert.body;
	}

	// Append any link
	if (notification && notification.href) {
		var link = notification.href;
		if (link.length && link[0] === '/') {
			link = baseUrl + link;
		}
		text += "\n\n" + link;
	}

	Users.Amb.Client.sendMessage(appId, xid, text).then(function () {
		Q.handle(callback, self, [null]);
	}, function (err) {
		Q.handle(callback, self, [err]);
	});
};

/**
 * @method setUp
 */
Users_ExternalFrom_Amb.prototype.setUp = function () {
	// overrides the Base class
};

module.exports = Users.ExternalFrom.Amb = Users_ExternalFrom_Amb;

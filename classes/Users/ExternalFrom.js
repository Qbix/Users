/**
 * Class representing external_from rows.
 *
 * @module Users
 */
var Q = require('Q');
var Db = Q.require('Db');

/**
 * Class representing 'ExternalFrom' rows in the 'Users' database
 * <br/>Each row represents an identity of a user on an external platform.
 *
 * @namespace Users
 * @class ExternalFrom
 * @extends Base.Users.ExternalFrom
 * @constructor
 * @param fields {object} The fields values to initialize table row as
 * an associative array of `{column: value}` pairs
 */
function Users_ExternalFrom(fields) {

	// Run constructors of mixed in objects
	Users_ExternalFrom.constructors.apply(this, arguments);

	/*
	 * Add any privileged methods here.
	 * Public methods should be below.
	 * If file 'ExternalFrom.js.inc' exists, its content is included.
	 */
}

Q.mixin(Users_ExternalFrom, Q.require('Base/Users/ExternalFrom'));

/**
 * The setUp() method is called the first time
 * an object of this class is constructed.
 * @method setUp
 */
Users_ExternalFrom.prototype.setUp = function () {
	// Override base class initialization here, if needed.
};

/**
 * Pushes a notification to the external platform identity.
 *
 * @method pushNotification
 * @param {Object} notification
 *   @param {String|Object} notification.alert
 *   @param {String} [notification.href]
 *   @param {String} [notification.ref]
 *   @param {Object} [notification.payload]
 * @param {Function} [callback]
 */
Users_ExternalFrom.prototype.pushNotification = function (notification, callback) {
	return this.handlePushNotification(notification, callback);
};

/**
 * Default implementation.
 * Override this in platform-specific adapters:
 *   Users_ExternalFrom_Telegram
 *   Users_ExternalFrom_Discord
 *   Users_ExternalFrom_Slack
 *
 * @method handlePushNotification
 */
Users_ExternalFrom.prototype.handlePushNotification = function (notification, callback) {
	throw new Q.Error(
		"Users.ExternalFrom.prototype.handlePushNotification: not implemented"
	);
};

/**
 * Called by the ORM to build the correct subclass instance
 * based on the `platform` field.
 *
 * @param {Object} fields
 * @param {Boolean} retrieved
 * @return {Users.ExternalFrom}
 */
Users_ExternalFrom.newRow = function (fields, retrieved) {
	if (!fields.platform) {
		throw new Q.Error("Users.ExternalFrom.newRow: missing fields.platform");
	}

	var platform = fields.platform.toLowerCase().toCapitalized();
	var PlatformExternalFrom = Users_ExternalFrom[platform];

	if (!PlatformExternalFrom) {
		throw new Q.Error(
			"Users.ExternalFrom.newRow: No adapter for platform '" + platform + "'"
		);
	}

	return new PlatformExternalFrom(fields, retrieved);
};

module.exports = Users_ExternalFrom;

/**
 * Autogenerated base class for the Users model.
 * 
 * Don't change this file, since it can be overwritten.
 * Instead, change the Users.js file.
 *
 * @module Users
 */
var Q = require('Q');
var Db = Q.require('Db');

/**
 * Base class for the Users model
 * @namespace Base
 * @class Users
 * @static
 */
function Base () {
	return this;
}
 
module.exports = Base;

/**
 * The list of model classes
 * @property tableClasses
 * @type array
 */
Base.tableClasses = [
	"Users_Contact",
	"Users_Device",
	"Users_Email",
	"Users_ExternalFrom",
	"Users_ExternalTo",
	"Users_Field",
	"Users_Identify",
	"Users_Intent",
	"Users_Label",
	"Users_Link",
	"Users_Mobile",
	"Users_Permission",
	"Users_Quota",
	"Users_Session",
	"Users_Total",
	"Users_User",
	"Users_Vote",
	"Users_Web3",
	"Users_Web3Transaction"
];

/**
 * This method calls Db.connect() using information stored in the configuration.
 * If this has already been called, then the same db object is returned.
 * @method db
 * @return {Db} The database connection
 */
Base.db = function () {
	return Db.connect('Users');
};

/**
 * The connection name for the class
 * @method connectionName
 * @return {string} The name of the connection
 */
Base.connectionName = function() {
	return 'Users';
};

/**
 * Link to Users.Contact model
 * @property Contact
 * @type Users.Contact
 */
Base.Contact = Q.require('Users/Contact');

/**
 * Link to Users.Device model
 * @property Device
 * @type Users.Device
 */
Base.Device = Q.require('Users/Device');

/**
 * Link to Users.Email model
 * @property Email
 * @type Users.Email
 */
Base.Email = Q.require('Users/Email');

/**
 * Link to Users.ExternalFrom model
 * @property ExternalFrom
 * @type Users.ExternalFrom
 */
Base.ExternalFrom = Q.require('Users/ExternalFrom');

/**
 * Link to Users.ExternalTo model
 * @property ExternalTo
 * @type Users.ExternalTo
 */
Base.ExternalTo = Q.require('Users/ExternalTo');

/**
 * Link to Users.Field model
 * @property Field
 * @type Users.Field
 */
Base.Field = Q.require('Users/Field');

/**
 * Link to Users.Identify model
 * @property Identify
 * @type Users.Identify
 */
Base.Identify = Q.require('Users/Identify');

/**
 * Link to Users.Intent model
 * @property Intent
 * @type Users.Intent
 */
Base.Intent = Q.require('Users/Intent');

/**
 * Link to Users.Label model
 * @property Label
 * @type Users.Label
 */
Base.Label = Q.require('Users/Label');

/**
 * Link to Users.Link model
 * @property Link
 * @type Users.Link
 */
Base.Link = Q.require('Users/Link');

/**
 * Link to Users.Mobile model
 * @property Mobile
 * @type Users.Mobile
 */
Base.Mobile = Q.require('Users/Mobile');

/**
 * Link to Users.Permission model
 * @property Permission
 * @type Users.Permission
 */
Base.Permission = Q.require('Users/Permission');

/**
 * Link to Users.Quota model
 * @property Quota
 * @type Users.Quota
 */
Base.Quota = Q.require('Users/Quota');

/**
 * Link to Users.Session model
 * @property Session
 * @type Users.Session
 */
Base.Session = Q.require('Users/Session');

/**
 * Link to Users.Total model
 * @property Total
 * @type Users.Total
 */
Base.Total = Q.require('Users/Total');

/**
 * Link to Users.User model
 * @property User
 * @type Users.User
 */
Base.User = Q.require('Users/User');

/**
 * Link to Users.Vote model
 * @property Vote
 * @type Users.Vote
 */
Base.Vote = Q.require('Users/Vote');

/**
 * Link to Users.Web3 model
 * @property Web3
 * @type Users.Web3
 */
Base.Web3 = Q.require('Users/Web3');

/**
 * Link to Users.Web3Transaction model
 * @property Web3Transaction
 * @type Users.Web3Transaction
 */
Base.Web3Transaction = Q.require('Users/Web3Transaction');

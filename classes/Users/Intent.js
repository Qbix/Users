/**
 * Class representing intent rows.
 *
 * This description should be revised and expanded.
 *
 * @module Users
 */
var Q = require('Q');
var Db = Q.require('Db');
var Intent = Q.require('Base/Users/Intent');

/**
 * Class representing 'Intent' rows in the 'Users' database
 * <br>Intent rows may hide secret instructions from the client
 * @namespace Users
 * @class Intent
 * @extends Base.Users.Intent
 * @constructor
 * @param {Object} fields The fields values to initialize table row as
 * an associative array of {column: value} pairs
 */
function Users_Intent (fields) {

	// Run mixed-in constructors
	Users_Intent.constructors.apply(this, arguments);
	
	/*
 	 * Add any privileged methods to the model class here.
	 * Public methods should probably be added further below.
	 */
}

Q.mixin(Users_Intent, Intent);

/*
 * Add any public methods here by assigning them to Users_Intent.prototype
 */

/**
 * The setUp() method is called the first time
 * an object of this class is constructed.
 * @method setUp
 */
Users_Intent.prototype.setUp = function () {
	// put any code here
	// overrides the Base class
};

module.exports = Users_Intent;
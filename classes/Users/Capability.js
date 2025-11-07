/**
 * Class representing capability rows.
 *
 * This description should be revised and expanded.
 *
 * @module Users
 */
var Q = require('Q');
var Db = Q.require('Db');
var Capability = Q.require('Base/Users/Capability');

/**
 * Class representing 'Capability' rows in the 'Users' database
 * <br>Signed capabilities bound to user public keys; supports recovery, federation, and delegation.
 * @namespace Users
 * @class Capability
 * @extends Base.Users.Capability
 * @constructor
 * @param {Object} fields The fields values to initialize table row as
 * an associative array of {column: value} pairs
 */
function Users_Capability (fields) {

	// Run mixed-in constructors
	Users_Capability.constructors.apply(this, arguments);
	
	/*
 	 * Add any privileged methods to the model class here.
	 * Public methods should probably be added further below.
	 */
}

Q.mixin(Users_Capability, Capability);

/*
 * Add any public methods here by assigning them to Users_Capability.prototype
 */

/**
 * The setUp() method is called the first time
 * an object of this class is constructed.
 * @method setUp
 */
Users_Capability.prototype.setUp = function () {
	// put any code here
	// overrides the Base class
};

module.exports = Users_Capability;
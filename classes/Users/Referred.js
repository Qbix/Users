/**
 * Class representing referred rows.
 *
 * This description should be revised and expanded.
 *
 * @module Users
 */
var Q = require('Q');
var Db = Q.require('Db');
var Referred = Q.require('Base/Users/Referred');

/**
 * Class representing 'Referred' rows in the 'Users' database
 * <br>Represents an accepted referral to a community
 * @namespace Users
 * @class Referred
 * @extends Base.Users.Referred
 * @constructor
 * @param {Object} fields The fields values to initialize table row as
 * an associative array of {column: value} pairs
 */
function Users_Referred (fields) {

	// Run mixed-in constructors
	Users_Referred.constructors.apply(this, arguments);
	
	/*
 	 * Add any privileged methods to the model class here.
	 * Public methods should probably be added further below.
	 */
}

Q.mixin(Users_Referred, Referred);

/*
 * Add any public methods here by assigning them to Users_Referred.prototype
 */

/**
 * The setUp() method is called the first time
 * an object of this class is constructed.
 * @method setUp
 */
Users_Referred.prototype.setUp = function () {
	// put any code here
	// overrides the Base class
};

module.exports = Users_Referred;
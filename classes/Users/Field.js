/**
 * Class representing field rows.
 *
 * This description should be revised and expanded.
 *
 * @module Users
 */
var Q = require('Q');
var Db = Q.require('Db');
var Field = Q.require('Base/Users/Field');

/**
 * Class representing 'Field' rows in the 'Users' database
 * @namespace Users
 * @class Field
 * @extends Base.Users.Field
 * @constructor
 * @param {Object} fields The fields values to initialize table row as
 * an associative array of {column: value} pairs
 */
function Users_Field (fields) {

	// Run mixed-in constructors
	Users_Field.constructors.apply(this, arguments);
	
	/*
 	 * Add any privileged methods to the model class here.
	 * Public methods should probably be added further below.
	 */
}

Q.mixin(Users_Field, Field);

/*
 * Add any public methods here by assigning them to Users_Field.prototype
 */

/**
 * The setUp() method is called the first time
 * an object of this class is constructed.
 * @method setUp
 */
Users_Field.prototype.setUp = function () {
	// put any code here
	// overrides the Base class
};

module.exports = Users_Field;
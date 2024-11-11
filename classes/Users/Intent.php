<?php
/**
 * @module Users
 */
/**
 * Class representing 'Intent' rows in the 'Users' database
 * You can create an object of this class either to
 * access its non-static methods, or to actually
 * represent a intent row in the Users database.
 *
 * @class Users_Intent
 * @extends Base_Users_Intent
 */
class Users_Intent extends Base_Users_Intent
{
	/**
	 * Does necessary preparations for saving an intent in the database.
	 * @method beforeSave
	 * @param {array} $modifiedFields
	 *	The array of fields
	 * @param {array} $options
	 *  Not used at the moment
	 * @param {array} $internal
	 *  Can be used to pass pre-fetched objects
	 * @return {array}
	 * @throws {Exception}
	 *	If mandatory field is not set
	 */
	function beforeSave(
		$modifiedFields,
		$options = array(),
		$internal = array()
	) {
		// Save current sessionId in the intent
		if (!isset($modifiedFields['instructions']) and !isset($this->instrucitons)) {
			$this->instructions = $modifiedFields['instructions'] = '{}';
		}
		if (!isset($modifiedFields['sessionId']) and !isset($this->sessionId)) {
			$sessionId = Q_Session::requestedId();
			if (Q_Session::isValidId($sessionId)) {
				$this->sessionId = $modifiedFields['sessionId'] = $sessionId;
			}
		}
		// Generate a unique token for the intent
		if (!isset($modifiedFields['token']) and !isset($this->token)) {
			$this->token = $modifiedFields['token'] = Users::db()->uniqueId(
				Users_Intent::table(), 'token'
			);
		}
		return parent::beforeSave($modifiedFields);
	}

	/**
	 * Validates the time on the intent
	 * @method isValid
	 * @return {boolean}
	 */
	function isValid()
	{
		$db = Users::db();
		$now = $db->toDateTime($db->getCurrentTimestamp());
		if ($this->startTime and $this->startTime > $now) {
			return false;
		}
		if ($this->endTime and $this->endTime < $now) {
			return false;
		}
		return true;
	}

	/**
	 * The setUp() method is called the first time
	 * an object of this class is constructed.
	 * @method setUp
	 */
	function setUp()
	{
		parent::setUp();
		// INSERT YOUR CODE HERE
		// e.g. $this->hasMany(...) and stuff like that.
	}

	/*
	 * Add any Users_Intent methods here, whether public or not
	 */
	 
	/**
	 * Implements the __set_state method, so it can work with
	 * with var_export and be re-imported successfully.
	 * @method __set_state
	 * @static
	 * @param {array} $array
	 * @return {Users_Intent} Class instance
	 */
	static function __set_state(array $array) {
		$result = new Users_Intent();
		foreach($array as $k => $v)
			$result->$k = $v;
		return $result;
	}
};
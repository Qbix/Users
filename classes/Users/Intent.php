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
	 * Save a new intent in the database (and perhaps remove a few outdated ones)
	 * @method newIntent
	 * @static
	 * @param {string} $action the action to take
	 * @param {array} [$instructions=array()] any additional instructions to use with the action
	 * @return {Users_Intent}
	 * @throws {Q_Exception_SessionHijacked}
	 */
	static function newIntent($action, $instructions = array())
	{
		$info = Q_Config::get('Users', 'intents', 'actions', $action, false);
		if (!$info) {
			throw new Users_Exception_NotAuthorized();
		}
		if (!empty($instructions) && !Q::isAssociative($instructions)) {
			throw new Q_Exception_WrongType(array(
				'field' => 'instructions',
				'type' => 'associative array'
			));
		}
		foreach ($instructions as $k => $v) {
			if (!isset($info['instructions'][$k])) {
				throw new Q_Exception_MissingConfig(array('fieldpath' => "Users/intents/actions/\"$action\"/instructions/$k"));
			}
		}
		$sessionId = Q_Session::requestedId();
		if (!Q_Session::isValidId($sessionId)) {
			throw new Q_Exception_SessionHijacked();
		}
		$durations = Q_Config::get('Users', 'intents', 'durations', array());
		$debounce = Q::ifset($durations, 'debounce', 10); // no more than one per second
		$intents = Users_Intent::select()
			->where(array(
				'sessionId' => $sessionId,
				'action' => $action,
				'insertedTime >' => new Db_Expression("CURRENT_TIMESTAMP - INTERVAL $debounce SECOND")
			))->fetchDbRows();
		if (count($intents)) {
			$intent = reset($intents);
		} else {
			// insert this new intent
			$instructions = Q::json_encode($instructions);
			$intent = new Users_Intent(compact('action', 'instructions'));
			$intent->startTime = new Db_Expression('CURRENT_TIMESTAMP');
			if ($duration = Q::ifset($info, 'duration', 0)) {
				$intent->endTime = new Db_Expression("CURRENT_TIMESTAMP + INTERVAL $duration SECOND");
			}
			$intent->save();
		}
		return $intent;
	}

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
		if (!isset($this->instructions)) {
			$this->instructions = '{}';
		}
		// save current sessionId in the intent
		if (!isset($this->sessionId)) {
			$sessionId = Q_Session::requestedId();
			if (Q_Session::isValidId($sessionId)) {
				$this->sessionId = $sessionId;
			}
		}
		// delete up to 10 previous intents with this sessionId, to save space
		Users_Intent::delete()->where(array(
			'endTime <' => new Db_Expression("CURRENT_TIMESTAMP")
		))->limit(10)->execute();
		// Generate a unique token for the intent
		if (!isset($this->token)) {
			$this->token = Users::db()->uniqueId(
				Users_Intent::table(), 'token'
			);
		}
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
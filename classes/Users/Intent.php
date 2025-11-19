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
	 * Retrieve intent from database by its token
	 * @method fromToken
	 * @static
	 * @param {string} $token
	 * @return {Users_Intent}
	 */
	static function fromToken($token)
	{
		$intent = (new Users_Intent(compact('token')))->retrieve();
		return $intent ? $intent : null;
	}

	/**
	 * Generate a unique token that can be used for intents
	 * @method generateToken
	 * @static
	 * @return {string}
	 */
	static function generateToken()
	{
		return self::db()->uniqueId(
			self::table(),
			'token',
			null,
			array(
				'length' => Q_Config::get('Users', 'intents', 'tokens', 'length', 16),
				'characters' => Q_Config::get('Users', 'intents', 'tokens', 'characters', 'abcdefghijklmnopqrstuvwxyz')
			)
		);
	}

	/**
	 * Generate a unique token that can be used for intents.
	 * Then sign capability and return it.
	 * Re-uses same capability if called multiple times.
	 * @method capability
	 * @param {array} $data Any additional data to include in the capability
	 * @static
	 * @return {Q_Capability}
	 */
	static function capability($data = array())
	{
		$data['token'] = self::generateToken();
		static $c = null;
		if (!isset($c)) {
			$duration = Q_Config::expect('Users', 'capability', 'duration');
			$time = floor(Q::millisecondsStarted() / 1000);
			$c = new Q_Capability(
				array('Users/intent'), 
				$data, $time, $time + $duration
			);
		}
		return $c;
	}

	/**
	 * Save a new intent in the database (and perhaps remove a few outdated ones)
	 * @method newIntent
	 * @static
	 * @param {string} $action the action to take
	 * @param {string} [$userId] the userId for whom the intent is generated
	 * @param {array} [$instructions=array()] any additional instructions to use with the action
	 *   such as the platform to authenticate with, etc.
	 * @param {string} [$token] optionally specify the exact token of the intent,
	 *   this is mostly for use by internal handlers like Users/intent/post.php
	 * @return {Users_Intent}
	 * @throws {Q_Exception_SessionHijacked}
	 */
	static function newIntent($action, $userId = null, $instructions = array(), $token = null)
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
		// foreach ($instructions as $k => $v) {
		// 	if (!isset($info['instructions'][$k])) {
		// 		throw new Q_Exception_MissingConfig(array('fieldpath' => "Users/intents/actions/\"$action\"/instructions/$k"));
		// 	}
		// }
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
			$instructions = Q::json_encode($instructions, Q::JSON_FORCE_OBJECT);
			$intent = new Users_Intent(compact('action', 'instructions'));
			if ($token) {
				$intent->token = $token;
			}
			$intent->startTime = new Db_Expression('CURRENT_TIMESTAMP');
			if ($duration = Q::ifset($info, 'duration', 600)) {
				$intent->endTime = new Db_Expression("CURRENT_TIMESTAMP + INTERVAL $duration SECOND");
			}
			$intent->save(true);
		}
		return $intent;
	}

	/**
	 * Accept the intent and set user from intent as the logged-in user
	 * @method accept
	 * @param {array} $copySessionFields Array of session fields to copy
	 * 	from original session to current session
	 * @return {boolean} true if successful, false otherwise
	 */
	function accept($copySessionFields = array())
	{
		$intent = $this;
		if (false === Q::event('Users/intent/accept', compact('intent', 'copySessionFields'), 'before')) {
			return false;
		}
		if ((!$intent->wasRetrieved() and !$intent->retrieve())
		or !empty($intent->completedTime)) {
			return false;
		}
		$userId = $content = $session = null;
		if ($intent->userId) {
			// user was already logged in when intent was created
			$userId = $intent->userId;
		}
		if ($intent->sessionId) {
			// perhaps user logged in after intent was generated,
			// although normally intent should have userId set
			$session = new Users_Session();
			$session->id = $intent->sessionId;
			if ($session->retrieve()) {
				$content = json_decode($session->content, true);
				if (!$userId) {
					$userId = Q::ifset($content, 'Users', 'loggedInUser', 'id', null);
				}
				$intent->set('sessionContent', $content);
			}
		}
		if ($userId) {
			// if user was logged into session that generated intent,
			// set them as logged-in user here too, before connecting telegram user
			Users::setLoggedInUser($userId, array('keepSessionId' => true));
		}
		if ($content and $copySessionFields) {
			Q::take($content, $copySessionFields, $_SESSION);
		}
		Q::event('Users/intent/accept', compact('intent', 'session', 'userId'), 'after');
		return true;
	}

	/**
	 * Mark intent completed, and set logged-in user in original session
	 * if no one was logged in there yet.
	 * @method complete
	 * @param {array} $results Any additional results to store in instructions
	 * @return {boolean} true if successful, false otherwise
	 */
	function complete($results = array())
	{
		$intent = $this;
		if (false === Q::event('Users/intent/complete', compact('intent', 'results'), 'before')) {
			return false;
		}
		if ((!$intent->wasRetrieved() and !$intent->retrieve())
		or !empty($intent->completedTime)) {
			return false;
		}
		$user = Users::loggedInUser(false, false);
		if ($user and !$intent->userId) {
			$intent->userId = $user->id;
		}
		$intent->setInstruction('results', $results);
		$intent->completedTime = new Db_Expression('CURRENT_TIMESTAMP');
		$intent->save();

		$session = null;
		if ($user and $intent->sessionId) {
			$session = new Users_Session();
			$session->id = $intent->sessionId;
			if ($session->retrieve()) {
				$content = json_decode($session->content, true);
				if (empty($content['Users']['switchToLoggedInUserId'])) {
					// user wasn't logged in on original session, so let's
					// set current user as logged-in on the original session, too
					$content['Users']['switchToLoggedInUserId'] = $user->id;
					$session->setContent($content);
					$session->save();
				}
			}
		}
		Q::event('Users/intent/complete', compact('intent', 'session', 'user'), 'after');
		return true;
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
		if (isset($this->attributes)
		and !is_string($this->attributes)) {
			if (is_array($this->attributes)) {
				$this->attributes = Q::json_encode($this->attributes, Q::JSON_FORCE_OBJECT);
			} else {
				throw new Q_Exception_WrongType(array(
					'field' => 'attributes',
					'type' => 'string'
				));
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
	 * @method getAllinstructions
	 * @return {array} The array of all instructions set in the message
	 */
	function getAllInstructions()
	{
		return empty($this->instructions) ? array() : json_decode($this->instructions, true);
	}
	
	/**
	 * @method getInstruction
	 * @param {string} $instructionName The name of the instruction to get
	 * @param {mixed} $default The value to return if the instruction is missing
	 * @return {mixed} The value of the instruction, or the default value, or null
	 */
	function getInstruction($instructionName)
	{
		$instr = $this->getAllInstructions();
		return isset($instr[$instructionName]) ? $instr[$instructionName] : null;
	}
	
	/**
	 * @method setInstruction
	 * @param {string|array} $instructionName The name of the instruction to set,
	 *  or an array of $instructionName => $value pairs
	 * @param {mixed} $value The value to set the instruction to
	 * @return Streams_Message
	 */
	function setInstruction($instructionName, $value)
	{
		$instr = $this->getAllInstructions();
		$instr[$instructionName] = $value;
		$this->instructions = Q::json_encode($instr, Q::JSON_FORCE_OBJECT);

		return $this;
	}
	
	/**
	 * @method clearInstruction
	 * @param {string} $instructionName The name of the instruction to remove
	 */
	function clearInstruction($instructionName)
	{
		$instr = $this->getAllInstructions();
		unset($instr[$instructionName]);
		$this->instructions = Q::json_encode($instr, Q::JSON_FORCE_OBJECT);
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
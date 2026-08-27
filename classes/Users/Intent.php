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
	 * @method fetch
	 * @static
	 * @param {string} $token
	 * @param {boolean} $throwIfMissing Whether to throw exception if intent is missing
	 * @return {Users_Intent}
	 */
	static function fetch($token, $throwIfMissing = false)
	{
		$intent = self::fromToken($token);
		if (!$intent && $throwIfMissing) {
			throw new Users_Exception_NotAuthorized();
		}
		return $intent;
	}

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
	 * @param {string} [$url] url of page the user was on when the intent was generated,
	 *   useful for returning to this page in another session after intent was completed
	 * @return {Users_Intent}
	 * @throws {Q_Exception_SessionHijacked}
	 */
	static function newIntent(
		$action, 
		$userId = null, 
		$instructions = array(), 
		$token = null,
		$url = null
	){
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
				'insertedTime' => new Db_Range(new Db_Expression("CURRENT_TIMESTAMP - INTERVAL $debounce SECOND"), false, false, null)
			))->andWhere(array(
				'completedTime' => Db_Values::$NOT_NULL
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
			if ($url) {
				$base = Q_Request::baseUrl();
				if (Q::startsWith($url, $base)) {
					$intent->url = $url;
				}
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
	 * @param {array} [$options]
	 * @param {array} [$options.copySessionFields] Array of session fields to copy
	 * 	from original session to current session
	 * @param {array} [$options.evenIfCompleted] Pass true to log user in even if intent
	 *   was already completed before (e.g. into for authenticating yet another session),
	 *   but the intent's endTime is still used to prevent attackers re-using old intents.
	 *   NOTE: this no longer makes the token a replayable bearer login - see
	 *   {{#crossLink "Users_Intent/authorizeAcceptingSession"}}{{/crossLink}},
	 *   which is enforced on every accept whether or not this option is passed.
	 * @return {boolean} true if successful, false otherwise
	 */
	function accept($options = array())
	{
		$intent = $this;
		if (false === Q::event('Users/intent/accept', compact('intent', 'options'), 'before')) {
			return false;
		}
		if ((!$intent->wasRetrieved() and !$intent->retrieve())
		or Users::db()->fromDateTime($intent->endTime) < time()
		or (empty($options['evenIfCompleted']) and !empty($intent->completedTime))) {
			return false;
		}
		// SECURITY: the token alone is not enough. It only logs in the session
		// that opened the intent, or - for actions that declare "handoff" - the
		// first other session it is handed to. See authorizeAcceptingSession().
		if (!$intent->authorizeAcceptingSession()) {
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
		if ($content and !empty($options['copySessionFields'])) {
			Q::take($content, $options['copySessionFields'], $_SESSION);
		}
		Q::event('Users/intent/accept', compact('intent', 'options', 'session', 'userId'), 'after');
		return true;
	}

	/**
	 * The name of the instruction under which an intent remembers which foreign
	 * session consumed it. Stripped from exportArray(), so it never reaches a client.
	 */
	const INSTRUCTION_ACCEPTED_BY = 'acceptedBy';

	/** This session may not be logged in as the intent's user. */
	const ACCEPT_DENY = 0;
	/** This session may be logged in, and nothing needs to be written. */
	const ACCEPT_ALLOW = 1;
	/** This session may be logged in, and consumes the intent's one handoff. */
	const ACCEPT_CLAIM = 2;

	/**
	 * The acceptance policy, as a pure function - see
	 * {{#crossLink "Users_Intent/authorizeAcceptingSession"}}{{/crossLink}}
	 * for why it exists and what it is defending against.
	 * @method acceptDecision
	 * @static
	 * @param {string} $action the intent's action
	 * @param {string} $openedBySessionId the sessionId stored on the intent
	 * @param {string} $sessionId the session making the current request
	 * @param {string} [$acceptedBy] fingerprint of the session that already
	 *   consumed this intent's handoff, if any
	 * @return {integer} one of ACCEPT_DENY, ACCEPT_ALLOW, ACCEPT_CLAIM
	 */
	static function acceptDecision(
		$action,
		$openedBySessionId,
		$sessionId,
		$acceptedBy = null
	) {
		if (!$sessionId) {
			return self::ACCEPT_DENY; // nothing to bind the acceptance to
		}
		if ($openedBySessionId and $openedBySessionId === $sessionId) {
			return self::ACCEPT_ALLOW; // case 1: the session that opened it
		}
		// case 2: a different session. The action has to have asked for this.
		$info = Q_Config::get('Users', 'intents', 'actions', $action, false);
		if (!is_array($info) or empty($info['handoff'])) {
			return self::ACCEPT_DENY;
		}
		if (!$acceptedBy) {
			return self::ACCEPT_CLAIM;
		}
		// the same handed-to session may reload the page it landed on
		return hash_equals($acceptedBy, self::sessionFingerprint($sessionId))
			? self::ACCEPT_ALLOW
			: self::ACCEPT_DENY;
	}

	/**
	 * Decide whether the session making the current request may be logged in
	 * as this intent's user.
	 *
	 * Without this, possession of the token was the whole credential: accept()
	 * called Users::setLoggedInUser() for anyone who presented it, an unbounded
	 * number of times, for the whole life of endTime - and the token travels in
	 * a URL query parameter (Q.Users.intent), which leaks through Referer
	 * headers, access logs, browser history and pasted links.
	 *
	 * Two acceptances are legitimate, and only two:
	 *
	 * 1. The session that opened the intent. Re-accepting there grants nothing
	 *    it does not already have, and the external-platform return leg
	 *    genuinely lands on the same session more than once (that is why
	 *    Users/before/Q_objects.php passes evenIfCompleted). Unlimited.
	 * 2. Exactly one other session, and only when the intent's action declares
	 *    "handoff": true in Users/intents/actions config. This is the QR /
	 *    second-device case ("log my phone in as the user on my desktop").
	 *    The first such session is remembered by fingerprint; every later
	 *    session is refused, so a leaked token is dead once it has been used.
	 *
	 * Handoff is opt-in per action rather than the default, because most
	 * actions never need it - Assets' "Assets/charge", for instance, is opened
	 * server-side by Assets::pay() and its token is never meant to
	 * authenticate a second device at all. An app or plugin that does need
	 * cross-device acceptance says so in its own config.
	 *
	 * Known limit, stated rather than papered over: this makes a leaked token
	 * single-use, not unusable. An attacker who steals a handoff token *before*
	 * the real second device uses it still wins the race - but the theft then
	 * becomes visible (the real device is refused) instead of silent and
	 * repeatable. Closing that needs the accepting device to prove something
	 * the token does not carry, which the QR flow has no channel for.
	 *
	 * Deliberately does NOT call Q_Session::isValidId() on the accepting
	 * session: it would add nothing (an attacker already holds a session the
	 * server issued) and it fails *open* when Q/internal/secret is unset, so
	 * it would read as a gate while gating nothing.
	 *
	 * The decision itself lives in the static acceptDecision() below, which is a
	 * pure function of (action, opening session, current session, prior claim).
	 * Splitting it is not ceremony: constructing a Db_Row opens a database
	 * connection, so a policy expressed only as an instance method cannot be
	 * pinned by a test until there is a database - and this policy is exactly
	 * the kind that has to be pinned.
	 *
	 * @method authorizeAcceptingSession
	 * @return {boolean} true if the current session may accept this intent
	 */
	function authorizeAcceptingSession()
	{
		// Q_Session::id() is '' until a session has started, and the Telegram
		// bot path starts an internal session before accepting, so try it
		// first and fall back to what the client presented.
		$sessionId = Q_Session::id();
		if (!$sessionId) {
			$sessionId = Q_Session::requestedId();
		}
		$decision = self::acceptDecision(
			$this->action,
			$this->sessionId,
			$sessionId,
			$this->getInstruction(self::INSTRUCTION_ACCEPTED_BY)
		);
		if ($decision === self::ACCEPT_CLAIM) {
			// first foreign session to arrive consumes the intent
			$this->setInstruction(
				self::INSTRUCTION_ACCEPTED_BY,
				self::sessionFingerprint($sessionId)
			);
			$this->save();
			return true;
		}
		return $decision === self::ACCEPT_ALLOW;
	}

	/**
	 * A non-reversible fingerprint of a session id, so that consuming an intent
	 * does not store a live session credential in a row that is read by token.
	 * @method sessionFingerprint
	 * @static
	 * @param {string} $sessionId
	 * @return {string}
	 */
	static function sessionFingerprint($sessionId)
	{
		$secret = Q_Config::get('Q', 'internal', 'secret', null);
		return isset($secret)
			? hash_hmac('sha256', $sessionId, $secret)
			: hash('sha256', $sessionId);
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
				if (empty($content['Users']['loggedInUser']['id'])) {
					// user wasn't logged in on original session, so let's
					// set current user as logged-in on the original session, too
					$content['Users']['switchToLoggedInUserId'] = $user->id;
					$session->setContent($content);
					$session->save();
				}
			}
		}

		// notify Node.js so the originating browser session can react immediately
		Q_Utils::sendToNode(array(
			"Q/method" => "Users/intentComplete",
			"token" => $intent->token,
			"userId" => $intent->userId,
			"sessionId" => $intent->sessionId
		));

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
		// delete a few previous intents with this sessionId, to save space
		if ($cleanup = Q_Config::get('Users', 'intents', 'cleanupOnSave', 10)) {
			Users_Intent::delete()->where(array(
				'endTime <' => new Db_Expression("CURRENT_TIMESTAMP")
			))->limit($cleanup)->execute();
		}
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
	 * @method getAllInstructions
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
	function setInstruction($instructionName, $value = null)
	{
		$instr = $this->getAllInstructions();
		if (is_array($instructionName)) {
			foreach ($instructionName as $k => $v) {
				$instr[$k] = $v;
			}
		} else {
			$instr[$instructionName] = $value;
		}
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
	 * Returns the fields and values we can export to clients, excluding sessionId.
	 * Can also contain "instructions", which will contain all the instructions.
	 *
	 * SECURITY: withholding sessionId is load-bearing, not tidiness.
	 * Users/before/Q_objects.php puts this array into Q.plugins.Users.intent
	 * script data for whoever presented the token, so exporting it would hand
	 * out the *originating* session's id - a longer-lived credential than the
	 * intent itself, and one that survives endTime. The acceptedBy fingerprint
	 * recorded by authorizeAcceptingSession() is withheld for the same reason.
	 *
	 * @method exportArray
	 * @param {$array} [$options=null]
	 * @return {array}
	 */
	function exportArray($options = null)
	{
		$fields = $this->fields;
		unset($fields['sessionId']);
		$instructions = $this->getAllInstructions();
		unset($instructions[self::INSTRUCTION_ACCEPTED_BY]);
		$fields['instructions'] = $instructions;
		return $fields;
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
<?php
/**
 * @module Users
 */
/**
 * Class representing 'Session' rows in the 'Users' database
 * You can create an object of this class either to
 * access its non-static methods, or to actually
 * represent a session row in the Users database.
 *
 * @class Users_Session
 * @extends Base_Users_Session
 */
class Users_Session extends Base_Users_Session
{
	/**
	 * The setUp() method is called the first time
	 * an object of this class is constructed.
	 * @method setUp
	 */
	function setUp()
	{
		parent::setUp();
	}

	/**
	 * Generates a valid session ID and the rest of a payload,
	 * based on source parameters, signs and returns it
	 * @method generatePayload
	 * @static
	 * @param {array} [$source=$_REQUEST] set this if you want to get the
	 *  parameters from an array other than $_REQUEST. Should contain:
	 * @param {string} 
	 * @param {string} [$source.redirect] the URL to redirect to, the fields
	 *  will be appended to the querystring of this URL.
	 * @param {string} [$source.platform] defaults to "qbix",
	 *  but can be "ios", "android", "web3", "facebook" etc.
	 * @param {string} [$source.appId] the ID of the app on that platform,
	 *  used to look up information from the config under "Users"/"apps"
	 * @param {string} [$source.deviceId] Optionally pass the device ID,
	 *  if notifications were enabled in this browser
	 * @return {array} the payload, which can be pt into a redirect URL
	 */
	static function generatePayload($source = null)
	{
		$source = Q::ifset($source, $_REQUEST);
		$req = Q::take($source, array(
			'appId' => Q::app(),
			'deviceId' => null, 
			'platform' => 'qbix'
		));
		list($appId, $appInfo) = Users::appInfo($req['platform'], $req['appId'], true);
		
		$payload = array();
		if (isset($req['deviceId'])) {
			$payload['Q.Users.deviceId'] = $req['deviceId'];
		}
		$prefixType = Q_Session::isAuthenticated() ? 'authenticated' : '';
		$newSessionId = Q_Session::generateId(null, $prefixType);
		$payload['Q.Users.newSessionId'] = $newSessionId;
		$payload['Q.Users.appId'] = $appId;
		$payload['Q.Users.platform'] = $req['platform'];
		$payload['Q.timestamp'] = time() + (int)Q_Config::get('Users', 'session', 'redirectSecondsMax', 300);
		$payload = Q_Utils::sign($payload, 'Q.Users.signature');
		return $payload;
	}

	/**
	 * Creates a session from a payload that was previously
	 * generated with Q_Session::generatePayload(), copies
	 * information from the current session into it, adds
	 * ["Q"]["fromSessionId"] pointing to current session,
	 * and saves it in the database.
	 * @method createSessionFromPayload
	 * @param {array} $payload contains the keys
	 *  "Q.Users.platform", "Q.Users.appId", "Q.Users.newSessionId",
	 *  and optionally "Q.Users.deviceId"
	 */
	static function createSessionFromPayload($payload)
	{
		$payload = Q::ifset($payload, $_REQUEST);
		$fields = array('Q.Users.appId', 'Q.Users.newSessionId', 'Q.Users.signature', 'Q.Users.deviceId', 'Q.timestamp', 'Q.Users.platform');
		$payload = Q_Request::fromUnderscores($fields, $payload);
		Q_Valid::requireFields(array('Q.Users.newSessionId'), $payload, true);
		$req = Q::take($payload, array(
			'Q.Users.platform' => 'qbix',
			'Q.Users.appId' => Q::app(),
			'Q.Users.newSessionId' => null,
			'Q.Users.deviceId' => null
		));
		list($appId, $appInfo) = Users::appInfo($req['Q.Users.platform'], $req['Q.Users.appId'], true);
	
		$sessionFields = Q_Request::userAgentInfo();
		$sessionFields['appId'] = $appInfo['appId'];
		if (isset($req['Q.Users.deviceId'])) {
			$sessionFields['deviceId'] = $req['Q.Users.deviceId'];
		}
		$duration_name = Q_Request::formFactor();
		$duration = Q_Config::expect('Q', 'session', 'durations', $duration_name);
		Users_Session::copyToNewSession(
			$sessionFields, $duration, $payload['Q.Users.newSessionId']
		);
	}

	/**
	 * Get redirect URL from payload
	 * @method getRedirectFromPayload
	 * @static
	 * @param {array} $payload the payload generated with
	 *  Users_Session::generatePayload($source)
	 * @param {array} [$source=$_REQUEST] expects it to contain
	 *  the keys "redirect", "appId" and optionally "platform"
	 * @return {string} the full redirect URL with query parameters
	 */
	static function getRedirectFromPayload($payload, $source = null)
	{
		$source = Q::ifset($source, $_REQUEST);
		$baseUrl = Q_Request::baseUrl();
		$req = Q::take($source, array(
			'appId' => Q::app(),
			'platform' => 'qbix',
			'redirect' => $baseUrl
		));
		$redirect = $req['redirect'];
		list($appId, $appInfo) = Users::appInfo($req['platform'], $req['appId'], true);
		$scheme = Q::ifset($appInfo, 'scheme', null);
		$paths = Q::ifset($appInfo, 'paths', null);
		if (Q::startsWith($redirect, $baseUrl)) {
			$path = substr($redirect, strlen($baseUrl)+1);
		} else if (Q::startsWith($redirect, $scheme)) {
			$path = substr($redirect, strlen($scheme));
		} else {
			throw new Users_Exception_Redirect(array('uri' => $redirect));
		}
		$path = $path ?: '/';
		if (is_array($paths) and !in_array($path, $paths)) {
			throw new Users_Exception_Redirect(array('uri' => $redirect));
		}
		$qs = http_build_query($payload);
		return Q_Uri::fixUrl("$redirect?$qs");
	}

	/**
	 * Sets the content of the session to the given array.
	 * This will also update the "php" field with the
	 * serialized session data.
	 * @method setContent
	 * @param {array} $content The content to set in the session.
	 */
	function setContent(array $content)
	{
		$a = $_SESSION;
		$_SESSION = $content;
		$this->content = Q::json_encode($content);
		$this->php = session_encode();
		$_SESSION = $a;
		return $this;
	}
	
	/**
	 * Saves a new Users_Session row with a copy of all the content from the current session.
	 * Also sets ["Q"]["fromSessionId"] in the new session's content.
	 * Does not change the current session id or cookies.
	 * @method copyToNewSession
	 * @static
	 * @param {array} $sessionFields Pass an array with keys such as
	 *   "platform", "appId", "version", "deviceId", "formFactor" and "permissions"
	 *   and they will go into the session data under the "Q" key
	 * @param {string|integer} [$duration='year'] The key in the Q/session/durations config field
	 *   or number of seconds. Pass 0 to expire at the end of browser session.
	 * @param {string} [$sessionId] Pass an existing valid (signed) sessionId here
	 *   otherwise this function will generate the ID.
	 * @return {string} the id of the newly saved Users_Session row
	 */
	static function copyToNewSession($sessionFields, $duration = 'year', $sessionId = null)
	{
		$id = Q_Session::id();
		if (!$id) {
			return null;
		}
		$seconds = is_string($duration)
			? Q_Config::expect('Q', 'session', 'durations', $duration)
			: $duration;

		$arr = isset($_SESSION['Q']) ? $_SESSION['Q'] : null;
		if (isset($arr)) {
			$fields = Q_Config::get('Q', 'session', 'userAgentInfo', array());
			$_SESSION['Q'] = Q::take($sessionFields, $fields, $arr);
		}
		$_SESSION['Q']['fromSessionId'] = $id;

		$loggedInUserId = Q::ifset($_SESSION, 'Users', 'loggedInUser', 'id', null);
		$prefixType = $loggedInUserId ? 'authenticated' : '';
		$sessionId = $sessionId ?: Q_Session::generateId(null, $prefixType);

		$us = new Users_Session();
		$us->id = $id;
		$us->retrieve(null, null, array('lock' => 'FOR UPDATE'));
		$us2 = new Users_Session();
		$us2->id = $sessionId;
		if ($us2->retrieve()) {
			return $us2->id;
		}
		if ($us->wasRetrieved()) {
			$us2->copyFromRow($us, null, false, true);
			$us2->id = $sessionId;
			$us2->wasRetrieved(false);
			$us2->insertedTime = new Db_Expression('CURRENT_TIMESTAMP');
		} else {
			$us2->timeout = 0;
		}
		$us2->content = Q::json_encode((object)$_SESSION);
		$us2->php = session_encode();
		$us2->duration = $seconds;
		$us2->timeout = 0;
		foreach ($sessionFields as $k => $v) {
			$us2->$k = $v;
		}
		$us2->save(false, true);

		if (isset($arr)) {
			$_SESSION['Q'] = $arr;
		}
		/**
		 * This is a hook for after the session has been copied to a new session.
		 * You may want to do extra security checks here.
		 * You may also want to notify the user that there has been a new session
		 * started from a new platform + appId.
		 * @event Users/Session/copyToNewSession {after}
		 * @param {Users_Session} $from The session that was copied from
		 * @param {Users_Session} $to The session that was copied to
		 */
		Q::event("Users/Session/copyToNewSession", array(
			'from' => $us,
			'to' => $us2
		), 'after');
		return $us2->id;
	}

	/**
	 * Implements the __set_state method, so it can work with
	 * with var_export and be re-imported successfully.
	 * @method __set_state
	 * @param {array} $array
	 * @return {Users_Session} Class instance
	 */
	static function __set_state(array $array) {
		$result = new Users_Session();
		foreach($array as $k => $v)
			$result->$k = $v;
		return $result;
	}
};
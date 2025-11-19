<?php
/**
 * Used by external platforms (e.g. Telegram, Web3) to complete
 * a pending authentication intent previously created via
 * POST /Users/intent.
 *
 * @module Users
 * @class HTTP Users intent
 * @method put
 * @param {array} [$params] Parameters that can come from the request
 *   @param {string} $params.token  Required. The intent token returned by POST /Users/intent.
 *   @param {string} $params.platform  Required. The external platform handling this intent (e.g. "telegram", "web3").
 *   @param {string} [$params.payload]  Optional. Signed or verified payload from the external platform.
 *   @param {string} [$params.signature]  Optional. Signature to validate the payload.
 *   @param {string} [$params.address]  Optional. Address or identifier from the platform (e.g. wallet address).
 * @throws {Q_Exception_MissingValue}
 * @throws {Q_Exception_MissingRow}
 * @throws {Users_Exception_IntentExpired}
 * @throws {Users_Exception_NotAuthorized}
 * @return {array} Returns the completed intent info in the "intent" slot:
 *   {
 *     "token": "...",
 *     "sessionId": "...",
 *     "userId": "...",
 *     "completedTime": "..."
 *   }
 */
function Users_intent_put()
{
	Q_Valid::requireRequestMethod('PUT');

	// Extract input
	$fields = Q::take($_REQUEST, array(
		'token' => null,
		'platform' => null,
		'payload' => null,
		'signature' => null,
		'address' => null
	));

	if (empty($fields['token'])) {
		throw new Q_Exception_MissingValue(array(
			'field' => 'token',
			'range' => 'a valid intent token'
		));
	}

	// Retrieve the intent
	$intent = new Users_Intent(array('token' => $fields['token']));
	if (!$intent->retrieve()) {
		throw new Q_Exception_MissingRow(array(
			'table' => 'Users_Intent',
			'criteria' => "token={$fields['token']}"
		));
	}

	if (!empty($intent->completedTime)) {
		throw new Users_Exception_IntentExpired();
	}

	// Load the target session (without setting a cookie)
	if (!empty($intent->sessionId)) {
		Q_Session::start(false, $intent->sessionId, 'internal', array(
			'temporary' => true
		));
	} else {
		throw new Q_Exception_MissingValue(array(
			'field' => 'sessionId',
			'range' => 'a valid session ID',
			'value' => 'empty'
		));
	}

	// Determine platform
	$platform = strtolower(trim($fields['platform']));
	if (!$platform) {
		throw new Q_Exception_MissingValue(array(
			'field' => 'platform',
			'range' => 'telegram, web3, etc.'
		));
	}

	$params = array(
		'intent' => $intent,
		'fields' => $fields,
		'sessionId' => $intent->sessionId
	);

	/**
	 * @event Users/intent/{platform} {before}
	 * Gives the platform a chance to verify payload, signature, etc.
	 * If any handler returns a non-null result, cancel default behavior.
	 */
	$result = Q::event("Users/intent/$platform", $params, 'before');
	if ($result !== null) {
		return $result;
	}

	// --- Default behavior: authenticate user for this platform ---
	Q::event("Users/intent/$platform/authenticate", $params);

	$user = Users::loggedInUser();
	if (!$user) {
		throw new Users_Exception_NotAuthorized();
	}

	// Mark intent as completed
	$intent->completedTime = new Db_Expression('CURRENT_TIMESTAMP');
	$intent->userId = $user->id;
	$intent->save();

	// --- After hooks ---
	Q::event("Users/intent/$platform", $params, 'after');
	Q::event('Users/intent/completed', array(
		'intent' => $intent,
		'user' => $user,
		'platform' => $platform
	), 'after');

	// Respond
	return Q_Response::setSlot('intent', array(
		'token' => $intent->token,
		'sessionId' => $intent->sessionId,
		'userId' => $user->id,
		'completedTime' => $intent->completedTime
	));
}

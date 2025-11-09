<?php

/**
 * Handle recovery of a user session using a previously registered recovery key.
 *
 * @method Users_recover_post
 * @throws Q_Exception_RequiredField
 * @throws Q_Exception_MissingRow
 * @throws Q_Exception
 * @throws Users_Exception_NotAuthorized
 */
function Users_recover_post()
{
	Q_Valid::requireOrigin(true);

	// Step 1 — extract recoveryKey from signed request
	$sigField = Q_Config::get('Users', 'signatures', 'sigField', null);
	$fieldNames = array(array($sigField, 'recoveryKey'));
	Q_Request::requireFields($fieldNames, true);

	$recoveryKey = Q::ifset($_REQUEST, $sigField, 'recoveryKey', null);
	if (!$recoveryKey) {
		throw new Q_Exception_RequiredField(array('field' => 'recoveryKey'));
	}

	// Normalize recoveryKey to string for consistent hashing
	if (!is_string($recoveryKey)) {
		$recoveryKey = json_encode($recoveryKey);
	}

	// Step 2 — find matching Users_Intent by token = sha256(recoveryKey)
	$hash = hash('sha256', $recoveryKey);
	$intent = new Users_Intent();
	$intent->token = $hash;
	if (!$intent->retrieve()) {
		throw new Q_Exception_MissingRow(array(
			'table' => 'Users_Intent',
			'criteria' => "token=$hash"
		));
	}

	if (empty($intent->sessionId)) {
		throw new Q_Exception(array(
			'message' => "Intent found but missing sessionId"
		));
	}

	// Step 3 — resume the original PHP session
	Q_Session::id($intent->sessionId);
	$sessionRow = Q_Session::start();
	if (!$sessionRow) {
		throw new Q_Exception("Could not resume session " . $intent->sessionId);
	}
	$sessionId = session_id();

	// Step 4 — mark the intent as recovered
	$gcMax = intval(ini_get('session.gc_maxlifetime'));

	$intent->action = 'Users.recoverSession';
	$intent->setInstruction(array(
		'recoveryKey' => $recoveryKey,
		'recoveredAt' => date('c'),
		'resumedSessionId' => $sessionId
	));
	$intent->endTime = $intent->db()->toDateTime(time() + $gcMax);
	$intent->save();

	// Step 5 — attach recovery info to PHP session
	if (!isset($_SESSION['Users'])) {
		$_SESSION['Users'] = array();
	}
	$_SESSION['Users']['recovered'] = true;
	$_SESSION['Users']['recoveryKey'] = $recoveryKey;
	$_SESSION['Users']['recoveryIntent'] = $intent->token;

	// Step 6 — respond
	Q_Response::setSlot('session', array(
		'recovered' => true,
		'sessionId' => $sessionId,
		'intentToken' => $intent->token
	));
	Q_Response::setSlot('recoveryKey', $recoveryKey);
	Q_Response::setSlot('saved', true);
}
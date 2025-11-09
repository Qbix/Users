<?php

function Users_key_post()
{
	Q_Valid::requireOrigin(true);

	$sigField = Q_Config::get('Users', 'signatures', 'sigField', null);
	$fieldNames = array('info', array($sigField, 'publicKey'), array($sigField, 'recoveryKey'));
	Q_Request::requireFields($fieldNames, true);

	$info         = $_REQUEST['info'];
	$publicKey    = $_REQUEST[$sigField]['publicKey'];
	$recoveryKey  = $_REQUEST[$sigField]['recoveryKey'];
	$publicKeyIsEphemeral = !empty($_REQUEST['publicKeyIsEphemeral']);

	// Validate algorithm
	if ($info['name'] !== 'ECDSA'
	or $info['namedCurve'] !== 'P-256'
	or $info['hash'] !== 'SHA-256') {
		throw new Q_Exception_WrongValue(array('field' => 'info'));
	}

	// Start PHP + DB session
	$sessionRow = Q_Session::start();
	if (!$sessionRow) {
		throw new Q_Exception("Could not start session");
	}
	$sessionId = session_id();

	// If a key already exists, allow overwrite if ephemeral
	if (!empty($_SESSION['Users']['publicKey']) && empty($_SESSION['Users']['publicKeyIsEphemeral'])) {
		throw new Q_Exception_AlreadyExists(array('source' => 'session key'));
	}

	// Save to PHP session for convenience
	$_SESSION['Users']['publicKey'] = $publicKey;
	$_SESSION['Users']['recoveryKey'] = $recoveryKey;
	$_SESSION['Users']['publicKeyIsEphemeral'] = $publicKeyIsEphemeral;

	// Only create a persistent intent if the key is not ephemeral
	if (!$publicKeyIsEphemeral) {
		$token = Q_Utils::signature(compact('recoveryKey'));

		$duration = isset($sessionRow->duration)
			? intval($sessionRow->duration)
			: intval(ini_get('session.gc_maxlifetime'));

		$intent = new Users_Intent(array(
			'token' => $token,
			'action' => 'Users.registerRecoveryKey',
			'instructions' => json_encode(array(
				'info' => $info,
				'publicKey' => $publicKey,
				'recoveryKey' => $recoveryKey
			)),
			'sessionId' => $sessionId,
			'startTime' => new Db_Expression('CURRENT_TIMESTAMP'),
			'endTime'   => new Db_Expression('CURRENT_TIMESTAMP + INTERVAL ' . intval($duration) . ' SECOND')
		));
		$intent->save();

		Q_Response::setSlot('recoveryToken', $token);
	} else {
		Q::log("Users_key_post: ephemeral key registered, skipping persistent recovery intent.");
	}

	Q_Response::setSlot('saved', true);
}
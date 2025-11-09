<?php

function Users_key_post()
{
    Q_Valid::requireOrigin(true);

	$sigField = Q_Config::get('Users', 'signatures', 'sigField', null);
	$fieldNames = array('info', array($sigField, 'publicKey'), array($sigField, 'recoveryKey'));
	Q_Request::requireFields($fieldNames, true);

	$info = $_REQUEST['info'];
	$publicKey  = $_REQUEST[$sigField]['publicKey'];
	$recoveryKey = $_REQUEST[$sigField]['recoveryKey'];

	// Validate expected algorithm
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

	// Prevent overwriting existing session key
	if (!empty($_SESSION['Users']['publicKey'])) {
		throw new Q_Exception_AlreadyExists(array('source' => 'session key'));
	}

	// Save to PHP session for convenience
	$_SESSION['Users']['publicKey'] = $publicKey;
	$_SESSION['Users']['recoveryKey'] = $recoveryKey;

	// Create Users_Intent to store recovery info, tied to session lifetime
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

	Q_Response::setSlot('saved', true);
	Q_Response::setSlot('recoveryToken', $token);
}

<?php

/**
 * Registers a session public key and recovery key for the currently authenticated session.
 *
 * Called automatically by the client after generating a new non-extractable session keypair.
 * If the session key is ephemeral, it will only be stored in PHP session and not persisted.
 * If persistent, creates a recovery intent allowing the user to later restore their session
 * using the recovery key.
 *
 * @module Users
 * @class HTTP Users key
 * @method post
 * @param {array} [$params] Parameters that can come from the request
 *   @param {array} $params.info  Required. Information about the key algorithm:
 *     {
 *       "name": "ECDSA",
 *       "namedCurve": "P-256",
 *       "hash": "SHA-256"
 *     }
 *   @param {array} $params.publicKey  Required. JWK representation of the session public key.
 *   @param {array} $params.recoveryKey  Required. JWK representation of the recovery public key.
 *   @param {boolean} [$params.publicKeyIsEphemeral=false]  Optional. Whether the key is ephemeral.
 *   @param {array} $params.Q_Users_sig  Required. Signature object:
 *     {
 *       "signature": "...",
 *       "publicKey": "...",
 *       "fieldNames": ["info", "publicKey", "recoveryKey"]
 *     }
 * @throws {Q_Exception_WrongValue}
 * @throws {Q_Exception_AlreadyExists}
 * @throws {Q_Exception}
 * @return {array} Returns:
 *   {
 *     "saved": true,
 *     "recoveryToken": "..." (if persistent)
 *   }
 */
function Users_key_post()
{
	Q_Valid::requireOrigin(true);

	$sigField = Q_Config::get('Users', 'signatures', 'sigField', 'Q_Users_sig');

	// Require the expected top-level fields and signature structure
	$fieldNames = array(
		'info',
		'publicKey',
		'recoveryKey',
		array($sigField, 'signature'),
		array($sigField, 'publicKey'),
		array($sigField, 'fieldNames')
	);
	Q_Request::requireFields($fieldNames, true);

	$info         = $_REQUEST['info'];
	$publicKey    = $_REQUEST['publicKey'];
	$recoveryKey  = $_REQUEST['recoveryKey'];
	$sig          = $_REQUEST[$sigField];
	$publicKeyIsEphemeral = !empty($_REQUEST['publicKeyIsEphemeral']);

	// Ensure fieldNames covers all three data fields
	if (empty($sig['fieldNames']) || !is_array($sig['fieldNames'])) {
		throw new Q_Exception_WrongValue(array('field' => $sigField . '[fieldNames]'));
	}
	foreach (['info', 'publicKey', 'recoveryKey'] as $f) {
		if (!in_array($f, $sig['fieldNames'])) {
			throw new Q_Exception_WrongValue(array(
				'field' => $sigField . '[fieldNames]',
				'value' => "missing $f"
			));
		}
	}

	// Validate algorithm parameters
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

	// Prevent overwriting a persistent key
	if (!empty($_SESSION['Users']['publicKey']) && empty($_SESSION['Users']['publicKeyIsEphemeral'])) {
		throw new Q_Exception_AlreadyExists(array('source' => 'session key'));
	}

	// Store in PHP session
	$_SESSION['Users']['publicKey'] = $publicKey;
	$_SESSION['Users']['recoveryKey'] = $recoveryKey;
	$_SESSION['Users']['publicKeyIsEphemeral'] = $publicKeyIsEphemeral;

	// If persistent, create an intent for recovery
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
	}

	Q_Response::setSlot('saved', true);
}
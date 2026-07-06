<?php
/**
 * @module Amb
 */
/**
 * Apple Messages for Business (AMB) model.
 *
 * Named after the platform (like Telegram, Facebook), but ships inside the
 * Users plugin. Holds shared config + crypto + policy text for the AMB channel.
 * Sending lives in Amb_Client (like Telegram_Bot); delivery in
 * Users_ExternalFrom_Amb (like Users_ExternalFrom_Telegram).
 *
 * Config lives under Users/apps/amb/$appName (same shape as telegram, twitter):
 *   mspId       - the MSP ID; JWT "iss" (out) / "aud" (in). NOT the business id.
 *   businessId  - the business id (urn:biz:...); the Source-Id when sending.
 *   secret      - the Messaging API secret key, Base64-encoded. Decode before use.
 *   endpoint    - optional; defaults to production. Staging: mspgw-int...
 *
 * @class Amb
 * @abstract
 */
abstract class Amb
{
	/**
	 * The business-chat extension bundle id required on every interactive message.
	 * @property BID
	 */
	const BID = 'com.apple.messages.MSMessageExtensionBalloonPlugin:0000000000:com.apple.icloud.apps.messages.business.extension';

	const ENDPOINT_PRODUCTION = 'https://mspgw.push.apple.com/v1/message';
	const ENDPOINT_STAGING    = 'https://mspgw-int.push.apple.com/v1/message';

	/**
	 * The disclosure Apple's policy requires before sending account/transaction
	 * notifications, keyed by 2-letter language. Send it (in the user's language)
	 * when a customer opts in. Add languages as needed.
	 * @property $NOTICE
	 */
	static $NOTICE = array(
		'en' => "We will send important notifications related to your account status or transactions. Send 'Unsubscribe' to manage your message preferences."
	);

	/**
	 * Read and validate config for an AMB app.
	 * @method appInfo
	 * @static
	 * @param {string} [$appId=Q::app()]
	 * @return {array} array($appId, $info)
	 * @throws {Q_Exception_MissingConfig}
	 */
	static function appInfo($appId = null)
	{
		list($appId, $info) = Users::appInfo('amb', $appId, true);
		foreach (array('mspId', 'businessId', 'secret') as $field) {
			if (empty($info[$field])) {
				throw new Q_Exception_MissingConfig(array(
					'fieldpath' => "Users/apps/amb/$appId/$field"
				));
			}
		}
		return array($appId, $info);
	}

	/**
	 * The endpoint URL for an app (config override or production default).
	 * @method endpoint
	 * @static
	 */
	static function endpoint($info)
	{
		return !empty($info['endpoint']) ? $info['endpoint'] : self::ENDPOINT_PRODUCTION;
	}

	/**
	 * The required opt-in disclosure text, in the customer's language.
	 * @method notificationNotice
	 * @static
	 * @param {string} [$locale] e.g. "en_US" (from the inbound message)
	 * @return {string}
	 */
	static function notificationNotice($locale = null)
	{
		$lang = $locale ? strtolower(substr($locale, 0, 2)) : 'en';
		return Q::ifset(self::$NOTICE, $lang, self::$NOTICE['en']);
	}

	/**
	 * "Authorization: Bearer <jwt>" for OUTBOUND messages.
	 * claims { iss: MSP-ID, iat: unix seconds }, HS256, secret Base64-decoded.
	 * @method authorizationHeader
	 * @static
	 */
	static function authorizationHeader($info)
	{
		$key = base64_decode($info['secret']);
		$header = self::base64url(Q::json_encode(array('alg' => 'HS256')));
		$claims = self::base64url(Q::json_encode(array(
			'iss' => $info['mspId'], // MSP ID, NOT the business id
			'iat' => time()          // seconds, not milliseconds
		)));
		$signingInput = "$header.$claims";
		$signature = self::base64url(hash_hmac('sha256', $signingInput, $key, true));
		return "Bearer $signingInput.$signature";
	}

	/**
	 * Verify the Authorization header on an INBOUND request from Apple.
	 * Inbound claims: { aud: <our MSP-ID>, iat }.
	 * @method verifyAuthorization
	 * @static
	 * @return {boolean}
	 */
	static function verifyAuthorization($appId, $authorizationHeader)
	{
		$jwt = preg_replace('/^\s*Bearer\s+/i', '', trim((string)$authorizationHeader));
		$parts = explode('.', $jwt);
		if (count($parts) !== 3) {
			return false;
		}
		list($h64, $c64, $s64) = $parts;
		list($appId, $info) = self::appInfo($appId);
		$key = base64_decode($info['secret']);
		$expected = self::base64url(hash_hmac('sha256', "$h64.$c64", $key, true));
		if (!hash_equals($expected, $s64)) {
			return false;
		}
		$claims = json_decode(self::base64urlDecode($c64), true);
		if (Q::ifset($claims, 'aud', null) !== $info['mspId']) {
			return false;
		}
		$iat = intval(Q::ifset($claims, 'iat', 0));
		return abs(time() - $iat) <= 3600;
	}

	/**
	 * Insert or fetch a Users_User for an AMB opaque customer id.
	 * @method futureUser
	 * @static
	 * @return {Users_User}
	 */
	static function futureUser($appId, $xid, &$status = null, &$inserted = null)
	{
		return Users::futureUser('amb_all', $xid, $status, $inserted);
	}

	/**
	 * Generate a version-4 UUID.
	 * @method uuid
	 * @static
	 */
	static function uuid()
	{
		return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
			mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
			mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
		);
	}

	/**
	 * URL-safe base64 without padding.
	 * @method base64url
	 * @static
	 */
	static function base64url($data)
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	/**
	 * Decode URL-safe base64.
	 * @method base64urlDecode
	 * @static
	 */
	static function base64urlDecode($data)
	{
		$pad = (4 - strlen($data) % 4) % 4;
		return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', $pad));
	}
}

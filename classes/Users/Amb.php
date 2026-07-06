<?php
/**
 * @module Users
 */
/**
 * Apple Messages for Business (AMB) model.
 *
 * Holds the shared configuration and crypto for the AMB channel, the way the
 * Telegram model holds Telegram::verifyData() etc. The message-sending methods
 * live in Users_Amb_Client (analogous to Telegram_Bot), and the delivery
 * adapter lives in Users_ExternalFrom_Amb (analogous to Users_ExternalFrom_Telegram).
 *
 * Config lives under Users/apps/amb/$appName (same shape as telegram, twitter):
 *   mspId       - the MSP ID; used as JWT "iss" outbound and "aud" inbound.
 *                 NOT the business id.
 *   businessId  - the business id (urn:biz:...); the Source-Id when sending.
 *   secret      - the Messaging API secret key, Base64-encoded. Decode before use.
 *   endpoint    - optional; defaults to production. Staging: mspgw-int...
 *
 * @class Users_Amb
 * @abstract
 */
abstract class Users_Amb
{
	/**
	 * The business-chat extension bundle id required on every interactive message.
	 * @property BID
	 */
	const BID = 'com.apple.messages.MSMessageExtensionBalloonPlugin:0000000000:com.apple.icloud.apps.messages.business.extension';

	const ENDPOINT_PRODUCTION = 'https://mspgw.push.apple.com/v1/message';
	const ENDPOINT_STAGING    = 'https://mspgw-int.push.apple.com/v1/message';

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
	 * Return the endpoint URL for an app (config override or production default).
	 * @method endpoint
	 * @static
	 * @param {array} $info the appInfo array
	 * @return {string}
	 */
	static function endpoint($info)
	{
		return !empty($info['endpoint']) ? $info['endpoint'] : self::ENDPOINT_PRODUCTION;
	}

	/**
	 * Build the "Authorization: Bearer <jwt>" header value for OUTBOUND messages.
	 * header { "alg": "HS256" }; claims { "iss": MSP-ID, "iat": unix seconds }.
	 * Signed with the Base64-decoded secret. Regenerate at least hourly.
	 * @method authorizationHeader
	 * @static
	 * @param {array} $info the appInfo array (mspId, secret)
	 * @return {string}
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
	 * Verify the "Authorization" header on an INBOUND request from Apple.
	 * The inbound JWT carries claims { "aud": <our MSP-ID>, "iat": ... }.
	 * @method verifyAuthorization
	 * @static
	 * @param {string} $appId
	 * @param {string} $authorizationHeader the raw header value, e.g. "Bearer x.y.z"
	 * @return {boolean} true when the signature, audience and freshness all pass
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
	 * The opaque id carries no profile data, so nothing is imported.
	 * @method futureUser
	 * @static
	 * @param {string} $appId
	 * @param {string} $xid the customer's opaque id
	 * @param {&string} [$status=null]
	 * @param {&boolean} [$inserted=null]
	 * @return {Users_User}
	 */
	static function futureUser($appId, $xid, &$status = null, &$inserted = null)
	{
		return Users::futureUser('amb_all', $xid, $status, $inserted);
	}

	/**
	 * Generate a version-4 UUID (message id / requestIdentifier).
	 * @method uuid
	 * @static
	 * @return {string}
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
	 * URL-safe base64 without padding (for JWT segments).
	 * @method base64url
	 * @static
	 * @param {string} $data
	 * @return {string}
	 */
	static function base64url($data)
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	/**
	 * Decode URL-safe base64.
	 * @method base64urlDecode
	 * @static
	 * @param {string} $data
	 * @return {string}
	 */
	static function base64urlDecode($data)
	{
		$pad = (4 - strlen($data) % 4) % 4;
		return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', $pad));
	}
}

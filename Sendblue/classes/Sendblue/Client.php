<?php
/**
 * @module Sendblue
 */
/**
 * Sendblue API v2 client — native iMessage (blue bubbles) with SMS/RCS fallback,
 * no Apple MSP. Base https://api.sendblue.com, two-header auth. Ships in its own
 * Sendblue plugin (like Telegram); the platform adapter is Users_ExternalFrom_Sendblue.
 *
 * Config under Users/apps/sendblue/$appName:
 *   apiKeyId   - sb-api-key-id
 *   apiSecret  - sb-api-secret-key
 *   from       - a Sendblue line on your account, E.164
 *
 * @class Sendblue_Client
 * @abstract
 */
abstract class Sendblue_Client
{
	const BASE = 'https://api.sendblue.com';

	/**
	 * Read + validate config for a Sendblue app.
	 * @method appInfo
	 * @static
	 * @return {array} array($appId, $info)
	 */
	static function appInfo($appId = null)
	{
		list($appId, $info) = Users::appInfo('sendblue', $appId, true);
		foreach (array('apiKeyId', 'apiSecret', 'from') as $f) {
			if (empty($info[$f])) {
				throw new Q_Exception_MissingConfig(array(
					'fieldpath' => "Users/apps/sendblue/$appId/$f"
				));
			}
		}
		return array($appId, $info);
	}

	/**
	 * Send a message (iMessage, auto-falls-back to SMS).
	 * @method sendMessage
	 * @static
	 * @param {string} $appId
	 * @param {string} $number E.164 recipient
	 * @param {string} $content
	 * @param {array} [$options] media_url, send_style, status_callback
	 * @return {array} the v2 "data" payload (message_handle, status, service, ...)
	 */
	static function sendMessage($appId, $number, $content, array $options = array())
	{
		list($appId, $info) = self::appInfo($appId);
		$body = array(
			'number'      => $number,
			'from_number' => $info['from'],
			'content'     => (string)$content
		);
		foreach (array('media_url', 'send_style', 'status_callback') as $k) {
			if (!empty($options[$k])) {
				$body[$k] = $options[$k];
			}
		}
		return self::api($info, 'POST', '/api/send-message', $body);
	}

	/**
	 * Check whether a number is on iMessage / SMS / RCS.
	 * @method evaluateService
	 * @static
	 */
	static function evaluateService($appId, $number)
	{
		list($appId, $info) = self::appInfo($appId);
		return self::api($info, 'GET', '/api/evaluate-service?number=' . urlencode($number));
	}

	/**
	 * Low-level request. Returns the decoded "data" on success (v2 wraps results
	 * as { status:'OK'|'ERROR', message, data }).
	 * @method api
	 * @static
	 * @throws {Q_Exception}
	 */
	static function api($info, $method, $path, array $body = null)
	{
		$ch = curl_init(self::BASE . $path);
		$headers = array(
			'Content-Type: application/json',
			'sb-api-key-id: ' . $info['apiKeyId'],
			'sb-api-secret-key: ' . $info['apiSecret']
		);
		curl_setopt_array($ch, array(
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 30
		));
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, Q::json_encode($body));
		}
		$response = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		if ($error) {
			throw new Q_Exception("Sendblue_Client: transport error: $error");
		}
		$decoded = Q::json_decode($response, true);
		if ($code < 200 || $code >= 300 || Q::ifset($decoded, 'status', null) === 'ERROR') {
			$msg = Q::ifset($decoded, 'message', substr((string)$response, 0, 200));
			throw new Q_Exception("Sendblue_Client: HTTP $code $msg");
		}
		return Q::ifset($decoded, 'data', $decoded);
	}
}

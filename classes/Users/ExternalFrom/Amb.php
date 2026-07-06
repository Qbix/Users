<?php
/**
 * @module Users
 */
/**
 * Apple Messages for Business (AMB) identity, for delivering notifications.
 * Mirror of Users_ExternalFrom_Telegram: the delivery adapter that Streams'
 * Message::deliver() invokes via Users_ExternalFrom::pushNotification().
 *
 * A row's xid is the customer's opaque id (Apple's sourceId on inbound). The
 * business itself is the app, configured under Users/apps/amb/$appName.
 *
 * @class Users_ExternalFrom_Amb
 * @extends Users_ExternalFrom
 */
class Users_ExternalFrom_Amb extends Users_ExternalFrom implements Users_ExternalFrom_Interface
{
	/**
	 * AMB users don't authenticate into a web session (there is no browser
	 * login flow). Identities are created by the inbound webhook instead.
	 * @method authenticate
	 * @static
	 * @param {string} [$appId=Q::app()]
	 * @param {boolean} [$setCookie=true]
	 * @param {boolean} [$longLived=true]
	 * @return {null}
	 */
	static function authenticate($appId = null, $setCookie = true, $longLived = true)
	{
		return null;
	}

	/**
	 * The opaque id carries no profile image.
	 * @method icon
	 * @param {array} [$sizes=null]
	 * @param {string} [$suffix='']
	 * @return {array}
	 */
	function icon($sizes = null, $suffix = '')
	{
		return array();
	}

	/**
	 * The opaque id carries no importable profile fields.
	 * @method import
	 * @param {array} [$fieldNames=null]
	 * @return {array}
	 */
	function import($fieldNames = null)
	{
		return array();
	}

	/**
	 * Sends a notification to the user over Apple Messages for Business.
	 * Builds a text message and appends any link, the same way the Telegram
	 * adapter does; richer messages can be sent directly via Users_Amb_Client.
	 * @method handlePushNotification
	 * @param {array} $notification the notification array (alert, href, ref)
	 * @param {array} [$options]
	 * @return {boolean} true on success, false on failure
	 */
	function handlePushNotification($notification, $options = array())
	{
		$xid = Q::ifset($this->fields, 'xid', null);
		if (!$xid) {
			return false;
		}
		$appId = $this->appId;
		if ($appId === 'all') {
			$appId = Q::app();
		}
		$baseUrl = Q_Config::get(array('Users', 'apps', 'baseUrl'), '');

		// Build text
		$text = '';
		$alert = Q::ifset($notification, 'alert', null);
		if (is_string($alert)) {
			$text = $alert;
		} else if (is_array($alert) && !empty($alert['body'])) {
			$text = $alert['body'];
		}

		// Append any link
		if (!empty($notification['href'])) {
			$link = $notification['href'];
			if (strlen($link) && $link[0] === '/') {
				$link = $baseUrl . $link;
			}
			$text .= "\n\n" . $link;
		}

		try {
			Users_Amb_Client::sendMessage($appId, $xid, $text);
			return true;
		} catch (Exception $e) {
			$msg = strtolower($e->getMessage());

			// Hard permanent rejects (customer unreachable via AMB)
			if (strpos($msg, 'http 404') !== false
			|| strpos($msg, 'http 403') !== false
			|| strpos($msg, 'forbidden') !== false) {
				$e->rejected = true;
			}

			// Soft rejects (retryable)
			if (strpos($msg, 'http 429') !== false
			|| strpos($msg, 'too many requests') !== false) {
				$e->rateLimited = true;
			}

			return false;
		}
	}
}

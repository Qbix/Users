<?php
/**
 * @module Amb
 */
/**
 * Apple Messages for Business (AMB) client.
 * The message-sending surface, modeled on Telegram_Bot: one api() core plus thin
 * send* helpers. Config + crypto live in Amb. Ships inside the Users plugin.
 *
 * Scope: text, rich link, quick reply, list picker, template notifications,
 * typing, plus the opt-in disclosure. Apple Pay, authentication, forms, custom
 * iMessage apps and the large-attachment (/preUpload) flow are omitted.
 *
 * @class Amb_Client
 * @abstract
 */
abstract class Amb_Client
{
	/**
	 * Send a plain text message (in-conversation reply).
	 * @method sendMessage
	 * @static
	 * @param {string} $appId
	 * @param {string} $xid the customer's opaque id (Destination-Id)
	 * @param {string} $text
	 * @param {array} [$options] 'locale' => 'en_US'
	 * @return {integer} HTTP status code
	 */
	static function sendMessage($appId, $xid, $text, array $options = array())
	{
		$body = array('type' => 'text', 'v' => 1, 'body' => (string)$text);
		if (!empty($options['locale'])) {
			$body['locale'] = $options['locale'];
		}
		return self::api($appId, $xid, $body);
	}

	/**
	 * Send the required opt-in disclosure, in the customer's language.
	 * Apple policy: before sending account/transaction notifications, the business
	 * must send this notice. Call it right after a customer subscribes.
	 * @method sendSubscriptionNotice
	 * @static
	 * @param {string} $appId
	 * @param {string} $xid
	 * @param {string} [$locale] e.g. "en_US" (from the inbound message)
	 * @return {integer} HTTP status code
	 */
	static function sendSubscriptionNotice($appId, $xid, $locale = null)
	{
		return self::sendMessage($appId, $xid, Amb::notificationNotice($locale), compact('locale'));
	}

	/**
	 * Send a rich link (URL card with a title + image/video asset).
	 * @method sendRichLink
	 * @static
	 */
	static function sendRichLink($appId, $xid, $url, $title, array $options = array())
	{
		$assets = array();
		if (!empty($options['imageData'])) {
			$assets['image'] = array(
				'data'     => $options['imageData'],
				'mimeType' => Q::ifset($options, 'imageMimeType', 'image/jpeg')
			);
		}
		if (!empty($options['videoUrl'])) {
			$assets['video'] = array(
				'url'      => $options['videoUrl'],
				'mimeType' => Q::ifset($options, 'videoMimeType', 'video/mp4')
			);
		}
		$richLinkData = array('url' => $url, 'title' => $title);
		if ($assets) {
			$richLinkData['assets'] = $assets;
		}
		$body = array('type' => 'richLink', 'v' => 1, 'richLinkData' => $richLinkData);
		return self::api($appId, $xid, $body);
	}

	/**
	 * Send a Quick Reply (2-5 tap options). Good for "Manage topics" / "Help".
	 * @method sendQuickReply
	 * @static
	 * @param {array} $items each array('identifier'=>..., 'title'=>...)
	 */
	static function sendQuickReply($appId, $xid, $summaryText, array $items, array $options = array())
	{
		$data = array(
			'version' => '1.0',
			'requestIdentifier' => Amb::uuid(),
			'quickReply' => array(
				'summaryText' => $summaryText,
				'items' => array_values($items)
			)
		);
		$received = Q::ifset($options, 'receivedMessage', array('style' => 'small', 'title' => $summaryText));
		$reply = Q::ifset($options, 'replyMessage', array('style' => 'small', 'title' => $summaryText));
		return self::sendInteractive($appId, $xid, $data, $received, $reply);
	}

	/**
	 * Send a List Picker (single/multi-select list). Good for a topic menu.
	 * @method sendListPicker
	 * @static
	 * @param {array} $sections each array('title','order','multipleSelection','items'=>[...])
	 */
	static function sendListPicker($appId, $xid, array $sections, array $options = array())
	{
		$data = array(
			'version' => '1.0',
			'requestIdentifier' => Amb::uuid(),
			'listPicker' => array('sections' => array_values($sections))
		);
		if (!empty($options['images'])) {
			$data['images'] = array_values($options['images']);
		}
		$received = Q::ifset($options, 'receivedMessage', array('style' => 'small', 'title' => 'Choose'));
		$reply = Q::ifset($options, 'replyMessage', array('style' => 'small', 'title' => 'Selection'));
		return self::sendInteractive($appId, $xid, $data, $received, $reply);
	}

	/**
	 * Send a proactive, template-based notification. REQUIRES an approved template
	 * in Apple Business Register; adds the "message-type: notification" header.
	 * @method sendNotification
	 * @static
	 */
	static function sendNotification($appId, $xid, $templateId, array $notification = array(), array $options = array())
	{
		$data = array(
			'version' => '1.0',
			'requestIdentifier' => Amb::uuid(),
			'notification' => array_merge(array(
				'templateId'  => $templateId,
				'referenceId' => Q::ifset($options, 'referenceId', Amb::uuid())
			), $notification)
		);
		return self::sendInteractive($appId, $xid, $data, array(), array(), array(
			'message-type: notification'
		));
	}

	/**
	 * Send a typing indicator (typing_start / typing_end).
	 * @method sendTyping
	 * @static
	 */
	static function sendTyping($appId, $xid, $active = true)
	{
		return self::api($appId, $xid, array(
			'type' => $active ? 'typing_start' : 'typing_end', 'v' => 1
		));
	}

	/**
	 * Wrap an interactiveData payload and send it. receivedMessage/replyMessage
	 * sit alongside "data" inside "interactiveData", per Apple's native schema.
	 * @method sendInteractive
	 * @static
	 */
	static function sendInteractive($appId, $xid, array $data, array $received = array(), array $reply = array(), array $extraHeaders = array())
	{
		$interactiveData = array('bid' => Amb::BID, 'data' => $data);
		if ($received) {
			$interactiveData['receivedMessage'] = $received;
		}
		if ($reply) {
			$interactiveData['replyMessage'] = $reply;
		}
		$body = array('type' => 'interactive', 'v' => 1, 'interactiveData' => $interactiveData);
		return self::api($appId, $xid, $body, $extraHeaders);
	}

	/**
	 * The one core call: sign a JWT, set headers, POST JSON to /v1/message.
	 * Mirrors Telegram_Bot::api(). Success is an HTTP 2xx with an empty body.
	 * @method api
	 * @static
	 * @return {integer} HTTP status code
	 * @throws {Q_Exception} on transport error or non-2xx response
	 */
	static function api($appId, $xid, array $body, array $extraHeaders = array())
	{
		list($appId, $info) = Amb::appInfo($appId);
		$businessId = $info['businessId'];
		$id = Amb::uuid();

		// Outbound: business is the source, customer is the destination.
		$body = array_merge(array(
			'id' => $id,
			'sourceId' => $businessId,
			'destinationId' => $xid
		), $body);

		$headers = array_merge(array(
			'Authorization: ' . Amb::authorizationHeader($info),
			'id: ' . $id,
			'Source-Id: ' . $businessId,
			'Destination-Id: ' . $xid,
			'Content-Type: application/json',
			'Accept: application/json'
		), $extraHeaders);

		$ch = curl_init(Amb::endpoint($info));
		curl_setopt_array($ch, array(
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => Q::json_encode($body),
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 30
		));
		$response = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		if ($error) {
			throw new Q_Exception("Amb_Client: transport error: $error");
		}
		if ($code < 200 || $code >= 300) {
			// 404 => customer unreachable via AMB; fall back to the next channel.
			// 429 => rate limited. Other 4xx => permanent reject.
			throw new Q_Exception("Amb_Client: HTTP $code " . substr((string)$response, 0, 200));
		}
		return $code;
	}
}

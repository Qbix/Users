<?php
/**
 * @module Users
 */
/**
 * Apple Messages for Business (AMB) client.
 * The message-sending surface for AMB, modeled on Telegram_Bot: one low-level
 * api() core plus thin send* helpers. Crypto and config live in Users_Amb.
 *
 * Scope: text, rich link, quick reply, list picker, template notifications and
 * typing indicators. Apple Pay, authentication, forms, custom iMessage apps and
 * the large-attachment (/preUpload) flow are intentionally omitted.
 *
 * @class Users_Amb_Client
 * @abstract
 */
abstract class Users_Amb_Client
{
	/**
	 * Send a plain text message (in-conversation reply).
	 * @method sendMessage
	 * @static
	 * @param {string} $appId appId under Users/apps/amb
	 * @param {string} $xid   the customer's opaque id (becomes Destination-Id)
	 * @param {string} $text
	 * @param {array}  [$options]
	 *   @param {string} [$options.locale] e.g. "en_US"
	 * @return {integer} HTTP status code (200 on success)
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
	 * Send a rich link: a URL card with a title and an image or video asset.
	 * The workhorse for "new photos in the gallery" style notifications.
	 * @method sendRichLink
	 * @static
	 * @param {string} $appId
	 * @param {string} $xid
	 * @param {string} $url   destination URL
	 * @param {string} $title
	 * @param {array}  [$options]
	 *   @param {string} [$options.imageData] base64 of a preview image
	 *   @param {string} [$options.imageMimeType="image/jpeg"]
	 *   @param {string} [$options.videoUrl] a video preview URL (instead of an image)
	 *   @param {string} [$options.videoMimeType="video/mp4"]
	 * @return {integer} HTTP status code
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
	 * Send a Quick Reply: 2-5 tap options. Good for STOP / "manage topics".
	 * @method sendQuickReply
	 * @static
	 * @param {string} $appId
	 * @param {string} $xid
	 * @param {string} $summaryText shown in the notification and transcript
	 * @param {array}  $items 2-5 arrays, each array('identifier'=>..., 'title'=>...)
	 * @param {array}  [$options]
	 *   @param {array} [$options.receivedMessage] override the received bubble
	 *   @param {array} [$options.replyMessage] override the reply bubble
	 * @return {integer} HTTP status code
	 */
	static function sendQuickReply($appId, $xid, $summaryText, array $items, array $options = array())
	{
		$data = array(
			'version' => '1.0',
			'requestIdentifier' => Users_Amb::uuid(),
			'quickReply' => array(
				'summaryText' => $summaryText,
				'items' => array_values($items)
			)
		);
		$received = Q::ifset($options, 'receivedMessage', array(
			'style' => 'small', 'title' => $summaryText
		));
		$reply = Q::ifset($options, 'replyMessage', array(
			'style' => 'small', 'title' => $summaryText
		));
		return self::sendInteractive($appId, $xid, $data, $received, $reply);
	}

	/**
	 * Send a List Picker: single- or multi-select list (more than 5 options).
	 * Good for a topic subscription menu.
	 * @method sendListPicker
	 * @static
	 * @param {string} $appId
	 * @param {string} $xid
	 * @param {array}  $sections each array(
	 *     'title'=>..., 'order'=>int, 'multipleSelection'=>bool,
	 *     'items'=>array(array('identifier'=>...,'title'=>...,'subtitle'=>...,
	 *                    'style'=>'default','order'=>int,'imageIdentifier'=>...), ...))
	 * @param {array}  [$options]
	 *   @param {array} [$options.images] array of array('identifier'=>...,'data'=>base64)
	 *   @param {array} [$options.receivedMessage]
	 *   @param {array} [$options.replyMessage]
	 * @return {integer} HTTP status code
	 */
	static function sendListPicker($appId, $xid, array $sections, array $options = array())
	{
		$data = array(
			'version' => '1.0',
			'requestIdentifier' => Users_Amb::uuid(),
			'listPicker' => array('sections' => array_values($sections))
		);
		if (!empty($options['images'])) {
			$data['images'] = array_values($options['images']);
		}
		$received = Q::ifset($options, 'receivedMessage', array(
			'style' => 'small', 'title' => 'Choose'
		));
		$reply = Q::ifset($options, 'replyMessage', array(
			'style' => 'small', 'title' => 'Selection'
		));
		return self::sendInteractive($appId, $xid, $data, $received, $reply);
	}

	/**
	 * Send a proactive, business-initiated notification.
	 * REQUIRES a notification template approved in Apple Business Register, and
	 * adds the "message-type: notification" header. This is the path for topic
	 * pushes sent outside an active conversation ("your gallery updated").
	 * @method sendNotification
	 * @static
	 * @param {string} $appId
	 * @param {string} $xid
	 * @param {string} $templateId the approved template id
	 * @param {array}  [$notification] template-specific fields merged into "notification"
	 * @param {array}  [$options]
	 *   @param {string} [$options.referenceId]
	 * @return {integer} HTTP status code
	 */
	static function sendNotification($appId, $xid, $templateId, array $notification = array(), array $options = array())
	{
		$data = array(
			'version' => '1.0',
			'requestIdentifier' => Users_Amb::uuid(),
			'notification' => array_merge(array(
				'templateId'  => $templateId,
				'referenceId' => Q::ifset($options, 'referenceId', Users_Amb::uuid())
			), $notification)
		);
		return self::sendInteractive($appId, $xid, $data, array(), array(), array(
			'message-type: notification'
		));
	}

	/**
	 * Send a typing indicator. For bots Apple recommends ~1s before each message.
	 * @method sendTyping
	 * @static
	 * @param {string} $appId
	 * @param {string} $xid
	 * @param {boolean} [$active=true] true => typing_start, false => typing_end
	 * @return {integer} HTTP status code
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
	 * @param {string} $appId
	 * @param {string} $xid
	 * @param {array}  $data the interactiveData "data" dictionary
	 * @param {array}  [$received] the receivedMessage dictionary
	 * @param {array}  [$reply] the replyMessage dictionary
	 * @param {array}  [$extraHeaders] e.g. array('message-type: notification')
	 * @return {integer} HTTP status code
	 */
	static function sendInteractive($appId, $xid, array $data, array $received = array(), array $reply = array(), array $extraHeaders = array())
	{
		$interactiveData = array('bid' => Users_Amb::BID, 'data' => $data);
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
	 * @param {string} $appId
	 * @param {string} $xid  becomes Destination-Id and body.destinationId
	 * @param {array}  $body the message dictionary; id/sourceId/destinationId are filled in
	 * @param {array}  [$extraHeaders]
	 * @return {integer} HTTP status code
	 * @throws {Q_Exception} on transport error or non-2xx response
	 */
	static function api($appId, $xid, array $body, array $extraHeaders = array())
	{
		list($appId, $info) = Users_Amb::appInfo($appId);
		$businessId = $info['businessId'];
		$id = Users_Amb::uuid();

		// Outbound: business is the source, customer is the destination.
		$body = array_merge(array(
			'id' => $id,
			'sourceId' => $businessId,
			'destinationId' => $xid
		), $body);

		$headers = array_merge(array(
			'Authorization: ' . Users_Amb::authorizationHeader($info),
			'id: ' . $id,
			'Source-Id: ' . $businessId,
			'Destination-Id: ' . $xid,
			'Content-Type: application/json',
			'Accept: application/json'
		), $extraHeaders);

		$ch = curl_init(Users_Amb::endpoint($info));
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
			throw new Q_Exception("Users_Amb_Client: transport error: $error");
		}
		if ($code < 200 || $code >= 300) {
			// 404 => customer unreachable via AMB; caller should fall back to the
			// next channel. 429 => rate limited. Other 4xx => permanent reject.
			throw new Q_Exception("Users_Amb_Client: HTTP $code " . substr((string)$response, 0, 200));
		}
		return $code;
	}
}

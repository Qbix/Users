<?php
/**
 * Inbound webhook for Apple Messages for Business.
 * Apple POSTs here (your MSP base URL + /message) when a customer messages the
 * business. This is a STUB: the subscribe/unsubscribe branches depend on your
 * topic model, so wire them to your Streams subscriptions.
 *
 * Route this so Apple's "{MSP base URL}/message" reaches it, e.g. add a route
 * in Users/config/plugin.json mapping "/message" to "Users/amb message".
 *
 * @module Users
 */
function Users_amb_message_post()
{
	// 1. Verify the JWT in the Authorization header (aud must be our MSP ID).
	$auth = Q::ifset($_SERVER, 'HTTP_AUTHORIZATION', '');
	$appId = Q::app();
	if (!Users_Amb::verifyAuthorization($appId, $auth)) {
		return Q_Dispatcher::result('unauthorized'); // respond 401 upstream
	}

	// 2. Parse the body. On inbound, the customer's opaque id is sourceId.
	$raw = file_get_contents('php://input');
	if (Q::ifset($_SERVER, 'HTTP_CONTENT_ENCODING', '') === 'gzip') {
		$raw = gzdecode($raw);
	}
	$body = Q::json_decode($raw, true);
	$xid  = Q::ifset($body, 'sourceId', null);      // customer opaque id -> xid
	$text = trim((string)Q::ifset($body, 'body', ''));

	if (!$xid) {
		return;
	}

	// 3. Branch on the message text.
	if (preg_match('/^(stop|unsubscribe|cancel)$/i', $text)) {
		// TODO: unsubscribe this identity from its topic stream(s),
		//       and mark the Users_ExternalFrom row opted-out.
		Users_amb_message_unsubscribe($appId, $xid, $body);
	} else {
		// TODO: resolve any opaque ctx token from the entry point to a topic,
		//       ensure the identity + subscription exist.
		Users_amb_message_subscribe($appId, $xid, $body);
	}
}

/**
 * Ensure a Users_ExternalFrom(amb) row exists for this conversation, and
 * subscribe the user to the relevant topic stream. Adapt to your topic model.
 */
function Users_amb_message_subscribe($appId, $xid, $body)
{
	$platform = 'amb';
	list($appId, $info) = Users_Amb::appInfo($appId);

	$ef = Users_ExternalFrom::select()
		->where(compact('platform', 'appId', 'xid'))
		->fetchDbRow();

	if (!$ef) {
		// Create a user for the opaque id (no profile data to import), then link.
		$status = null; $inserted = null;
		$user = Users_Amb::futureUser($appId, $xid, $status, $inserted);
		$ef = new Users_ExternalFrom(array(
			'platform' => $platform,
			'appId'    => $appId,
			'xid'      => $xid,
			'userId'   => $user->id
		));
		$ef->save(true); // auto-mirrors to Users_ExternalTo
	}

	// TODO: subscribe $ef->userId to the topic stream resolved from the
	// entry point's opaque ctx token, e.g.:
	// $stream = Streams_Stream::fetch($ef->userId, $publisherId, $topicName);
	// $stream->subscribe(array('userId' => $ef->userId));
}

/**
 * Stop delivering to this identity. Adapt to your topic model.
 */
function Users_amb_message_unsubscribe($appId, $xid, $body)
{
	// TODO: unsubscribe $userId from the topic stream(s) and/or remove the row.
}

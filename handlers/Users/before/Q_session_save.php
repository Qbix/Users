<?php

function Users_before_Q_session_save($params)
{
	if (empty($params['row'])) {
		return;
	}
	$row = $params['row'];
	$sessionId = Q::ifset($row, 'id', null);
	$user = Users::loggedInUser(false, false);
	$userId = $user ? $user->id : null;
	if (Q::ifset($row, 'userId', null) !== $userId) {
		$row->userId = $userId;
	}
	$row->content = isset($_SESSION)
		? Q::json_encode((object)$_SESSION)
		: "{}";
	if (Users::$cache['session'][$sessionId]['deviceId']) {
		$row->deviceId = Users::$cache['session'][$sessionId]['deviceId'];
	}
	if (!$row->wasRetrieved()) {
		$row->deviceId = "";
		$row->timeout = 0;
	}
}
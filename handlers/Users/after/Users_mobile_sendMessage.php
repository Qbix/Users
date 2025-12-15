<?php

function Users_after_Users_mobile_sendMessage($params)
{
	$transport = $params['transport'];
	$logMessage = Q::interpolate("Sent message to {{number}}:\n{{body}}\n", $params);
	if (!$transport) {
		$logMessage = "Would have $logMessage";
	}
	Q::log($logMessage, Q_Config::get('Users', 'mobile', 'logKey', 'mobile'));
}
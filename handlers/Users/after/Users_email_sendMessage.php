<?php

function Users_after_Users_email_sendMessage($params)
{
	$transport = $params['transport'];
	$logMessage = Q::interpolate("Sent message to {{emailAddress}}:\n{{subject}}\n{{body}}\n", $params);
	if (!$transport) {
		$logMessage = "Would have $logMessage";
	}
	Q::log($logMessage, Q_Config::get('Users', 'email', 'logKey', 'email'), array(
		"maxLength" => 2048
	));
}
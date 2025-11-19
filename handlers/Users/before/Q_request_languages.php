<?php

function Users_before_Q_request_languages($params, &$result)
{
	if (!Q_Config::get('Users', 'login', 'setLanguage', false)) {
		return;
	}
	$user = Users::loggedInUser(false, false);
	if ($user && $user->preferredLanguage) {
		if (!is_array($result)) {
			$result = array();
		}
		$newEntry = array($user->preferredLanguage, null, 1);
		foreach ($result as $i => $entry) {
			if ($entry[0] === $user->preferredLanguage) {
				$newEntry = $entry;
				array_splice($result, $i, 1);
				break;
			}
		}
		array_unshift($result, $newEntry);
	}
}
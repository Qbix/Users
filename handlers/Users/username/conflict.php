<?php

function Users_username_conflict($params, &$username)
{
	if (!Q_Config::get('Users', 'username', 'unique', false)) {
		$identify = $params['identify'];
		$identify->userId = '';
		return; // conflict is fine, but don't resolve identifier to a specific user anymore
	}
	Q::event('Users/username/conflict/exception', $params, 'before');
	throw new Users_Exception_UsernameExists(array_merge(
		$params,
		array('username' => Q::ifset($params, 'originalUsername', $username))
	));
}

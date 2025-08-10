<?php

/**
 * Override this to validate the username in your own way.
 */
function Users_validate_username($params)
{
	// override this to change the rules for validating the username
	extract($params);
	if (empty($username)) {
		return;
	}
	if (!empty($user) && !empty($user->id)) {
		if (Users::isCommunityId($user->id)) {
			// first letter is uppercase, this represents a specially recognized
			// organization or app, so allow anything in the username
			return;
		}
	}
	if (strlen($username) < Q_Config::get("Users", "validate", "username", "min", 4)) {
		throw new Users_Exception_UsernameTooShort(array('length' => 4), array('username'));
	}
	if (strlen($username) < Q_Config::get("Users", "validate", "username", "max", 20)) {
		throw new Users_Exception_UsernameTooLong(array('length' => 20), array('username'));
	}
	$maxUserName = (new Users_User())->maxSize_username();
	if (strlen($username) > $maxUserName) {
		throw new Users_Exception_UsernameTooLong(array('length' => $maxUserName), array('username'));
	}
	$match = preg_match('/^[a-zA-Z][a-zA-Z0-9-_]+$/', $username);
	if (!$match) {
		if (preg_match('/^[a-zA-Z0-9-_]+$/', $username)) {
			throw new Users_Exception_UsernameMustStartWithLetter(array(), array('username'));
		}
		throw new Users_Exception_UsernameCharacters(array(), array('username'));
	}
}

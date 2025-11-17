<?php

/**
 * @module Users
 */

/**
 * Used by HTTP clients to fetch languages list
 * @class HTTP Users language
 * @method GET/list
 * @param {array} [$params] Parameters that can come from the request
 * @return {array} An array of languages.
 */
function Users_language_response_list($params = array())
{
	$user = Users::loggedInUser();
	$result = array();

	$result['list'] = array_keys(Q_Config::expect('Q', 'web', 'languages'));
	sort($result['list']);

	// default language
	$result['currentLanguage'] = Q_Text::$language;

	// if user is logged in, select preferred language for this user
	if ($user) {
		$result['currentLanguage'] = Users::getLanguage($user->id);
	}

	return Q_Response::setSlot('list', $result);
}
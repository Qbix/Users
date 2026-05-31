<?php

/**
 * @module Users
 */

/**
 * Used by HTTP clients to fetch one more more labels
 * @class HTTP Users label
 * @method GET/labels
 * @param {array} [$params] Parameters that can come from the request
 *   @param {string|array} [$params.userIds] The users whose labels to fetch. Can be a comma-separated string
 * @return {array} An array of Users_Label objects.
 */
function Users_label_response_can($params = array())
{
    $req = array_merge($_REQUEST, $params);
	if (!isset($req['userId']) and !isset($req['userIds'])) {
		throw new Q_Exception_RequiredField(array(
			'field' => 'userId'
		), 'userId');
	}
	$userIds = isset($req['userIds']) ? $req['userIds'] : array($req['userId']);
	if (is_string($userIds)) {
		$userIds = explode(",", $userIds);
	}
    $can = array();
    $userId = Users::loggedInUserId();
    foreach ($userIds as $communityId) {
        $can[$communityId] = Users_Label::can($communityId, $userId);
    }
    return $can;
}
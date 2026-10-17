<?php
/**
 * Which labels holders of a label may grant and revoke in a community
 * @param {array} $_REQUEST
 * @param {string} $_REQUEST.userId The userId(CommunityId)
 * @param {string} $_REQUEST.label The label
 * @throws {Users_Exception_NotAuthorized} unless the logged-in user may
 *   view the community's labels (Users::canManageLabels with $readOnly)
 */
function Users_permissions_response_result($params = array())
{
	$user = Users::loggedInUser(true);
	$req = array_merge($_REQUEST, $params);
	// Empty means missing: an empty userId would make Users::roles() judge
	// the current community, and an empty label passes canManageLabels()
	// as "some label".
	Q_Valid::requireFields(array('userId', 'label'), $req, true, true);
	if (!is_string($req['userId']) or !is_string($req['label'])) {
		throw new Q_Exception_WrongType(array(
			'field' => 'userId and label',
			'type' => 'string'
		));
	}
	Users::canManageLabels($user->id, $req['userId'], $req['label'], true, true);

	$ret = array();

	$tmp = Users_Label::canManage($req['userId'], $req['label']);
	$ret['labels'] = $tmp['labels'];
	$ret['locked'] = $tmp['locked'];

	return Q_Response::setSlot('result', $ret);
}

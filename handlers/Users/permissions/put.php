<?php

/**
 * dynamically edit permissions
 * @param {array} $_REQUEST
 * @param {string} $_REQUEST.userId The userId(CommunityId)
 * @param {string} $_REQUEST.label The label
 * @param {array} [$_REQUEST.toGrant] Labels that holders of $label may now grant and revoke
 * @param {array} [$_REQUEST.toRevoke] Labels that holders of $label may no longer grant or revoke
 * @throws {Users_Exception_NotAuthorized} unless the logged-in user can manage
 *   $label and every label in toGrant and toRevoke in that community
 *   (Users::canManageLabels, which by default means its Users/owners)
 */
function Users_permissions_put($params = array())
{
	$user = Users::loggedInUser(true);
	$req = array_merge($_REQUEST, $params);
	Q_Valid::requireFields(array('userId', 'label'), $req, true);
	$communityId = $req['userId'];
	$label = $req['label'];
	if (!is_string($communityId) or !is_string($label)
	or $communityId === '' or $label === '') {
		throw new Q_Exception_WrongType(array(
			'field' => 'userId and label',
			'type' => 'non-empty string'
		));
	}
	foreach (array('toGrant', 'toRevoke') as $field) {
		$req[$field] = empty($req[$field]) ? array() : $req[$field];
		if (!is_array($req[$field])) {
			$req[$field] = array($req[$field]);
		}
		foreach ($req[$field] as $l) {
			// An empty label would pass canManageLabels() as "some label".
			if (!is_string($l) or $l === '') {
				throw new Q_Exception_WrongType(array(
					'field' => $field,
					'type' => 'array of non-empty strings'
				), $field);
			}
		}
	}

	// Authorize before the quota check, which begins a transaction.
	// Changing what holders of $label may grant is managing $label, and
	// handing out a label is managing that label too.
	$labels = array_unique(array_merge(array($label), $req['toGrant'], $req['toRevoke']));
	foreach ($labels as $l) {
		Users::canManageLabels($user->id, $communityId, $l, true);
	}

	$privileges = array_keys(Users::roles($communityId, null, array(), $user->id));
	$quota = Users_Quota::check($user->id, '', 'Users/permissions', true, 1, $privileges);

	$perm = new Users_Permission();
	$perm->userId = $communityId;
	$perm->label = $label;
	$perm->permission = 'Users/communities/roles';
	$perm->retrieve();

	$extras = $perm->getAllExtras();

	if (empty($extras['canGrant'])) {
		$extras['canGrant'] = array();
	} else {
		$perm->clearExtra('canGrant');
	}

	if (empty($extras['canRevoke'])) {
		$extras['canRevoke'] = array();
	} else {
		$perm->clearExtra('canRevoke');
	}

	$extras['canGrant'] = array_values(array_diff($extras['canGrant'], $req['toRevoke']));
	$extras['canRevoke'] = array_values(array_diff($extras['canRevoke'], $req['toRevoke']));

	foreach($req['toGrant'] as $grantKey){
		if (!in_array($grantKey, $extras['canGrant'])){
			array_push($extras['canGrant'], $grantKey);
		}
		if (!in_array($grantKey, $extras['canRevoke'])){
			array_push($extras['canRevoke'], $grantKey);
		}
	}

	// update extras
	$perm->setExtra(array(
		'canGrant' => $extras['canGrant'],
		'canRevoke' => $extras['canRevoke']
	));
	$perm->save();

	$quota->used(1);

	Q_Response::setSlot('result', true);
}

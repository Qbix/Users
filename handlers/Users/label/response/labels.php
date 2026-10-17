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
 *   @param {string|array} [$params.filter] Optionally filter by specific labels, or label prefixes ending in "/". Can be a comma-separated string
 * @return {array} An array of Users_Label objects.
 */
function Users_label_response_labels($params = array())
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
	$filter = null;
	if (isset($req['filter'])) {
		$filter = $req['filter'];
	} else if (isset($req['label'])) {
		$filter = array($req['label']);
	}
	$rows = array();
	if (isset($req['batch'])) {
		// Batch format: $userIds and $filter are PARALLEL arrays, one entry per
		// sub-request, and the caller (Users_label_response_batch) wraps each
		// element of what comes back as one sub-response. So the result has to
		// stay index-aligned with the input, which array_merge() cannot be:
		// Users_Label::fetch() returns zero rows for a label the user does not
		// have, and every later sub-response then shifts up one. Emit exactly
		// one entry per index, null for a miss -- the same shape
		// Users_contact_response_contacts() already produces for its own batch
		// branch. (only Users/label's batch slot passes 'batch', so
		// nothing else sees this.)
		foreach ($userIds as $i => $userId) {
			$fetched = Users_Label::fetch($userId, $filter[$i]);
			$rows[] = $fetched ? reset($fetched) : null;
		}
	} else {
		foreach ($userIds as $i => $userId) {
			$rows = array_merge($rows, Users_Label::fetch($userId, $filter));
		}
	}
	return Db::exportArray($rows);
}
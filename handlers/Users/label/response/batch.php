<?php

function Users_label_response_batch($params = array())
{
	$req = array_merge($_REQUEST, $params);
	Q_Valid::requireFields(array('batch'), $req, true);
	$batch = $req['batch'];
	$batch = json_decode($batch, true);
	if (!isset($batch)) {
		throw new Q_Exception_WrongValue(array(
			'field' => 'batch', 
			'range' => '{userIds: [...], labels: [...]}'
		));
	}
	Q_Valid::requireFields(array('userIds', 'labels'), $batch, true);
	$userIds = $batch['userIds'];
	$labels = $batch['labels'];
	if (is_string($labels)) {
		$labels = explode(",", $labels);
	}
	// Dispatches Users/LABEL/response/labels, not Users/CONTACT/response/labels.
	// The latter has never existed anywhere in the tree -- no handler file and
	// no Users_contact_response_labels() declared -- so Q::handle() threw
	// Q_Exception_MissingFile and this slot was dead for every caller. It is a
	// copy of Users_contact_response_batch() that copied the sibling's *plugin
	// path* along with its shape; that one correctly names
	// Users/contact/response/contacts.
	//
	// 'filter' rather than 'labels': Users_label_response_labels() reads the
	// label names it fetches by from `filter` (or a single `label`), and would
	// see filter === null and fatal on $filter[$i] in its batch branch. The
	// contact sibling does not need this because contacts.php happens to read
	// `labels` under that name.
	$filter = $labels;
	$rows = Q::event('Users/label/response/labels', @compact(
		'userIds', 'filter', 'batch'
	));
	$result = array();
	foreach ($rows as $row) {
		$result[] = array('slots' => array('label' => $row));
	}
	Q_Response::setSlot('batch', $result);
}
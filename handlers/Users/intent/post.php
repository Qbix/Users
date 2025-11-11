<?php

/**
 * Handles POST /Users/intent
 * Creates or reuses an intent based on a valid capability
 */
function Users_intent_post()
{
	Q_Request::requireFields(array('capability'), true);
	$capability = $_REQUEST['capability'];

	// Validate capability signature and permission
	if (!Q_Valid::capability($capability, 'Users/intent/provision')) {
		throw new Q_Exception_PermissionDenied();
	}

	// Extract relevant fields from capability data
	$data = Q::ifset($capability, 'data', $capability);
	$fields = Q::take($data, array(
		'token'    => null,
		'action'   => null,
		'platform' => null,
		'appName'  => null
	));

	// Validate required fields
    if ($fields['action'] === 'Users/authenticate') {
        foreach ($fields as $k => $v) {
            if (!isset($v)) {
                throw new Q_Exception_MissingField(array('field' => $k));
            }
        }
    }

	// Attach user ID and timestamp
	$user = Users::loggedInUser();
	$fields['userId'] = $user ? $user->id : null;
	$fields['createdTime'] = time();

	// Idempotent insert: reuse existing intent if same token already exists
	$intent = Users_Intent::select()
		->where(array('token' => $fields['token']))
		->fetchDbRow();

	if (!$intent) {
		$intent = new Users_Intent($fields);
		$intent->save();
	}

	// Return minimal info (AJAX-safe)
	Q_Response::setSlot('intent', $intent->exportArray());
}
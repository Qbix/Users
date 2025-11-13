<?php
/**
 * Used by HTTP clients to create (or reuse) an authentication intent.
 * An "intent" represents a pending authentication or connection flow
 * (e.g. via Telegram, Web3 wallet, or another external identity provider).
 *
 * Once created, the intent can be completed by PUT /Users/intent
 * after verification by the external platform.
 *
 * @module Users
 * @class HTTP Users intent
 * @method post
 * @param {array} [$params] Parameters that can come from the request
 *   @param {string} [$params.capability] Optional. A capability token granting permission to create or reuse the intent.
 *   @param {string} [$params.platform] Optional. Target platform for the authentication (e.g. "telegram", "web3").
 *   @param {string} [$params.redirect] Optional. URL to redirect to after completing the external authentication.
 *   @param {string} [$params.sessionId] Optional. A specific sessionId to attach this intent to (defaults to current session).
 *   @param {string} [$params.userId] Optional. If provided, associates the intent with this user.
 * @throws {Users_Exception_NotAuthorized}
 * @throws {Q_Exception_MissingValue}
 * @return {array} Returns the created intent in the "intent" slot:
 *   {
 *     "token": "...",
 *     "sessionId": "...",
 *     "platform": "...",
 *     "createdTime": "..."
 *   }
 */
function Users_intent_post()
{
	Q_Request::requireFields(array('capability'), true);
	$capability = $_REQUEST['capability'];

	// Validate capability signature and permission
	if (!Q_Valid::capability($capability, 'Users/intent/provision')) {
		throw new Users_Exception_NotAuthorized();
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
<?php
/**
 * Used by HTTP clients to create (or reuse) an authentication intent.
 * An "intent" represents a pending authentication or connection flow
 * (e.g. via Telegram, Web3 wallet, or another external identity provider).
 *
 * @module Users
 * @class HTTP Users intent
 * @method post
 * @param {array} [$params] Parameters that can come from the request
 *   @param {string} [$params.capability] Optional. A capability token granting permission to create or reuse the intent.
 *   @param {string} [$params.platform] Optional. Target platform some the authentication (e.g. "telegram", "web3").
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
	$capability = new Q_Capability($_REQUEST['capability']);

	// Validate capability signature and permission
	if (!Q_Valid::capability($capability, 'Users/intent')) {
		throw new Users_Exception_NotAuthorized();
	}

	// Extract relevant fields from capability data
	$data = Q::ifset($capability, 'data', $capability);
	$fieldNames = array('token', 'action', 'platform', 'appId');
	$fields = Q::take($data, $fieldNames);
	Q::take($_REQUEST, $fieldNames, $fields);

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

	// Idempotent insert: reuse existing intent if same token already exists
	$intent = Users_Intent::select()
		->where(array('token' => $fields['token']))
		->fetchDbRow();

	if (!$intent) {
		$instructions = $fields;
		foreach (Users_Intent::fieldNames() as $f) {
			unset($instructions[$f]);
		}
		$intent = Users_Intent::newIntent(
			$fields['action'], $fields['userId'], $instructions, $fields['token']
		);
	}

	// Return minimal info (AJAX-safe)
	Q_Response::setSlot('intent', $intent->exportArray());
}
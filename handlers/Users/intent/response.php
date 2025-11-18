<?php

/**
 * Used to generate an intent and redirect to external platform app
 *
 * @module Users
 * @class HTTP Users intent
 * @method get
 * @param {array} $_REQUEST 
 * @param {string} $_REQUEST.capability May contain the required action and platform, otherwise you must specify them.
 * @param {string} [$_REQUEST.action] Override desired action, e.g. 'Users/authenticate'
 * @param {string} [$_REQUEST.platform] Override desired action. The external platform to redirect to afterwards
 * @param {string} [$_REQUEST.appId] Optional. The external platform appId used to load info
  *   with fields to interpolate into the redirection URL
 * @param {string} [$_REQUEST.field="redirect"] Optional. Names a diffent config field under
 *    the config Users/intents/actions/$action/$platform, default is "redirect"
 * @param {array} [$_REQUEST.instructions] Any additional instructions for the action, 
 *   if listed under Users/intents/actions/$action/instructions config
 * @param {array} [$_REQUEST.interpolate] Any additional fields to interpolate into the pattern
 *   found in config under Users/intents/actions/$action/$platform/$field
 * @return {void}
 */
function Users_intent_response()
{
    if (Q_Request::isAjax()) {
        // just get a new token and capability
        Q_Request::requireOrigin(true);
        $slotNames = Q_Request::slotNames();
        if (in_array('token', $slotNames)) {
            $capability = Users_Intent::capability();
            Q_Response::setSlot('token', $capability->data['token']);
            Q_Response::setSlot('capability', (string)$capability);
        }
        return true;
    }

    // normal non-AJAX request, create new intent and redirect
    $intent = null;
    if (isset($_REQUEST['capability'])) {
        $capability = new Q_Capability($_REQUEST['capability']);
        if (isset($capability->data['token'])) {
            $intent = (new Users_Intent(array('token' => $capability->data['token'])))->retrieve();
            if ($intent) {
                if (!empty($intent->action)) {
                    $action = $intent->action;
                }
                if ($intent->getInstruction('platform')) {
                    $platform = $intent->getInstruction('platform');
                }
            }
        }
    }
    if (!isset($action)) {
        Q_Request::requireFields(array('action'), true);
        $action = $_REQUEST['action'];
    }
    if (!isset($platform)) {
        Q_Request::requireFields(array('platform'), true);
        $platform = $_REQUEST['platform'];
    }
    $sessionId = Q_Session::requestedId();
    if (!$sessionId) {
        // no session ID, redirect back if we can
        if ($refererURL = Q::ifset($_SERVER, 'HTTP_REFERER', null)) {
            Q_Response::redirect($refererURL);
        } else {
            echo "No active session";
        }
        return false;
    }
    $appId = Q::ifset($_REQUEST, 'appId', Q::app());
    $field = Q::ifset($_REQUEST, 'field', 'redirect');
    list($appId, $info) = Users::appInfo($platform, $appId);
    $interpolate = Q::ifset($_REQUEST, 'interpolate', array());
    if (!$intent) {
        $user = Users::loggedInUser();
        $userId = $user ? $user->id : null;
        $intent = Users_Intent::newIntent($action, $userId, array(
            'platform' => $platform,
            'appId' => $appId
        ));
    }
    Q_Session::start();
	$intents = Q::ifset($_SESSION, 'Users', 'intents', array());
    $intents[] = $intent->token;
    $_SESSION['Users']['intents'] = $intents;
    $params = array_merge(
        $info, 
        $interpolate,  
        $intent->getAllInstructions(),
        array('token' => $intent->token)
    );
    $pattern = Q_Config::expect('Users', 'intents', 'actions', $action, $platform, $field);
    $url = Q::interpolate($pattern, $params);
    Q_Response::redirect($url);
    return false; // to prevent XSS, don't return anything to JS, simply redirect the user
}
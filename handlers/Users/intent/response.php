<?php

/**
 * Used to generate an intent and redirect to external platform app
 *
 * @module Users
 * @class HTTP Users intent
 * @method get
 * @param {array} $_REQUEST 
 * @param {string} $_REQUEST.action Required. The desired action, e.g. 'Users/authenticate'
 * @param {string} $_REQUEST.platform Required. The external platform to redirect to afterwards
 * @param {string} [$_REQUEST.appId] Optional. The external platform appId used to load info
  *   with fields to interpolate into the redirection URL
 * @param {string} [$_REQUEST.redirect="redirect"] Optional. Names a diffent config field under
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
        Q_Request::requireOrigin(true);
        $slotNames = Q_Request::slotNames();
        if (in_array('token', $slotNames)) {
            $capability = Users_Intent::capability();
            Q_Request::setSlot('token', $capability->data['token']);
            Q_Request::setSlot('capability', $capability->exportArray());
        }
        return true;
    }
    Q_Request::requireFields(array('action', 'platform'), true);
    $action = $_REQUEST['action'];
    $platform = $_REQUEST['platform'];
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
    $intent = Users_Intent::newIntent($action);
    $params = array_merge($info, $interpolate, array('token' => $intent->token));
    $pattern = Q_Config::expect('Users', 'intents', 'actions', $action, $platform, $field);
    $url = Q::interpolate($pattern, $params);
    Q_Response::redirect($url);
    return false; // to prevent XSS, don't return anything to JS, simply redirect the user
}
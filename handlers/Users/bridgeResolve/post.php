<?php

/**
 * Resolves a Users/bridge conflict (Case 6 of the bridge decision matrix).
 * Called by the client when the user chooses to keep their current first-party
 * identity or switch to the bridge identity.
 *
 * @module Users
 * @class HTTP Users bridgeResolve
 * @method post
 * @param {string} $_REQUEST.token The bridge intent token to resolve
 * @param {string} $_REQUEST.choice Either 'current' or 'bridge'
 * @return {void} Sets the 'resolved' slot to true on success.
 */
function Users_bridgeResolve_post()
{
    Q_Valid::requireOrigin(true);
    Q_Request::requireFields(array('token', 'choice'), true);

    $token = $_REQUEST['token'];
    $choice = $_REQUEST['choice'];
    if (!in_array($choice, array('current', 'bridge'))) {
        throw new Q_Exception_BadValue(array(
            'internal' => 'choice', 'problem' => 'must be current or bridge'
        ));
    }

    $intent = Users_Intent::fetch($token);
    if (!$intent
        || !$intent->isValid()
        || !empty($intent->completedTime)
        || $intent->action !== 'Users/bridge') {
        throw new Q_Exception_BadValue(array(
            'internal' => 'token', 'problem' => 'invalid bridge token'
        ));
    }

    if ($choice === 'bridge') {
        $intent->accept(array('copySessionFields' => array('Users')));
        $intent->complete(array('mode' => 'manual_chose_bridge'));
    } else {
        $currentUser = Users::loggedInUser(true);
        if ($currentUser && $intent->sessionId) {
            Users_User::pushIntoSession($intent->sessionId, $currentUser->id);
        }
        $intent->complete(array('mode' => 'manual_chose_current'));
    }

    Q_Response::setSlot('resolved', true);
}
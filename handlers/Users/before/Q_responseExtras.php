<?php

/**
 * Hook on Q/responseExtras to redeem a Users/bridge intent token from
 * ?Q.Users.intent=TOKEN on incoming first-party requests.
 *
 * The bridge intent is minted by an iframe via Users.Intent.mint('Users/bridge', ...),
 * handed to its parent window via Q.Users.Embed.call (postMessage RPC), and appended
 * to outbound links to the Qbix origin by Embed.js. When the user clicks and
 * the browser navigates top-level, this hook resumes the iframe's session.
 *
 * Same user agent, same person — the goal is one session row in Users_Session
 * used from both contexts. We resume the iframe's sessionId rather than just
 * stamping the user into a fresh session, so any session state accumulated in
 * the iframe carries forward to first-party.
 *
 * Decision matrix:
 *   - Unknown/expired/completed/wrong-action token: ignore silently
 *   - No first-party session: resume iframe's sessionId
 *   - Same user on both sides: accept for fresher state
 *   - Bridge anonymous, current logged in: push current into bridge session
 *     (so iframe matches first-party on its next request)
 *   - Current is verified richer (sessionCount > 0), bridge isn't: push current
 *     into bridge session, keep current first-party
 *   - Bridge is verified richer, current isn't: accept bridge into first-party
 *   - Both verified, distinct: surface conflict to client UI
 *   - Neither verified, distinct: accept bridge (recent action wins)
 *
 * @module Users
 * @class Users_before_Q_responseExtras
 */
function Users_before_Q_responseExtras()
{
    $token = Q_Request::special('Users.intent');
    if (!$token) {
        return;
    }

    $intent = Users_Intent::fetch($token);
    if (!$intent
        || !$intent->isValid()
        || !empty($intent->completedTime)
        || $intent->action !== 'Users/bridge') {
        return;
    }

    $bridgeUserId = $intent->userId;
    $bridgeSessionId = $intent->sessionId;
    $currentUser = Users::loggedInUser(false, false);
    $currentUserId = $currentUser ? $currentUser->id : null;

    // Case 1: no current first-party session — resume iframe's session
    if (!$currentUserId) {
        if ($bridgeSessionId) {
            Q_Session::id($bridgeSessionId);
            Q_Session::start();
        }
        if ($intent->accept(array('copySessionFields' => array('Users')))) {
            $intent->complete(array('mode' => 'resume_session'));
        }
        return;
    }

    // Case 2: same user on both sides — accept for fresher state
    if ($bridgeUserId === $currentUserId) {
        $intent->accept(array('copySessionFields' => array('Users')));
        $intent->complete(array('mode' => 'same_user'));
        return;
    }

    // Different users. Look up verification status via sessionCount.
    $bridgeUser = $bridgeUserId ? Users_User::fetch($bridgeUserId, false) : null;
    $currentVerified = $currentUser
        && intval(Q::ifset($currentUser, 'sessionCount', 0)) > 0;
    $bridgeVerified = $bridgeUser
        && intval(Q::ifset($bridgeUser, 'sessionCount', 0)) > 0;

    // Case 3: bridge anonymous, current logged in — push current to iframe
    if (!$bridgeUserId) {
        Users_User::pushIntoSession($bridgeSessionId, $currentUserId);
        $intent->complete(array('mode' => 'first_party_into_iframe_anon'));
        return;
    }

    // Case 4: current verified richer, bridge isn't — first-party wins
    if ($currentVerified && !$bridgeVerified) {
        Users_User::pushIntoSession($bridgeSessionId, $currentUserId);
        $intent->complete(array('mode' => 'first_party_richer'));
        return;
    }

    // Case 5: bridge verified richer, current isn't — bridge wins
    if ($bridgeVerified && !$currentVerified) {
        $intent->accept(array('copySessionFields' => array('Users')));
        $intent->complete(array('mode' => 'bridge_richer'));
        return;
    }

    // Case 6: both verified, distinct — surface to client UI
    if ($currentVerified && $bridgeVerified) {
        Q_Response::setScriptData('Q.plugins.Users.bridgeConflict', array(
            'token' => $token,
            'bridgeUserId' => $bridgeUserId,
            'currentUserId' => $currentUserId
        ));
        return;
    }

    // Case 7: neither verified, distinct — bridge wins (recent action)
    $intent->accept(array('copySessionFields' => array('Users')));
    $intent->complete(array('mode' => 'both_anon_bridge_wins'));
}
<?php

/**
 * Creates (or links) a Discourse forum account for a user and saves the
 * Users_ExternalTo row. The server sends that user's display name, username
 * and email address to "$baseUrl/users", so the caller must be allowed to
 * act for that user:
 *
 * - over HTTP a user must be logged in;
 * - they may do it for themselves, or for another user only if they hold one
 *   of the roles in Users/discourse/requireAuthorizedRole in the current
 *   community. With no roles configured nobody may do it for another user.
 * - when Users/discourse/requireAuthorizedRole is configured, it gates the
 *   endpoint for everyone, as before.
 *
 * Code in the same process that has already decided who may act (a CLI
 * import, say) passes "skipAccess" => true in $params. $params is empty for
 * an HTTP request (Q/post dispatches with none), and skipAccess is never read
 * from $_REQUEST.
 *
 * @param {array} $params
 * @param {string} $params.userId
 * @param {string} $params.baseUrl
 * @param {string} [$params.apiKey]
 * @param {boolean} [$params.skipAccess=false]
 */
function Users_discourse_post($params)
{
    $r = array_merge($_REQUEST, $params);
    Q_Valid::requireFields(array('userId', 'baseUrl'), $r, true);
    $userId = $r['userId'];
    $baseUrl = $r['baseUrl'];
    if (empty($params['skipAccess'])) {
        $user = Users::loggedInUser(true);
        $authorized = Q_Config::get('Users', 'discourse', 'requireAuthorizedRole', false);
        $hasRole = $authorized && Users::roles(null, $authorized, array(), $user->id);
        if ($authorized && !$hasRole) {
            throw new Users_Exception_NotAuthorized();
        }
        if ($userId !== $user->id && !$hasRole) {
            // Someone else's name and email would go to a URL the caller chose.
            throw new Users_Exception_NotAuthorized();
        }
    }
    if (isset($r['apiKey']))  {
        $apiKey = $r['apiKey'];
    }
    $uxt = new Users_ExternalTo_Discourse(array(
        'userId' => $userId,
        'platform' => 'discourse',
        'appId' => $baseUrl
    ));
    $uxt->setExtra(@compact('baseUrl', 'apiKey'));
    $ret = $uxt->create();

    // Q_Request::requireFields(array(
    //     array('user', 'name'),
    //     array('user', 'email'),
    //     array('user', 'password'),
    //     array('user', 'userId')
    // ), true);
    // $user = $_REQUEST['user'];
    // Users_ExternalTo_Discourse::createForumUser(
    //     $user['name'], 
    //     $user['email'],
    //     $user['password'],
    //     $user['userId']
    // );
    Q_Response::setSlot('data', array());
}
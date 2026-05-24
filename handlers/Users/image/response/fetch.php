<?php

function Users_image_response_fetch()
{
    // 1. Must be logged in
    $user = Users::loggedInUser(true); // throws Users_Exception_NotLoggedIn

    // 2. Rate limit — gallery queries per minute per user
    $quota = Users_Quota::check(
        $user->id,
        '',               // global (not stream-scoped)
        'Q/image/fetch',
        true,             // throw Users_Exception_Quota if exceeded
        1,
        Users::roles($user->id, Users::communityId())
    );

    $provider = Q::ifset($_REQUEST, 'provider', 'pexels');
    $query    = Q::ifset($_REQUEST, 'q', '');
    $options  = Q::ifset($_REQUEST, 'options', array());
    if (is_string($options)) {
        $options = Q::json_decode($options, true) ?: array();
    }
    if (!in_array($provider, array('pexels', 'pixabay'))) {
        throw new Q_Exception_WrongValue(array(
            'field' => 'provider',
            'range' => 'pexels or pixabay',
            'value' => $provider
        ));
    }

    $raw    = call_user_func(array('Q_Image', $provider), $query, $options);
    $result = Q_Image::normalize($raw, $provider);

    // 3. Commit quota usage only after successful API call
    $quota->used();

    return $result;
}
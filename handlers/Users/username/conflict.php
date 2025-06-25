<?php

function Users_username_conflict($params, $username)
{
    if (!Q_Config::get('Users', 'username', 'unique', true)) {
        $identify = $params['identify'];
        $identify->userId = '';
        return; // conflict is fine, but don't resolve identifier to a specific user anymore
    }
    if (Q_Config::get('Users', 'username', 'onConflict', 'increment', false)
    and $params['attemptsRemaining'] > 0) {
        $username = $params['originalUsername'] . ($params['attemptIndex'] + 2);
    } else if (Q_Config::get('Users', 'username', 'onConflict', 'null', false)) {
        $username = null;
    } else {
        Q::event('Users/username/conflict/exception', $params, 'before');
        throw new Users_Exception_UsernameExists(array_merge(
            $params, compact('username')
        ));
    }
}
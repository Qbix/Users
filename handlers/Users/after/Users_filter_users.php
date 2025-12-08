<?php

function Users_after_Users_filter_users($params, &$result)
{
    // hide by prefixes
    $hiddenUserIds = array();
    $prefixes = Q_Config::get('Users', 'filter', 'byIdPrefixes', array());
    foreach ($prefixes as $p) {
        foreach ($result as $userId) {
            if (Q::startsWith($userId, $p)) {
                $hiddenUserIds[] = $userId;
            }
        }
    }
    if ($hiddenUserIds) {
        $result = array_values(array_diff($result, $hiddenUserIds));
    }

    // hide by Users/hidden role
    $hiddenRole = 'Users/hidden';
    $communityId = Users::currentCommunityId(true);
    $roles = Users::roles($communityId);
    $config = Q_Config::get('Users', 'roles', array());
    foreach ($roles as $role) {
        if (Users_Label::canSeeLabel($role->label, $hiddenRole, $config)) {
            return;
        }
    }
    $userIds = $result;
    $settings = Users_Field::select(array('userId', 'content'))->where(array(
        'userId' => $userIds,
        'name' => 'Users/hidden'
    ))->fetchAll(PDO::FETCH_ASSOC);
    $hiddenUserIds = array();
    foreach ($settings as $setting) {
        if (empty($setting['content'])) {
            return;
        }
        $content = Q::json_decode($setting['content'], true);
        if (in_array($communityId, $content)) {
            $hiddenUserIds[] = $setting['userId'];
        }
    }
    if ($hiddenUserIds) {
        $result = array_values(array_diff($userIds, $hiddenUserIds));
    }
}
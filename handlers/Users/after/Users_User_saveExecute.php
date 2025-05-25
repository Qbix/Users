<?php

function Users_after_Users_User_saveExecute($params)
{
    try {
        // If the username or icon was somehow modified,
        // update all the discourse avatars, if any
        $modifiedFields = $params['modifiedFields'];
        $user = $params['row'];
        $updates = array();
        if (isset($modifiedFields['username'])) {
            $updates['username'] = $modifiedFields['username'];
        }
        if (isset($modifiedFields['icon'])) {
            $updates['icon'] = $modifiedFields['icon'];
        }
        if (empty($updates)) {
            return;
        }

        $externalTos = Users_ExternalTo_Discourse::select()->where(array(
            'userId' => $user->id,
            'platform' => 'discourse'
        ));
        foreach ($externalTos as $externalTo) {
            if (isset($updates['icon'])) {
                $externalTo->updateAvatar();
            }
            if (isset($updates['username'])) {
                $newUsername = $updates['username'];
                $externalTo->updateUsername($newUsername);
            }
        }    
    } catch (Exception $e) {
        Q::log("Users/after/Users_User_saveExecute error for user {$user->id}: " . $e->getMessage());
    }
}
<?php

function Users_before_Metrics_Visit_id($params, $result)
{
    if ($user = Users::loggedInUser(false, false)) {
        $result = $user->id . ";" . $result;
    }
}
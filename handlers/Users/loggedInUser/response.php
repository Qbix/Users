<?php

function Users_loggedInUser_response()
{
    $user = Users::loggedInUser(false, true);
    Q_Response::setSlot('user', $user);
}
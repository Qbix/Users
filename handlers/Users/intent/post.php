<?php

function Users_intent_post()
{
    $fields = Q::take($_REQUEST, array('action', 'instructions', 'userId', 'startTime', 'endTime'));
    $action = Q::ifset($fields, 'action', null);
    $instructions = Q::ifset($fields, 'instructions', array());
    $intent = Users_Intent::newIntent($action, $instructions);
    Q_Response::setSlot('token', Q::ifset($intent, 'token', null));
}
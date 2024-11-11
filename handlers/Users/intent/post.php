<?php

function Users_intent_post()
{
    $fields = Q::take($_REQUEST, array('action', 'instructions', 'userId', 'startTime', 'endTime'));
    $action = Q::ifset($fields, 'action', null);
    $info = Q_Config::get('Users', 'intents', 'actions', $action, false);
    if (!$info) {
        throw new Users_Exception_NotAuthorized();
    }
    $instructions = Q::ifset($fields, 'instructions', array());
    if (!empty($instructions) && !Q::isAssociative($instructions)) {
        throw new Q_Exception_WrongType(array(
            'field' => 'instructions',
            'type' => 'associative array'
        ));
    }
    foreach ($instructions as $k => $v) {
        if (!isset($info['instructions'][$k])) {
            throw new Q_Exception_MissingConfig(array('fieldpath' => "Users/intents/actions/\"$action\"/instructions/$k"));
        }
    }
    $sessionId = Q_Session::requestedId();
    $seconds = 10; // no more than one per second
    $intents = Users_Intent::select()
        ->where(array(
            'sessionId' => $sessionId,
            'action' => $action,
            'insertedTime >' => new Db_Expression("CURRENT_TIMESTAMP - INTERVAL $seconds SECOND")
        ))->fetchDbRows();
    if (count($intents)) {
        $intent = reset($intents);
    } else {
        // delete previous intents with this sessionId, to save space
        Users_Intent::delete()->where(array(
            'sessionId' => $sessionId
        ))->execute();
        $seconds = 100;
        // delete 10 outdated intents
        Users_Intent::delete()->where(array(
            'insertedTime <' => new Db_Expression("CURRENT_TIMESTAMP - INTERVAL $seconds SECOND")
        ))->limit(10)->execute();
        // insert this new intent
        $intent = new Users_Intent($fields);
        $intent->startTime = new Db_Expression('CURRENT_TIMESTAMP');
        if ($seconds = Q::ifset($info, 'duration', 0)) {
            $intent->endTime = new Db_Expression("CURRENT_TIMESTAMP + INTERVAL $seconds SECOND");
        }
        $intent->save();
    }
    Q_Response::setSlot('token', Q::ifset($intent, 'token', null));
}
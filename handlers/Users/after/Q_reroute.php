<?php

function Users_after_Q_reroute($params, &$stop_dispatch)
{
	$uri = Q_Dispatcher::uri();
	$app = Q::app();
	$ma = $uri->module.'/'.$uri->action;
	$requireLogin = Q_Config::get('Users', 'requireLogin', array());
	if (!isset($requireLogin[$ma])) {
		return; // We don't have to require login here
	}
	if (!$requireLogin[$ma]) {
		return; // We don't have to require login here
	}
	if ($requireLogin[$ma] === true) {
		if ($user = Users::loggedInUser()) {
			return; // user is already logged in
		}
	} else {
		if ($appUser = Users_ExternalFrom::authenticate($requireLogin[$ma])) {
			return; // We don't have to require login here
		}
	}
	$redirect_action = Q_Config::get('Users', 'uris', "$app/login", "$app/welcome");
	if ($redirect_action and $ma != $redirect_action) {
		Q_Response::redirect($redirect_action);
		if (!Q_Request::isAjax()) {
			$stop_dispatch = true;
		}
	}
}

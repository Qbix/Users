<?php

/**
 * Default implementation of logout
 * Just logs the user out.
 */
function Users_logout_post()
{
	Users::logout();
	Q_Response::setSlot('script', false);

	if (!empty(Users::$logoutFetch)) {
		Q_Response::setSlot('fetch', Users::$logoutFetch);
	}
}
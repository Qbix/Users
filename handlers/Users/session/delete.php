<?php

function Users_session_delete()
{
	// Destroy current session
    Q_Session::start();
	Q_Session::destroy();

	// Optionally remove the session cookie immediately
	if (session_name()) {
		setcookie(session_name(), '', time() - 3600, '/');
	}

	// Clear any additional Users session-related client vars
	Q_Response::clearSlot('Q.Users');
	Q_Response::setSlot('result', 'success');

	// If you want, you can also clear session extras for completeness
	Q_Response::processSessionExtras('before');
	Q_Response::processSessionExtras('after');
}
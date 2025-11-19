<?php

function Users_after_Q_session_start($params)
{
	$switchToLoggedInUserId = Q::ifset($_SESSION, 'Users', 'switchToLoggedInUserId', null);
	if ($switchToLoggedInUserId and !Users::loggedInUser()) {
		unset($_SESSION['Users']['switchToLoggedInUserId']);
		Users::setLoggedInUser($switchToLoggedInUserId);
	}
}
<?php

/**
 * Adds an email address or mobile number to the logged-in user's account,
 * sending the activation message to it.
 *
 * With "userId" naming another user, it sets up the first identifier of an
 * account that has none yet (for example a user invited on paper who never
 * logged in), so someone can help them get in. Whoever activates that
 * identifier can then log in as that user, so the caller must be logged in
 * and that user must have given them one of the labels in
 * Users/identifier/canManage (as with Users/icon/canManage: the caller holds
 * the label in that user's contacts). Nothing is configured by default, so
 * nobody can do it until an app opts in. Community accounts are never a
 * subject here. Naming yourself is the ordinary path; the "already able to
 * log in" guard applies only to other users.
 */
function Users_identifier_post()
{
	$userId = Q::ifset($_REQUEST, 'userId', null);
	$loggedInUser = Users::loggedInUser(true);
	if (isset($userId) and $userId !== $loggedInUser->id) {
		$labels = Q_Config::get('Users', 'identifier', 'canManage', array());
		// Users::roles() of an empty id ('' or '0') would judge the current
		// community instead. A community account is refused outright: its
		// roles are the very rows that make the caller an owner or admin of
		// it, and activating an identifier on it would log them in as the
		// community.
		if (!is_string($userId) or empty($userId) or !$labels
		or Users::isCommunityId($userId)
		or !Users::roles($userId, $labels, array(), $loggedInUser->id)) {
			throw new Users_Exception_NotAuthorized();
		}
		$user = Users_User::fetch($userId, true);
		if ($user->emailAddress or $user->mobileNumber) {
			throw new Q_Exception("This user is already able to log in and set their own email and mobile number.");
		}
	} else {
		$user = $loggedInUser;
	}
	$app = Q::app();
	$fields = array();

	$identifier = Users::requestedIdentifier($type);
	if (!$type) {
		throw new Q_Exception(
			"a valid email address or mobile number or wallet is required",
			array('identifier', 'mobileNumber', 'emailAddress')
		);
	}
	if ($type === 'email') {
		$subject = Q_Config::get(
			'Users', 'transactional', 'identifier', 'subject', "Welcome! Verify your email address." 
		);
		$view = Q_Config::get(
			'Users', 'transactional', 'identifier', 'body', 'Users/email/addEmail.php'
		);
		$user->addEmail(
			$identifier, $subject, $view, array()
		);
		$user->save();
	} else if ($type === 'mobile') {
		$view = Q_Config::get(
			'Users', 'transactional', 'identifier', 'mobile', Q_Config::get(
				'Users', 'transactional', 'identifier', 'sms', 'Users/mobile/activation.php'
			)
		);
		$user->addMobile(
			$identifier,
			$view
		);
		$user->save();
	}
}

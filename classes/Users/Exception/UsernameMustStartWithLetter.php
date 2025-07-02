<?php

/**
 * @module Users
 */
class Users_Exception_UsernameMustStartWithLetter extends Q_Exception
{
	/**
	 * An exception is raised if user is not authorized
	 * @class Users_Exception_UsernameMustStartWithLetter
	 * @constructor
	 * @extends Q_Exception
	 */
};

Q_Exception::add('Users_Exception_UsernameMustStartWithLetter', 'Usernames must start with a letter');

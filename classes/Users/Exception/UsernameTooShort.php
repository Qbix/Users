<?php

/**
 * @module Users
 */
class Users_Exception_UsernameTooShort extends Q_Exception
{
	/**
	 * An exception is raised if user is not authorized
	 * @class Users_Exception_UsernameTooShort
	 * @constructor
	 * @extends Q_Exception
	 */
};

Q_Exception::add('Users_Exception_UsernameTooShort', 'Username length must be at least {{length}} characters');

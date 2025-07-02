<?php

/**
 * @module Users
 */
class Users_Exception_UsernameTooLong extends Q_Exception
{
	/**
	 * An exception is raised if user is not authorized
	 * @class Users_Exception_UsernameTooLong
	 * @constructor
	 * @extends Q_Exception
	 */
};

Q_Exception::add('Users_Exception_UsernameTooLong', 'Username length must be at most {{length}} characters');

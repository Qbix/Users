<?php

/**
 * @module Users
 */
class Users_Exception_AccountTooYoung extends Q_Exception
{
	/**
	 * An exception to be raised if an account is not old enough
	 * @class Users_Exception_AccountTooYoung
	 * @constructor
	 * @extends Q_Exception
	 * @param {string} $key
	 */
};

Q_Exception::add('Users_Exception_AccountTooYoung', 'This account is not old enough.');

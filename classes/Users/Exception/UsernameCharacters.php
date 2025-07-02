<?php

/**
 * @module Users
 */
class Users_Exception_UsernameCharacters extends Q_Exception
{
	/**
	 * An exception is raised if user is not authorized
	 * @class Users_Exception_UsernameCharacters
	 * @constructor
	 * @extends Q_Exception
	 */
};

Q_Exception::add('Users_Exception_UsernameCharacters', 'Please use only A..Z, a..z, 0..9, - and _');

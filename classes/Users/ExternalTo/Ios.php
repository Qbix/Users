<?php

/**
 * @module Users
 */

/**
 * Class representing iOS app user.
 *
 * @class Users_ExternalTo_Ios
 * @extends Users_ExternalTo
 */
class Users_ExternalTo_Ios extends Users_ExternalTo implements Users_ExternalTo_Interface
{
    function fetchXids(array $roleIds, array $options = array())
    {
        return array();
    }
}
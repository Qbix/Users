<?php
/**
 * @module Users
 */
/**
 * Class representing 'Referred' rows in the 'Users' database
 * You can create an object of this class either to
 * access its non-static methods, or to actually
 * represent a referred row in the Users database.
 *
 * @class Users_Referred
 * @extends Base_Users_Referred
 */
class Users_Referred extends Base_Users_Referred
{
	/**
	 * The setUp() method is called the first time
	 * an object of this class is constructed.
	 * @method setUp
	 */
	function setUp()
	{
		parent::setUp();
		// INSERT YOUR CODE HERE
		// e.g. $this->hasMany(...) and stuff like that.
	}

	/**
	 * Inserts or updates Users_Referred
	 * @param {string} $userId The user that was referred
	 * @param {string} $publisherId The community or publisher of content the user was referred to
	 * @param {string} $referredToType The type of entity the user was referred to
	 * @param {string} $invitingUserId The user that did the referring
	 * @return {Users_Referred}
	 */
	static function handleReferral($userId, $publisherId, $referredToType, $invitingUserId)
	{
		$points = Q_Config::get('Users', 'referred', $referredToType, 'points', 10);
		if (!$points) {
			return;
		}
		$r = new Users_Referred(array(
			'userId' => $userId,
			'toCommunityId' => $publisherId,
			'byUserId' => $invitingUserId
		));
		if ($r->retrieve()) {
			$prevPoints = $r->points;
			$r->points = max($r->points, $points);
		} else {
			$prevPoints = 0;
			$r->points = $points;
		}
		$threshold = Q_Config::get('Users', 'referred', $referredToType, 'qualified', 10);
		$justQualified = false;
		if ($prevPoints < $threshold and $points >= $threshold) {
			// the user passed the threshold and qualified for something, record the time
			$r->qualifiedTime = new Db_Expression("CURRENT_TIMESTAMP");
			$justQualified = true;
		}
		$r->save();
		Q::event('Users/referred', array(
			'referred' => $r,
			'justQualified' => $justQualified
		), 'after');
		return $r;
	}

	/*
	 * Add any Users_Referred methods here, whether public or not
	 */
	 
	/**
	 * Implements the __set_state method, so it can work with
	 * with var_export and be re-imported successfully.
	 * @method __set_state
	 * @static
	 * @param {array} $array
	 * @return {Users_Referred} Class instance
	 */
	static function __set_state(array $array) {
		$result = new Users_Referred();
		foreach($array as $k => $v)
			$result->$k = $v;
		return $result;
	}
};
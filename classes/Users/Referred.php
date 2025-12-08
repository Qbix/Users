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
	 * @param {string} $referredAction The type of entity the user was referred to
	 * @param {string} $referredType The type of entity the user was referred to
	 * @param {string} $invitingUserId The user that did the referring
	 * @return {Users_Referred}
	 */
	static function handleReferral($userId, $publisherId, $referredAction, $referredType, $invitingUserId)
	{
		$points = Q_Config::get('Users', 'referred', $referredAction, $referredType, 'points', 10);
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
		$threshold = Q_Config::get('Users', 'referred', 'qualified', 'points', 10);
		$justQualified = false;
		if (!$r->qualifiedTime and $prevPoints < $threshold and $points >= $threshold) {
			// the user passed the threshold and qualified for something, record the time
			$r->qualifiedTime = new Db_Expression("CURRENT_TIMESTAMP");
			$justQualified = true;
		}

		$maxCount = Q_Config::get('Users', 'referred', 'history', 'max', 10);
		
		$byAction = $r->getExtra('byAction', array());
		$existing = Q::ifset($byAction, $referredAction, array());
		if (count($existing) > $maxCount) {
			array_shift($existing);
		}
		$existing[] = array(time(), $points, $prevPoints);
		$byAction[$referredAction] = $existing;
		$r->setExtra('byAction', $byAction);

		$byType = $r->getExtra('byType', array());
		$existing = Q::ifset($byType, $referredType, array());
		if (count($existing) > $maxCount) {
			array_shift($existing);
		}
		$existing[] = array(time(), $points, $prevPoints);
		$byType[$referredType] = $existing;
		$r->setExtra('byType', $byType);

		$r->save();
		Q::event('Users/referred', array(
			'referred' => $r,
			'justQualified' => $justQualified
		), 'after');
		return $r;
	}

	/**
	 * @method getAllExtras
	 * @return {array} The array of all extras set in the stream
	 */
	function getAllExtras()
	{
		return empty($this->extra) 
			? array()
			: json_decode($this->extra, true);
	}
	
	/**
	 * @method getExtra
	 * @param {string} $extraName The name of the extra to get
	 * @param {mixed} $default The value to return if the extra is missing
	 * @return {mixed} The value of the extra, or the default value, or null
	 */
	function getExtra($extraName, $default = null)
	{
		$attr = $this->getAllExtras();
		return isset($attr[$extraName]) ? $attr[$extraName] : $default;
	}
	
	/**
	 * @method setExtra
	 * @param {string} $extraName The name of the extra to set,
	 *  or an array of $extraName => $extraValue pairs
	 * @param {mixed} $value The value to set the extra to
	 * @return Streams_Participant
	 */
	function setExtra($extraName, $value = null)
	{
		$attr = $this->getAllExtras();
		if (is_array($extraName)) {
			foreach ($extraName as $k => $v) {
				$attr[$k] = $v;
			}
		} else {
			$attr[$extraName] = $value;
		}
		$this->extra = Q::json_encode($attr, Q::JSON_FORCE_OBJECT);

		return $this;
	}
	
	/**
	 * @method clearExtra
	 * @param {string} $extraName The name of the extra to remove
	 */
	function clearExtra($extraName)
	{
		$attr = $this->getAllExtras();
		unset($attr[$extraName]);
		$this->extra = Q::json_encode($attr, Q::JSON_FORCE_OBJECT);
	}
	
	/**
	 * @method clearAllExtras
	 */
	function clearAllExtras()
	{
		$this->extra = '{}';
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
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
	 * Call this to handle referrals for action taken on certain types of resources.
	 * Inserts or updates Users_Referred rows with information about users referring other users.
	 * May cause qualifiedTime to be set, in which case justQualified = true in Users/referred "after" hook
	 * @param {string} $userId The user that was referred
	 * @param {string} $communityId The community or publisher of content the user was referred to
	 * @param {string} $referredAction The type of entity the user was referred to
	 * @param {string} $referredType The type of entity the user was referred to
	 * @param {string} [$byUserId] You can explicitly override the user to reward for the referring
	 * @return {Users_Referred|false} Returns false if couldn't determine which user to reward
	 */
	static function handleReferral($userId, $communityId, $referredAction, $referredType, $byUserId = null)
	{
		$points = Q_Config::get('Users', 'referred', $referredAction, $referredType, 'points', 10);
		if (!$points) {
			return;
		}

		$lastActiveTime = Users_User::lastActiveTime($userId);
		$referred = null;
		$justQualified = false;

		$fields = Q::event(
			'Users/referred',
			compact('userId', 'communityId', 'referredAction', 'referredType', 'byUserId', 'points', 'referred', 'justQualified', 'lastActiveTime'),
			'before',
			compact('byUserId')
		);

		if (!empty($fields['byUserId'])) {
			$q = Users_Referred::select()->where(array(
				'userId' => $userId,
				'toCommunityId' => $communityId
			))->andWhere('qualifiedTime IS NOT NULL');

			$lastActiveDateTime = Users::db()->toDateTime($lastActiveTime);
			$seconds = Q_Config::get('Users', 'referred', 'expiration', 0);
			if ($seconds) {
				$cutoff = new Db_Range("$lastActiveDateTime - INTERVAL $seconds SECOND");
				$q = $q->andWhere(array('insertedTime' => new Db_Range(null, null, true, $cutoff)));
			}

			$rows = $q->orderBy('qualifiedTime', true)->limit(1)->fetchDbRows();
			if ($rows) {
				$fields['byUserId'] = $rows[0]->referredByUserId;
			} else {
				return false;
			}
		}

		// apply byUserId and any extras from fields
		$byUserId = $fields['byUserId'];
		if (!empty($fields['extras']) and is_array($fields['extras'])) {
			$referred->setExtra($fields['extras']);
		}

		$referred = new Users_Referred(array(
			'userId' => $userId,
			'toCommunityId' => $communityId,
			'referredByUserId' => $byUserId
		));

		if ($referred->retrieve()) {
			$prevPoints = $referred->points;
			$referred->points = max($referred->points, $points);
		} else {
			$prevPoints = 0;
			$referred->points = $points;
		}

		$threshold = Q_Config::get('Users', 'referred', 'qualified', 'points', 10);
		if (!$referred->qualifiedTime && $prevPoints < $threshold && $points >= $threshold) {
			$referred->qualifiedTime = new Db_Expression("CURRENT_TIMESTAMP");
			$justQualified = true;
		}

		$maxCount = Q_Config::get('Users', 'referred', 'history', 'max', 10);

		$byAction = $referred->getExtra('byAction', array());
		$existing = Q::ifset($byAction, $referredAction, array());
		if (count($existing) > $maxCount) {
			array_shift($existing);
		}
		$existing[] = array(time(), $points, $prevPoints);
		$byAction[$referredAction] = $existing;
		$referred->setExtra('byAction', $byAction);

		$byType = $referred->getExtra('byType', array());
		$existing = Q::ifset($byType, $referredType, array());
		if (count($existing) > $maxCount) {
			array_shift($existing);
		}
		$existing[] = array(time(), $points, $prevPoints);
		$byType[$referredType] = $existing;
		$referred->setExtra('byType', $byType);

		$referred->save();

		Q::event(
			'Users/referred',
			compact('userId', 'communityId', 'referredAction', 'referredType', 'byUserId', 'points', 'referred', 'justQualified', 'lastActiveTime'),
			'after'
		);

		return $referred;
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
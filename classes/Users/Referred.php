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
	 * @param {string} $referredAction The type of action the referred user took
	 * @param {string} $referredType The type of entity (e.g. stream) the user was referred to
	 * @param {array} [$options=array()]
	 * @param {string} [$options.byUserId] You can explicitly override the user to reward for the referring
	 * @param {array} [$options.extras] Pass any extras to this referral, e.g. to retain metadata for other plugins
	 * @param {array} [$options.points] Manually pass points (e.g. to scale points by size of purchase)
	 * @return {Users_Referred|false} Returns false if couldn't determine which user to reward
	 */
	static function handleReferral($userId, $communityId, $referredAction, $referredType, $options = array())
	{
		// Determine referral points for this action/type
		$points = Q::ifset($options, 'points', Q_Config::get(
			'Users', 'referred', $referredAction, $referredType, 'points',
			Q_Config::get('Users', 'referred', $referredAction, '', 'points', 1)
		));
		if (!$points) {
			return;
		}

		$lastActiveTime = Users_User::lastActiveTime($userId);
		$justQualified = false;

		// Allow hooks to modify byUserId or extras
		$fields = Q::take($options, array('byUserId', 'extras'));
		$fields = Q::event(
			'Users/referred',
			compact('userId', 'communityId', 'referredAction', 'referredType', 'options', 'points', 'lastActiveTime'),
			'before',
			false,
			$fields
		);

		// If no explicit override, determine referrer via deterministic lookup
		if (empty($fields['byUserId'])) {
			$fields['byUserId'] = Users_Referred::referrer(
				$userId,
				$communityId,
				array(
					'requireQualified' => true,
					'expiration'       => Q_Config::get('Users', 'referred', 'expiration', 0),
					'lastActiveTime'   => $lastActiveTime,
					'newestFirst'      => true
				)
			);

			if (!$fields['byUserId']) {
				return false; // No valid referrer found
			}
		}

		$byUserId = $fields['byUserId'];

		// Prepare the Users_Referred row
		$referred = new Users_Referred(array(
			'userId'         => $userId,
			'toCommunityId'  => $communityId,
			'referredByUserId' => $byUserId
		));

		// Update or set points. Look the row up by its primary key only, past
		// the per-request query cache (ignoreCache) and without storing the
		// result in it (caching(false)). Two ways this read used to miss an
		// existing row, and the save below then INSERTed it again and failed
		// on the duplicate key (ro#1068):
		// - extras were applied first, so retrieve() also matched on the
		//   `extra` column, which never equals the stored history;
		// - an earlier referral in this request inserted the row after a
		//   cached "missing" read (Assets_Credits::spend() paying one
		//   publisher twice in a request).
		if ($referred->retrieve(null, true, array('ignoreCache' => true, 'caching' => false))) {
			$prevPoints = $referred->points;
			$referred->points = max($referred->points, $points);
		} else {
			$prevPoints = 0;
			$referred->points = $points;
		}

		// Apply extra metadata, merged into what the row already holds
		if (!empty($fields['extras']) && is_array($fields['extras'])) {
			$referred->setExtra($fields['extras']);
		}

		// Determine if this qualifies the referrer now
		$threshold = Q_Config::get('Users', 'referred', 'qualified', 'points', 10);
		if (empty($referred->qualifiedTime)
			&& $prevPoints < $threshold
			&& $points >= $threshold)
		{
			$referred->qualifiedTime = new Db_Expression("CURRENT_TIMESTAMP");
			$justQualified = true;
		}

		// Maintain referral history: byAction
		$maxCount = Q_Config::get('Users', 'referred', 'history', 'max', 10);
		$byAction = $referred->getExtra('byAction', array());
		$existing = Q::ifset($byAction, $referredAction, array());
		if (count($existing) > $maxCount) {
			array_shift($existing);
		}
		$existing[] = array(time(), $points, $prevPoints);
		$byAction[$referredAction] = $existing;
		$referred->setExtra('byAction', $byAction);

		// Maintain referral history: byType
		$byType = $referred->getExtra('byType', array());
		$existing = Q::ifset($byType, $referredType, array());
		if (count($existing) > $maxCount) {
			array_shift($existing);
		}
		$existing[] = array(time(), $points, $prevPoints);
		$byType[$referredType] = $existing;
		$referred->setExtra('byType', $byType);

		// Save the row. Use save(true) with a duplicate-key guard rather than
		// a bare save(). Two concurrent requests (e.g. the Stripe webhook being
		// retried, or accept() and Assets/credits/spend firing in parallel) can
		// both call retrieve(), both get "not found", both try INSERT, and the
		// second one fails with a duplicate-key error. Catching it and merging
		// is correct because max() on points is already how this method handles
		// an existing row.
		try {
			$referred->save();
		} catch (Exception $e) {
			if (Q::ifset(Q::$cache, 'Db_Query_Exception_Duplicate', false)
			or strpos($e->getMessage(), 'Duplicate') !== false) {
				// lost the race: re-read, merge, update
				$referred2 = new Users_Referred(array(
					'userId' => $userId,
					'toCommunityId' => $communityId,
					'referredByUserId' => $byUserId
				));
				if ($referred2->retrieve()) {
					$referred2->points = max($referred2->points, $referred->points);
					$referred2->extra = $referred->extra;
					if (!empty($referred->qualifiedTime)) {
						$referred2->qualifiedTime = $referred->qualifiedTime;
					}
					$referred2->save();
				}
			} else {
				throw $e; // a real error, not a race
			}
		}

		/**
		 * @event Users/referred {after}
		 * @param string $userId
		 * @param string $communityId
		 * @param string $referredAction
		 * @param string $referredType
		 * @param string $byUserId
		 * @param int    $points
		 * @param Users_Referred $referred
		 * @param bool   $justQualified
		 */
		Q::event(
			'Users/referred',
			compact('userId', 'communityId', 'referredAction', 'referredType', 'byUserId', 'points', 'referred', 'justQualified', 'lastActiveTime'),
			'after'
		);

		return $referred;
	}


	/**
	 * Lightweight referrer lookup for a user within a community.
	 * Does not modify referral points or create rows.
	 *
	 * @method referrer
	 * @static
	 * @param {string} userId The user who was referred
	 * @param {string} communityId The community where the referral took place
	 * @param {array} [options] Optional parameters
	 * @param {boolean} [options.requireQualified=false] 
	 *   If true, only consider rows where qualifiedTime IS NOT NULL
	 * @param {integer} [options.expiration] 
	 *   Expiration window in seconds. Defaults to 
	 *   Q_Config::get('Users','referred','expiration',0)
	 * @param {integer} [options.lastActiveTime] 
	 *   UNIX timestamp used for expiration cutoff calculation
	 * @param {boolean} [options.newestFirst=false] 
	 *   If true, returns the row with the newest qualifiedTime or insertedTime
	 *
	 * @return {string|null} referredByUserId The referrer’s userId, or null if none found
	 */
	static function referrer($userId, $communityId, $options = array())
	{
		$requireQualified = Q::ifset($options, 'requireQualified', false);
		$newestFirst = Q::ifset($options, 'newestFirst', false);

		$expiration = Q::ifset($options, 'expiration', Q_Config::get(
			'Users', 'referred', 'expiration', 0
		));

		$lastActiveTime   = Q::ifset($options, 'lastActiveTime', null);
		if ($expiration && !$lastActiveTime) {
			// lastActiveTime is required for expiration logic
			$lastActiveTime = Users_User::lastActiveTime($userId);
		}

		$q = Users_Referred::select()->where(array(
			'userId'       => $userId,
			'toCommunityId'=> $communityId
		));

		if ($requireQualified) {
			$q = $q->andWhere("qualifiedTime IS NOT NULL");
		}

		// expiration: only consider referrals whose insertedTime is after cutoff
		if ($expiration && $lastActiveTime) {
			$db = Users::db();
			$dt = $db->toDateTime($lastActiveTime);
			$cutoff = new Db_Expression("'$dt' - INTERVAL $expiration SECOND");
			$q = $q->andWhere(array(
				'insertedTime' => new Db_Range(null, null, true, $cutoff)
			));
		}

		// order: newest first
		$orderCol = $requireQualified ? 'qualifiedTime' : 'insertedTime';
		$q = $q->orderBy($orderCol, $newestFirst);

		$row = $q->limit(1)->fetchDbRow();
		return $row ? $row->referredByUserId : null;
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

	/**
	 * Which referrals were handled during this request.
	 * Used to avoid handling them again.
	 */
	static $handled = array();
};
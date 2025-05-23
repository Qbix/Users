<?php
/**
 * @module Users
 */
/**
 * Class representing 'Label' rows in the 'Users' database
 * You can create an object of this class either to
 * access its non-static methods, or to actually
 * represent a label row in the Users database.
 *
 * @class Users_Label
 * @extends Base_Users_Label
 */
class Users_Label extends Base_Users_Label
{
	/**
	 * The setUp() method is called the first time
	 * an object of this class is constructed.
	 * @method setUp
	 */
	function setUp()
	{
		parent::setUp();
	}

	/**
	 * Return a label for Users_Label row, from a roleId.
	 * @static
	 * @param {string} $platform
	 * @param {string} [$appId=null]
	 * @param {string} [$roleId=null]
	 * @return {string} of the form {{platform}}_{{app}}/{{roleId}}
	 */
	static function external($platform, $appId = null, $roleId = null)
	{
		$result = self::$externalPrefix . $platform;
		if ($appId) {
			$result .= '/' . $appId;
		}
		if ($roleId) {
			$result .= '/' . $roleId;
		}
		return $result;
	}

	/**
	 * Parse an external label into platform, appId, label
	 * @method parseExternalLabel
	 * @static
	 * @param {string} $externalLabel must start with externalPrefix "<<<"
	 * @return {array} of platform, appId, label
	 */
	static function parseExternalLabel($externalLabel)
	{
		if (!Q::startsWith($externalLabel, self::$externalPrefix)) {
			return array(null, null, null);
		}
		$externalLabel = substr($externalLabel, strlen(self::$externalPrefix));
		$parts1 = explode('/', $externalLabel);
		if (!isset($parts1[1])) {
			return array($parts1[0], null, null);
		}
		$parts2 = explode('/', $parts1[1]);
		$roleId = isset($parts2[1]) ? $parts2[1] : null;
		return array($parts1[0], $parts2[0], $roleId);
	}

	/**
	 * Creates a new label / role
	 * @method {boolean} addLabel
	 * @static
	 * @param {string|array} $label A label or array whose keys are labels,
	 *   and values may include a string ($title) or array($title, $icon).
	 * @param {string} [$userId=null] Defaults to the logged-in user
	 * @param {string} [$title=''] specify the title, otherwise a default one is generated
	 * @param {string} [$icon=null] defaults to what's in config, falling back to "labels/default"
	 * @param {string|false} [$asUserId=null] The user to do this operation as.
	 *   Defaults to the logged-in user. Pass false to skip access checks and set Q::app() as the user.
	 * @param boolean [$updateIfExists=false] If true, updates the label if it already exists
	 * @param {boolean} [$skipAccess=false] If true skip checking permissions
	 *   in the database.
	 * @return {Users_Label}
	 */
	static function addLabel(
		$label, 
		$userId = null, 
		$title = null, 
		$icon = null,
		$asUserId = null,
		$updateIfExists = false,
		$skipAccess = false)
	{
		if (is_array($label)) {
			if (!Q::isAssociative($label)) {
				foreach ($label as $l) {
					Users_Label::addLabel($l, $userId, null, null, $asUserId, $updateIfExists, $skipAccess);
				}
				return;
			}
			foreach ($label as $l => $title) {
				Users_Label::addLabel($l, $userId, $title, $icon, $asUserId, $updateIfExists, $skipAccess);
			}
			return;
		} else if (empty($label)) {
			throw new Q_Exception_RequiredField(array('field' => 'label'));
		}
		if (is_array($title)) {
			$icon = $title[1];
			$title = $title[0];
		}
		if (empty($title) or empty($icon)) {
			$info = Users::isCommunityId($userId)
				? Q_Config::get('Users', 'roles', $label, array())
				: Q_Config::get('Users', 'labels', $label, array());
			if (empty($title)) {
				$title = Q::ifset($info, 'title', null);
				// if still empty, then will be calculated from label
			}
			if (empty($icon)) {
				if (isset($info['icon'])) {
					$icon = $info['icon'];
				} else {
					if (Q::realPath(
						USERS_PLUGIN_WEB_DIR.DS.'img'.DS.'icons'.DS.'labels'.DS
						.str_replace('/', DS, $label)
					)) {
						$icon = "labels/$label";
					} else {
						$icon = "labels/default";
					}
				}
			}
		}
		if (is_array($title)) {
			// can specify text file entries directly
			$title = Q::interpolate($title);
		} else {
			$parts = explode('/', $label);
			$module = reset($parts);
			if (empty($title)) {
				$title = ucfirst(end($parts));
			}
			try {
				$text = Q_Text::get("$module/content");
				if (!empty($text['labels']['titles'][$label])) {
					$title = $text['labels']['titles'][$label];
				} else if (!empty($text['labels']['titles'][$title])) {
					$title = $text['labels']['titles'][$title];
				}
			} catch (Exception $e) {
				// maybe the file doesn't exist
			}
		}
		if (!isset($userId)) {
			$user = Users::loggedInUser(true);
			$userId = $user->id;
		}
		if (!isset($asUserId)) {
			$user = Users::loggedInUser(true);
			$asUserId = $user->id;
		}
		$l = new Users_Label();
		$l->label = $label;
		$l->userId = $userId;
		$retrieved = $l->retrieve();
		if ($retrieved and !$updateIfExists) {
			return $l;
		}

		if (!$skipAccess) {
			Users::canManageLabels($asUserId, $userId, $label, true);
		}
		if (!$retrieved) {
			// update permissions if external 
			// we've create similar structure as in platform\plugins\Users\config\plugin.json
			if (strpos($label, self::$externalPrefix) !== false) {
				$perm = new Users_Permission();
				$perm->userId = $userId;
				$perm->label = 'Users/owners';
				$perm->permission = 'Users/communities/roles';
				$perm->retrieve(null, false, array('begin' => true));
				$extras = $perm->getAllExtras();
				$extras['canManageLabels'][] = $label;
				$extras['canGrant'][] = $label;
				$extras['canRevoke'][] = $label;
				$extras['canManageLabels'] = array_unique($extras['canManageLabels']);
				$extras['canGrant'] = array_unique($extras['canGrant']);
				$extras['canRevoke'] = array_unique($extras['canRevoke']);
				$perm->setExtra($extras);
				$perm->save(false, true);
				$perm = new Users_Permission();
				$perm->userId = $userId;
				$perm->label = 'Users/admins';
				$perm->permission = 'Users/communities/roles';
				$perm->retrieve(null, false, array('begin' => true));
				$extras = $perm->getAllExtras();
				$extras['canManageLabels'][] = $label;
				$extras['canGrant'][] = $label;
				$extras['canRevoke'][] = $label;
				$extras['canManageLabels'] = array_unique($extras['canManageLabels']);
				$extras['canGrant'] = array_unique($extras['canGrant']);
				$extras['canRevoke'] = array_unique($extras['canRevoke']);
				$perm->setExtra($extras);
				$perm->save(false, true);
			}
		}
		self::_icon($l, $icon, $userId);
		$l->title = $title;
		$l->icon = $icon;
		$l->save(true); 
        
        
		return $l;
	}
	
	/**
	 * Update labels / roles
	 * @method updateLabel
	 * @static
	 * @param {string} $label
	 * @param {array} $updates Can contain one or more of "title", "icon"
	 * @param {string} [$userId=null] User that owns the label, current user if not provided
	 * @param {string} [$asUserId=null] The user to do this operation as.
	 *   Defaults to the logged-in user. Pass false to skip access checks.
	 * @throws {Users_Exception_NotAuthorized}
	 * @return {Db_Query_Mysql}
	 */
	static function updateLabel($label, $updates, $userId = null, $asUserId = null)
	{
		foreach (array('userId', 'label', 'updates') as $field) {
			if (empty($$field)) {
				throw new Q_Exception_RequiredField(@compact($field));
			}
		}
		if (!isset($userId)) {
			$user = Users::loggedInUser(true);
			$userId = $user->id;
		}
		Users::canManageLabels($asUserId, $userId, $label, true);
		$l = new Users_Label();
		$l->userId = $userId;
		$l->label = $label;
		if (!$l->retrieve()) {
			throw new Q_Exception_MissingRow(array(
				'table' => 'Label',
				'criteria' => json_encode($l->fields)
			));
		}
		if (isset($updates['title'])) {
			$l->title = $updates['title'];
		}
		$icon = Q::ifset($updates, 'icon', null);
		self::_icon($l, $icon, $userId);
		$l->save();
		return $l;
	}

	/**
	 * Remove an existing label / role
	 * @method removeLabel
	 * @static
	 * @param {string} $label
	 * @param {string|null} [$userId=null]
	 *   The user whose label is to be removed
	 * @param {string} [$asUserId=null] The user to do this operation as.
	 *   Defaults to the logged-in user. Pass false to skip access checks.
	 * @param {boolean} [$skipAccess=false] If true skip checking permissions
	 * @return {Db_Query_MySql}
	 */
	static function removeLabel($label, $userId = null, $asUserId = null, $skipAccess = false)
	{
		if (!isset($userId)) {
			$user = Users::loggedInUser(true);
			$userId = $user->id;
		}
		if (!$skipAccess) {
			Users::canManageLabels($asUserId, $userId, $label, true);
		}
		$usersLabel = new Users_Label();
		$usersLabel->userId = $userId;
		$usersLabel->label = $label;
		$usersLabel->remove();
	}

	/**
	 * Whether $label_1 can grant $label_2
	 * @method canGrantLabel
	 * @param {string} $label_1 - Label doing the granting
	 * @param {string|array} $label_2 - Label(s) being granted
	 * @param {array} [$roles] override the Users/roles config, if needed
	 * @throws Q_Exception_MissingConfig
	 * @return {bool} returns true only if label_1 can grant all label_2 labels
	 */
	static function canGrantLabel($label_1, $label_2, $roles = null)
	{
        return self::operateLabelAction($label_1, $label_2, 'canGrant', $roles);
	}

	/**
	 * Whether $label_1 can revoke $label_2
	 * @method canRevokeLabel
	 * @param {string} $label_1 - Label doing the revoking
	 * @param {string|array} $label_2 - Label(s) being revoked
	 * @param {array} [$roles] override the Users/roles config, if needed
	 * @throws Q_Exception_MissingConfig
	 * @return {bool} returns true only if label_1 can revoke all label_2 labels
	 */
	static function canRevokeLabel($label_1, $label_2, $roles = null)
	{
        return self::operateLabelAction($label_1, $label_2, 'canRevoke', $roles);
	}

	/**
	 * Whether $label_1 can see $label_2
	 * @method canSeeLabel
	 * @param {string} $label_1 - Label doing the seeing
	 * @param {string|array} $label_2 - Label(s) being seen
	 * @param {array} [$roles] override the Users/roles config, if needed
	 * @throws Q_Exception_MissingConfig
	 * @return {bool} returns true only if label_1 can see all label_2 labels
	 */
	static function canSeeLabel($label_1, $label_2, $roles = null)
	{
        return self::operateLabelAction($label_1, $label_2, 'canSee', $roles);
	}
    /**
	 * Whether $label_1 can "action" $label_2
     * "action" - can be "see", "revoke", "grant", etc.
	 * @param {string} $label_1 - Label doing the acting
	 * @param {string|array} $label_2 - Label(s) being acted upon
	 * @param {string} $actionKey - key that identify data from $roles[$label_2]
	 * @param {array} [$roles] override the Users/roles config, if needed
	 * @throws Q_Exception_MissingConfig
	 * @return {bool} returns true only if label_1 can operate on all label_2 labels
	 */
    static function operateLabelAction($label_1, $label_2, $actionKey, $roles = null)
    {
		if (empty($roles)) {
			$roles = Q_Config::expect("Users", "roles");
		}
        $roleKeys = array_keys($roles);
		
		// check whether label exist
		if (!in_array($label_1, $roleKeys)) {
			return false;
		}

		if (is_string($label_2)) {
			$label_2 = array($label_2);
		}
        
		$rolesCanOperate = Q::ifset($roles, $label_1, $actionKey, array());

		foreach ($label_2 as $label) {
			if (in_array($label, $rolesCanOperate)) {
				return false;
			}
		}

		return true;
    }


	/**
	 * Get information as to which community roles a user can grant, revoke or see.
	 * Any hooks for "Users/Label/can" event can also add additional info to the returned result
	 * @method can
	 * @param {string} $communityId The community for which we are checking labels
	 * @param {string} [$userId=null] The user whose access we are checking. Defaults to logged-in user.
	 * @return array Contains "grant", "revoke", "see" arrays of labels, as well as
	 *  "roles", "manageIcon", "manageContacts" and any other information added by hooks
	 */
	static function can($communityId, $userId = null)
	{
		if (!$userId) {
			$user = Users::loggedInUser();
			$userId = Q::ifset($user, "id", null);
		}
		if (!Users::isCommunityId($communityId)) {
			throw new Users_Exception_NoSuchUser();
		}
		$userCommunityRoles = array_merge(array(""), array_keys(Users::roles($communityId, null, array(), $userId)));
        $communityRoles = self::ofCommunity($communityId);
		$communityLabels = Users_Label::fetch($communityId, "", array("skipAccess" => true));
		$labelsCanManageIcon = Q_Config::get("Users", "icon", "canManage", array());
		$result = array(
			"manageIcon" => false,
			"manageContacts" => Users::canManageContacts($userId, $communityId, Q::app()."/"),
			"grant" => array(),
			"revoke" => array(),
			"see" => array()
		);
        
        
		foreach ($userCommunityRoles as $role) {
			$result["roles"][] = $role;
			//foreach ($communityRoles as $keyLabel => $label) {
			foreach ($communityLabels as $keyLabel => $label) {
				if (Users_Label::canGrantLabel($role, $keyLabel, $communityRoles)) {
					if (!in_array($keyLabel, $result['grant'])) {
						$result["grant"][] = $keyLabel;
					}
				}
				if (Users_Label::canRevokeLabel($role, $keyLabel, $communityRoles)) {
					if (!in_array($keyLabel, $result['revoke'])) {
						$result["revoke"][] = $keyLabel;
					}
				}
				if (Users_Label::canSeeLabel($role, $keyLabel, $communityRoles)) {
					if (!in_array($keyLabel, $result['see'])) {
						$result["see"][] = $keyLabel;
					}
				}
			}

			if (in_array($role, $labelsCanManageIcon)) {
				$result["manageIcon"] = true;
			}
		}

		// collect from other sources
		Q::event("Users/Label/can", @compact('userId', 'communityId', 'userCommunityRoles', 'communityRoles'), 'after', false, $result);

		return $result;
	}

	/**
	 * Get labels related to communities
	 * @method ofCommunities
	 * @return {array}
	 */
	static function ofCommunities()
	{
		$roles = Q_Config::expect("Users", "roles");
		return array_keys($roles);
	}
    
	/**
	 * Merges the extras from each dynamic role in the database,
	 * over the information found in Users/communities/roles config.
	 * @param {string} $communityId The user ID of the community
	 * @return {array} the merged array of the form [ $label => ["canSee": [...], "canGrant": [...], "canRevoke": [...], ]
	 */
    static function ofCommunity($communityId) 
    {
		$roles = Q_Config::get('Users', 'roles', array());
        $rows = Users_Permission::ofCommunity($communityId);
		$tree = new Q_Tree($roles);
		foreach ($rows as $row) {
			if ($row->userId !== '') {
				continue;
			}
			$label = $row->label;
			$tree->merge(array(
				$label => $row->getAllExtras()
			));
		}
		return $tree->getAll();
    }

	/**
	 * Get array of labels from both "canGrant" and "canRevoke"
	 * which can manage a given label.
	 * @method canManage
	 * @static
	 * @param {string} $communityid
	 * @param {string} $label
	 * @return {array} with keys "labels" (from all roles) and "locked" (from config roles)
	 */
	static function canManage($communityId, $label) 
	{
		$ret = array();
		$labels = Users_Label::ofCommunity($communityId);
		$roles = Q_Config::get('Users', 'roles', array());	
		
		$ret['labels'] = Users_Label::_canManage($labels, $label);
		$ret['locked'] = Users_Label::_canManage($roles, $label);
		return $ret;
	}

	/**
	 * return array of labels which contain labels in both array `canGrant` and `canRevoke`
	 */
	protected static function _canManage($labels, $label) 
	{
		$ret = array();
		if (!empty($labels[$label])) {
			$arrCanGrant = (empty($labels[$label]['canGrant'])) ? array() : $labels[$label]['canGrant'];
			$arrCanRevoke = (empty($labels[$label]['canRevoke'])) ? array() : $labels[$label]['canRevoke'];

			$t = array_unique(array_merge(array_values($arrCanGrant), array_values($arrCanRevoke)));
			foreach ($t as $ilabel) {
				if (in_array($ilabel, $arrCanGrant) && in_array($ilabel, $arrCanRevoke)) {
					$ret[] = $ilabel;
				}
			}
		}
		return $ret;
	}
	
	/**
	 * Fetch an array of labels. By default, returns all the labels.
	 * @method fetch
	 * @param {string|array} [$userId=null] The id of the user whose contact labels should be fetched. Can be an array of userIds.
	 * @param {string|array|Db_Expression} [$filter=''] Pass a string prefix such as "Users/", or some array or db expression, to get only a particular subset of labels.
	 * @param {array} [$options=array()]
	 * @param {string} [$options.asUserId] the user to do access checks as
	 * @param {boolean} [$options.checkContacts=false] Whether to also look in the Users_Contact table and only return labels that have at least one contact.
	 * @return {array} An array of array(label => Users_Label) pairs
	 */
	static function fetch($userId = null, $filter = '', $options = array())
	{
		if (!isset($userId)) {
			$user = Users::loggedInUser(true);
			$userId = $user->id;
		}
		$prefixes = $labelNames = array();
		$criteria = @compact('userId');
		if ($filter) {
			if (is_string($filter)) {
				$filter = explode("\t", $filter);
			}
			foreach ($filter as &$f) {
				$f = trim($f);
				if (is_string($f) and substr($f, -1) === '/') {
					$prefixes[] = new Db_Range($f, true, false, true);
				} else {
					$labelNames[] = $f;
				}
			}

			$criteria['label'] = $labelNames;
		}
		if (!empty($options['checkContacts'])) {
			$contact_array = Users_Contact::select('userId, label, contactUserId')
				->where($criteria)
				->groupBy('userId, label, contactUserId')
				->fetchDbRows();
			foreach ($prefixes as $p) {
				$contact_array = array_merge($contact_array, Users_Contact::select('userId, label, contactUserId')
					->where(array_merge($criteria, array('label' => $p)))
					->groupBy('userId, label, contactUserId')
					->fetchDbRows()
				);
			}
		}
		$labels = Users_Label::select()
			->where($criteria)
			->fetchDbRows(null, null, 'label');
		foreach ($prefixes as $p) {
			$labelsPrefixed = Users_Label::select()
				->where(array_merge($criteria, array('label' => $p)))
				->fetchDbRows(null, null, 'label');
			$labels = array_merge($labels, $labelsPrefixed);
		}
		if (!empty($options['checkContacts'])) {
			$contacts = array();
			foreach ($contact_array as $contact) {
				$contacts[$contact->label] = $contact->label;
			}
			foreach ($labels as $label) {
				if (!isset($contacts[$label->label])) {
					unset($labels[$label->label]);
				}
			}
		}
		return $labels;
	}

	/**
	 * Fetch an array of basic labels info
	 * @method getLabelsInfo
	 * @return {array} An array of array(label => array(title=> ..., icon => ...)) pairs
	 */
	static function getLabelsInfo ($userId) {
		$labelsMysql = self::fetch($userId);
		$labels = array();
		foreach ($labelsMysql as $row) {
			$labels[$row->label] = array(
				"title" => $row->title,
				"icon" => Users::iconUrl($row->icon, "40.png")
			);
		}

		return $labels;
	}

	static function _icon($l, $icon, $userId)
	{
		if (!is_array($icon)) {
			if ($icon) {
				$l->icon = $icon;
			}
			return;
		}
		// Process any icon data
		$icon['path'] = 'Q/uploads/Users';
		$icon['subpath'] = "$userId/label/$l->label/icon";
		$data = Q_Image::postNewImage($icon);
		Q_Response::setSlot('icon', $data);
		$l->icon = '{{baseUrl}}/'.$data[''];
	}

	public static $externalPrefix = '<<< ';

	/* * * */
	/**
	 * Implements the __set_state method, so it can work with
	 * with var_export and be re-imported successfully.
	 * @method __set_state
	 * @param {array} $array
	 * @return {Users_Label} Class instance
	 */
	static function __set_state(array $array)
	{
		$result = new Users_Label();
		foreach($array as $k => $v)
			$result->$k = $v;
		return $result;
	}
};
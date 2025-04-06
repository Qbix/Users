<?php

function Users_after_Q_image_save($params, &$authorized)
{
    extract($params);
    /**
     * @var string $path
     * @var string $subpath
     * @var Users_User $user
     */
    $user = Q::ifset(Users::$cache, 'user', Users::loggedInUser(false, false));
    if (!$user) {
        return;
    }

    $fullpath = $path.($subpath ? DS.$subpath : '');
    Q_Utils::normalizePath($fullpath);

    $splitId = Q_Utils::splitId($user->id);
    $prefix = "Q/uploads/Users/$splitId";
    Q_Utils::normalizePath($prefix);

    if (Q::startsWith($fullpath, $prefix)) {
        $iconPrefix = "Q/uploads/Users/$splitId/icon";
        Q_Utils::normalizePath($iconPrefix);
        if (Q::startsWith($fullpath,$iconPrefix)) {
            // modification of logged user icon
            if ($user->icon != $subpath) {
                $user->icon = Q_Html::themedUrl("$path/$subpath", array(
                    'baseUrlPlaceholder' => true
                ));
                $user->save(); // triggers any registered hooks
                Users::$cache['iconUrlWasChanged'] = true;
            } else {
                Users::$cache['iconUrlWasChanged'] = false;
            }
        }
    } else if (Q::startsWith($fullpath, implode(DS, array('Q', 'uploads', 'Users')))
    and preg_match('/(\/[a-zA-Z]{2,3}){2,3}\/icon\//', $fullpath)) {
        // modification of another user
        // trying to fetch userId from subpath
        $anotherUserId = preg_replace('/\/icon.*/', '', $subpath);
        $anotherUserId = preg_replace('/\//', '', $anotherUserId);

        $anotherUser = Users_User::fetch($anotherUserId, false);

        if (!$anotherUser) {
            return;
        }

        // label can manage icons of other users
        $labelsCanManage = Q_Config::get("Users", "icon", "canManage", array());

        // whether logged user assigned as one of $labelsCanManage to $anotherUser
		$authorized = (bool)Users::roles($anotherUserId, $labelsCanManage, array(), $user->id);

        if ($authorized) {
            if ($anotherUser->icon != $subpath) {
                $anotherUser->icon = Q_Html::themedUrl("$path/$subpath", array(
					'baseUrlPlaceholder' => true
				));
                $anotherUser->save(); // triggers any registered hooks
                Users::$cache['iconUrlWasChanged'] = true;
            } else {
                Users::$cache['iconUrlWasChanged'] = false;
            }
        } else {
            throw new Users_Exception_NotAuthorized();
        }
    }
}
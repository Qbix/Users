<?php

require_once USERS_PLUGIN_DIR.DS.'vendor'.DS.'autoload.php';

use Minishlink\WebPush\WebPush;

class Users_Device_Web
{

	static function prepare($notification)
	{
		// lead to common standard
		if (is_string($notification['alert'])) {
			$notification['alert'] = array(
				'title' => Users::communityName(),
				'body' => $notification['alert']
			);
		}
		$result = array(
			'title' => Q::ifset($notification, 'alert', 'title', null),
			'body' => Q::ifset($notification, 'alert', 'body', null),
			'icon' => Q::ifset($notification, 'icon', ''),
			'sound' => Q::ifset($notification, 'sound', 'default')
		);
		if (isset($notification['collapseId'])) {
			$result['tag'] = $notification['collapseId'];
		}
		foreach (array(
			'url', 'data', 'tag', 'actions', 'requireInteraction',
			'icon', 'image', 'badge',
			'sound', 'dir', 'tag'
		) as $f) {
			if (isset($notification[$f])) {
				$result[$f] = $notification[$f];
			}
		}
		return $result;
	}

	static function send($device, $notifications)
	{
		$appConfig = Q_Config::expect('Users', 'apps', 'chrome', Q::app());
		$auth = array(
			'VAPID' => array(
				'subject' => $appConfig["url"],
				'publicKey' => $appConfig["publicKey"],
				'privateKey' => $appConfig["privateKey"]
			),
		);
		$webPush = new WebPush($auth);
		// send multiple notifications with payload
		foreach ($notifications as $notification) {
			$webPush->sendNotification(
				$device->deviceId,
				json_encode($notification), // payload
				$device->p256dh,
				$device->auth
			);
		}
		$webPush->flush();
	}

}
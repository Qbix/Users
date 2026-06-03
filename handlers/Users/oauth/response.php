<?php

/**
 * Generic OAuth 2.0 (PKCE) login/connect handler, driven by a Users_Intent.
 *
 * One action, four modes, distinguished by the request:
 *
 *   check=1               -> status mode. Reports {ok, xid} so the opener can poll
 *                            once after the popup closes. ok means phase 2 finished
 *                            (xid stashed on the intent). The login itself and the
 *                            intent completion happen afterwards, in Users::authenticate.
 *   code + state          -> phase 2. Exchange the code, resolve xid + tokens via the
 *                            platform adapter, stash them ON THE INTENT (server-side
 *                            only, no DB row), WITHOUT completing it, then close.
 *   state + error         -> user denied. Just close; the poll reports not-ok.
 *   intent set, no code   -> phase 1. Stash platform/appId/finalRedirect + the PKCE
 *                            verifier on the intent, redirect to the platform with
 *                            state = intent token.
 *
 * The opener then runs the ordinary Users.authenticate(platform) chain: its
 * ExternalFrom adapter reads the intent, builds the row, and Users::authenticate
 * saves it with the right userId and completes the intent. This handler never logs
 * anyone in (popup case), so it never rotates the opener's session.
 *
 * @module Users
 * @class Users_oauth
 */
function Users_oauth_response()
{
	Q_Session::start();

	// ---- status mode -------------------------------------------------------
	if (Q_Request::get('check', false)) {
		$token = Q_Request::get('intent', null);
		$ok = false;
		$xid = null;
		if ($token) {
			$intent = Users_Intent::fromToken($token);
			// only the session that created the intent may read its status
			if ($intent && $intent->sessionId === Q_Session::id()) {
				$xid = $intent->getInstruction('xid');
				// ok == phase 2 finished and stashed the xid. Completion is later,
				// inside Users::authenticate, so we must NOT gate on completedTime.
				$ok = !empty($xid);
			}
		}
		Q_Response::setSlot('ok', $ok);
		Q_Response::setSlot('completed', $ok);
		Q_Response::setSlot('xid', $ok ? $xid : "");
		return;
	}

	$code  = Q_Request::get('code', null);
	$state = Q_Request::get('state', null);

	// ---- phase 2: platform redirected back ---------------------------------
	if ($code && $state) {
		$intent = Users_Intent::fromToken($state);
		if (!$intent || !$intent->isValid()) {
			return Users_oauth_finalize(null); // stale/forged state; just close
		}
		$platform = $intent->getInstruction('platform');
		$platform = $platform ? $platform : 'twitter';
		$appId    = $intent->getInstruction('appId');
		$verifier = $intent->getInstruction('verifier');
		$finalRedirect = $intent->getInstruction('finalRedirect');

		try {
			list($appId, $appInfo) = Users::appInfo($platform, $appId, true);

			$tokens = Users_OAuth::exchange($platform, $appId, $code, $verifier);
			if (!$tokens || empty($tokens['accessToken'])) {
				throw new Q_Exception("token exchange failed");
			}

			// Resolve the external id (and any profile fields) using the platform
			// adapter; this is the only platform-specific call here.
			$className = 'Users_ExternalFrom_' . ucfirst($platform);
			$me  = call_user_func(array($className, 'fetchMe'), $appId, $tokens['accessToken']);
			$xid = Q::ifset($me, 'id', null);
			if (!$xid) {
				throw new Q_Exception("could not resolve xid from platform");
			}

			// Stash everything the adapter needs ONTO THE INTENT (server-side only,
			// never exported to the client). No DB row here, and do NOT complete the
			// intent: Users::authenticate builds the row and completes the intent once
			// the user is found/created (it stamps loggedInUserId into results).
			$expiresStr = !empty($tokens['expires'])
				? date('Y-m-d H:i:s', (int)$tokens['expires'])
				: null;
			$oauth = array(
				'accessToken'  => $tokens['accessToken'],
				'expires'      => $expiresStr,
				'refreshToken' => Q::ifset($tokens, 'refreshToken', null),
				'profile'      => $me
					? Q::take($me, array('username', 'name', 'profile_image_url'))
					: null
			);

			// The verifier has done its job; the xid is public and safe to expose.
			$intent->clearInstruction('verifier');
			$intent->setInstruction('xid', $xid);
			$intent->setInstruction('oauth', $oauth);
			$intent->save();

			if ($finalRedirect) {
				// Full-page flow: no opener will run the authenticate POST, so do it
				// here. (Popup flow leaves finalRedirect unset and the opener does it,
				// which is why we don't rotate the session in that case.)
				$_REQUEST['intent'] = $state;
				$authed = null;
				Users::authenticate($platform, $appId, $authed);
			}

			return Users_oauth_finalize($finalRedirect);
		} catch (Exception $e) {
			Q::log($e, 'Users');
			// leave the intent without an xid: the status check reports not-ok -> onCancel
			return Users_oauth_finalize($finalRedirect);
		}
	}

	// ---- phase 2: platform returned an error (user denied, etc.) -----------
	if ($state && Q_Request::get('error', null)) {
		// do not stash an xid; just close. Opener's status check -> onCancel.
		$intent = Users_Intent::fromToken($state);
		$fr = $intent ? $intent->getInstruction('finalRedirect') : null;
		return Users_oauth_finalize($fr);
	}

	// ---- phase 1: open the flow --------------------------------------------
	$token = Q_Request::get('intent', null);
	if (!$token) {
		throw new Q_Exception_RequiredField(array('field' => 'intent'));
	}
	$intent = Users_Intent::fromToken($token);
	if (!$intent || !$intent->isValid() || !empty($intent->completedTime)) {
		return Users_oauth_finalize(null);
	}

	$platform = Q_Request::get('platform', 'twitter');
	$appId    = Q_Request::get('appId', Q::app());
	list($appId, $appInfo) = Users::appInfo($platform, $appId, true);

	$finalRedirect = Q_Request::get('finalRedirect', null);
	if ($finalRedirect && !Q::startsWith($finalRedirect, Q_Request::baseUrl())) {
		$finalRedirect = null; // never honor an off-site redirect
	}

	$verifier = null;
	$authorizeUrl = Users_OAuth::authorizeUrl($platform, $appId, $token, $verifier);

	$intent->setInstruction('platform', $platform);
	$intent->setInstruction('appId', $appId);
	if ($verifier) {
		$intent->setInstruction('verifier', $verifier);
	}
	if ($finalRedirect) {
		$intent->setInstruction('finalRedirect', $finalRedirect);
	}
	$intent->save();

	Q_Response::redirect($authorizeUrl);
	return false;
}

/**
 * Renders a minimal page that closes the popup, or (for a full-page / webview
 * flow with no opener to close back to) redirects to a same-origin finalRedirect.
 * @method Users_oauth_finalize
 * @param {string|null} $finalRedirect
 * @return {boolean} false (output already handled)
 */
function Users_oauth_finalize($finalRedirect)
{
	if ($finalRedirect) {
		// Full-page / webview flow: openWindow was false, so there is no opener to
		// close back to. The session cookie (if Users::authenticate ran) is already
		// set, so just 302 straight there. No close-page, no blank-flash detour.
		Q_Response::redirect($finalRedirect);
		return false;
	}
	// Popup flow: close the window. The opener polls the intent for the result.
	header('Content-Type: text/html; charset=utf-8');
	echo <<<EOT
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Done</title></head>
<body><script>try { window.close(); } catch (e) {}</script></body></html>
EOT;
	return false;
}
<?php

/**
 * Generic OAuth 2.0 (PKCE) login/connect handler, driven by a Users_Intent.
 *
 * One action, three modes, distinguished by the request:
 *
 *   check=1                 -> status mode. Returns slots {completed, ok} for the
 *                              originating browser to poll once after popup.closed.
 *   intent set, no code     -> phase 1. Stash platform/appId/finalRedirect + the PKCE
 *                              verifier on the intent, redirect to the platform with
 *                              state = intent token.
 *   code + state            -> phase 2. Exchange the code, resolve the xid via the
 *                              platform's ExternalFrom adapter, stage the tokens in a
 *                              server-only ExternalFrom row, complete the intent with the
 *                              (public) xid, and render a page that closes the popup.
 *
 * The opener authenticates afterwards via the ordinary Users.authenticate(platform)
 * chain; this handler never logs anyone in, so it never rotates the opener's session.
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
		$completed = false;
		$ok = false;
		$xid = null;
		if ($token) {
			$intent = Users_Intent::fromToken($token);
			// only the session that created the intent may read its status
			if ($intent && $intent->sessionId === Q_Session::id()) {
				$completed = !empty($intent->completedTime);
				$xid = $intent->getInstruction('xid');
				if (!$xid) {
					$results = $intent->getInstruction('results');
					$xid = is_array($results) ? Q::ifset($results, 'xid', null) : null;
				}
				$ok = $completed && !empty($xid);
			}
		}
		Q_Response::setSlot('completed', $completed);
		Q_Response::setSlot('ok', $ok);
		Q_Response::setSlot('xid', $ok ? $xid : null);
		return;
	}

	$code  = Q_Request::get('code', null);
	$state = Q_Request::get('state', null);

	// ---- phase 2: platform redirected back ---------------------------------
	if ($code && $state) {
		$intent = Users_Intent::fromToken($state);
		if (!$intent || !$intent->isValid()) {
			return Users_oauth_renderClose(null); // stale/forged state; just close
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

			// Resolve the external id (and any profile fields) using the
			// platform's adapter; this is the only platform-specific call here.
			$className = 'Users_ExternalFrom_' . ucfirst($platform);
			$me  = call_user_func(array($className, 'fetchMe'), $appId, $tokens['accessToken']);
			$xid = Q::ifset($me, 'id', null);
			if (!$xid) {
				throw new Q_Exception("could not resolve xid from platform");
			}

			// Stage into the row's own columns; only the refresh token (and a small
			// profile subset), which have no column, go into extra. Query-level
			// upsert so the From->To mirror hook does NOT fire here (it runs later,
			// with the right userId, from Users::authenticate). On a returning xid
			// we update only the token columns, never userId.
			$expiresStr = !empty($tokens['expires'])
				? date('Y-m-d H:i:s', (int)$tokens['expires'])
				: null;
			$extra = array();
			if (!empty($tokens['refreshToken'])) {
				$extra['refreshToken'] = $tokens['refreshToken'];
			}
			if ($me) {
				// small, useful subset for import()/icon(); may go stale, which
				// is fine — it's refreshable and only used to seed the account
				$extra['profile'] = Q::take($me, array('username', 'name', 'profile_image_url'));
			}
			$extraJson = Q::json_encode($extra, Q::JSON_FORCE_OBJECT);

			$insert = array(
				'userId'       => '',
				'platform'     => $platform,
				'appId'        => $appId,
				'xid'          => $xid,
				'responseType' => 'code',
				'accessToken'  => $tokens['accessToken'],
				'expires'      => $expiresStr,
				'extra'        => $extraJson
			);
			$update = array(
				'responseType' => 'code',
				'accessToken'  => $tokens['accessToken'],
				'expires'      => $expiresStr,
				'extra'        => $extraJson
			);
			Users_ExternalFrom::insert($insert)
				->onDuplicateKeyUpdate($update)
				->execute();

			// The verifier has done its job; the xid is public and safe to expose.
			$intent->clearInstruction('verifier');
			$intent->setInstruction('xid', $xid);
			$intent->complete(array('xid' => $xid)); // marks completed + saves

			if ($finalRedirect) {
				// Full-page flow: this is the user's own tab/session, and no opener
				// will run the authenticate POST, so complete the login here. (In the
				// popup flow finalRedirect is unset and the opener authenticates,
				// which is why we don't rotate the session here in that case.)
				$_REQUEST['intent'] = $state;
				$authed = null;
				Users::authenticate($platform, $appId, $authed);
			}

			return Users_oauth_renderClose($finalRedirect);
		} catch (Exception $e) {
			Q::log($e, 'Users');
			// leave the intent incomplete: the status check reports not-ok -> onCancel
			return Users_oauth_renderClose($finalRedirect);
		}
	}

	// ---- phase 2: platform returned an error (user denied, etc.) -----------
	if ($state && Q_Request::get('error', null)) {
		// do not complete the intent; just close. Opener's status check -> onCancel.
		$intent = Users_Intent::fromToken($state);
		$fr = $intent ? $intent->getInstruction('finalRedirect') : null;
		return Users_oauth_renderClose($fr);
	}

	// ---- phase 1: open the flow --------------------------------------------
	$token = Q_Request::get('intent', null);
	if (!$token) {
		throw new Q_Exception_RequiredField(array('field' => 'intent'));
	}
	$intent = Users_Intent::fromToken($token);
	if (!$intent || !$intent->isValid() || !empty($intent->completedTime)) {
		return Users_oauth_renderClose(null);
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
 * @method Users_oauth_renderClose
 * @param {string|null} $finalRedirect
 * @return {boolean} false (output already handled)
 */
function Users_oauth_renderClose($finalRedirect)
{
	$fr = $finalRedirect ? Q::json_encode($finalRedirect) : 'null';
	header('Content-Type: text/html; charset=utf-8');
	echo <<<EOT
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Done</title></head>
<body>
<script>
(function () {
	var finalRedirect = $fr;
	try { window.close(); } catch (e) {}
	// If the window did not close (full-page / in-app webview), move along.
	setTimeout(function () {
		if (finalRedirect) { location.href = finalRedirect; }
	}, 150);
})();
</script>
</body></html>
EOT;
	return false;
}

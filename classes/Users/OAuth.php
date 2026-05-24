<?php
/**
 * @module Users
 */
/**
 * For oAuth methods
 *
 * @class Users_OAuth
 */
class Users_OAuth
{
	/**
	 * Get an array of ($code => $title) pairs from space-separated list in $_REQUEST['scope'],
	 * defaulting to the "all" scope.
	 * @method requestedScope
	 * @static
	 * @param {string} $client_id The id of the client app
	 * @param {boolean} [$throwIfMissing=false]
	 * @param {reference} [$scopes] this is filled with an array
	 * @return {array}
	 */
	static function requestedScope($client_id, $throwIfMissing = false, &$scopes = null)
	{
		$rs = Q::ifset($_REQUEST, 'scope', 'all');
		if (is_string($rs)) {
			$rs = preg_split("/(,|\s+|,+\s*)+/", $rs);
		}
		$scopes = Q_Config::get('Users', 'authorize', 'clients', $client_id, 'scopes', array(
			'all' => 'give this app full access'
		));
		if ($throwIfMissing) {
			foreach ($rs as $s) {
				if ($s and !isset($scopes[$s])) {
					throw new Q_Exception_WrongValue(array(
						'field' => 'scope',
						'range' => json_encode(array_keys($scopes))
					));
				}
			}
		}
		$scope = array(); // copy them in the order they are found in the config
		foreach ($scopes as $k => $v) {
			if (in_array($k, $rs)) {
				$scope[] = $k;
			}
		}
		return $scope;
	}

	/**
	 * Obtain or refresh an OAuth 2.0 access token for the logged-in user.
	 *
	 * Reads all settings from config under Users/apps/{$platform}/{$appId}:
	 *   clientId              — OAuth 2.0 client ID (required)
	 *   clientSecret          — client secret (omit for public/PKCE-only clients)
	 *   oauth2/authorizationUrl — e.g. "https://twitter.com/i/oauth2/authorize"
	 *   oauth2/tokenUrl         — e.g. "https://api.twitter.com/2/oauth2/token"
	 *   oauth2/revokeUrl        — e.g. "https://api.twitter.com/2/oauth2/revoke" (optional)
	 *   oauth2/scopes           — array, e.g. ["tweet.read","users.read","offline.access"]
	 *   oauth2/redirectUri      — full callback URL the platform will redirect back to
	 *   oauth2/pkce             — true|false (default true; false for confidential server flows)
	 *
	 * Behavior by situation:
	 *   Valid stored token found    → returns token array immediately.
	 *   Expired + refresh token     → silently refreshes, stores, returns token array.
	 *   Refresh failed / no token   → initiates PKCE redirect and returns null.
	 *   ?code=&state= in request    → exchanges code, stores token, returns token array.
	 *
	 * Return array keys on success:
	 *   access_token, token_type ('bearer'), expires (unix timestamp|null),
	 *   refresh_token (string|null), platform, appId
	 *
	 * Usage:
	 *   $token = Users_OAuth::oAuth('twitter');
	 *   if (!$token) return; // redirect was issued; stop processing
	 *   Q_Utils::get($url, null, [], ['Authorization: Bearer '.$token['access_token']]);
	 *
	 * @method oAuth
	 * @static
	 * @param {string} $platform Name of the platform under Users/apps config (e.g. 'twitter', 'google')
	 * @param {string} [$appId=Q::app()] Only needed when multiple apps exist for the platform
	 * @return {array|null} Token array on success, null if a redirect was issued
	 * @throws {Users_Exception_NotLoggedIn} If no user is logged in
	 * @throws {Q_Exception_MissingConfig} If required config keys are absent
	 */
	static function oAuth($platform, $appId = null)
	{
		$user = Users::loggedInUser();
		if (!$user) {
			throw new Users_Exception_NotLoggedIn();
		}

		list($appId, $appInfo) = Users::appInfo($platform, $appId, true);

		$clientId     = Q::ifset($appInfo, 'clientId', null);
		$clientSecret = Q::ifset($appInfo, 'clientSecret', null);
		$oauth2       = Q::ifset($appInfo, 'oauth2', array());

		$authUrl     = Q::ifset($oauth2, 'authorizationUrl', null);
		$tokenUrl    = Q::ifset($oauth2, 'tokenUrl', null);
		$scopes      = Q::ifset($oauth2, 'scopes', array());
		$redirectUri = Q::ifset($oauth2, 'redirectUri', null);
		$usePkce     = Q::ifset($oauth2, 'pkce', true);

		if (!$clientId || !$authUrl || !$tokenUrl || !$redirectUri) {
			throw new Q_Exception_MissingConfig(array(
				'fieldpath' => "Users/apps/$platform/$appId/oauth2"
			));
		}

		$sessionKey = "oauth2_{$platform}_{$appId}_{$user->id}";

		// ---- Check for a stored, valid token ----
		$ef = new Users_ExternalFrom();
		$ef->userId   = $user->id;
		$ef->platform = $platform;
		$ef->appId    = $appId;

		if ($ef->retrieve()) {
			$expiresTs = $ef->expires ? (int)Db::toDateTime($ef->expires, true) : null;
			$isExpired = $expiresTs && $expiresTs < time() + 300;

			if (!$isExpired && $ef->accessToken) {
				return self::tokenArray($ef, $platform, $appId);
			}

			// ---- Attempt silent refresh ----
			$refreshToken = $ef->get('refreshToken', null);
			if ($refreshToken) {
				$tokens = self::refresh($tokenUrl, $clientId, $clientSecret, $refreshToken);
				if ($tokens) {
					self::storeTokens($ef, $tokens);
					return self::tokenArray($ef, $platform, $appId);
				}
			}
			// Refresh failed — fall through to re-auth
		}

		// ---- Handle the OAuth callback (?code=&state= in the current request) ----
		$code        = Q_Request::get('code', null);
		$state       = Q_Request::get('state', null);
		$storedState = Q_Session::get("{$sessionKey}_state", null);

		if ($code && $state && $storedState && hash_equals($storedState, $state)) {
			$codeVerifier = $usePkce ? Q_Session::get("{$sessionKey}_verifier", null) : null;

			// Clear PKCE session values immediately (replay protection)
			Q_Session::clear("{$sessionKey}_state");
			Q_Session::clear("{$sessionKey}_verifier");

			$tokens = self::exchangeCode(
				$tokenUrl, $clientId, $clientSecret,
				$code, $codeVerifier, $redirectUri
			);

			if ($tokens && !empty($tokens['access_token'])) {
				if (!$ef->id) {
					$ef->userId   = $user->id;
					$ef->platform = $platform;
					$ef->appId    = $appId;
				}
				self::storeTokens($ef, $tokens);
				return self::tokenArray($ef, $platform, $appId);
			}
		}

		// ---- Initiate a fresh OAuth 2.0 (PKCE) authorization flow ----
		$state = self::generateVerifier(16);
		Q_Session::set("{$sessionKey}_state", $state);

		$params = array(
			'response_type' => 'code',
			'client_id'     => $clientId,
			'redirect_uri'  => $redirectUri,
			'scope'         => implode(' ', (array)$scopes),
			'state'         => $state,
		);

		if ($usePkce) {
			$verifier  = self::generateVerifier();
			$challenge = self::generateChallenge($verifier);
			Q_Session::set("{$sessionKey}_verifier", $verifier);
			$params['code_challenge']        = $challenge;
			$params['code_challenge_method'] = 'S256';
		}

		Q_Response::redirect($authUrl . '?' . http_build_query($params));
		return null;
	}

	/**
	 * Revoke and remove a stored OAuth 2.0 token for the logged-in user.
	 *
	 * Attempts to call the platform's revoke endpoint if oauth2/revokeUrl is
	 * configured. Always removes the local Users_ExternalFrom row regardless
	 * of the revocation response so that local logout is never blocked by a
	 * network failure.
	 *
	 * @method oAuthClear
	 * @static
	 * @param {string} $platform Name of the platform under Users/apps config
	 * @param {string} [$appId=Q::app()]
	 * @throws {Users_Exception_NotLoggedIn} If no user is logged in
	 */
	static function oAuthClear($platform, $appId = null)
	{
		$user = Users::loggedInUser();
		if (!$user) {
			throw new Users_Exception_NotLoggedIn();
		}

		list($appId, $appInfo) = Users::appInfo($platform, $appId, true);

		$ef = new Users_ExternalFrom();
		$ef->userId   = $user->id;
		$ef->platform = $platform;
		$ef->appId    = $appId;

		if (!$ef->retrieve()) {
			return; // nothing stored locally
		}

		// Best-effort revocation on the platform side
		$revokeUrl    = Q::ifset($appInfo, 'oauth2', 'revokeUrl', null);
		$clientId     = Q::ifset($appInfo, 'clientId', null);
		$clientSecret = Q::ifset($appInfo, 'clientSecret', null);

		if ($revokeUrl && $ef->accessToken && $clientId) {
			try {
				$body    = http_build_query(array('token' => $ef->accessToken));
				$headers = array(
					'Content-Type: application/x-www-form-urlencoded',
					'Accept: application/json',
				);
				if ($clientSecret) {
					$headers[] = 'Authorization: Basic ' . base64_encode("$clientId:$clientSecret");
				}
				Q_Utils::post($revokeUrl, $body,
					Q_Config::get('Q', 'userAgent', 'Qbix', null),
					[], $headers, 10, false
				);
			} catch (Exception $e) {
				// Swallow — local cleanup proceeds regardless
			}
		}

		// Clear any PKCE session state for this user+platform
		$sessionKey = "oauth2_{$platform}_{$appId}_{$user->id}";
		Q_Session::clear("{$sessionKey}_state");
		Q_Session::clear("{$sessionKey}_verifier");

		$ef->remove();
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Exchange an authorization code for an access token.
	 * @method exchangeCode
	 * @static
	 * @param {string} $tokenUrl
	 * @param {string} $clientId
	 * @param {string|null} $clientSecret  Null for public (PKCE-only) clients
	 * @param {string} $code
	 * @param {string|null} $codeVerifier  PKCE verifier stored in session; null for non-PKCE flows
	 * @param {string} $redirectUri
	 * @return {array|null} Raw token response, or null on failure
	 */
	private static function exchangeCode(
		$tokenUrl, $clientId, $clientSecret, $code, $codeVerifier, $redirectUri
	) {
		$body = array(
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'client_id'    => $clientId,
			'redirect_uri' => $redirectUri,
		);
		if ($codeVerifier) {
			$body['code_verifier'] = $codeVerifier;
		}
		$headers = array('Content-Type: application/x-www-form-urlencoded', 'Accept: application/json');
		if ($clientSecret) {
			$headers[] = 'Authorization: Basic ' . base64_encode("$clientId:$clientSecret");
		}
		$response = Q_Utils::post($tokenUrl, http_build_query($body),
			Q_Config::get('Q', 'userAgent', 'Qbix', null), [], $headers, 30, false
		);
		$arr = Q::json_decode($response, true);
		return (!empty($arr['access_token'])) ? $arr : null;
	}

	/**
	 * Use a refresh token to obtain a new access token.
	 * Requires the platform to have issued a refresh_token (typically needs
	 * offline_access or equivalent scope).
	 * @method refresh
	 * @static
	 * @param {string} $tokenUrl
	 * @param {string} $clientId
	 * @param {string|null} $clientSecret
	 * @param {string} $refreshToken
	 * @return {array|null} Raw token response, or null on failure
	 */
	private static function refresh($tokenUrl, $clientId, $clientSecret, $refreshToken)
	{
		$body = array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refreshToken,
			'client_id'     => $clientId,
		);
		$headers = array('Content-Type: application/x-www-form-urlencoded', 'Accept: application/json');
		if ($clientSecret) {
			$headers[] = 'Authorization: Basic ' . base64_encode("$clientId:$clientSecret");
		}
		$response = Q_Utils::post($tokenUrl, http_build_query($body),
			Q_Config::get('Q', 'userAgent', 'Qbix', null), [], $headers, 30, false
		);
		$arr = Q::json_decode($response, true);
		return (!empty($arr['access_token'])) ? $arr : null;
	}

	/**
	 * Persist token data into a Users_ExternalFrom row.
	 * The row must already have userId, platform, and appId set.
	 * @method storeTokens
	 * @static
	 * @param {Users_ExternalFrom} $ef
	 * @param {array} $tokens Raw token response from the platform
	 */
	private static function storeTokens(Users_ExternalFrom $ef, array $tokens)
	{
		$ef->accessToken = $tokens['access_token'];
		$ef->expires     = isset($tokens['expires_in'])
			? Db::fromDateTime(date('Y-m-d H:i:s', time() + (int)$tokens['expires_in']))
			: null;
		if (!empty($tokens['refresh_token'])) {
			$ef->set('refreshToken', $tokens['refresh_token']);
		}
		$ef->save();
	}

	/**
	 * Build the standard token array returned by oAuth().
	 * @method tokenArray
	 * @static
	 * @param {Users_ExternalFrom} $ef
	 * @param {string} $platform
	 * @param {string} $appId
	 * @return {array}
	 */
	private static function tokenArray(Users_ExternalFrom $ef, $platform, $appId)
	{
		return array(
			'access_token'  => $ef->accessToken,
			'token_type'    => 'bearer',
			'expires'       => $ef->expires ? (int)Db::toDateTime($ef->expires, true) : null,
			'refresh_token' => $ef->get('refreshToken', null),
			'platform'      => $platform,
			'appId'         => $appId,
		);
	}

	/**
	 * Generate a PKCE code_verifier (or state token).
	 * Produces N random bytes encoded as a base64url string, which stays
	 * within the 43–128 character range required by RFC 7636 at the default
	 * of 32 bytes (= 43 chars).
	 * @method generateVerifier
	 * @static
	 * @param {integer} [$bytes=32]
	 * @return {string}
	 */
	private static function generateVerifier($bytes = 32)
	{
		return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
	}

	/**
	 * Derive a PKCE code_challenge from a verifier.
	 * Algorithm: BASE64URL(SHA256(ASCII(code_verifier))) per RFC 7636 §4.2.
	 * @method generateChallenge
	 * @static
	 * @param {string} $verifier
	 * @return {string}
	 */
	private static function generateChallenge($verifier)
	{
		return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
	}
}
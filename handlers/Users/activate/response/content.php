<?php

function Users_activate_response_content()
{
	$email = $mobile = $type = $user = $emailAddress = $mobileNumber = null;
	extract(Users::$cache, EXTR_IF_EXISTS);

	$complete = false;
	if ($user and !empty($user->passphraseHash)) {
		if ($emailAddress and $user->emailAddress == $emailAddress) {
			$complete = true;
		} else if ($mobileNumber and $user->mobileNumber = $mobileNumber) {
			$complete = true;
		}
	}
	
	$app = Q::app();
	$successUrl = Q::ifset($_REQUEST, 'successUrl',
		Q_Config::get('Users', 'uris', "$app/successUrl", "$app/home")
	);
	$appendFields = array();
	foreach ($_GET as $k => $v) {
		$substr = substr($k, 0, 2);
		if ($substr !== 'Q_' && $substr !== 'Q.') {
			$appendFields[$k] = $v;
		}
	}
	$afterActivate = Q::ifset($_REQUEST, 'afterActivate',
		Q_Config::get('Users', 'uris', "$app/afterActivate", $successUrl)
	) .'?Q.fromSuccess=Users/activate';

	if (!empty(Users::$cache['success'])
	and Q_Request::method() === 'POST') {
		$afterActivate = Q_Uri::fixUrl(Q::interpolate($afterActivate, array(
			'email' => $emailAddress ? urlencode($emailAddress) : '',
			'mobile' => $mobileNumber ? urlencode($mobileNumber) : ''
		)));
		Q_Response::redirect($afterActivate);
		return true;
	}
	
	$view = Q_Config::get('Users', 'activateView', 'Users/content/activate.php');
	$t = $email ? 'e' : 'm';
	$autocompleteType = $email ? 'email' : 'phone';
	$identifier = $email ? $emailAddress : $mobileNumber;

	// Generate 10 passphrase suggestions (fallback)
	$suggestions = array();
	$arr = include(USERS_PLUGIN_FILES_DIR.DS.'passphrases.php');
	for ($i=0; $i<10; ++$i) {
		$pre1 = $arr['pre'][random_int(0, count($arr['pre'])-1)];
		$noun1 = $arr['nouns'][random_int(0, count($arr['nouns'])-1)];
		$verb = $arr['verbs'][random_int(0, count($arr['verbs'])-1)];
		$pre2 = $arr['pre'][random_int(0, count($arr['pre'])-1)];
		$noun2 = $arr['nouns'][random_int(0, count($arr['nouns'])-1)];
		$suggestions[] = strtolower("$pre1 $noun1 $verb $pre2 $noun2");
	}
	$verb_ue = urlencode($arr['verbs'][random_int(0, count($arr['verbs']) - 1)]);
	$noun_ue = urlencode($arr['nouns'][random_int(0, count($arr['nouns']) - 1)]);
	$code = Q::ifset($_REQUEST, 'code', null);
	
	// NewsAPI v2 enrichment (same output shape as before)
	if ($key = Q_Config::get('Users', 'newsapi', 'key', null)) {
		$words = 3;
		try {
			$languages = array();
			foreach (Q_Request::languages() as $entry) {
				$languages[reset($entry)] = true;
			}

			// v2 sources
			$sourcesUrl = "https://newsapi.org/v2/top-headlines/sources?apiKey=" . urlencode($key);
			$json = Q_Utils::get($sourcesUrl);
			$result = Q::json_decode($json, true);

			$sources = array();
			$fallback = array();

			if (!empty($result['sources'])) {
				foreach ($result['sources'] as $source) {
					if (!empty($languages[$source['language']])) {
						$sources[] = $source['id'];
					}
					if ($source['language'] === 'en') {
						$fallback[] = $source['id'];
					}
				}
			}

			if (!$sources) {
				$sources = $fallback;
			}

			$suggestions2 = array();

			if ($sources) {
				$source = $sources[array_rand($sources)];
				$url = "https://newsapi.org/v2/top-headlines?" . http_build_query(array(
					'sources'  => $source,
					'pageSize' => 50,
					'apiKey'   => $key
				));

				$json = Q_Utils::get($url);
				$result = Q::json_decode($json, true);

				if (!empty($result['articles'])) {
					foreach ($result['articles'] as $article) {
						if (empty($article['description'])) {
							continue;
						}
						$characters = "/([^A-Za-z0-9-']|\\s{2,})+/";
						$text = strtolower(preg_replace($characters, ' ', $article['description']));
						$parts = array_values(array_filter(explode(' ', $text)));
						$count = count($parts);

						if ($count > $words) {
							$rand = rand(0, $count - $words);
							$suggestion = implode(' ', array_slice($parts, $rand, $words));
							if (strlen($suggestion) > 10) {
								$suggestions2[] = $suggestion;
							}
						}
					}
				}
			}

			if ($suggestions2) {
				$suggestions = $suggestions2;
			}
		} catch (Exception $e) {
			// ignore
		}
	}
	
	$salt_json = Q::json_encode($user ? $user->salt : '');

	return Q::view($view, @compact(
		'identifier', 'type', 'user', 'code', 'afterActivate',
		'suggestions', 'verb_ue', 'noun_ue', 't', 'autocompleteType', 'app', 'home', 'complete', 'salt_json'
	));
}
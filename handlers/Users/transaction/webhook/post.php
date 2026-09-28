<?php
/**
 * Webhook endpoint for third-party transaction notification providers.
 *
 * Accepts POST from Alchemy, Moralis, or any provider that sends a txHash
 * when a watched address receives a transaction. The handler verifies the
 * signature, looks up the transaction in Users_Web3Transaction, confirms
 * it on chain, and fires the mined events.
 *
 * Provider is auto-detected from the request headers and payload shape.
 * Each provider signs its webhooks differently; the signing secret is
 * read from config under Users/web3/webhooks/{provider}/signingKey.
 *
 * @module Users
 * @class HTTP Users transaction webhook
 * @method POST
 */
function Users_transaction_webhook_post()
{
	$body = file_get_contents('php://input');
	$provider = _detectProvider($body);

	if (!$provider) {
		throw new Q_Exception_BadValue(array(
			'internal' => 'webhook provider',
			'problem' => 'could not be detected from the request'
		));
	}

	// Verify the webhook signature
	$signingKey = Q_Config::get('Users', 'web3', 'webhooks', $provider, 'signingKey', null);
	if ($signingKey && !_verifySignature($provider, $body, $signingKey)) {
		header('HTTP/1.1 401 Unauthorized');
		echo 'invalid signature';
		return;
	}

	$txHashes = _extractTransactionHashes($provider, $body);

	$confirmed = 0;
	$skipped = 0;
	$attempts = Q_Config::get('Users', 'web3', 'transactions', 'receipt', 'attempts', 3);

	foreach ($txHashes as $info) {
		$txHash = $info['hash'];
		$chainId = $info['chainId'];

		$tx = new Users_Web3Transaction(array(
			'chainId' => $chainId,
			'transactionId' => $txHash
		));

		if (!$tx->retrieve()) {
			// Not one of ours — the provider may be watching addresses
			// that receive transactions from outside the app
			$skipped++;
			continue;
		}

		if ($tx->status !== 'pending' && $tx->status !== 'signed') {
			$skipped++;
			continue;
		}

		// Verify on chain before trusting the webhook
		try {
			if (!$tx->updateFromBlockchainReceipt(compact('attempts'))) {
				$skipped++;
				continue;
			}
		} catch (Exception $e) {
			Q::log("webhook: receipt check failed for $txHash: " . $e->getMessage(), 'warning');
			$skipped++;
			continue;
		}

		if ($tx->status === 'mined') {
			$confirmed++;
			$contract = $tx->contract;
			$transaction = $tx;
			$communityId = $tx->communityId ?: null;

			if (!empty($tx->contractABIName)) {
				Q::event("Users/transaction/mined/"
					. $tx->contractABIName . '/'
					. $tx->methodName,
					compact('transaction', 'contract', 'chainId', 'communityId'),
					'after'
				);

				Q::event('Users/transaction/registered',
					compact('transaction', 'contract', 'chainId', 'communityId'),
					'after'
				);
			}
		}
	}

	// Return 200 so the provider does not retry
	Q_Response::setSlot('result', array(
		'confirmed' => $confirmed,
		'skipped' => $skipped
	));
}

/**
 * Detect which provider sent this webhook from headers and payload shape.
 */
function _detectProvider($body)
{
	// Alchemy: X-Alchemy-Token header, payload has .type = "MINED_TRANSACTION"
	if (!empty($_SERVER['HTTP_X_ALCHEMY_TOKEN'])) {
		return 'alchemy';
	}

	// Moralis: X-Signature header, payload has .tag and .txs array
	if (!empty($_SERVER['HTTP_X_SIGNATURE'])) {
		$decoded = json_decode($body, true);
		if (isset($decoded['tag']) || isset($decoded['txs'])) {
			return 'moralis';
		}
	}

	// QuickNode: X-QN-Nonce + X-QN-Timestamp + X-QN-Signature
	if (!empty($_SERVER['HTTP_X_QN_SIGNATURE'])) {
		return 'quicknode';
	}

	// Generic: look for a txHash field and trust the config to tell us
	$decoded = json_decode($body, true);
	if (isset($decoded['txHash']) || isset($decoded['transactionHash'])) {
		return 'generic';
	}

	return null;
}

/**
 * Verify the webhook signature according to the provider's scheme.
 */
function _verifySignature($provider, $body, $signingKey)
{
	switch ($provider) {
		case 'alchemy':
			// Alchemy sends the signing key as X-Alchemy-Token
			return hash_equals($signingKey, $_SERVER['HTTP_X_ALCHEMY_TOKEN'] ?? '');

		case 'moralis':
			// Moralis: HMAC-SHA3-256 of the body
			$expected = hash_hmac('sha3-256', $body, $signingKey);
			return hash_equals($expected, $_SERVER['HTTP_X_SIGNATURE'] ?? '');

		case 'quicknode':
			// QuickNode: HMAC-SHA256 of nonce + timestamp + body
			$nonce = $_SERVER['HTTP_X_QN_NONCE'] ?? '';
			$timestamp = $_SERVER['HTTP_X_QN_TIMESTAMP'] ?? '';
			$message = $nonce . $timestamp . $body;
			$expected = hash_hmac('sha256', $message, $signingKey);
			return hash_equals($expected, $_SERVER['HTTP_X_QN_SIGNATURE'] ?? '');

		case 'generic':
			// No signature verification for generic webhooks — rely on
			// the on-chain receipt check instead
			return true;

		default:
			return false;
	}
}

/**
 * Extract transaction hashes and chain IDs from the provider's payload.
 * Returns array of ['hash' => '0x...', 'chainId' => '0x...'].
 */
function _extractTransactionHashes($provider, $body)
{
	$decoded = json_decode($body, true);
	$results = array();

	switch ($provider) {
		case 'alchemy':
			// Alchemy Mined Transaction webhook:
			// { "type": "MINED_TRANSACTION", "event": { "network": "...",
			//   "transaction": { "hash": "0x..." } } }
			$event = Q::ifset($decoded, 'event', array());
			$hash = Q::ifset($event, 'transaction', 'hash', null);
			$network = Q::ifset($event, 'network', '');
			$chainId = _alchemyNetworkToChainId($network);
			if ($hash) {
				$results[] = array('hash' => $hash, 'chainId' => $chainId);
			}
			break;

		case 'moralis':
			// Moralis Streams: { "tag": "...", "chainId": "0x...",
			//   "txs": [ { "hash": "0x..." }, ... ] }
			$chainId = Q::ifset($decoded, 'chainId', '0x1');
			$txs = Q::ifset($decoded, 'txs', array());
			foreach ($txs as $tx) {
				if (!empty($tx['hash'])) {
					$results[] = array('hash' => $tx['hash'], 'chainId' => $chainId);
				}
			}
			break;

		case 'quicknode':
			// QuickNode: array of receipts
			$items = is_array($decoded) ? $decoded : array($decoded);
			foreach ($items as $item) {
				$hash = Q::ifset($item, 'transactionHash',
					Q::ifset($item, 'hash', null));
				$chainId = Q::ifset($item, 'chainId', '0x1');
				if ($hash) {
					$results[] = array('hash' => $hash, 'chainId' => $chainId);
				}
			}
			break;

		case 'generic':
			$hash = Q::ifset($decoded, 'txHash',
				Q::ifset($decoded, 'transactionHash',
				Q::ifset($decoded, 'hash', null)));
			$chainId = Q::ifset($decoded, 'chainId', '0x1');
			if ($hash) {
				$results[] = array('hash' => $hash, 'chainId' => $chainId);
			}
			break;
	}

	return $results;
}

/**
 * Map Alchemy network strings to EIP-155 chain IDs.
 */
function _alchemyNetworkToChainId($network)
{
	static $map = array(
		'ETH_MAINNET' => '0x1',
		'ETH_GOERLI' => '0x5',
		'ETH_SEPOLIA' => '0xaa36a7',
		'MATIC_MAINNET' => '0x89',
		'MATIC_MUMBAI' => '0x13881',
		'ARB_MAINNET' => '0xa4b1',
		'OPT_MAINNET' => '0xa',
		'BASE_MAINNET' => '0x2105',
	);
	return isset($map[$network]) ? $map[$network] : '0x1';
}

<?php
/**
 * Polls pending web3 transactions and updates their status.
 *
 * Run on a cron every 30-60 seconds:
 *
 *     * * * * * php APP_DIR/scripts/Users/web3-poll-pending.php
 *     * * * * * sleep 30 && php APP_DIR/scripts/Users/web3-poll-pending.php
 *
 * Each run queries Users_Web3Transaction for rows with status 'pending'
 * or 'signed' that are less than maxAge old, calls eth_getTransactionReceipt
 * on each through the configured RPC, and updates the row.
 *
 * When a transaction is confirmed it fires the same events the browser PUT
 * path does, so instance binding, markPaid, and everything downstream sees
 * no difference between a browser confirmation and a cron-detected one.
 *
 * Config keys (all under Users/web3/transactions/poll):
 *   maxAge     — seconds before a pending tx is marked expired (default 3600)
 *   batchSize  — max rows per run (default 50)
 *
 * @module Users
 */

if (!defined('Q_DIR')) {
	include dirname(dirname(dirname(dirname(dirname(__FILE__))))).'/scripts/Q.inc.php';
}

$maxAge    = Q_Config::get('Users', 'web3', 'transactions', 'poll', 'maxAge', 3600);
$batchSize = Q_Config::get('Users', 'web3', 'transactions', 'poll', 'batchSize', 50);
$attempts  = Q_Config::get('Users', 'web3', 'transactions', 'receipt', 'attempts', 3);

$cutoff = date('Y-m-d H:i:s', time() - $maxAge);

$pending = Users_Web3Transaction::select()
	->where(array(
		'status' => array('pending', 'signed')
	))
	->andWhere(array('insertedTime >=' => $cutoff))
	->orderBy('insertedTime', true)
	->limit($batchSize)
	->fetchDbRows();

if (empty($pending)) {
	exit(0);
}

$mined = 0; $failed = 0; $expired = 0; $still = 0;

foreach ($pending as $tx) {
	try {
		$updated = $tx->updateFromBlockchainReceipt(compact('attempts'));
	} catch (Exception $e) {
		Q::log("web3-poll-pending: {$tx->transactionId} on chain {$tx->chainId}: "
			. $e->getMessage(), 'warning');
		$still++;
		continue;
	}

	if ($updated && $tx->status === 'mined') {
		$mined++;
		_fireMined($tx);
	} elseif ($updated && $tx->status === 'failed') {
		$failed++;
	} elseif (strtotime($tx->insertedTime) < time() - $maxAge) {
		$tx->status = 'expired';
		$tx->save(true);
		$expired++;
	} else {
		$still++;
	}
}

if ($mined || $failed || $expired) {
	Q::log("web3-poll-pending: {$mined} mined, {$failed} failed, "
		. "{$expired} expired, {$still} still pending");
}

function _fireMined($tx) {
	$contract    = $tx->contract;
	$chainId     = $tx->chainId;
	$communityId = $tx->communityId ?: null;
	$transaction = $tx;

	if (empty($tx->contractABIName)) {
		return;
	}

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

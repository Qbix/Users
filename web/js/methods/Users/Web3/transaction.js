Q.exports(function (Users, priv) {

	/**
	 * Transfer native coin or issue transaction.
	 *
	 * @method transaction
	 * @static
	 * @param {String} recipient
	 * @param {Number} amount
	 * @param {Function} [callback]
	 * @param {Object} [options]
	 * @return {Promise}
	 */
	return Q.promisify(function _Web3_transaction(
		recipient,
		amount,
		callback,
		options
	) {
		var Web3 = Users.Web3;

		options = options || {};

		var wait = Q.getObject("wait", options);
		if (!isNaN(wait)) {
			delete options.wait;
		}

		Web3.withChain(options.chainId, function (err, provider) {

			if (err) {
				return Q.handle(callback, null, [err]);
			}

			try {

				// Normalize provider safely
				var ethersProvider =
					provider && provider._isProvider
						? provider
						: new ethers.providers.Web3Provider(provider, "any");

				var signer = ethersProvider.getSigner();

				Web3.getWalletAddress(function (err, address) {

					if (err) {
						return Q.handle(callback, null, [err]);
					}

					signer.sendTransaction(
						Q.extend({}, options, {
							from: address,
							to: recipient,
							value: ethers.utils.parseEther(String(amount))
						})
					)
					.then(function (tx) {

						if (!tx || !tx.wait) {
							return Q.handle(callback, null, [
								"Transaction request invalid",
								tx
							]);
						}

						// No wait requested
						if (!wait) {
							return Q.handle(callback, null, [null, tx]);
						}

						tx.wait(wait)
						.then(function (receipt) {

							if (parseInt(Q.getObject("status", receipt)) === 1) {
								return Q.handle(callback, null, [
									null,
									tx,
									receipt
								]);
							}

							Q.handle(callback, null, [
								"Transaction failed",
								tx,
								receipt
							]);

						})
						.catch(function (error) {
							Q.handle(callback, null, [error, tx]);
						});

					})
					.catch(function (error) {
						Q.handle(callback, null, [error]);
					});

				});

			} catch (error) {
				Q.handle(callback, null, [error]);
			}

		});

	});

});
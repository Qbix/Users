Q.exports(function (Users, priv) {

	/**
	 * Ensure correct chain is selected.
	 * May switch wallet to requested chain if necessary.
	 *
	 * @method withChain
	 * @static
	 * @param {String|null} chainId
	 *   Hex chain id (e.g. "0x1").
	 *   Pass null to use current chain.
	 * @param {Function} [callback]
	 *   Receives (err, provider, needSigner)
	 * @return {Promise}
	 */
	return Q.promisify(function _Web3_withChain(chainId, callback) {

		var Web3 = Users.Web3;

		Web3.connect(function (err, provider) {

			if (err) {
				return Q.handle(callback, null, [err]);
			}

			// If no chain specified, just use current
			if (!chainId) {
				return Q.handle(callback, null, [null, provider]);
			}

			// Normalize to decimal for comparison
			var current = parseInt(provider.chainId);
			var target = parseInt(chainId);

			if (current === target) {
				return Q.handle(callback, null, [null, provider]);
			}

			var chain = Web3.chains[chainId];
			if (!chain) {
				return Q.handle(callback, null, [
					"Users.Web3.withChain: Unknown chain " + chainId
				]);
			}

			Web3.switchChain(chain, function (err) {

				if (Q.firstErrorMessage(err)) {
					return Q.handle(callback, null, [err]);
				}

				Q.handle(callback, null, [null, provider]);
			});
		});
	});

});
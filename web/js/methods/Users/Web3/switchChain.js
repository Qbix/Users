Q.exports(function (Users, priv) {

	/**
	 * Switch provider to a different Web3 chain.
	 *
	 * @method switchChain
	 * @static
	 * @param {String|Object} info
	 * @param {Function} [callback]
	 *   Receives (error, chainId, provider)
	 * @return {Promise}
	 */
	return Q.promisify(function _Web3_switchChain(info, callback) {
        var Web3 = Users.Web3;
		if (typeof info === 'string') {
			info = Web3.chains[info];
		}
		if (!info || !info.chainId) {
			return Q.handle(callback, null, [
				"Q.Users.Web3.switchChain: chainId missing"
			]);
		}
		Web3.connect(function (err, provider) {
			if (err) {
				return Q.handle(callback, null, [err]);
			}
			// Already on correct chain
			if (provider && provider.chainId == info.chainId) {
				return _continue();
			}
			Web3.switchChainOccurring = true;
			provider.request({
				method: 'wallet_switchEthereumChain',
				params: [{ chainId: info.chainId }]
			})
			.then(_continue)
			.catch(function (switchError) {

				// Normalize possible JSON error message
				try {
					if (switchError && switchError.message
						&& JSON.isValid
						&& JSON.isValid(switchError.message)) {
						switchError = JSON.parse(switchError.message);
					}
				} catch (e) {}

				// If not "chain not added" error, fail
				if (switchError.code !== 4902
				 && switchError.code !== -32603) {

					Web3.switchChainOccurring = false;
					return Q.handle(callback, null, [switchError]);
				}

				var rpcUrls = info.rpcUrls || (info.rpcUrl ? [info.rpcUrl] : []);
				var blockExplorerUrls = info.blockExplorerUrls
					|| (info.blockExplorerUrl ? [info.blockExplorerUrl] : []);

				provider.request({
					method: 'wallet_addEthereumChain',
					params: [{
						chainId: info.chainId,
						chainName: info.name,
						nativeCurrency: {
							name: info.currency.name,
							symbol: info.currency.symbol,
							decimals: info.currency.decimals
						},
						rpcUrls: rpcUrls,
						blockExplorerUrls: blockExplorerUrls
					}]
				})
				.then(_continue)
				.catch(function (error) {
					Web3.switchChainOccurring = false;
					Q.handle(callback, null, [error]);
				});
			});

			function _continue() {
				Web3.switchChainOccurring = false;
				Q.handle(callback, null, [
					null,
					provider.chainId,
					provider
				]);
			}
		});
	});

});
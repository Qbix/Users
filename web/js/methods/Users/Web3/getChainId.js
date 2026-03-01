Q.exports(function (Users, priv) {

	/**
	 * Get currently selected chain id asynchronously
	 *
	 * @method getChainId
	 * @static
	 * @param {Function} [callback]
	 *   Receives (err, chainId) where chainId is hexadecimal (e.g. "0x1")
	 * @return {Promise}
	 *   Resolves with chainId or rejects with error
	 */
	return Q.promisify(function _Web3_getChainId(callback) {
        var Web3 = Users.Web3;
		Web3.connect(function (err, provider) {

			if (err) {
				return Q.handle(callback, null, [err]);
			}

			(new ethers.providers.Web3Provider(provider))
				.getNetwork()
				.then(function (network) {

					var chainIdHex = '0x' + Number(network.chainId).toString(16);

					Q.handle(callback, null, [null, chainIdHex]);
				})
				.catch(function (error) {
					Q.handle(callback, null, [error]);
				});

		});

	});

});
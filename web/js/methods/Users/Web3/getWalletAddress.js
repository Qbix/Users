Q.exports(function (Users, priv) {

	/**
	 * Get currently selected wallet address asynchronously
	 *
	 * @method getWalletAddress
	 * @static
	 * @param {Function} [callback]
	 *   Receives (err, address)
	 * @return {Promise}
	 *   Resolves with address or rejects with error
	 */
	return Q.promisify(function _Web3_getWalletAddress(callback) {
        var Web3 = Users.Web3;
		Web3.connect(function (err, provider) {

			if (err) {
				return Q.handle(callback, null, [err]);
			}

			(new ethers.providers.Web3Provider(provider))
				.listAccounts()
				.then(function (accounts) {

					var address = accounts && accounts.length
						? accounts[0]
						: null;

					Q.handle(callback, null, [null, address]);
				})
				.catch(function (error) {
					Q.handle(callback, null, [error]);
				});

		});

	});

});
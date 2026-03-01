Q.exports(function (Users, priv) {

	/**
	 * Connect web3 wallet session
	 *
	 * @method connect
	 * @static
	 * @param {Function} [callback]
	 *   Receives (err, provider)
	 * @return {Promise}
	 */
	return Q.promisify(function _Web3_connect(callback) {
        var Web3 = Users.Web3;
		var provider = window.ethereum || Web3.provider || null;
		if (!provider) {
			return Q.handle(callback, null, ["No Web3 provider"]);
		}

        Web3.provider = provider;

        priv.subscribeToEvents(provider); // the handlers will take care of it

		provider.request({ method: 'eth_requestAccounts' })
			.then(function (accounts) {

				Web3.provider = provider;

				Web3.onAccounts.handle(accounts);

				Q.handle(callback, null, [null, provider]);
			})
			.catch(function (error) {
				Q.handle(callback, null, [error]);
			});
	});

});
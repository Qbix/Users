Q.exports(function (Users, priv) {

	/**
	 * Check if user is logged in to MetaMask
	 *
	 * @method loggedIn
	 * @static
	 * @param {Function} [callback]
	 *   Receives (accountCount).
	 * @return {Boolean}
	 *   Returns false if MetaMask is not available.
	 */
	return function _Web3_loggedIn(callback) {

		if (typeof ethereum === 'undefined') {
			console.log("MetaMask browser plugin not found");
			return false;
		}

		(new ethers.providers.Web3Provider(ethereum))
		.listAccounts()
		.then(function (accounts) {
			Q.handle(callback, null, [accounts.length]);
		})
		.catch(function (err) {
			Q.alert(err.message);
		});

		return true;
	};

});
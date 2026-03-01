Q.exports(function (Users, priv) {

	/**
	 * Disconnect web3 wallet session
	 *
	 * @method disconnect
	 * @static
	 * @param {Function} [callback]
	 *   Receives (err)
	 * @return {Promise}
	 */
	return Q.promisify(function _Web3_disconnect(callback) {
        var Web3 = Users.Web3;
		if (Users.disconnect.web3.occurring) {
			return Q.handle(callback, null, ["Disconnect already occurring"]);
		}

		localStorage.removeItem('walletconnect');
		localStorage.removeItem('WALLETCONNECT_DEEPLINK_CHOICE');

		Users.disconnect.web3.occurring = true;

		if (Web3.web3Modal) {
			Web3.web3Modal.closeModal();
		}

		if (!Web3.provider) {
			Users.disconnect.web3.occurring = false;
			return Q.handle(callback, null, [null]);
		}

		var provider = Web3.provider;

		function _finalize() {
			delete Users.connected.web3;
			Web3.provider = null;
			Users.disconnect.web3.occurring = false;
			Q.handle(callback, null, [null]);
		}

		// WalletConnect-style providers
		if (provider.close) {

			provider.close()
				.then(function () {
					setTimeout(_finalize, 0);
				})
				.catch(function (err) {
					Users.disconnect.web3.occurring = false;
					Q.handle(callback, null, [err]);
				});

			// failsafe timeout
			Users.disconnect.web3.cleanupT = setTimeout(function () {
				Users.disconnect.web3.occurring = false;
				delete Users.disconnect.web3.cleanupT;
			}, 300);

		} else {

			setTimeout(function () {
				Users.logout.occurring = false;
			}, 0);

			if (provider._handleDisconnect) {
				provider._handleDisconnect();
			}

			_finalize();
		}

	});

});
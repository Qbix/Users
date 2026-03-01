Q.exports(function (Users, priv) {

	/**
	 * Used to fetch the ethers.Contract factory.
	 *
	 * @method getFactory
	 * @static
	 * @param {string} contractABIName
	 * @param {string|Object} [chainId]
	 * @param {Function} [callback]
	 *   Receives (err, contract)
	 * @return {Promise}
	 */
	return function _Web3_getFactory(
		contractABIName,
		chainId,
		callback
	) {
        var Web3 = Users.Web3;
        if (typeof contractABIName !== 'string') {
            throw new Error("Users.Web3.getContract() expects contactABIName as a string");
        }

		var readOnly = false;

		if (Q.isPlainObject(chainId)) {
			readOnly = chainId.readOnly;
			chainId = chainId.chainId;
		}

		if (typeof chainId !== 'string'
		 || chainId.substring(0, 2) !== '0x') {
			if (!callback) {
				callback = chainId;
			}
			chainId = null;
		}

		var chainPromise = chainId
			? Promise.resolve(chainId)
			: Web3.getChainId();

		return chainPromise.then(function (chainId) {

			var contracts = Web3.contracts[contractABIName];

			if (Q.isEmpty(contracts)) {
				throw new Q.Exception(
					"Users.Web3.getFactory: missing contract address for "
					+ contractABIName
				);
			}

			var contractAddress = contracts[chainId] || contracts['all'];

            var args = [contractABIName, {
                chainId: chainId,
                contractAddress: contractAddress,
                readOnly: readOnly
            }];
            if (callback) {
                args.push(callback);
            }
			return Web3.getContract.apply(Web3, args);

		});

	};

});
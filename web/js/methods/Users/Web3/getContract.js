Q.exports(function (Users, priv) {

	/**
	 * Fetch an ethers.Contract instance.
	 *
	 * @method getContract
	 * @static
	 * @param {String} contractABIName
	 * @param {String|Object} contractAddress
	 *   If object:
	 *     {
	 *       contractAddress: "0x...",
	 *       chainId: "0x...",
	 *       readOnly: true
	 *     }
	 * @param {Function} [callback]
	 *   Receives (err, contract)
	 * @return {Promise}
	 */
	return Q.getter(
	function _Web3_getContract(contractABIName, contractAddress, callback) {

		if (typeof contractABIName !== 'string') {
			throw new Error("Users.Web3.getContract() expects contractABIName as string");
		}

		if (!Q.isPlainObject(contractAddress) && typeof contractAddress !== 'string') {
			throw new Error("Users.Web3.getContract() expects contractAddress as string or object");
		}

		var Web3 = Users.Web3;

		var chainId, address, readOnly = false;

		if (Q.isPlainObject(contractAddress)) {
			chainId = contractAddress.chainId;
			address = contractAddress.contractAddress;
			readOnly = !!contractAddress.readOnly;
		} else {
			address = contractAddress;
		}

		if (!address) {
			return Q.handle(callback, null, ["Users.Web3.getContract: Missing contract address"]);
		}

		// Load ABI template
		Q.Template.set(contractABIName, undefined, "abi.json");

		Q.Template.render(contractABIName, function (err, json) {

			if (err) {
				return Q.handle(callback, null, [err]);
			}

			var ABI;

			try {
				ABI = JSON.parse(json);
			} catch (e) {
				return Q.handle(callback, null, [e]);
			}

			// ===============================
			// READ-ONLY PATH (no wallet)
			// ===============================

			if (readOnly) {

				var proceed = function (resolvedChainId) {

					try {
						var provider = Web3.getBatchProvider(resolvedChainId);
						var contract = new ethers.Contract(address, ABI, provider);
						contract.ABI = ABI;

						Q.handle(callback, null, [null, contract]);

					} catch (error) {
						Q.handle(callback, null, [error]);
					}
				};

				if (chainId) {
					return proceed(chainId);
				}

				return Web3.getChainId()
					.then(proceed)
					.catch(function (error) {
						Q.handle(callback, null, [error]);
					});
			}

			// ===============================
			// SIGNER PATH (wallet required)
			// ===============================

			Web3.withChain(chainId, function (err, provider) {

				if (err) {
					return Q.handle(callback, null, [err]);
				}

				try {
					var signer = new ethers.providers.Web3Provider(provider).getSigner();
					var contract = new ethers.Contract(address, ABI, signer);
					contract.ABI = ABI;

					Q.handle(callback, null, [null, contract]);

				} catch (error) {
					Q.handle(callback, null, [error]);
				}
			});
		});
	},
	{
		cache: Q.Cache.document("Users.Web3.getContract"),
		resolveWithSecondArgument: true
	});

});
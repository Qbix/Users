Q.exports(function (Users, priv) {

	/**
	 * Execute method on contract
	 *
	 * @method execute
	 * @static
	 * @param {string} contractABIName
	 * @param {string|Object} contractAddress
	 * @param {string} methodName
	 * @param {Array} params
	 * @param {Function} [callback]
	 *   Receives (err, result)
	 * @return {Promise}
	 *   Resolves with result or rejects with error
	 */
	return Q.promisify(function _Web3_execute(
		contractABIName,
		contractAddress,
		methodName,
		params,
		callback
	) {
        var Web3 = Users.Web3;
		Web3.getContract(
			contractABIName,
			contractAddress,
			function (err, contract) {

				if (err) {
					return Q.handle(callback, null, [err]);
				}

				if (!contract[methodName]) {

					var possibilities = [];
					var m = methodName && methodName.match(/[A-Za-z1-9]+/);

					if (m) {
						for (var k in contract) {
							if (k.startsWith(m[0])) {
								possibilities.push(k);
							}
						}
					}

					var error =
						"Q.Users.Web3.execute: missing method " + methodName + "\n"
						+ "But perhaps you meant these method names:\n"
						+ possibilities.join("\n");

					console.error(error);
					return Q.handle(callback, null, [error]);
				}

				contract[methodName]
					.apply(contract, params || [])
					.then(function (result) {
						Q.handle(callback, null, [null, result]);
					})
					.catch(function (error) {
						Q.handle(callback, null, [error]);
					});
			}
		);

	});

});
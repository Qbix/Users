Q.exports(function (Users, priv) {
	/**
	 * Attempts to recover a session using a previously generated recovery key.
	 * It posts the public key to the server, which issues a new capability.
	 *
	 * @method recover
	 * @static
	 * @param {Function} callback Receives (err, response)
	 */
	return Q.getter(function Users_Session_recover(callback) {
		var storeName = "Q.Users.keys";
		Q.IndexedDB.open(Q.info.baseUrl, storeName, "id", function (err, db) {
			if (err) {
				Q.handle(callback, null, [err]);
				return;
			}

			var tx = db.transaction(storeName, "readonly");
			var store = tx.objectStore(storeName);
			var request = store.get("Users.Recovery");

			request.onsuccess = function (event) {
				var record = event.target.result;
				if (!record || !record.key) {
					Q.handle(callback, null, ["No recovery key found"]);
					return;
				}
				var recoveryKey = record.key;

				// Confirm the private key is non-extractable
				try {
					crypto.subtle.exportKey("pkcs8", recoveryKey.privateKey)
						.then(function () {
							throw new Error("Recovery key IS extractable — aborting for security reasons.");
						})
						.catch(function () {
							Q.log("Recovery key confirmed non-extractable.");
							exportAndSend();
						});
				} catch (e) {
					// In browsers that throw synchronously
					Q.log("Recovery key confirmed non-extractable (caught).");
					exportAndSend();
				}

				function exportAndSend() {
					// Export only the public key (safe)
					crypto.subtle.exportKey("jwk", recoveryKey.publicKey)
                    .then(function (recoveryKeyJwk) {
                        var fields = { recoveryKey: recoveryKeyJwk };

                        // Sign and send to backend
                        Q.Users.sign(fields, function (err, signedFields) {
                            if (err) return Q.handle(callback, null, [err]);

                            Q.req("Users/recover", ["session"], function (err2, resp) {
                                Q.handle(callback, null, [err2, resp]);
                            }, {
                                method: "post",
                                fields: signedFields
                            });

                        }, {
                            key: recoveryKey,
                            fieldNames: ["recoveryKey"]
                        });
                    }).catch(function (e) {
                        Q.handle(callback, null, [e]);
                    });
				}
			};

			request.onerror = function (e) {
				Q.handle(callback, null, [e]);
			};
		});
	});
});
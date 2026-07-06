/**
 * Sendblue API v2 client (JS) — used by the delivery adapter in Node.
 * @module Sendblue
 * @class Sendblue.Client
 */
var Q = require('Q');
var Sendblue = Q.require('Sendblue');
var https = require('https');

Sendblue.Client = {

	/**
	 * @method appInfo
	 * @static
	 */
	appInfo: function (appId) {
		var r = Q.Users.appInfo('sendblue', appId, true);
		var info = r.appInfo || {};
		['apiKeyId', 'apiSecret', 'from'].forEach(function (f) {
			if (!info[f]) {
				throw new Error("Missing config Users/apps/sendblue/" + (r.appId || appId) + "/" + f);
			}
		});
		return { appId: r.appId || appId, info: info };
	},

	/**
	 * Send a message (iMessage, SMS fallback). Returns a Promise of the v2 data.
	 * @method sendMessage
	 * @static
	 */
	sendMessage: function (appId, number, content, options) {
		options = options || {};
		var info = Sendblue.Client.appInfo(appId).info;
		var body = { number: number, from_number: info.from, content: String(content) };
		['media_url', 'send_style', 'status_callback'].forEach(function (k) {
			if (options[k]) { body[k] = options[k]; }
		});
		var payload = JSON.stringify(body);
		var headers = {
			'Content-Type': 'application/json',
			'Content-Length': Buffer.byteLength(payload),
			'sb-api-key-id': info.apiKeyId,
			'sb-api-secret-key': info.apiSecret
		};
		return new Promise(function (resolve, reject) {
			var req = https.request({
				method: 'POST', hostname: 'api.sendblue.com', path: '/api/send-message',
				port: 443, headers: headers, timeout: 30000
			}, function (res) {
				var chunks = '';
				res.on('data', function (d) { chunks += d; });
				res.on('end', function () {
					var data;
					try { data = JSON.parse(chunks); } catch (e) { data = {}; }
					if (res.statusCode >= 200 && res.statusCode < 300 && data.status !== 'ERROR') {
						return resolve(data.data || data);
					}
					reject(new Error('Sendblue.Client: HTTP ' + res.statusCode + ' ' + (data.message || '')));
				});
			});
			req.on('timeout', function () { req.destroy(new Error('Sendblue.Client: timeout')); });
			req.on('error', reject);
			req.write(payload);
			req.end();
		});
	}
};

module.exports = Sendblue.Client;

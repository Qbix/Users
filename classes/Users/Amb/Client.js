/**
 * Apple Messages for Business (AMB) client.
 *
 * JS counterpart of Users_Amb_Client.php, modeled on the Telegram.Bot object:
 * one api() core plus thin send* helpers. Returns Promises.
 *
 * @module Users
 * @class Users.Amb.Client
 */
var Q = require('Q');
var Users = Q.require('Users');
var Amb = Q.require('Users/Amb');
var https = require('https');
var urlParser = require('url');

Users.Amb.Client = {

	/**
	 * Send a plain text message.
	 * @method sendMessage
	 * @static
	 * @return {Promise<Number>} resolves with the HTTP status code
	 */
	sendMessage: function (appId, xid, text, options) {
		options = options || {};
		var body = { type: 'text', v: 1, body: String(text) };
		if (options.locale) {
			body.locale = options.locale;
		}
		return Users.Amb.Client.api(appId, xid, body);
	},

	/**
	 * Send a rich link (URL card with title + image/video asset).
	 * @method sendRichLink
	 * @static
	 */
	sendRichLink: function (appId, xid, url, title, options) {
		options = options || {};
		var assets = {};
		if (options.imageData) {
			assets.image = { data: options.imageData, mimeType: options.imageMimeType || 'image/jpeg' };
		}
		if (options.videoUrl) {
			assets.video = { url: options.videoUrl, mimeType: options.videoMimeType || 'video/mp4' };
		}
		var richLinkData = { url: url, title: title };
		if (Object.keys(assets).length) {
			richLinkData.assets = assets;
		}
		return Users.Amb.Client.api(appId, xid, { type: 'richLink', v: 1, richLinkData: richLinkData });
	},

	/**
	 * Send a Quick Reply (2-5 tap options).
	 * @method sendQuickReply
	 * @static
	 * @param {Array} items each { identifier, title }
	 */
	sendQuickReply: function (appId, xid, summaryText, items, options) {
		options = options || {};
		var data = {
			version: '1.0',
			requestIdentifier: Amb.uuid(),
			quickReply: { summaryText: summaryText, items: items }
		};
		var received = options.receivedMessage || { style: 'small', title: summaryText };
		var reply = options.replyMessage || { style: 'small', title: summaryText };
		return Users.Amb.Client.sendInteractive(appId, xid, data, received, reply);
	},

	/**
	 * Send a List Picker (single/multi-select list).
	 * @method sendListPicker
	 * @static
	 * @param {Array} sections each { title, order, multipleSelection, items:[...] }
	 */
	sendListPicker: function (appId, xid, sections, options) {
		options = options || {};
		var data = {
			version: '1.0',
			requestIdentifier: Amb.uuid(),
			listPicker: { sections: sections }
		};
		if (options.images) {
			data.images = options.images;
		}
		var received = options.receivedMessage || { style: 'small', title: 'Choose' };
		var reply = options.replyMessage || { style: 'small', title: 'Selection' };
		return Users.Amb.Client.sendInteractive(appId, xid, data, received, reply);
	},

	/**
	 * Send a proactive, template-based notification.
	 * Requires an approved template in Apple Business Register; adds the
	 * "message-type: notification" header.
	 * @method sendNotification
	 * @static
	 */
	sendNotification: function (appId, xid, templateId, notification, options) {
		options = options || {};
		var data = {
			version: '1.0',
			requestIdentifier: Amb.uuid(),
			notification: Q.extend({
				templateId: templateId,
				referenceId: options.referenceId || Amb.uuid()
			}, notification || {})
		};
		return Users.Amb.Client.sendInteractive(appId, xid, data, null, null, {
			'message-type': 'notification'
		});
	},

	/**
	 * Send a typing indicator (typing_start / typing_end).
	 * @method sendTyping
	 * @static
	 */
	sendTyping: function (appId, xid, active) {
		return Users.Amb.Client.api(appId, xid, {
			type: (active === false) ? 'typing_end' : 'typing_start', v: 1
		});
	},

	/**
	 * Wrap an interactiveData payload and send it. receivedMessage/replyMessage
	 * sit alongside "data" inside "interactiveData", per Apple's native schema.
	 * @method sendInteractive
	 * @static
	 */
	sendInteractive: function (appId, xid, data, received, reply, extraHeaders) {
		var interactiveData = { bid: Amb.BID, data: data };
		if (received) { interactiveData.receivedMessage = received; }
		if (reply) { interactiveData.replyMessage = reply; }
		return Users.Amb.Client.api(appId, xid, {
			type: 'interactive', v: 1, interactiveData: interactiveData
		}, extraHeaders);
	},

	/**
	 * The one core call: sign a JWT, set headers, POST JSON to /v1/message.
	 * Success is an HTTP 2xx with an empty body.
	 * @method api
	 * @static
	 * @return {Promise<Number>} resolves with the HTTP status code, or rejects
	 *   with an Error carrying .rejected / .rateLimited flags.
	 */
	api: function (appId, xid, body, extraHeaders) {
		var creds = Amb.appInfo(appId);
		var info = creds.info;
		var id = Amb.uuid();

		body = Q.extend({ id: id, sourceId: info.businessId, destinationId: xid }, body);
		var payload = JSON.stringify(body);

		var headers = Q.extend({
			'Authorization': Amb.authorizationHeader(info),
			'id': id,
			'Source-Id': info.businessId,
			'Destination-Id': xid,
			'Content-Type': 'application/json',
			'Content-Length': Buffer.byteLength(payload),
			'Accept': 'application/json'
		}, extraHeaders || {});

		var u = urlParser.parse(Amb.endpoint(info));

		return new Promise(function (resolve, reject) {
			var req = https.request({
				method: 'POST',
				hostname: u.hostname,
				port: u.port || 443,
				path: u.path,
				headers: headers,
				timeout: 30000
			}, function (res) {
				var chunks = '';
				res.on('data', function (d) { chunks += d; });
				res.on('end', function () {
					var code = res.statusCode;
					if (code >= 200 && code < 300) {
						return resolve(code);
					}
					// 404 => unreachable via AMB; 429 => rate limited
					var err = new Error('Users.Amb.Client: HTTP ' + code + ' ' + chunks.slice(0, 200));
					if (code === 404 || code === 403) { err.rejected = true; }
					if (code === 429) { err.rateLimited = true; }
					reject(err);
				});
			});
			req.on('timeout', function () { req.destroy(new Error('Users.Amb.Client: timeout')); });
			req.on('error', reject);
			req.write(payload);
			req.end();
		});
	}
};

module.exports = Users.Amb.Client;

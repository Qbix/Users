/**
 * Apple Messages for Business (AMB) model.
 * JS counterpart of Amb.php. Named after the platform, ships in the Users plugin.
 * Sending lives in Amb.Client (Amb/Client.js); delivery in Users.ExternalFrom.Amb.
 *
 * @module Amb
 * @class Amb
 */
var Q = require('Q');
var Users = Q.require('Users');
var crypto = require('crypto');

var Amb = {

	BID: 'com.apple.messages.MSMessageExtensionBalloonPlugin:0000000000:com.apple.icloud.apps.messages.business.extension',

	ENDPOINT_PRODUCTION: 'https://mspgw.push.apple.com/v1/message',
	ENDPOINT_STAGING: 'https://mspgw-int.push.apple.com/v1/message',

	/**
	 * Required opt-in disclosure text keyed by 2-letter language. Add languages.
	 * @property NOTICE
	 */
	NOTICE: {
		en: "We will send important notifications related to your account status or transactions. Send 'Unsubscribe' to manage your message preferences."
	},

	/**
	 * Read and validate config for an AMB app.
	 * @method appInfo
	 * @static
	 * @return {Object} { appId, info }
	 */
	appInfo: function (appId) {
		var r = Q.Users.appInfo('amb', appId, true);
		var info = r.appInfo || {};
		['mspId', 'businessId', 'secret'].forEach(function (field) {
			if (!info[field]) {
				throw new Error("Missing config Users/apps/amb/" + (r.appId || appId) + "/" + field);
			}
		});
		return { appId: r.appId || appId, info: info };
	},

	/**
	 * @method endpoint
	 * @static
	 */
	endpoint: function (info) {
		return info.endpoint || Amb.ENDPOINT_PRODUCTION;
	},

	/**
	 * The required opt-in disclosure, in the customer's language.
	 * @method notificationNotice
	 * @static
	 */
	notificationNotice: function (locale) {
		var lang = locale ? String(locale).slice(0, 2).toLowerCase() : 'en';
		return Amb.NOTICE[lang] || Amb.NOTICE.en;
	},

	/**
	 * "Authorization: Bearer <jwt>" for OUTBOUND messages.
	 * @method authorizationHeader
	 * @static
	 */
	authorizationHeader: function (info) {
		var key = Buffer.from(info.secret, 'base64');
		var header = Amb.base64url(Buffer.from(JSON.stringify({ alg: 'HS256' })));
		var claims = Amb.base64url(Buffer.from(JSON.stringify({
			iss: info.mspId,
			iat: Math.floor(Date.now() / 1000)
		})));
		var signingInput = header + '.' + claims;
		var sig = Amb.base64url(crypto.createHmac('sha256', key).update(signingInput).digest());
		return 'Bearer ' + signingInput + '.' + sig;
	},

	/**
	 * Verify the Authorization header on an INBOUND request from Apple.
	 * @method verifyAuthorization
	 * @static
	 * @return {Boolean}
	 */
	verifyAuthorization: function (appId, authorizationHeader) {
		var jwt = String(authorizationHeader || '').replace(/^\s*Bearer\s+/i, '').trim();
		var parts = jwt.split('.');
		if (parts.length !== 3) {
			return false;
		}
		var info = Amb.appInfo(appId).info;
		var key = Buffer.from(info.secret, 'base64');
		var expected = Amb.base64url(
			crypto.createHmac('sha256', key).update(parts[0] + '.' + parts[1]).digest()
		);
		var a = Buffer.from(expected), b = Buffer.from(parts[2]);
		if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
			return false;
		}
		var claims;
		try {
			claims = JSON.parse(Amb.base64urlDecode(parts[1]).toString('utf8'));
		} catch (e) {
			return false;
		}
		if (claims.aud !== info.mspId) {
			return false;
		}
		return Math.abs(Math.floor(Date.now() / 1000) - (parseInt(claims.iat) || 0)) <= 3600;
	},

	/**
	 * @method uuid
	 * @static
	 */
	uuid: function () {
		return crypto.randomUUID
			? crypto.randomUUID()
			: 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
				var r = Math.random() * 16 | 0;
				return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
			});
	},

	/**
	 * @method base64url
	 * @static
	 */
	base64url: function (data) {
		var b = Buffer.isBuffer(data) ? data : Buffer.from(String(data));
		return b.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	},

	/**
	 * @method base64urlDecode
	 * @static
	 */
	base64urlDecode: function (data) {
		var s = String(data).replace(/-/g, '+').replace(/_/g, '/');
		while (s.length % 4) { s += '='; }
		return Buffer.from(s, 'base64');
	}
};

module.exports = Amb;

Q.require('Amb/Client');

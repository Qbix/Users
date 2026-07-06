/**
 * Apple Messages for Business (AMB) model.
 *
 * JS counterpart of Users_Amb.php: shared config + crypto for the AMB channel.
 * Sending lives in Users.Amb.Client (see Amb/Client.js), delivery in
 * Users.ExternalFrom.Amb (see ExternalFrom/Amb.js).
 *
 * @module Users
 * @class Users.Amb
 */
var Q = require('Q');
var Users = Q.require('Users');
var crypto = require('crypto');

var Users_Amb = {

	BID: 'com.apple.messages.MSMessageExtensionBalloonPlugin:0000000000:com.apple.icloud.apps.messages.business.extension',

	ENDPOINT_PRODUCTION: 'https://mspgw.push.apple.com/v1/message',
	ENDPOINT_STAGING: 'https://mspgw-int.push.apple.com/v1/message',

	/**
	 * Read and validate config for an AMB app.
	 * @method appInfo
	 * @static
	 * @param {String} appId
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
	 * The endpoint URL for an app (config override or production default).
	 * @method endpoint
	 * @static
	 */
	endpoint: function (info) {
		return info.endpoint || Users_Amb.ENDPOINT_PRODUCTION;
	},

	/**
	 * "Authorization: Bearer <jwt>" value for OUTBOUND messages.
	 * claims { iss: MSP-ID, iat: unix seconds }, HS256, secret is Base64-decoded.
	 * @method authorizationHeader
	 * @static
	 */
	authorizationHeader: function (info) {
		var key = Buffer.from(info.secret, 'base64');
		var header = Users_Amb.base64url(Buffer.from(JSON.stringify({ alg: 'HS256' })));
		var claims = Users_Amb.base64url(Buffer.from(JSON.stringify({
			iss: info.mspId,
			iat: Math.floor(Date.now() / 1000)
		})));
		var signingInput = header + '.' + claims;
		var sig = Users_Amb.base64url(
			crypto.createHmac('sha256', key).update(signingInput).digest()
		);
		return 'Bearer ' + signingInput + '.' + sig;
	},

	/**
	 * Verify the Authorization header on an INBOUND request from Apple.
	 * Inbound claims: { aud: <our MSP-ID>, iat }.
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
		var info = Users_Amb.appInfo(appId).info;
		var key = Buffer.from(info.secret, 'base64');
		var expected = Users_Amb.base64url(
			crypto.createHmac('sha256', key).update(parts[0] + '.' + parts[1]).digest()
		);
		var a = Buffer.from(expected), b = Buffer.from(parts[2]);
		if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
			return false;
		}
		var claims;
		try {
			claims = JSON.parse(Users_Amb.base64urlDecode(parts[1]).toString('utf8'));
		} catch (e) {
			return false;
		}
		if (claims.aud !== info.mspId) {
			return false;
		}
		return Math.abs(Math.floor(Date.now() / 1000) - (parseInt(claims.iat) || 0)) <= 3600;
	},

	/**
	 * Generate a version-4 UUID.
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
	 * URL-safe base64 without padding.
	 * @method base64url
	 * @static
	 * @param {Buffer|String} data
	 */
	base64url: function (data) {
		var b = Buffer.isBuffer(data) ? data : Buffer.from(String(data));
		return b.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	},

	/**
	 * Decode URL-safe base64 to a Buffer.
	 * @method base64urlDecode
	 * @static
	 */
	base64urlDecode: function (data) {
		var s = String(data).replace(/-/g, '+').replace(/_/g, '/');
		while (s.length % 4) { s += '='; }
		return Buffer.from(s, 'base64');
	}
};

module.exports = Users.Amb = Users_Amb;

Q.require('Users/Amb/Client');

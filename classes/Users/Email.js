/**
 * Class representing email rows.
 *
 * @module Users
 */
var Q = require('Q');
var Db = Q.require('Db');
var Users = Q.require('Users');

/**
 * Class representing 'Email' rows in the 'Users' database
 * @namespace Users
 * @class Email
 * @extends Base.Users.Email
 * @constructor
 * @param fields {object} The fields values to initialize table row as
 * an associative array of `{column: value}` pairs
 */
function Users_Email (fields) {

	// Run constructors of mixed in objects
	Users_Email.constructors.apply(this, arguments);

	/*
	 * Add any other methods to the model class by assigning them to this.
	 
	 * * * */

	/* * * */
}

Q.mixin(Users_Email, Q.require('Base/Users/Email'));

/**
 * Send e-mail message
 * @method sendMessage
 * @static
 * @param {String} to Comma-separated list of emails
 * @param {String|Array} subject
 *  The subject. May contain variable references to members of the $fields array.
 *  You can also pass an array like [source, [key1, ...]] to use Q_Text to obtain
 *  the subject.
 * @param {String} view The name of a view for the body. Fields are passed to it.
 * @param {Object} [fields={}] The fields referenced in the subject and/or view
 * @param {Object} [options={}]
 * @param {String} [options.html=false] Whether to send as HTML email.
 * @param {String} [options.name] A human-readable name in addition to the address to send to.
 * @param {Array} [options.from] An array of [emailAddress, humanReadableName].
 * @param {String} [options.language] Preferred language to be used for the view
 * @param {Boolean} [options.isSource] If true, the view parameter contains the exact source, not the path of the template
 * @param {function} callback Receives error, method used and response objects after complete
 */
var _transport = null;
Users_Email.sendMessage = function (to, subject, view, fields, options, callback) {
	var mailer = require('nodemailer');
	var handlebars = require('handlebars');
	var key = Q.Config.get(['Users', 'email', 'log', 'key'], 'email');

	if (typeof fields === 'function') {
		callback = fields;
		options = fields = {};
	} else if (typeof options === "function") {
		callback = options;
		options = {};
	}

	fields = fields || {};
	options = options || {};

	var from = options.from || Q.Config.get(['Users', 'email', 'from'], null);
	if (!from) {
		var app = Q.Config.expect(['Q', 'app']);
		var appUrl = Q.Config.get(["Q", "web", "appRootUrl"], null);
		if (!app || !appUrl) return false;
		from = [app+'@'+appUrl.parseUrl('host'), app];
	}
	if (typeof from === "string") from = [from, Q.Config.expect(['Q', 'app'])];

	// if subject is object - get subject from text file
	if (typeof subject === 'object') {
		var text = Q.Text.get(subject[0], options.language);
		subject = Q.getObject(subject[1], text);
	}

	var htmlEntities = require('html-entities').AllHtmlEntities;
	var entities = new htmlEntities();

	subject = entities.decode(Q.Handlebars.renderSource(subject, fields));
	var body = options.isSource
		? Q.Handlebars.renderSource(view, fields)
		: Q.view(view, fields, { language: options.language });
	if (!options.html) {
		body = entities.decode(body);
	}

	var mailOptions = {
		from: from[1]+' <'+from[0]+'>',
		to: to,
		subject: subject
	};
	mailOptions[options.html ? 'html' : 'text'] = body;

	var smtp = Q.Config.get(['Users', 'email', 'smtp'], {host: 'sendmail'});
	if (!_transport && smtp) {
		// Set up the default mail transport
		var host = smtp.host || 'sendmail';
		if (host === "sendmail") {
			var sendmailTransport = require("nodemailer-sendmail-transport");
			_transport = mailer.createTransport(sendmailTransport());
		} else {
			var smtpTransport = require("nodemailer-smtp-transport");
			host = {
				host: host
			};
			// TLS mode. Users_Email.php hands the same `smtp` config to
			// Zend_Mail_Transport_Smtp, whose Zend_Mail_Protocol_Smtp
			// constructor defines `ssl` as a three-valued string -- absent
			// (plaintext, opportunistic STARTTLS), "tls" (STARTTLS required)
			// and "ssl" (implicit TLS) -- and raises on any other value. Map
			// those three onto nodemailer's own option names so that one
			// config key means one thing in both halves of the platform.
			//
			// Previously this set `secureConnection`, which is nodemailer 1.x
			// naming and is not read by any client this code has run against:
			// smtp-connection (used by nodemailer-smtp-transport) and
			// nodemailer's own client both do
			//   this.secureConnection = !!this.options.secure;
			// so `secureConnection` is an output of parsing `secure`, never an
			// input. The result was that `ssl` did nothing here -- the
			// connection was made in plaintext, and only opportunistic STARTTLS
			// kept that from being obvious. Against a server that speaks
			// implicit TLS (port 465) there is no fallback at all: the client
			// waits for a banner that never arrives.
			var ssl = (smtp.ssl === true) ? "ssl" : smtp.ssl;
			ssl = ssl ? String(ssl).toLowerCase() : null;
			switch (ssl) {
				case null:
					host.secure = false;
					break;
				case "tls":
					host.secure = false;
					host.requireTLS = true;
					break;
				case "ssl":
					host.secure = true;
					break;
				default:
					throw new Q.Exception(
						smtp.ssl + ' is unsupported SSL type in Users/email/smtp.ssl'
						+ ' (expected "tls", "ssl", or nothing)'
					);
			}
			// State the port rather than letting the client derive one from
			// `secure`, so that honouring the flag above cannot silently move
			// the connection to a different port. These defaults are Zend's.
			host.port = smtp.port || (host.secure ? 465 : 25);
			// So that implicit TLS pointed at a plaintext listener reaches the
			// callback as an error instead of sitting on the socket.
			host.connectionTimeout = smtp.connectionTimeout || 15000;
			host.greetingTimeout = smtp.greetingTimeout || 15000;
			if (smtp.auth === "login") {
				host.auth = {
					user: smtp.username,
					pass: smtp.password
				};
			}
			_transport = mailer.createTransport(smtpTransport(host));
		}
	}
	
	var logContent = 'Sent email message to ' + to
		+ ":\n" + mailOptions.subject
		+ "\n" + (mailOptions.html || mailOptions.text);
	if (_transport) {
		_transport.sendMail(mailOptions, function (err, response) {
			callback(err, 'smtp', response);
		});
	} else {
		logContent = 'Would have ' + logContent;
		setTimeout(function () {
			callback(null, 'log');
		}, 0);
	}
	if (key) {
		Q.log(logContent, key);
	}
};

/**
 * The setUp() method is called the first time
 * an object of this class is constructed.
 * @method setUp
 */
Users_Email.prototype.setUp = function () {
	// put any code here
	// overrides the Base class
};

/**
 * Send e-mail message
 * @method sendMessage
 * @param {string} subject
 *  The subject. May contain variable references to members
 *  of the $fields array.
 * @param {string} view
 *  The name of a view for the body. Fields are passed to it.
 * @param {array} fields={}
 *  Optional. The fields referenced in the subject and/or view
 * @param {array} $options={}
 *  Optional. Array of options. Can include:<br/>
 *  "html" => Defaults to false. Whether to send as HTML email.<br/>
 *  "from" => An array of emailAddress, human_readable_name<br/>
 * @param {function} callback Receives error and response objects after complete
 */
Users_Email.prototype.sendMessage = function(subject, view, fields, options, callback) {
	if (typeof fields === 'function') {
		callback = fields;
		options = fields = {};
	} else if (typeof options === "function") {
		callback = options;
		options = {};
	}
	Users.Email.sendMessage(this.address, subject, view, fields, options, callback);
};

module.exports = Users_Email;
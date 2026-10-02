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
			// nodemailer's own sendmail transport, replacing the deprecated
			// nodemailer-sendmail-transport@1.0.2 shim. The shim's defaults
			// (binary "sendmail", args "-i -f <envelope.from> <envelope.to...>")
			// are already what the built-in does with no options. The one thing
			// it did that the built-in does NOT do by default is rewrite the
			// message's CRLF line endings to LF before piping to the binary --
			// that is what `newline: 'unix'` restores here. Dropping it would
			// hand sendmail a raw CRLF stream.
			_transport = mailer.createTransport({
				sendmail: true,
				newline: 'unix'
			});
		} else {
			// nodemailer's own SMTP transport, replacing the deprecated
			// nodemailer-smtp-transport@2.7.4 shim -- whose only real job was
			// to hand these same options to its bundled smtp-connection@2.12,
			// and which dragged in the vulnerable
			// smtp-connection -> httpntlm -> underscore subtree.
			var smtpOptions = {
				host: host
			};
			// smtp-connection@2.12 defaulted the unencrypted port to 25;
			// nodemailer's own client defaults it to 587. Pin 25 explicitly so
			// removing the shim cannot silently move the port out from under an
			// existing config, and so the node side keeps agreeing with
			// Users_Email.php's Zend_Mail_Transport_Smtp (also port 25).
			smtpOptions.port = smtp.port || 25;
			if (smtp.auth === "login") {
				if (smtp.ssl) {
					// GOTCHA: this option is dead, and is preserved verbatim on
					// purpose. Neither smtp-connection@2.12 (what the shim used)
					// nor nodemailer's own client ever reads `secureConnection` --
					// both read `secure` -- so a Users/email/smtp config with
					// "ssl" set has never actually turned on implicit TLS here.
					// Renaming it to `secure` is a real behaviour change (it would
					// flip a live deployment from plaintext to TLS and, with no
					// port set, from 25 to 465), so it belongs in its own issue
					// rather than in this shim removal.
					smtpOptions.secureConnection = true;
				}
				smtpOptions.auth = {
					user: smtp.username,
					pass: smtp.password
				};
			}
			_transport = mailer.createTransport(smtpOptions);
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
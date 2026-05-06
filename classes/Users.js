"use strict";
/*jshint node:true */
/**
 * Users plugin
 * @module Users
 * @main Users
 */
var Q = require('Q');

/**
 * Static methods for the Users model
 * @class Users
 * @extends Base.Users
 * @static
 */
function Users() { }
module.exports = Users;

var Base_Users = require('Base/Users');
Q.mixin(Users, Base_Users);

/*
 * This is where you would place all the static methods for the models,
 * the ones that don't strongly pertain to a particular row or table.
 * Just assign them as methods of the Users object.
 
 * * * */

var db = Q.require('Db').connect('Users');
var querystring = require('querystring');
var util = require('util');

Q.makeEventEmitter(Users);

/**
 * Store user sessions
 * @property sessions
 * @type {Object}
 */
// Users.sessions = {};

/**
 * Store clients
 * @property clients
 * @type {Object}
 */ 
Users.clients = {};

/**
 * Get the id of the main community from the config. Defaults to the app name.
 * @return {String} The id of the main community for the installed app.
 */
Users.communityId = function() {
	var communityId = Q.Config.get(['Users', 'community', 'id'], null);
	return communityId ? communityId : Q.Config.expect(['Q', 'app']);
};

/**
 * Get the name of the main community from the config. Defaults to the app name.
 * @return {String} The name of the main community for the installed app.
 */
Users.communityName = function() {
	var communityName = Q.Config.get(['Users', 'community', 'name'], null);
	return communityName ? communityName : Q.Config.expect(['Q', 'app']);
};

/**
 * Get the suffix of the main community from the config, such as "Incorporated" or "LLC"
 * @return {String|null} The suffix of the main community for the installed app.
 */
Users.communitySuffix = function() {
	return Q.Config.get(['Users', 'community', 'suffix'], null);
};

// /**
//  * Gets a user (from database if needed) associated with sessionId and passes it to callback.
//  * @method userFromSession
//  * @param sessionId {string}
//  *	User's session Id
//  * @param callback {function}
//  *  Passes a Users.User object, or null if the the user wasn't found
//  */
// Users.userFromSession = function (sessionId, callback) {
// 	if (Users.sessions[sessionId]) {
// 		var user = Q.getObject([sessionId, 'Users', 'loggedInUser'], Users.sessions) || null;
// 		callback && callback(user);
// 	} else {
// 		Users.Session.SELECT('*').where({
// 			id: sessionId
// 		}).execute(function(err, results){
// 			if (!results || results.length === 0) {
// 				return callback(null, null);
// 			}
// 			if (results[0].fields.content === undefined) {
// 				Q.log(err, results);
// 				throw new Q.Error("Users.userFromSession session.fields.content is undefined");
// 			}
// 			var sess = JSON.parse(results[0].fields.content);
//
// 			if (!Q.isSet(sess, ['Users', 'loggedInUser'])) {
// 				callback(null);
// 			} else {
// 				Users.sessions[sessionId] = { Users: sess.Users };
// 				callback(sess.Users.loggedInUser, sess.Q && sess.Q.nonce);
// 			}
// 		});
// 	}
// };

/**
 * Start internal listener for Users plugin and open socket.
 *
 * Registers Q/method handlers via the framework's server.addMethod() API.
 * The framework's IPC dispatcher (Q.listen) owns /Q/node, routes by
 * Q/method, and gates on req.internal + req.validated before invoking
 * any handler here — so handlers don't need to re-check those.
 *
 * Handler signature: function (parsed, req, res, ctx)
 *   - `parsed` is req.body (the signed internal payload)
 *   - `req`, `res` are the Express request/response (rarely needed)
 *   - `ctx` carries IPC metadata (jobId, done) when the caller used
 *     Q_Utils::sendToNode with 'job'=>true + 'webhook'=>...
 *   Most handlers only need `parsed`.
 *
 * @method listen
 * @param {Object} [options={}]
 * @param {Object} [options.apn.provider={}] Additional options for node-apn Provider
 * @param {String} [options.apn.appId=Q.app.name] Only needed if you have multiple ios platform apps
 */
Users.listen = function (options) {

	var o = Q.extend({}, Users.listen.options, options);

	// Start internal server
	var server = Q.listen();

	// Users/device — no-op placeholder (matches legacy behavior: empty case).
	server.addMethod('Users/device', function () {});

	// Users/setLoggedInUser — attach a socket.io client to a newly authenticated user.
	server.addMethod('Users/setLoggedInUser', function (parsed) {
		var userId = parsed.userId;
		var sessionId = parsed.sessionId;
		var clientId = parsed.clientId;
		if (!clientId || !userId) {
			return;
		}
		var nsp = Q.Socket.io.of('/Q');
		var client = nsp.sockets.get(clientId);
		if (!client) {
			return;
		}
		// remove from previous user mapping
		for (var uid in Users.clients) {
			if (Users.clients[uid] && Users.clients[uid][clientId]) {
				delete Users.clients[uid][clientId];
			}
		}
		client.userId = userId;
		client.sessionId = sessionId;
		if (!Users.clients[userId]) {
			Users.clients[userId] = {};
		}
		Users.clients[userId][clientId] = client;
		Q.log("Socket upgraded to user " + userId + " (" + clientId + ")");
	});

	// Users/logout — disconnect sockets for a session and clear push badge.
	server.addMethod('Users/logout', function (parsed) {
		var userId = parsed.userId;
		var sessionId = parsed.sessionId;
		if (!userId) {
			return;
		}
		if (sessionId) {
			var clients = Users.clients[userId];
			for (var cid in clients) {
				if (clients[cid] && clients[cid].sessionId === sessionId) {
					clients[cid].disconnect();
				}
			}
		}
		Users.pushNotifications(userId, {
			badge: 0
		});
	});

	// Users/sendMessage — send a view-rendered message via email/SMS.
	//
	// NOTE: the legacy handler references an undeclared function `_send`.
	// This is preserved verbatim from the original switch case — if the
	// code path is ever exercised it will throw ReferenceError, exactly as
	// before. Define `_send` in scope if you want this to actually work.
	server.addMethod('Users/sendMessage', function (parsed) {
		if (parsed.delay) {
			setTimeout(_send, parsed.delay);
		} else {
			_send();
		}
	});

	// Users/addEventListener — attach an external callback to a socket event.
	server.addMethod('Users/addEventListener', function (parsed) {
		var userId = parsed.userId;
		var socketId = parsed.socketId;
		if (!userId || !socketId) {
			return;
		}
		var clients = Users.clients[userId];
		var client = null;
		for (var cid in clients) {
			if (!clients[cid] || !clients[cid].id) continue;
			if (clients[cid].id === socketId) {
				client = clients[cid];
				break;
			}
		}
		if (!client) {
			return;
		}
		var eventName = parsed.eventName;
		var handlerToExecute = parsed.handlerToExecute;
		if (!eventName || !handlerToExecute) {
			return;
		}
		var evtData = parsed.data;
		if (typeof evtData === 'string') {
			try { evtData = JSON.parse(evtData); } catch(e) { evtData = {}; }
		}
		evtData = evtData || {};

		(function(capturedClient, capturedData, capturedHandler) {
			capturedClient.on(eventName, function() {
				var headers = {
					'user-agent': 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.9) Gecko/20071025 Firefox/2.0.0.9',
					'cookie': capturedClient.handshake.headers.cookie
				};
				Q.Utils.queryExternal(capturedHandler, capturedData, null, headers)
				.catch(function(err) {
					Q.log('Users/addEventListener queryExternal error: ' + err.message);
				});
			});
		})(client, evtData, handlerToExecute);
	});

	// Users/checkIfOnline — report whether a target user's socket is connected,
	// fanning the result back through the operator's session cookie.
	server.addMethod('Users/checkIfOnline', function (parsed) {
		var userId = parsed.userId;
		var socketId = parsed.socketId;
		var operatorUserId = parsed.operatorUserId;
		var operatorSocketId = parsed.operatorSocketId;
		if (!userId || !socketId || !operatorUserId || !operatorSocketId) {
			return;
		}
		var checkHandler = parsed.handlerToExecute;
		if (!checkHandler) {
			return;
		}
		var checkData = parsed.data;
		if (typeof checkData === 'string') {
			try { checkData = JSON.parse(checkData); } catch(e) { checkData = {}; }
		}
		checkData = checkData || {};

		(function(uid, sid, opUid, opSid, data, handler) {
			// find the target user's socket
			var targetClient = null;
			var targetClients = Users.clients[uid] || {};
			for (var c in targetClients) {
				if (targetClients[c] && targetClients[c].id === sid) {
					targetClient = targetClients[c];
					break;
				}
			}
			data.userIsOnline = targetClient ? 'true' : 'false';

			// find the operator's socket for cookie forwarding
			var opClient = null;
			var opClients = Users.clients[opUid] || {};
			for (var oc in opClients) {
				if (opClients[oc] && opClients[oc].id === opSid) {
					opClient = opClients[oc];
					break;
				}
			}
			var headers = opClient ? {
				'user-agent': 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.9) Gecko/20071025 Firefox/2.0.0.9',
				'cookie': opClient.handshake.headers.cookie
			} : {};

			Q.Utils.queryExternal(handler, data, null, headers)
			.catch(function(err) {
				Q.log('Users/checkIfOnline queryExternal error: ' + err.message);
			});
		})(userId, socketId, operatorUserId, operatorSocketId, checkData, checkHandler);
	});

	// Users/emitToUser — fan out a socket.io event to all of a user's clients.
	server.addMethod('Users/emitToUser', function (parsed) {
		Users.Socket.emitToUser(parsed.userId, parsed.event, parsed.data);
	});

	// Users/intentComplete — notify a user's clients that an intent finished.
	server.addMethod('Users/intentComplete', function (parsed) {
		if (parsed.userId) {
			Users.Socket.emitToUser(parsed.userId, 'Users/intentComplete', {
				token: parsed.token,
				sessionId: parsed.sessionId
			});
		}
	});
};

Users.listen.options = {};

/**
 * Fetches a user from the database
 * @method listen
 * @param {object} options={}
 *  So far no options are implemented.
 */
Users.fetch = function (id, callback) {
	new Users.User({id: id}).retrieve(callback);
};

/**
 * Calculate the url of a user's icon
 * @method
 * @param {String} icon the value of the user's "icon" field
 * @param {String|Number|Boolean} [basename=40] The last part after the slash, such as "50.png" or "50". Pass true to get the largest size. Setting it to false skips appending "/basename"
 * @return {String} the url
 */
Users.iconUrl = function Users_iconUrl(icon, basename) {
	if (!icon) {
		console.warn("Users.iconUrl: icon is empty");
		return '';
	}
	var src = Q.interpolateUrl(icon);
	if (!src.isUrl() && icon.substring(0, 2) !== '{{') {
		src = '{{Users}}/img/icons/'+src;
	}
	if (basename !== false) {
		if (basename === null || basename === undefined) {
			basename = '40';
		}
		if (basename === true) {
			basename = Q.Image.largestSize('Users/icon');
		}
		if (String(basename).indexOf('.') < 0) {
			basename += '.png';
		}
		src += '/' + basename;
	}
	return Q.url(src);
};

/**
 * Pushes notifications to all devices of the given user or users
 * @method pushNotifications
 * @static
 * @param {String|Array} userIds A user id, or an array of them, 
 *   in which case notifications would be an object of { userId: notification }
 * @param {Object} notifications If userIds is an array, this is a hash of {userId: notification} objects, otherwise it is just a single notification object. Please see Users.Device.prototype.pushNotification for the schema of this object.
 * @param {Function} [callback] A function to call after the push has been completed
 * @param {Function} [options] Any additional options to pass to device.pushNotification method
 * @param {Function} [filter] Receives the Users.Device object. Return false to skip the device.
 */
Users.pushNotifications = function (userIds, notifications, callback, options, filter) {
	var isArrayLike = Q.isArrayLike(userIds);
	Users.Device.SELECT('*').where({
		userId: userIds
	}).execute(function (err, devices) {
		if (err) {
			return callback && callback(err);
		}
		Q.each(devices, function (i) {
			if (filter && filter(this) === false) {
				return;
			}
			this.pushNotification(
				isArrayLike ? notifications[this.fields.userId] : notifications,
				options
			);
		});
		Q.handle(callback, Users, [null, devices, notifications]);
	});
};

/**
 * Get the internal app id and info
 * @method appId
 * @static
 * @param {String} platform The platform or platform for the app
 * @param {String} [appId=Q.app.name] Can be either an internal or external app id
 * @return {Object} Has keys "appId" and "appInfo"
 */
Users.appInfo = function (platform, appId)
{
	if (!appId) {
		appId = Q.app.name;
	}
	var apps = Q.Config.get(['Users', 'apps', platform], []);
	var appInfo, id;
	if (apps[appId]) {
		appInfo = apps[appId];
	} else {
		id = null;
		for (var k in apps) {
			var v = apps[k];
			if (v.appId === appId) {
				appInfo = v;
				id = k;
				break;
			}
		}
		appId = id;
	}
	return {
		appId: appId,
		appInfo: appInfo
	};
};

var timeouts = {};

/**
 * Replacements for Q.Socket methods, use these instead.
 * They implement logic involving sockets, users, sessions, devices, and more.
 * @class Users.Socket
 */
Users.Socket = {
	/**
	 * Start http server if needed, and start listening to socket.
	 * Use this instead of Q.Socket
	 * This also attaches a few event handlers for Users events.
	 * @method listen
	 * @param {Object} options Can be any options for the server.listen() in socket.io,
	 *    as well as the following options:
	 * @param {Object} options.host Set the hostname to listen on
	 * @param {Object} options.port Set the port to listen on
	 * @param {Object} options.https If you use https, pass https options here (see Q.listen)
	 * @return {socket.io}
	 */
	listen: function (options) {
		var socket = Q.Socket.listen(options);

		if (!socket) {
			console.warn("Users.listen: socket missing");
			return null;
		}

		socket.io.of('/Q').use(function (client, next) {
			// NOTE: the Q.Socket middleware already ran, and set
			// client.capability to the capability object.
			// Since the capability was successfully verified,
			// the userId must have been generated by the PHP server,
			// so we trust it.
			var userId = client.capability && client.capability.userId;
			if (!userId) {
				return next(); // user is not logged in
			}
			if (!Users.clients[userId]) {
				Users.clients[userId] = {};
			}
			var wasOnline = !Q.isEmpty(Users.User.clientsOnline(userId));
			client.userId = userId;
			client.clientId = client.id;
			Users.clients[userId][client.id] = client;
			if (timeouts[userId]) {
				clearTimeout(timeouts[userId]);
			}
			delete timeouts[userId];
			/**
			 * User has connected.
			 * Reconnections before disconnect timeout don't count.
			 * @event connected
			 * @param {Socket} client
			 *	The connecting client. Contains userId, clientId
			 * @param {Boolean} online
			 *	Whether any other clients were online for the user before this
			 */
			Users.emit('connected', client, wasOnline);
			if (wasOnline) {
				Q.log('New client connected: ' + userId + '('+client.id+')');
			} else {
				Q.log('User connected: ' + client.userId);
			}
			next();
		});
		socket.io.of('/Q').on('connection', function(client) {
			Q.log("Socket.IO client connected " + client.id);
			if (client.alreadyListening) {
				return;
			}
			client.alreadyListening = true;
			client.on('Users/clients', function (callback) {
				var userId = client.capability && client.capability.userId;
				if (!userId) {
					callback(null);
				}
				var oci = {};
				for (var cid in Users.clients[userId]){
					var c = Users.clients[userId][cid];
					oci[cid] = {
						clientId: c.clientId
					};
				}
				callback({
					clients: oci
				});
			});
			client.on('Users/online', function (options, callback) {
				var ret = {};
				for (var userId in Users.clients) {
					ret[userId] = {};
					for (var clientId in Users.clients[userId]) {
						ret[userId][clientId] = true;
					}
				}
				callback(Users.clients);
			});
			client.on('disconnect', function(){
				var userId = client.userId;
				var i;
				if (!userId || !Users.clients[userId]) {
					return;
				}
				var clients = Users.clients[userId];
				delete clients[this.id];
				Q.log('Client disconnected: ' + userId + "(" + this.id + ")");
				if (Q.isEmpty(clients)) {
					// All the clients have been disconnected.
					// Let's wait a bit and if none of them reconnect within the timeout period,
					// we'll post a message saying the user disconnected.
					timeouts[userId] = setTimeout(function () {
						if (Q.isEmpty(clients)) {
							/**
							 * User has disconnected, and timeout for reconnection has passed
							 * @event disconnected
							 * @param {String} userId id of the user that disconnected
							 */
							Users.emit('disconnected', userId);
						}
						delete timeouts[userId];
					}, Q.Config.get(["Users", "socket", "disconnectTimeout"], 1000));
				}
			});
		});
		return socket;
	},
	
	/**
	 * Emits an event to user's socket.io clients that are currently connected
	 * @method emitToUser
	 * @static
	 * @param {String} userId The id of the user
	 * @param {String} event The name of the event the socket client should emit
	 * @param {Object} data Any data to accompany this event name
	 * @return {Boolean} Whether any socket clients were connected at all
	 */
	emitToUser: function(userId, event) {
		var args = Array.prototype.slice.call(arguments, 1);
		var clients = Users.User.clientsOnline(userId);
		if (Q.isEmpty(clients)) {
			return false;
		}
		for (var cid in clients) {
			var client = clients[cid];
			client && client.emit.apply(client, args);
		}
		return true;
	}
};

/* * * */

Q.require('Users/ExternalFrom/Facebook');

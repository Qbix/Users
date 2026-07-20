/**
 * Class representing quota rows.
 *
 * Used for keeping track of quotas — rate limiting actions
 * per user based on time windows and roles.
 *
 * Config format:
 *   "Users": { "quotas": {
 *     "Streams/invite": {
 *       "86400": { "": 10, "Users/admins": 1000 }
 *     },
 *     "MyPlugin/vote": {
 *       "3600": { "": 5, "Users/members": 50 }
 *     }
 *   }}
 *
 * The keys under each action are time windows in seconds.
 * The values are objects mapping labels to limits:
 *   "" (empty string) = default for everyone
 *   "Users/admins" = limit for users with that label
 * The highest matching limit is used (labels aggregate by max).
 *
 * @module Users
 */
var Q = require('Q');
var Db = Q.require('Db');
var Quota = Q.require('Base/Users/Quota');
var Users = Q.require('Users');

/**
 * Class representing 'Quota' rows in the 'Users' database
 * <br>Used for keeping track of quotas
 * @namespace Users
 * @class Quota
 * @extends Base.Users.Quota
 * @constructor
 * @param {Object} fields The fields values to initialize table row as
 *   an associative array of `{column: value}` pairs
 */
function Users_Quota (fields) {

	// Run mixed-in constructors
	Users_Quota.constructors.apply(this, arguments);

}

Q.mixin(Users_Quota, Quota);

/**
 * The setUp() method is called the first time
 * an object of this class is constructed.
 * @method setUp
 */
Users_Quota.prototype.setUp = function () {
	// put any code here
	// overrides the Base class
};

/**
 * Check and enforce a quota for a user performing an action.
 *
 * Looks up config under Users/quotas/$name for time windows
 * and per-label limits. Checks how many times this user has
 * performed the action within each window. If any window is
 * exceeded, either throws or returns false.
 *
 * If the check passes, increments the count for the current
 * time window.
 *
 * Usage:
 *   Users.Quota.check(userId, 'Streams/invite', function (err, quota) {
 *       if (err) return; // quota exceeded or other error
 *       // proceed with the action
 *   });
 *
 *   // Or with throwIfExceeded = true (default):
 *   Users.Quota.check(userId, 'Streams/invite', true, function (err, quota) {
 *       // err will be a Users.Exception.Quota if exceeded
 *   });
 *
 * @method check
 * @static
 * @param {String} userId The user performing the action
 * @param {String} name The quota name, e.g. "Streams/invite"
 * @param {Boolean} [throwIfExceeded=true] Whether to pass an error
 *   to callback if quota is exceeded
 * @param {Function} callback Receives (err, quota) where quota is
 *   the Users.Quota row, or null if no quota configured
 */
Users_Quota.check = function (userId, name, throwIfExceeded, callback) {
	if (typeof throwIfExceeded === 'function') {
		callback = throwIfExceeded;
		throwIfExceeded = true;
	}
	if (!callback) {
		callback = function () {};
	}

	// Look up config
	var quotaConfig = Q.Config.get(['Users', 'quotas', name], null);
	if (!quotaConfig) {
		// No quota configured for this action — allow
		return callback(null, null);
	}

	// Get user's roles/labels for limit lookup
	Users.labelsForUser(userId, function (err, labels) {
		if (err) return callback(err);

		var labelSet = {};
		if (labels) {
			Q.each(labels, function (i, label) {
				var l = (typeof label === 'string') ? label : label.label;
				if (l) labelSet[l] = true;
			});
		}

		// Check each time window
		var windows = Object.keys(quotaConfig);
		var remaining = windows.length;
		var exceeded = false;
		var quotaRow = null;

		if (!remaining) {
			return callback(null, null);
		}

		Q.each(windows, function (i, seconds) {
			seconds = parseInt(seconds);
			if (isNaN(seconds) || seconds <= 0) {
				if (--remaining === 0) _finish();
				return;
			}

			var limits = quotaConfig[seconds.toString()];
			if (!limits || typeof limits !== 'object') {
				if (--remaining === 0) _finish();
				return;
			}

			// Find the highest limit that applies to this user
			var maxLimit = _resolveLimit(limits, labelSet);
			if (maxLimit === null) {
				// No limit applies — allow
				if (--remaining === 0) _finish();
				return;
			}

			// Count actions in this window
			var since = new Date(Date.now() - seconds * 1000);

			Users_Quota.SELECT('COUNT(1) as count')
				.where({
					userId: userId,
					name: name,
					insertedTime: { '>=': since }
				})
				.execute(function (err, rows) {
					if (err) {
						if (--remaining === 0) _finish();
						return;
					}

					var count = rows && rows[0] ? parseInt(rows[0].count) : 0;

					if (count >= maxLimit) {
						exceeded = {
							name: name,
							window: seconds,
							limit: maxLimit,
							count: count
						};
					}

					if (--remaining === 0) _finish();
				});
		});

		function _finish() {
			if (exceeded) {
				if (throwIfExceeded) {
					var error = new Q.Exception(
						"Users.Quota: {{name}} exceeded ({{count}}/{{limit}} in {{window}}s)",
						exceeded
					);
					error.code = 'Users/Quota/exceeded';
					error.data = exceeded;
					return callback(error);
				}
				return callback(null, false);
			}

			// Quota check passed — record this action
			var row = new Users_Quota({
				userId: userId,
				name: name,
				insertedTime: new Date()
			});
			row.save(true, function (err) {
				if (err) return callback(err);
				callback(null, row);
			});
		}
	});
};

/**
 * Resolve the highest limit that applies to a user given their labels.
 *
 * @method _resolveLimit
 * @private
 * @static
 * @param {Object} limits Map of label → limit (empty string key = default)
 * @param {Object} labelSet Map of user's labels → true
 * @return {Number|null} The highest applicable limit, or null if none
 */
function _resolveLimit(limits, labelSet) {
	var maxLimit = null;

	Q.each(limits, function (label, limit) {
		limit = parseInt(limit);
		if (isNaN(limit)) return;

		if (label === '') {
			// Default limit applies to everyone
			if (maxLimit === null || limit > maxLimit) {
				maxLimit = limit;
			}
		} else if (labelSet[label]) {
			// User has this label — use higher limit
			if (maxLimit === null || limit > maxLimit) {
				maxLimit = limit;
			}
		}
	});

	return maxLimit;
}

/**
 * Clean up old quota records beyond the longest configured window.
 * Call periodically (e.g. daily cron) to prevent table growth.
 *
 * @method cleanup
 * @static
 * @param {String} [name] Quota name to clean. If null, cleans all.
 * @param {Function} [callback] Receives (err, deletedCount)
 */
Users_Quota.cleanup = function (name, callback) {
	if (typeof name === 'function') {
		callback = name;
		name = null;
	}

	// Find the longest window across all configured quotas
	var allQuotas = Q.Config.get(['Users', 'quotas'], {});
	var maxWindow = 0;

	Q.each(name ? { x: allQuotas[name] } : allQuotas, function (n, windows) {
		if (!windows) return;
		Q.each(windows, function (seconds) {
			seconds = parseInt(seconds);
			if (seconds > maxWindow) maxWindow = seconds;
		});
	});

	if (!maxWindow) {
		maxWindow = 86400; // default: clean anything older than 1 day
	}

	var cutoff = new Date(Date.now() - maxWindow * 1000);
	var where = { insertedTime: { '<': cutoff } };
	if (name) where.name = name;

	Users_Quota.DELETE()
		.where(where)
		.execute(function (err, result) {
			if (callback) {
				callback(err, result ? result.affectedRows : 0);
			}
		});
};

/**
 * Get remaining quota for a user in a specific window.
 *
 * @method remaining
 * @static
 * @param {String} userId
 * @param {String} name Quota name
 * @param {Function} callback Receives (err, remaining) where remaining
 *   is an object { window: seconds, limit: N, used: N, left: N }
 *   for each configured window, or null if no quota configured
 */
Users_Quota.remaining = function (userId, name, callback) {
	var quotaConfig = Q.Config.get(['Users', 'quotas', name], null);
	if (!quotaConfig) {
		return callback(null, null);
	}

	Users.labelsForUser(userId, function (err, labels) {
		if (err) return callback(err);

		var labelSet = {};
		if (labels) {
			Q.each(labels, function (i, label) {
				var l = (typeof label === 'string') ? label : label.label;
				if (l) labelSet[l] = true;
			});
		}

		var windows = Object.keys(quotaConfig);
		var results = [];
		var remaining = windows.length;

		if (!remaining) return callback(null, []);

		Q.each(windows, function (i, seconds) {
			seconds = parseInt(seconds);
			var limits = quotaConfig[seconds.toString()];
			var maxLimit = _resolveLimit(limits || {}, labelSet);

			if (maxLimit === null) {
				if (--remaining === 0) callback(null, results);
				return;
			}

			var since = new Date(Date.now() - seconds * 1000);
			Users_Quota.SELECT('COUNT(1) as count')
				.where({
					userId: userId,
					name: name,
					insertedTime: { '>=': since }
				})
				.execute(function (err, rows) {
					if (err) {
						if (--remaining === 0) callback(null, results);
						return;
					}
					var used = rows && rows[0] ? parseInt(rows[0].count) : 0;
					results.push({
						window: seconds,
						limit: maxLimit,
						used: used,
						left: Math.max(0, maxLimit - used)
					});
					if (--remaining === 0) callback(null, results);
				});
		});
	});
};

module.exports = Users_Quota;
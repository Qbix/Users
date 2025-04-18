/**
 * Class representing vote rows.
 *
 * @module Users
 */
var Q = require('Q');
var Db = Q.require('Db');
var Users = Q.require('Users');

/**
 * Class representing 'Vote' rows in the 'Users' database
 * <br/>Represents a vote by a user for something
 * @namespace Users
 * @class Vote
 * @extends Base.Users.Vote
 * @constructor
 * @param fields {object} The fields values to initialize table row as
 * an associative array of `{column: value}` pairs
 */
function Users_Vote (fields) {

	// Run constructors of mixed in objects
	Users_Vote.constructors.apply(this, arguments);

	/*
	 * Add any other methods to the model class by assigning them to this.
	 
	 * * * */

	var _cache = null;

	function _afterHandler (query, error, result) {
		if (!error && _cache) {
			_cache.save(false, true, function (err) {
				if (err) {
					// rollback
					_cache.beforeRetrieveExecute = function (query) {
						return query.rollback().execute();
					};
					_cache.retrieve(function () {
						_cache = null;
						query.resume(err);
					});
				} else {
					_cache = null;
					query.resume(error, result);
				}
			});
			return true;
		}
	}

	function _rollback (obj, str) {
		obj.beforeRetrieveExecute = function (query) {
			return query.rollback();
		};
		obj.retrieve(function () {
			throw new Error(str);
		});
	}

	/**
	 * Update total votes in Users.Total when saving new vote
	 * @method beforeSaveExecute
	 * @param query {Db.Query.Mysql}
	 *	The query being excecuted
	 * @param modifiedFields {object}
	 *	The fields which are modified by query
	 */
	this.beforeSaveExecute = function (query, modifiedFields) {
		var self = this;
		var total = new Users.Total({
			forType: this.forType,
			forId: this.forId
		});
		total.retrieve('*', true, true, function(err, total_res) {
			if (err) {
				return console.log(err);
			}
			if (!total_res.length) {
				total.weightTotal = 0;
				total.voteCount = 0;
				total.value = 0;
			} else {
				total = total_res[0];
			}
			var weightTotal = total.weightTotal;
			var vote = new Users.Vote({
				userId: modifiedFields.userId,
				forType: total.forType,
				forId: modifiedFields.forId
			});
			vote.retrieve('*', true, function (err, vote_res) {
				if (err) {
					return console.warn(err);
				}
				if (!vote_res.length) {
					total.weightTotal += modifiedFields.weight;
					total.voteCount += 1;
					total.value = (total.value * weightTotal + modifiedFields.value * modifiedFields.weight) / (total.weightTotal);
				} else {
					vote = vote_res[0];
					if (!total.voteCount) {
						// something is wrong
						total.voteCount = 1;
					}
					total.weightTotal += (modifiedFields.weight - vote.weight);
					if (!total.weightTotal) {
						_rollback(total, self.className + ".beforeSaveExecute(): total.weight = 0!");
					}
					total.value =
						(total.value * weightTotal 
							- vote.value * vote.weight 
							+ modifiedFields.value * modifiedFields.weight) / (total.weightTotal);
				}
				_cache = total;
				query.resume();
			});
		}).begin().lock().resume();
		if (this.constructor.prototype.beforeSaveExecute) {
			return this.constructor.prototype.beforeSaveExecute(query, modifiedFields);
		}
	};
	
	/**
	 * Commit or rollback transaction when saving new vote
	 * @method afterSaveExecute
	 * @param query {Db.Query.Mysql}
	 *	The query being excecuted
	 * @param result {object}
	 *	The result of the query
	 * @param error {Error}
	 *	Error object if any
	 */
	this.afterSaveExecute = _afterHandler;

	/**
	 * Commit or rollback transaction when deleting a vote
	 * @method afterRemoveExecute
	 * @param query {Db.Query.Mysql}
	 *	The query being excecuted
	 * @param result {object}
	 *	The result of the query
	 * @param error {Error}
	 *	Error object if any
	 */
	this.afterRemoveExecute = _afterHandler;

	/* * * */
}

Q.mixin(Users_Vote, Q.require('Base/Users/Vote'));

/**
 * Saves votes by a user
 * @param {String} type the type of item being voted on
 * @param {Array} ids an array of item IDs to vote for
 * @param {Array} weights an array of item IDs to vote for
 * @param {Array} values an array of item IDs to vote for
 * @param {String} asUserId the ID of the user casting the votes
 * @param {Function} callback - can be called after all the votes have been saved
 * @returns {Array|false} Returns the array of vote objects saved, or false if asUserId is not provided
 */
Users_Vote.vote = function(
	type, 
	ids,
	weights,
	values,
	asUserId, 
	callback
) {
	if (!asUserId) {
		return false;
	}
	ids = ids || [];
	weights = weights || [];
	values = values || [];
	const votes = [];
	var p = new Q.Pipe(ids, 1, function () {
		callback && callback(ids);
	});
	for (var i=0; i<ids.length; ++i) {
		var vote = new Users_Vote({
			userId: asUserId,
			forType: type,
			forId: String(ids[i]),
			value: values[i],
			weight: weights[i]
		});
		vote.save(true, false, p.fill(ids[i]));
		votes.push(vote);
	}

	return votes;
};

/**
 * The setUp() method is called the first time
 * an object of this class is constructed.
 * @method setUp
 */
Users_Vote.prototype.setUp = function () {
	// put any code here
	// overrides the Base class
};

module.exports = Users_Vote;
/**
 * Sendblue plugin (JS) — iMessage/SMS/RCS via API v2.
 * @module Sendblue
 * @class Sendblue
 */
var Q = require('Q');
var Sendblue = { BASE: 'https://api.sendblue.com' };
module.exports = Sendblue;
Q.require('Sendblue/Client');

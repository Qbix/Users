// ===========================================================
// Q.embed.js — Parent-side script for Qbix iframe embeds
// ===========================================================
//
// Loaded by third-party sites to embed Qbix tools and bridge sessions
// to the first-party Qbix domain when users navigate.
//
// Provides:
//   - Q.embed({url, container, ...}) — create an iframe pointing at Qbix
//   - Recovery key persistence (CryptoKey stored in parent IndexedDB,
//     returned to iframe on subsequent loads)
//   - Q.embed.call(iframe, method, params, cb) — generic postMessage RPC
//   - Auto-intercept of clicks on links to Q.embed.origin: mints a bridge
//     token via Q.embed.call(iframe, 'Users.bridge.mint'), decorates URL
//     with Q.Users.intent before navigating
//
// Usage:
//   <script src="https://qbix.example.com/Q.embed.js"></script>
//   <script>
//     var f = Q.embed({
//       url: 'https://qbix.example.com/embed.php?tool=Streams/chat&host=mysite'
//     });
//     document.body.appendChild(f);
//   </script>
//   <a href="https://qbix.example.com/community/abc">Join the community</a>
// ===========================================================

window.Q = window.Q || {};

Q.log = function (msg) {
	var el = document.getElementById('Q_log');
	if (window.console) console.log(msg);
	if (el) {
		el.innerHTML += '<div>'
			+ (typeof msg === 'string' ? msg : JSON.stringify(msg)) + '</div>';
	}
};

Q.warn = function (msg) {
	if (window.console && console.warn) console.warn(msg);
};

Q.uuid = function () {
	return 'xxxxxxxx'.replace(/[x]/g, function () {
		return ((Math.random() * 16) | 0).toString(16);
	});
};

// ===========================================================
// Detect Qbix origin from this script's own src
// ===========================================================
Q.embed = Q.embed || {};
Q.embed.origin = (function () {
	var scripts = document.getElementsByTagName('script');
	for (var i = scripts.length - 1; i >= 0; i--) {
		var src = scripts[i].src || '';
		if (src.indexOf('/Q.embed.js') >= 0 || src.indexOf('/Q.js') >= 0) {
			try { return new URL(src).origin; } catch (e) {}
		}
	}
	return null;
})();

// ===========================================================
// Minimal IndexedDB wrapper
// ===========================================================
Q.IndexedDB = {
	open: function (dbName, storeName, keyPath, callback) {
		if (!window.indexedDB) return callback(new Error('No IndexedDB'));
		var req = indexedDB.open(dbName, 1);
		req.onupgradeneeded = function (e) {
			var db = e.target.result;
			if (!db.objectStoreNames.contains(storeName))
				db.createObjectStore(storeName, { keyPath: keyPath });
		};
		req.onerror = function (e) { callback(e.target.error); };
		req.onsuccess = function (e) { callback(null, e.target.result); };
	},
	put: function (db, store, id, key, cb) {
		try {
			var tx = db.transaction(store, 'readwrite');
			var req = tx.objectStore(store).put({ id: id, key: key });
			req.onsuccess = function () { cb && cb(null, true); };
			req.onerror = function (e) { cb && cb(e.target.error); };
		} catch (e) { cb && cb(e); }
	},
	get: function (db, store, id, cb) {
		try {
			var tx = db.transaction(store, 'readonly');
			var req = tx.objectStore(store).get(id);
			req.onsuccess = function (e) { cb && cb(null, e.target.result); };
			req.onerror = function (e) { cb && cb(e.target.error); };
		} catch (e) { cb && cb(e); }
	},
	del: function (db, store, id, cb) {
		try {
			var tx = db.transaction(store, 'readwrite');
			var req = tx.objectStore(store).delete(id);
			req.onsuccess = function () { cb && cb(null, true); };
			req.onerror = function (e) { cb && cb(e.target.error); };
		} catch (e) { cb && cb(e); }
	}
};

// ===========================================================
// Q.embed() — iframe constructor and registry
// ===========================================================
Q.embed = function (opts) {
	if (typeof opts === 'string') opts = { url: opts };
	var url = opts.url || '';
	var html = opts.html || '';
	var container = opts.container;
	var width = opts.width || '100%';
	var height = opts.height || '480px';
	var sandbox = opts.sandbox
		|| 'allow-scripts allow-forms allow-popups allow-same-origin';

	var iframe = opts.iframe || document.createElement('iframe');
	if (!iframe.hasAttribute('sandbox')) iframe.setAttribute('sandbox', sandbox);
	iframe.style.width = width;
	iframe.style.height = height;
	iframe.style.border = opts.border || '0';

	if (html) {
		if ('srcdoc' in iframe) iframe.srcdoc = html;
		else iframe.src = 'data:text/html;charset=utf-8,' + encodeURIComponent(html);
	} else if (url) {
		iframe.src = url;
	}

	if (container && container.appendChild) container.appendChild(iframe);

	var origin;
	try { origin = (new URL(iframe.src)).origin; }
	catch (e) { origin = location.origin; }

	var id = opts.id || Q.uuid();
	iframe.qid = id;
	iframe.origin = origin;
	iframe.dbName = opts.dbName || 'Q.Embed.' + origin.replace(/[^a-z0-9]/gi, '_');

	Q.embed.iframes[id] = iframe;
	Q.log('Q.embed: iframe ' + id + ' created for ' + origin);

	if (!Q.embed._listenerAdded) {
		window.addEventListener('message', Q.embed._onMessage);
		Q.embed._listenerAdded = true;
	}

	iframe.addEventListener('load', function () {
		Q.embed._restoreKey(id);
	});

	return iframe;
};

Q.embed.iframes = {};

// ===========================================================
// Q.embed.call — generic postMessage RPC to an iframe
// ===========================================================
Q.embed._pending = {}; // id -> {callback, timeout}
Q.embed._callTimeoutMs = 5000;

/**
 * Call a method on the iframe via postMessage and receive a response.
 * The iframe must have a handler registered under Q.embed.handlers[method]
 * (see iframe-side dispatcher in Users.js).
 *
 * @method call
 * @param {HTMLIFrameElement} iframe  The iframe to call
 * @param {String} method             Method name (e.g. "Users.bridge.mint")
 * @param {Object} [params]           Parameters to pass to the handler
 * @param {Function} callback         Receives (err, result)
 */
Q.embed.call = function (iframe, method, params, callback) {
	if (typeof params === 'function') {
		callback = params;
		params = {};
	}
	if (!iframe || !iframe.contentWindow) {
		callback(new Error('no iframe'));
		return;
	}

	var id = Q.uuid();
	Q.embed._pending[id] = {
		callback: callback,
		timeout: setTimeout(function () {
			var entry = Q.embed._pending[id];
			if (!entry) return;
			delete Q.embed._pending[id];
			entry.callback(new Error('Q.embed.call ' + method + ' timed out'));
		}, Q.embed._callTimeoutMs)
	};

	try {
		iframe.contentWindow.postMessage({
			type: 'Q.call',
			id: id,
			method: method,
			params: params || {}
		}, iframe.origin);
	} catch (e) {
		clearTimeout(Q.embed._pending[id].timeout);
		delete Q.embed._pending[id];
		callback(e);
	}
};

// ===========================================================
// Message handler
// ===========================================================
Q.embed._onMessage = function (ev) {
	var data = ev.data || {};
	var type = data.type;
	var payload = data.payload;
	var target = null;

	for (var id in Q.embed.iframes) {
		var ifr = Q.embed.iframes[id];
		if (ifr.origin === ev.origin || (data.id && data.id === ifr.qid)) {
			target = ifr;
			break;
		}
	}
	if (!target) return;

	if (type === 'Q.return') {
		// Response to a Q.embed.call() request
		var entry = Q.embed._pending[data.id];
		if (!entry) return;
		clearTimeout(entry.timeout);
		delete Q.embed._pending[data.id];
		if (data.error) {
			entry.callback(new Error(data.error));
		} else {
			entry.callback(null, data.result);
		}
		return;
	}

	if (type === 'Q.Users.recoveryKey.generated') {
		if (!payload || !payload.recoveryKey) return;
		Q.embed._storeKey(target.qid, 'recoveryKey', payload.recoveryKey);
	}
	else if (type === 'Q.Users.recoveryKey.cleared') {
		Q.embed._deleteKey(target.qid, 'recoveryKey');
	}
	else if (type === 'Q.Users.recoveryKey.request') {
		Q.IndexedDB.open(target.dbName, 'keys', 'id', function (err, db) {
			if (err) {
				Q.warn('Q.embed: failed to open IndexedDB for recoveryKey.request');
				return;
			}
			Q.IndexedDB.get(db, 'keys', 'recoveryKey', function (err2, record) {
				var key = record && record.key;
				try {
					ev.source.postMessage({
						type: 'Q.Users.recoveryKey.recover',
						payload: { recoveryKey: key || null }
					}, ev.origin);
				} catch (e) {
					Q.warn('Q.embed: response postMessage failed: ' + e);
				}
			});
		});
	}
};

// ===========================================================
// Recovery key persistence helpers
// ===========================================================
Q.embed._storeKey = function (id, keyId, keyObj) {
	var iframe = Q.embed.iframes[id];
	if (!iframe) return;
	Q.IndexedDB.open(iframe.dbName, 'keys', 'id', function (err, db) {
		if (err) return Q.log('Q.embed: open DB failed ' + err);
		Q.IndexedDB.put(db, 'keys', keyId, keyObj, function (err2) {
			if (err2) Q.log('Q.embed: store key failed ' + err2);
			else Q.log('Q.embed: stored key ' + keyId + ' for ' + iframe.qid);
		});
	});
};

Q.embed._deleteKey = function (id, keyId) {
	var iframe = Q.embed.iframes[id];
	if (!iframe) return;
	Q.IndexedDB.open(iframe.dbName, 'keys', 'id', function (err, db) {
		if (err) return Q.log('Q.embed: open DB failed ' + err);
		Q.IndexedDB.del(db, 'keys', keyId, function (err2) {
			if (err2) Q.log('Q.embed: delete key failed ' + err2);
			else Q.log('Q.embed: removed key ' + keyId + ' for ' + iframe.qid);
		});
	});
};

Q.embed._restoreKey = function (id) {
	var iframe = Q.embed.iframes[id];
	if (!iframe) return;
	Q.IndexedDB.open(iframe.dbName, 'keys', 'id', function (err, db) {
		if (err) return Q.log('Q.embed: open DB failed ' + err);
		Q.IndexedDB.get(db, 'keys', 'recoveryKey', function (err2, record) {
			if (err2) return Q.log('Q.embed: read key failed ' + err2);
			var key = record && record.key;
			if (!key) return;
			try {
				iframe.contentWindow.postMessage({
					id: iframe.qid,
					type: 'Q.Users.recoveryKey.restored',
					payload: { recoveryKey: key }
				}, iframe.origin);
			} catch (e) {
				Q.log('Q.embed: postMessage failed ' + e);
			}
		});
	});
};

// ===========================================================
// Find an iframe pointing at the Qbix origin
// ===========================================================
Q.embed._findIframeForOrigin = function (targetOrigin) {
	for (var id in Q.embed.iframes) {
		var ifr = Q.embed.iframes[id];
		if (ifr.contentWindow && ifr.origin === targetOrigin) {
			return ifr;
		}
	}
	return null;
};

// ===========================================================
// Link click interception for bridge handoff
// ===========================================================
// Intercepts clicks on any <a> whose href is on Q.embed.origin. Asks the
// iframe to mint a bridge token via Users.bridge.mint, then navigates
// top-level with the Q.Users.intent parameter appended.
//
// Skip rules:
//   - modifier keys / non-primary button (let browser open new tab)
//   - data-qbix-bridge="false" opt-out
//   - URL already has Q.Users.intent (don't double-stamp)

Q.embed._onClick = function (ev) {
	if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey || ev.button !== 0) {
		return;
	}
	if (ev.defaultPrevented) return;

	var a = ev.target;
	while (a && a !== document.body) {
		if (a.tagName === 'A' && a.href) break;
		a = a.parentNode;
	}
	if (!a || a.tagName !== 'A' || !a.href) return;
	if (!Q.embed.origin) return;

	var linkOrigin;
	try { linkOrigin = (new URL(a.href)).origin; }
	catch (e) { return; }
	if (linkOrigin !== Q.embed.origin) return;

	if (a.getAttribute('data-qbix-bridge') === 'false') return;
	if (a.href.indexOf('Q.Users.intent=') >= 0) return;

	var iframe = Q.embed._findIframeForOrigin(Q.embed.origin);
	if (!iframe) {
		Q.log('Q.embed: no iframe to mint bridge — navigating without token');
		return; // let browser navigate normally
	}

	ev.preventDefault();
	var href = a.href;

	Q.embed.call(iframe, 'Users.bridge.mint', {}, function (err, token) {
		if (err || !token) {
			Q.log('Q.embed: bridge mint failed, navigating without token: ' + err);
			window.location = href;
			return;
		}
		var sep = href.indexOf('?') >= 0 ? '&' : '?';
		window.location = href + sep + 'Q.Users.intent='
			+ encodeURIComponent(token);
	});
};

// ===========================================================
// Initialization
// ===========================================================
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', function () {
		document.body.addEventListener('click', Q.embed._onClick, false);
	});
} else {
	document.body.addEventListener('click', Q.embed._onClick, false);
}

Q.log('Q.embed initialized, Qbix origin: ' + Q.embed.origin);
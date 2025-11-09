// ===========================================================
// Q.js — Multi-iframe embedding system (pre-ES2015)
// ===========================================================

window.Q = window.Q || {};

Q.log = function (msg) {
	var el = document.getElementById('log');
	if (window.console) console.log(msg);
	if (el) {
		el.innerHTML +=
			'<div>' + (typeof msg === 'string' ? msg : JSON.stringify(msg)) + '</div>';
	}
};

// -----------------------------------------------------------
// Internal helpers
// -----------------------------------------------------------
Q.uuid = function () {
	return 'xxxxxxxx'.replace(/[x]/g, function () {
		return ((Math.random() * 16) | 0).toString(16);
	});
};

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
			var st = tx.objectStore(store);
			var req = st.put({ id: id, key: key });
			req.onsuccess = function () { cb && cb(null, true); };
			req.onerror = function (e) { cb && cb(e.target.error); };
		} catch (e) { cb && cb(e); }
	},
	get: function (db, store, id, cb) {
		try {
			var tx = db.transaction(store, 'readonly');
			var st = tx.objectStore(store);
			var req = st.get(id);
			req.onsuccess = function (e) { cb && cb(null, e.target.result); };
			req.onerror = function (e) { cb && cb(e.target.error); };
		} catch (e) { cb && cb(e); }
	},
	del: function (db, store, id, cb) {
		try {
			var tx = db.transaction(store, 'readwrite');
			var st = tx.objectStore(store);
			var req = st.delete(id);
			req.onsuccess = function () { cb && cb(null, true); };
			req.onerror = function (e) { cb && cb(e.target.error); };
		} catch (e) { cb && cb(e); }
	}
};

// ===========================================================
// Q.embed() — Multi-iframe manager
// ===========================================================
Q.embed = function (opts) {
	if (typeof opts === 'string') opts = { url: opts };
	var url = opts.url || '';
	var html = opts.html || '';
	var container = opts.container; // no default now
	var width = opts.width || '800px';
	var height = opts.height || '200px';
	var sandbox =
		opts.sandbox ||
		'allow-scripts allow-forms allow-popups allow-same-origin';

	var iframe = opts.iframe || document.createElement('iframe');
	if (!iframe.hasAttribute('sandbox')) iframe.setAttribute('sandbox', sandbox);
	iframe.style.width = width;
	iframe.style.height = height;
	iframe.style.border = opts.border || '1px solid #ccc';

	// If html provided, use srcdoc or data URI
	if (html) {
		if ('srcdoc' in iframe) {
			iframe.srcdoc = html;
		} else {
			iframe.src =
				'data:text/html;charset=utf-8,' +
				encodeURIComponent(html);
		}
	} else if (url) {
		iframe.src = url;
	}

	// Only append if container provided
	if (container && container.appendChild) {
		container.appendChild(iframe);
	}

	// Metadata
	var origin;
	try {
		origin = (new URL(iframe.src)).origin;
	} catch (e) {
		origin = location.origin;
	}

	var id = opts.id || Q.uuid();
	iframe.qid = id;
	iframe.origin = origin;
	iframe.dbName = opts.dbName || 'Q.Embed.' + origin.replace(/[^a-z0-9]/gi, '_');

	Q.embed.iframes[id] = iframe;
	Q.log('Q.embed: iframe ' + id + ' created for ' + origin);

	// Global listener (added once)
	if (!Q.embed._listenerAdded) {
		window.addEventListener('message', Q.embed._onMessage);
		Q.embed._listenerAdded = true;
	}

	iframe.addEventListener('load', function () {
		Q.embed._restoreKey(id);
	});

	return iframe;
};

// -----------------------------------------------------------
// Static fields and handlers
// -----------------------------------------------------------
Q.embed.iframes = {}; // id → iframe

Q.embed._onMessage = function (ev) {
	var data = ev.data || {};
	var type = data.type;
	var payload = data.payload;
	var target = null;

	// Locate iframe by origin or id
	for (var id in Q.embed.iframes) {
		var ifr = Q.embed.iframes[id];
		if (ifr.origin === ev.origin || (data.id && data.id === ifr.qid)) {
			target = ifr;
			break;
		}
	}
	if (!target) return;

	if (type === 'Q.Users.recoveryKey.generated') {
		Q.log('Q.embed: recoveryKey.generated from ' + ev.origin);
		Q.embed._storeKey(target.qid, 'recoveryKey', payload.recoveryKey);

	} else if (type === 'Q.Users.recoveryKey.cleared') {
		Q.log('Q.embed: recoveryKey.cleared from ' + ev.origin);
		Q.embed._deleteKey(target.qid, 'recoveryKey');

	} else if (type === 'Q.Users.recoveryKey.request') {
		Q.log('Q.embed: recoveryKey.request from ' + ev.origin);

		Q.IndexedDB.open('Q.Users.parent', 'keys', 'id', function (err, db) {
			if (err) {
				Q.warn('Q.embed: failed to open IndexedDB for recoveryKey.request');
				return;
			}
			Q.IndexedDB.get(db, 'keys', 'recoveryKey', function (err2, key) {
				ev.source.postMessage(
					{
						type: 'Q.Users.recoveryKey.recover',
						payload: { recoveryKey: key || null }
					},
					ev.origin || '*'
				);
				Q.log(
					'Parent: sent recoveryKey.recover ' +
					(key ? 'with key' : 'with null') +
					' to iframe from ' + ev.origin
				);
			});
		});
	}
};

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

Q.embed._restoreKey = function (id, sendNow) {
	var iframe = Q.embed.iframes[id];
	if (!iframe) return;
	Q.IndexedDB.open(iframe.dbName, 'keys', 'id', function (err, db) {
		if (err) return Q.log('Q.embed: open DB failed ' + err);
		Q.IndexedDB.get(db, 'keys', 'recoveryKey', function (err2, record) {
			if (err2) return Q.log('Q.embed: read key failed ' + err2);
			var key = record && record.key;
			if (key && (sendNow || iframe)) {
				Q.log('Q.embed: posting restored key to iframe ' + id);
				try {
					iframe.contentWindow.postMessage(
						{
							id: iframe.qid,
							type: 'Q.Users.recoveryKey.restored',
							payload: { recoveryKey: key }
						},
						iframe.origin
					);
				} catch (e) {
					Q.log('Q.embed: postMessage failed ' + e);
				}
			}
		});
	});
};

// ===========================================================
// Example usage
// ===========================================================
document.addEventListener('DOMContentLoaded', function () {
	var log = document.createElement('div');
	log.id = 'log';
	document.body.appendChild(log);

	// Example 1: create but do NOT append (manual control)
	var f1 = Q.embed({ url: 'https://intercoin.app/demo-iframe.html' });
	// insert it later
	document.body.appendChild(f1);

	// Example 2: inline HTML snippet
	var htmlSnippet = '<!doctype html><html><body><h4>Inline Embed</h4><p>Hello from parent.</p></body></html>';
	var f2 = Q.embed({ html: htmlSnippet, width: '400px', height: '100px' });
	document.body.appendChild(f2);

	Q.log('Embeds active: ' + Object.keys(Q.embed.iframes).length);
});
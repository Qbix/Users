// ===========================================================
// Q.Users.Embed.js — Parent-side script for Qbix iframe embeds
// ===========================================================
//
// Loaded by third-party sites to embed Qbix tools and bridge sessions
// to the first-party Qbix domain.
//
// Provides:
//   - Q.Users.Embed({url, container, ...}) — create an iframe
//   - Recovery key persistence in parent IndexedDB
//   - Q.Users.Embed.call(iframe, method, params, cb) — postMessage RPC
//   - Click interception on links to Q.Users.Embed.origin: mints bridge token,
//     appends Q.Users.intent before navigating top-level
//
// Usage:
//   <script src="https://qbix.org/Q/plugins/Users/Embed.js"></script>
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

// Detect Qbix origin from this script's own src
Q.Users.Embed = Q.Users.Embed || {};
Q.Users.Embed.origin = (function () {
    var scripts = document.getElementsByTagName('script');
    for (var i = scripts.length - 1; i >= 0; i--) {
        var src = scripts[i].src || '';
        if (src.indexOf('/Embed.js') >= 0 || src.indexOf('/Q.js') >= 0) {
            try { return new URL(src).origin; } catch (e) {}
        }
    }
    return null;
})();

// IndexedDB wrapper
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

// Q.Users.Embed() — iframe constructor and registry
Q.Users.Embed = function (opts) {
    if (typeof opts === 'string') opts = { url: opts };
    var url = opts.url || '';
    var html = opts.html || '';
    var container = opts.container;
    var width = opts.width || '100%';
    var height = opts.height || '480px';
    var sandbox = opts.sandbox
        || 'allow-scripts allow-forms allow-popups allow-same-origin allow-storage-access-by-user-activation';

    var iframe = opts.iframe || document.createElement('iframe');
    if (!iframe.hasAttribute('sandbox')) iframe.setAttribute('sandbox', sandbox);
    // Required for Storage Access API to work in sandboxed iframes
    if (!iframe.hasAttribute('allow')) {
        iframe.setAttribute('allow', 'storage-access');
    }
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
    iframe.dbName = opts.dbName || 'Q.Users.Embed.' + origin.replace(/[^a-z0-9]/gi, '_');

    Q.Users.Embed.iframes[id] = iframe;
    Q.log('Q.Users.Embed: iframe ' + id + ' created for ' + origin);

    if (!Q.Users.Embed._listenerAdded) {
        window.addEventListener('message', Q.Users.Embed._onMessage);
        Q.Users.Embed._listenerAdded = true;
    }

    iframe.addEventListener('load', function () {
        Q.Users.Embed._restoreKey(id);
    });

    return iframe;
};

Q.Users.Embed.iframes = {};
Q.Users.Embed._pending = {};
Q.Users.Embed._callTimeoutMs = 5000;

/**
 * Call a method on the iframe via postMessage and receive a response.
 * The iframe must have a handler registered under Q.Users.Embed.handlers[method].
 *
 * @method call
 * @param {HTMLIFrameElement} iframe
 * @param {String} method
 * @param {Object} [params]
 * @param {Function} callback Receives (err, result)
 */
Q.Users.Embed.call = function (iframe, method, params, callback) {
    if (typeof params === 'function') {
        callback = params;
        params = {};
    }
    if (!iframe || !iframe.contentWindow) {
        callback(new Error('no iframe'));
        return;
    }

    var id = Q.uuid();
    Q.Users.Embed._pending[id] = {
        callback: callback,
        timeout: setTimeout(function () {
            var entry = Q.Users.Embed._pending[id];
            if (!entry) return;
            delete Q.Users.Embed._pending[id];
            entry.callback(new Error('Q.Users.Embed.call ' + method + ' timed out'));
        }, Q.Users.Embed._callTimeoutMs)
    };

    try {
        iframe.contentWindow.postMessage({
            type: 'Q.Users.Embed.call',
            id: id,
            method: method,
            params: params || {}
        }, iframe.origin);
    } catch (e) {
        clearTimeout(Q.Users.Embed._pending[id].timeout);
        delete Q.Users.Embed._pending[id];
        callback(e);
    }
};

Q.Users.Embed._onMessage = function (ev) {
    var data = ev.data || {};
    var type = data.type;
    var payload = data.payload;
    var target = null;

    for (var id in Q.Users.Embed.iframes) {
        var ifr = Q.Users.Embed.iframes[id];
        if (ifr.origin === ev.origin || (data.id && data.id === ifr.qid)) {
            target = ifr;
            break;
        }
    }
    if (!target) return;

    if (type === 'Q.Users.Embed.return') {
        var entry = Q.Users.Embed._pending[data.id];
        if (!entry) return;
        clearTimeout(entry.timeout);
        delete Q.Users.Embed._pending[data.id];
        if (data.error) {
            entry.callback(new Error(data.error));
        } else {
            entry.callback(null, data.result);
        }
        return;
    }

    if (type === 'Q.Users.recoveryKey.generated') {
        if (!payload || !payload.recoveryKey) return;
        Q.Users.Embed._storeKey(target.qid, 'recoveryKey', payload.recoveryKey);
    }
    else if (type === 'Q.Users.recoveryKey.cleared') {
        Q.Users.Embed._deleteKey(target.qid, 'recoveryKey');
    }
    else if (type === 'Q.Users.recoveryKey.request') {
        Q.IndexedDB.open(target.dbName, 'keys', 'id', function (err, db) {
            if (err) return;
            Q.IndexedDB.get(db, 'keys', 'recoveryKey', function (err2, record) {
                var key = record && record.key;
                try {
                    ev.source.postMessage({
                        type: 'Q.Users.recoveryKey.recover',
                        payload: { recoveryKey: key || null }
                    }, ev.origin);
                } catch (e) {
                    Q.warn('Q.Users.Embed: response postMessage failed: ' + e);
                }
            });
        });
    }
};

Q.Users.Embed._storeKey = function (id, keyId, keyObj) {
    var iframe = Q.Users.Embed.iframes[id];
    if (!iframe) return;
    Q.IndexedDB.open(iframe.dbName, 'keys', 'id', function (err, db) {
        if (err) return;
        Q.IndexedDB.put(db, 'keys', keyId, keyObj, function () {});
    });
};

Q.Users.Embed._deleteKey = function (id, keyId) {
    var iframe = Q.Users.Embed.iframes[id];
    if (!iframe) return;
    Q.IndexedDB.open(iframe.dbName, 'keys', 'id', function (err, db) {
        if (err) return;
        Q.IndexedDB.del(db, 'keys', keyId, function () {});
    });
};

Q.Users.Embed._restoreKey = function (id) {
    var iframe = Q.Users.Embed.iframes[id];
    if (!iframe) return;
    Q.IndexedDB.open(iframe.dbName, 'keys', 'id', function (err, db) {
        if (err) return;
        Q.IndexedDB.get(db, 'keys', 'recoveryKey', function (err2, record) {
            if (err2) return;
            var key = record && record.key;
            if (!key) return;
            try {
                iframe.contentWindow.postMessage({
                    id: iframe.qid,
                    type: 'Q.Users.recoveryKey.restored',
                    payload: { recoveryKey: key }
                }, iframe.origin);
            } catch (e) {}
        });
    });
};

Q.Users.Embed._findIframeForOrigin = function (targetOrigin) {
    for (var id in Q.Users.Embed.iframes) {
        var ifr = Q.Users.Embed.iframes[id];
        if (ifr.contentWindow && ifr.origin === targetOrigin) {
            return ifr;
        }
    }
    return null;
};

Q.Users.Embed._onClick = function (ev) {
    if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey || ev.button !== 0) return;
    if (ev.defaultPrevented) return;

    var a = ev.target;
    while (a && a !== document.body) {
        if (a.tagName === 'A' && a.href) break;
        a = a.parentNode;
    }
    if (!a || a.tagName !== 'A' || !a.href) return;
    if (!Q.Users.Embed.origin) return;

    var linkOrigin;
    try { linkOrigin = (new URL(a.href)).origin; }
    catch (e) { return; }
    if (linkOrigin !== Q.Users.Embed.origin) return;

    if (a.getAttribute('data-qbix-bridge') === 'false') return;
    if (a.href.indexOf('Q.Users.intent=') >= 0) return;

    var iframe = Q.Users.Embed._findIframeForOrigin(Q.Users.Embed.origin);
    if (!iframe) return;

    ev.preventDefault();
    var href = a.href;

    Q.Users.Embed.call(iframe, 'Users.bridge.mint', {}, function (err, token) {
        if (err || !token) {
            Q.log('Q.Users.Embed: bridge mint failed, navigating plain: ' + err);
            window.location = href;
            return;
        }
        var sep = href.indexOf('?') >= 0 ? '&' : '?';
        window.location = href + sep + 'Q.Users.intent='
            + encodeURIComponent(token);
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
        document.body.addEventListener('click', Q.Users.Embed._onClick, false);
    });
} else {
    document.body.addEventListener('click', Q.Users.Embed._onClick, false);
}

Q.log('Q.Users.Embed initialized, Qbix origin: ' + Q.Users.Embed.origin);
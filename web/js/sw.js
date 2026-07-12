'use strict';

self.addEventListener('install', function (event) {
	event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', function (event) {
	event.waitUntil(clients.claim());
});

self.addEventListener('push', function (event) {
	var data;
	try {
		data = event.data ? JSON.parse(event.data.text()) : {};
	} catch (e) {
		console.error('[Service Worker] Push parse error', e);
		data = {};
	}
	console.log('[Service Worker] Push received', data);
	if (data.update) {
		// force service worker to update via push
		self.registration.update().then(() => console.log('[Service Worker] Updated'));
		return;
	}

	var title = data.title || (data.alert && data.alert.title) || 'Notification';
	var body = data.body || (data.alert && data.alert.body) || 'New notification';
	// Safari rejects showNotification when unsupported/invalid options are passed.
	var options = {
		body: body,
		tag: data.collapseId || data.tag,
		data: {
			title: title,
			body: body,
			url: data.url,
			payload: data.payload
		}
	};
	if (data.icon && typeof data.icon === 'string') {
		options.icon = data.icon;
	}
	Object.keys(options).forEach(function (key) {
		if (options[key] === undefined || options[key] === null || options[key] === '') {
			delete options[key];
		}
	});

	sendMessageToAllClients({
		Q: {
			notification: {
				received: data
			}
		}
	});

	event.waitUntil(
		self.registration.showNotification(title, options).catch(function (err) {
			console.error('[Service Worker] showNotification failed', err, title, options);
			return self.registration.showNotification(title, { body: body });
		})
	);
});

self.addEventListener('notificationclick', function (event) {
	let data = event.notification.data;
	console.log('[Service Worker] Notification click Received.');
	event.notification.close();
	if (data.url) {
		event.waitUntil(clients.openWindow(data.url));
	}
	var payload = Object.assign({}, event.notification.data, {
		action: event.action
	});
	sendMessageToAllClients({
		Q: {
			notification: {
				clicked: payload
			}
		}
	});
});

function sendMessageToAllClients(msg) {
	self.clients.matchAll({includeUncontrolled: true, type: 'window'})
	.then(function(all) {
		all.forEach(function(client) {
			client.postMessage(msg);
		});
	});
}

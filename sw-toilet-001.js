/* ========= SERVICE WORKER VAPID - STABILE ========= */

self.addEventListener('install', event => {
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(self.clients.claim());
});

/**
 * PUSH: mostra la notifica.
 * Accetta payload JSON { title, body, data:{url} }
 */
self.addEventListener('push', event => {
  let data = {};
  try {
    if (event.data) data = event.data.json();
  } catch (e) {}

  const title = data.title || 'Notifica';
  const body  = data.body  || '';

  const url =
    (data.data && data.data.url) ||
    './agenda-cliente-toilet-001.html';

  const options = {
    body,
    icon:  data.icon  || 'icon-192.png',
    badge: data.badge || 'icon-192.png',
    data: { url }
  };

  event.waitUntil(
    self.registration.showNotification(title, options)
  );
});

/**
 * CLICK: apre la pagina corretta
 */
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const url = event.notification.data && event.notification.data.url
    ? event.notification.data.url
    : './agenda-cliente-toilet-001.html';

  event.waitUntil(clients.openWindow(url));
});

/* ====== FINE FILE ====== */
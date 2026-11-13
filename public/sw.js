// Smartgenda: recibe los avisos (Web Push) y abre la app al tocarlos.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data && event.data.text() }; }
    event.waitUntil(self.registration.showNotification(data.title || 'Smartgenda', {
        body: data.body || '',
        tag: data.tag || undefined,
        renotify: !!data.tag,
        icon: '/img/icon-192.png',
        badge: '/img/favicon-32.png',
        data: { url: data.url || '/' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/', self.location.origin).href;
    event.waitUntil((async () => {
        const external = !url.startsWith(self.location.origin);
        if (!external) {
            const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            for (const w of wins) {
                if (w.url.startsWith(self.location.origin)) { await w.focus(); return w.navigate(url); }
            }
        }
        return self.clients.openWindow(url);
    })());
});

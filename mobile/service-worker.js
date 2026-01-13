const CACHE_NAME = 'zendure-mobile-v1';
const ASSETS = [
    './icon.png',
    './manifest.json',
    '../schedule/assets/css/charge_schedule.css',
    '../schedule/assets/css/charge_status.css',
    '../schedule/assets/css/charge_status_defines.css'
];

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(ASSETS);
        })
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(clients.claim());
});

self.addEventListener('fetch', (event) => {
    // For PHP pages (dynamic), try network first, fall back to cache if offline (if cached) working mostly for static
    // Actually for this dashboard we always want network for the main page.
    if (event.request.method !== 'GET') return;

    event.respondWith(
        fetch(event.request)
            .catch(() => {
                return caches.match(event.request);
            })
    );
});

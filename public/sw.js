/*
 * Service worker de la réception hors-ligne (exige HTTPS ou localhost).
 * Rend la page /hors-ligne et ses scripts disponibles sans réseau. Les appels /api/ ne sont jamais mis en cache.
 * Sans HTTPS, le navigateur ne l'exécute pas : le cache HTTP classique (en-têtes de la page) prend le relais, moins fiable.
 */
'use strict';
const VERSION = 'v1';
const CACHE = 'pressing-offline-' + VERSION;
const PAGE = '/hors-ligne';
const CORE = [PAGE, '/assets/app.css', '/assets/offline-core.js', '/assets/offline.js', '/assets/vendor/qrcode.min.js'];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE)
      .then((cache) => Promise.all(CORE.map((u) => cache.add(new Request(u, { credentials: 'same-origin' })).catch(() => null))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('pressing-offline-') && k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/')) {
    return; // laisse passer : jamais de cache pour l'API ni pour les envois
  }

  // Navigation : réseau d'abord ; hors réseau, on retombe sur l'écran de réception hors-ligne
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then((res) => {
          if (url.pathname === PAGE && res.ok && !res.redirected) {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(PAGE, copy));
          }
          return res;
        })
        .catch(() => caches.match(PAGE, { ignoreSearch: true }).then((hit) => hit || Response.error()))
    );
    return;
  }

  // Styles, scripts, QR : cache d'abord, rafraîchis en arrière-plan
  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.match(req, { ignoreSearch: true }).then((hit) => {
        const net = fetch(req).then((res) => {
          if (res.ok) { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(req, copy)); }
          return res;
        }).catch(() => hit);
        return hit || net;
      })
    );
  }
});

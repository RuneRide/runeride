// Service worker de RuneRide (portée /app/) : met l'interface en cache, jamais les réponses de l'API.
const VERSION = 'v1';
const CACHE = `runeride-app-${VERSION}`;
const SHELL = [
  './', 'index.html', 'app.css', 'manifest.webmanifest',
  'js/main.js', 'js/config.js', 'js/api.js', 'js/dom.js', 'js/carte.js', 'js/vues.js',
  'icons/icon.svg', 'icons/icon-192.png', 'icons/icon-512.png', 'icons/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  // Tout ce qui n'est pas de l'interface (API avec jeton, tuiles, CDN, vitrine) passe directement au réseau.
  if (url.origin !== self.location.origin || !url.pathname.startsWith('/app/')) return;

  // Réseau d'abord : on voit toujours la dernière version déployée, le cache sert hors ligne.
  event.respondWith(
    fetch(req)
      .then((res) => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
        }
        return res;
      })
      .catch(() => caches.match(req, { ignoreSearch: true }).then((hit) => hit || caches.match('index.html'))),
  );
});

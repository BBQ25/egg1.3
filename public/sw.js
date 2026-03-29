const CACHE_VERSION = 'egg13-pwa-v1';
const STATIC_CACHE = `${CACHE_VERSION}-assets`;
const OFFLINE_URL = new URL('offline.html', self.registration.scope).toString();
const PRECACHE_URLS = [
  'offline.html',
  'manifest.webmanifest',
  'icons/icon-192.png',
  'icons/icon-512.png',
  'icons/maskable-512.png',
];

function toScopeUrl(path) {
  return new URL(path, self.registration.scope).toString();
}

function toPathInScope(url) {
  const scopePath = new URL(self.registration.scope).pathname.replace(/\/+$/, '');
  const normalizedPath = url.pathname;

  if (scopePath === '') {
    return normalizedPath;
  }

  if (!normalizedPath.startsWith(scopePath)) {
    return null;
  }

  const path = normalizedPath.slice(scopePath.length);
  return path === '' ? '/' : path;
}

function shouldBypassCache(pathInScope) {
  if (!pathInScope) {
    return true;
  }

  if (pathInScope.startsWith('/dashboard/data')) {
    return true;
  }

  if (pathInScope.startsWith('/api/devices/ingest')) {
    return true;
  }

  return false;
}

function isStaticAssetRequest(pathInScope, request) {
  if (!pathInScope) {
    return false;
  }

  if (pathInScope.startsWith('/build/')) {
    return true;
  }

  if (pathInScope.startsWith('/vendor/') || pathInScope.startsWith('/sneat/')) {
    return true;
  }

  if (request.destination === 'style' || request.destination === 'script' || request.destination === 'font' || request.destination === 'image') {
    return true;
  }

  return /\.(?:css|js|mjs|png|jpg|jpeg|gif|svg|webp|ico|woff|woff2|ttf)$/i.test(pathInScope);
}

async function networkOnly(request) {
  return fetch(request);
}

async function networkFirstNavigation(request) {
  try {
    return await fetch(request);
  } catch (error) {
    const cache = await caches.open(STATIC_CACHE);
    const fallback = await cache.match(OFFLINE_URL);
    if (fallback) {
      return fallback;
    }

    return new Response('Offline', {
      status: 503,
      statusText: 'Offline',
      headers: {
        'Content-Type': 'text/plain; charset=UTF-8',
      },
    });
  }
}

async function staleWhileRevalidate(request, event) {
  const cache = await caches.open(STATIC_CACHE);
  const cachedResponse = await cache.match(request);

  const networkPromise = fetch(request)
    .then(async (networkResponse) => {
      if (networkResponse && networkResponse.ok) {
        await cache.put(request, networkResponse.clone());
      }
      return networkResponse;
    })
    .catch(() => null);

  if (cachedResponse) {
    event.waitUntil(networkPromise);
    return cachedResponse;
  }

  const networkResponse = await networkPromise;
  if (networkResponse) {
    return networkResponse;
  }

  return new Response('', { status: 504, statusText: 'Gateway Timeout' });
}

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(STATIC_CACHE);
    await cache.addAll(PRECACHE_URLS.map((path) => toScopeUrl(path)));
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys
        .filter((key) => key.startsWith('egg13-pwa-') && key !== STATIC_CACHE)
        .map((key) => caches.delete(key)),
    );
    await self.clients.claim();
  })());
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.method !== 'GET') {
    return;
  }

  const requestUrl = new URL(request.url);
  if (requestUrl.origin !== self.location.origin) {
    return;
  }

  const pathInScope = toPathInScope(requestUrl);
  if (!pathInScope) {
    return;
  }

  if (shouldBypassCache(pathInScope)) {
    event.respondWith(networkOnly(request));
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(networkFirstNavigation(request));
    return;
  }

  if (isStaticAssetRequest(pathInScope, request)) {
    event.respondWith(staleWhileRevalidate(request, event));
  }
});

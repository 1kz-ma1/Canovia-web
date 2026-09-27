const CACHE_VERSION = 'canovia-shell-v41-16-1';
const META_CACHE = 'canovia-instant-meta-v2';
const LAST_NETWORK_KEY = '/__canovia_last_network_success__';
const LIKELY_SLEEP_AFTER_MS = 12 * 60 * 1000;
const RECENT_NETWORK_TIMEOUT_MS = 8000;

// Instant Start is intentionally limited to the four core shell surfaces.
// Everything else, especially AI/import/auth/PWA-handoff/admin flows, stays on
// the browser's normal network path.
const INSTANT_START_PATHS = new Set([
    '/',
    '/navigate',
    '/inbox',
    '/roadmap',
]);

const STATIC_ASSETS = [
    '/offline.html',
    '/icons/icon-180.png',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/brand/logo-mark.svg',
    '/brand/app-icon.svg',
    '/brand/mascot-guide.webp',
];

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE_VERSION);

        // offline.html is the only required fallback. Decorative assets are
        // best-effort so one missing image never prevents the worker installing.
        await cache.add('/offline.html');
        await Promise.all(
            STATIC_ASSETS
                .filter((path) => path !== '/offline.html')
                .map((path) => cache.add(path).catch(() => null))
        );

        await self.skipWaiting();
    })());
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        void self.skipWaiting();
        return;
    }

    if (event.data?.type === 'MARK_NETWORK_SUCCESS') {
        event.waitUntil?.(rememberNetworkSuccess());
    }
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(
            keys
                .filter((key) => (
                    key.startsWith('canovia-shell-')
                    || key.startsWith('pacekeeper-shell-')
                ) && key !== CACHE_VERSION)
                .map((key) => caches.delete(key))
        );

        if (self.registration.navigationPreload) {
            // Navigation Preload is registration-wide. Enabling it while only
            // consuming preloadResponse on Instant Start routes causes every
            // other navigation (AI practice, auth, admin, etc.) to hit Laravel
            // twice: once as an unused preload and once as the normal request.
            // Keep it disabled so network-only routes truly make one GET.
            await self.registration.navigationPreload.disable().catch(() => {});
        }

        await self.clients.claim();
    })());
});

async function rememberNetworkSuccess() {
    try {
        const cache = await caches.open(META_CACHE);
        await cache.put(LAST_NETWORK_KEY, new Response(String(Date.now()), {
            headers: { 'Content-Type': 'text/plain' },
        }));
    } catch (_) {}
}

async function lastNetworkSuccessAt() {
    try {
        const cache = await caches.open(META_CACHE);
        const response = await cache.match(LAST_NETWORK_KEY);
        if (!response) return 0;
        const value = Number(await response.text());
        return Number.isFinite(value) ? value : 0;
    } catch (_) {
        return 0;
    }
}

function markServerWarm(response) {
    if (response?.ok) {
        void rememberNetworkSuccess();
    }
    return response;
}

function timeoutAfter(ms) {
    return new Promise((_, reject) => {
        setTimeout(() => reject(new Error('network-timeout')), ms);
    });
}

function isInstantStartNavigation(request, url) {
    return request.mode === 'navigate' && INSTANT_START_PATHS.has(url.pathname);
}

async function offlineShell() {
    return (await caches.match('/offline.html'))
        || fetch('/offline.html', { cache: 'no-store', credentials: 'same-origin' });
}

async function navigationNetworkResponse(event, request) {
    const preload = event.preloadResponse
        ? await event.preloadResponse.catch(() => null)
        : null;

    if (preload) return markServerWarm(preload);

    return fetch(request, {
        cache: 'no-store',
        credentials: 'same-origin',
    }).then(markServerWarm);
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    // Core safety boundary: no mutation/API/cross-origin request is intercepted.
    // V39's POST idempotency and Laravel redirects therefore remain authoritative.
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    if (isInstantStartNavigation(request, url)) {
        // offline.html sets this when /health confirms the server is ready.
        // The resumed request is network-only so we can never loop back to shell.
        if (
            url.searchParams.get('_canovia_network') === '1'
            || url.searchParams.get('_pk_network') === '1'
        ) {
            event.respondWith(
                navigationNetworkResponse(event, request)
                    .catch(() => offlineShell())
            );
            return;
        }

        const networkPromise = navigationNetworkResponse(event, request);

        // Keep the wake-up request alive even when we immediately render the
        // local shell. This is a GET only; no mutation is replayed in background.
        event.waitUntil(networkPromise.then(() => undefined).catch(() => undefined));

        event.respondWith((async () => {
            const lastSuccess = await lastNetworkSuccessAt();
            const likelySleeping = !lastSuccess
                || (Date.now() - lastSuccess) >= LIKELY_SLEEP_AFTER_MS;

            if (likelySleeping) {
                return offlineShell();
            }

            return Promise.race([
                networkPromise,
                timeoutAfter(RECENT_NETWORK_TIMEOUT_MS),
            ]).catch(() => offlineShell());
        })());
        return;
    }

    // Only immutable shell assets get cache-first treatment. All other GETs,
    // including auth, AI, PWA handoff, admin and plan-detail pages, bypass SW.
    if (STATIC_ASSETS.includes(url.pathname)) {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request))
        );
    }
});

import { isCanoviaNativeRuntime } from './client-runtime.mjs';

const SERVICE_WORKER_URL = '/sw.js';

function postWorkerMessage(worker, message) {
    try {
        worker?.postMessage?.(message);
    } catch (_) {}
}

function markCurrentPageAsNetworkSuccess(serviceWorker, registration) {
    const worker = registration?.active || serviceWorker?.controller || registration?.waiting;
    postWorkerMessage(worker, { type: 'MARK_NETWORK_SUCCESS' });
}

/**
 * Register the narrow V39.1 Instant Start worker.
 *
 * The worker is deliberately navigation-only. Mutation requests remain owned by
 * the browser/Laravel path so V39 idempotency and native POST/redirect behavior
 * are not changed by PWA support.
 */
export async function mountInstantStartServiceWorker({ navigatorRef = globalThis.navigator, windowRef = globalThis.window } = {}) {
    const serviceWorker = navigatorRef?.serviceWorker;

    if (isCanoviaNativeRuntime(windowRef)) {
        try {
            const registrations = await serviceWorker?.getRegistrations?.();
            await Promise.all(
                (registrations || []).map((registration) => registration.unregister?.())
            );
        } catch (_) {}

        return null;
    }

    if (!serviceWorker || !windowRef?.isSecureContext) return null;

    try {
        const registration = await serviceWorker.register(SERVICE_WORKER_URL, {
            updateViaCache: 'none',
        });

        // Repair registrations created by older workers that enabled Navigation
        // Preload. Disabling from the page context takes effect without waiting
        // for the newly installed worker to become active.
        await registration.navigationPreload?.disable?.().catch(() => {});

        if (registration.waiting) {
            postWorkerMessage(registration.waiting, { type: 'SKIP_WAITING' });
        }

        registration.addEventListener?.('updatefound', () => {
            const worker = registration.installing;
            if (!worker) return;

            worker.addEventListener?.('statechange', () => {
                if (worker.state === 'installed' && serviceWorker.controller) {
                    postWorkerMessage(worker, { type: 'SKIP_WAITING' });
                }
                if (worker.state === 'activated') {
                    markCurrentPageAsNetworkSuccess(serviceWorker, registration);
                }
            });
        });

        // This page itself came from Laravel successfully. Tell the worker so a
        // navigation immediately after registration never flashes the shell.
        markCurrentPageAsNetworkSuccess(serviceWorker, registration);
        void serviceWorker.ready
            ?.then((readyRegistration) => markCurrentPageAsNetworkSuccess(serviceWorker, readyRegistration))
            .catch(() => {});

        // /sw.js is no-cache in Nginx, so this cheaply picks up new deployments.
        void registration.update?.().catch(() => {});

        return registration;
    } catch (_) {
        // PWA support must never make the normal online app fail to boot.
        return null;
    }
}

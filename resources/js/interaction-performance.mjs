import { canoviaClientPlatform, canoviaClientSurface } from './client-runtime.mjs';

const CORE_PERFORMANCE_PATHS = new Set([
    '/',
    '/map',
    '/inbox',
    '/roadmap',
    '/timeline',
    '/calendar',
    '/navigate',
]);

const MAX_PERFORMANCE_ENTRIES = 80;

function clientDevice(windowRef) {
    return /iPhone|iPad|iPod|Android|Mobile/i.test(windowRef.navigator?.userAgent || '')
        ? 'mobile'
        : 'desktop';
}

function safePath(value, windowRef) {
    try {
        return new URL(value || windowRef.location.href, windowRef.location.href).pathname || '/';
    } catch (_) {
        return windowRef.location.pathname || '/';
    }
}

function rounded(value, digits = 2) {
    const number = Number(value);
    if (!Number.isFinite(number)) return 0;
    const multiplier = 10 ** digits;
    return Math.max(0, Math.round(number * multiplier) / multiplier);
}

function afterTwoFrames(windowRef, callback) {
    const raf = typeof windowRef.requestAnimationFrame === 'function'
        ? windowRef.requestAnimationFrame.bind(windowRef)
        : (fn) => windowRef.setTimeout(fn, 16);

    raf(() => raf(callback));
}

export function mountCanoviaInteractionPerformance({
    documentRef = globalThis.document,
    windowRef = globalThis.window,
    fetchRef = globalThis.fetch,
} = {}) {
    if (!documentRef || !windowRef || typeof fetchRef !== 'function') return null;
    if (windowRef.__canoviaInteractionPerformance) return windowRef.__canoviaInteractionPerformance;

    const endpoint = documentRef.querySelector('meta[name="canovia-client-performance-url"]')?.content;
    const csrfToken = documentRef.querySelector('meta[name="csrf-token"]')?.content;
    if (!endpoint || !csrfToken) return null;
    if (!CORE_PERFORMANCE_PATHS.has(windowRef.location.pathname)) return null;

    const performanceRef = windowRef.performance;
    const longTasks = [];
    const layoutShifts = [];
    const observers = [];
    const queue = [];
    let disposed = false;
    let flushHandle = null;

    const trimEntries = (entries) => {
        if (entries.length > MAX_PERFORMANCE_ENTRIES) {
            entries.splice(0, entries.length - MAX_PERFORMANCE_ENTRIES);
        }
    };

    if (typeof windowRef.PerformanceObserver === 'function' && performanceRef) {
        const supported = windowRef.PerformanceObserver.supportedEntryTypes || [];

        if (supported.includes('longtask')) {
            try {
                const observer = new windowRef.PerformanceObserver((list) => {
                    list.getEntries().forEach((entry) => {
                        longTasks.push({
                            startTime: entry.startTime,
                            duration: entry.duration,
                        });
                    });
                    trimEntries(longTasks);
                });
                observer.observe({ type: 'longtask', buffered: true });
                observers.push(observer);
            } catch (_) {}
        }

        if (supported.includes('layout-shift')) {
            try {
                const observer = new windowRef.PerformanceObserver((list) => {
                    list.getEntries().forEach((entry) => {
                        if (entry.hadRecentInput) return;
                        layoutShifts.push({
                            startTime: entry.startTime,
                            value: entry.value,
                        });
                    });
                    trimEntries(layoutShifts);
                });
                observer.observe({ type: 'layout-shift', buffered: true });
                observers.push(observer);
            } catch (_) {}
        }
    }

    const interactionWindow = (startedAt, completedAt) => {
        const start = Number(startedAt);
        const end = Number(completedAt);
        if (!Number.isFinite(start) || !Number.isFinite(end) || end < start) {
            return {
                long_task_count: 0,
                long_task_ms: 0,
                layout_shift: 0,
            };
        }

        const matchingLongTasks = longTasks.filter((entry) => (
            entry.startTime >= start && entry.startTime <= end
        ));
        const matchingShifts = layoutShifts.filter((entry) => (
            entry.startTime >= start && entry.startTime <= end
        ));

        return {
            long_task_count: matchingLongTasks.length,
            long_task_ms: rounded(
                matchingLongTasks.reduce((sum, entry) => sum + entry.duration, 0)
            ),
            layout_shift: rounded(
                matchingShifts.reduce((sum, entry) => sum + entry.value, 0),
                4,
            ),
        };
    };

    const flush = () => {
        flushHandle = null;
        if (disposed || queue.length === 0) return;

        const payloads = queue.splice(0, queue.length);
        payloads.forEach((payload) => {
            fetchRef(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
                keepalive: true,
                body: JSON.stringify(payload),
            }).catch(() => {});
        });
    };

    const scheduleFlush = () => {
        if (flushHandle !== null || disposed) return;

        if (typeof windowRef.requestIdleCallback === 'function') {
            flushHandle = windowRef.requestIdleCallback(flush, { timeout: 1500 });
            return;
        }

        flushHandle = windowRef.setTimeout(flush, 700);
    };

    const enqueue = ({
        type,
        path,
        route = null,
        source = null,
        metrics,
    }) => {
        if (disposed || !metrics || Object.keys(metrics).length === 0) return;

        queue.push({
            type,
            path: safePath(path, windowRef),
            route: route || documentRef.body?.dataset.routeName || null,
            source,
            surface: canoviaClientSurface(windowRef),
            device: clientDevice(windowRef),
            platform: canoviaClientPlatform(windowRef),
            metrics,
        });

        if (queue.length > 8) queue.splice(0, queue.length - 8);
        scheduleFlush();
    };

    const onInstantNavigationPerformance = (event) => {
        const detail = event.detail || {};
        const completedAt = Number(detail.completed_at);
        const startedAt = Number(detail.started_at);
        const interaction = interactionWindow(startedAt, completedAt);

        enqueue({
            type: 'instant_navigation',
            path: detail.path,
            route: detail.route_name,
            source: detail.source || 'programmatic',
            metrics: {
                total_ms: rounded(detail.total_ms),
                wait_ms: rounded(detail.wait_ms),
                fetch_ms: rounded(detail.fetch_ms),
                parse_ms: rounded(detail.parse_ms),
                render_ms: rounded(detail.render_ms),
                mount_ms: rounded(detail.mount_ms),
                frame_ready_ms: rounded(detail.frame_ready_ms),
                response_bytes: Math.max(0, Math.round(Number(detail.response_bytes) || 0)),
                ...interaction,
            },
        });
    };

    const onSurfaceMounted = (event) => {
        const detail = event.detail || {};
        if (detail.instant === true) return;

        enqueue({
            type: 'surface_mount',
            path: detail.path || windowRef.location.pathname,
            route: detail.route_name,
            source: 'initial',
            metrics: {
                mount_ms: rounded(detail.mount_ms),
            },
        });
    };

    documentRef.addEventListener(
        'canovia:instant-navigation-performance',
        onInstantNavigationPerformance,
    );
    documentRef.addEventListener('canovia:surface-mounted', onSurfaceMounted);

    const recordInitialLoad = () => {
        if (!performanceRef) return;

        afterTwoFrames(windowRef, () => {
            const navigation = performanceRef.getEntriesByType?.('navigation')?.[0];
            if (!navigation) return;

            const completedAt = performanceRef.now();
            const interaction = interactionWindow(0, completedAt);

            enqueue({
                type: 'initial_load',
                path: windowRef.location.pathname,
                route: documentRef.body?.dataset.routeName || null,
                source: 'initial',
                metrics: {
                    total_ms: rounded(
                        navigation.loadEventEnd || navigation.duration || completedAt
                    ),
                    ttfb_ms: rounded(navigation.responseStart),
                    dom_content_loaded_ms: rounded(navigation.domContentLoadedEventEnd),
                    load_ms: rounded(navigation.loadEventEnd),
                    response_bytes: Math.max(
                        0,
                        Math.round(Number(navigation.transferSize || navigation.encodedBodySize) || 0),
                    ),
                    ...interaction,
                },
            });
        });
    };

    if (documentRef.readyState === 'complete') {
        recordInitialLoad();
    } else {
        windowRef.addEventListener('load', recordInitialLoad, { once: true });
    }

    const api = {
        enqueue,
        dispose() {
            if (disposed) return;
            disposed = true;

            documentRef.removeEventListener(
                'canovia:instant-navigation-performance',
                onInstantNavigationPerformance,
            );
            documentRef.removeEventListener('canovia:surface-mounted', onSurfaceMounted);
            observers.forEach((observer) => observer.disconnect());

            if (flushHandle !== null) {
                if (typeof windowRef.cancelIdleCallback === 'function') {
                    windowRef.cancelIdleCallback(flushHandle);
                } else {
                    windowRef.clearTimeout(flushHandle);
                }
            }
        },
    };

    windowRef.__canoviaInteractionPerformance = api;
    return api;
}

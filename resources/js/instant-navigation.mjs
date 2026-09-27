const DEFAULT_CORE_PATHS = new Set([
    '/',
    '/inbox',
    '/roadmap',
    '/timeline',
    '/calendar',
]);

const CORE_BUNDLE_SURFACES = new Map([
    ['/', 'home'],
    ['/roadmap', 'roadmap'],
    ['/timeline', 'timeline'],
    ['/calendar', 'calendar'],
]);

function normalizedUrl(value, windowRef) {
    const url = new URL(value, windowRef.location.href);
    for (const key of ['_pk_network', '_canovia_network', '_canovia_update', '_canovia_stable']) {
        url.searchParams.delete(key);
    }
    url.hash = '';
    return url;
}

function cacheKey(url) {
    return url.pathname + url.search;
}

function feedbackContextFrom(documentRef) {
    const form = documentRef.querySelector('[data-feedback-dialog] form');
    if (!form) return null;

    return {
        page: form.querySelector('input[name="page"]')?.value || '',
        planId: form.querySelector('input[name="plan_id"]')?.value || '',
        taskId: form.querySelector('input[name="task_id"]')?.value || '',
    };
}

function payloadFromDocument(documentRef, url) {
    const page = documentRef.querySelector('[data-canovia-page]');
    if (!page) return null;

    let fragmentMeta = null;
    const fragmentMetaElement = documentRef.getElementById('canovia-instant-meta');
    if (fragmentMetaElement) {
        try {
            fragmentMeta = JSON.parse(fragmentMetaElement.textContent || 'null');
        } catch (_) {
            fragmentMeta = null;
        }
    }

    const nav = fragmentMeta?.nav && typeof fragmentMeta.nav === 'object'
        ? fragmentMeta.nav
        : {};

    if (!fragmentMeta?.nav) {
        documentRef.querySelectorAll('[data-canovia-nav-key]').forEach((element) => {
            const key = element.dataset.canoviaNavKey;
            if (!key) return;
            nav[key] = {
                className: element.className,
                ariaCurrent: element.getAttribute('aria-current'),
            };
        });
    }

    const pageSnapshot = page.cloneNode(true);
    pageSnapshot.querySelectorAll('[data-canovia-instant-initialized]').forEach((element) => {
        element.removeAttribute('data-canovia-instant-initialized');
    });
    pageSnapshot.querySelectorAll('.is-leaving-left, .is-leaving-right').forEach((element) => {
        element.classList.remove('is-leaving-left', 'is-leaving-right');
    });

    return {
        url: cacheKey(url),
        title: documentRef.title,
        routeName: documentRef.body?.dataset.routeName || '',
        pageHtml: pageSnapshot.innerHTML,
        companionHtml: documentRef.querySelector('[data-canovia-companion-slot]')?.innerHTML || '',
        mobileSection: fragmentMeta?.mobileSection
            || documentRef.querySelector('[data-mobile-section-label]')?.textContent
            || '',
        nav,
        feedbackContext: fragmentMeta?.feedbackContext || feedbackContextFrom(documentRef),
        capturedAt: Date.now(),
    };
}

function syncFeedbackContext(documentRef, context) {
    if (!context) return;

    const form = documentRef.querySelector('[data-feedback-dialog] form');
    if (!form) return;

    const sync = (name, value) => {
        let input = form.querySelector(`input[name="${name}"]`);
        if (!value) {
            input?.remove();
            return;
        }
        if (!input) {
            input = documentRef.createElement('input');
            input.type = 'hidden';
            input.name = name;
            form.prepend(input);
        }
        input.value = value;
    };

    sync('page', context.page);
    sync('plan_id', context.planId);
    sync('task_id', context.taskId);
}

export function mountCanoviaInstantNavigation({
    documentRef = globalThis.document,
    windowRef = globalThis.window,
    fetchRef = globalThis.fetch,
    corePaths = DEFAULT_CORE_PATHS,
} = {}) {
    if (!documentRef || !windowRef || typeof fetchRef !== 'function') return null;
    if (!documentRef.querySelector('[data-canovia-page]')) return null;

    const initialUrl = normalizedUrl(windowRef.location.href, windowRef);
    if (!corePaths.has(initialUrl.pathname)) return null;

    const cache = new Map();
    const inflight = new Map();
    const bundleInflight = new Map();
    let disposed = false;
    let navigationSerial = 0;
    let prefetchTimer = null;

    const isCoreUrl = (value) => {
        let url;
        try {
            url = normalizedUrl(value, windowRef);
        } catch (_) {
            return false;
        }
        return url.origin === windowRef.location.origin && corePaths.has(url.pathname);
    };

    const captureCurrent = () => {
        const url = normalizedUrl(windowRef.location.href, windowRef);
        const payload = payloadFromDocument(documentRef, url);
        if (payload) cache.set(cacheKey(url), payload);
        return payload;
    };

    const parsePayload = (html, requestedUrl) => {
        const parser = new windowRef.DOMParser();
        const nextDocument = parser.parseFromString(html, 'text/html');
        return payloadFromDocument(nextDocument, requestedUrl);
    };

    const fetchPayload = async (value, mode = 'navigate') => {
        const url = normalizedUrl(value, windowRef);
        const key = cacheKey(url);
        const inflightKey = `${mode}:${key}`;

        if (inflight.has(inflightKey)) return inflight.get(inflightKey);

        const request = fetchRef(url.pathname + url.search, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'text/html',
                'X-Canovia-Instant-Navigation': mode,
            },
        }).then(async (response) => {
            const responseUrl = normalizedUrl(response.url || url.href, windowRef);
            if (!response.ok || !isCoreUrl(responseUrl)) {
                throw Object.assign(new Error('instant-navigation-unavailable'), {
                    fallbackUrl: response.url || url.href,
                });
            }

            const payload = parsePayload(await response.text(), responseUrl);
            if (!payload) {
                throw Object.assign(new Error('instant-navigation-payload-missing'), {
                    fallbackUrl: response.url || url.href,
                });
            }

            cache.set(cacheKey(responseUrl), payload);
            return payload;
        }).finally(() => {
            inflight.delete(inflightKey);
        });

        inflight.set(inflightKey, request);
        return request;
    };

    const prefetchBundle = async (values) => {
        const urls = values
            .map((value) => normalizedUrl(value, windowRef))
            .filter((url) => (
                url.origin === windowRef.location.origin
                && CORE_BUNDLE_SURFACES.has(url.pathname)
                && !cache.has(cacheKey(url))
            ));

        if (urls.length === 0) return new Map();

        const surfaces = [...new Set(urls.map((url) => CORE_BUNDLE_SURFACES.get(url.pathname)))];
        const bundleUrl = new URL('/instant/core-bundle', windowRef.location.origin);
        bundleUrl.searchParams.set('surfaces', surfaces.join(','));

        const roadmap = urls.find((url) => url.pathname === '/roadmap');
        if (roadmap?.searchParams.get('plan_id')) {
            bundleUrl.searchParams.set('roadmap_plan_id', roadmap.searchParams.get('plan_id'));
        }

        const calendar = urls.find((url) => url.pathname === '/calendar');
        if (calendar) {
            const mapping = {
                view: 'calendar_view',
                date: 'calendar_date',
                selected: 'calendar_selected',
            };
            Object.entries(mapping).forEach(([source, target]) => {
                const value = calendar.searchParams.get(source);
                if (value) bundleUrl.searchParams.set(target, value);
            });
        }

        const bundleKey = bundleUrl.pathname + bundleUrl.search;
        if (inflight.has(bundleKey)) return inflight.get(bundleKey);

        const request = fetchRef(bundleKey, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'X-Canovia-Instant-Navigation': 'prefetch',
            },
        }).then(async (response) => {
            if (!response.ok) throw new Error('instant-core-bundle-unavailable');

            const body = await response.json();
            if (!body?.fragments || typeof body.fragments !== 'object') {
                throw new Error('instant-core-bundle-invalid');
            }

            const loaded = new Map();
            Object.entries(body.fragments).forEach(([path, html]) => {
                if (typeof html !== 'string') return;
                const targetUrl = normalizedUrl(path, windowRef);
                const payload = parsePayload(html, targetUrl);
                if (!payload) return;
                cache.set(cacheKey(targetUrl), payload);
                loaded.set(cacheKey(targetUrl), payload);
            });

            return loaded;
        }).finally(() => {
            inflight.delete(bundleKey);
            urls.forEach((url) => bundleInflight.delete(cacheKey(url)));
        });

        inflight.set(bundleKey, request);
        urls.forEach((url) => {
            const key = cacheKey(url);
            bundleInflight.set(key, request.then(() => cache.get(key) || null));
        });

        return request;
    };

    const render = (payload, { historyMode = 'push', scroll = true } = {}) => {
        const page = documentRef.querySelector('[data-canovia-page]');
        if (!page || !payload) return false;

        page.innerHTML = payload.pageHtml;
        documentRef.title = payload.title || 'Canovia';
        if (documentRef.body) documentRef.body.dataset.routeName = payload.routeName || '';

        const companionSlot = documentRef.querySelector('[data-canovia-companion-slot]');
        if (companionSlot) companionSlot.innerHTML = payload.companionHtml || '';

        const mobileSection = documentRef.querySelector('[data-mobile-section-label]');
        if (mobileSection && payload.mobileSection) mobileSection.textContent = payload.mobileSection;

        documentRef.querySelectorAll('[data-canovia-nav-key]').forEach((element) => {
            const state = payload.nav?.[element.dataset.canoviaNavKey];
            if (!state) return;
            element.className = state.className;
            if (state.ariaCurrent === null) element.removeAttribute('aria-current');
            else element.setAttribute('aria-current', state.ariaCurrent);
        });

        syncFeedbackContext(documentRef, payload.feedbackContext);

        if (historyMode === 'push') {
            windowRef.history.pushState({ canoviaInstant: true }, '', payload.url);
        } else if (historyMode === 'replace') {
            windowRef.history.replaceState({ canoviaInstant: true }, '', payload.url);
        }

        if (scroll) windowRef.scrollTo({ top: 0, left: 0, behavior: 'auto' });

        documentRef.dispatchEvent(new windowRef.CustomEvent('canovia:page-ready', {
            detail: {
                url: payload.url,
                routeName: payload.routeName,
                instant: true,
            },
        }));

        return true;
    };

    const revalidate = (url) => {
        void fetchPayload(url, 'navigate').catch(() => {});
    };

    const withUncachedFeedback = async (callback) => {
        const overlay = documentRef.querySelector('[data-route-loading]');
        const timer = windowRef.setTimeout(() => {
            overlay?.classList.add('is-visible');
            overlay?.setAttribute('aria-hidden', 'false');
        }, 120);

        try {
            return await callback();
        } finally {
            windowRef.clearTimeout(timer);
            overlay?.classList.remove('is-visible');
            overlay?.setAttribute('aria-hidden', 'true');
        }
    };

    const navigate = async (value, {
        historyMode = 'push',
        scroll = true,
        fallback = true,
    } = {}) => {
        const url = normalizedUrl(value, windowRef);
        if (!isCoreUrl(url)) {
            if (fallback) windowRef.location.assign(url.href);
            return false;
        }

        const key = cacheKey(url);
        const serial = ++navigationSerial;
        captureCurrent();

        const cached = cache.get(key);
        if (cached) {
            render(cached, { historyMode, scroll });
            revalidate(url);
            return true;
        }

        const prefetched = inflight.get(`prefetch:${key}`) || bundleInflight.get(key);
        try {
            const payload = await withUncachedFeedback(() => (
                prefetched
                    ? prefetched
                    : fetchPayload(url, 'navigate')
            ));

            if (serial !== navigationSerial || disposed) return false;
            render(payload, { historyMode, scroll });

            if (prefetched) revalidate(url);
            return true;
        } catch (error) {
            if (fallback) windowRef.location.assign(error?.fallbackUrl || url.href);
            return false;
        }
    };

    const prefetch = (value) => {
        if (disposed || !isCoreUrl(value)) return Promise.resolve(null);

        const url = normalizedUrl(value, windowRef);
        const key = cacheKey(url);
        if (key === cacheKey(normalizedUrl(windowRef.location.href, windowRef))) return Promise.resolve(cache.get(key) || null);
        if (cache.has(key)) return Promise.resolve(cache.get(key));
        if (bundleInflight.has(key)) return bundleInflight.get(key);

        return fetchPayload(url, 'prefetch').catch(() => null);
    };

    const linkForEvent = (event) => {
        const link = event.target?.closest?.('a[href]');
        if (!link) return null;
        if (link.target === '_blank' || link.hasAttribute('download') || link.hasAttribute('data-instant-nav-skip')) return null;
        if (link.href.startsWith('mailto:') || link.href.startsWith('tel:')) return null;
        return isCoreUrl(link.href) ? link : null;
    };

    const onClick = (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = linkForEvent(event);
        if (!link) return;

        const target = normalizedUrl(link.href, windowRef);
        const current = normalizedUrl(windowRef.location.href, windowRef);
        if (target.pathname === current.pathname && target.search === current.search && link.hash) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        void navigate(target, { historyMode: 'push', scroll: true });
    };

    const onIntent = (event) => {
        const link = linkForEvent(event);
        if (link) void prefetch(link.href);
    };

    const onPopState = () => {
        void navigate(windowRef.location.href, {
            historyMode: 'none',
            scroll: true,
            fallback: true,
        });
    };

    documentRef.addEventListener('click', onClick, true);
    documentRef.addEventListener('pointerover', onIntent, true);
    documentRef.addEventListener('focusin', onIntent, true);
    documentRef.addEventListener('touchstart', onIntent, { capture: true, passive: true });
    windowRef.addEventListener('popstate', onPopState);

    captureCurrent();
    windowRef.history.replaceState({ ...(windowRef.history.state || {}), canoviaInstant: true }, '', windowRef.location.href);

    const scheduleIdlePrefetch = () => {
        const connection = windowRef.navigator?.connection;
        if (connection?.saveData || /(^|-)2g$/.test(connection?.effectiveType || '')) return;

        const urls = [...documentRef.querySelectorAll('[data-canovia-nav-key][href]')]
            .map((link) => link.href)
            .filter((href, index, all) => isCoreUrl(href) && all.indexOf(href) === index);

        const currentKey = cacheKey(normalizedUrl(windowRef.location.href, windowRef));
        const pending = urls.filter((href) => cacheKey(normalizedUrl(href, windowRef)) !== currentKey);
        const bundled = pending.filter((href) => CORE_BUNDLE_SURFACES.has(normalizedUrl(href, windowRef).pathname));
        const individual = pending.filter((href) => !CORE_BUNDLE_SURFACES.has(normalizedUrl(href, windowRef).pathname));

        const warm = async () => {
            if (disposed) return;

            if (bundled.length > 0) {
                try {
                    await prefetchBundle(bundled);
                } catch (_) {
                    for (const href of bundled) {
                        if (disposed) return;
                        await prefetch(href);
                    }
                }
            }

            for (const href of individual) {
                if (disposed) return;
                await prefetch(href);
            }
        };

        const idle = windowRef.requestIdleCallback || ((callback) => windowRef.setTimeout(callback, 650));
        idle(() => { void warm(); }, { timeout: 1400 });
    };

    scheduleIdlePrefetch();

    const api = {
        navigate,
        prefetch,
        prefetchBundle,
        cache,
        dispose() {
            disposed = true;
            if (prefetchTimer) windowRef.clearTimeout(prefetchTimer);
            documentRef.removeEventListener('click', onClick, true);
            documentRef.removeEventListener('pointerover', onIntent, true);
            documentRef.removeEventListener('focusin', onIntent, true);
            documentRef.removeEventListener('touchstart', onIntent, true);
            windowRef.removeEventListener('popstate', onPopState);
        },
    };

    windowRef.CanoviaInstantNavigation = api;
    return api;
}

export { DEFAULT_CORE_PATHS, payloadFromDocument };

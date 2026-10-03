const NATIVE_BRIDGE_VERSION = 1;
const NATIVE_USER_AGENT = /CanoviaNative\/(iOS|Android)(?:\/([^\s]+))?/i;

function normalizedPlatform(value) {
    const platform = String(value || '').toLowerCase();
    if (platform === 'ios') return 'ios';
    if (platform === 'android') return 'android';
    return 'other';
}

export function detectCanoviaRuntime(windowRef = globalThis.window) {
    const navigatorRef = windowRef?.navigator;
    const userAgent = navigatorRef?.userAgent || '';
    const injected = windowRef?.__CANOVIA_NATIVE__;
    const userAgentMatch = userAgent.match(NATIVE_USER_AGENT);

    if (injected?.platform || userAgentMatch) {
        const injectedPlatform = normalizedPlatform(injected?.platform);
        const userAgentPlatform = normalizedPlatform(userAgentMatch?.[1]);
        const platform = injectedPlatform !== 'other'
            ? injectedPlatform
            : userAgentPlatform;

        return {
            surface: 'native',
            platform,
            native: true,
            pwa: false,
            bridgeVersion: Math.max(
                1,
                Number(injected?.bridgeVersion || NATIVE_BRIDGE_VERSION) || NATIVE_BRIDGE_VERSION,
            ),
            appVersion: String(injected?.appVersion || userAgentMatch?.[2] || ''),
        };
    }

    const pwa = Boolean(
        windowRef?.matchMedia?.('(display-mode: standalone)')?.matches
        || navigatorRef?.standalone === true
    );

    let platform = 'other';
    if (/iPhone|iPad|iPod/i.test(userAgent)) platform = 'ios';
    else if (/Android/i.test(userAgent)) platform = 'android';

    return {
        surface: pwa ? 'pwa' : 'web',
        platform,
        native: false,
        pwa,
        bridgeVersion: 0,
        appVersion: '',
    };
}

export function canoviaClientSurface(windowRef = globalThis.window) {
    return detectCanoviaRuntime(windowRef).surface;
}

export function canoviaClientPlatform(windowRef = globalThis.window) {
    return detectCanoviaRuntime(windowRef).platform;
}

export function isCanoviaNativeRuntime(windowRef = globalThis.window) {
    return detectCanoviaRuntime(windowRef).native;
}

function nativeHandler(windowRef) {
    return windowRef?.webkit?.messageHandlers?.canovia;
}

export function postCanoviaNativeMessage(
    type,
    payload = {},
    windowRef = globalThis.window,
) {
    if (!isCanoviaNativeRuntime(windowRef)) return false;

    const handler = nativeHandler(windowRef);
    if (typeof handler?.postMessage !== 'function') return false;

    try {
        handler.postMessage({
            version: NATIVE_BRIDGE_VERSION,
            type: String(type || ''),
            payload: payload && typeof payload === 'object' ? payload : {},
        });
        return true;
    } catch (_) {
        return false;
    }
}

function safeSameOriginUrl(value, windowRef) {
    try {
        const url = new URL(value, windowRef.location.href);
        return url.origin === windowRef.location.origin ? url : null;
    } catch (_) {
        return null;
    }
}

function externalHttpUrl(value, windowRef) {
    try {
        const url = new URL(value, windowRef.location.href);
        if (!['http:', 'https:'].includes(url.protocol)) return null;
        return url.origin !== windowRef.location.origin ? url : null;
    } catch (_) {
        return null;
    }
}

export function mountCanoviaNativeBridge({
    documentRef = globalThis.document,
    windowRef = globalThis.window,
} = {}) {
    if (!documentRef || !windowRef) return null;

    const runtime = detectCanoviaRuntime(windowRef);
    if (!runtime.native) return null;
    if (windowRef.CanoviaNativeBridge?.version === NATIVE_BRIDGE_VERSION) {
        return windowRef.CanoviaNativeBridge;
    }

    let disposed = false;

    documentRef.documentElement.dataset.canoviaRuntime = 'native';
    documentRef.documentElement.dataset.canoviaPlatform = runtime.platform;
    documentRef.documentElement.dataset.canoviaBridgeVersion = String(NATIVE_BRIDGE_VERSION);

    const navigationState = () => ({
        path: windowRef.location.pathname + windowRef.location.search,
        route: documentRef.body?.dataset.routeName || null,
        can_go_back: windowRef.history.length > 1,
    });

    const notifyNavigation = () => {
        postCanoviaNativeMessage('navigationState', navigationState(), windowRef);
    };

    const annotateFileInputs = (root = documentRef) => {
        root.querySelectorAll?.('input[type="file"]').forEach((input) => {
            input.dataset.canoviaNativeFileInput = '1';
        });
    };

    const openPath = (value) => {
        const url = safeSameOriginUrl(value, windowRef);
        if (!url) return false;

        const instant = windowRef.CanoviaInstantNavigation;
        if (typeof instant?.navigate === 'function') {
            void instant.navigate(url, {
                historyMode: 'push',
                scroll: true,
                fallback: true,
            });
            return true;
        }

        windowRef.location.assign(url.href);
        return true;
    };

    const handleBack = () => {
        if (windowRef.history.length > 1) {
            windowRef.history.back();
            return true;
        }

        return postCanoviaNativeMessage('requestClose', {
            reason: 'history_empty',
            ...navigationState(),
        }, windowRef);
    };

    const receive = (message) => {
        if (!message || typeof message !== 'object') return false;

        switch (message.type) {
            case 'back':
                return handleBack();
            case 'openPath':
                return openPath(message.path);
            case 'appBecameActive':
                documentRef.dispatchEvent(new windowRef.CustomEvent('canovia:native-resume'));
                notifyNavigation();
                return true;
            default:
                return false;
        }
    };

    const onClick = (event) => {
        const link = event.target?.closest?.('a[href]');
        if (!link) return;

        const external = externalHttpUrl(link.href, windowRef);
        if (!external) return;

        event.preventDefault();

        const handled = postCanoviaNativeMessage('openExternal', {
            url: external.href,
        }, windowRef);

        if (!handled) {
            windowRef.location.assign(external.href);
        }
    };

    const onFileInputClick = (event) => {
        const input = event.target?.closest?.('input[type="file"]');
        if (!input) return;

        postCanoviaNativeMessage('fileInputRequested', {
            accept: input.getAttribute('accept') || '',
            multiple: Boolean(input.multiple),
            capture: input.getAttribute('capture') || '',
        }, windowRef);
    };

    const onPageReady = () => {
        annotateFileInputs(documentRef);
        notifyNavigation();
    };

    const onPopState = () => notifyNavigation();

    documentRef.addEventListener('click', onClick, true);
    documentRef.addEventListener('click', onFileInputClick, true);
    documentRef.addEventListener('canovia:page-ready', onPageReady);
    windowRef.addEventListener('popstate', onPopState);

    annotateFileInputs(documentRef);

    const api = {
        version: NATIVE_BRIDGE_VERSION,
        runtime,
        receive,
        handleBack,
        openPath,
        notifyNavigation,
        dispose() {
            if (disposed) return;
            disposed = true;

            documentRef.removeEventListener('click', onClick, true);
            documentRef.removeEventListener('click', onFileInputClick, true);
            documentRef.removeEventListener('canovia:page-ready', onPageReady);
            windowRef.removeEventListener('popstate', onPopState);

            if (windowRef.CanoviaNativeBridge === api) {
                delete windowRef.CanoviaNativeBridge;
            }
        },
    };

    windowRef.CanoviaNativeBridge = api;

    postCanoviaNativeMessage('ready', {
        bridge_version: NATIVE_BRIDGE_VERSION,
        surface: runtime.surface,
        platform: runtime.platform,
        app_version: runtime.appVersion || null,
        csrf_present: Boolean(
            documentRef.querySelector('meta[name="csrf-token"]')?.content
        ),
        session_mode: 'web_cookie',
        capabilities: [
            'openExternal',
            'navigationState',
            'fileInput',
            'deepLink',
            'back',
        ],
        ...navigationState(),
    }, windowRef);

    return api;
}

export { NATIVE_BRIDGE_VERSION };

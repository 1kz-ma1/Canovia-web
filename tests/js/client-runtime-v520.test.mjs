import test from 'node:test';
import assert from 'node:assert/strict';

import {
    canoviaClientPlatform,
    canoviaClientSurface,
    detectCanoviaRuntime,
    isCanoviaNativeRuntime,
    mountCanoviaNativeBridge,
    postCanoviaNativeMessage,
} from '../../resources/js/client-runtime.mjs';
import { mountInstantStartServiceWorker } from '../../resources/js/instant-start.mjs';

function fakeWindow({
    userAgent = 'Mozilla/5.0',
    standalone = false,
    displayStandalone = false,
    injected = null,
    handler = null,
} = {}) {
    const messages = [];
    const windowRef = {
        __CANOVIA_NATIVE__: injected,
        navigator: { userAgent, standalone },
        matchMedia: () => ({ matches: displayStandalone }),
        location: {
            href: 'https://canovia.example/roadmap',
            origin: 'https://canovia.example',
            pathname: '/roadmap',
            search: '',
            assign: () => {},
        },
        history: {
            length: 1,
            back: () => {},
        },
        CustomEvent: class {
            constructor(type, init = {}) {
                this.type = type;
                this.detail = init.detail;
            }
        },
        webkit: {
            messageHandlers: handler === false
                ? {}
                : {
                    canovia: {
                        postMessage: (message) => {
                            messages.push(message);
                            handler?.(message);
                        },
                    },
                },
        },
    };

    return { windowRef, messages };
}

test('native runtime is detected from the iOS user-agent suffix', () => {
    const { windowRef } = fakeWindow({
        userAgent: 'Mozilla/5.0 CanoviaNative/iOS/1.0.0',
    });

    const runtime = detectCanoviaRuntime(windowRef);

    assert.equal(runtime.surface, 'native');
    assert.equal(runtime.platform, 'ios');
    assert.equal(runtime.native, true);
    assert.equal(runtime.pwa, false);
    assert.equal(runtime.appVersion, '1.0.0');
    assert.equal(canoviaClientSurface(windowRef), 'native');
    assert.equal(canoviaClientPlatform(windowRef), 'ios');
    assert.equal(isCanoviaNativeRuntime(windowRef), true);
});

test('injected native runtime works before a custom user-agent is available', () => {
    const { windowRef } = fakeWindow({
        injected: {
            platform: 'ios',
            bridgeVersion: 1,
            appVersion: 'prototype',
        },
    });

    const runtime = detectCanoviaRuntime(windowRef);

    assert.equal(runtime.surface, 'native');
    assert.equal(runtime.platform, 'ios');
    assert.equal(runtime.appVersion, 'prototype');
});

test('PWA and browser remain distinct from native', () => {
    const pwa = fakeWindow({ displayStandalone: true }).windowRef;
    const web = fakeWindow().windowRef;

    assert.equal(canoviaClientSurface(pwa), 'pwa');
    assert.equal(canoviaClientSurface(web), 'web');
    assert.equal(isCanoviaNativeRuntime(pwa), false);
    assert.equal(isCanoviaNativeRuntime(web), false);
});

test('native messages use the versioned canovia WKWebView handler', () => {
    const { windowRef, messages } = fakeWindow({
        injected: { platform: 'ios' },
    });

    assert.equal(
        postCanoviaNativeMessage('openExternal', { url: 'https://example.com' }, windowRef),
        true,
    );
    assert.deepEqual(messages[0], {
        version: 1,
        type: 'openExternal',
        payload: { url: 'https://example.com' },
    });
});

test('native shell never registers PWA service worker and clears stale registrations', async () => {
    let registrations = 0;
    let unregisters = 0;
    const { windowRef } = fakeWindow({
        injected: { platform: 'ios' },
    });
    windowRef.isSecureContext = true;

    const result = await mountInstantStartServiceWorker({
        windowRef,
        navigatorRef: {
            serviceWorker: {
                register: async () => {
                    registrations += 1;
                    return {};
                },
                getRegistrations: async () => [
                    {
                        unregister: async () => {
                            unregisters += 1;
                            return true;
                        },
                    },
                ],
            },
        },
    });

    assert.equal(result, null);
    assert.equal(registrations, 0);
    assert.equal(unregisters, 1);
});

test('bridge publishes ready state without exposing session secrets', () => {
    const { windowRef, messages } = fakeWindow({
        injected: { platform: 'ios', appVersion: '1.0' },
    });

    const listeners = new Map();
    const root = { dataset: {} };
    const documentRef = {
        documentElement: root,
        body: { dataset: { routeName: 'roadmap.index' } },
        querySelector: (selector) => selector === 'meta[name="csrf-token"]'
            ? { content: 'present-but-not-sent' }
            : null,
        querySelectorAll: () => [],
        addEventListener: (name, callback) => listeners.set(name, callback),
        removeEventListener: () => {},
        dispatchEvent: () => {},
    };
    windowRef.addEventListener = () => {};
    windowRef.removeEventListener = () => {};

    const bridge = mountCanoviaNativeBridge({ documentRef, windowRef });

    assert.equal(bridge.version, 1);
    assert.equal(root.dataset.canoviaRuntime, 'native');

    const ready = messages.find((message) => message.type === 'ready');
    assert.ok(ready);
    assert.equal(ready.payload.session_mode, 'web_cookie');
    assert.equal(ready.payload.csrf_present, true);
    assert.equal(JSON.stringify(ready).includes('present-but-not-sent'), false);
});

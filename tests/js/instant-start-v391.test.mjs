import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { mountInstantStartServiceWorker } from '../../resources/js/instant-start.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const swSource = fs.readFileSync(path.join(root, 'public/sw.js'), 'utf8');

async function testRegistration() {
    const messages = [];
    let updateCalls = 0;
    let registerArgs = null;

    const activeWorker = {
        postMessage(message) {
            messages.push(message);
        },
    };

    const registration = {
        active: activeWorker,
        waiting: null,
        installing: null,
        addEventListener() {},
        async update() {
            updateCalls += 1;
        },
    };

    const serviceWorker = {
        controller: activeWorker,
        ready: Promise.resolve(registration),
        async register(url, options) {
            registerArgs = { url, options };
            return registration;
        },
    };

    const result = await mountInstantStartServiceWorker({
        navigatorRef: { serviceWorker },
        windowRef: { isSecureContext: true },
    });

    await Promise.resolve();
    await Promise.resolve();

    assert.equal(result, registration);
    assert.deepEqual(registerArgs, {
        url: '/sw.js',
        options: { updateViaCache: 'none' },
    });
    assert.ok(messages.some((message) => message?.type === 'MARK_NETWORK_SUCCESS'));
    assert.equal(updateCalls, 1);
}

async function testOnlineFallbackSafety() {
    const result = await mountInstantStartServiceWorker({
        navigatorRef: {},
        windowRef: { isSecureContext: true },
    });
    assert.equal(result, null);

    let called = false;
    const insecure = await mountInstantStartServiceWorker({
        navigatorRef: {
            serviceWorker: {
                async register() {
                    called = true;
                },
            },
        },
        windowRef: { isSecureContext: false },
    });
    assert.equal(insecure, null);
    assert.equal(called, false);
}

function testWorkerBoundaries() {
    assert.match(swSource, /request\.method !== 'GET'/);
    assert.match(swSource, /url\.origin !== self\.location\.origin/);
    assert.match(swSource, /INSTANT_START_PATHS = new Set\(\[\s*'\/'\s*,\s*'\/navigate'\s*,\s*'\/inbox'\s*,\s*'\/roadmap'/s);
    assert.match(swSource, /_canovia_network/);
    assert.match(swSource, /navigationNetworkResponse/);
    assert.match(swSource, /STATIC_ASSETS\.includes\(url\.pathname\)/);
    assert.match(swSource, /navigationPreload\.disable\(\)/);
    assert.doesNotMatch(swSource, /navigationPreload\.enable\(\)/);

    // Capability/auth/admin paths must not be part of the Instant Start allowlist.
    const allowlistBlock = swSource.match(/INSTANT_START_PATHS = new Set\(\[(.*?)\]\);/s)?.[1] || '';
    for (const forbidden of ['/pwa/handoff', '/pwa/install', '/login', '/register', '/admin', '/plans/']) {
        assert.equal(allowlistBlock.includes(forbidden), false, `${forbidden} must stay network-only`);
    }
}

await testRegistration();
await testOnlineFallbackSafety();
testWorkerBoundaries();

console.log('Instant Start V39.1 regression tests: PASS');

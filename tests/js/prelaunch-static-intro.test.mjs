import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('static-welcome/app.js');

function createElement() {
    const listeners = new Map();
    const classes = new Set();
    return {
        disabled: true,
        textContent: '',
        listeners,
        classList: {
            add: (value) => classes.add(value),
            remove: (value) => classes.delete(value),
            toggle: (value, active) => active ? classes.add(value) : classes.delete(value),
            contains: (value) => classes.has(value),
        },
        addEventListener(type, fn) { listeners.set(type, fn); },
        click() { listeners.get('click')?.(); },
    };
}

async function startWelcome({ search = '', returning = false, healthReady = true, appUrl = 'https://app.canovia.com' } = {}) {
    const els = new Map([
        ['[data-status]', createElement()],
        ['[data-open]', createElement()],
        ['[data-open-anyway]', createElement()],
        ['[data-retry]', createElement()],
        ['[data-slow-notice]', createElement()],
    ]);
    const assignments = [];
    const scheduled = [];
    const storage = new Map(returning ? [['canovia.welcome.seen.v1', '1']] : []);
    const fetchCalls = [];
    const win = {
        CANOVIA_WELCOME_CONFIG: { appUrl },
        location: { search, assign: (href) => assignments.push(href) },
        setTimeout: (callback, delay) => {
            scheduled.push({ callback, delay });
            return scheduled.length;
        },
        clearTimeout: () => {},
        setInterval: () => {},
        addEventListener: () => {},
    };
    const ctx = {
        window: win,
        document: {
            querySelector: (selector) => els.get(selector) || null,
            querySelectorAll: () => [],
        },
        localStorage: {
            getItem: (key) => storage.get(key) ?? null,
            setItem: (key, value) => storage.set(key, value),
        },
        navigator: { onLine: true },
        Date,
        AbortController,
        URLSearchParams,
        Object,
        fetch: async (url, options) => {
            fetchCalls.push({ url, options });
            if (healthReady) {
                return { ok: true, status: 200, headers: { get: (key) => key === 'X-Canovia-Ready' ? '1' : null } };
            }
            throw new Error('test-only cold start');
        },
    };
    vm.runInNewContext(source, ctx, { filename: 'static-welcome/app.js' });
    await new Promise((resolve) => setImmediate(resolve));
    return { els, assignments, scheduled, storage, fetchCalls };
}

test('public intro is static, provides honest launch copy and routes through the existing wake page', () => {
    const html = read('static-welcome/intro.html');
    assert.match(html, /<h1[^>]*>[\s\S]*やったことを、/);
    assert.match(html, /先行公開に向けて準備中/);
    assert.match(html, /診断やPlan作成は、最初から必須ではありません/);
    for (const target of ['./index.html', './index.html?destination=future', './index.html?destination=support']) {
        assert.ok(html.includes(`href="${target}"`), `missing route ${target}`);
    }
    assert.match(html, /金銭的支援・購入ではありません/);
    assert.doesNotMatch(html, /<script/i);
    assert.doesNotMatch(html, /https?:\/\//i);
});

test('existing index still has the cold-start recovery controls and discoverable intro link', () => {
    const html = read('static-welcome/index.html');
    for (const marker of ['data-status', 'data-open', 'data-slow-notice', 'data-retry', 'data-open-anyway', 'href="./intro.html"']) {
        assert.ok(html.includes(marker), `missing marker: ${marker}`);
    }
    const script = read('static-welcome/app.js');
    for (const behavior of ['X-Canovia-Ready', 'slowAfterMs', 'navigator.onLine', "window.location.assign(targetUrl)"]) {
        assert.ok(script.includes(behavior), `missing behavior: ${behavior}`);
    }
});

test('default free-to-try entry opens the configured app only after ready health response', async () => {
    const w = await startWelcome();
    assert.equal(w.els.get('[data-open]').disabled, false);
    assert.equal(w.els.get('[data-open]').textContent, 'Canoviaを開く');
    assert.equal(w.fetchCalls.length, 1);
    assert.ok(w.fetchCalls[0].url.startsWith('https://app.canovia.com/health?from=welcome&t='));
    assert.equal(w.fetchCalls[0].options.credentials, 'omit');
    assert.deepEqual(w.assignments, []);
    w.els.get('[data-open]').click();
    assert.deepEqual(w.assignments, ['https://app.canovia.com']);
    assert.equal(w.storage.get('canovia.welcome.seen.v1'), '1');
});

test('future and support links keep a fixed public destination, not an arbitrary redirect', async () => {
    const expected = [
        ['?destination=future', 'https://app.canovia.com/feedback', '開発ロードマップを見る'],
        ['?destination=support', 'https://app.canovia.com/feedback#support-candidates', '無料Support・フィードバックへ'],
        ['?destination=https%3A%2F%2Fevil.example', 'https://app.canovia.com', 'Canoviaを開く'],
        ['?destination=%2F%2Fevil.example', 'https://app.canovia.com', 'Canoviaを開く'],
        ['?destination=bogus', 'https://app.canovia.com', 'Canoviaを開く'],
    ];
    for (const [search, url, label] of expected) {
        const w = await startWelcome({ search });
        assert.equal(w.els.get('[data-open]').textContent, label);
        w.els.get('[data-open]').click();
        assert.deepEqual(w.assignments, [url]);
    }
});

test('returning actor preserves ready-only auto entry and respects explicit public destination', async () => {
    const w = await startWelcome({ search: '?destination=future', returning: true });
    assert.deepEqual(w.assignments, []);
    const automatic = w.scheduled.find((item) => item.delay === 450);
    assert.ok(automatic, 'should only auto-enter after healthy ready state');
    automatic.callback();
    assert.deepEqual(w.assignments, ['https://app.canovia.com/feedback']);
});

test('cold start preserves retry and explicit open-anyway; it never auto-redirects before ready', async () => {
    const w = await startWelcome({ search: '?destination=support', returning: true, healthReady: false });
    assert.equal(w.els.get('[data-open]').disabled, true);
    assert.deepEqual(w.assignments, []);
    assert.ok(w.scheduled.some((item) => item.delay === 2200));
    w.els.get('[data-open-anyway]').click();
    assert.deepEqual(w.assignments, ['https://app.canovia.com/feedback#support-candidates']);
});

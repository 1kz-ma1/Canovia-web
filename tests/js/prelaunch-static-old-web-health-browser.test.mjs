import assert from 'node:assert/strict';
import { test } from 'node:test';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright';

// Live Web requests are NEVER forwarded. This is a synthetic CORS contract,
// not an Aiven/Render/Safari acceptance or readiness certificate.
const WELCOME = pathToFileURL(resolve('static-welcome/index.html')).href;
const WEB = 'https://pacekeeper-d3mm.onrender.com';
const HEALTH_PATH = '/health';
const allowedDestinations = new Set([WEB + '/', WEB + '/feedback']);

async function openSyntheticWelcome(browser, {
    search = '',
    readyHeader = true,
    status = 204,
    corsHeaders = true,
} = {}) {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const attempts = [];
    let navigationResolve;
    const navigation = new Promise(resolve => { navigationResolve = resolve; });
    await context.route(/^https?:\/\//, async route => {
        const url = new URL(route.request().url());
        attempts.push({ method: route.request().method(), url: url.toString() });
        if (url.origin === WEB && url.pathname === HEALTH_PATH && route.request().method() === 'GET') {
            await route.fulfill({
                status,
                headers: {
                    ...(corsHeaders ? {
                        'Access-Control-Allow-Origin': '*',
                        'Access-Control-Expose-Headers': 'X-Canovia-Ready',
                    } : {}),
                    ...(readyHeader ? { 'X-Canovia-Ready': '1' } : {}),
                    'Cache-Control': 'no-store',
                },
                body: '',
            });
            return;
        }
        // Capture the intent deterministically before failing the synthetic
        // navigation. The Playwright request event fires before route handler.
        navigationResolve({ method: route.request().method(), url: url.toString() });
        // Every non-health request is aborted: zero live Render HTTP.
        await route.abort('blockedbyclient');
    });
    const page = await context.newPage();
    const healthResponse = page.waitForResponse(r => r.url().startsWith(WEB + '/health?'));
    await page.goto(WELCOME + search, { waitUntil: 'load' });
    await healthResponse;
    return { context, page, attempts, navigation };
}

test('Chromium synthetic CORS + Ready enables entry only after 204 with exposed marker', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const { context, page, attempts, navigation } = await openSyntheticWelcome(browser);
        await page.waitForFunction(() => document.querySelector('[data-open]')?.disabled === false);
        assert.equal(await page.locator('[data-status]').innerText(), '準備できました');
        assert.equal(await page.locator('[data-open]').innerText(), 'Canoviaを開く');
        const intended = page.waitForRequest(r => r.url() === WEB + '/');
        await page.evaluate(() => document.querySelector('[data-open]').click());
        const request = await intended;
        const actualRoute = await navigation;
        assert.equal(request.method(), 'GET');
        assert.deepEqual(actualRoute, { method: 'GET', url: WEB + '/' });
        assert.equal(attempts.filter(x => x.url.startsWith(WEB + '/health?')).length, 1);
        assert.ok(attempts.every(x => x.url.startsWith(WEB + '/health?')
          || x.url === WEB + '/'), 'unexpected HTTP host/path in synthetic browser');
        await context.close();
    } finally {
        await browser.close();
    }
});

test('Chromium synthetic free Support enters old Live Web /feedback, never unreleased hash', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const { context, page, attempts, navigation } = await openSyntheticWelcome(browser, { search: '?destination=support' });
        await page.waitForFunction(() => !document.querySelector('[data-open]').disabled);
        assert.equal(await page.locator('[data-open]').innerText(), '無料Support・フィードバックへ');
        const intended = page.waitForRequest(r => r.url() === WEB + '/feedback');
        await page.evaluate(() => document.querySelector('[data-open]').click());
        assert.equal((await intended).method(), 'GET');
        assert.deepEqual(await navigation, { method: 'GET', url: WEB + '/feedback' });
        assert.equal(attempts.filter(x => x.url === WEB + '/feedback').length, 1);
        assert.ok(!attempts.some(x => x.url.includes('#support-candidates')));
        await context.close();
    } finally {
        await browser.close();
    }
});

test('Chromium synthetic CORS denial, missing Ready, 503 never unlock the CTA', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        for (const setting of [
            { corsHeaders: false },
            { readyHeader: false },
            { status: 503 },
        ]) {
            const { context, page, attempts } = await openSyntheticWelcome(browser, setting);
            await page.waitForTimeout(150);
            assert.equal(await page.locator('[data-open]').isDisabled(), true);
            assert.equal(attempts.filter(x => x.url.startsWith(WEB + '/health?')).length, 1);
            assert.equal(attempts.filter(x => !x.url.startsWith(WEB + '/health?')).length, 0);
            await context.close();
        }
    } finally {
        await browser.close();
    }
});

test('Chromium invalid destination cannot inject arbitrary redirect or release roadmap', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const { context, page, attempts, navigation } = await openSyntheticWelcome(browser, {
            search: '?destination=https%3A%2F%2Fevil.example',
        });
        await page.waitForFunction(() => !document.querySelector('[data-open]').disabled);
        assert.equal(await page.locator('[data-open]').innerText(), 'Canoviaを開く');
        const intended = page.waitForRequest(r => r.url() === WEB + '/');
        await page.evaluate(() => document.querySelector('[data-open]').click());
        await intended;
        assert.deepEqual(await navigation, { method: 'GET', url: WEB + '/' });
        assert.ok(attempts.every(x => new URL(x.url).origin === WEB), 'unexpected external request');
        await context.close();
    } finally {
        await browser.close();
    }
});

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright';

const intro = pathToFileURL(resolve('static-welcome/intro.html')).href;
const welcome = pathToFileURL(resolve('static-welcome/index.html')).href;

test('real offline Chromium renders intro and navigation without viewport overflow', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        for (const viewport of [{ width: 390, height: 844 }, { width: 1280, height: 800 }]) {
            const context = await browser.newContext({ viewport });
            // No public provider or production Web is contacted in this test.
            await context.route(/^https?:\/\//, (route) => route.abort());
            const page = await context.newPage();
            await page.goto(intro, { waitUntil: 'load' });
            assert.equal(await page.locator('h1').count(), 1);
            assert.match(await page.locator('h1').innerText(), /やったことを/);
            assert.equal(await page.locator('.experience-steps li').count(), 3);
            assert.equal(await page.locator('.future-actions article').count(), 2);
            const overflow = await page.evaluate(() => ({
                pageWidth: document.documentElement.scrollWidth,
                viewportWidth: document.documentElement.clientWidth,
            }));
            assert.ok(overflow.pageWidth <= overflow.viewportWidth + 1,
                `overflow at ${viewport.width}px: ${JSON.stringify(overflow)}`);
            assert.equal(await page.locator('a[href="./index.html?destination=future"]').count(), 0);
            assert.match(await page.locator('#future').innerText(), /開発ロードマップは準備中/);
            assert.equal(await page.locator('a[href="./index.html?destination=support"]').count(), 1);
            assert.ok(await page.locator('.hero-actions .button-primary').isVisible());
            await context.close();
        }
    } finally {
        await browser.close();
    }
});

test('existing Welcome remains reachable, cold-start fallback remains present, intro is discoverable', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
        await context.route(/^https?:\/\//, (route) => route.abort()); // no production request
        const page = await context.newPage();
        await page.goto(welcome, { waitUntil: 'load' });
        assert.equal(await page.locator('a[href="./intro.html"]').count(), 1);
        assert.equal(await page.locator('[data-open]').isDisabled(), true);
        assert.equal(await page.locator('[data-open-anyway]').count(), 1);
        assert.equal(await page.locator('[data-retry]').count(), 1);
        await page.locator('a[href="./intro.html"]').click();
        await page.waitForURL('**/intro.html');
        assert.match(await page.locator('h1').innerText(), /やったことを/);
        await context.close();
    } finally {
        await browser.close();
    }
});

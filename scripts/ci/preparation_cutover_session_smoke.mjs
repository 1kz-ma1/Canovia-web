// Owned lab session continuity probe. No endpoint, credential or SQL arguments.
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { readFile, writeFile, chmod } from 'node:fs/promises';
import { dirname, join, basename } from 'node:path';
const base = 'http://127.0.0.1:18804';
assert.equal(process.env.APP_URL, base);
assert.equal(process.env.CANOVIA_CUTOVER_FIXTURE, '1');
assert.equal(process.env.CANOVIA_MAC_LAB_FIXTURE, '1');
assert.equal(process.env.APP_ENV, 'local');
assert.equal(process.env.DB_CONNECTION, 'sqlite');
assert(basename(dirname(process.env.DB_DATABASE)).startsWith('canovia-preparation-mac-'));
const state = join(dirname(process.env.DB_DATABASE), 'synthetic-browser-state.json');
const phase = process.argv[2];
assert(['before', 'after'].includes(phase));
assert.equal(process.env.CANOVIA_PREPARATION_ONLY, phase === 'before' ? 'false' : 'true');
const browser = await chromium.launch({ headless: true,
    ...(process.env.CANOVIA_BROWSER_EXECUTABLE_PATH ? { executablePath: process.env.CANOVIA_BROWSER_EXECUTABLE_PATH } : {}) });
try {
    const ctx = await browser.newContext({ serviceWorkers: 'block',
        ...(phase === 'after' ? { storageState: JSON.parse(await readFile(state, 'utf8')) } : {}) });
    await ctx.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const page = await ctx.newPage();
    page.setDefaultTimeout(12000);
    if (phase === 'before') {
        await page.goto(base + '/login');
        await page.locator('[name="email"]').fill('tester@example.test');
        await page.locator('[name="password"]').fill('Synthetic-Preparation-Only-2026');
        await page.getByRole('button', { name: 'ログイン', exact: true }).click();
        await page.waitForLoadState('load');
        assert.equal((await ctx.request.get(base + '/plans/1')).status(), 200);
        assert.equal((await ctx.request.get(base + '/plans/1/tasks/1/study-practice/history')).status(), 200);
        await writeFile(state, JSON.stringify(await ctx.storageState()), { mode: 0o600 });
        await chmod(state, 0o600);
    } else {
        // Same host/cookie, APP_KEY and private session storage; no forced logout.
        const plan = await page.goto(base + '/plans/1');
        assert.equal(plan.status(), 200);
        await page.getByText('合成AP学習Plan', { exact: true }).waitFor();
        const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
        for (const path of ['/workspace/study?plan_id=1', '/workspace/development?plan_id=2', '/plans/1/tasks/1/study-practice/history', '/api/mcp']) {
            // Represents a saved old URL/HTML link, not a real installed SW proof.
            assert.equal((await ctx.request.get(base + path, { headers: { Accept: 'application/json' } })).status(), 404);
        }
        assert.equal((await ctx.request.post(base + '/work-sessions', { data: { _token: token }, headers: { Accept: 'application/json' } })).status(), 404);
        assert.equal((await ctx.request.post(base + '/plans', { data: { _token: 'synthetic-invalid' }, headers: { Accept: 'application/json' } })).status(), 419);
    }
    await ctx.close();
    console.log(JSON.stringify({ result: 'SYNTHETIC_SESSION_CONTINUITY_VERIFIED', phase, real_sessions_revoked: false,
        real_device_verified: false, production_go: false }));
} finally { await browser.close(); }

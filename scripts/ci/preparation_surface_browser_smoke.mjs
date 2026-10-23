import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';

const base = process.env.CANOVIA_PREPARATION_SURFACE_BASE_URL;
const macLab = process.env.CANOVIA_MAC_LAB_FIXTURE === '1';
assert.equal(base, macLab ? 'http://127.0.0.1:18803' : 'http://127.0.0.1:18802');
assert.equal(process.env.CANOVIA_PREPARATION_SURFACE_FIXTURE, '1');
assert.equal(process.env.CANOVIA_PREPARATION_ONLY, 'true');
assert.equal(process.env.DB_CONNECTION, 'sqlite');
assert.equal(process.env.APP_ENV, macLab ? 'local' : 'testing');
if (macLab) assert.equal(process.env.CANOVIA_PUBLIC_RELEASE_LEVEL, '0');
const browser = await chromium.launch({ headless: true,
    ...(process.env.CANOVIA_BROWSER_EXECUTABLE_PATH ? { executablePath: process.env.CANOVIA_BROWSER_EXECUTABLE_PATH } : {}) });
try {
    const context = await browser.newContext({ serviceWorkers: 'block' });
    await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const existingCookies = ['pace_keeper_actor_token', 'canovia_first_run_passed', 'XSRF-TOKEN', 'pace_keeper_owner_token_1'];
    if (macLab) await context.addCookies(existingCookies.map(name => ({ name, value: 'synthetic-existing-do-not-replace', url: base })));
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const visit = path => page.goto(base + path, { waitUntil: 'domcontentloaded' });
    await visit('/');
    await page.getByRole('heading', { name: '今の目標を、これからの一歩につなげる。' }).waitFor();
    const email = macLab ? 'tester@example.test' : `prepared-${randomUUID()}@example.test`;
    const password = 'Synthetic-Preparation-Only-2026';
    await visit(macLab ? '/login' : '/register');
    if (!macLab) await page.locator('[name="name"]').fill('Synthetic preparation owner');
    await page.locator('[name="email"]').fill(email);
    await page.locator('[name="password"]').fill(password);
    if (!macLab) await page.locator('[name="password_confirmation"]').fill(password);
    await page.getByRole('button', { name: macLab ? 'ログイン' : '登録してそのまま続ける', exact: true }).click();
    await page.waitForURL(base + '/');
    await page.getByRole('link', { name: '現在地を整理する' }).click();
    await page.locator('[name="domains[]"]').selectOption('study');
    await page.locator('[name="study_goal"]').fill('同じAccountで目標を引き継ぐ');
    await page.locator('[name="study_stage"]').selectOption('started');
    await page.locator('[name="weekly_capacity"]').selectOption('2_5');
    await page.getByRole('button', { name: '現在地を保存して候補を見る' }).click();
    await page.getByRole('heading', { name: '現在地を保存しました' }).waitFor();
    await page.getByRole('button', { name: 'この候補を編集する' }).first().click();
    await page.locator('[name="title"]').fill('正式版に向けた合成の準備目標');
    await page.locator('[name="description"]').fill('現在地と次に整理したいこと');
    await page.getByRole('button', { name: 'Planを保存する', exact: true }).click();
    await page.locator('[data-preparation-plan]').waitFor();
    const planPath = new URL(page.url()).pathname;
    assert.match(planPath, /^\/plans\/\d+$/);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.getByRole('link', { name: '目標・計画を編集する' }).click();
    await page.locator('[name="title"]').fill('再訪して編集した準備目標');
    await page.getByRole('button', { name: '変更を保存する' }).click();
    await page.getByRole('heading', { name: '再訪して編集した準備目標' }).waitFor();
    const stale = await context.newPage();
    await stale.goto(base + planPath + '/edit', { waitUntil: 'domcontentloaded' });
    await page.getByRole('link', { name: '目標・計画を編集する' }).click();
    await page.locator('[name="description"]').fill('現在地と次に整理したいこと・更新済み');
    await page.getByRole('button', { name: '変更を保存する' }).click();
    await page.locator('[data-preparation-plan]').waitFor();
    await stale.locator('[name="title"]').fill('古いタブからの上書き');
    await stale.getByRole('button', { name: '変更を保存する' }).click();
    await stale.locator('[data-preparation-conflict]').waitFor();
    assert.equal(await stale.locator('[name="title"]').inputValue(), '古いタブからの上書き');
    assert.equal(await stale.getByRole('button', { name: '変更を保存する' }).isDisabled(), true);
    await stale.close();
    assert.equal(await page.locator('a[href*="/workspace/study"],a[href*="/work-sessions"],a[href*="/github-workflow"]').count(), 0);
    for (const path of ['/workspace/study', '/workspace/development', '/map', '/public-plans', '/admin']) {
        const response = await context.request.get(base + path);
        assert.equal(response.status(), 404);
        assert.equal((await response.json()).code, 'PREPARATION_SURFACE_CLOSED');
    }
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    for (const path of ['/work-sessions', '/offline/work-sessions/sync', '/api/integrations/github/webhook', '/api/mcp']) {
        const response = await context.request.post(base + path, { form: { _token: token }, maxRedirects: 0 });
        assert.equal(response.status(), 404);
    }
    assert.equal((await context.request.post(base + '/plans', { form: { title: 'CSRFなし' } })).status(), 419);
    await page.getByRole('button', { name: 'ログアウト', exact: true }).click();
    await visit('/login');
    await page.locator('[name="email"]').fill(email);
    await page.locator('[name="password"]').fill(password);
    await page.getByRole('button', { name: 'ログイン', exact: true }).click();
    await page.waitForURL(base + '/');
    await page.getByRole('link', { name: '再訪して編集した準備目標', exact: true }).click();
    assert.equal(new URL(page.url()).pathname, planPath);
    await page.getByText('現在地と次に整理したいこと・更新済み', { exact: true }).waitFor();
    await page.setViewportSize({ width: 390, height: 844 });
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await page.getByRole('link', { name: 'Canovia Future', exact: true }).click();
    await page.getByRole('heading', { name: 'Canovia Future', exact: true }).waitFor();
    assert.equal(await page.locator('[data-support-count],button[type="submit"]:not([form])').count(), 1); // logout only
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    const stranger = await browser.newContext({ serviceWorkers: 'block' });
    const denied = await stranger.request.get(base + planPath, { maxRedirects: 0 });
    assert.equal(denied.status(), 302);
    assert(new URL(denied.headers().location).pathname === '/login');
    assert.deepEqual(errors, []);
    if (macLab) {
        const cookies = await context.cookies();
        for (const name of existingCookies) {
            assert.equal(cookies.find(cookie => cookie.name === name)?.value, 'synthetic-existing-do-not-replace');
        }
        assert(cookies.filter(cookie => !existingCookies.includes(cookie.name)).every(cookie => /^canovia_mac_lab_[a-f0-9]{16}$/.test(cookie.name)));
        // Public L0 and administrator status alone do not grant the diagnosis.
        for (const actor of ['normal', 'admin']) {
            const isolated = await browser.newContext({ serviceWorkers: 'block' });
            const p = await isolated.newPage();
            await p.goto(base + '/login');
            await p.locator('[name="email"]').fill(`${actor}@example.test`);
            await p.locator('[name="password"]').fill(password);
            await p.getByRole('button', { name: 'ログイン', exact: true }).click();
            await p.waitForURL(base + '/');
            assert.equal(await p.getByRole('link', { name: '現在地を整理する' }).count(), 0);
            assert.equal((await isolated.request.get(base + '/personalization', { maxRedirects: 0 })).status(), 302);
            assert.equal((await isolated.request.get(base + '/workspace/study')).status(), 404);
            assert.equal((await isolated.request.get(base + planPath)).status(), 404);
            await isolated.close();
        }
    }
    console.log('PREPARATION_SURFACE_BROWSER=PASS: diagnosis, canonical Plan, stale-tab rejection, reload/edit/relogin, private access, direct GET/POST/API deny, CSRF, mobile layout');
} finally {
    await browser.close();
}

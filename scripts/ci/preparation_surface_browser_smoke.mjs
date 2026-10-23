import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';

const base = process.env.CANOVIA_PREPARATION_SURFACE_BASE_URL;
assert.equal(base, 'http://127.0.0.1:18802');
assert.equal(process.env.CANOVIA_PREPARATION_SURFACE_FIXTURE, '1');
assert.equal(process.env.CANOVIA_PREPARATION_ONLY, 'true');
assert.equal(process.env.DB_CONNECTION, 'sqlite');
assert.equal(process.env.APP_ENV, 'testing');
const browser = await chromium.launch({ headless: true,
    ...(process.env.CANOVIA_BROWSER_EXECUTABLE_PATH ? { executablePath: process.env.CANOVIA_BROWSER_EXECUTABLE_PATH } : {}) });
try {
    const context = await browser.newContext({ serviceWorkers: 'block' });
    await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const visit = path => page.goto(base + path, { waitUntil: 'domcontentloaded' });
    await visit('/');
    await page.getByRole('heading', { name: '今の目標を、これからの一歩につなげる。' }).waitFor();
    const email = `prepared-${randomUUID()}@example.test`;
    const password = 'Synthetic-Preparation-Only-2026';
    await visit('/register');
    await page.locator('[name="name"]').fill('Synthetic preparation owner');
    await page.locator('[name="email"]').fill(email);
    await page.locator('[name="password"]').fill(password);
    await page.locator('[name="password_confirmation"]').fill(password);
    await page.getByRole('button', { name: '登録してそのまま続ける', exact: true }).click();
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
    await page.getByText('現在地と次に整理したいこと', { exact: true }).waitFor();
    await page.setViewportSize({ width: 390, height: 844 });
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    const stranger = await browser.newContext({ serviceWorkers: 'block' });
    const denied = await stranger.request.get(base + planPath, { maxRedirects: 0 });
    assert.equal(denied.status(), 302);
    assert(new URL(denied.headers().location).pathname === '/login');
    assert.deepEqual(errors, []);
    console.log('PREPARATION_SURFACE_BROWSER=PASS: diagnosis, canonical Plan, reload/edit/relogin, private access, direct GET/POST/API deny, CSRF, mobile layout');
} finally {
    await browser.close();
}

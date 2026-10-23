import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';

const base = process.env.CANOVIA_PREPARATION_BASE_URL;
assert.equal(base, 'http://127.0.0.1:18801');
assert.equal(process.env.CANOVIA_PREPARATION_FIXTURE, '1');
assert.equal(process.env.APP_ENV, 'testing');
assert.equal(process.env.DB_CONNECTION, 'sqlite');
const browser = await chromium.launch({ headless: true,
    ...(process.env.CANOVIA_BROWSER_EXECUTABLE_PATH ? { executablePath: process.env.CANOVIA_BROWSER_EXECUTABLE_PATH } : {}) });
try {
    const owner = await browser.newContext({ serviceWorkers: 'block' });
    const stranger = await browser.newContext({ serviceWorkers: 'block' });
    for (const context of [owner, stranger]) await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const page = await owner.newPage();
    const other = await stranger.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const csrf = async (p, path = '/login') => {
        await p.goto(base + path, { waitUntil: 'domcontentloaded' });
        return p.locator('meta[name="csrf-token"]').getAttribute('content');
    };
    const post = async (p, path, form) => p.request.post(base + path, {
        form: { ...form, _token: await p.locator('meta[name="csrf-token"]').getAttribute('content') }, maxRedirects: 0,
    });
    await csrf(page);
    const payload = { title: '準備を保存する合成Plan', category: 'その他', create_request_id: randomUUID() };
    const first = await post(page, '/plans', payload);
    assert.equal(first.status(), 302);
    const location = first.headers().location;
    assert.match(location, /\/plans\/\d+$/);
    const path = new URL(location).pathname;
    const ownerCookieName = 'pace_keeper_owner_token_' + path.split('/').at(-1);
    assert((await owner.cookies()).some(c => c.name === ownerCookieName));
    assert.equal((await post(page, '/plans', payload)).headers().location, location);

    await csrf(other);
    assert.equal((await post(other, '/plans', payload)).status(), 403);
    assert(!(await stranger.cookies()).some(c => c.name === ownerCookieName));
    assert.equal((await other.request.get(base + path + '/edit')).status(), 403);
    assert.equal((await post(other, path, { _method: 'PUT', title: '他人の上書き' })).status(), 403);
    assert.equal((await page.request.post(base + '/plans', { form: payload })).status(), 419);

    // Claim through the real registration flow, then prove Account persistence
    // across logout/login; the request UUID never replaces Account ownership.
    const email = `preparation-${randomUUID()}@example.test`;
    const password = 'Synthetic-browser-only-2026';
    await csrf(page, '/register');
    assert.equal((await post(page, '/register', { name: 'Synthetic owner', email,
        password, password_confirmation: password })).status(), 302);
    await page.goto(base + path + '/edit', { waitUntil: 'domcontentloaded' });
    await page.locator('[name="title"]').fill('再ログイン後も保持する目標');
    await Promise.all([page.waitForURL(base + path), page.locator('form').filter({ has: page.locator('[name="title"]') }).locator('button[type="submit"]').first().click()]);
    assert.equal((await post(page, '/logout', {})).status(), 302);
    await csrf(page);
    assert.equal((await post(page, '/login', { email, password })).status(), 302);
    await page.goto(base + path + '/edit', { waitUntil: 'domcontentloaded' });
    assert.equal(await page.locator('[name="title"]').inputValue(), '再ログイン後も保持する目標');
    assert.equal((await post(page, '/plans', payload)).headers().location, location);
    assert.equal((await post(other, '/plans', payload)).status(), 409);
    assert.equal((await other.request.get(base + path + '/edit')).status(), 403);
    assert.deepEqual(errors, []);
    console.log('PREPARATION_PLAN_OWNERSHIP_BROWSER=PASS: guest replay, IDOR GET/PUT, CSRF, claim, save, logout/login, same Plan');
} finally {
    await browser.close();
}

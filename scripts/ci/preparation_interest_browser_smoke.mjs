import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { chromium, webkit } from 'playwright';
const base = process.env.CANOVIA_PREPARATION_INTEREST_BASE_URL;
assert.equal(base, process.env.CANOVIA_CUTOVER_FIXTURE === '1' ? 'http://127.0.0.1:18804' : 'http://127.0.0.1:18805');
for (const [key, value] of Object.entries({ CANOVIA_PREPARATION_INTEREST_FIXTURE: '1',
    CANOVIA_PREPARATION_ONLY: 'true', DB_CONNECTION: 'sqlite', APP_ENV: 'local', CANOVIA_PUBLIC_RELEASE_LEVEL: '0' })) assert.equal(process.env[key], value);
const engine = process.env.CANOVIA_INTEREST_ENGINE ?? 'chromium';
assert(['chromium', 'webkit'].includes(engine));
const browser = await ({ chromium, webkit })[engine].launch({ headless: true,
    ...(process.env.CANOVIA_BROWSER_EXECUTABLE_PATH ? { executablePath: process.env.CANOVIA_BROWSER_EXECUTABLE_PATH } : {}) });
const errors = [];
const mobileCaptures = [];
const captureDirectory = process.env.CANOVIA_INTEREST_SCREENSHOT_DIR;
async function captureMobile(page, name) {
    if (!captureDirectory) return;
    assert.equal(page.viewportSize().width, 390);
    await fits(page);
    await page.waitForLoadState('networkidle');
    await mkdir(captureDirectory, { recursive: true });
    await page.screenshot({ path: join(captureDirectory, name + '.png'), fullPage: true });
    mobileCaptures.push(name);
}
async function context(options = {}) {
    const ctx = await browser.newContext({ serviceWorkers: 'block', ...options });
    await ctx.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    ctx.on('page', page => { page.setDefaultTimeout(12000); page.on('pageerror', error => errors.push(error.message)); });
    return ctx;
}
async function register(ctx) {
    const page = await ctx.newPage();
    const email = `interest-${randomUUID()}@example.test`;
    await page.goto(base + '/register');
    await page.locator('[name="name"]').fill('Synthetic interest owner');
    await page.locator('[name="email"]').fill(email);
    await page.locator('[name="password"]').fill('Synthetic-Interest-2026');
    await page.locator('[name="password_confirmation"]').fill('Synthetic-Interest-2026');
    // A fresh no-JS context can still be laying out fonts/styles. Observe the
    // same loaded geometry we assert elsewhere; do not force clicks or retry POSTs.
    await page.waitForLoadState('networkidle');
    await fits(page);
    await page.getByRole('button', { name: '登録してそのまま続ける', exact: true }).click();
    await page.waitForURL(base + '/');
    return { page, email };
}
async function fits(page) {
    await page.waitForLoadState('load');
    await page.evaluate(() => document.fonts.ready);
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
}
async function stored(ctx) {
    const response = await ctx.request.get(base + '/preparation/interests', { headers: { Accept: 'application/json' } });
    assert.equal(response.status(), 200);
    return response.json();
}
try {
    const a = await context();
    const { page, email } = await register(a);
    // L0 gets only the explicitly permitted preparation surface, not old L1 routes.
    assert.equal(await page.getByRole('link', { name: '現在地を整理する' }).count(), 0);
    await page.getByRole('link', { name: '30問で関心を見つける' }).click();
    await page.setViewportSize({ width: 390, height: 844 });
    await fits(page);
    assert.equal(await page.locator('[data-interest-page]:visible').count(), 1);
    // Native radio keyboard navigation and touch-size labels survive the card skin.
    const first = page.locator('[name="answers[Q01]"][value="0"]');
    await first.focus();
    await first.press('ArrowRight');
    assert.equal(await page.locator('[name="answers[Q01]"][value="1"]').isChecked(), true);
    const target = await page.locator('.preparation-answer-body').first().boundingBox();
    assert(target.height >= 44);
    await page.getByText('選択済み 1 / 30問', { exact: false }).waitFor();
    await captureMobile(page, '01-diagnosis-390');
    await page.locator('[name="save_consent"]').check();
    await page.locator('[name="answers[Q01]"][value="3"]').check();
    await page.locator('[name="answers[Q02]"][value="3"]').check();
    await page.locator('[name="answers[Q03]"][value="unknown"]').check();
    await page.locator('[name="answers[Q04]"][value="2"]').check();
    await page.locator('[name="answers[Q05]"][value="2"]').check();
    assert.equal(await page.locator('[data-interest-meter]').getAttribute('max'), '30');
    assert.equal(await page.locator('[data-interest-meter]').evaluate(meter => meter.value), 5);
    await page.getByRole('button', { name: '次の5問' }).click();
    await page.getByRole('button', { name: '途中保存する', exact: true }).click();
    await page.getByRole('status').filter({ hasText: '保存しました。後日' }).waitFor();
    await page.reload();
    await page.getByText('2 / 6 ページ', { exact: false }).waitFor();
    assert.equal(await page.locator('[name="answers[Q03]"][value="unknown"]').isChecked(), true);
    // Network failure retains edits; a lost response never becomes a success receipt.
    await page.route('**/preparation/interests', route => route.request().method() === 'POST' ? route.abort() : route.continue());
    await page.locator('[name="answers[Q06]"][value="2"]').check();
    await page.getByRole('button', { name: '途中保存する', exact: true }).click();
    await page.getByText('保存を確認できません。', { exact: false }).waitFor();
    assert.equal(await page.locator('[name="answers[Q06]"][value="2"]').isChecked(), true);
    await page.unroute('**/preparation/interests');
    const stale = await a.newPage();
    await stale.goto(base + '/preparation/interests');
    await page.getByRole('button', { name: '途中保存する', exact: true }).click();
    await page.getByRole('status').filter({ hasText: '保存しました。後日' }).waitFor();
    await stale.locator('[name="answers[Q06]"][value="1"]').check();
    await stale.getByRole('button', { name: '途中保存する', exact: true }).click();
    await stale.getByRole('status').filter({ hasText: '別の画面で更新' }).waitFor();
    assert.equal(await stale.getByRole('button', { name: '途中保存する', exact: true }).isDisabled(), true);
    assert.equal((await stored(a)).record.answers.Q06, 2);
    await stale.close();
    // Complete the remaining questions using the exact reviewed five-choice bank.
    for (let step = 2; step <= 6; step++) {
        for (const question of await page.locator('[data-interest-page]:visible fieldset').all()) {
            const id = await question.getAttribute('data-interest-question');
            await question.locator(`input[value="${id === 'Q07' ? '0' : '2'}"]`).check();
        }
        if (step < 6) await page.getByRole('button', { name: '次の5問' }).click();
    }
    await page.getByRole('button', { name: '今の回答で結果を見る', exact: true }).click();
    await page.waitForURL(base + '/preparation/interests/result');
    await page.locator('[data-interest-result]').waitFor();
    await captureMobile(page, '02-interest-graph-390');
    assert.equal(await page.locator('[data-interest-category]').count(), 8);
    const scores = (await stored(a)).record.result;
    assert.equal(scores.unknown_count, 1);
    assert.equal(scores.answered_count, 29);
    assert.equal(scores.unanswered_count, 0);
    assert.equal(Object.values(scores.categories).reduce((sum, item) => sum + item.share_percent, 0), 100);
    await fits(page);
    await page.locator('[name="confirmed_categories[]"][value="CT"]').check();
    await page.getByText('準備の希望を選ぶ・見直す（複数選択・解除できます）', { exact: true }).click();
    await page.locator('[name="requested_support[]"][value="ST"]').check();
    await page.getByRole('button', { name: '自分の関心・希望を保存する', exact: true }).click();
    await page.getByRole('status').filter({ hasText: '保存しました。' }).waitFor();
    const saved = await stored(a);
    assert.deepEqual(saved.record.confirmed_categories, ['CT']);
    assert.deepEqual(saved.record.requested_support, ['ST']);
    // Clearing both optional groups must not resurrect inferred or prior choices.
    await page.locator('[name="confirmed_categories[]"][value="CT"]').uncheck();
    await page.locator('[name="requested_support[]"][value="ST"]').uncheck();
    await page.getByRole('button', { name: '自分の関心・希望を保存する', exact: true }).click();
    await page.waitForLoadState('load');
    const cleared = await stored(a);
    assert.deepEqual(cleared.record.confirmed_categories, []);
    assert.deepEqual(cleared.record.requested_support, []);
    assert.deepEqual(cleared.record.result, scores);
    await page.locator('[name="confirmed_categories[]"][value="CT"]').check();
    await page.getByText('準備の希望を選ぶ・見直す（複数選択・解除できます）', { exact: true }).click();
    await page.locator('[name="requested_support[]"][value="ST"]').check();
    await page.getByRole('button', { name: '自分の関心・希望を保存する', exact: true }).click();
    await page.waitForLoadState('load');
    const reviewed = await stored(a);
    // Duplicate request is idempotent even when the first response is lost.
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const draft = { _token: csrf, revision: reviewed.revision, action: 'save', step: 6,
        answers: reviewed.record.answers, save_consent: true };
    const post = () => a.request.post(base + '/preparation/interests', { data: draft, headers: { Accept: 'application/json' } });
    const one = await post(); const two = await post();
    assert.equal(one.status(), 200); assert.equal(two.status(), 200);
    assert.equal((await one.json()).revision, (await two.json()).revision);
    await page.getByRole('link', { name: '関連するCanovia Futureを見る', exact: true }).click();
    await page.getByRole('heading', { name: 'Canovia Future', exact: true }).waitFor();
    const futureArt = page.locator('img[src$="first-use-choices.webp"]');
    await futureArt.scrollIntoViewIfNeeded();
    await futureArt.evaluate(async img => { await img.decode(); });
    assert.equal(await page.getByText('一般公開済み', { exact: true }).count(), 0);
    await captureMobile(page, '03-future-390');
    await page.getByRole('link', { name: '関心のふりかえりへ戻る' }).click();
    await page.getByRole('link', { name: '自分で目標・Planを作る', exact: true }).click();
    // Explicit example click fills only an empty form and never saves it.
    const writes = [];
    const trackWrites = request => { if (request.method() === 'POST' && new URL(request.url()).pathname === '/plans') writes.push(request); };
    page.on('request', trackWrites);
    assert.equal(await page.locator('[name="title"]').inputValue(), '');
    await page.getByRole('button', { name: '知りたいことを調べる', exact: true }).click();
    assert.equal(await page.locator('[name="title"]').inputValue(), '気になることを一つ調べる');
    assert.equal(writes.length, 0);
    assert.equal(await page.getByRole('button', { name: 'これからの選択肢を探す', exact: true }).isDisabled(), true);
    page.off('request', trackWrites);
    await page.locator('[name="title"]').fill('関心から自分で選んだ合成目標');
    await page.locator('[name="description"]').fill('診断を確定事実にしない、自分の準備メモ');
    await page.getByRole('button', { name: 'Planを保存する', exact: true }).click();
    await page.locator('[data-preparation-plan]').waitFor();
    const plan = new URL(page.url()).pathname;
    await captureMobile(page, '04-plan-saved-390');
    await page.reload();
    await page.getByRole('link', { name: '目標・計画を編集する', exact: true }).click();
    assert.equal(await page.locator('[data-preparation-templates]').count(), 0);
    await page.locator('[name="description"]').fill('再訪して更新した合成メモ');
    await page.getByRole('button', { name: '変更を保存する', exact: true }).click();
    await page.locator('[data-preparation-plan]').waitFor();
    // No CSRF token, foreign owner, and forbidden specialists stay closed.
    assert.equal((await a.request.post(base + '/preparation/interests', { data: {} })).status(), 419);
    for (const path of ['/workspace/study', '/workspace/development', '/admin', '/api/mcp']) {
        assert.equal((await a.request.get(base + path, { headers: { Accept: 'application/json' } })).status(), 404);
    }
    const b = await context(); const { page: other } = await register(b);
    assert.equal((await stored(b)).record, null);
    assert.equal((await b.request.get(base + plan, { headers: { Accept: 'application/json' } })).status(), 404);
    const otherCsrf = await other.locator('meta[name="csrf-token"]').getAttribute('content');
    assert.equal((await b.request.put(base + plan, { data: { _token: otherCsrf, title: '侵入', preparation_revision: 'x' }, headers: { Accept: 'application/json' } })).status(), 403);
    await b.close();
    await page.getByRole('button', { name: 'ログアウト', exact: true }).click();
    assert.equal((await a.request.get(base + '/preparation/interests', { headers: { Accept: 'application/json' } })).status(), 401);
    await page.goto(base + '/login');
    await page.locator('[name="email"]').fill(email);
    await page.locator('[name="password"]').fill('Synthetic-Interest-2026');
    await page.getByRole('button', { name: 'ログイン', exact: true }).click();
    await page.waitForURL(base + '/');
    await page.getByRole('link', { name: '関心から自分で選んだ合成目標', exact: true }).click();
    assert.equal(new URL(page.url()).pathname, plan);
    await page.getByText('再訪して更新した合成メモ', { exact: true }).waitFor();
    await captureMobile(page, '05-plan-revisit-390');
    assert.deepEqual((await stored(a)).record.confirmed_categories, ['CT']);
    await fits(page);
    // Diagnostic skip does not create data or block a manual Plan.
    await page.goto(base + '/preparation/interests');
    await page.getByRole('link', { name: '診断をスキップして目標を入力する', exact: true }).click();
    await page.getByRole('heading', { name: '目標と計画を保存する', exact: true }).waitFor();
    await page.locator('[name="title"]').fill('例文に置き換えない自分の目標');
    assert.equal(await page.locator('[data-plan-example]:enabled').count(), 0);
    await page.reload();
    assert.equal(await page.locator('[name="title"]').inputValue(), '');
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto(base + '/preparation/interests/result');
    await fits(page);
    if (process.env.CANOVIA_INTEREST_SCREENSHOT) await page.screenshot({ path: process.env.CANOVIA_INTEREST_SCREENSHOT, fullPage: true });
    if (captureDirectory) {
        await page.goto(base + '/preparation/interests');
        await page.screenshot({ path: join(captureDirectory, 'diagnosis-desktop.png'), fullPage: true });
        await page.goto(base + '/feedback?from=interest-diagnostic');
        await page.screenshot({ path: join(captureDirectory, 'future-desktop.png'), fullPage: true });
        await page.getByRole('link', { name: '自分で目標・Planを作る', exact: true }).click();
        await page.screenshot({ path: join(captureDirectory, 'plan-form-desktop.png'), fullPage: true });
    }
    // Progressive enhancement: no JavaScript still submits native values/consent.
    const native = await context({ javaScriptEnabled: false, viewport: { width: 390, height: 844 } });
    const { page: nativePage } = await register(native);
    await nativePage.goto(base + '/preparation/interests');
    await nativePage.locator('[name="answers[Q01]"][value="3"]').check();
    await nativePage.locator('[name="answers[Q02]"][value="3"]').check();
    await nativePage.locator('[name="answers[Q03]"][value="unknown"]').check();
    await nativePage.locator('[name="save_consent"]').check();
    await nativePage.getByRole('button', { name: '今の回答で結果を見る', exact: true }).click();
    await nativePage.waitForURL(base + '/preparation/interests/result');
    await nativePage.getByText('わからない 1問', { exact: true }).waitFor();
    assert.equal((await stored(native)).record.answers.Q03, 'unknown');
    await native.close();
    assert.deepEqual(errors, []);
    if (captureDirectory) {
        assert.equal(mobileCaptures.length, 5);
        await writeFile(join(captureDirectory, 'manifest.json'), JSON.stringify({
            kind: 'SYNTHETIC_PREPARATION_MOBILE_EVIDENCE', engine, viewport: { width: 390, height: 844 },
            captures: mobileCaptures, source: 'OWNED_LOOPBACK_DISPOSABLE_SQLITE', real_device_verified: false,
            production_verified: false, secrets_or_real_user_data_used: false,
        }, null, 2));
    }
    console.log(JSON.stringify({ result: 'passed', journey: 'optional_interest_to_same_owner_plan',
        owner_level: 0, engine, questions: 30, mobile_capture_count: mobileCaptures.length, database: 'disposable_sqlite', real_device_verified: false }));
    await a.close();
} finally { await browser.close(); }

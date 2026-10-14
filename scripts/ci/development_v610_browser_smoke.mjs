/**
 * V6.10 disposable real Chromium user journey. The first Task is created
 * through the application's visible owner-submitted Plan JSON import,
 * not through CLI/DB seeding or synthetic HTTP routes.
 * Browser-entered JSON is NOT proof of autonomous AI recommendation.
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const base = process.env.CANOVIA_V610_BASE_URL;
if (base !== 'http://127.0.0.1:8791' ||
    process.env.CANOVIA_V610_BROWSER_FIXTURE !== '1' ||
    process.env.APP_ENV !== 'testing' ||
    process.env.DB_CONNECTION !== 'sqlite') {
    throw Error('Disposable local browser safety gate failed');
}

const email = 'v610-browser@example.invalid';
const password = 'V610-only!test-Password';
const title = 'V610 ブラウザ開発Plan';
const next = '次はログアウト後のホーム復帰を直す';
const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
const ctx = await browser.newContext({
    viewport: { width: 1280, height: 850 },
    locale: 'ja-JP',
});
const page = await ctx.newPage();
page.setDefaultTimeout(20000);
const step = (name) => process.stdout.write(`PASS ${name}\n`);
try {
    const initial = await page.goto(`${base}/plans/create/manual`, { waitUntil: 'domcontentloaded' });
    assert.equal(initial.status(), 200, 'Initial Guest page must be accessible');
    if (new URL(page.url()).pathname === '/welcome') {
        await page.locator('form[data-first-run-start]')
            .getByRole('button', { name: 'Canoviaと始める' }).click();
        step('Entered through actual first-run consent/intro form');
        await page.goto(`${base}/plans/create/manual`, { waitUntil: 'domcontentloaded' });
    }
    assert.equal(new URL(page.url()).pathname, '/plans/create/manual',
        'Guest should enter Plan form after real onboarding start');

    const form = page.locator('form[data-onboarding-target="plan-form"]');
    await form.locator('input[name="title"]').fill(title);
    // Category is deliberately hidden in progressive-disclosure details.
    // Open it as a human does; do not force hidden DOM interactions.
    await form.locator('details:has(select[name="category"]) > summary').click();
    await form.locator('select[name="category"]').selectOption('個人開発');
    await form.locator('button[type="submit"]').click();
    await page.waitForURL(url => !url.pathname.startsWith('/plans/create'), { timeout: 25000 });
    step('Guest created Development Plan through visible form');
    const planId = Number(new URL(page.url()).searchParams.get('plan_id'));
    assert.ok(Number.isSafeInteger(planId) && planId > 0,
        'Development Plan create must redirect to the real Workspace with a plan_id');

    await page.goto(`${base}/register`);
    const registration = page.locator('form[action$="/register"]');
    await registration.locator('input[name="name"]').fill('V610 Browser');
    await registration.locator('input[name="email"]').fill(email);
    await registration.locator('input[name="password"]').fill(password);
    await registration.locator('input[name="password_confirmation"]').fill(password);
    await registration.getByRole('button', { name: '登録してそのまま続ける' }).click();
    await page.waitForURL(url => url.pathname !== '/register');
    step('Guest Plan claimed by newly registered account');

    // Follow the real empty Plan -> Plan builder link instead of injecting a Task.
    const planPage = await page.goto(`${base}/plans/${planId}`);
    assert.equal(planPage.status(), 200);
    const firstTaskLink = page.locator('[data-plan-optional-task-planning]');
    await firstTaskLink.waitFor({ state: 'visible' });
    await firstTaskLink.click();
    const importer = page.locator('form[data-ai-plan-generation-import]');
    await importer.waitFor({ state: 'visible' });
    const jsonDetails = importer.locator('#initialPlanJsonManualInput');
    await jsonDetails.locator('summary').click();
    const taskTitle = 'ブラウザでログイン復帰を調べる';
    const payload = {
        schema_version: '2.0',
        flow: 'plan_generation',
        target_plan: { id: planId, title, category: '個人開発' },
        summary: '本人入力で最初の1 Taskを作成する',
        operations: [{
            type: 'add_task', client_ref: 'task_1', title: taskTitle,
            description: 'Chromiumから作成したTaskでGuided Executionを検証',
            estimated_minutes: 45, remaining_minutes: 45,
            progress_percent: 0, status: 'todo', priority: 1, activation_cost: 2,
        }],
    };
    await importer.locator('textarea[name="tasks_json"]').fill(JSON.stringify(payload));
    await importer.getByRole('button', { name: 'この回答を読み込む' }).click();
    await page.waitForURL(url => url.pathname === `/plans/${planId}`
        || url.pathname.startsWith('/workspace/development'));
    await page.goto(`${base}/plans/${planId}`);
    await page.getByText(taskTitle, { exact: true }).first().waitFor({ state: 'visible' });
    step('Registered user created first Task through visible JSON import (no DB fixture)');

    // CLI reads isolated SQLite for an exact Task ID; it never writes data.
    const created = JSON.parse(execFileSync(
        'php', ['scripts/ci/development_v610_verify_browser_task.php', '--lookup'],
        { encoding: 'utf8', env: process.env, timeout: 20000 },
    ));
    assert.equal(created.plan_id, planId);
    assert.equal(created.task_count, 1);
    assert.equal(created.task_title, taskTitle);
    assert.equal(created.task_progress_percent, 0);
    assert.equal(created.task_evidence_count, 0);
    assert.equal(created.guided_execution_count, 0);

    const guidedUrl = `${base}/plans/${created.plan_id}/tasks/${created.task_id}/guided-execution`;
    const opened = await page.goto(guidedUrl);
    assert.equal(opened.status(), 200);
    const prepare = page.locator('[data-guided-execution-prepare] form');
    await prepare.locator('textarea[name="intent"]').fill('ログイン状態を実際に確認する');
    await prepare.getByRole('button', { name: 'この方針で現実の実行へ' }).click();
    await page.locator('[data-guided-reflection]').waitFor();
    step('Started first execution using rendered form/CSRF');

    const reflect = page.locator('[data-guided-reflection] form');
    await reflect.locator('input[name="outcome_rating"][value="partly"]').check();
    await reflect.locator('textarea[name="actual_outcome"]').fill('ホームに戻ると画面の状態が一部失われた');
    await reflect.locator('textarea[name="next_adjustment"]').fill(next);
    await reflect.getByRole('button', { name: '振り返りをEvidenceとして残す' }).click();
    await page.locator('[data-reflection-next-action] form').waitFor();
    step('Saved actual reflection through Chromium form');

    await page.locator('[data-reflection-next-action] form').getByRole(
        'button', { name: 'この内容を次のActionに反映する' },
    ).click();
    await page.locator('[data-reflection-next-action-adopted]').waitFor();
    await page.locator('[data-reflection-next-action-start]').click();
    await page.locator('[data-guided-execution-prepare]').waitFor();
    const intent = page.locator('[data-guided-execution-prepare] textarea[name="intent"]');
    assert.equal(await intent.inputValue(), next);
    step('Confirmed next Action and followed on-page link to prefilled form');

    await page.locator('[data-guided-execution-prepare] form')
        .getByRole('button', { name: 'この方針で現実の実行へ' }).click();
    await page.locator('[data-guided-execution-active]').waitFor();
    assert.equal(await page.locator('[data-guided-execution-active]').getByText(next).count() > 0, true);
    step('Started follow-up action without submitting another reflection');

    await page.goto(`${base}/account`);
    const logout = page.locator('form[action$="/logout"]').first();
    await Promise.all([
        page.waitForURL(url => url.pathname !== '/account', {
            waitUntil: 'domcontentloaded',
        }),
        logout.getByRole('button', { name: 'ログアウト' }).click(),
    ]);
    step('Real logout form completed navigation and invalidated session');
    await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
    const login = page.locator('form[action$="/login"]');
    await login.locator('input[name="email"]').fill(email);
    await login.locator('input[name="password"]').fill(password);
    await login.getByRole('button', { name: 'ログイン', exact: true }).click();
    await page.waitForURL(url => url.pathname !== '/login');
    const resumed = await page.goto(guidedUrl);
    assert.equal(resumed.status(), 200);
    await page.locator('[data-guided-execution-active]').waitFor();
    assert.ok(await page.getByText(next).count() >= 1);
    step('Logout/login restored follow-up action on the same user and Plan');

    const checked = JSON.parse(execFileSync(
        'php', ['scripts/ci/development_v610_verify_browser_task.php', '--verify'],
        { encoding: 'utf8', env: process.env, timeout: 20000 },
    ));
    assert.equal(checked.guided_execution_count, 2);
    assert.equal(checked.task_evidence_count, 1);
    assert.equal(checked.task_progress_percent, 0);
    assert.equal(checked.task_count, 1);
    assert.equal(checked.plan_id, planId);
    assert.equal(checked.task_id, created.task_id);
    assert.equal(checked.task_next_action, next);
    step('Backend facts match browser-visible action (2 runs, 1 Evidence, progress unchanged)');
    process.stdout.write('V6.10 DEVELOPMENT BROWSER ACCEPTANCE PASS (owner-entered JSON; NOT autonomous AI or full P0 GO)\n');
} finally {
    await ctx.close();
    await browser.close();
}

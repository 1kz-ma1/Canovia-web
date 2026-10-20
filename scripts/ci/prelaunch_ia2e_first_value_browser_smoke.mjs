/**
 * Pre-Launch IA-2e actual first-value Chromium journey. The first Task is created
 * through the application's visible owner-submitted Plan JSON import,
 * not through CLI/DB seeding or synthetic HTTP routes.
 * Browser-entered JSON is NOT proof of autonomous AI recommendation.
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium, webkit } from 'playwright';

const base = process.env.CANOVIA_IA2E_BASE_URL;
const engine = process.env.CANOVIA_IA2E_BROWSER_ENGINE ?? 'chromium';
const entry = process.env.CANOVIA_IA2E_ENTRY ?? 'browse';
if (!['browse', 'diagnosis'].includes(entry)) {
    throw Error('Unsupported first-value entry');
}
if (!['chromium', 'webkit'].includes(engine)) {
    throw Error('Unsupported browser engine for isolated first-value smoke');
}
const mobile = engine === 'webkit';
if (base !== 'http://127.0.0.1:8797' ||
    process.env.CANOVIA_IA2E_BROWSER_FIXTURE !== '1' ||
    process.env.APP_ENV !== 'testing' ||
    process.env.DB_CONNECTION !== 'sqlite' ||
    process.env.CANOVIA_PUBLIC_RELEASE_LEVEL !== '1' ||
    process.env.NATIVE_AI_DRIVER !== 'disabled') {
    throw Error('Disposable local browser safety gate failed');
}

const email = 'ia2e-browser@example.invalid';
const password = 'IA2e-only!test-Password';
const title = 'IA2e 初回体験開発Plan';
const next = '次はログアウト後のホーム復帰を直す';
const browser = await (mobile ? webkit : chromium).launch({
    headless: true,
    ...(mobile ? {} : { args: ['--no-sandbox'] }),
    ...(process.env.CANOVIA_BROWSER_EXECUTABLE_PATH
        ? { executablePath: process.env.CANOVIA_BROWSER_EXECUTABLE_PATH } : {}),
});
const ctx = await browser.newContext({
    viewport: mobile ? { width: 390, height: 844 } : { width: 1280, height: 850 },
    locale: 'ja-JP',
    serviceWorkers: 'block',
    ...(mobile ? { isMobile: true, hasTouch: true } : {}),
});
// These are synthetic journeys; even fonts must stay inside the loopback boundary.
await ctx.route(/^https?:\/\//, route => new URL(route.request().url()).origin === base
    ? route.continue() : route.abort());
const page = await ctx.newPage();
page.setDefaultTimeout(20000);
const step = (name) => process.stdout.write(`PASS ${name}\n`);
try {
    const initial = await page.goto(`${base}/`, { waitUntil: 'domcontentloaded' });
    assert.equal(initial.status(), 200, 'The guest should enter the actual first-run Welcome');
    assert.equal(new URL(page.url()).pathname, '/welcome');
    if (entry === 'browse') {
        await page.locator('[data-first-run-choice="browse"]').click();
        await page.waitForURL(url => url.pathname === '/workspace/overview');
        await page.locator('[data-overview-planless-start]').waitFor({state:'visible'});
        if (mobile) {
            assert.ok(await page.locator('[data-overview-start-manual-plan]').isVisible(),
                'Mobile WebKit must expose an accessible optional Plan action');
        }
        assert.equal(await page.locator('[data-overview-primary-action]').count(), 0,
            'A zero-Plan visitor must not see a fictitious recommended Action');
        step('Guest chose Browse and reached a truthful Planless Overview without a forced diagnosis');
        await page.locator('[data-overview-start-manual-plan]').click();
        await page.waitForURL(url => url.pathname === '/plans/create/manual');
        step('Guest voluntarily selected manual Plan creation from the Overview');
    } else {
        await page.locator('[data-first-run-choice="diagnosis"]').click();
        await page.waitForURL(url => url.pathname === '/personalization');
        const diagnosis = page.locator('[data-personalization-form]');
        await diagnosis.locator('label:has([data-personalization-domain="development"])').click();
        await diagnosis.locator('input[name="development_goal"]').fill('編集前の開発目標');
        await diagnosis.locator('input[name="deadline"]').fill('2026-12-01');
        await diagnosis.locator('select[name="weekly_capacity"]').selectOption('2_5');
        await diagnosis.locator('select[name="development_stage"]').selectOption('existing');
        await diagnosis.locator('select[name="github_usage"]').selectOption('no');
        await diagnosis.locator('select[name="repository_ready"]').selectOption('no');
        await diagnosis.getByRole('button', { name: 'おすすめを作る' }).click();
        await page.waitForURL(url => url.pathname === '/personalization/result');
        await page.locator('[data-plan-seed="development_existing"]')
            .getByRole('button', { name: 'この型からPlanを作る' }).click();
        await page.waitForURL(url => url.pathname === '/plans/create/manual');
        const editor = page.locator('form[data-onboarding-target="plan-form"]');
        assert.equal(await editor.locator('input[name="title"]').inputValue(), '編集前の開発目標');
        assert.equal(await editor.locator('input[name="deadline"]').inputValue(), '2026-12-01');
        assert.match(await editor.locator('textarea[name="description"]').inputValue(), /Current State/);
        step('Diagnosis supplied an editable phase outline, not an automatically scheduled Task');
    }

    const form = page.locator('form[data-onboarding-target="plan-form"]');
    await form.locator('input[name="title"]').fill(title);
    // Category is deliberately hidden in progressive-disclosure details.
    // Open it as a human does; do not force hidden DOM interactions.
    if (!await form.locator('select[name="category"]').isVisible()) {
        await form.locator('details:has(select[name="category"]) > summary').click();
    }
    await form.locator('select[name="category"]').selectOption('個人開発');
    await form.locator('button[type="submit"]').click();
    await page.waitForURL(url => !url.pathname.startsWith('/plans/create'), { timeout: 25000 });
    step('Guest created Development Plan through visible form');
    const planId = Number(new URL(page.url()).searchParams.get('plan_id'));
    assert.ok(Number.isSafeInteger(planId) && planId > 0,
        'Development Plan create must redirect to the real Workspace with a plan_id');

    await page.goto(`${base}/register`);
    const registration = page.locator('form[action$="/register"]');
    await registration.locator('input[name="name"]').fill('IA2e Browser');
    await registration.locator('input[name="email"]').fill(email);
    await registration.locator('input[name="password"]').fill(password);
    await registration.locator('input[name="password_confirmation"]').fill(password);
    await registration.getByRole('button', { name: '登録してそのまま続ける' }).click();
    await page.waitForURL(url => url.pathname !== '/register');
    step('Guest Plan claimed by newly registered account');

    const csrfDenied = await ctx.request.post(`${base}/plans`, {
        form: { title: 'This tokenless request must not create a Plan' },
        headers: { 'Sec-Fetch-Site': 'cross-site' },
        maxRedirects: 0,
    });
    assert.equal(csrfDenied.status(), 419, 'A cross-site write without CSRF proof must fail');
    step('A real HTTP write with no CSRF token is rejected');
    const emptyPlan = JSON.parse(execFileSync(
        'php', ['scripts/ci/prelaunch_ia2e_verify_first_value.php', '--plan-only'],
        { encoding: 'utf8', env: process.env, timeout: 20000 },
    ));
    assert.equal(emptyPlan.plan_id, planId);
    assert.equal(emptyPlan.plan_count, 1, 'Diagnosis and rejected CSRF cannot silently create another Plan');
    assert.equal(emptyPlan.task_count, 0, 'A phase outline is not a generated Task');
    if (entry === 'diagnosis') assert.equal(emptyPlan.deadline, '2026-12-01');
    step('Edited Plan survived Guest-to-Account claim with zero automatically created Tasks');

    // Follow the real empty Plan -> Plan builder link instead of injecting a Task.
    const planPage = await page.goto(`${base}/plans/${planId}`);
    assert.equal(planPage.status(), 200);
    const firstTaskLink = page.locator('[data-plan-optional-task-planning]');
    if (mobile && !await firstTaskLink.isVisible()) {
        const visibility = await firstTaskLink.evaluate((node) => {
            const chain = [];
            for (let el = node, i = 0; el && i < 10; el = el.parentElement, i++) {
                const style = getComputedStyle(el);
                chain.push({
                    tag: el.tagName,
                    classes: String(el.className).slice(0, 160),
                    display: style.display,
                    visibility: style.visibility,
                    rects: el.getClientRects().length,
                });
            }
            return { width: document.documentElement.clientWidth, chain };
        });
        process.stdout.write(`MOBILE WEBKIT VISIBILITY DIAGNOSTIC ${JSON.stringify(visibility)}\n`);
    }
    await firstTaskLink.waitFor({ state: 'visible' });
    await firstTaskLink.click();
    const importer = page.locator('form[data-ai-plan-generation-import]');
    await importer.waitFor({ state: 'visible' });
    const jsonDetails = importer.locator('#initialPlanJsonManualInput');
    await jsonDetails.locator('summary').click();
    const taskTitle = '最初の一歩として画面を確認する';
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
        'php', ['scripts/ci/prelaunch_ia2e_verify_first_value.php', '--lookup'],
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
    // Verify the real, persisted Evidence/next Action is discoverable through
    // a user-facing dashboard, not only through a CLI/DB assertion.
    const dashboard = await page.goto(`${base}/plans/${planId}/dashboard`, {waitUntil:'domcontentloaded'});
    assert.equal(dashboard.status(), 200);
    await page.locator('[data-plan-dashboard-standalone]').waitFor({state:'visible'});
    const overviewRegion = page.locator('[data-plan-board-region="overview"]');
    await overviewRegion.getByText('1 Task').waitFor({state:'visible'});
    await page.locator('[data-plan-board-region="progress"]').waitFor({state:'visible'});
    const nextRegion = page.locator('[data-plan-board-region="next"]');
    await nextRegion.getByText(taskTitle, {exact:true}).waitFor({state:'visible'});
    assert.ok((await nextRegion.innerText()).includes(next),
        'After human approval the Plan Dashboard should show the real next Action');
    const reflections = page.locator('[data-plan-board-reflections]');
    await reflections.waitFor({state:'attached'});
    if (mobile) {
        assert.ok(await reflections.isVisible(), 'Mobile WebKit must show the saved Evidence region');
    }
    const reflection = reflections.locator(`[data-plan-board-reflection-task="${created.task_id}"]`);
    assert.equal(await reflection.count(), 1, 'A saved Evidence should appear exactly once as a reflection');
    assert.match(await reflection.innerText(), /ホームに戻ると画面の状態が一部失われた/);
    assert.match(await reflection.innerText(), /進捗率への自動反映なし/);
    const reflectionLink = reflection.locator('a[href]');
    assert.equal(new URL(await reflectionLink.getAttribute('href'), base).pathname,
        new URL(guidedUrl).pathname, 'The reflection returns to the actor-owned Task');
    assert.equal(await page.locator('[data-plan-board-region="activity"]')
        .getByText('時間を記録した作業はまだありません。').count(), 1,
        'Evidence is not a timed WorkLog');
    step('The actor sees their saved reflection Evidence as a separate linked Dashboard item');

    step('Saved Evidence is followed by truthful Plan Dashboard state: 1 Task, progress and adopted next Action');

    const overview = await page.goto(`${base}/workspace/overview`, {waitUntil:'domcontentloaded'});
    assert.equal(overview.status(), 200);
    assert.equal(await page.locator('[data-overview-planless-start]').count(), 0,
        'Overview must recognize the registered actor now owns a Plan');
    const developmentCard = page.locator('[data-overview-mode="development"]');
    await developmentCard.waitFor({state:'visible'});
    assert.ok((await developmentCard.locator('.plan-identity-chip').innerText()).includes(title),
        'The current actor\'s Development Plan must appear in the global Overview');
    step('Returning to the global Overview shows an actual Plan, never the empty first-use shell');

    await page.goto(guidedUrl, {waitUntil:'domcontentloaded'});
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
    // The desktop header has another logout form that is deliberately hidden
    // on mobile. Exercise the account page's visible form, not .first().
    const logout = page.locator('main form[action$="/logout"]');
    assert.equal(await logout.count(), 1);
    assert.ok(await logout.isVisible(), 'The account logout action must be visible');
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
        'php', ['scripts/ci/prelaunch_ia2e_verify_first_value.php', '--verify'],
        { encoding: 'utf8', env: process.env, timeout: 20000 },
    ));
    assert.equal(checked.guided_execution_count, 2);
    assert.equal(checked.task_evidence_count, 1);
    assert.equal(checked.task_progress_percent, 0);
    assert.equal(checked.task_count, 1);
    assert.equal(checked.plan_id, planId);
    assert.equal(checked.task_id, created.task_id);
    assert.equal(checked.task_next_action, next);
    assert.equal(checked.plan_id, planId);
    assert.equal(checked.task_evidence_count, 1);
    step('Backend facts match browser-visible action (2 runs, 1 Evidence, progress unchanged)');
    // A second real registered actor has no grant to the first actor's private Plan.
    const other = await browser.newContext({ serviceWorkers: 'block' });
    try {
        await other.route(/^https?:\/\//, route => new URL(route.request().url()).origin === base
            ? route.continue() : route.abort());
        const otherPage = await other.newPage();
        await otherPage.goto(`${base}/register`);
        const register = otherPage.locator('form[action$="/register"]');
        await register.locator('input[name="name"]').fill('Unrelated synthetic account');
        await register.locator('input[name="email"]').fill('ia2e-other@example.invalid');
        await register.locator('input[name="password"]').fill(password);
        await register.locator('input[name="password_confirmation"]').fill(password);
        await register.getByRole('button', { name: '登録してそのまま続ける' }).click();
        await otherPage.waitForURL(url => url.pathname !== '/register');
        for (const [url, status] of [[`/plans/${planId}`, 404],
            [`/plans/${planId}/dashboard`, 404],
            [`/workspace/development?plan_id=${planId}`, 404],
            [new URL(guidedUrl).pathname, 403]]) {
            const denied = await otherPage.goto(base + url);
            assert.equal(denied.status(), status, `Another account must be denied ${url}`);
            assert.equal(await otherPage.getByText(title, { exact: true }).count(), 0);
        }
        step('Another registered account is denied private Plan, Dashboard, Workspace and Task');
    } finally {
        await other.close();
    }
    step(`First-value entry: ${entry}`);
    step(`Acceptance engine: ${engine}${mobile ? ' / touch 390x844' : ' / desktop 1280x850'}`);
    process.stdout.write('IA-2e FIRST VALUE E2E PASS (owner-entered Task; browser Evidence and current state; NOT real device/live public GO)\n');
} finally {
    await ctx.close();
    await browser.close();
}

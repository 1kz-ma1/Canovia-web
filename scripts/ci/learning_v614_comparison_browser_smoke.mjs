/**
 * Learning V6.14: actual Chromium inputs/clicks for Study Plan, first Action
 * and published synthetic Question Bank. No Task/Plan/Run/Answer DB seeding.
 * Bank question content alone is a bounded testing fixture.
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const base = process.env.CANOVIA_LEARNING_V610_BASE_URL;
if (base !== 'http://127.0.0.1:8792' ||
    process.env.CANOVIA_LEARNING_V610_FIXTURE !== '1' ||
    process.env.APP_ENV !== 'testing' ||
    process.env.DB_CONNECTION !== 'sqlite') {
    throw Error('Learning browser must run against isolated loopback testing SQLite');
}

const email = 'v610-learning@example.invalid';
const password = 'V610-learning-only!test-Password';
const title = '応用情報技術者試験 合格 V610';
const packTitle = 'V610 合成ネットワーク演習';
const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
const context = await browser.newContext({viewport:{width:1280,height:850},locale:'ja-JP'});
const page = await context.newPage();
page.setDefaultTimeout(20000);
const step = name => process.stdout.write(`PASS ${name}\n`);
const verify = flag => JSON.parse(execFileSync(
    'php', ['scripts/ci/learning_v610_disposable_bank.php', flag],
    {encoding:'utf8',env:process.env,timeout:20000},
));
try {
    const response = await page.goto(`${base}/plans/create/manual`, {waitUntil:'domcontentloaded'});
    assert.equal(response.status(), 200);
    if (new URL(page.url()).pathname === '/welcome') {
        // Supports both the old one-button welcome and IA-2b's
        // three explicit entry choices. No hidden auth bypass.
        const entryForm = page.locator('form[data-first-run-start]');
        const browseChoice = entryForm.locator('[data-first-run-choice="browse"]');
        if (await browseChoice.count()) {
            await browseChoice.click();
        } else {
            await entryForm.getByRole('button',{name:'Canoviaと始める'}).click();
        }
        await page.goto(`${base}/plans/create/manual`,{waitUntil:'domcontentloaded'});
    }

    const form = page.locator('form[data-onboarding-target="plan-form"]');
    await form.locator('input[name="title"]').fill(title);
    await form.locator('details:has(select[name="category"]) > summary').click();
    await form.locator('select[name="category"]').selectOption('資格学習');
    await form.locator('button[type="submit"]').click();
    await page.waitForURL(url => !url.pathname.startsWith('/plans/create'));
    const planId = Number(new URL(page.url()).searchParams.get('plan_id'));
    assert.ok(Number.isSafeInteger(planId) && planId > 0);
    step('Guest created a real Study Plan through visible form');

    await page.goto(`${base}/register`);
    const register = page.locator('form[action$="/register"]');
    await register.locator('input[name="name"]').fill('V610 Learning Browser');
    await register.locator('input[name="email"]').fill(email);
    await register.locator('input[name="password"]').fill(password);
    await register.locator('input[name="password_confirmation"]').fill(password);
    await register.getByRole('button',{name:'登録してそのまま続ける'}).click();
    await page.waitForURL(url=>url.pathname!=='/register');
    step('Synthetic Free account registered and claimed Guest Study Plan');

    const workspace = await page.goto(`${base}/workspace/study?plan_id=${planId}`);
    assert.equal(workspace.status(),200);
    const action = page.locator(`form[action$="/plans/${planId}/study-action/execute"]`).first();
    await action.waitFor({state:'visible'});
    await action.locator('button[type="submit"]').click();
    await page.waitForURL(url=>url.pathname.includes('/study-practice'),{timeout:25000});
    step('Owner created first Study Task through visible recommended Action');

    const created = verify('--lookup');
    assert.equal(created.plan_id,planId);
    assert.equal(created.task_count,1);
    assert.equal(created.progress_percent,0);
    assert.ok(Number.isSafeInteger(created.task_id));
    const entry = page.locator('[data-adaptive-learning-entry]').first();
    await entry.waitFor({state:'visible'});
    await entry.click();
    const startForm = page.locator('[data-adaptive-learning-start] form').first();
    await startForm.waitFor({state:'visible'});
    await startForm.locator('input[name="mode"][value="understanding"]').check();
    const option = startForm.locator('select[name="question_pack_id"] option')
        .filter({hasText:packTitle});
    assert.equal(await option.count(),1,'Expected exactly one published synthetic pack');
    const packId = await option.getAttribute('value');
    assert.equal(Number(packId),created.pack_id);
    await startForm.locator('select[name="question_pack_id"]').selectOption(packId);
    await startForm.getByRole('button',{name:'問題を始める'}).click();
    await page.locator('[data-learning-question="1"]').waitFor();
    const runPath = new URL(page.url()).pathname;
    assert.match(runPath,/\/learning\/\d+$/);
    step('Started first Bank question using rendered Study form');

    const first = page.locator('[data-learning-question="1"]');
    await first.getByText('V610 検証用ネットワーク問題 1').waitFor();
    await first.locator('input[name="choice"][value="B"]').check();
    await first.locator('textarea[name="reasoning"]').fill('選択肢Bを選んだ理由のメモ');
    await first.getByRole('button',{name:'この1問を採点・保存'}).click();
    const feedback1 = page.locator('[data-learning-answer-feedback]');
    await feedback1.waitFor();
    await feedback1.getByText('不正解',{exact:true}).waitFor();
    await page.getByText('V610 合成問題の採点解説 1').waitFor();
    step('Saved incorrect first answer, grade, rationale and explanation');

    await page.getByRole('link',{name:'回答履歴を見る →'}).click();
    await page.locator('[data-learning-history]').waitFor();
    assert.equal(await page.locator('[data-learning-history-entry]').count(),1);
    await page.getByRole('link',{name:'← 1問ずつ学習へ戻る'}).click();
    await page.locator('[data-adaptive-learning-resume]').waitFor();
    await page.locator('[data-adaptive-learning-resume] a').first().click();
    await page.locator('[data-learning-answer-feedback]').waitFor();
    await page.getByRole('button',{name:'次の問題へ'}).click();
    const second = page.locator('[data-learning-question="2"]');
    await second.waitFor();
    await second.getByText('V610 検証用ネットワーク問題 2').waitFor();
    step('Resumed from saved Study history and advanced to next Question Action');

    await second.locator('input[name="choice"][value="A"]').check();
    await second.getByRole('button',{name:'この1問を採点・保存'}).click();
    await page.locator('[data-learning-answer-feedback]').getByText('正解',{exact:true}).waitFor();
    await page.getByRole('button',{name:'次の問題へ'}).click();
    await page.locator('[data-learning-question="3"]').waitFor();
    step('Graded second answer and advanced to third Question Action');

    await page.goto(`${base}/account`);
    const logout = page.locator('form[action$="/logout"]').first();
    await Promise.all([
        page.waitForURL(url => url.pathname !== '/account', {
            waitUntil: 'domcontentloaded',
        }),
        logout.getByRole('button', {name:'ログアウト'}).click(),
    ]);
    await page.goto(`${base}/login`, {waitUntil:'domcontentloaded'});
    const login=page.locator('form[action$="/login"]');
    await login.locator('input[name="email"]').fill(email);
    await login.locator('input[name="password"]').fill(password);
    await login.getByRole('button',{name:'ログイン',exact:true}).click();
    await page.waitForURL(url=>url.pathname!=='/login');
    const resumed = await page.goto(`${base}${runPath}`);
    assert.equal(resumed.status(),200);
    await page.locator('[data-learning-question="3"]').waitFor();
    step('Logout/login restored question index and actor-bound Learning Run');

    // Third distinct question is answered incorrectly. Together with the
    // first miss this creates a bounded review candidate, not fake mastery.
    const third = page.locator('[data-learning-question="3"]');
    await third.locator('input[name="choice"][value="B"]').check();
    await third.getByRole('button',{name:'この1問を採点・保存'}).click();
    await page.locator('[data-learning-answer-feedback]').getByText('不正解',{exact:true}).waitFor();
    // V6.14: return via the real Study Workspace; the owner should now see
    // the new evidence-bounded queue without navigating to per-Task history.
    const studyWork = await page.goto(`${base}/workspace/study?plan_id=${planId}&surface=work`,
        {waitUntil:'domcontentloaded'});
    assert.equal(studyWork.status(),200);
    const workspaceCue = page.locator('[data-study-learning-review-queue]');
    await workspaceCue.waitFor({state:'visible'});
    assert.equal(await workspaceCue.locator('[data-study-learning-review-item]').count(),1);
    assert.equal(await workspaceCue.locator('[data-study-learning-review-item]')
        .getAttribute('data-study-review-task-id'), String(created.task_id));
    await workspaceCue.getByText('異なる誤答問題 2問').waitFor();
    assert.equal(await workspaceCue.locator('[data-study-learning-review-open]').count(),1);
    const beforeWorkspace = verify('--verify');
    assert.equal(beforeWorkspace.task_evidence_count,0);
    assert.equal(beforeWorkspace.task_next_action,created.task_next_action);
    step('Study Workspace surfaced repeated-miss proposal without auto-changing Task');
    await workspaceCue.locator('[data-study-learning-review-open]').click();
    await page.locator('[data-learning-next-action-preview]').waitFor({state:'visible'});
    await page.getByText('「ネットワーク」を別の問題で再確認し、誤答の理由を見直す').waitFor();
    const beforeAdopt = verify('--verify');
    assert.equal(beforeAdopt.learning_answer_count,3);
    assert.equal(beforeAdopt.correct_count,1);
    assert.equal(beforeAdopt.progress_percent,0);
    assert.equal(beforeAdopt.task_evidence_count,0);
    step('Two distinct wrong answers produced a review suggestion without mutating the Task');

    const confirmedResponsePromise = page.waitForResponse(response =>
        response.request().method() === 'POST'
        && new URL(response.url()).pathname.endsWith('/learning/confirm-next-action'),
    );
    await page.locator('[data-learning-next-action-preview] form')
        .getByRole('button',{name:'この復習を次のActionに反映する'}).click();
    const confirmedResponse = await confirmedResponsePromise;
    assert.equal(confirmedResponse.status(), 302,
        'Owner confirmation must respond with a real POST redirect; status='
        + confirmedResponse.status() + ', page=' + new URL(page.url()).pathname);
    const refreshed = await page.goto(
        `${base}/plans/${planId}/tasks/${created.task_id}/learning/history`,
        {waitUntil:'domcontentloaded'});
    assert.equal(refreshed.status(),200);
    await page.locator('[data-learning-next-action-adopted]').waitFor({state:'visible'});
    step('Owner explicitly confirmed the suggested next Action in real browser');

    const refreshedWorkspace = await page.goto(
        `${base}/workspace/study?plan_id=${planId}&surface=work`,
        {waitUntil:'domcontentloaded'});
    assert.equal(refreshedWorkspace.status(),200);
    await page.locator('[data-study-learning-review-queue] [data-study-review-adopted="true"]')
        .waitFor({state:'visible'});
    await page.locator('[data-study-review-applied]').waitFor();
    step('Study Workspace reloaded owner-approved review state without changing grading');

    const final = verify('--verify');
    assert.equal(final.plan_id,planId);
    assert.equal(final.task_id,created.task_id);
    assert.equal(final.task_count,1);
    assert.equal(final.learning_answer_count,3);
    assert.equal(final.correct_count,1);
    assert.equal(final.run_status,'active');
    assert.equal(final.run_current_ordinal,3);
    assert.equal(final.progress_percent,0);
    assert.equal(final.task_evidence_count,0);
    assert.equal(final.task_next_action,'「ネットワーク」を別の問題で再確認し、誤答の理由を見直す');
    step('Read-only DB: 1 Task, 3 graded answers, confirmed next Action, no fabricated progress or Evidence');

    // V6.14: a new focused review Run starts only after the owner explicitly
    // chooses the topic and published Bank. Nothing is silently generated.
    const reviewLink = page.locator('[data-study-learning-review-queue] [data-study-learning-review-practice]');
    await reviewLink.waitFor({state:'visible'});
    await reviewLink.click();
    const reviewEntry = page.locator('[data-learning-topic-review-entry]');
    await reviewEntry.waitFor({state:'visible'});
    const focusedStart = reviewEntry.locator('[data-learning-topic-review-start]');
    await focusedStart.waitFor({state:'visible'});
    await focusedStart.locator('select[name="question_pack_id"]').selectOption(packId);
    const focusedPostPromise = page.waitForResponse(response =>
        response.request().method() === 'POST'
        && new URL(response.url()).pathname.endsWith('/learning'));
    await focusedStart.getByRole('button',{name:'この分野の問題を解く'}).click();
    const focusedPost = await focusedPostPromise;
    assert.equal(focusedPost.status(),302,
        'Focused Bank Run POST must redirect to its first Question; status='
        + focusedPost.status() + ', page=' + new URL(page.url()).pathname);
    await page.locator('[data-learning-question="1"]').waitFor({state:'visible'});
    const focusedPath = new URL(page.url()).pathname;
    assert.notEqual(focusedPath,runPath,'Topic review must create a fresh actor-bound Run');
    step('Workspace topic review created a separate first-question Bank Run through visible consent');

    const reviewFirst = page.locator('[data-learning-question="1"]');
    await reviewFirst.locator('input[name="choice"][value="A"]').check();
    await reviewFirst.getByRole('button',{name:'この1問を採点・保存'}).click();
    await page.locator('[data-learning-answer-feedback]').getByText('正解',{exact:true}).waitFor();
    await page.getByRole('button',{name:'次の問題へ'}).click();
    const reviewSecond = page.locator('[data-learning-question="2"]');
    await reviewSecond.waitFor({state:'visible'});
    await reviewSecond.locator('input[name="choice"][value="A"]').check();
    await reviewSecond.getByRole('button',{name:'この1問を採点・保存'}).click();
    await page.locator('[data-learning-answer-feedback]').getByText('正解',{exact:true}).waitFor();
    await page.locator('[data-learning-review-return-work]').click();
    await page.locator('[data-study-learning-review-cleared]').waitFor({state:'visible'});
    assert.equal(await page.locator('[data-study-learning-review-queue]').count(),0);
    step('Two recorded correct answers reopened breadth; Workspace no longer displays tentative review');

    const rerun = JSON.parse(execFileSync('php',
        ['scripts/ci/learning_v613_verify_review.php','--verify'],
        {encoding:'utf8',env:process.env,timeout:20000}));
    assert.equal(rerun.run_count,2);
    assert.equal(rerun.review_queue_policy,'bank_topic_review_initial_v613');
    assert.equal(rerun.review_answer_count,2);
    assert.equal(rerun.review_correct_count,2);
    assert.equal(rerun.total_recorded_answer_count,5);
    assert.equal(rerun.review_question_ids.length,2);
    assert.equal(new Set(rerun.review_question_ids).size,2);
    assert.deepEqual(rerun.review_question_topics,['ネットワーク','ネットワーク']);
    assert.equal(rerun.task_progress_percent,0);
    assert.equal(rerun.task_evidence_count,0);
    assert.equal(rerun.task_next_action_note,final.task_next_action);
    step('Read-only DB: two focused correct source Questions, five immutable grades, unchanged Task Action/progress');

    // V6.14: compare only matching source Question IDs, not generic
    // pre/post mastery. q1 was wrong before, q2 was already right.
    const comparisonResponse = await page.goto(
        `${base}/plans/${planId}/tasks/${created.task_id}/learning/history`,
        {waitUntil:'domcontentloaded'});
    assert.equal(comparisonResponse.status(),200);
    const comparisonSection = page.locator('[data-learning-review-comparisons]');
    await comparisonSection.waitFor({state:'visible'});
    const comparisonRuns = comparisonSection.locator('[data-learning-review-comparison]');
    assert.equal(await comparisonRuns.count(),1);
    assert.equal(await comparisonRuns.first().getAttribute('data-learning-review-topic-state'),
        'two_recent_correct');
    const comparisons = comparisonRuns.first().locator('[data-learning-review-comparison-answer]');
    assert.equal(await comparisons.count(),2);
    await comparisons.nth(0).getByText('前回：不正解').waitFor();
    await comparisons.nth(0).getByText('今回：正解').waitFor();
    await comparisons.nth(1).getByText('前回：正解').waitFor();
    await comparisons.nth(1).getByText('今回：正解').waitFor();
    await comparisonSection.locator('[data-learning-review-topic-rechecked]').waitFor();
    assert.equal(await page.locator('[data-learning-next-action-preview]').count(),0);
    step('History visibly distinguishes incorrect→correct from already-correct→correct; bounded review hint cleared');

    process.stdout.write('LEARNING V6.14 CHROMIUM ACCEPTANCE PASS (topic practice, actor-scoped source-ID before/after comparison, no invented Evidence)\n');
} finally {
    await context.close();
    await browser.close();
}

/**
 * Learning V6.10: actual Chromium inputs/clicks for Study Plan, first Action
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
        await page.locator('form[data-first-run-start]')
            .getByRole('button',{name:'Canoviaと始める'}).click();
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
    await page.locator('form[action$="/logout"]').first()
        .getByRole('button',{name:'ログアウト'}).click();
    await page.goto(`${base}/login`);
    const login=page.locator('form[action$="/login"]');
    await login.locator('input[name="email"]').fill(email);
    await login.locator('input[name="password"]').fill(password);
    await login.getByRole('button',{name:'ログイン',exact:true}).click();
    await page.waitForURL(url=>url.pathname!=='/login');
    const resumed = await page.goto(`${base}${runPath}`);
    assert.equal(resumed.status(),200);
    await page.locator('[data-learning-question="3"]').waitFor();
    step('Logout/login restored question index and actor-bound Learning Run');

    const final = verify('--verify');
    assert.equal(final.plan_id,planId);
    assert.equal(final.task_id,created.task_id);
    assert.equal(final.task_count,1);
    assert.equal(final.learning_answer_count,2);
    assert.equal(final.correct_count,1);
    assert.equal(final.run_status,'active');
    assert.equal(final.run_current_ordinal,3);
    assert.equal(final.progress_percent,0);
    assert.equal(final.task_evidence_count,0);
    assert.equal(final.task_next_action,created.task_next_action);
    step('Read-only DB: 1 Task, 2 recorded grades, Action=third question, no false Task progress');
    process.stdout.write('LEARNING V6.10 CHROMIUM ACCEPTANCE PASS (synthetic Question Bank; no Study Task seed)\n');
} finally {
    await context.close();
    await browser.close();
}

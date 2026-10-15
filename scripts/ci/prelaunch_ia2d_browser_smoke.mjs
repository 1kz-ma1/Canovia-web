/**
 * IA-2d: disposable real-browser first-use contract.
 *
 * Three fresh isolated guest Chromium contexts prove Welcome browse,
 * diagnosis -> human-confirmed Study Seed -> first Study Action,
 * and conversational Goal Discovery -> user-confirmed Plan.
 * NO fake HTTP routes, no Plan/Task DB seeding, no remote AI.
 */
import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const base = process.env.CANOVIA_IA2_BROWSER_BASE_URL;
if (base !== 'http://127.0.0.1:8796'
  || process.env.CANOVIA_IA2_BROWSER_FIXTURE !== '1'
  || process.env.APP_ENV !== 'testing'
  || process.env.DB_CONNECTION !== 'sqlite'
  || process.env.NATIVE_AI_DRIVER !== 'disabled'
  || process.env.CANOVIA_PUBLIC_RELEASE_LEVEL !== '1') {
  throw Error('IA-2d strictly isolated local test boundary not satisfied');
}
const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
const log = name => process.stdout.write('PASS IA-2d ' + name + '\n');
const journey = async (name, run, mobile = false) => {
  const context = await browser.newContext({
    locale: 'ja-JP',
    viewport: mobile ? {width: 390, height: 844} : {width: 1280, height: 850},
    serviceWorkers: 'block',
  });
  const page = await context.newPage();
  page.setDefaultTimeout(20000);
  try {
    const first = await page.goto(base + '/', {waitUntil: 'domcontentloaded'});
    assert.equal(first.status(), 200);
    assert.equal(new URL(page.url()).pathname, '/welcome', name + ' must enter Welcome');
    await page.locator('[data-first-run-choice="browse"]').waitFor();
    await run(page);
  } finally {
    await context.close();
  }
};
try {
  await journey('browse', async page => {
    await page.locator('[data-first-run-choice="browse"]').click();
    await page.waitForURL(url => url.pathname === '/workspace/overview');
    await page.locator('[data-overview-planless-start]').waitFor();
    assert.equal(await page.locator('[data-overview-start-diagnosis]').count(), 1);
    assert.equal(await page.locator('[data-overview-primary-action]').count(), 0);
    log('browse -> real zero-Plan Overview without creating a Plan');

    await page.locator('[data-overview-start-diagnosis]').click();
    await page.waitForURL(url => url.pathname === '/personalization');
    await page.locator('[data-personalization-bootstrap]').waitFor();
    await page.locator('form[action$="/personalization/skip"]')
      .getByRole('button', {name: '今はスキップしてCanoviaを使う'}).click();
    await page.waitForURL(url => url.pathname === '/workspace/overview');
    await page.locator('[data-overview-planless-start]').waitFor();
    log('browse -> optional diagnosis -> skip -> same zero-Plan Overview');

    await page.locator('[data-overview-start-conversation]').click();
    await page.waitForURL(url => url.pathname === '/plans/create');
    await page.getByText('FIRST COMPANION', {exact: true}).first().waitFor();
    log('browse -> optional conversation entry without a mandatory Plan');
  }, true);

  await journey('diagnosis', async page => {
    await page.locator('[data-first-run-choice="diagnosis"]').click();
    await page.waitForURL(url => url.pathname === '/personalization');
    const form = page.locator('form[data-personalization-form]');
    await form.locator('label:has([data-personalization-domain="study"])').click();
    await form.locator('input[name="study_goal"]').fill('IA2d 学習Planの初回Action');
    await form.locator('select[name="study_kind"]').selectOption('qualification');
    await form.locator('select[name="study_stage"]').selectOption('started');
    await form.getByRole('button', {name:'おすすめを作る'}).click();
    await page.waitForURL(url => url.pathname === '/personalization/result');
    await page.locator('[data-plan-seed="study_qualification"]').waitFor();
    log('diagnosis -> real submitted Study answers -> displayed editable Plan seed');

    await page.locator('[data-plan-seed="study_qualification"]')
      .getByRole('button', {name: 'この型からPlanを作る'}).click();
    await page.waitForURL(url => url.pathname === '/plans/create/manual');
    const editor = page.locator('form[data-onboarding-target="plan-form"]');
    assert.equal(await editor.locator('input[name="title"]').inputValue(),
      'IA2d 学習Planの初回Action');
    await editor.locator('button[type="submit"]').click();
    await page.waitForURL(url => url.pathname === '/workspace/study');
    const planId = Number(new URL(page.url()).searchParams.get('plan_id'));
    assert.ok(Number.isSafeInteger(planId) && planId > 0);
    await page.locator('[data-study-workspace]').waitFor();
    log('human accepted Study seed and created actual first Plan via browser');

    // A new Study Plan can recommend a GET to confirm learning scope
    // instead of a POST that creates/executes a Task. Follow the live
    // first Action the UI actually recommends, not a hard-coded route.
    const firstAction = page.locator(
      '[data-study-workspace-action], [data-study-workspace-missing-context], '
      + '[data-study-workspace-recommendation]',
    ).first();
    await firstAction.waitFor({state:'visible'});
    const firstActionLink = firstAction.locator('a[href]').first();
    const firstActionForm = firstAction.locator('form').first();
    const originalPath = new URL(page.url()).pathname;
    if (await firstActionLink.count()) {
      await firstActionLink.click();
    } else if (await firstActionForm.count()) {
      await firstActionForm.locator('button[type="submit"]').click();
    } else {
      throw Error('First Study Action was rendered without any permitted clickable action');
    }
    await page.waitForURL(url => url.pathname !== originalPath, {timeout: 25000});
    const destination = await page.goto(page.url(), {waitUntil:'domcontentloaded'});
    assert.equal(destination.status(), 200, 'First Study Action destination must render');
    assert.notEqual(new URL(page.url()).pathname, originalPath);
    log('Study Workspace first recommended Action followed to actual destination');
  });

  await journey('conversation', async page => {
    await page.locator('[data-first-run-choice="conversation"]').click();
    await page.waitForURL(url => url.pathname === '/plans/create');
    const start = page.locator('form[action$="/goal-discovery"]');
    await start.locator('textarea[name="desired_state"]')
      .fill('IA2d アプリの体験を改善し、小さな実装を試す');
    await start.getByRole('button', {name: 'Canoviaと始める'}).click();
    await page.waitForURL(url => /^\/goal-discovery\/\d+$/.test(url.pathname));
    await page.locator('[data-goal-discovery-conversation]').waitFor();
    log('conversation -> user-authored desired state -> actual Goal Context conversation');

    for (let i = 0; i < 6 && !(await page.locator('form[action$="/plans"]').count()); i++) {
      const question = page.locator('[data-goal-discovery-question]');
      await question.waitFor({state: 'visible'});
      const text = question.locator('form:has(input[name="answer_mode"][value="text"])')
        .filter({visible:true}).first();
      const quick = question.locator('form:has(input[name="answer_mode"][value="quick"])').first();
      if (await quick.count()) {
        await quick.locator('button[type="submit"]').click();
      } else if (await text.count()) {
        await text.locator('textarea[name="answer_text"]')
          .fill('まず一つ小さい改善を実装し、利用後に振り返る');
        await text.locator('button[type="submit"]').click();
      } else {
        throw Error('Goal Discovery displayed no accessible answer form');
      }
      await page.locator('[data-goal-discovery-conversation]').waitFor();
    }
    const adopt = page.locator('form[action$="/plans"]');
    await adopt.waitFor({state:'visible'});
    log('conversational follow-up answered; explicit human Plan confirmation offered');
    await adopt.getByRole('button', {name: /仮Plan/}).click();
    await page.waitForURL(url => url.pathname.startsWith('/plans/')
      || url.pathname.startsWith('/workspace/'));
    assert.notEqual(new URL(page.url()).pathname, '/plans/create');
    await page.goto(base + '/workspace/overview', {waitUntil:'domcontentloaded'});
    assert.equal(await page.locator('[data-overview-planless-start]').count(), 0);
    log('confirmed conversational Plan -> Overview is now a real nonempty Plan state');
  });
  process.stdout.write('IA-2d DISPOSABLE CHROMIUM FIRST-USE ACCEPTANCE PASS\n');
} finally {
  await browser.close();
}

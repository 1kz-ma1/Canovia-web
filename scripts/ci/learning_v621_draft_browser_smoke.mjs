/**
 * Learning V6.21: real-browser external JSON practice, first-draft persistence
 * and owner-confirmed assessment. A browser creates its own Plan/Task/Session;
 * no Plan/Task/Answer/Attempt DB seeding, no remote AI, no production access.
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const base = process.env.CANOVIA_LEARNING_V621_BASE_URL;
if (base !== 'http://127.0.0.1:8799'
    || process.env.CANOVIA_LEARNING_V621_FIXTURE !== '1'
    || process.env.APP_ENV !== 'testing'
    || process.env.DB_CONNECTION !== 'sqlite'
    || process.env.NATIVE_AI_DRIVER !== 'disabled'
    || process.env.CI !== 'true') {
    throw Error('V6.21 DISPOSABLE BROWSER BOUNDARY REQUIRED');
}

const email = 'v621-draft@example.invalid';
const password = 'V621-only-Disposable!password';
const browser = await chromium.launch({headless:true,args:['--no-sandbox']});
const context = await browser.newContext({
    locale: 'ja-JP', viewport:{width:390,height:844}, serviceWorkers:'block',
});
const page = await context.newPage();
page.setDefaultTimeout(20000);
const log = value => process.stdout.write('V621_DISPOSABLE_PASS ' + value + '\n');
const verify = () => JSON.parse(execFileSync('php', [
    'scripts/ci/learning_v621_draft_readonly.php',
],{env:process.env,encoding:'utf8',timeout:25000}));

try {
    const first = await page.goto(base+'/plans/create/manual',{waitUntil:'domcontentloaded'});
    assert.equal(first.status(),200);
    if (new URL(page.url()).pathname === '/welcome') {
        // Use actual First-Run browse UI, never a backdoor or seeded Plan.
        const browse = page.locator('[data-first-run-choice="browse"]');
        if (await browse.count()) await browse.click();
        else await page.locator('form[data-first-run-start] button[type="submit"]').first().click();
        await page.goto(base+'/plans/create/manual',{waitUntil:'domcontentloaded'});
    }
    const plan = page.locator('form[data-onboarding-target="plan-form"]');
    await plan.locator('input[name="title"]').fill('V621 合成学習初回保存');
    await plan.locator('details:has(select[name="category"]) > summary').click();
    await plan.locator('select[name="category"]').selectOption('資格学習');
    await plan.locator('button[type="submit"]').click();
    await page.waitForURL(url=>url.pathname.startsWith('/workspace/'));
    const planId=Number(new URL(page.url()).searchParams.get('plan_id'));
    assert.ok(Number.isSafeInteger(planId)&&planId>0);
    log('guest-created-study-plan');

    await page.goto(base+'/register');
    const register=page.locator('form[action$="/register"]');
    await register.locator('input[name="name"]').fill('V621 Synthetic Learner');
    await register.locator('input[name="email"]').fill(email);
    await register.locator('input[name="password"]').fill(password);
    await register.locator('input[name="password_confirmation"]').fill(password);
    await register.getByRole('button',{name:'登録してそのまま続ける'}).click();
    await page.waitForURL(url=>url.pathname!=='/register');
    await page.goto(base+'/workspace/study?plan_id='+planId);
    const start=page.locator('form[action$="/plans/'+planId+'/study-action/execute"]').first();
    await start.waitFor({state:'visible'});
    await start.locator('button[type="submit"]').click();
    await page.waitForURL(url=>/\/study-practice$/.test(url.pathname));
    const path=new URL(page.url()).pathname;
    const match=path.match(/\/plans\/(\d+)\/tasks\/(\d+)\/study-practice$/);
    assert.ok(match);
    assert.equal(Number(match[1]),planId);
    log('owner-created-task-and-opened-classic-study-practice');

    const questionPayload={
        flow:'study_practice',target_plan:{id:planId},target_task:{id:Number(match[2])},
        title:'V621 合成の2問セット',
        questions:[
            {id:'q1',type:'single_choice',prompt:'合成Q1: テスト専用の選択問題',
              choices:[{id:'A',label:'選択肢A'},{id:'B',label:'選択肢B'}]},
            {id:'q2',type:'text',prompt:'合成Q2: テスト専用の記述問題'},
        ],
    };
    const manual=page.locator('#questionJsonManualInput');
    await manual.locator('summary').click();
    await manual.locator('textarea[name="questions_json"]').fill(JSON.stringify(questionPayload));
    await manual.getByRole('button',{name:'このJSONを読み込む'}).click();
    await page.locator('form[data-study-practice-draft-form]').waitFor({state:'visible'});
    assert.equal(await page.locator('[data-study-practice-question]').count(),2);
    let state=verify();
    assert.equal(state.session_count,1);
    assert.equal(state.attempt_count,0);
    assert.equal(state.stage,'ready');
    log('external-json-import-without-provider-or-score');

    const firstQuestion=page.locator('[data-study-practice-question]').first();
    await firstQuestion.locator('input[name="answers[q1][answer]"][value="A"]').check();
    const savedState=page.locator('[data-study-practice-draft-status][data-save-state="saved"]');
    await savedState.waitFor({state:'visible'});
    assert.match(await savedState.innerText(),/途中回答をサーバーへ保存/);
    state=verify();
    assert.equal(state.has_q1_saved,true);
    assert.equal(state.has_q2_saved,false);
    assert.equal(state.attempt_count,0);
    assert.equal(state.stage,'in_progress');
    log('first-answer-autosaved-in-server-session-not-graded');

    // Prove DB restore rather than trusting origin localStorage.
    await page.evaluate(()=>localStorage.clear());
    await page.reload({waitUntil:'domcontentloaded'});
    const restored=page.locator('input[name="answers[q1][answer]"][value="A"]');
    await restored.waitFor({state:'attached'});
    assert.equal(await restored.isChecked(),true);
    assert.match(await page.locator('[data-study-practice-draft-status]').innerText(),/途中回答/);
    log('hard-reload-restores-first-answer-with-localStorage-cleared');

    await page.locator('[data-study-practice-next]').click();
    const second=page.locator('[data-study-practice-question]').nth(1);
    await second.locator('textarea[name="answers[q2][answer]"]').fill('合成の短い回答');
    await page.locator('[data-study-practice-draft-status][data-save-state="saved"]').waitFor();
    await page.locator('[data-study-practice-final-submit]').click();
    await page.locator('#practice-evaluation').waitFor({state:'visible'});
    assert.ok((await page.locator('#studyPracticeEvaluationPrompt').inputValue()).length>40);
    state=verify();
    assert.equal(state.stage,'answered');
    assert.equal(state.has_q1_saved,true);
    assert.equal(state.has_q2_saved,true);
    assert.equal(state.attempt_count,0);
    log('both-answers-submitted-for-evaluation-no-fake-scored-history');

    const assessment={
        flow:'study_assessment',target_plan:{id:planId},target_task:{id:Number(match[2])},
        score_percent:50,recommended_task_progress_percent:20,
        strengths:['合成の確認'],weaknesses:['合成の再確認'],
        evidence_summary:'合成データの学習評価',next_action:'合成の復習に進む',
    };
    const assessmentManual=page.locator('#assessmentJsonManualInput');
    await assessmentManual.locator('summary').click();
    await assessmentManual.locator('textarea[name="assessment_json"]').fill(JSON.stringify(assessment));
    await assessmentManual.getByRole('button',{name:'この評価JSONを確認する'}).click();
    await page.locator('[data-study-focus-result]').waitFor({state:'visible'});
    state=verify();
    assert.equal(state.stage,'assessed');
    assert.equal(state.attempt_count,1);
    assert.equal(state.task_progress,0,'assessment alone must not change Task progress');
    log('explicit-synthetic-assessment-creates-one-history-only-without-task-progress');
    process.stdout.write('LEARNING V6.21 DISPOSABLE CHROMIUM DRAFT-TO-HISTORY PASS\n');
} finally {
    await context.close();
    await browser.close();
}

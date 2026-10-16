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
const browser = await chromium.launch({headless:true,args:['--no-sandbox'],
    executablePath: process.env.CANOVIA_BROWSER_EXECUTABLE_PATH || undefined});
const context = await browser.newContext({
    locale: 'ja-JP', viewport:{width:390,height:844}, serviceWorkers:'block',
});
await context.route(/^https?:\/\//, route => new URL(route.request().url()).origin === base
    ? route.continue() : route.abort());
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
    // The first Task recommendation may require missing-context setup,
    // rather than offering a Task creation form. It is a separate acceptance
    // journey. Isolate the user-reported *legacy draft* UI on a synthetic
    // Task created only in the strictly pinned disposable SQLite fixture.
    const seeded=JSON.parse(execFileSync('php', [
        'scripts/ci/learning_v621_synthetic_task.php','--seed-task',
    ],{env:process.env,encoding:'utf8',timeout:25000}));
    assert.equal(seeded.plan_id,planId);
    assert.ok(Number.isSafeInteger(seeded.task_id)&&seeded.task_id>0);
    const path='/plans/'+planId+'/tasks/'+seeded.task_id+'/study-practice';
    const study=await page.goto(base+path,{waitUntil:'domcontentloaded'});
    assert.equal(study.status(),200);
    await page.locator('[data-study-practice-root]').waitFor({state:'visible'});
    log('disposable-task-fixture-used-first-task-flow-not-proven');

    const questionPayload={
        flow:'study_practice',target_plan:{id:planId},target_task:{id:seeded.task_id},
        title:'V621 合成の2問セット',
        questions:[
            {id:'q1',type:'single_choice',prompt:'合成Q1: テスト専用の選択問題',
              choices:[{id:'A',label:'選択肢A'},{id:'B',label:'選択肢B'}]},
            {id:'q2',type:'text',prompt:'合成Q2: テスト専用の記述問題'},
        ],
    };
    const manual=page.locator('#questionJsonManualInput');
    await manual.locator('summary').click();
    // Synthetic reproduction of Q3 digital-signature + unrelated salt answers.
    const choice = (id, topic) => ({id, type:'single_choice', label:'回答', required:true,
        choices:['A','B','C','D'].map(id=>({id,label:topic+' '+id}))});
    const mixed = {...questionPayload, questions:[{id:'q3',
        prompt:'公開鍵暗号方式を用いた電子署名について、最も適切な説明はどれか。',
        response_fields:[choice('answer','電子署名'), choice('salt','ソルト')]}]};
    await manual.locator('textarea[name="questions_json"]').fill(JSON.stringify(mixed));
    await manual.getByRole('button',{name:'このJSONを読み込む'}).click();
    await page.getByText('修正依頼を作りました',{exact:true}).waitFor();
    assert.match(await page.locator('#studyPracticeQuestionRepairPrompt').inputValue(), /複数の独立した回答欄/);
    assert.match(await manual.locator('textarea').inputValue(), /ソルト/);
    assert.equal(verify().session_count,0);
    assert.equal(verify().attempt_count,0);
    log('ap-mixed-independent-answers-rejected-with-repair-and-no-session');
    // The normal exercise includes a legitimate optional diagnostic field.
    questionPayload.questions[0].response_fields = [choice('answer','選択肢'),
        {id:'reasoning',type:'textarea',label:'判断理由',required:false}];
    // A malformed external reply must remain repairable through real HTTP.
    await manual.locator('textarea[name="questions_json"]').fill('{"flow":');
    await manual.getByRole('button',{name:'このJSONを読み込む'}).click();
    await page.getByText('修正依頼を作りました',{exact:true}).waitFor();
    assert.equal(verify().attempt_count,0);
    log('invalid-external-json-shows-repair-without-assessment');
    if (!await manual.locator('textarea[name="questions_json"]').isVisible()) {
        await manual.locator('summary').click();
    }
    await manual.locator('textarea[name="questions_json"]').fill(JSON.stringify(questionPayload));
    await manual.getByRole('button',{name:'このJSONを読み込む'}).click();
    await page.locator('form[data-study-practice-draft-form]').waitFor({state:'visible'});
    assert.equal(await page.locator('[data-study-practice-question]').count(),2);
    let state=verify();
    assert.equal(state.session_count,1);
    assert.equal(state.attempt_count,0);
    assert.equal(state.stage,'ready');
    assert.equal(await page.locator('input[name="answers[q1][answer]"]').count(),4);
    assert.equal(await page.locator('textarea[name="answers[q1][reasoning]"]').count(),1);
    log('ap-single-answer-with-optional-reasoning-preserved');
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

    const note=firstQuestion.locator('[data-study-practice-optional-note]');
    await note.locator('summary').click();
    await note.locator('textarea').fill('記録済みの判断理由：合成の根拠');
    await savedState.waitFor({state:'visible'});

    // Prove DB restore rather than trusting origin localStorage.
    await page.evaluate(()=>localStorage.clear());
    await page.reload({waitUntil:'domcontentloaded'});
    const restored=page.locator('input[name="answers[q1][answer]"][value="A"]');
    await restored.waitFor({state:'attached'});
    assert.equal(await restored.isChecked(),true);
    assert.match(await page.locator('[data-study-practice-draft-status]').innerText(),/途中回答/);
    log('hard-reload-restores-first-answer-with-localStorage-cleared');

    const second=page.locator('[data-study-practice-question]').nth(1);
    // After Q1 is restored, the pager chooses the first unanswered Q2.
    // "Next" is intentionally hidden on the final page; never force a
    // click on a hidden button or misdiagnose legitimate resume behavior.
    if (!(await second.isVisible())) {
        const next=page.locator('[data-study-practice-next]');
        await next.waitFor({state:'visible'});
        await next.click();
    }
    await second.waitFor({state:'visible'});
    const q2=second.locator('textarea[name="answers[q2][answer]"]');

    // Simulate authentication/CSRF expiry: HTTP 419 must NEVER show saved.
    await page.route('**/study-practice/draft', route =>
        route.fulfill({status:419,contentType:'text/html',body:'synthetic expired session'}));
    await q2.fill('合成の一時回答');
    await page.locator('[data-study-practice-draft-status][data-save-state="failed"]')
        .waitFor({state:'visible'});
    assert.equal(verify().attempt_count,0);
    assert.equal(verify().has_q2_saved,false);
    log('expired-draft-write-does-not-claim-server-save');

    await page.unroute('**/study-practice/draft');
    await q2.fill('合成の短い回答');
    await page.locator('[data-study-practice-draft-status][data-save-state="saved"]')
        .waitFor({state:'visible'});
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
        flow:'study_assessment',target_plan:{id:planId},target_task:{id:seeded.task_id},
        score_percent:50,recommended_task_progress_percent:20,
        strengths:['合成の確認'],weaknesses:['合成の再確認'],
        question_feedback:[{question_id:'q2',correctness:'incorrect',feedback:'合成Q2の振り返り',misconceptions:['合成の誤解候補']},
            {question_id:'q1',correctness:'correct',feedback:'合成Q1の評価記録'}],
        evidence_summary:'合成データの学習評価',next_action:'合成の復習に進む',
    };
    const assessmentManual=page.locator('#assessmentJsonManualInput');
    await assessmentManual.locator('summary').click();
    await assessmentManual.locator('textarea[name="assessment_json"]').fill('{"flow":');
    await assessmentManual.getByRole('button',{name:'この評価JSONを確認する'}).click();
    await page.getByText('評価JSONの修正依頼を作りました',{exact:true}).waitFor();
    assert.equal(verify().attempt_count,0);
    log('invalid-assessment-json-shows-repair-without-history');
    if (!await assessmentManual.locator('textarea[name="assessment_json"]').isVisible()) {
        await assessmentManual.locator('summary').click();
    }
    await assessmentManual.locator('textarea[name="assessment_json"]').fill(JSON.stringify(assessment));
    await assessmentManual.getByRole('button',{name:'この評価JSONを確認する'}).click();
    await page.locator('[data-study-focus-result]').waitFor({state:'visible'});
    state=verify();
    assert.equal(state.stage,'assessed');
    assert.equal(state.attempt_count,1);
    assert.equal(state.task_progress,0,'assessment alone must not change Task progress');
    log('explicit-synthetic-assessment-creates-one-history-only-without-task-progress');
    const review=page.locator('[data-attempt-review]');
    await review.waitFor({state:'visible'});
    assert.equal(await review.locator('[data-review-question]').first().getAttribute('data-review-question'),'q2');
    assert.equal(await review.evaluate(el=>el.closest('details:not([open])')===null),true);
    const q1Review=review.locator('[data-review-question="q1"]');
    assert.equal(await q1Review.locator('[data-review-choices] li').count(),4);
    assert.match(await q1Review.innerText(),/A：選択肢 A/);
    assert.match(await q1Review.innerText(),/記録済みの判断理由：合成の根拠/);
    assert.match(await review.innerText(),/合成の誤解候補/);
    assert.equal(await review.locator('[data-review-reference]').count(),0);
    const sourceHash=verify().attempt_record_hash;
    await page.locator('[data-study-focus-continue] button').click();
    await page.locator('[data-attempt-history-link]').click();
    await page.getByRole('link',{name:'問題と回答を復習する',exact:true}).click();
    const reviewUrl=page.url();
    await page.reload();
    await page.locator('[data-attempt-review-workspace]').waitFor({state:'visible'});
    assert.equal(verify().attempt_record_hash,sourceHash);
    assert.equal(verify().task_progress,0);
    if (process.env.CANOVIA_STUDY_REVIEW_SCREENSHOT) await page.screenshot({path:process.env.CANOVIA_STUDY_REVIEW_SCREENSHOT,fullPage:true});
    log('open-wrong-first-review-and-durable-history-without-mutation');
    const nojs=await browser.newContext({javaScriptEnabled:false,storageState:await context.storageState(),serviceWorkers:'block'});
    await nojs.route(/^https?:\/\//,route=>new URL(route.request().url()).origin===base?route.continue():route.abort());
    const plain=await nojs.newPage(); await plain.goto(reviewUrl);
    assert.equal(await plain.locator('[data-review-question]').count(),2);
    assert.equal(await plain.locator('[data-attempt-review]').evaluate(el=>el.closest('details:not([open])')===null),true);
    await nojs.close();
    const outsider=await browser.newContext({serviceWorkers:'block'});
    await outsider.route(/^https?:\/\//,route=>new URL(route.request().url()).origin===base?route.continue():route.abort());
    const other=await outsider.newPage(); await other.goto(base+'/register');
    const otherForm=other.locator('form[action$="/register"]');
    await otherForm.locator('[name="name"]').fill('Other Synthetic Learner');
    await otherForm.locator('[name="email"]').fill('other-review@example.invalid');
    await otherForm.locator('[name="password"]').fill(password);
    await otherForm.locator('[name="password_confirmation"]').fill(password);
    await otherForm.getByRole('button',{name:'登録してそのまま続ける'}).click();
    await other.waitForURL(url=>url.pathname!=='/register');
    const denied=await outsider.request.get(reviewUrl,{headers:{Accept:'application/json'}});
    assert.ok([403,404].includes(denied.status()));
    assert.equal((await denied.text()).includes('合成Q2の振り返り'),false);
    await page.getByRole('link',{name:'同じ問題への再回答を始める',exact:true}).click();
    const retryForm=page.locator('[data-study-retry-form] form');
    const retryAnswer=retryForm.locator('textarea[name="answers[q2][answer]"]');
    assert.equal(await retryAnswer.inputValue(),'');
    assert.equal(await page.locator('[data-retry-question]').count(),1);
    const retryAction=await retryForm.getAttribute('action');
    const retryId=await retryForm.locator('[name="retry_request_id"]').inputValue();
    const retryCsrf=await retryForm.locator('[name="_token"]').inputValue();
    await retryAnswer.fill('合成の独立再回答');
    await retryForm.getByRole('button',{name:'独立した再回答記録を保存する'}).click();
    await page.locator('[data-study-retry-result]').waitFor({state:'visible'});
    const retryUrl=page.url();
    assert.match(await page.locator('[data-study-retry-result]').innerText(),/正誤保留：1問/);
    assert.match(await page.locator('[data-study-retry-result]').innerText(),/元の第2問/);
    assert.equal(verify().attempt_record_hash,sourceHash);
    assert.equal(verify().retry_result_count,1);
    assert.equal(verify().task_progress,0);
    const replayBody={_token:retryCsrf,retry_request_id:retryId,'answers[q2][answer]':'合成の独立再回答'};
    const replay=await context.request.post(retryAction,{form:replayBody});
    assert.equal(replay.status(),200);
    assert.equal(verify().retry_result_count,1);
    const conflict=await context.request.post(retryAction,{form:{...replayBody,'answers[q2][answer]':'変更した回答'},headers:{Accept:'application/json'}});
    assert.equal(conflict.status(),409);
    await page.reload();
    await page.getByRole('link',{name:'元の復習・再回答履歴へ',exact:true}).click();
    await page.getByRole('link',{name:/の再回答記録を開く/}).first().waitFor();
    const bankUrl=await page.getByRole('link',{name:'Question Bankの復習候補を確認',exact:true}).getAttribute('href');
    assert.ok(bankUrl.includes('review=1'));
    const blockedRetry=await outsider.request.get(retryUrl,{headers:{Accept:'application/json'}});
    assert.ok([403,404].includes(blockedRetry.status()));
    await other.goto(base+'/feedback');
    const blockedAppend=await outsider.request.post(retryAction,{form:{...replayBody,_token:await other.locator('meta[name="csrf-token"]').getAttribute('content')},headers:{Accept:'application/json'}});
    assert.ok([403,404].includes(blockedAppend.status()));
    await outsider.close();
    const retryNojs=await browser.newContext({javaScriptEnabled:false,storageState:await context.storageState(),serviceWorkers:'block'});
    await retryNojs.route(/^https?:\/\//,route=>new URL(route.request().url()).origin===base?route.continue():route.abort());
    const plainRetry=await retryNojs.newPage();
    await plainRetry.goto(retryAction);
    await plainRetry.locator('textarea[name="answers[q2][answer]"]').fill('no-JSの独立再回答');
    await plainRetry.getByRole('button',{name:'独立した再回答記録を保存する'}).click();
    await plainRetry.locator('[data-study-retry-result]').waitFor();
    assert.equal(verify().retry_result_count,2);
    await retryNojs.close();
    await page.goto(bankUrl);
    assert.equal(verify().learning_run_count,0);
    assert.equal(verify().attempt_record_hash,sourceHash);
    assert.equal(verify().task_progress,0);
    log('independent-ungraded-retries-replay-conflict-no-js-and-outsider-denial');
    assert.equal(verify().attempt_record_hash,sourceHash);
    log('no-js-review-and-other-registered-user-denied');
    process.stdout.write('LEARNING V6.21 DISPOSABLE CHROMIUM DRAFT-TO-HISTORY PASS\n');
} finally {
    await context.close();
    await browser.close();
}

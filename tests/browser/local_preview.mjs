import {chromium} from 'playwright';
import assert from 'node:assert/strict';
const browser=await chromium.launch({headless:true,...(process.env.CANOVIA_BROWSER_EXECUTABLE_PATH?{executablePath:process.env.CANOVIA_BROWSER_EXECUTABLE_PATH}:{})});
const context=await browser.newContext();const page=await context.newPage();
try {
 await page.goto('http://127.0.0.1:18799/');await page.getByText('端末内の保存領域を利用できます。',{exact:true}).waitFor();
 const result=await page.evaluate(async()=>{
  const {Repository}=await import('/src/repository.mjs'); const {record}=await import('/src/model.mjs');
  const a=await Repository.open(),b=await Repository.open(); const old=await a.read();
  const p=record(old,{title:'保存確認',description:'',domain:'development',start_date:'2026-10-23',deadline:null,priority:1});
  await a.change(old.revision,s=>s.plans.push(p));let conflict=false,abort=false;
  try{await b.change(old.revision,s=>s.plans=[])}catch(e){conflict=e.message==='CONFLICT'}
  let fresh=await a.read();try{await a.change(fresh.revision,s=>s.tasks.push(record(s,{plan_id:'bad'})))}catch{abort=true}
  const saved=await b.read();const method=IDBObjectStore.prototype.put;
  IDBObjectStore.prototype.put=function(){throw new DOMException('synthetic','QuotaExceededError')};let quota=false;
  try{await a.change(saved.revision,s=>s.plans=[])}catch{quota=true}finally{IDBObjectStore.prototype.put=method}
  const after=await a.read();a.close();b.close();return {conflict,abort,quota,kept:after.plans.length===1&&after.revision===saved.revision};
 });assert.deepEqual(result,{conflict:true,abort:true,quota:true,kept:true});
 await page.reload();await page.getByText('端末内の保存領域を利用できます。',{exact:true}).waitFor();
 console.log('LOCAL_PREVIEW_BROWSER_FOUNDATION=PASS');
 const form=page.locator('#diagnosis');await form.getByLabel('できるようになりたいこと').fill('基礎を確かめる');await form.getByLabel('いま分かっていること').fill('最初から');await form.getByRole('button',{name:'最初のPlanを作る'}).click();
 await page.getByRole('heading',{name:'基礎を確かめる',exact:true}).waitFor();
 await page.locator('#task-create').locator('..').locator('summary').click();
 await page.locator('#task-create').getByLabel('Task名',{exact:true}).fill('<img src=x onerror=alert(1)>');await page.locator('#task-create').getByRole('button').click();
 await page.getByRole('heading',{name:'<img src=x onerror=alert(1)>',exact:true}).waitFor();assert.equal(await page.locator('img').count(),0);
 await page.reload();await page.getByRole('heading',{name:'基礎を確かめる',exact:true}).waitFor();
 const other=await context.newPage();await other.goto('http://127.0.0.1:18799/');await other.getByRole('heading',{name:'基礎を確かめる',exact:true}).waitFor();
 await page.locator('#task-create').locator('..').locator('summary').click();await page.locator('#task-create').getByLabel('Task名',{exact:true}).fill('新しいTask');await page.locator('#task-create').getByRole('button').click();await page.getByRole('heading',{name:'新しいTask',exact:true}).waitFor();
 const edit=other.locator('[data-edit-task]').first();await edit.locator('..').locator('summary').click();await edit.getByLabel('Task名',{exact:true}).fill('古いタブの編集');await edit.getByRole('button',{name:'確認した進捗を保存'}).click();await other.getByText('別のタブで更新されました。入力を控えてから「最新状態を表示」を押してください。',{exact:true}).waitFor();await other.close();
 console.log('LOCAL_PREVIEW_BROWSER_PLANNING=PASS');
 await page.getByRole('button',{name:'演習を始める',exact:true}).first().click();await page.locator('#answer').waitFor();
 await page.locator('#answer input[value="a"]').check();await page.getByText('回答を保存済み',{exact:true}).waitFor();await page.reload();await page.locator('#answer input[value="a"]').waitFor();assert.equal(await page.locator('#answer input[value="a"]').isChecked(),true);
 await page.getByRole('button',{name:'回答を確定して採点',exact:true}).click();await page.locator('#next-practice').waitFor();await page.getByRole('heading',{name:'学習履歴',exact:true}).waitFor();
 await page.locator('#next-practice').click();await page.locator('#answer input[value="a"]').check();await page.getByText('回答を保存済み',{exact:true}).waitFor();await page.getByRole('button',{name:'回答を確定して採点',exact:true}).click();await page.getByText('観測された弱点候補',{exact:true}).waitFor();
 const study=await page.evaluate(async()=>{const {Repository}=await import('/src/repository.mjs');const r=await Repository.open(),s=await r.read();r.close();return {attempts:s.practice_attempts.length,progress:s.tasks.filter(t=>s.plans.find(p=>p.id===t.plan_id).domain==='study').map(t=>t.progress_percent)}});assert.equal(study.attempts,2);assert.ok(study.progress.every(x=>x===0));await page.reload();await page.getByText('観測された弱点候補',{exact:true}).waitFor();
 console.log('LOCAL_PREVIEW_BROWSER_STUDY=PASS');
 const dev=await browser.newContext(),dp=await dev.newPage();await dp.goto('http://127.0.0.1:18799/');await dp.locator('#diagnosis').waitFor();const df=dp.locator('#diagnosis');await df.getByLabel('取り組む領域').selectOption('development');await df.getByLabel('できるようになりたいこと').fill('小さく作る');await df.getByRole('button').click();await dp.getByRole('heading',{name:'小さく作る',exact:true}).waitFor();
 await dp.getByRole('button',{name:'作業を始める',exact:true}).first().click();await dp.locator('#work-toggle').waitFor();await dp.waitForTimeout(1200);await dp.locator('#work-toggle').click();await dp.getByRole('button',{name:'作業を再開',exact:true}).waitFor();await dp.getByRole('button',{name:'作業を再開',exact:true}).click();await dp.waitForTimeout(700);await dp.locator('#work-finish').click();await dp.getByRole('heading',{name:'実績のTimeline',exact:true}).waitFor();await dp.locator('#active-work').waitFor({state:'detached'});
 let actual=await dp.evaluate(async()=>{const {Repository}=await import('/src/repository.mjs');const r=await Repository.open(),s=await r.read();r.close();return {logs:s.work_logs.length,seconds:s.work_logs[0].actual_seconds,progress:s.tasks[0].progress_percent}});assert.equal(actual.logs,1);assert.ok(actual.seconds>0);assert.equal(actual.progress,0);
 const ef=dp.locator('[data-edit-task]').first();await ef.locator('..').locator('summary').click();await ef.getByLabel('自分が確認した進捗（%）').fill('40');await ef.getByLabel('残作業（分）').fill('15');await ef.getByRole('button',{name:'確認した進捗を保存'}).click();await dp.getByText('進捗 40% ／ 残り 15分',{exact:true}).waitFor();await dp.reload();await dp.getByText('進捗 40% ／ 残り 15分',{exact:true}).waitFor();
 await dp.getByRole('button',{name:'作業を始める',exact:true}).first().click();await dp.locator('#work-toggle').waitFor();await dp.reload();await dp.getByRole('button',{name:'作業を再開',exact:true}).waitFor();await dp.locator('#work-finish').click();await dp.locator('#active-work').waitFor({state:'detached'});await dev.close();
 console.log('LOCAL_PREVIEW_BROWSER_EXECUTION=PASS');
 // Build a mixed-domain snapshot using actual UI, then restore into an isolated context.
 await page.getByRole('button',{name:'Development',exact:true}).click();await page.locator('#task-create').locator('..').locator('summary').click();await page.locator('#task-create').getByLabel('Task名',{exact:true}).fill('復元する作業');await page.locator('#task-create').getByRole('button').click();await page.getByRole('button',{name:'作業を始める',exact:true}).first().click();await page.waitForTimeout(600);await page.locator('#work-finish').click();await page.locator('#active-work').waitFor({state:'detached'});
 const download=page.waitForEvent('download');await page.locator('#export').click();const file=await download;const stream=await file.createReadStream();const chunks=[];for await(const chunk of stream)chunks.push(chunk);const bytes=Buffer.concat(chunks);
 const before=await page.evaluate(async()=>{const {Repository}=await import('/src/repository.mjs');const r=await Repository.open(),s=await r.read();r.close();return s});
 const restored=await browser.newContext(),rp=await restored.newPage();await rp.goto('http://127.0.0.1:18799/');await rp.locator('#backup').waitFor();await rp.locator('#import-file').setInputFiles({name:'backup.json',mimeType:'application/json',buffer:bytes});await rp.locator('#restore-confirm').click();await rp.getByText('復元しました。内容を確認し、改めてバックアップしてください。',{exact:true}).waitFor();
 const after=await rp.evaluate(async()=>{const {Repository}=await import('/src/repository.mjs');const r=await Repository.open(),s=await r.read();r.close();return s});for(const table of ['profiles','plans','tasks','work_sessions','work_logs','practice_sessions','practice_attempts'])assert.deepEqual(after[table],before[table]);assert.notEqual(after.installation_id,before.installation_id);
 await rp.locator('#import-file').setInputFiles({name:'backup.json',mimeType:'application/json',buffer:bytes});await rp.getByText('同じデータは復元済みです。二重取込は行いません。',{exact:true}).waitFor();await rp.locator('#import-file').setInputFiles({name:'bad.json',mimeType:'application/json',buffer:Buffer.from('invalid')});await rp.getByText('復元できません。形式・破損・既存データとの衝突を確認してください。',{exact:true}).waitFor();
 await rp.reload();await rp.getByRole('heading',{name:'基礎を確かめる',exact:true}).waitFor();await restored.close();
 console.log('LOCAL_PREVIEW_BROWSER_RESTORE_ISOLATED=PASS');
} finally {await context.close();await browser.close()}

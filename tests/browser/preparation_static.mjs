import {boundaries} from './preparation_static_boundaries.mjs';
import {chromium,webkit} from 'playwright';
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import net from 'node:net';
import {readFile,mkdtemp,rm,mkdir} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
const directory=process.env.PREPARATION_DIRECTORY;
const meta=JSON.parse(await readFile(directory+'/manifest.json','utf8'));
const probe=net.createServer();await new Promise(r=>probe.listen(0,'127.0.0.1',r));const port=probe.address().port;await new Promise(r=>probe.close(r));
const origin=`http://127.0.0.1:${port}`,server=spawn('python3',['-B','scripts/ci/preparation_static_serve.py','--directory',directory,'--port',String(port)],{stdio:'ignore'});
let browser;const traffic=[],errors=[];
const stored=meta.profile==='B';
const shot=async(page,name)=>{if(process.env.PREPARATION_SCREENSHOTS){const dir=join(process.env.PREPARATION_SCREENSHOTS,(process.env.BROWSER_ENGINE||'chromium')+'-'+meta.profile);await mkdir(dir,{recursive:true});await page.screenshot({path:join(dir,name+'.png'),fullPage:true});}};
async function watch(c){
  c.on('request',r=>traffic.push({url:r.url(),method:r.method()}));
  c.on('page',p=>{p.on('pageerror',e=>errors.push(e.message));p.on('dialog',d=>d.accept());});
  await c.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());return c;
}
try{
  let ready=false;for(let i=0;i<80;i++){assert.equal(server.exitCode,null);try{if((await fetch(origin)).ok){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,100));}assert.ok(ready);
  const engine=process.env.BROWSER_ENGINE==='webkit'?webkit:chromium;
  const launch={headless:true,...(process.env.CANOVIA_BROWSER_EXECUTABLE_PATH?{executablePath:process.env.CANOVIA_BROWSER_EXECUTABLE_PATH}:{})};
  browser=await engine.launch(launch);
  const context=await watch(await browser.newContext({viewport:{width:390,height:844}}));
  const page=await context.newPage();
  await page.goto(origin);await page.getByRole('heading',{name:'自分の関心から、次の準備へ。'}).waitFor();
  await page.getByRole('link',{name:'関心を見つける',exact:true}).first().click();
  await page.locator('input[name=Q01][value=unknown]').focus();await page.keyboard.press('Space');assert.ok(await page.locator('input[name=Q01][value=unknown]').isChecked());await shot(page,'01-diagnosis-390');
  const vectors=JSON.parse(await readFile(process.env.PREPARATION_VECTOR_PATH,'utf8'));
  const actual=await page.evaluate(async vectors=>{const {score}=await import('/src/scoring.mjs');return vectors.map(v=>score(v.answers));},vectors);assert.deepEqual(actual,vectors.map(v=>v.expected));
  await page.locator('input[name=Q01][value=unknown]').check();await page.locator('input[name=consent]').check();
  await page.getByRole('button',{name:stored?'途中保存する':'途中の内容を反映する',exact:true}).click();
  await page.getByText(stored?'この端末に保存しました。':'この画面に反映しました。再読み込みすると消えます。',{exact:true}).waitFor();
  if(stored){await page.reload();await page.locator('#diagnosis-form').waitFor();assert.ok(await page.locator('input[name=Q01][value=unknown]').isChecked());}
  await page.getByRole('button',{name:'今の回答で結果を見る',exact:true}).click();
  await page.getByText('回答 0問 ／ わからない 1問 ／ 未回答 29問',{exact:true}).waitFor();
  await page.getByRole('link',{name:'回答を見直す・再計算する',exact:true}).click();
  for(let step=1;step<=6;step++){
    await page.locator(`[data-page="${step}"]`).waitFor({state:'visible'});
    const inputs=await page.locator(`[data-page="${step}"] input[value="3"]`).all();
    assert.equal(inputs.length,5,'VISIBLE_PAGE_HAS_FIVE_QUESTIONS');
    for(const input of inputs)await input.check();
    if(step<6)await page.getByRole('button',{name:'次の5問',exact:true}).click();
  }
  assert.equal(await page.locator('input[type=radio]:checked').count(),30,'ALL_ANSWERS_BEFORE_RESULT');
  await page.getByRole('button',{name:'今の回答で結果を見る',exact:true}).click();
  await page.getByText('回答 30問 ／ わからない 0問 ／ 未回答 0問',{exact:true}).waitFor();
  assert.equal(await page.locator('meter').count(),8);await shot(page,'02-graph-390');
  assert.equal(await page.locator('meter').evaluateAll(m=>m.reduce((n,v)=>n+Number(v.value),0)),100);
  await page.locator('input[name=confirmed][value=ST]').check();
  await page.getByRole('button',{name:stored?'関心・希望を保存する':'関心・希望を反映する',exact:true}).click();
  assert.equal(await page.locator('input[name=confirmed][value=ST]').isChecked(),true);
  await page.getByRole('link',{name:'Canovia Future',exact:true}).click();await page.getByRole('heading',{name:'Canovia Future',exact:true}).waitFor();
  assert.equal(await page.locator('article').count(),3);await shot(page,'03-future-390');
  await page.getByRole('link',{name:'Plan',exact:true}).click();await page.locator('#plan-form').waitFor();
  await page.getByLabel('目標',{exact:true}).fill('Synthetic preparation plan');
  await page.getByLabel('現在地・計画のメモ',{exact:true}).fill('<img src="/leak" onerror="window.CANARY=1">');
  await page.getByLabel('分野',{exact:true}).fill('学習・探究');await page.getByLabel('目標日（任意）',{exact:true}).fill('2026-12-01');
  await page.getByRole('button',{name:stored?'Planを保存する':'Planを反映する',exact:true}).click();
  await page.getByRole('button',{name:'Synthetic preparation plan',exact:true}).waitFor();
  const planId=await page.evaluate(async()=>{const {repo}=await import('/src/app.mjs');return (await repo.read()).plans[0].id;});
  if(stored){await page.reload();await page.getByRole('button',{name:'Synthetic preparation plan',exact:true}).waitFor();}
  await page.getByRole('button',{name:'Synthetic preparation plan',exact:true}).click();
  await page.getByLabel('目標',{exact:true}).fill('Synthetic preparation plan updated');
  await page.getByRole('button',{name:stored?'変更を保存する':'変更を反映する',exact:true}).click();
  await page.getByRole('button',{name:'Synthetic preparation plan updated',exact:true}).waitFor();await shot(page,'04-plan-390');
  assert.equal(await page.locator('img[src="/leak"]').count(),0);assert.equal(await page.evaluate(()=>window.CANARY),undefined);
  assert.equal(await page.evaluate(async()=>{const {repo}=await import('/src/app.mjs');return (await repo.read()).plans[0].id;}),planId);
  if(stored){
    const before=await page.evaluate(async()=>{const {repo}=await import('/src/app.mjs');return JSON.stringify(await repo.read());});
    await page.getByLabel('目標',{exact:true}).fill('Unsaved quota input');
    await page.evaluate(()=>{window.originalPut=IDBObjectStore.prototype.put;IDBObjectStore.prototype.put=function(){throw new DOMException('SYNTHETIC','QuotaExceededError');};});
    await page.getByRole('button',{name:'変更を保存する',exact:true}).click();await page.getByText('保存領域が足りません。入力を控えてください。データは削除していません。',{exact:true}).waitFor();
    assert.equal(await page.getByLabel('目標',{exact:true}).inputValue(),'Unsaved quota input');
    await page.evaluate(()=>{IDBObjectStore.prototype.put=window.originalPut;delete window.originalPut;});
    assert.equal(await page.evaluate(async()=>{const {repo}=await import('/src/app.mjs');return JSON.stringify(await repo.read());}),before);
    await page.reload();await page.locator('#plan-form').waitFor();await page.getByRole('button',{name:'Synthetic preparation plan updated',exact:true}).click();
    const stale=await context.newPage();await stale.goto(origin+'/#plans');await stale.getByRole('button',{name:'Synthetic preparation plan updated',exact:true}).click();await stale.getByLabel('目標',{exact:true}).fill('Stale unsaved input');
    await page.getByRole('button',{name:'変更を保存する',exact:true}).click();await page.getByText('この端末に保存しました。',{exact:true}).waitFor();
    await stale.getByRole('button',{name:'変更を保存する',exact:true}).click();await stale.getByText('別のタブで変更されました。入力を控えてから再読み込みしてください。',{exact:true}).waitFor();
    assert.equal(await stale.getByLabel('目標',{exact:true}).inputValue(),'Stale unsaved input');await stale.close();
  }
  await page.getByRole('link',{name:'データを守る',exact:true}).click();await page.locator('#export').waitFor();
  await shot(page,'05-data-390');assert.ok(await page.getByText(/Exportがなければ、消去後に復旧できません/).isVisible());
  const download=page.waitForEvent('download');await page.locator('#export').click();const bytes=await readFile(await (await download).path());
  const imported=await watch(await browser.newContext()),ip=await imported.newPage();await ip.goto(origin+'/#data');await ip.locator('#import-file').waitFor();
  await ip.locator('#import-file').setInputFiles({name:'synthetic.json',mimeType:'application/json',buffer:bytes});await ip.locator('#import-confirm').waitFor();
  assert.equal(await ip.evaluate(async()=>{const {repo}=await import('/src/app.mjs');return (await repo.read()).plans.length;}),0,'IMPORT_REQUIRES_CONFIRMATION');
  await ip.locator('#import-confirm').click();await ip.locator('#import-file:enabled').waitFor();
  await ip.getByText(stored?'Importしました。この端末で内容を確認し、改めてExportしてください。':'この画面にImportしました。再読み込みすると消えます。',{exact:true}).waitFor();
  assert.equal(await ip.evaluate(async()=>{const {repo}=await import('/src/app.mjs');return (await repo.read()).plans[0].id;}),planId);
  for(const [name,buffer] of [['duplicate.json',bytes],['corrupt.json',Buffer.from(bytes.toString().replace('Synthetic preparation plan updated','Modified plan'))],['huge.json',Buffer.alloc(2*1024*1024+1)]]){
    await ip.locator('#import-file').setInputFiles({name,mimeType:'application/json',buffer});
    await ip.locator('#import-preview').getByText(name==='duplicate.json'?'同じデータはImport済みです。二重取込は行いません。':'Importできません。形式・版・サイズ・破損・既存記録との衝突を確認してください。',{exact:true}).waitFor();
    assert.equal(await ip.evaluate(async()=>{const {repo}=await import('/src/app.mjs');return (await repo.read()).plans.length;}),1);
  }
  if(stored){
    const atomic=await watch(await browser.newContext()),ap=await atomic.newPage();await ap.goto(origin+'/#data');await ap.locator('#import-file').waitFor();
    const before=await ap.evaluate(async()=>{const {repo}=await import('/src/app.mjs');return JSON.stringify(await repo.read());});
    for(const mode of ['quota','abort']){
      await ap.locator('#import-file').setInputFiles({name:mode+'.json',mimeType:'application/json',buffer:bytes});await ap.locator('#import-confirm').waitFor();
      await ap.evaluate(mode=>{window.originalPut=IDBObjectStore.prototype.put;IDBObjectStore.prototype.put=function(...args){if(mode==='quota')throw new DOMException('SYNTHETIC','QuotaExceededError');const q=window.originalPut.apply(this,args);this.transaction.abort();return q;};},mode);
      await ap.locator('#import-confirm').click();await ap.getByText('Importできませんでした。衝突や別タブの変更を確認し、ファイルを再選択してください。既存データは保持されています。',{exact:true}).waitFor();
      await ap.evaluate(()=>{IDBObjectStore.prototype.put=window.originalPut;delete window.originalPut;});
      assert.equal(await ap.evaluate(async()=>{const {repo}=await import('/src/app.mjs');return JSON.stringify(await repo.read());}),before);
    }
    await ap.locator('#import-file').setInputFiles({name:'stale.json',mimeType:'application/json',buffer:bytes});await ap.locator('#import-confirm').waitFor();
    await ap.evaluate(async()=>{const {DB_NAME}=await import('/src/repository.mjs'),{Repository}=await import('/src/repository.mjs'),{savePlan}=await import('/src/model.mjs');const other=await Repository.open(),s=await other.read();await other.change(s.revision,n=>savePlan(n,{title:'Other tab',description:'',category:'',deadline:''},crypto.randomUUID()));other.close();});
    await ap.locator('#import-confirm').click();await ap.getByText('Importできませんでした。衝突や別タブの変更を確認し、ファイルを再選択してください。既存データは保持されています。',{exact:true}).waitFor();
    assert.equal(await ap.evaluate(async()=>{const {repo}=await import('/src/app.mjs');return (await repo.read()).plans.length;}),1);await atomic.close();
    await ip.evaluate(async()=>{const {repo}=await import('/src/app.mjs');repo.close();await new Promise((resolve,reject)=>{const q=indexedDB.deleteDatabase('canovia-preparation-v1');q.onsuccess=resolve;q.onerror=reject;q.onblocked=()=>reject(Error('OPEN_CONNECTION'));});});
    await ip.reload();await ip.locator('#import-file').waitFor();assert.equal(await ip.evaluate(async()=>{const {repo}=await import('/src/app.mjs');return (await repo.read()).plans.length;}),0);
    const profile=await mkdtemp(join(tmpdir(),'canovia-preparation-restart-'));let pc;
    try{
      pc=await watch(await engine.launchPersistentContext(profile,launch));let pp=await pc.newPage();await pp.goto(origin+'/#data');await pp.locator('#import-file').waitFor();
      await pp.locator('#import-file').setInputFiles({name:'restart.json',mimeType:'application/json',buffer:bytes});await pp.locator('#import-confirm').waitFor();await pp.locator('#import-confirm').click();await pp.getByText('Importしました。この端末で内容を確認し、改めてExportしてください。',{exact:true}).waitFor();
      await pc.close();pc=null;pc=await watch(await engine.launchPersistentContext(profile,launch));pp=await pc.newPage();await pp.goto(origin+'/#plans');await pp.getByRole('button',{name:'Synthetic preparation plan updated',exact:true}).waitFor();
      assert.equal(await pp.evaluate(async()=>{const {repo}=await import('/src/app.mjs');return (await repo.read()).plans[0].id;}),planId);
    }finally{if(pc)await pc.close();await rm(profile,{recursive:true,force:true});}
  }else{
    await page.reload();await page.locator('#export').waitFor();assert.equal(await page.evaluate(async()=>{const {repo}=await import('/src/app.mjs');return (await repo.read()).plans.length;}),0);
  }
  await imported.close();
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  await page.keyboard.press('Tab');assert.ok(await page.evaluate(()=>document.activeElement!==document.body));
  await boundaries({browser,origin,stored});
  assert.deepEqual(errors,[]);assert.ok(traffic.every(r=>new URL(r.url).origin===origin&&!new URL(r.url).search&&r.method==='GET'));
  console.log(JSON.stringify({engine:process.env.BROWSER_ENGINE||'chromium',profile:meta.profile,diagnosis_graph_review_future:true,plans_export_import:true,quota_abort_conflict:stored,process_restart:stored,storage_clear:stored,mobile_keyboard:true,asset_get_only:true,http_csp_origin_distribution:true,scoring_parity:133,device_verified:false,release_approved:false}));
}catch(error){
  if(browser){for(const c of browser.contexts())for(const p of c.pages())console.log('STATIC_FAILURE_STATE',await p.evaluate(async()=>{const app=await import('/src/app.mjs');return {hash:location.hash,message:document.querySelector('#message')?.textContent,counts:document.querySelector('#result-counts')?.textContent,checked:document.querySelectorAll('input[type=radio]:checked').length,answers:Object.keys(app.state?.diagnosis?.answers||{}).length};}).catch(()=>({unavailable:true})));}
  console.log('STATIC_PAGE_ERRORS',errors);throw error;
}finally{
  if(browser)await browser.close();server.kill('SIGTERM');await new Promise(r=>server.exitCode!==null||server.signalCode!==null?r():server.once('exit',r));
}

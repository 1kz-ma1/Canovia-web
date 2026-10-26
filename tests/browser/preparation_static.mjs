import {chromium,webkit} from 'playwright';
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import net from 'node:net';
import {readFile} from 'node:fs/promises';
const directory=process.env.PREPARATION_DIRECTORY;
const meta=JSON.parse(await readFile(directory+'/manifest.json','utf8'));
const probe=net.createServer();await new Promise(r=>probe.listen(0,'127.0.0.1',r));const port=probe.address().port;await new Promise(r=>probe.close(r));
const origin=`http://127.0.0.1:${port}`,server=spawn('python3',['-B','scripts/ci/preparation_static_serve.py','--directory',directory,'--port',String(port)],{stdio:'ignore'});
let browser;const traffic=[],errors=[];
try{
  let ready=false;for(let i=0;i<80;i++){assert.equal(server.exitCode,null);try{if((await fetch(origin)).ok){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,100));}assert.ok(ready);
  const engine=process.env.BROWSER_ENGINE==='webkit'?webkit:chromium;
  browser=await engine.launch({headless:true,...(process.env.CANOVIA_BROWSER_EXECUTABLE_PATH?{executablePath:process.env.CANOVIA_BROWSER_EXECUTABLE_PATH}:{})});
  const context=await browser.newContext({viewport:{width:390,height:844}});
  context.on('request',r=>traffic.push({url:r.url(),method:r.method()}));
  await context.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
  await page.goto(origin);await page.getByRole('heading',{name:'自分の関心から、次の準備へ。'}).waitFor();
  await page.getByRole('link',{name:'関心を見つける',exact:true}).first().click();
  await page.locator('input[name=Q01][value=unknown]').check();await page.locator('input[name=consent]').check();
  await page.getByRole('button',{name:'途中の内容を反映する',exact:true}).click();
  await page.getByText('この画面に反映しました。再読み込みすると消えます。',{exact:true}).waitFor();
  await page.getByRole('button',{name:'今の回答で結果を見る',exact:true}).click();
  await page.getByText('回答 0問 ／ わからない 1問 ／ 未回答 29問',{exact:true}).waitFor();
  await page.getByRole('link',{name:'回答を見直す・再計算する',exact:true}).click();
  for(let step=1;step<=6;step++){
    for(const input of await page.locator(`[data-page="${step}"] input[value="3"]`).all())await input.check();
    if(step<6)await page.getByRole('button',{name:'次の5問',exact:true}).click();
  }
  assert.equal(await page.locator('input[type=radio]:checked').count(),30,'ALL_ANSWERS_BEFORE_RESULT');
  await page.getByRole('button',{name:'今の回答で結果を見る',exact:true}).click();
  await page.getByText('回答 30問 ／ わからない 0問 ／ 未回答 0問',{exact:true}).waitFor();
  assert.equal(await page.locator('meter').count(),8);
  assert.equal(await page.locator('meter').evaluateAll(m=>m.reduce((n,v)=>n+Number(v.value),0)),100);
  await page.locator('input[name=confirmed][value=ST]').check();
  await page.getByRole('button',{name:'関心・希望を反映する',exact:true}).click();
  assert.equal(await page.locator('input[name=confirmed][value=ST]').isChecked(),true);
  await page.getByRole('link',{name:'Canovia Future',exact:true}).click();await page.getByRole('heading',{name:'Canovia Future',exact:true}).waitFor();
  assert.equal(await page.locator('article').count(),3);
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  await page.keyboard.press('Tab');assert.ok(await page.evaluate(()=>document.activeElement!==document.body));
  assert.deepEqual(errors,[]);assert.ok(traffic.every(r=>new URL(r.url).origin===origin&&!new URL(r.url).search&&r.method==='GET'));
  console.log(JSON.stringify({engine:process.env.BROWSER_ENGINE||'chromium',profile:meta.profile,diagnosis_graph_review_future:true,mobile_keyboard:true,asset_get_only:true,device_verified:false,release_approved:false}));
}catch(error){
  if(browser){for(const c of browser.contexts())for(const p of c.pages())console.log('STATIC_FAILURE_STATE',await p.evaluate(async()=>{const app=await import('/src/app.mjs');return {hash:location.hash,message:document.querySelector('#message')?.textContent,counts:document.querySelector('#result-counts')?.textContent,checked:document.querySelectorAll('input[type=radio]:checked').length,answers:Object.keys(app.state?.diagnosis?.answers||{}).length};}).catch(()=>({unavailable:true})));}
  console.log('STATIC_PAGE_ERRORS',errors);throw error;
}finally{
  if(browser)await browser.close();server.kill('SIGTERM');await new Promise(r=>server.exitCode!==null||server.signalCode!==null?r():server.once('exit',r));
}

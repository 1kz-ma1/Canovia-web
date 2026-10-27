// Entirely intercepted HTTPS response model. No request goes to Render or DNS.
import {chromium,webkit} from 'playwright';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
const review=process.env.PREPARATION_RELEASE_REVIEW;
const receipt=JSON.parse(await readFile(review+'/review.json','utf8'));
assert.equal(receipt.deployment_authorized,false);assert.equal(receipt.deployed_artifact,'UNVERIFIED');
const origin=receipt.origin,files={};
for(const name of Object.keys(receipt.files))files['/'+name]=await readFile(review+'/.render-static-B/'+name);
assert.equal(Object.keys(files).length,25);files['/']=files['/index.html'];
const headers=Object.fromEntries(files['/_headers'].toString().trim().split('\n').slice(1).map(v=>{const i=v.indexOf(':');return [v.slice(0,i).trim().toLowerCase(),v.slice(i+1).trim()];}));
const stop='この配信先・配信処理は未採用です。記録を書き換えず終了しました。';
const engine=process.env.BROWSER_ENGINE==='webkit'?webkit:chromium;
const browser=await engine.launch({headless:true});let rejectedTransport=0;
async function model({securityHeaders=true,parentCookie=false,init}={}){
  const context=await browser.newContext({acceptDownloads:true});
  if(parentCookie)await context.addCookies([{name:'SYNTHETIC_PARENT',value:'opaque',domain:'.rpf-canovia.com',path:'/',secure:true,httpOnly:true,sameSite:'Lax'}]);
  const observed={parentCookieSent:false,unexpected:0};
  await context.route('**/*',async route=>{
    const request=route.request(),url=new URL(request.url());
    // Deliberately model a permissive CDN, INCLUDING a wrong rewrite of unknown
    // paths to index. This is NOT a prediction of Render's actual response.
    if(url.pathname.startsWith('/forbidden')){observed.unexpected++;rejectedTransport++;return route.abort();}
    const requestHeaders=await request.allHeaders();
    if((requestHeaders.cookie||'').includes('SYNTHETIC_PARENT='))observed.parentCookieSent=true;
    // Also model a nested legacy alias whose relative assets still resolve.
    const raw=files[url.pathname]||files[url.pathname.replace(/^\/legacy\//,'/')]||files['/index.html'];
    const type=url.pathname.endsWith('.mjs')?'text/javascript':url.pathname.endsWith('.css')?'text/css':url.pathname.endsWith('.webp')?'image/webp':url.pathname.endsWith('.png')?'image/png':url.pathname.endsWith('.json')?'application/json':'text/html';
    return route.fulfill({status:200,body:raw,headers:{...(securityHeaders?headers:{}),'content-type':type,'access-control-allow-origin':'*'}});
  });
  await context.addInitScript(()=>{window.idbCalls=0;const open=IDBFactory.prototype.open;IDBFactory.prototype.open=function(...args){window.idbCalls++;return open.apply(this,args);};});
  if(init)await context.addInitScript(init);
  return {context,page:await context.newPage(),observed};
}
try{
  // Origin-bound B preserves diagnosis -> Future -> edit Plan -> JSON handover.
  const good=await model(),{page,context}=good;
  await page.goto(origin);await page.getByRole('heading',{name:'自分の関心から、次の準備へ。'}).waitFor();
  assert.equal(await page.evaluate(()=>window.idbCalls),1);
  await page.getByRole('link',{name:'関心を見つける',exact:true}).first().click();
  for(let step=1;step<=6;step++){
    for(const input of await page.locator(`[data-page="${step}"] input[value="3"]`).all())await input.check();
    if(step<6)await page.getByRole('button',{name:'次の5問',exact:true}).click();
  }
  await page.locator('input[name=consent]').check();await page.getByRole('button',{name:'今の回答で結果を見る',exact:true}).click();
  await page.getByText('回答 30問 ／ わからない 0問 ／ 未回答 0問',{exact:true}).waitFor();assert.equal(await page.locator('meter').count(),8);
  await page.getByRole('link',{name:'Canovia Future',exact:true}).click();assert.equal(await page.locator('article').count(),3);
  await page.getByRole('link',{name:'Plan',exact:true}).click();await page.getByLabel('目標',{exact:true}).fill('Synthetic bound Plan');
  await page.getByRole('button',{name:'Planを保存する',exact:true}).click();
  await page.getByRole('button',{name:'Synthetic bound Plan',exact:true}).click();await page.getByLabel('目標',{exact:true}).fill('Synthetic bound Plan edited');
  await page.getByRole('button',{name:'変更を保存する',exact:true}).click();await page.getByRole('button',{name:'Synthetic bound Plan edited',exact:true}).waitFor();
  await page.reload();await page.getByRole('button',{name:'Synthetic bound Plan edited',exact:true}).waitFor();
  await page.getByRole('link',{name:'データを守る',exact:true}).click();
  const downloading=page.waitForEvent('download');await page.locator('#export').click();const backup=await readFile(await (await downloading).path());
  const imported=await model();await imported.page.goto(origin+'/#data');
  await imported.page.locator('#import-file').setInputFiles({name:'synthetic.json',mimeType:'application/json',buffer:backup});
  await imported.page.locator('#import-confirm').click();await imported.page.getByText('Importしました。この端末で内容を確認し、改めてExportしてください。',{exact:true}).waitFor();
  assert.equal(await imported.page.evaluate(async()=>{const {repo}=await import('/src/app.mjs');return (await repo.read()).plans[0].title;}),'Synthetic bound Plan edited');
  assert.equal((await context.cookies()).length,0,'app does not create a cookie');
  assert.equal((await imported.context.cookies()).length,0);
  await imported.context.close();
  // Actual browser CSP enforcement: no forbidden request reaches our interceptor.
  await page.evaluate(async()=>{await fetch('/forbidden-fetch').catch(()=>{});await fetch('https://blocked.invalid/forbidden-external').catch(()=>{});});
  assert.equal(good.observed.unexpected,0);await context.close();
  for(const target of [origin+'/?SYNTHETIC_QUERY',origin+'/?',origin+'/old-web',origin+'/legacy/index.html',
    'https://start.canovia.com','https://app.rpf-canovia.com','https://canovia-preparation-start.onrender.com',
    'https://preview.invalid','http://127.0.0.1:18798','http://start.rpf-canovia.com']){
    const probe=await model();await probe.page.goto(target);await probe.page.getByText(stop,{exact:true}).waitFor();
    assert.equal(await probe.page.evaluate(()=>window.idbCalls),0,'bound origin/path/query rejects before IDB');
    await probe.context.close();
  }
  const sw=await model({init:()=>Object.defineProperty(navigator,'serviceWorker',{value:{controller:{synthetic:true},getRegistrations(){throw Error('MUST_NOT_TOUCH_SW');}}})});
  await sw.page.goto(origin);await sw.page.getByText(stop,{exact:true}).waitFor();assert.equal(await sw.page.evaluate(()=>window.idbCalls),0);await sw.context.close();
  for(const unavailable of [false,true]){
    const registration=await model({init:()=>{
      const failed=location.hash==='#sw-api-failed';
      Object.defineProperty(navigator,'serviceWorker',{value:{
        controller:null,
        getRegistrations(){return failed?Promise.reject(Error('SYNTHETIC_SW_API_FAILURE')):Promise.resolve([{unregister(){throw Error('MUST_NOT_UNREGISTER');}}]);}
      }});
    }});
    await registration.page.goto(origin+(unavailable?'#sw-api-failed':''));await registration.page.getByText(stop,{exact:true}).waitFor();
    assert.equal(await registration.page.evaluate(()=>window.idbCalls),0,'registered but not controlling SW also stops before IDB');await registration.context.close();
  }
  // Missing CSP demonstrably loses the transport guarantee, even though this
  // test's interceptor STILL prevents external I/O. Dashboard Headers are a gate.
  const noHeaders=await model({securityHeaders:false});await noHeaders.page.goto(origin);await noHeaders.page.locator('#app h1').waitFor();
  await noHeaders.page.evaluate(()=>fetch('/forbidden-without-csp').catch(()=>{}));assert.equal(noHeaders.observed.unexpected,1);await noHeaders.context.close();
  const parent=await model({parentCookie:true});await parent.page.goto(origin);await parent.page.locator('#app h1').waitFor();
  assert.equal(parent.observed.parentCookieSent,true,'a parent HttpOnly cookie reaches initial static request');await parent.context.close();
  assert.equal(rejectedTransport,1,'only the intentional missing-CSP case attempted transport');
  console.log(JSON.stringify({engine:process.env.BROWSER_ENGINE||'chromium',profile:'B',bound_origin_model:true,diagnosis_plan_backup:true,origin_query_path_sw_stop_before_idb:true,csp_difference_measured:true,parent_cookie_risk_measured:true,actual_http_requests:0,provider_runtime:'UNVERIFIED',device_verified:false,release_approved:false}));
}finally{await browser.close();}

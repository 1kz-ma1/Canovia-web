import {readFile} from 'node:fs/promises';
// Only the owned loopback fixture; route guard blocks any accidental external I/O.
import assert from 'node:assert/strict';
import http from 'node:http';
export async function boundaries({browser,origin,stored,directory}){
  const request=(path,method='GET',headers={})=>new Promise((resolve,reject)=>{
    const q=http.request(origin,{path,method,headers},r=>{let body='';r.on('data',b=>body+=b);r.on('end',()=>resolve({status:r.statusCode,headers:r.headers,body}));});q.on('error',reject);q.end();
  });
  const ok=await request('/','HEAD');assert.equal(ok.status,200);assert.equal(ok.body,'');
  const csp=ok.headers['content-security-policy'];
  for(const directive of ["default-src 'none'","connect-src 'none'","worker-src 'none'","form-action 'none'","frame-ancestors 'none'"])assert.ok(csp.includes(directive));
  assert.equal(ok.headers['x-content-type-options'],'nosniff');assert.equal(ok.headers['referrer-policy'],'no-referrer');assert.equal(ok.headers['set-cookie'],undefined);
  for(const [path,method,headers,status] of [
    ['/.env','GET',{},404],['/admin','GET',{},404],['/api','GET',{},404],['/sw.js','GET',{},404],['/_headers','GET',{},404],['/manifest.json','GET',{},404],
    ['/src/../index.html','GET',{},404],['/%2e%2e/.env','GET',{},404],['/%69ndex.html','GET',{},404],['/?SYNTHETIC_CANARY','GET',{},404],['/?','GET',{},404],
    ['/','POST',{},405],['/','PUT',{},405],['/','DELETE',{},405],['/','OPTIONS',{},405],['/','GET',{Host:'unowned.invalid'},421],
    ['/','GET',{Origin:'http://unowned.invalid'},403],['/','GET',{Origin:'null'},403],['/','GET',{Origin:[origin,'http://unowned.invalid']},403],['/','GET',{'Sec-Fetch-Site':'cross-site'},403],
    ['/','GET',{Cookie:'synthetic_old_cookie=opaque'},403],['/','GET',{Authorization:'SYNTHETIC'},403],['/','GET',{Cookie:''},403]
  ]){
    const r=await request(path,method,headers);assert.equal(r.status,status,`${method} ${path}`);assert.equal(r.headers['content-security-policy'],csp);assert.equal(r.body,'LOCAL_FIXTURE_REQUEST_REJECTED');
  }
  assert.equal((await request('/','GET',{Origin:origin})).status,200);
  // Test browser enforcement rather than interpreting the header string as proof.
  const context=await browser.newContext();let unexpected=0;
  await context.route('**/*',r=>{if(new URL(r.request().url()).origin!==origin||r.request().url().includes('/forbidden')){unexpected++;return r.abort();}return r.continue();});
  const page=await context.newPage();await page.goto(origin);await page.locator('#app h1').waitFor();
  const blocked=await page.evaluate(async origin=>{
    const violations=[];addEventListener('securitypolicyviolation',e=>violations.push(e.effectiveDirective));
    const fetched=await fetch(origin+'/forbidden-fetch?SYNTHETIC').then(()=>true,()=>false);
    const external=await fetch('https://blocked.invalid/forbidden').then(()=>true,()=>false);
    const xhr=await new Promise(resolve=>{const x=new XMLHttpRequest();x.open('POST',origin+'/forbidden-xhr');x.onload=()=>resolve(true);x.onerror=()=>resolve(false);x.send('SYNTHETIC');});
    const beacon=navigator.sendBeacon(origin+'/forbidden-beacon','SYNTHETIC');
    const ws=await new Promise(resolve=>{try{const w=new WebSocket(origin.replace('http:','ws:')+'/forbidden-ws');w.onopen=()=>{w.close();resolve(true);};w.onerror=()=>resolve(false);}catch{resolve(false);}});
    // Drain queued transport/events; no attempted request may reach the route guard.
    await new Promise(resolve=>setTimeout(resolve,100));
    return {fetched,external,xhr,beacon,ws,violations};
  },origin);
  // sendBeacon's boolean means queued, not that CSP permitted any transmission.
  assert.equal(typeof blocked.beacon,'boolean');
  for(const key of ['fetched','external','xhr','ws'])assert.equal(blocked[key],false,key);
  // WebKit coalesces/omits some violation events. Network denial is measured
  // independently for all transports, not inferred from an event count.
  assert.ok(blocked.violations.includes('connect-src'),'CSP enforcement event observed');assert.equal(unexpected,0,'CSP rejects before transport');
  // Dedicated DB and old preview namespace coexist without touching each other.
  if(stored){
    await page.evaluate(async()=>{await new Promise((resolve,reject)=>{const q=indexedDB.open('canovia-local-preview-v1',1);q.onupgradeneeded=()=>q.result.createObjectStore('legacy');q.onerror=reject;q.onsuccess=()=>{const db=q.result,tx=db.transaction('legacy','readwrite');tx.objectStore('legacy').put('SYNTHETIC_OLD','untouched');tx.oncomplete=()=>{db.close();resolve();};};});});
    await page.reload();await page.locator('#app h1').waitFor();
    assert.equal(await page.evaluate(()=>new Promise(resolve=>{const q=indexedDB.open('canovia-local-preview-v1');q.onsuccess=()=>{const db=q.result,r=db.transaction('legacy').objectStore('legacy').get('untouched');r.onsuccess=()=>{resolve(r.result);db.close();};};})), 'SYNTHETIC_OLD');
    // Unsupported saved schema must be preserved, never converted or erased.
    await page.evaluate(async()=>{const {repo}=await import('/src/app.mjs');repo.close();await new Promise(resolve=>{const q=indexedDB.open('canovia-preparation-v1');q.onsuccess=()=>{const db=q.result,tx=db.transaction('state','readwrite'),store=tx.objectStore('state'),r=store.get('root');r.onsuccess=()=>{r.result.schema_version=999;store.put(r.result,'root');};tx.oncomplete=()=>{db.close();resolve();};};});});
    await page.reload();await page.getByText('保存済み記録の版を確認できません。推定や削除は行いません。',{exact:true}).waitFor();assert.equal(await page.locator('#app h1').count(),0);
    assert.equal(await page.evaluate(()=>new Promise(resolve=>{const q=indexedDB.open('canovia-preparation-v1');q.onsuccess=()=>{const db=q.result,r=db.transaction('state').objectStore('state').get('root');r.onsuccess=()=>{resolve(r.result.schema_version);db.close();};};})),999);
  }
  await context.close();
  // A stale SW causes STOP; this application never unregisters someone else's SW.
  const sw=await browser.newContext();await sw.addInitScript(()=>{Object.defineProperty(navigator,'serviceWorker',{value:{controller:{synthetic:true},getRegistrations(){throw Error('MUST_NOT_UNREGISTER');}}});});
  const sp=await sw.newPage();await sp.goto(origin);await sp.getByText('この配信先・配信処理は未採用です。記録を書き換えず終了しました。',{exact:true}).waitFor();assert.equal(await sp.locator('#app h1').count(),0);await sw.close();
  const unavailable=await browser.newContext();await unavailable.addInitScript(()=>{Object.defineProperty(window,'indexedDB',{get(){throw Error('SYNTHETIC_UNAVAILABLE');}});});
  const up=await unavailable.newPage();await up.goto(origin);
  if(stored){await up.getByText('保存領域を開けませんでした。記録を削除せず、設定や対応する版を確認してください。',{exact:true}).waitFor();assert.equal(await up.locator('#app h1').count(),0);}
  else{await up.getByRole('heading',{name:'すべての一歩が、未来につながる。'}).waitFor();}
  await unavailable.close();
  await hostingDifferences({browser,origin,directory});
}

async function hostingDifferences({browser,origin,directory}){
  // A response model only: no DNS, TLS, provider, deployment or Origin adoption.
  const meta=JSON.parse(await readFile(directory+'/manifest.json','utf8')),files={};
  for(const name of Object.keys(meta.files))files['/'+name]=await readFile(directory+'/'+name);
  files['/']=files['/index.html'];
  const headers=Object.fromEntries(files['/_headers'].toString().trim().split('\n').slice(1).map(v=>{const n=v.indexOf(':');return [v.slice(0,n).trim().toLowerCase(),v.slice(n+1).trim()];}));
  const context=await browser.newContext(),allowed=new Set([origin,'https://preflight-a.invalid','https://preflight-b.invalid','https://preview-a.invalid']);let outside=0;
  await context.addInitScript(()=>{window.idbCalls=0;const open=IDBFactory.prototype.open;IDBFactory.prototype.open=function(...a){window.idbCalls++;return open.apply(this,a);};});
  await context.route('**/*',route=>{
    const u=new URL(route.request().url());if(!allowed.has(u.origin)){outside++;return route.abort();}
    const raw=files[u.pathname];
    // Static hosts can serve the index with query/Cookie/Auth, unlike the lab.
    return route.fulfill({status:raw?200:404,body:raw||files['/404.html'],headers:{...headers,'access-control-allow-origin':'*','content-type':u.pathname.endsWith('.mjs')?'text/javascript':u.pathname.endsWith('.css')?'text/css':u.pathname.endsWith('.png')?'image/png':u.pathname.endsWith('.webp')?'image/webp':'text/html'}});
  });
  const page=await context.newPage();
  await page.goto(origin+'/?SYNTHETIC_QUERY');await page.getByText('この配信先・配信処理は未採用です。記録を書き換えず終了しました。',{exact:true}).waitFor();assert.equal(await page.evaluate(()=>window.idbCalls),0,'Query STOP before storage, even when host returns 200');
  assert.equal((await page.goto('https://preflight-a.invalid/unknown')).status(),404);await page.getByRole('heading',{name:'ページが見つかりません',exact:true}).waitFor();assert.equal(await page.locator('script').count(),0);
  for(const target of ['https://preflight-a.invalid','https://preflight-b.invalid','https://preview-a.invalid']){
    await page.goto(target);await page.getByText('この配信先・配信処理は未採用です。記録を書き換えず終了しました。',{exact:true}).waitFor();assert.equal(await page.evaluate(()=>window.idbCalls),0,'Invalid test origins must never activate the app');
    const value=await page.evaluate(()=>new Promise((resolve,reject)=>{const q=indexedDB.open('preparation-hosting-origin-probe',1);q.onupgradeneeded=()=>q.result.createObjectStore('probe');q.onerror=reject;q.onsuccess=()=>{const db=q.result,tx=db.transaction('probe','readwrite'),store=tx.objectStore('probe'),r=store.get('marker');let previous=null;r.onsuccess=()=>{previous=r.result??null;store.put('SYNTHETIC_ORIGIN_ONLY','marker');};tx.oncomplete=()=>{db.close();resolve(previous);};};}));
    assert.equal(value,null,'Distinct production/preview origins must not share IndexedDB');
  }
  await page.goto('https://preflight-a.invalid');await page.getByText('この配信先・配信処理は未採用です。記録を書き換えず終了しました。',{exact:true}).waitFor();
  assert.equal(await page.evaluate(()=>new Promise(resolve=>{const q=indexedDB.open('preparation-hosting-origin-probe');q.onsuccess=()=>{const db=q.result,r=db.transaction('probe').objectStore('probe').get('marker');r.onsuccess=()=>{resolve(r.result);db.close();};};})),'SYNTHETIC_ORIGIN_ONLY');
  assert.equal(outside,0);await context.close();
}

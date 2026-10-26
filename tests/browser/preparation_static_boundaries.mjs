// Only the owned loopback fixture; route guard blocks any accidental external I/O.
import assert from 'node:assert/strict';
import http from 'node:http';
export async function boundaries({browser,origin,stored}){
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
    ['/','GET',{Origin:'http://unowned.invalid'},403],['/','GET',{Origin:'null'},403],['/','GET',{'Sec-Fetch-Site':'cross-site'},403],
    ['/','GET',{Cookie:'synthetic_old_cookie=opaque'},403],['/','GET',{Authorization:'SYNTHETIC'},403]
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
    return {fetched,external,xhr,beacon,ws,violations};
  },origin);
  for(const key of ['fetched','external','xhr','beacon','ws'])assert.equal(blocked[key],false,key);
  assert.ok(blocked.violations.includes('connect-src'));assert.equal(unexpected,0,'CSP rejects before transport');
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
  else{await up.getByRole('heading',{name:'自分の関心から、次の準備へ。'}).waitFor();}
  await unavailable.close();
}

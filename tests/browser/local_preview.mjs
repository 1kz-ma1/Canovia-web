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
} finally {await context.close();await browser.close()}

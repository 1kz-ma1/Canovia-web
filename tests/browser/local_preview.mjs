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
} finally {await context.close();await browser.close()}

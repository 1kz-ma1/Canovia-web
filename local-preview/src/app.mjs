import {Repository} from './repository.mjs';
import {localDay} from './model.mjs';
import * as planning from './planning.mjs';
const root=document.querySelector('#app'),message=document.querySelector('#message');
export const escape=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
export const field=(name,label,value='',type='text',extra='')=>`<label>${label}<input name="${name}" type="${type}" value="${escape(value)}" ${extra}></label>`;
export const taskStatus={todo:'これから',doing:'取り組み中',done:'完了',cancelled:'取りやめ'};
export let state,repo;let domain='study',selected=null,busy=false;
const errors={CONFLICT:'別のタブで更新されました。入力を控えてから「最新状態を表示」を押してください。',STORAGE_FULL:'保存容量が不足しています。保存できませんでした。',HAS_TASKS:'先にTaskを整理してください。',HAS_HISTORY:'実績があるTaskは削除できません。取りやめにすると履歴を残せます。',INVALID_DATA:'入力内容を確認してください。保存は行っていません。',VERSION_CHANGED:'別の版が開かれました。この画面を再読み込みしてください。'};
export function notice(text,error=false){message.textContent=text;message.className=error?'error':'good'}
export async function act(mutate,after=()=>{},redraw=true){
 if(busy)return false;busy=true;
 try{state=await repo.change(state.revision,mutate);after();if(redraw){render();notice('端末内に保存しました。')}return true}
 catch(e){notice(errors[e.message]||'保存できませんでした。入力を控え、状態を確認してください。',true);return false}
 finally{busy=false}
}
export function data(form){return Object.fromEntries(new FormData(form))}
export function currentPlan(){return state.plans.find(p=>p.id===selected)||state.plans.find(p=>p.domain===domain)}
export function render(){
 const p=currentPlan();if(p)selected=p.id;
 root.innerHTML=`<nav aria-label="Workspace"><button data-domain="study" aria-pressed="${domain==='study'}">Study</button><button data-domain="development" aria-pressed="${domain==='development'}">Development</button><button id="refresh" class="secondary">最新状態を表示</button></nav>
 ${!state.profiles.length?`<section><h2>まずは、今の目標を教えてください</h2><p>分かる範囲で大丈夫。端末内にPlanと最初のTaskを作ります。</p><form id="diagnosis"><label>取り組む領域<select name="domain"><option value="study">Study・学習</option><option value="development">Development・開発</option></select></label>${field('goal','できるようになりたいこと','','text','required maxlength="120"')}${field('current_state','いま分かっていること','','text','maxlength="2000"')}${field('weekly_capacity','週に使える時間（分）',150,'number','min="0" max="10080" required')}${field('deadline','目標日（任意）','','date')}<button>最初のPlanを作る</button></form></section>`:''}
 <div class="grid"><section><h2>${domain==='study'?'学習':'開発'}のPlan</h2>${state.plans.filter(x=>x.domain===domain).map(x=>`<button class="secondary" data-plan="${x.id}" aria-pressed="${x.id===selected}">${escape(x.title)}</button>`).join(' ')}
 <details><summary>Planを追加</summary><form id="plan-create">${field('title','Plan名','','text','required maxlength="120"')}${field('deadline','期限','','date')}<button>Planを追加する</button></form></details></section>
 ${p?`<section class="card"><h2>${escape(p.title)}</h2><p>${escape(p.description)}</p><p>期限：${escape(p.deadline||'未設定')}</p><details><summary>Planを編集</summary><form id="plan-edit">${field('title','Plan名',p.title,'text','required maxlength="120"')}${field('description','説明',p.description)}${field('deadline','期限',p.deadline||'','date')}<button>Planを保存</button><button type="button" class="danger" id="plan-delete">Planを削除</button></form></details></section>`:''}</div>
 ${p?`<section id="workspace"><h2>今日の一歩とRoadmap</h2><div id="insights"></div><ol>${state.tasks.filter(t=>t.plan_id===p.id).sort((a,b)=>a.sort_order-b.sort_order).map(t=>`<li class="card"><h3>${escape(t.title)}</h3><span class="badge">${taskStatus[t.status]}</span><p>進捗 ${t.progress_percent}% ／ 残り ${t.remaining_minutes}分</p><div class="task-tools" data-task="${t.id}"></div><details><summary>Taskを編集</summary><form data-edit-task="${t.id}">${field('title','Task名',t.title,'text','required maxlength="120"')}${field('estimated_minutes','見積（分）',t.estimated_minutes,'number','min="0" max="100000" required')}${field('remaining_minutes','残作業（分）',t.remaining_minutes,'number','min="0" max="100000" required')}${field('progress_percent','自分が確認した進捗（%）',t.progress_percent,'number','min="0" max="100" required')}<label>状態<select name="status">${Object.entries(taskStatus).map(([v,l])=>`<option value="${v}" ${v===t.status?'selected':''}>${l}</option>`).join('')}</select></label><button>確認した進捗を保存</button><button type="button" class="danger" data-delete-task="${t.id}">Taskを削除</button></form></details></li>`).join('')}</ol>
 <details><summary>Taskを追加</summary><form id="task-create">${field('title','Task名','','text','required maxlength="120"')}${field('estimated_minutes','見積（分）',25,'number','min="0" max="100000" required')}<button>Taskを追加する</button></form></details></section>`:''}<section id="extensions"></section>`;
 bind();for(const extend of extensions)extend(p);
}
export const extensions=[];
function bind(){
 root.querySelectorAll('[data-domain]').forEach(b=>b.onclick=()=>{domain=b.dataset.domain;selected=null;render()});
 root.querySelectorAll('[data-plan]').forEach(b=>b.onclick=()=>{selected=b.dataset.plan;render()});
 root.querySelector('#refresh').onclick=async()=>{try{state=await repo.read();render();notice('最新状態を表示しました。')}catch{notice('保存状態を読み取れません。',true)}};
 const on=(id,fn)=>{const f=root.querySelector('#'+id);if(f)f.onsubmit=e=>{e.preventDefault();fn(data(f))}};
 on('diagnosis',v=>act(s=>planning.diagnose(s,v),()=>{domain=v.domain;selected=null}));
 on('plan-create',v=>act(s=>planning.createPlan(s,{...v,domain,start_date:localDay()})));
 on('plan-edit',v=>act(s=>planning.updatePlan(s,selected,{...v,deadline:v.deadline||null})));
 on('task-create',v=>act(s=>planning.createTask(s,{...v,plan_id:selected})));
 const del=root.querySelector('#plan-delete');if(del)del.onclick=()=>{if(confirm('このPlanを削除しますか？'))act(s=>planning.deletePlan(s,selected))};
 root.querySelectorAll('[data-edit-task]').forEach(f=>f.onsubmit=e=>{e.preventDefault();const v=data(f);for(const k of ['estimated_minutes','remaining_minutes','progress_percent'])v[k]=Number(v[k]);act(s=>planning.updateTask(s,f.dataset.editTask,v))});
 root.querySelectorAll('[data-delete-task]').forEach(b=>b.onclick=()=>{if(confirm('このTaskを削除しますか？'))act(s=>planning.deleteTask(s,b.dataset.deleteTask))});
}
try{repo=await Repository.open();state=await repo.read();domain=state.profiles[0]?.domains[0]||'study';const work=await import('./execution.mjs');if(state.work_sessions.some(s=>s.status==='running'))state=await repo.change(state.revision,s=>work.recoverWork(s));const ui=await import('./execution-ui.mjs');ui.install({state:()=>state,act,extensions,escape,notice,busy:()=>busy});repo.onchange=()=>notice('別のタブで変更されました。編集を控えてから最新状態を表示してください。',true);render();notice('端末内の保存領域を利用できます。')}
catch{notice('保存領域を利用できません。データを削除せず、通常ブラウザでお試しください。',true)}

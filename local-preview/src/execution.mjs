import {record,requireValue,localDay} from './model.mjs';
const terminal=t=>['done','cancelled'].includes(t.status);
export function startWork(state,taskId,now=new Date().toISOString()) {
 const task=state.tasks.find(t=>t.id===taskId);requireValue(task&&!terminal(task),'TASK_UNAVAILABLE');
 requireValue(!state.work_sessions.some(s=>s.status!=='finished'),'SESSION_ACTIVE');
 const s=record(state,{plan_id:task.plan_id,task_id:task.id,status:'running',started_at:now,ended_at:null,paused_at:null,active_seconds:0,last_checkpoint_at:now},now);
 state.work_sessions.push(s);return s;
}
export function checkpoint(state,id,elapsed,mode='running',memo='',now=new Date().toISOString()) {
 const s=state.work_sessions.find(s=>s.id===id);requireValue(s&&s.status==='running','SESSION_NOT_RUNNING');
 requireValue(Number.isFinite(elapsed)&&elapsed>=0&&elapsed<=15&&Date.parse(now)>=Date.parse(s.last_checkpoint_at),'CLOCK_CHANGED');
 s.active_seconds+=elapsed;s.last_checkpoint_at=now;s.updated_at=now;
 if(mode==='paused'){s.status='paused';s.paused_at=now}
 else if(mode==='finished')finishWork(state,id,memo,now);
 else requireValue(mode==='running');
}
export function resumeWork(state,id,now=new Date().toISOString()) {
 const s=state.work_sessions.find(s=>s.id===id);requireValue(s&&s.status==='paused','SESSION_NOT_PAUSED');
 requireValue(Date.parse(now)>=Date.parse(s.last_checkpoint_at),'CLOCK_CHANGED');
 s.status='running';s.paused_at=null;s.last_checkpoint_at=now;s.updated_at=now;
}
export function finishWork(state,id,memo='',now=new Date().toISOString()) {
 const s=state.work_sessions.find(s=>s.id===id);requireValue(s&&s.status!=='finished','SESSION_FINISHED');
 requireValue(Date.parse(now)>=Date.parse(s.last_checkpoint_at),'CLOCK_CHANGED');
 requireValue(!state.work_logs.some(l=>l.session_id===id),'DUPLICATE_LOG');
 const t=state.tasks.find(t=>t.id===s.task_id);requireValue(t,'BROKEN_REFERENCE');
 s.status='finished';s.ended_at=now;s.updated_at=now;
 state.work_logs.push(record(state,{session_id:id,plan_id:s.plan_id,task_id:s.task_id,worked_on:localDay(new Date(now)),actual_seconds:s.active_seconds,actual_minutes:s.active_seconds/60,memo,progress_before:t.progress_percent,progress_after:t.progress_percent},now));
}
export function recoverWork(state,now=new Date().toISOString()) {
 for(const s of state.work_sessions.filter(s=>s.status==='running')) {s.status='paused';s.paused_at=s.last_checkpoint_at;s.updated_at=now;}
}
export function progress(state,planId) {
 const tasks=state.tasks.filter(t=>t.plan_id===planId&&t.status!=='cancelled');
 const weight=tasks.reduce((n,t)=>n+t.estimated_minutes,0);
 const percent=tasks.length?(weight?tasks.reduce((n,t)=>n+t.estimated_minutes*t.progress_percent,0)/weight:tasks.reduce((n,t)=>n+t.progress_percent,0)/tasks.length):0;
 return {percent,remaining:tasks.reduce((n,t)=>n+t.remaining_minutes,0),actual:state.work_logs.filter(l=>l.plan_id===planId).reduce((n,l)=>n+l.actual_minutes,0)};
}
export function recommend(state,planId,today=localDay()) {
 const p=state.plans.find(p=>p.id===planId);if(!p)return null;
 const tasks=state.tasks.filter(t=>t.plan_id===planId&&!terminal(t));
 const score=t=>t.priority*10+(t.status==='doing'?5:0)+(state.work_logs.some(l=>l.task_id===t.id)?0:2);
 tasks.sort((a,b)=>score(b)-score(a)||a.sort_order-b.sort_order||a.id.localeCompare(b.id));
 return tasks.length?{task:tasks[0],reason:p.deadline&&p.deadline<today?'期限を過ぎています。残作業を確認して計画を見直しましょう。':tasks[0].status==='doing'?'取り組み中のTaskを優先しました。':'本人の優先度と、まだ実績のないTaskを優先しました。'}:null;
}

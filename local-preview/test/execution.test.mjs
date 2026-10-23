import {test} from 'node:test';import assert from 'node:assert/strict';
import {emptyState,validate} from '../src/model.mjs';import {createPlan,createTask,updateTask} from '../src/planning.mjs';
import {startWork,checkpoint,resumeWork,recoverWork,finishWork,progress,recommend} from '../src/execution.mjs';
const fixture=()=>{const s=emptyState(),p=createPlan(s,{title:'開発',domain:'development',start_date:'2026-10-23'}),t=createTask(s,{plan_id:p.id,title:'実装',estimated_minutes:20});return {s,p,t}};
test('pause/resume finish once and time never changes task progress',()=>{
 const {s,p,t}=fixture(),w=startWork(s,t.id,'2026-10-23T01:00:00.000Z');checkpoint(s,w.id,5,'paused','','2026-10-23T01:00:05.000Z');assert.throws(()=>checkpoint(s,w.id,2));resumeWork(s,w.id,'2026-10-23T02:00:00.000Z');checkpoint(s,w.id,3,'finished','','2026-10-23T02:00:03.000Z');validate(s);assert.equal(s.work_logs[0].actual_seconds,8);assert.throws(()=>finishWork(s,w.id));assert.equal(t.progress_percent,0);assert.equal(progress(s,p.id).percent,0);updateTask(s,t.id,{progress_percent:50,remaining_minutes:10});assert.equal(progress(s,p.id).percent,50);
});
test('recover conservatively ignores unobserved interval; large/negative clock inputs rejected',()=>{
 const {s,t}=fixture(),w=startWork(s,t.id,'2026-10-23T01:00:00.000Z');assert.throws(()=>startWork(s,t.id));assert.throws(()=>checkpoint(s,w.id,100));recoverWork(s,'2026-10-23T02:00:00.000Z');validate(s);assert.equal(w.active_seconds,0);assert.equal(w.status,'paused');
});
test('weighted progress excludes cancelled tasks and recommendation responds to explicit completion',()=>{
 const {s,p,t}=fixture();const second=createTask(s,{plan_id:p.id,title:'検証',estimated_minutes:40});updateTask(s,t.id,{status:'done'});assert.equal(recommend(s,p.id).task.id,second.id);assert.equal(Math.round(progress(s,p.id).percent),33);updateTask(s,second.id,{status:'cancelled'});assert.equal(progress(s,p.id).percent,100);assert.equal(recommend(s,p.id),null);
});

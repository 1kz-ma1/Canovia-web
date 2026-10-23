import {test} from 'node:test';
import assert from 'node:assert/strict';
import {emptyState,record,validate} from '../src/model.mjs';
test('fresh UUID schema and unsupported/invalid records rejected',()=>{
 const s=emptyState();assert.equal(validate(s),s);
 for(const mutate of [x=>x.schema_version=99,x=>x.plans.push(record(x,{title:'orphan'})),x=>x.extra=true,x=>x.tasks.push(record(x,{plan_id:crypto.randomUUID()}))]) {
  const c=structuredClone(s);mutate(c);assert.throws(()=>validate(c));
 }
});
import {diagnose,createPlan,createTask,updateTask,deletePlan,deleteTask} from '../src/planning.mjs';
test('diagnosis creates linked plan/tasks, CRUD preserves history boundaries',()=>{
 const s=emptyState();diagnose(s,{domain:'study',goal:'基礎を学ぶ',current_state:'初めて',weekly_capacity:'100',deadline:''});validate(s);assert.equal(s.tasks.length,2);assert.throws(()=>diagnose(s,{}));
 updateTask(s,s.tasks[0].id,{status:'done'});assert.equal(s.tasks[0].progress_percent,100);assert.equal(s.tasks[0].remaining_minutes,0);assert.throws(()=>deletePlan(s,s.plans[0].id));
 deleteTask(s,s.tasks[0].id);deleteTask(s,s.tasks[0].id);deletePlan(s,s.plans[0].id);validate(s);
 const p=createPlan(s,{title:'開発',domain:'development',start_date:'2026-10-23'});createTask(s,{plan_id:p.id,title:'実装',estimated_minutes:20});validate(s);
});

import {date,instant} from '../src/model.mjs';
test('date-only and UTC instants reject normalized impossible dates',()=>{assert.equal(date('2026-02-30'),false);assert.equal(instant('2026-02-30T00:00:00.000Z'),false);assert.equal(date('2028-02-29'),true)});

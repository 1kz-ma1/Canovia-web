import {test} from 'node:test';
import assert from 'node:assert/strict';
import {emptyState,record,validate} from '../src/model.mjs';
test('fresh UUID schema and unsupported/invalid records rejected',()=>{
 const s=emptyState();assert.equal(validate(s),s);
 for(const mutate of [x=>x.schema_version=99,x=>x.plans.push(record(x,{title:'orphan'})),x=>x.extra=true,x=>x.tasks.push(record(x,{plan_id:crypto.randomUUID()}))]) {
  const c=structuredClone(s);mutate(c);assert.throws(()=>validate(c));
 }
});

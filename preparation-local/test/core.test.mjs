import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {bank} from '../src/data.mjs';
import {score} from '../src/scoring.mjs';
import {emptyState,validate,saveDiagnosis} from '../src/model.mjs';
import {Repository} from '../src/memory-repository.mjs';

test('bounded answers distinguish unknown, zero and absent evidence',()=>{
  assert.equal(score({}).status,'insufficient_evidence');
  assert.equal(score({Q01:'unknown'}).unknown_count,1);
  assert.equal(score({Q01:0,Q02:0}).status,'no_relative_signal');
  assert.equal(score({Q01:3}).categories.ST.share_percent,null);
  for(const v of [[],null,{Q99:3},{Q01:4},{Q01:'3'},{Q01:NaN},{Q01:1.5},JSON.parse('{"__proto__":{}}')])assert.throws(()=>score(v));
});
test('ties sum to 100 and recalculation is deterministic without mutation',()=>{
  const a=Object.fromEntries(Object.keys(bank.items).map(k=>[k,1])),before=structuredClone(a);
  const r=score(a);assert.equal(Object.values(r.categories).reduce((n,c)=>n+c.share_percent,0),100);
  assert.deepEqual(r,score(a));assert.deepEqual(a,before);assert.equal(r.categories.ST.share_percent,13);
});
test('schema stores answers, not a second conflicting score or Account identity',()=>{
  const s=emptyState();saveDiagnosis(s,{Q01:3,Q02:'unknown'},2);validate(s);
  assert.equal(s.diagnosis.step,2);assert.equal(score(s.diagnosis.answers).answered_count,1);
  assert.throws(()=>validate({...s,account_id:'fake'}));assert.throws(()=>validate({...s,schema_version:2}));
  assert.throws(()=>validate({...s,diagnosis:{...s.diagnosis,result:{score:100}}}));
});
test('C is memory only; conflicting mutations preserve previous content',async()=>{
  const r=await Repository.open(),s=await r.read();await r.change(s.revision,n=>saveDiagnosis(n,{Q01:3},1));
  await assert.rejects(r.change(s.revision,n=>n.plans=[]),/CONFLICT/);
  const saved=await r.read();await assert.rejects(r.change(saved.revision,n=>n.diagnosis.answers.Q01=7));assert.deepEqual(await r.read(),saved);
  r.close();const fresh=await Repository.open();assert.equal((await fresh.read()).diagnosis,null);fresh.close();
});
test('PHP reference parity across category thresholds, unknowns, halves and ties',()=>{
  const vectors=JSON.parse(readFileSync(process.env.PREPARATION_VECTOR_PATH,'utf8'));
  assert.equal(vectors.length,133);
  for(const v of vectors)assert.deepEqual(score(v.answers),v.expected);
});

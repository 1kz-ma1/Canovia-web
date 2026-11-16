// Synthetic library tests: not WebKit/default-store or iPhone update evidence.
import test from 'node:test';
import assert from 'node:assert/strict';
import {emptyState,validate,saveDiagnosis,savePlan} from '../src/model.mjs';
import {Repository} from '../src/memory-repository.mjs';
import {exportJSON,previewImport,restore} from '../src/backup.mjs';

const values={title:'Synthetic continuity',description:'Synthetic memo',category:'ST',deadline:'2026-12-01'};
async function example(){
  const repo=await Repository.open(),id=crypto.randomUUID(),initial=await repo.read();
  await repo.change(initial.revision,s=>{saveDiagnosis(s,{Q01:0,Q02:'unknown',Q03:3},2);savePlan(s,values,id);});
  return repo;
}
test('v1 identity/provenance survive serialization and successful edits without a new schema',async()=>{
  const repo=await example();try{
    const before=await repo.read(),raw=await exportJSON(before),roundtrip=JSON.parse(raw).payload.data;
    assert.deepEqual(roundtrip,before);assert.equal(before.schema_version,1);assert.equal(before.revision,1);
    assert.equal(before.diagnosis.source_installation_id,before.installation_id);
    assert.equal(before.plans[0].source_installation_id,before.installation_id);
    await repo.change(before.revision,s=>savePlan(s,{...values,description:'Edited synthetic memo'},before.plans[0].id));
    const after=await repo.read();assert.equal(after.installation_id,before.installation_id);assert.equal(after.revision,2);
    assert.equal(after.plans[0].id,before.plans[0].id);assert.equal(after.plans[0].created_at,before.plans[0].created_at);
    assert.deepEqual(after.diagnosis,before.diagnosis);
  }finally{repo.close();}
});
test('stale and async writes refuse without replacing the last committed records',async()=>{
  const repo=await example();try{
    const before=await repo.read();
    await assert.rejects(repo.change(0,s=>{s.plans=[];}),/CONFLICT/);
    await assert.rejects(repo.change(before.revision,async s=>{s.plans=[];}),/ASYNC_MUTATION_REFUSED/);
    assert.deepEqual(await repo.read(),before);
  }finally{repo.close();}
});
test('a state-size failure discards only the attempted mutation',async()=>{
  const repo=await example();try{
    const before=await repo.read();
    await assert.rejects(repo.change(before.revision,s=>{
      s.plans=[];
      for(let i=0;i<100;i++)savePlan(s,{...values,description:'あ'.repeat(5000)},crypto.randomUUID());
    }),/STORAGE_FULL/);
    assert.deepEqual(await repo.read(),before);
  }finally{repo.close();}
});
test('unsupported schema is refused without conversion, including a write mutation',async()=>{
  const repo=await example();try{
    const before=await repo.read(),future=structuredClone(before);future.schema_version=999;
    const raw=JSON.stringify(future);assert.throws(()=>validate(future),/UNSUPPORTED_SCHEMA/);assert.equal(JSON.stringify(future),raw);
    await assert.rejects(repo.change(before.revision,s=>{s.schema_version=999;}),/UNSUPPORTED_SCHEMA/);
    assert.deepEqual(await repo.read(),before);
  }finally{repo.close();}
});
test('internal isolated restore preserves all records and leaves the source unchanged',async()=>{
  const source=await example(),isolated=await Repository.open();try{
    const before=await source.read(),destination=await isolated.read(),raw=await exportJSON(before);
    const preview=await previewImport(destination,raw);
    assert.deepEqual(await isolated.read(),destination,'preview has no write');
    const restored=await restore(isolated,raw,preview);
    assert.deepEqual(restored.diagnosis,before.diagnosis);assert.deepEqual(restored.plans,before.plans);
    assert.equal(restored.installation_id,destination.installation_id);assert.notEqual(restored.installation_id,before.installation_id);
    assert.deepEqual(await source.read(),before);
    await assert.rejects(previewImport(restored,raw),/DUPLICATE_IMPORT/);
    assert.deepEqual(await isolated.read(),restored);
  }finally{source.close();isolated.close();}
});
test('corruption and changed-after-preview never replace isolated or source data',async()=>{
  const source=await example(),isolated=await Repository.open();try{
    const before=await source.read(),empty=await isolated.read(),raw=await exportJSON(before);
    await assert.rejects(previewImport(empty,raw.replace('Synthetic memo','Tampered memo')),/CHECKSUM_MISMATCH/);
    const preview=await previewImport(empty,raw),different=structuredClone(before);different.plans[0].description='Different synthetic snapshot';
    await assert.rejects(restore(isolated,await exportJSON(different),preview),/BACKUP_CHANGED/);
    assert.deepEqual(await isolated.read(),empty);assert.deepEqual(await source.read(),before);
  }finally{source.close();isolated.close();}
});

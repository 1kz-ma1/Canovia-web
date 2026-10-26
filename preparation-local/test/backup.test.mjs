import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {emptyState,record,saveDiagnosis,savePlan,validate} from '../src/model.mjs';
import {Repository} from '../src/memory-repository.mjs';
import {decode,exportJSON,parseBackup,previewImport,restore,MAX_BYTES} from '../src/backup.mjs';
import {hash} from '../src/backup-codec.mjs';
function example(){const s=emptyState();saveDiagnosis(s,{Q01:3,Q02:'unknown'},2);savePlan(s,{title:'Synthetic <script> text',description:'A plan',category:'ST',deadline:'2026-12-01'},crypto.randomUUID());return s;}
test('reuses the old canonical/checksum primitive with a tighter node cap',()=>{
  const old=readFileSync('local-preview/src/backup.mjs','utf8'),codec=readFileSync('preparation-local/src/backup-codec.mjs','utf8');
  assert.ok(codec.endsWith(old.slice(old.indexOf('export function canonical'),old.indexOf('export async function exportJSON')).replace('1000000','100000')));
});
test('bounded decoder refuses duplicate escaped keys, prototypes, huge and deep input',()=>{
  for(const s of ['{"a":1,"a":2}','{"a":1,"\\u0061":2}','{"__proto__":{}}','{"constructor":1}','[1,]','"unterminated','['.repeat(13)+']'.repeat(13)])assert.throws(()=>decode(s));
  assert.throws(()=>decode('x'.repeat(MAX_BYTES+1)),/BACKUP_TOO_LARGE/);
  assert.deepEqual(decode('{"value":"constructor __proto__ <img>","nested":{"a":1}}'),{value:'constructor __proto__ <img>',nested:{a:1}});
});
test('schema constrains plans, versions and identities but does not assert Account ownership',()=>{
  const s=example();validate(s);for(const p of [{...s.plans[0],title:''},{...s.plans[0],deadline:'2026-02-31'},{...s.plans[0],description:'x'.repeat(5001)},{...s.plans[0],url:'javascript:alert(1)'}])assert.throws(()=>validate({...s,plans:[p]}));
  assert.throws(()=>validate({...s,plans:[s.plans[0],s.plans[0]]}));assert.throws(()=>validate({...s,item_bank_version:'future'}));
});
test('export/import previews, atomic apply, new installation identity and duplicate refusal',async()=>{
  const state=example(),raw=await exportJSON(state),repo=await Repository.open();
  const preview=await previewImport(await repo.read(),raw);assert.equal(preview.added,2);assert.equal((await repo.read()).plans.length,0);
  const restored=await restore(repo,raw,preview);assert.deepEqual(restored.plans,state.plans);assert.deepEqual(restored.diagnosis,state.diagnosis);assert.notEqual(restored.installation_id,state.installation_id);
  await assert.rejects(previewImport(restored,raw),/DUPLICATE_IMPORT/);repo.close();
});
test('corruption, foreign formats, changes after preview and conflicting records preserve state',async()=>{
  const source=example(),raw=await exportJSON(source),repo=await Repository.open();
  await assert.rejects(parseBackup(raw.replace('A plan','Changed plan')),/CHECKSUM_MISMATCH/);
  const f=JSON.parse(raw);f.payload.format='canovia.local.preview';f.sha256=await hash(f.payload);await assert.rejects(parseBackup(JSON.stringify(f)),/UNSUPPORTED_BACKUP/);
  const preview=await previewImport(await repo.read(),raw);await repo.change(0,s=>savePlan(s,{title:'Other tab',description:'',category:'',deadline:''},crypto.randomUUID()));
  const before=await repo.read();await assert.rejects(restore(repo,raw,preview),/CONFLICT/);assert.deepEqual(await repo.read(),before);
  const current=await Repository.open();await restore(current,raw,await previewImport(await current.read(),raw));
  source.plans[0].description='New content';const changed=await exportJSON(source),old=await current.read();await assert.rejects(previewImport(old,changed),/UUID_COLLISION/);assert.deepEqual(await current.read(),old);repo.close();current.close();
});

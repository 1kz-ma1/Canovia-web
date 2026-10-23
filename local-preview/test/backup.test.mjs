import {test} from 'node:test';import assert from 'node:assert/strict';import {emptyState,validate,record} from '../src/model.mjs';import {diagnose} from '../src/planning.mjs';import {startWork,checkpoint} from '../src/execution.mjs';import {startPractice,draftAnswer,submitPractice} from '../src/study.mjs';import {exportJSON,parseBackup,mergeBackup,previewImport,hash} from '../src/backup.mjs';
const source=()=>{const s=emptyState();diagnose(s,{domain:'study',goal:'試す',current_state:'',weekly_capacity:100});const w=startWork(s,s.tasks[0].id);checkpoint(s,w.id,3,'finished');const p=startPractice(s,s.tasks[0].id);draftAnswer(s,p.id,p.question.answer);submitPractice(s,p.id);validate(s);return s};
test('full backup restores all record facts into new installation with preserved source IDs; duplicate refused',async()=>{
 const s=source(),file=await exportJSON(s),b=await parseBackup(file),to=emptyState();const preview=await previewImport(to,file);assert.ok(preview.added>0);mergeBackup(to,b);validate(to);for(const t of ['profiles','plans','tasks','work_sessions','work_logs','practice_sessions','practice_attempts'])assert.deepEqual(to[t],s[t]);assert.notEqual(to.installation_id,s.installation_id);assert.throws(()=>mergeBackup(to,b),/DUPLICATE_IMPORT/);
});
test('checksum/version/broken references/malformed/oversize/prototype keys rejected',async()=>{
 const raw=await exportJSON(source());const changed=JSON.parse(raw);changed.payload.data.tasks[0].title='改変';await assert.rejects(()=>parseBackup(JSON.stringify(changed)),/CHECKSUM/);
 for(const mutate of [f=>f.payload.schema_version=0,f=>f.payload.data.tasks[0].plan_id=crypto.randomUUID(),f=>f.payload.data.extra='bad']){const f=JSON.parse(raw);mutate(f);f.sha256=await hash(f.payload);await assert.rejects(()=>parseBackup(JSON.stringify(f)))}
 await assert.rejects(()=>parseBackup('not json'));await assert.rejects(()=>parseBackup('x'.repeat(8*1024*1024+1)));await assert.rejects(()=>parseBackup('{"__proto__":{}}'));
});
test('UUID collision, profile conflict and active run import are conservative',async()=>{
 const s=source(),raw=await exportJSON(s),b=await parseBackup(raw),to=structuredClone(s);to.tasks[0].title='別編集';assert.throws(()=>mergeBackup(to,b),/UUID_COLLISION/);
 const other=source();assert.throws(()=>mergeBackup(other,b),/PROFILE_CONFLICT/);
 const active=source();startWork(active,active.tasks[0].id);const result=emptyState();mergeBackup(result,await parseBackup(await exportJSON(active)));assert.equal(result.work_sessions.at(-1).status,'paused');validate(result);
});

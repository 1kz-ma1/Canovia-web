import {TABLES,validate,requireValue,LocalError,instant,UUID} from './model.mjs';
import {recoverWork} from './execution.mjs';
export const FORMAT='canovia.local.preview',MAX_BYTES=8*1024*1024;
export function canonical(v,depth=0,budget={nodes:0}){
 requireValue(depth<=12&&++budget.nodes<=1000000,'IMPORT_TOO_COMPLEX');
 if(v===null||typeof v!=='object')return JSON.stringify(v);
 if(Array.isArray(v))return '['+v.map(x=>canonical(x,depth+1,budget)).join(',')+']';
 requireValue(Object.getPrototypeOf(v)===Object.prototype&&!Object.keys(v).some(k=>['__proto__','constructor','prototype'].includes(k)),'INVALID_DATA');
 return '{'+Object.keys(v).sort().map(k=>JSON.stringify(k)+':'+canonical(v[k],depth+1,budget)).join(',')+'}';
}
export async function hash(value){requireValue(globalThis.crypto?.subtle,'SECURE_CONTEXT_REQUIRED');return [...new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(canonical(value))))].map(n=>n.toString(16).padStart(2,'0')).join('')}
export async function exportJSON(state){
 validate(state);const payload={format:FORMAT,schema_version:1,exported_at:new Date().toISOString(),source_installation_id:state.installation_id,data:structuredClone(state)};
 const raw=JSON.stringify({payload,sha256:await hash(payload)});requireValue(new TextEncoder().encode(raw).length<=MAX_BYTES,'BACKUP_TOO_LARGE');return raw;
}
export async function parseBackup(raw){
 try{
  requireValue(typeof raw==='string'&&new TextEncoder().encode(raw).length<=MAX_BYTES,'BACKUP_TOO_LARGE');
  const file=JSON.parse(raw);canonical(file);
  requireValue(file&&Object.keys(file).sort().join()==='payload,sha256'&&typeof file.sha256==='string'&&/^[a-f0-9]{64}$/.test(file.sha256),'INVALID_BACKUP');
  const p=file.payload;requireValue(p&&Object.keys(p).sort().join()==='data,exported_at,format,schema_version,source_installation_id'&&p.format===FORMAT&&p.schema_version===1,'UNSUPPORTED_BACKUP');
  requireValue(instant(p.exported_at)&&UUID.test(p.source_installation_id)&&p.data.installation_id===p.source_installation_id,'INVALID_BACKUP');
  requireValue(await hash(p)===file.sha256,'CHECKSUM_MISMATCH');validate(p.data);return {data:p.data,sha256:file.sha256};
 }catch(e){throw e instanceof LocalError?e:new LocalError('INVALID_BACKUP')}
}
export function mergeBackup(state,backup){
 validate(state);validate(backup.data);requireValue(!state.import_receipts.includes(backup.sha256),'DUPLICATE_IMPORT');
 const all=new Map(TABLES.flatMap(t=>state[t].map(row=>[row.id,{table:t,row}])));let added=0,skipped=0;
 for(const table of TABLES)for(const row of backup.data[table]){
  const old=all.get(row.id);
  if(old){requireValue(old.table===table&&canonical(old.row)===canonical(row),'UUID_COLLISION');skipped++;}
  else{state[table].push(structuredClone(row));added++;}
 }
 requireValue(added>0,'DUPLICATE_IMPORT');requireValue(state.profiles.length<=1,'PROFILE_CONFLICT');
 recoverWork(state);state.import_receipts.push(backup.sha256);validate(state);return {added,skipped};
}
export async function previewImport(state,raw){
 const backup=await parseBackup(raw);const copy=structuredClone(state),counts=mergeBackup(copy,backup);return {...counts,base_revision:state.revision,sha256:backup.sha256};
}
export async function restore(repo,raw,preview){
 const backup=await parseBackup(raw);requireValue(backup.sha256===preview.sha256,'BACKUP_CHANGED');
 return repo.change(preview.base_revision,state=>mergeBackup(state,backup));
}

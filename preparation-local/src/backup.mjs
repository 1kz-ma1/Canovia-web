import {validate,keys,requireValue,LocalError,instant,UUID} from './model.mjs';
import {canonical,hash} from './backup-codec.mjs';
export const FORMAT='canovia.preparation.local',MAX_BYTES=2*1024*1024;

// JSON.parse alone silently accepts duplicate keys. Bound structure and reject
// duplicate/unsafe keys before decoding. Input is never interpreted as code.
export function decode(raw){
  requireValue(typeof raw==='string'&&raw.length<=MAX_BYTES&&new TextEncoder().encode(raw).length<=MAX_BYTES,'BACKUP_TOO_LARGE');
  const stack=[];let nodes=0;
  try{
    for(let i=0;i<raw.length;i++){
      const c=raw[i];
      if(c==='"'){
        const start=i;for(i++;i<raw.length;i++){if(raw[i]==='\\'){i++;continue;}if(raw[i]==='"')break;}
        requireValue(i<raw.length);const value=JSON.parse(raw.slice(start,i+1));let next=i+1;while(/\s/.test(raw[next]||'x'))next++;
        if(raw[next]===':'){
          const object=stack.at(-1);requireValue(object?.kind==='{'&&!['__proto__','constructor','prototype'].includes(value)&&!object.keys.has(value),'INVALID_BACKUP');object.keys.add(value);
        }
        nodes++;
      }else if(c==='{'||c==='['){stack.push({kind:c,keys:new Set()});requireValue(stack.length<=12,'IMPORT_TOO_COMPLEX');nodes++;}
      else if(c==='}'||c===']'){requireValue(stack.pop()?.kind===(c==='}'?'{':'['));}
      else if(c===',')nodes++;
      requireValue(nodes<=100000,'IMPORT_TOO_COMPLEX');
    }
    requireValue(stack.length===0);return JSON.parse(raw);
  }catch(e){throw e instanceof LocalError?e:new LocalError('INVALID_BACKUP');}
}
export async function exportJSON(state){
  validate(state);const payload={format:FORMAT,schema_version:1,exported_at:new Date().toISOString(),source_installation_id:state.installation_id,data:structuredClone(state)};
  const raw=JSON.stringify({payload,sha256:await hash(payload)});requireValue(new TextEncoder().encode(raw).length<=MAX_BYTES,'BACKUP_TOO_LARGE');return raw;
}
export async function parseBackup(raw){
  try{
    const file=decode(raw);keys(file,['payload','sha256']);const p=file.payload;
    keys(p,['format','schema_version','exported_at','source_installation_id','data']);
    requireValue(p.format===FORMAT&&p.schema_version===1,'UNSUPPORTED_BACKUP');
    requireValue(typeof file.sha256==='string'&&/^[a-f0-9]{64}$/.test(file.sha256)&&instant(p.exported_at)&&typeof p.source_installation_id==='string'&&UUID.test(p.source_installation_id));
    validate(p.data);requireValue(p.data.installation_id===p.source_installation_id);
    requireValue(await hash(p)===file.sha256,'CHECKSUM_MISMATCH');return {data:p.data,sha256:file.sha256};
  }catch(e){throw e instanceof LocalError?e:new LocalError('INVALID_BACKUP');}
}
function merge(state,backup){
  validate(state);requireValue(!state.import_receipts.includes(backup.sha256),'DUPLICATE_IMPORT');let added=0,skipped=0;
  if(backup.data.diagnosis){
    if(state.diagnosis){requireValue(canonical(state.diagnosis)===canonical(backup.data.diagnosis),'DIAGNOSIS_CONFLICT');skipped++;}
    else{state.diagnosis=structuredClone(backup.data.diagnosis);added++;}
  }
  for(const p of backup.data.plans){
    const old=state.plans.find(v=>v.id===p.id);
    if(old){requireValue(canonical(old)===canonical(p),'UUID_COLLISION');skipped++;}
    else{state.plans.push(structuredClone(p));added++;}
  }
  requireValue(added>0,'DUPLICATE_IMPORT');state.import_receipts.push(backup.sha256);validate(state);return {added,skipped};
}
export async function previewImport(state,raw){
  const backup=await parseBackup(raw),copy=structuredClone(state),counts=merge(copy,backup);return {...counts,base_revision:state.revision,sha256:backup.sha256};
}
export async function restore(repo,raw,preview){
  const backup=await parseBackup(raw);requireValue(backup.sha256===preview.sha256,'BACKUP_CHANGED');
  return repo.change(preview.base_revision,s=>merge(s,backup));
}

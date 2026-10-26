import {bank} from './data.mjs';
import {plain,validateAnswers} from './scoring.mjs';
export const VERSION=1,MAX_STATE_BYTES=1024*1024;
export const UUID=/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
export class LocalError extends Error {constructor(code){super(code);this.name='LocalError';}}
export function requireValue(value,code='INVALID_DATA'){if(!value)throw new LocalError(code);}
export const text=(s,max)=>typeof s==='string'&&s.length<=max&&!/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/.test(s);
export const instant=s=>typeof s==='string'&&/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/.test(s)&&Number.isFinite(Date.parse(s))&&new Date(s).toISOString()===s;
export const date=s=>typeof s==='string'&&/^\d{4}-\d\d-\d\d$/.test(s)&&Number.isFinite(Date.parse(s+'T00:00:00Z'))&&new Date(s+'T00:00:00Z').toISOString().slice(0,10)===s;
export function keys(v,expected){requireValue(plain(v)&&Object.keys(v).length===expected.length&&expected.every(k=>Object.hasOwn(v,k)));}
const versions={item_bank_version:bank.item_bank_version,scoring_version:bank.scoring_version,category_version:bank.category_version};
export function emptyState(){return {schema_version:VERSION,...versions,installation_id:crypto.randomUUID(),revision:0,diagnosis:null,plans:[],import_receipts:[]};}
export function record(state,values,now=new Date().toISOString()){return {id:crypto.randomUUID(),source_installation_id:state.installation_id,created_at:now,updated_at:now,...values};}
function identity(v){requireValue(UUID.test(v.id)&&UUID.test(v.source_installation_id)&&instant(v.created_at)&&instant(v.updated_at)&&v.created_at<=v.updated_at);}
export function validate(s){
  keys(s,['schema_version',...Object.keys(versions),'installation_id','revision','diagnosis','plans','import_receipts']);
  requireValue(s.schema_version===VERSION&&Object.entries(versions).every(([k,v])=>s[k]===v),'UNSUPPORTED_SCHEMA');
  requireValue(UUID.test(s.installation_id)&&Number.isSafeInteger(s.revision)&&s.revision>=0);
  requireValue(Array.isArray(s.import_receipts)&&s.import_receipts.length<=1000&&new Set(s.import_receipts).size===s.import_receipts.length&&s.import_receipts.every(v=>typeof v==='string'&&/^[a-f0-9]{64}$/.test(v)));
  if(s.diagnosis!==null){
    const d=s.diagnosis;keys(d,['id','source_installation_id','created_at','updated_at','answers','confirmed_categories','requested_support','step']);identity(d);
    try{validateAnswers(d.answers);}catch{throw new LocalError('INVALID_DATA');}
    requireValue(Number.isInteger(d.step)&&d.step>=1&&d.step<=6);
    for(const field of ['confirmed_categories','requested_support'])requireValue(Array.isArray(d[field])&&d[field].length<=8&&new Set(d[field]).size===d[field].length&&d[field].every(c=>typeof c==='string'&&Object.hasOwn(bank.categories,c)));
  }
  requireValue(Array.isArray(s.plans)&&s.plans.length<=100);
  const ids=new Set(s.diagnosis?[s.diagnosis.id]:[]);
  for(const p of s.plans){
    keys(p,['id','source_installation_id','created_at','updated_at','title','description','category','deadline']);identity(p);
    requireValue(!ids.has(p.id));ids.add(p.id);
    requireValue(text(p.title,255)&&p.title.trim().length>0&&text(p.description,5000)&&text(p.category,100)&&(p.deadline===null||date(p.deadline)));
  }
  requireValue(new TextEncoder().encode(JSON.stringify(s)).length<=MAX_STATE_BYTES,'STORAGE_FULL');return s;
}
export function saveDiagnosis(s,answers,step){
  validateAnswers(answers);
  if(!s.diagnosis)s.diagnosis=record(s,{answers:{},step:1,confirmed_categories:[],requested_support:[]});
  s.diagnosis.answers=structuredClone(answers);s.diagnosis.step=step;s.diagnosis.updated_at=new Date().toISOString();
}
export function savePlan(s,values,id){
  requireValue(typeof id==='string'&&UUID.test(id));
  const clean={title:values.title.trim(),description:values.description,category:values.category,deadline:values.deadline||null};
  const old=s.plans.find(p=>p.id===id);
  if(old){Object.assign(old,clean);old.updated_at=new Date().toISOString();}
  else{s.plans.push({...record(s,clean),id});}
  validate(s);
}

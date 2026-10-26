// Shared canonical/checksum primitive from local-preview; generated, no authority.
import {requireValue} from './model.mjs';
export function canonical(v,depth=0,budget={nodes:0}){
 requireValue(depth<=12&&++budget.nodes<=100000,'IMPORT_TOO_COMPLEX');
 if(v===null||typeof v!=='object')return JSON.stringify(v);
 if(Array.isArray(v))return '['+v.map(x=>canonical(x,depth+1,budget)).join(',')+']';
 requireValue(Object.getPrototypeOf(v)===Object.prototype&&!Object.keys(v).some(k=>['__proto__','constructor','prototype'].includes(k)),'INVALID_DATA');
 return '{'+Object.keys(v).sort().map(k=>JSON.stringify(k)+':'+canonical(v[k],depth+1,budget)).join(',')+'}';
}
export async function hash(value){requireValue(globalThis.crypto?.subtle,'SECURE_CONTEXT_REQUIRED');return [...new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(canonical(value))))].map(n=>n.toString(16).padStart(2,'0')).join('')}

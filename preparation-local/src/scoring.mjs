import {bank} from './data.mjs';
export const plain=v=>v!==null&&typeof v==='object'&&!Array.isArray(v)&&Object.getPrototypeOf(v)===Object.prototype;
export function validateAnswers(answers) {
  if(!plain(answers)||Object.keys(answers).length>30)throw Error('INVALID_ANSWERS');
  for(const [id,v] of Object.entries(answers)) {
    if(!Object.hasOwn(bank.items,id)||(v!=='unknown'&&(!Number.isInteger(v)||v<0||v>3)))throw Error('INVALID_ANSWERS');
  }
}
export function score(answers) {
  validateAnswers(answers);
  const totals=Object.fromEntries(Object.entries(bank.categories).map(([id,label])=>[id,{label,points:0,weight:0,primary:0}]));
  let answered=0,unknown=0;
  for(const [id,item] of Object.entries(bank.items)) {
    if(!Object.hasOwn(answers,id))continue;
    const value=answers[id];if(value==='unknown'){unknown++;continue;}answered++;
    const p=item.primary,s=item.secondary??null,w=s===null?1:0.5;
    totals[p].points+=w*value;totals[p].weight+=w;
    if(s===null)totals[p].primary++;else {totals[s].points+=0.5*value;totals[s].weight+=0.5;}
  }
  let sum=0;
  const categories=Object.fromEntries(Object.entries(totals).map(([id,v])=>{
    const ready=v.primary>=2,result=ready&&v.weight>0?v.points/v.weight:null;
    if(result!==null)sum+=result;
    return [id,{label:v.label,status:ready?'ready':'insufficient_evidence',answered_primary:v.primary,score:result,share_percent:null}];
  }));
  const status=sum>0?'ready':Object.values(categories).some(v=>v.score!==null)?'no_relative_signal':'insufficient_evidence';
  if(status==='ready') {
    let remaining=100;const fractions=[];
    Object.entries(categories).forEach(([id,v],position)=>{
      if(v.score===null)return;const exact=100*v.score/sum,floor=Math.floor(exact);
      v.share_percent=floor;remaining-=floor;fractions.push({id,fraction:exact-floor,position});
    });
    fractions.sort((a,b)=>b.fraction-a.fraction||a.position-b.position);
    for(let i=0;i<remaining;i++)categories[fractions[i].id].share_percent++;
  }
  return {item_bank_version:bank.item_bank_version,scoring_version:bank.scoring_version,category_version:bank.category_version,
    status,answered_count:answered,unknown_count:unknown,unanswered_count:30-Object.keys(answers).length,categories};
}

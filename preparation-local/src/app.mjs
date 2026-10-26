import {bank,future} from './data.mjs';
import {score} from './scoring.mjs';
import {validate,saveDiagnosis} from './model.mjs';
import {Repository} from './repository.mjs';
import {PROFILE,ADOPTED_ORIGIN} from './settings.mjs';
import {planView,bindPlans} from './plan-ui.mjs';
import * as backupUI from './backup-ui.mjs';
const root=document.querySelector('#app'),message=document.querySelector('#message');
export const h=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
export let repo,state;let busy=false,dirty=false,currentView='home',editing=null;
const errors={CONFLICT:'別のタブで変更されました。入力を控えてから再読み込みしてください。',STORAGE_FULL:'保存領域が足りません。入力を控えてください。データは削除していません。',UNSUPPORTED_SCHEMA:'保存済み記録の版を確認できません。推定や削除は行いません。'};
export function notice(s,error=false){message.textContent=s;message.className=error?'error':'good';}
export async function act(mutate,view,planId=editing){
  if(busy){notice('処理中です。この操作はまだ保存・反映されていません。',true);return false;}busy=true;
  const buttons=[...root.querySelectorAll('button')].map(b=>[b,b.disabled]);buttons.forEach(([b])=>b.disabled=true);
  try{state=await repo.change(state.revision,mutate);dirty=false;render(view,planId);notice(PROFILE==='B'?'この端末に保存しました。':'この画面に反映しました。再読み込みすると消えます。');return true;}
  catch(e){notice(errors[e.message]||'保存・反映できませんでした。入力を控え、状態を確認してください。',true);return false;}
  finally{busy=false;buttons.forEach(([b,disabled])=>{if(b.isConnected)b.disabled=disabled;});}
}
function journey(current){return `<ol class="preparation-journey" aria-label="準備のステップ">${['関心を見つける','結果をふりかえる','Futureを知る','自分のPlanへ'].map((v,i)=>`<li ${i===current?'aria-current="step"':''}><span aria-hidden="true">${i+1}</span>${v}</li>`).join('')}</ol>`;}
function diagnosis(){
  const answers=state.diagnosis?.answers||{},step=state.diagnosis?.step||1;
  return journey(0)+`<section class="preparation-intro"><h1>30問で関心を見つける</h1><p>日常の選び方から、今の関心を整理します。能力・達成度・性格を判定する検査ではなく、心理学的な妥当性は未検証です。</p><p>回答は任意です。「わからない」と未回答は肯定・否定として採点しません。</p></section><form method="post" id="diagnosis-form"><div class="preparation-progress"><p id="question-progress"></p><progress max="30" value="${Object.keys(answers).length}" aria-label="選択済みの質問数"></progress></div>${Array.from({length:6},(_,page)=>`<section class="page-card" data-page="${page+1}" ${page+1!==step?'hidden':''} aria-label="質問 ${page*5+1}〜${page*5+5}">${Object.entries(bank.items).slice(page*5,page*5+5).map(([id,item])=>`<fieldset><legend>${Number(id.slice(1))}. ${h(item.text)}</legend><div class="preparation-answers">${[['0','あてはまらない'],['1','あまりあてはまらない'],['2','ややあてはまる'],['3','あてはまる'],['unknown','わからない']].map(([v,label])=>`<label class="preparation-answer ${v==='unknown'?'preparation-answer-unknown':''}"><input type="radio" name="${id}" value="${v}" ${String(answers[id])===v?'checked':''}><span class="preparation-answer-body">${label}</span></label>`).join('')}</div><button type="button" data-unanswer="${id}">未回答に戻す</button></fieldset>`).join('')}</section>`).join('')}<div class="buttons"><button type="button" id="previous">前の5問</button><button type="button" id="next">次の5問</button></div><section class="page-card"><label><input type="checkbox" name="consent" required ${state.diagnosis?'checked':''}> ${PROFILE==='B'?'回答・結果と自分の修正をこの端末に保存します。クラウド保存ではありません。':'入力はこの画面だけに反映され、再読み込み・終了で失われます。'}</label><div class="buttons"><button name="action" value="save" type="submit">${PROFILE==='B'?'途中保存する':'途中の内容を反映する'}</button><button name="action" value="result" type="submit">今の回答で結果を見る</button></div></section></form>`;
}
function result(){
  const r=score(state.diagnosis?.answers||{}),labels={insufficient_evidence:'まだ判断材料が足りません。各分野の基本3問のうち2問以上の回答が必要です。',no_relative_signal:'今の回答からは相対的な割合を求められません。能力や関心の欠如を意味しません。'};
  return journey(1)+`<section class="preparation-intro"><h1>関心のふりかえり</h1><p>今の回答からみた相対的な傾向です。能力・達成度・適性の判定ではありません。結果に合わせる必要はありません。</p><a href="#diagnosis">回答を見直す・再計算する</a></section><section class="page-card"><h2>関心のバランス</h2><p id="result-counts">回答 ${r.answered_count}問 ／ わからない ${r.unknown_count}問 ／ 未回答 ${r.unanswered_count}問</p><p>${labels[r.status]||'割合は、回答できた分野の間でのバランスです。低い値は苦手という意味ではありません。'}</p><ul>${Object.entries(r.categories).map(([id,c])=>`<li class="preparation-chart-row" data-category="${id}"><span class="preparation-chart-label"><img src="./assets/${id.toLowerCase()}.webp" width="320" height="320" alt=""><span>${h(c.label)}</span></span><span>${c.share_percent===null?'判断材料が足りません':c.share_percent+'%'}</span>${c.share_percent===null?'':`<meter min="0" max="100" value="${c.share_percent}" aria-label="${h(c.label)} ${c.share_percent}%"></meter>`}</li>`).join('')}</ul><details><summary>割合の出し方</summary><p>0〜3点を分野ごとに集計し、複数分野の設問は半分ずつ配分します。unknownと未回答は分母から除き、基本3問中2問以上の回答がある分野で割合を配分します。心理尺度ではありません。</p></details></section><form method="post" id="review-form" class="page-card"><fieldset><legend>自分で選ぶ、今の関心（任意）</legend><p>選んだ内容は別に保持し、グラフの点数を書き換えません。</p>${Object.entries(bank.categories).map(([id,label])=>`<label><input type="checkbox" name="confirmed" value="${id}" ${state.diagnosis?.confirmed_categories.includes(id)?'checked':''}> ${h(label)}</label>`).join('')}</fieldset><fieldset><legend>準備したいこと（任意）</legend><p>機能提供やSupport投票を意味しません。</p>${Object.entries(bank.categories).map(([id,label])=>`<label><input type="checkbox" name="support" value="${id}" ${state.diagnosis?.requested_support.includes(id)?'checked':''}> ${h(label)}</label>`).join('')}</fieldset><button type="submit">関心・希望を${PROFILE==='B'?'保存':'反映'}する</button></form><a href="#future">Canovia Futureを見る</a>`;
}
function futureView(){return journey(2)+`<section class="preparation-intro"><h1>Canovia Future</h1><p>開発・検証中の体験を紹介します。掲載されていても利用可能とは限りません。投票や応援数の表示はありません。</p></section>${future.map(v=>`<article class="page-card"><p>${h({planned:'将来構想',in_development:'開発中',ready:'検証中・一般提供未承認'}[v.status])} · ${h(v.area)}</p><h2>${h(v.title)}</h2><p>${h(v.description)}</p></article>`).join('')}`;}
export function render(view=location.hash.slice(1)||'home',planId=editing){
  if(!['home','diagnosis','result','future','plans','data'].includes(view))view='home';editing=planId;
  currentView=view;history.replaceState(null,'','#'+view);
  root.innerHTML=view==='diagnosis'?diagnosis():view==='result'?result():view==='future'?futureView():`<section class="preparation-intro"><h1>自分の関心から、次の準備へ。</h1><p>任意の30問で関心を整理し、正式リリースに向けた準備を考えます。</p><a href="#diagnosis">関心を見つける</a> · <a href="#future">Canovia Future</a></section>`;
  if(view==='plans'){root.innerHTML=journey(3)+planView(state,editing,h,PROFILE);bindPlans(root,state,editing,act,render);}
  if(view==='data'){root.innerHTML=backupUI.markup();backupUI.install(root,{repo,h,notice,busy:()=>busy,replace:async(operation,onFailure)=>{
    if(busy)return;busy=true;try{state=validate(await operation());dirty=false;render('data');notice(PROFILE==='B'?'Importしました。この端末で内容を確認し、改めてExportしてください。':'この画面にImportしました。再読み込みすると消えます。');}catch{onFailure();}finally{busy=false;}
  }});}
  if(['home','diagnosis','result','future'].includes(view))root.insertAdjacentHTML('beforeend','<section class="page-card"><p>診断を使わず、自分で目標・Planを作ることもできます。</p><a href="#plans">自分の目標・Planへ</a></section>');
  if(view==='result'&&state.diagnosis){const button=document.createElement('button');button.type='button';button.textContent='診断の記録を削除する';button.onclick=()=>{if(confirm('この端末の診断回答・修正を削除します。Planは保持します。'))act(s=>{s.diagnosis=null;},'diagnosis');};root.append(button);}
  if(view==='diagnosis')bindDiagnosis();
  const review=root.querySelector('#review-form');if(review)review.onsubmit=e=>{e.preventDefault();if(!state.diagnosis){notice('先に今の回答で結果を反映してください。',true);return;}const f=new FormData(review);act(s=>{s.diagnosis.confirmed_categories=f.getAll('confirmed');s.diagnosis.requested_support=f.getAll('support');s.diagnosis.updated_at=new Date().toISOString();},'result');};
  root.querySelectorAll('input:not([type=file]),textarea').forEach(v=>v.addEventListener('input',()=>{dirty=true;}));
}
function bindDiagnosis(){
  const form=root.querySelector('#diagnosis-form');let step=state.diagnosis?.step||1;
  const update=()=>{form.querySelectorAll('[data-page]').forEach(p=>p.hidden=Number(p.dataset.page)!==step);root.querySelector('#previous').disabled=step===1;root.querySelector('#next').disabled=step===6;const n=[...form.elements].filter(v=>v.type==='radio'&&v.checked).length;root.querySelector('#question-progress').textContent=`${step} / 6ページ · 選択済み ${n} / 30問`;form.querySelector('progress').value=n;};
  root.querySelector('#previous').onclick=()=>{step--;update();};root.querySelector('#next').onclick=()=>{step++;update();};form.onchange=update;
  root.querySelectorAll('[data-unanswer]').forEach(b=>b.onclick=()=>{form.querySelectorAll(`input[name="${b.dataset.unanswer}"]`).forEach(v=>v.checked=false);dirty=true;update();});
  form.onsubmit=e=>{e.preventDefault();const values=new FormData(form),answers={};for(const id of Object.keys(bank.items)){const v=values.get(id);if(v!==null)answers[id]=v==='unknown'?v:Number(v);}act(s=>saveDiagnosis(s,answers,step),e.submitter?.value==='result'?'result':'diagnosis');};update();
}
addEventListener('hashchange',()=>{if(!repo)return;if(dirty&&!confirm('まだ保存・反映していない入力を破棄して移動しますか？')){history.replaceState(null,'','#'+currentView);return;}dirty=false;render();document.querySelector('#main').focus();});
addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});
try{
  const local=['127.0.0.1','localhost','[::1]'].includes(location.hostname)&&['http:','https:'].includes(location.protocol);
  if(location.search||(!local&&(ADOPTED_ORIGIN===null||location.origin!==ADOPTED_ORIGIN))||navigator.serviceWorker?.controller)throw Error('ORIGIN_NOT_ACCEPTED');
  repo=await Repository.open();state=validate(await repo.read());
  message.textContent='';
  document.querySelector('#storage-notice').textContent=PROFILE==='B'?'端末内保存です。消去・紛失で失う場合があります。定期的にExportを別保管してください。':'保存なしのPreviewです。入力は再読み込み・終了で消えます。';
  repo.onchange=()=>notice('別のタブで変更されました。入力を控えてから再読み込みしてください。',true);render();
}catch(e){notice(errors[e.message]||(e.message==='ORIGIN_NOT_ACCEPTED'?'この配信先・配信処理は未採用です。記録を書き換えず終了しました。':'保存領域を開けませんでした。記録を削除せず、設定や対応する版を確認してください。'),true);}

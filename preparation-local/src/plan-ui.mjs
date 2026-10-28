import {savePlan} from './model.mjs';
import {bank} from './data.mjs';

export function planView(state,editing,h,profile){
  const p=state.plans.find(v=>v.id===editing),stored=profile==='B';
  const plans=[...state.plans].sort((a,b)=>b.updated_at.localeCompare(a.updated_at));
  const updated=p?new Intl.DateTimeFormat('ja-JP',{month:'long',day:'numeric',hour:'2-digit',minute:'2-digit'}).format(new Date(p.updated_at)):'';
  const intro=`<div class="screen-heading"><p class="eyebrow">PLAN · 自分の一歩を残す</p><h1>自分の目標とPlan</h1><p class="lead">少し先の目標と、まず試すことを。<br>あなたの言葉で、次の一歩を残しましょう。</p></div>`;
  const library=`<section class="plan-library"><div class="section-title"><h2>準備したPlan</h2><span class="count-badge">${plans.length}件</span></div>
  ${plans.length?`<ul class="plan-list">${plans.map(v=>`<li class="plan-list-item ${v.id===editing?'is-selected':''}"><span class="plan-category">${h(v.category||'自分の目標')}</span><button type="button" data-edit-plan="${v.id}" aria-pressed="${v.id===editing}">${h(v.title)}</button><p>${v.deadline?`目標日 <time datetime="${v.deadline}">${h(v.deadline)}</time>`:'目標日は未設定'}</p></li>`).join('')}</ul>`:`<div class="empty-plan"><span class="empty-orbit" aria-hidden="true">＋</span><h3>最初の一歩を、ここから。</h3><p>まだPlanはありません。<br>気になることを、ひとつ書いてみませんか。</p></div>`}
  <button type="button" id="new-plan" class="button button-secondary full-width">＋ 新しいPlanを入力する</button><p class="subtle">診断を使わずに作れます。自動生成・自動保存は行いません。</p></section>`;
  const editor=`<form method="post" id="plan-form" class="card plan-editor"><div class="editor-heading"><span class="pill">${p?'MY PLAN':'NEW PLAN'}</span><h2>${p?'Planを編集する':'目標と計画を残す'}</h2><p id="editor-save-state" class="save-state">${p?`${stored?'端末内に保存済み':'この画面に反映済み'} · ${h(updated)}`:'入力して保存すると、あなたのPlanになります。'}</p></div>
  <label for="plan-title">目標</label><p id="title-help" class="field-hint"><span class="required-label">必須</span>どんなことを、できるようになりたい？</p><input id="plan-title" name="title" required maxlength="255" aria-describedby="title-help" placeholder="例：英語で、自分の考えを伝えたい" value="${h(p?.title||'')}">
  <label for="plan-description">現在地・計画のメモ</label><p id="description-help" class="field-hint">今の自分と、まず試したい小さな一歩を。</p><textarea id="plan-description" name="description" maxlength="5000" aria-describedby="description-help" placeholder="今は、どんなところにいる？&#10;まずは、何から始めてみる？">${h(p?.description||'')}</textarea>
  <div class="field-pair"><div><label for="plan-category">分野</label><input id="plan-category" name="category" maxlength="100" list="plan-categories" placeholder="自由に入力できます" value="${h(p?.category||'')}"><datalist id="plan-categories">${Object.values(bank.categories).map(v=>`<option value="${h(v)}"></option>`).join('')}</datalist></div><div><label for="plan-deadline">目標日（任意）</label><input id="plan-deadline" type="date" name="deadline" value="${h(p?.deadline||'')}"></div></div>
  <button type="submit" class="button button-primary full-width">${p?'変更を':'Planを'}${stored?'保存する':'反映する'}</button><p class="subtle">${stored?'この端末に保存します。別端末とクラウド同期はされません。':'保存なしのPreviewです。再読み込み・終了で入力は消えます。'}</p></form>`;
  return intro+`<div class="plan-layout ${plans.length?'':'plan-layout-empty'}">${plans.length?library+editor:editor+library}</div>`;
}
export function bindPlans(root,state,editing,act,render){
  root.querySelector('#new-plan').onclick=()=>render('plans',null);
  root.querySelectorAll('[data-edit-plan]').forEach(b=>b.onclick=()=>render('plans',b.dataset.editPlan));
  const id=editing||crypto.randomUUID();
  root.querySelector('#plan-form').onsubmit=e=>{e.preventDefault();const data=Object.fromEntries(new FormData(e.currentTarget));act(s=>savePlan(s,data,id),'plans',id);};
}

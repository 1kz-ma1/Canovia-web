import {savePlan} from './model.mjs';
export function planView(state,editing,h,profile){
  const p=state.plans.find(v=>v.id===editing);
  return `<section class="preparation-intro"><h1>自分の目標とPlan</h1><p>診断は任意です。内容は自分で入力し、確認してから${profile==='B'?'端末内に保存':'この画面に反映'}します。自動生成・保存は行いません。</p></section><section class="page-card"><h2>準備したPlan</h2><ul>${state.plans.map(v=>`<li><button type="button" data-edit-plan="${v.id}">${h(v.title)}</button><p>${h(v.category)} ${v.deadline?'· '+h(v.deadline):''}</p></li>`).join('')||'<li>まだPlanはありません。</li>'}</ul><button type="button" id="new-plan">新しいPlanを入力する</button></section><form method="post" id="plan-form" class="page-card"><h2>${p?'Planを編集する':'目標と計画を残す'}</h2><label>目標<input name="title" required maxlength="255" value="${h(p?.title||'')}"></label><label for="plan-description">現在地・計画のメモ</label><textarea id="plan-description" name="description" maxlength="5000">${h(p?.description||'')}</textarea><label>分野<input name="category" maxlength="100" value="${h(p?.category||'')}"></label><label>目標日（任意）<input type="date" name="deadline" value="${h(p?.deadline||'')}"></label><button type="submit">${p?'変更を':'Planを'}${profile==='B'?'保存する':'反映する'}</button></form>`;
}
export function bindPlans(root,state,editing,act,render){
  root.querySelector('#new-plan').onclick=()=>render('plans',null);
  root.querySelectorAll('[data-edit-plan]').forEach(b=>b.onclick=()=>render('plans',b.dataset.editPlan));
  const id=editing||crypto.randomUUID();
  root.querySelector('#plan-form').onsubmit=e=>{e.preventDefault();const data=Object.fromEntries(new FormData(e.currentTarget));act(s=>savePlan(s,data,id),'plans',id);};
}

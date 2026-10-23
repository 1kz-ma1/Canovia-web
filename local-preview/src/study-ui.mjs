import {startPractice,draftAnswer,submitPractice,topics,nextQuestion} from './study.mjs';
export function install(ctx){
 let current=null;
 ctx.extensions.push(p=>{
  if(!p||p.domain!=='study')return;
  document.querySelectorAll('.task-tools').forEach(el=>{
   const t=ctx.state().tasks.find(t=>t.id===el.dataset.task);if(['done','cancelled'].includes(t.status))return;
   const b=document.createElement('button');b.className='secondary';b.textContent='演習を始める';b.onclick=()=>ctx.act(s=>{current=startPractice(s,t.id).id});el.append(b);
  });
  const sessions=ctx.state().practice_sessions.filter(s=>s.plan_id===p.id);
  let s=sessions.find(s=>s.id===current)||sessions.findLast(s=>s.status==='draft');if(s)current=s.id;
  const panel=document.createElement('section');panel.className='card';panel.id='study';
  panel.innerHTML=`<h2>学習の現在地</h2><p class="muted">動作確認用のオリジナル基礎クイズです。AP教材の公開承認・習熟判定は含みません。</p>${topics(ctx.state()).map(t=>`<p>${ctx.escape(t.key)}：${t.correct}/${t.total} 正解（${Math.round(t.accuracy*100)}%） ${t.weak?'<strong>観測された弱点候補</strong>':'集計中'}</p>`).join('')||'<p>演習すると、分野ごとの結果がここに反映されます。</p>'}<p id="next-question">次の演習候補：${ctx.escape(nextQuestion(ctx.state()).prompt)}<br><small>未回答の問題を優先し、同じ出題数なら観測された弱点分野を優先します。</small></p>`;
  if(s){const a=ctx.state().practice_attempts.find(a=>a.session_id===s.id);const q=s.question;
   panel.innerHTML+=`<hr><h3>${ctx.escape(q.prompt)}</h3><p>${ctx.state().practice_attempts.some(a=>a.question_id===q.id&&a.session_id!==s.id)?'同じ問題への再回答です。直後の正解だけで弱点解消やTask完了とは判断しません。':'別の問題による確認です。'}</p>${a?`<p class="${a.correct?'good':'error'}"><strong>${a.correct?'正解':'誤答'}</strong> ／ 選んだ回答：${ctx.escape(q.choices[a.answer])}</p><p>正答：${ctx.escape(q.choices[q.answer])}</p><p>${ctx.escape(q.explanation)}</p><button id="next-practice">次の1問へ</button>`:`<form id="answer"><fieldset><legend>回答を1つ選んでください</legend>${Object.entries(q.choices).map(([key,label])=>`<label><input type="radio" name="answer" value="${key}" ${s.draft_answers[q.id]===key?'checked':''}>${ctx.escape(label)}</label>`).join('')}</fieldset><button ${s.draft_answers[q.id]?'':'disabled'}>回答を確定して採点</button><p id="draft-status" role="status">${s.draft_answers[q.id]?'回答を保存済み':'選択すると端末内に自動保存します。'}</p></form>`}`;
   if(a)panel.querySelector('#next-practice').onclick=()=>ctx.act(state=>{current=startPractice(state,s.task_id).id});
   else{const form=panel.querySelector('form');form.onchange=async()=>{
     const value=new FormData(form).get('answer');form.querySelector('button').disabled=true;form.querySelectorAll('input').forEach(e=>e.disabled=true);form.querySelector('#draft-status').textContent='回答を保存しています…';
     const okay=await ctx.act(state=>draftAnswer(state,s.id,value),()=>{},false);
     form.querySelectorAll('input').forEach(e=>e.disabled=false);form.querySelector('button').disabled=!okay;form.querySelector('#draft-status').textContent=okay?'回答を保存済み':'保存できませんでした。確定せず、入力と保存状態を確認してください。';
   };form.onsubmit=e=>{e.preventDefault();ctx.act(state=>submitPractice(state,s.id))};}
  }
  panel.insertAdjacentHTML('beforeend',`<h3>学習履歴</h3>${ctx.state().practice_attempts.filter(a=>sessions.some(s=>s.id===a.session_id)).slice().reverse().map(a=>`<p>${ctx.escape(new Date(a.graded_at).toLocaleString())} ／ ${a.correct?'正解':'誤答'} — ${ctx.escape(sessions.find(s=>s.id===a.session_id).question.prompt)}</p>`).join('')||'<p>確定した回答を記録します。下書きは履歴に含めません。</p>'}`);
  document.querySelector('#extensions').append(panel);
 });
}

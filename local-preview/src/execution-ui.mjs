import * as work from './execution.mjs';
export function install(ctx) {
 let anchor=null,failed=false;
 const session=()=>ctx.state().work_sessions.find(s=>s.status!=='finished');
 const reset=()=>{anchor={perf:performance.now(),wall:Date.now()};failed=false};
 const elapsed=()=>Math.max(0,(performance.now()-anchor.perf)/1000);
 const pulse=async mode=>{
  const s=session();if(!s||s.status!=='running'||failed||ctx.busy())return;
  if(!anchor)reset();const seconds=elapsed(),skew=Math.abs((Date.now()-anchor.wall)/1000-seconds)>5;
  const interrupted=skew||seconds>15||(document.hidden&&mode==='running');const safe=interrupted?0:seconds;
  const okay=await ctx.act(state=>work.checkpoint(state,s.id,safe,interrupted?'paused':mode),reset,mode!=='running');
  if(!okay)failed=true;
  if(interrupted)ctx.notice('時計や離席を検出したため一時停止しました。確認できない時間は加算していません。',true);
 };
 ctx.extensions.push(p=>{
  const s=session();if(s?.status==='running'&&!anchor)reset();if(!s||s.status!=='running')anchor=null;
  if(p){const a=work.progress(ctx.state(),p.id),next=work.recommend(ctx.state(),p.id);
   document.querySelector('#insights').innerHTML=`<div class="card"><strong>Planの進捗 ${Math.round(a.percent)}%</strong><progress max="100" value="${a.percent}"></progress><p>残作業 ${a.remaining}分 ／ 記録した時間 ${a.actual.toFixed(1)}分</p><p>${next?`次の候補：${ctx.escape(next.task.title)}<br><small>${ctx.escape(next.reason)}</small>`:'すべてのTaskを終えました。次の目標を考えましょう。'}</p></div>`;
  }
  document.querySelectorAll('.task-tools').forEach(el=>{
   const t=ctx.state().tasks.find(t=>t.id===el.dataset.task);
   if(!s&&!['done','cancelled'].includes(t.status)){el.innerHTML='<button data-work-start>作業を始める</button>';el.querySelector('button').onclick=()=>ctx.act(state=>work.startWork(state,t.id),reset)}
  });
  if(s){const t=ctx.state().tasks.find(t=>t.id===s.task_id);const card=document.createElement('section');card.className='card';card.id='active-work';card.innerHTML=`<h2>作業中：${ctx.escape(t.title)}</h2><p class="timer" aria-label="記録した作業時間" id="elapsed">${Math.floor(s.active_seconds)}秒</p><p>${s.status==='paused'?'一時停止中。再開は自分で確認してください。':'作業中。離席・復帰時は安全のため一時停止します。'}</p><div class="actions"><button id="work-toggle">${s.status==='paused'?'作業を再開':'一時停止'}</button><button id="work-finish" class="secondary">作業を終了して記録</button></div><p class="muted">時間だけではTaskの進捗を変えません。確認した進捗はTask編集から保存してください。</p>`;
   document.querySelector('#extensions').append(card);
   card.querySelector('#work-toggle').onclick=()=>s.status==='running'?pulse('paused'):ctx.act(state=>work.resumeWork(state,s.id),reset);
   card.querySelector('#work-finish').onclick=()=>s.status==='running'?pulse('finished'):ctx.act(state=>work.finishWork(state,s.id));
  }
  if(p){const card=document.createElement('section');card.className='card';card.innerHTML=`<h2>実績のTimeline</h2>${ctx.state().work_logs.filter(l=>l.plan_id===p.id).slice().reverse().map(l=>`<p>${ctx.escape(l.worked_on)} ／ ${l.actual_minutes.toFixed(1)}分 — ${ctx.escape(ctx.state().tasks.find(t=>t.id===l.task_id)?.title)}</p>`).join('')||'<p>最初の実績をここに残しましょう。</p>'}`;document.querySelector('#extensions').append(card)}
 });
 setInterval(()=>{
  const s=session();if(s?.status==='running'&&anchor&&!failed){const el=document.querySelector('#elapsed');if(el)el.textContent=`${Math.floor(s.active_seconds+Math.min(elapsed(),15))}秒`;if(elapsed()>=5)pulse('running')}
 },500);
 document.addEventListener('visibilitychange',()=>{if(document.hidden)pulse('paused')});
 window.addEventListener('pagehide',()=>{failed=true}); // no unreliable async save promise on page close
}

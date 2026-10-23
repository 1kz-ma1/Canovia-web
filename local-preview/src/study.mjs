import {record,requireValue} from './model.mjs';
import {pack} from './validation-pack.mjs';
export function topics(state) {
 const totals=new Map();
 for(const a of state.practice_attempts)for(const key of a.topic_keys){const v=totals.get(key)||{key,correct:0,total:0};v.total++;if(a.correct)v.correct++;totals.set(key,v)}
 return [...totals.values()].map(t=>({...t,accuracy:t.correct/t.total,weak:t.total>=2&&t.correct/t.total<.7})).sort((a,b)=>a.accuracy-b.accuracy||a.key.localeCompare(b.key));
}
export function nextQuestion(state) {
 const weak=topics(state).find(t=>t.weak)?.key;const seen=id=>state.practice_attempts.filter(a=>a.question_id===id).length;
 // Prefer unseen questions first; a seen same-question retry is explicitly labelled.
 return pack.questions.slice().sort((a,b)=>seen(a.id)-seen(b.id)||(a.topic===weak?-1:0)-(b.topic===weak?-1:0)||a.id.localeCompare(b.id))[0];
}
export function startPractice(state,taskId,question=nextQuestion(state)) {
 const t=state.tasks.find(t=>t.id===taskId);requireValue(t&&state.plans.find(p=>p.id===t.plan_id)?.domain==='study'&&!['done','cancelled'].includes(t.status),'TASK_UNAVAILABLE');
 const draft=state.practice_sessions.find(s=>s.task_id===taskId&&s.status==='draft');if(draft)return draft;
 requireValue(pack.questions.some(q=>JSON.stringify(q)===JSON.stringify(question)),'UNKNOWN_QUESTION');
 const s=record(state,{plan_id:t.plan_id,task_id:t.id,pack_id:pack.id,pack_version:pack.version,question_ids:[question.id],question:structuredClone(question),draft_answers:{},status:'draft',submitted_at:null});state.practice_sessions.push(s);return s;
}
export function draftAnswer(state,id,value) {
 const s=state.practice_sessions.find(s=>s.id===id);requireValue(s&&s.status==='draft'&&Object.hasOwn(s.question.choices,value),'INVALID_ANSWER');
 s.draft_answers={[s.question.id]:value};s.updated_at=new Date().toISOString();
}
export function submitPractice(state,id,now=new Date().toISOString()) {
 const s=state.practice_sessions.find(s=>s.id===id);requireValue(s&&s.status==='draft','ALREADY_GRADED');
 const answer=s.draft_answers[s.question.id];requireValue(Object.hasOwn(s.question.choices,answer),'ANSWER_REQUIRED');
 requireValue(!state.practice_attempts.some(a=>a.session_id===id),'ALREADY_GRADED');
 s.status='submitted';s.submitted_at=now;s.updated_at=now;
 const a=record(state,{session_id:s.id,question_id:s.question.id,answer,correct:answer===s.question.answer,topic_keys:[s.question.topic],graded_at:now,pack_version:s.pack_version},now);state.practice_attempts.push(a);return a;
}

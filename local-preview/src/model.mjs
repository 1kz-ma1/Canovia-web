export const VERSION = 1;
export const TABLES = ['profiles', 'plans', 'tasks', 'work_sessions', 'work_logs', 'practice_sessions', 'practice_attempts'];
export const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
export class LocalError extends Error { constructor(code) { super(code); this.name = 'LocalError'; } }
export function requireValue(ok, code = 'INVALID_DATA') { if (!ok) throw new LocalError(code); }
export const text = (v, max = 2000) => typeof v === 'string' && v.length <= max && !/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/.test(v);
export const number = (v, max = 1e7) => Number.isFinite(v) && v >= 0 && v <= max;
export const date = v => typeof v === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v) && Number.isFinite(Date.parse(v + 'T00:00:00Z')) && new Date(v + 'T00:00:00Z').toISOString().slice(0, 10) === v;
export const localDay = (value = new Date()) => `${value.getFullYear()}-${String(value.getMonth()+1).padStart(2,'0')}-${String(value.getDate()).padStart(2,'0')}`;
export const instant = v => typeof v === 'string' && /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/.test(v) && Number.isFinite(Date.parse(v)) && new Date(v).toISOString() === v;
export function emptyState() {
  requireValue(globalThis.crypto?.randomUUID, 'SECURE_CONTEXT_REQUIRED');
  return {schema_version: VERSION, installation_id: crypto.randomUUID(), revision: 0, import_receipts: [], ...Object.fromEntries(TABLES.map(t => [t, []]))};
}
export function record(state, values, now = new Date().toISOString()) {
  return {...values, id: crypto.randomUUID(), source_installation_id: state.installation_id, schema_version: VERSION, created_at: now, updated_at: now};
}
export function validate(state) {
  requireValue(state && Object.getPrototypeOf(state) === Object.prototype);
  requireValue(Object.keys(state).sort().join() === ['schema_version', 'installation_id', 'revision', 'import_receipts', ...TABLES].sort().join());
  requireValue(state.schema_version === VERSION, 'UNSUPPORTED_SCHEMA');
  requireValue(UUID.test(state.installation_id) && Number.isSafeInteger(state.revision) && state.revision >= 0);
  requireValue(Array.isArray(state.import_receipts) && state.import_receipts.length <= 1000 && new Set(state.import_receipts).size === state.import_receipts.length && state.import_receipts.every(x => /^[a-f0-9]{64}$/.test(x)));
  const common=['id','source_installation_id','schema_version','created_at','updated_at'];
  const fields={profiles:['domains','goal','current_state','weekly_capacity','setup_version'],plans:['domain','title','description','start_date','deadline','priority'],tasks:['plan_id','title','description','status','estimated_minutes','remaining_minutes','progress_percent','priority','sort_order'],work_sessions:['plan_id','task_id','status','started_at','ended_at','paused_at','active_seconds','last_checkpoint_at'],work_logs:['session_id','plan_id','task_id','worked_on','actual_seconds','actual_minutes','memo','progress_before','progress_after'],practice_sessions:['plan_id','task_id','pack_id','pack_version','question_ids','question','draft_answers','status','submitted_at'],practice_attempts:['session_id','question_id','answer','correct','topic_keys','graded_at','pack_version']};
  const ids = new Set(); let count = 0;
  for (const table of TABLES) {
    requireValue(Array.isArray(state[table]) && state[table].length <= 10000);
    for (const row of state[table]) {
      requireValue(row && Object.getPrototypeOf(row) === Object.prototype && UUID.test(row.id) && UUID.test(row.source_installation_id) && !ids.has(row.id)); ids.add(row.id);
      requireValue(Object.keys(row).sort().join()===[...common,...fields[table]].sort().join());
      requireValue(row.schema_version === VERSION && instant(row.created_at) && instant(row.updated_at) && row.created_at <= row.updated_at);
      requireValue(JSON.stringify(row).length <= 32000); count++;
    }
  }
  requireValue(count <= 20000 && state.profiles.length <= 1);
  for (const p of state.profiles) requireValue(Array.isArray(p.domains) && p.domains.length >= 1 && p.domains.length <= 2 && p.domains.every(d => ['study','development'].includes(d)) && text(p.goal,120) && p.goal.trim() && text(p.current_state) && number(p.weekly_capacity,10080) && p.setup_version === 1);
  const plans = new Map(state.plans.map(x => [x.id, x]));
  const tasks = new Map(state.tasks.map(x => [x.id, x]));
  for (const p of state.plans) requireValue(['study', 'development'].includes(p.domain) && text(p.title, 120) && p.title.trim() && text(p.description) && date(p.start_date) && (p.deadline === null || date(p.deadline) && p.deadline >= p.start_date) && number(p.priority, 3));
  for (const t of state.tasks) requireValue(plans.has(t.plan_id) && text(t.title, 120) && t.title.trim() && text(t.description) && ['todo', 'doing', 'done', 'cancelled'].includes(t.status) && number(t.estimated_minutes, 100000) && number(t.remaining_minutes, 100000) && number(t.progress_percent, 100) && number(t.priority, 3) && Number.isSafeInteger(t.sort_order) && t.sort_order >= 0 && (t.status !== 'done' || t.remaining_minutes === 0 && t.progress_percent === 100));
  for (const row of [...state.work_sessions, ...state.work_logs, ...state.practice_sessions]) requireValue(tasks.has(row.task_id) && tasks.get(row.task_id).plan_id === row.plan_id, 'BROKEN_REFERENCE');
  const sessions = new Map(state.work_sessions.map(s => [s.id,s]));
  requireValue(state.work_sessions.filter(s => s.status !== 'finished').length <= 1);
  for (const s of state.work_sessions) requireValue(['running','paused','finished'].includes(s.status) && instant(s.started_at) && instant(s.last_checkpoint_at) && s.started_at <= s.last_checkpoint_at && number(s.active_seconds,31536000) && (s.status === 'finished' ? instant(s.ended_at) && s.ended_at >= s.last_checkpoint_at : s.ended_at === null) && (s.paused_at === null || instant(s.paused_at)));
  const logged = new Set();
  for (const l of state.work_logs) {const session=sessions.get(l.session_id);requireValue(session && session.status === 'finished' && session.task_id === l.task_id && session.plan_id === l.plan_id && !logged.has(l.session_id) && date(l.worked_on) && number(l.actual_seconds,31536000) && l.actual_seconds === session.active_seconds && l.actual_minutes === l.actual_seconds/60 && text(l.memo) && number(l.progress_before,100) && number(l.progress_after,100));logged.add(l.session_id);}
  requireValue(state.work_sessions.filter(s => s.status === 'finished').every(s => logged.has(s.id)));
  const practice = new Map(state.practice_sessions.map(s=>[s.id,s]));
  for(const s of state.practice_sessions) {
    const q=s.question;requireValue(plans.get(s.plan_id).domain==='study' && ['draft','submitted'].includes(s.status) && text(s.pack_id,120) && Number.isSafeInteger(s.pack_version) && s.pack_version>0 && Array.isArray(s.question_ids) && s.question_ids.length===1 && q && text(q.id,120) && q.id===s.question_ids[0] && text(q.prompt,4000) && q.prompt.trim() && text(q.topic,120) && text(q.explanation,4000) && q.choices && Object.getPrototypeOf(q.choices)===Object.prototype && Object.keys(q.choices).length>=2 && Object.keys(q.choices).length<=8 && Object.entries(q.choices).every(([k,v])=>/^[a-z]$/.test(k)&&text(v,2000)) && Object.hasOwn(q.choices,q.answer));
    requireValue(s.draft_answers && Object.getPrototypeOf(s.draft_answers)===Object.prototype && Object.keys(s.draft_answers).length<=1 && Object.entries(s.draft_answers).every(([k,v])=>k===q.id&&Object.hasOwn(q.choices,v)) && (s.status==='draft'?s.submitted_at===null:instant(s.submitted_at)));
  }
  const graded=new Set();
  for(const a of state.practice_attempts){const s=practice.get(a.session_id);requireValue(s && s.status==='submitted' && !graded.has(s.id) && a.question_id===s.question.id && a.pack_version===s.pack_version && Object.hasOwn(s.question.choices,a.answer) && s.draft_answers[a.question_id]===a.answer && a.correct===(a.answer===s.question.answer) && Array.isArray(a.topic_keys) && a.topic_keys.length===1 && a.topic_keys[0]===s.question.topic && instant(a.graded_at) && a.graded_at===s.submitted_at);graded.add(s.id);}
  requireValue(state.practice_sessions.filter(s=>s.status==='submitted').every(s=>graded.has(s.id)));
  requireValue(new TextEncoder().encode(JSON.stringify(state)).length<=6*1024*1024,'STORAGE_LIMIT');
  return state;
}

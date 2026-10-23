export const VERSION = 1;
export const TABLES = ['profiles', 'plans', 'tasks', 'work_sessions', 'work_logs', 'practice_sessions', 'practice_attempts'];
export const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
export class LocalError extends Error { constructor(code) { super(code); this.name = 'LocalError'; } }
export function requireValue(ok, code = 'INVALID_DATA') { if (!ok) throw new LocalError(code); }
export const text = (v, max = 2000) => typeof v === 'string' && v.length <= max && !/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/.test(v);
export const number = (v, max = 1e7) => Number.isFinite(v) && v >= 0 && v <= max;
export const date = v => typeof v === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v) && Number.isFinite(Date.parse(v + 'T00:00:00Z')) && new Date(v + 'T00:00:00Z').toISOString().slice(0, 10) === v;
export const localDay = (value = new Date()) => `${value.getFullYear()}-${String(value.getMonth()+1).padStart(2,'0')}-${String(value.getDate()).padStart(2,'0')}`;
export const instant = v => typeof v === 'string' && /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/.test(v) && Number.isFinite(Date.parse(v));
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
  const ids = new Set(); let count = 0;
  for (const table of TABLES) {
    requireValue(Array.isArray(state[table]) && state[table].length <= 10000);
    for (const row of state[table]) {
      requireValue(row && Object.getPrototypeOf(row) === Object.prototype && UUID.test(row.id) && UUID.test(row.source_installation_id) && !ids.has(row.id)); ids.add(row.id);
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
  return state;
}

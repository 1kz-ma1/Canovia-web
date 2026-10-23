import {record,requireValue,localDay} from './model.mjs';
export function createPlan(state, values) {
  const p=record(state,{title:values.title.trim(),description:values.description||'',domain:values.domain,start_date:values.start_date,deadline:values.deadline||null,priority:values.priority??1});
  state.plans.push(p);return p;
}
export function createTask(state, values) {
  requireValue(state.plans.some(p=>p.id===values.plan_id),'BROKEN_REFERENCE');
  const task=record(state,{plan_id:values.plan_id,title:values.title.trim(),description:values.description||'',estimated_minutes:Number(values.estimated_minutes),remaining_minutes:Number(values.estimated_minutes),progress_percent:0,status:'todo',priority:values.priority??1,sort_order:state.tasks.filter(t=>t.plan_id===values.plan_id).length});
  state.tasks.push(task);return task;
}
export function updatePlan(state,id,values) {
  const p=state.plans.find(p=>p.id===id);requireValue(p,'NOT_FOUND');
  for(const key of ['title','description','deadline','priority'])if(Object.hasOwn(values,key))p[key]=values[key];
  p.updated_at=new Date().toISOString();
}
export function updateTask(state,id,values) {
  const task=state.tasks.find(t=>t.id===id);requireValue(task,'NOT_FOUND');
  for(const key of ['title','description','priority','status','progress_percent','remaining_minutes','estimated_minutes'])if(Object.hasOwn(values,key))task[key]=values[key];
  if(task.status==='done'){task.progress_percent=100;task.remaining_minutes=0}
  task.updated_at=new Date().toISOString();
}
export function deleteTask(state,id) {
  requireValue(state.tasks.some(t=>t.id===id),'NOT_FOUND');
  requireValue(![...state.work_sessions,...state.work_logs,...state.practice_sessions].some(x=>x.task_id===id),'HAS_HISTORY');
  state.tasks=state.tasks.filter(t=>t.id!==id);
}
export function deletePlan(state,id) {
  requireValue(!state.tasks.some(t=>t.plan_id===id),'HAS_TASKS');
  requireValue(state.plans.some(p=>p.id===id),'NOT_FOUND');state.plans=state.plans.filter(p=>p.id!==id);
}
export function diagnose(state,values) {
  requireValue(!state.profiles.length,'ALREADY_SET_UP');
  const now=new Date().toISOString();const domain=values.domain;
  requireValue(['study','development'].includes(domain));
  state.profiles.push(record(state,{domains:[domain],goal:values.goal,current_state:values.current_state,weekly_capacity:Number(values.weekly_capacity),setup_version:1},now));
  const p=createPlan(state,{title:values.goal,description:values.current_state,domain,start_date:localDay(),deadline:values.deadline});
  const seeds=domain==='study'?['現在地を確かめる演習','結果を振り返り、次の学習を決める']:['作りたいものと完了条件を整理する','小さな変更を実装・検証する'];
  seeds.forEach(title=>createTask(state,{plan_id:p.id,title,estimated_minutes:25}));return p;
}

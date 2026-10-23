import {test} from 'node:test';import assert from 'node:assert/strict';
import {emptyState,validate} from '../src/model.mjs';import {diagnose} from '../src/planning.mjs';import {pack} from '../src/validation-pack.mjs';import {startPractice,draftAnswer,submitPractice,topics,nextQuestion} from '../src/study.mjs';
const setup=()=>{const s=emptyState();diagnose(s,{domain:'study',goal:'基本',current_state:'',weekly_capacity:100});return s};
test('draft is not an attempt; exact choice grade from immutable snapshot once, no task progress inference',()=>{
 const s=setup(),r=startPractice(s,s.tasks[0].id);assert.throws(()=>submitPractice(s,r.id));assert.throws(()=>draftAnswer(s,r.id,'bad'));draftAnswer(s,r.id,r.question.answer);validate(s);assert.equal(s.practice_attempts.length,0);submitPractice(s,r.id);validate(s);assert.equal(s.practice_attempts[0].correct,true);assert.throws(()=>submitPractice(s,r.id));assert.equal(s.tasks[0].progress_percent,0);
});
test('multiple observations update topic counts and choose unseen weakness questions, not an invented cause',()=>{
 const s=setup();for(const q of pack.questions.filter(q=>q.topic==='数の基礎').slice(0,2)){const r=startPractice(s,s.tasks[0].id,q);draftAnswer(s,r.id,Object.keys(q.choices).find(k=>k!==q.answer));submitPractice(s,r.id)}validate(s);assert.equal(topics(s)[0].total,2);assert.equal(topics(s)[0].weak,true);assert.equal(nextQuestion(s).id,'binary-four');
});
test('imported or corrupted assessment contradiction is rejected',()=>{const s=setup(),r=startPractice(s,s.tasks[0].id);draftAnswer(s,r.id,r.question.answer);submitPractice(s,r.id);s.practice_attempts[0].correct=false;assert.throws(()=>validate(s))});

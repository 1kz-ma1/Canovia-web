# TEMP V58.10 Study Language Activities

Status: implementation in progress  
Branch: `feature/v58-10-study-language-activities`

## Goal

Add real executable Study Activities for language-listening Tasks without
introducing speculative speech recognition or automatic pronunciation scoring.

Implemented Activity types:

- Listening
- Dictation
- Shadowing

```text
Task semantics
→ Study Activity Policy
→ state-aware Study Method Recommendation
→ dedicated language Activity surface
→ open existing study Resource
→ learner performs the Activity
→ explicit self-report
→ Task Evidence
```

## Activity keys

```text
listening
dictation
shadowing
```

They are first-class Study Activity keys alongside:

- question_practice
- recall
- resource_study

## Semantic routing

### Listening

Strong terms include:

- リスニング
- listening
- 聴解
- 聞き取り
- 聞き取る
- 音声を聞く

### Dictation

Strong terms include:

- ディクテーション
- dictation
- 書き取り
- 聞き取って書く
- 音声を書き取る

### Shadowing

Strong terms include:

- シャドーイング
- shadowing
- 音声に続いて
- 復唱
- 発音練習

Explicit question-practice intent such as 問題演習 / 過去問 / 模試 still
wins when the Task is actually a scored listening question Task.

Example:

- "TOEICリスニング問題演習" → Question Practice
- "TOEIC音声を聞いて内容を理解する" → Listening
- "英文をディクテーションする" → Dictation
- "英語音声をシャドーイングする" → Shadowing

## State-aware recommendation

`StudyMethodRecommendationService` must preserve a language Activity chosen by
the Task-semantic policy unless a stronger existing state rule applies first
(for example resume continuity or school Scope organization).

Language Activities are not silently collapsed back to Question Practice.

## Execution capability

Add:

`study.language`

with native provider:

`canovia.study.language`

Listening / Dictation / Shadowing all resolve to this capability.

No external provider integration is added.

## Dedicated execution surface

New route family:

```text
GET  /plans/{plan}/tasks/{task}/study-language/{activity}
POST /plans/{plan}/tasks/{task}/study-language/{activity}
```

The surface is server-rendered and provider-free.

It shows:

- selected Activity
- Activity-specific steps
- existing Task/Plan Resources
- explicit self-report form
- recent same-type Activity Evidence

No audio is uploaded to Canovia.

No microphone permission is requested.

## Activity guidance

### Listening

1. first listen without transcript / answer
2. identify main meaning and missed sections
3. replay only difficult sections
4. optionally compare with transcript/material

### Dictation

1. listen to a short segment
2. write exactly what was heard
3. compare against transcript/material
4. identify sound/word boundaries that caused misses

### Shadowing

1. listen once for rhythm / stress
2. repeat immediately after the audio
3. replay short difficult chunks
4. repeat until timing feels stable

## Evidence

Explicit completion records:

type:
`study_language_activity_completed`

known metadata:

- activity_type
- rounds
- outcome_rating
- resource_count

optional raw user reflection may remain in TaskEvidence metadata but is not
normalized into Intelligence facts.

Outcome rating:

- struggled
- partial
- comfortable

Evidence confidence:

`0.6`

This is a self-reported execution observation, weaker than objective Question
Practice assessment.

Stable idempotency key:

`study-language:{activity}:{request_uuid}`

## Progress boundary

Completing a language Activity does not automatically:

- increase Task progress
- complete the Task
- change remaining minutes
- change Study Practice mastery
- change Recall mastery

It only records that the learner performed the Activity and how it felt.

## TaskEvidenceAdapter

Normalize only:

- activity_type
- rounds
- outcome_rating

Do not normalize free-text reflection.

## Timeline / Evidence readability

`TaskEvidence::typeLabel()` and `summary()` should render the language
Activity Evidence as a human-readable Study event.

## Resource boundary

The surface can open existing PlanResource URLs in the browser.

Canovia does not server-fetch or parse those URLs in V58.10.

This preserves the current Resource security boundary.

## Free path

Language Activities are native non-AI Study execution.

They do not require Automatic AI or AI Practice entitlement.

## Boundaries

V58.10 does not:

- add speech recognition
- record microphone audio
- score pronunciation
- automatically grade dictation text
- call AI/provider
- fetch remote Resource content
- auto-complete Task progress
- add a migration
- change billing

## Validation

Required:

- pure Listening Task prefers Listening
- listening question exercise keeps Question Practice
- Dictation Task prefers Dictation
- Shadowing Task prefers Shadowing
- StudyMethodRecommendation preserves language primary method
- Study Activity page links to dedicated language surface
- dedicated surface renders correct steps and Resources
- explicit completion records one idempotent Evidence
- Evidence confidence remains 0.6
- Task progress is unchanged
- TaskEvidenceAdapter normalizes known language facts only
- manual reflection does not cross normalized Intelligence boundary
- ExecutionCapabilityResolver maps all three to study.language
- ExecutionProviderRegistry exposes canovia.study.language
- no AI/provider traffic occurs
- existing V41.10 Activity Policy regression remains green
- V56.15 Study Method Recommendation regression remains green
- V55.6 Execution Ecosystem regression remains green
- V58.6 Recall progression regression remains green

## Completion

After validation:

1. promote to `docs/V58.10_STUDY_LANGUAGE_ACTIVITIES.md`
2. update Product Spec / V41.10 follow-up
3. retire TEMP to pointer
4. remove temporary validation workflow
5. create PR to `main`
6. stop before merge

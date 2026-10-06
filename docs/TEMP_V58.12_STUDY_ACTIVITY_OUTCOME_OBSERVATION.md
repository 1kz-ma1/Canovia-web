# TEMP V58.12 Study Activity Outcome Observation

Status: implementation in progress  
Branch: `feature/v58-12-study-activity-outcome-observation`

## Goal

Use real Study execution data to observe what happened after each Activity
without claiming causal effectiveness and without automatically changing Study
Method Recommendation.

```text
Practice assessment
→ one Activity type
→ next Practice assessment
→ before / after score observation
→ descriptive Activity outcome
```

V58.12 is an observation layer.

It is not an experiment engine and not a causal learner.

## Supported Activity keys

Observed:

- question_practice
- recall
- listening
- dictation
- shadowing

Resource Study is shown as unmeasured until Canovia has an explicit completion
Evidence for Resource Study itself.

## Actor boundary

Observation is scoped to the current Task and current actor:

- logged-in user → `user_id`
- anonymous actor → `actor_token`

Evidence from another user / actor must never enter the comparison.

## Evidence sources

Objective assessment boundary:

- `study_practice_assessed`
  - requires integer `score_percent`

Tracked intervening Activity Evidence:

- `study_recall_reviewed` → recall
- `study_language_activity_completed`
  - listening
  - dictation
  - shadowing

Question Practice observation is represented by two consecutive Practice
assessments with no tracked non-Practice Activity between them.

## Pairing rule

For every adjacent pair of valid Practice assessments:

1. previous Practice score is the baseline;
2. next Practice score is the outcome;
3. inspect tracked Activity Evidence strictly between the two assessments;
4. if none exists → `question_practice`;
5. if every tracked Activity maps to one same key → that Activity key;
6. if multiple Activity keys are mixed → exclude from per-Activity comparison;
7. if the time gap exceeds 14 days → exclude as too temporally distant.

Multiple Recall card reviews between assessments are treated as one Recall
Activity interval, not multiple score observations.

Multiple same-type Language Activity completions are also one interval.

## Metrics

For each Activity:

- usage_count
- observation_count
- average_before_score
- average_after_score
- average_score_delta
- latest_before_score
- latest_after_score
- latest_score_delta
- latest_observed_at

Task-level:

- total valid observation pairs
- ambiguous mixed-Activity intervals
- stale intervals over 14 days
- practice assessment count

## Important interpretation

A positive score delta after an Activity does not prove that Activity caused the
improvement.

Confounders include:

- question difficulty
- topic mix
- outside study
- elapsed time
- prior knowledge
- sleep / attention
- unrecorded study

Therefore UI wording must use:

- "観測"
- "前後差"
- "後続Practice"

and must not use:

- "効果が証明された"
- "このActivityが原因"
- "最適化済み"

## Recommendation boundary

V58.12 does not change:

- `StudyActivityPolicyService`
- `StudyMethodRecommendationService` scoring
- primary Activity selection
- Task progress
- mastery
- Practice Reliability score

The observation is visible evidence only.

A future policy may use it only after a separate reliability / causal design is
defined.

## UI

Study Activity page adds a collapsed section:

`実利用でのActivity観測`

It shows:

- Practice assessment count
- valid before/after pair count
- mixed intervals excluded
- per-Activity usage / paired observations
- average before → after
- average delta when available
- explicit "causal proofではない" explanation

If fewer than 2 Practice assessments exist, show an "観測待ち" state instead
of fabricating a comparison.

## No migration

All required facts already exist in Task Evidence.

## No provider call

Projection is DB-only and deterministic.

Opening Study Activity must not invoke AI/provider because of V58.12.

## Validation

Required:

- adjacent Practice assessments with no intervening Activity map to Question Practice
- Recall reviews between Practice assessments map to one Recall observation
- multiple same-type Language events remain one score observation
- mixed Recall + Language interval is excluded
- interval over 14 days is excluded
- actor data is isolated
- invalid / missing Practice score is ignored
- average and latest deltas are deterministic
- Resource Study remains unmeasured
- Study Activity UI shows observation panel
- fewer than 2 Practice assessments shows waiting state
- projection performs no provider call
- Study Method Recommendation output is unchanged by observation data
- V56.15 recommendation regression remains green
- V58.10 language Activity regression remains green
- V58.6 Recall progression regression remains green

## Completion

After validation:

1. promote to `docs/V58.12_STUDY_ACTIVITY_OUTCOME_OBSERVATION.md`
2. update Product Spec / V41.10 / V41.11 follow-up
3. retire TEMP to pointer
4. remove temporary validation workflow
5. create PR to `main`
6. stop before merge

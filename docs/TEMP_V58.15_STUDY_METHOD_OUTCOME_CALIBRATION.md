# TEMP V58.15 Study Method Outcome Calibration

Status: implementation in progress  
Branch: `feature/v58-15-study-method-outcome-calibration`

## Goal

Use V58.12 descriptive Activity outcome observations as a bounded secondary
calibration signal for Study Method fit without claiming causal effectiveness
and without automatically switching the Primary Method.

```text
Task semantic fit
+ current Study state
+ V56.15 deterministic priority
+ sufficiently repeated explicit Activity observations
→ bounded method-fit calibration
→ same Primary Method
→ calibrated alternative ranking / explanation
```

## Causal boundary

V58.15 does NOT interpret:

`after Activity score improved`

as:

`Activity caused the improvement`

Observation may still contain:

- question difficulty changes;
- topic mix;
- outside study;
- sleep / attention;
- elapsed time;
- unrecorded study.

The calibration is therefore a small personalization hint, not an effectiveness
estimate.

## Eligible methods

Calibration applies only to explicit non-Practice Activities:

- recall
- resource_study
- listening
- dictation
- shadowing

Question Practice is excluded.

V58.12 classifies two adjacent Practice assessments with no tracked Activity in
between as `question_practice`. That absence does not prove that only Practice
occurred, so it is not safe enough for method calibration.

Scope Organization and Practical Evidence are also excluded because V58.12 does
not observe them.

## Scope

Signal is derived only from V58.12 observations for:

- same Plan;
- same Task;
- same actor.

No cross-Task, cross-Plan or population learning is introduced.

## Minimum sample

For each eligible method:

- fewer than 3 valid observation intervals → `observing`, adjustment 0;
- 3 or more → evaluate the latest at most 5 intervals.

This intentionally starts conservatively.

## Robust center

For the selected latest sample:

- compute median `score_delta`;
- do not use raw average as the calibration center.

Median reduces the influence of one unusually easy/hard follow-up Practice.

## Meaningful direction

Per interval:

- delta >= +5 → positive;
- delta <= -5 → negative;
- otherwise → neutral.

Determine the dominant direction.

Require dominant meaningful-direction ratio >= 2/3.

If not, status is `mixed` and adjustment = 0.

## Adjustment

If the median absolute delta is < 5pt:

- status = `neutral`;
- adjustment = 0.

Otherwise:

```text
raw_adjustment = round(median_delta / 5)
clamp raw to -5..+5

sample_strength = min(1, observation_count / 5)

applied_adjustment =
  round(raw_adjustment * sample_strength)

clamp applied to -5..+5
```

The direction-consistency requirement is a gate, not an additional multiplier.

Examples:

```text
3 observations
+10 / +15 / +20
median +15
raw +3
strength 0.6
applied +2
```

```text
5 observations
+10 / +15 / +20 / +15 / +10
median +15
raw +3
strength 1.0
applied +3
```

```text
3 observations
+15 / -10 / +1
mixed direction
→ 0
```

## Recommendation authority

V58.15 does not change the selected `primaryKey`.

V56.15 priority remains authoritative:

- resume continuity;
- Scope gates;
- memorization Recall;
- skill Practical Evidence;
- Exam Mode;
- adaptive Recall;
- repeated knowledge/concept gap;
- retention due;
- Task-semantic fit.

Calibration is applied only to displayed/ranked `fit_score` after deterministic
state scoring.

Therefore:

- the existing Primary Method remains Primary;
- alternatives can reorder based on bounded personal observation;
- the Primary score can receive a bounded negative calibration for transparency
  but is not automatically replaced.

A future Primary auto-switch requires a separate policy.

## Method output

Each method should expose:

- base_fit_score;
- fit_score;
- outcome_adjustment;
- outcome_signal_status;
- outcome_observation_count;
- outcome_sample_count;
- outcome_median_delta;
- outcome_direction_ratio_percent.

Top-level recommendation output should expose:

`outcome_calibration`

for explanation/debugging.

## UI

Study Method Recommendation:

- show a compact `実利用補正 ±N` badge only when adjustment != 0;
- show a note that Primary is not auto-switched by this signal.

Other Study Methods:

- show outcome calibration badge when non-zero.

Study Activity method-fit list:

- show base → calibrated fit when adjusted;
- explain that calibration is observational, not causal.

Existing V58.12 observation panel remains available.

## No automatic mutation

V58.15 does not modify:

- Task progress;
- Task status;
- Practice Strategy;
- Recall scheduler;
- Practice Reliability;
- mastery;
- Evidence;
- Session state.

## Provider / storage

DB-only, deterministic, read-only.

No provider call.
No AI call.
No migration.

## Validation

Required:

1. no observations → no adjustment
2. 1–2 observations → observing / 0
3. 3 consistent positive observations → bounded positive adjustment
4. 3 consistent negative observations → bounded negative adjustment
5. mixed-direction observations → 0
6. median absolute delta <5 → 0
7. latest maximum 5 observations only
8. adjustment never exceeds ±5
9. Question Practice never receives calibration
10. Primary Method key never changes because of calibration
11. alternatives can reorder after calibration
12. actor isolation remains inherited from V58.12
13. no provider call / no mutation on GET
14. V56.15 recommendation regressions remain green
15. V58.12 observation regressions remain green
16. V58.14 Resource Study regressions remain green
17. V58.10 Language Activity regressions remain green

## Completion

After validation:

1. promote to `docs/V58.15_STUDY_METHOD_OUTCOME_CALIBRATION.md`
2. update Product Spec / V41.10 / V56.15 / V58.12
3. convert TEMP to completion pointer
4. remove temporary validation workflow
5. create PR to `main`
6. stop before merge

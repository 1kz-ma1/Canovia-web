# TEMP V58.16 AP Subject A Coverage Dashboard

Status: implementation in progress  
Branch: `feature/v58-16-ap-subject-a-coverage-dashboard`

## Goal

Provide a Plan-wide AP Subject A coverage / observed-performance dashboard from
real assessed Question Bank activity across Tasks.

```text
AP Subject A Plan
→ assessed Question Bank Sessions across Tasks
→ selected question domain / parent topic
→ per-question feedback correctness
→ official-pack unique coverage
→ Plan-wide domain performance observation
→ Study Analysis surface
```

This dashboard is descriptive. It is not a new mastery engine.

## Scope

V58.16 is enabled only when the Plan can be identified as AP Subject A through
the existing `StudyPracticeExamProfileService`.

No generic exam taxonomy is invented in this phase.

## Actor boundary

Only the current actor is included:

- authenticated → `user_id`
- anonymous → `actor_token`

No cross-user aggregation.

## Session boundary

Use StudyPracticeSession rows for the selected Plan only.

Bounded scan:

- latest maximum 200 Sessions
- Session must have at least one Attempt
- `selected_questions` must contain Question Bank `question_id`
- a selected question must have a non-empty `selection_domain`
- question feedback must match the selected `question_ref`

Native AI / External AI questions with no Question Bank id do not enter the
domain coverage denominator or domain accuracy.

## Official Pack reference

When a published AP / 科目A QuestionPack exists with:

- metadata.content_kind = `official_past_exam_curated`

select the highest `metadata.selection_priority`, then latest id.

The reference Pack provides:

- total question count
- `metadata.source_domain_counts`

Current supported source-domain mapping:

```text
technology → テクノロジ
management → マネジメント
strategy   → ストラテジ
```

If no eligible published official Pack is installed:

- observed Plan-wide performance is still shown
- official Pack coverage percent remains unavailable

## Two distinct metrics

### Official Pack unique coverage

For each domain:

```text
unique assessed questions from reference official Pack
/
reference Pack domain question count
```

Plan-level:

```text
unique assessed reference-Pack questions
/
reference Pack total questions
```

Repeated exposure does not increase unique coverage.

### Plan-wide assessed performance

Across all Question Bank packs used in the Plan, including curated core packs:

- assessed_exposure_count
- correct_count
- partial_count
- incorrect_count
- observed_correct_rate_percent
- unique_question_count
- latest_assessed_at

This allows reinforcement/core questions to inform observed performance without
pretending they increase official Pack coverage.

## Correctness interpretation

Use only question_feedback correctness:

- correct
- partial
- incorrect

Observed correct rate:

```text
correct / (correct + partial + incorrect)
```

Partial is not counted as correct.

This metric is named observed correctness / 正答観測, not mastery.

## Domain status

For display only:

- no graded exposures → `unobserved`
- 1–2 graded exposures → `observing`
- >=3 and correct rate <60 → `needs_attention`
- >=3 and correct rate 60–79 → `developing`
- >=3 and correct rate >=80 → `stable`

These labels do not modify Practice routing or weakness priority.

## Across-Task behavior

The dashboard aggregates all eligible Tasks in the same Plan.

It therefore exposes a Plan-wide weakness observation without replacing the
existing Task-local `StudyWeaknessPrioritizationService`.

No cross-Task weakness is fed back into Practice routing in V58.16.

## Parent-topic detail

Within each broad domain, aggregate `selection_parent_topic` when present.

Show up to 5 parent topics ordered by:

1. needs-attention style lower correct rate, but only after >=2 exposures
2. higher assessed exposure count
3. label

Parent topics with only one observation remain explicitly low-confidence.

## Study Analysis UI

Register a new Study surface:

`ap_subject_a_coverage`

Analysis view order:

- current_state
- readiness
- biggest_gap
- AP Subject A coverage
- weaknesses
- recent_results
- scope_coverage

Surface shows:

- reference Pack title
- official unique coverage X / N and percent when available
- assessed Question Bank exposures
- unique assessed bank questions
- broad domain cards
- official domain coverage
- observed correctness
- current display status
- top parent-topic observations
- note that repeated questions increase exposure but not unique coverage

If no assessed Question Bank evidence exists:

- show an explicit waiting state
- do not invent zero mastery

## Routing / readiness boundary

V58.16 does not change:

- StudyWeaknessPrioritizationService
- StudyPracticeRoutingPolicyService
- StudyExamConvergencePolicyService
- Study Method Recommendation
- V58.15 outcome calibration
- Exam Readiness
- Task progress
- mastery
- Question selection

It is read-only analysis.

## Provider / storage

DB-only, deterministic, read-only.

No AI/provider call.
No migration.

## Validation

Required:

1. non-AP Plan → surface unavailable
2. AP Subject A Plan with no assessed Bank activity → waiting dashboard
3. current actor isolation
4. unassessed selected Session does not count
5. Native AI question with no bank id does not count
6. official repeated question increases exposure but not unique official coverage
7. core-pack question increases observed performance but not official coverage
8. domain coverage uses reference Pack source_domain_counts
9. correctness matches feedback by question_ref
10. partial is not counted as correct
11. domain statuses are deterministic
12. cross-Task Sessions aggregate into one Plan dashboard
13. parent-topic observations aggregate
14. bounded latest 200 Session scan
15. registered Analysis surface renders
16. no provider call
17. no data mutation on GET
18. V56.3 exposure rotation regression remains green
19. V56.4 routing regression remains green
20. V58.15 Study Method calibration regression remains green
21. V58.3 Study Workspace surface regression remains green

## Completion

After validation:

1. promote to `docs/V58.16_AP_SUBJECT_A_COVERAGE_DASHBOARD.md`
2. update Product Spec / V41.4 / Study Workspace spec
3. convert TEMP to completion pointer
4. remove temporary validation workflow
5. create PR to `main`
6. stop before merge

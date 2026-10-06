# TEMP V58.18 Plan-wide Weakness Handoff after 100 Questions

Status: implementation in progress  
Branch: `feature/v58-18-plan-wide-weakness-handoff`

## Goal

Use V58.17 100-question completion plus V58.16 Plan-wide AP observations to add a small, evidence-gated recheck budget to the next broad Practice block.

```text
current Task reaches 100 graded questions
+ AP Subject A Plan-wide Question Bank observations
+ parent topic has repeated weak evidence
→ bounded Plan-wide weakness handoff
→ next broad Practice gets at most 2 recheck questions
→ at least 8/10 remain broad exploration
```

V58.18 does not convert Plan-wide observations into full weakness_reinforcement.

## Eligibility

All conditions are required:

1. selected Plan is AP Subject A
2. current Task cumulative checkpoint has reached >=100 graded questions
3. V58.16 Plan-wide coverage is available
4. candidate is a parent topic with >=3 assessed Question Bank exposures
5. candidate spans >=2 unique Question Bank questions
6. candidate observed correctness <60%
7. current Practice routing task_mode = broad_assessment
8. current deterministic policy phase = general_practice

If any condition fails, no Plan-wide routing effect is applied.

## Candidate source

Reuse V58.16 actor-scoped same-Plan coverage projection.

Candidate fields:
- topic
- domain
- assessed_exposure_count
- observed_correct_rate_percent

Only parent-topic rows are candidates. Broad domains such as テクノロジ are not promoted into focus topics.

Candidate order:
1. lower observed correct rate
2. higher assessed exposure count
3. topic label

Maximum candidate list: 5.

## Local authority first

V56.0 / V56.4 Task-local routing remains authoritative.

Existing `broad_recheck_topics` are kept first.

Plan-wide candidates only fill unused seats in the existing broad-assessment weakness recheck budget.

Current default recheck budget is 2 questions.

Example:

```text
local broad recheck = [DNS]
plan-wide candidates = [Database, Availability]
budget = 2
→ applied = [DNS, Database]
```

Plan-wide Signal cannot displace the local DNS recheck.

## Cooldown / mastery guard

Plan-wide candidates are rejected when the current Task routing policy marks the topic as:
- cooldown
- mastered

This prevents older Plan-wide history from immediately resurrecting a locally stabilized topic.

## Phase guard

Do not apply Plan-wide handoff in:
- diagnosis
- weakness_reinforcement
- exam_mode

Exam Mode remains broad and exam-balanced.

Focused remediation remains Task-local.

## Question mix

When Plan-wide handoff is applied in broad_assessment General Practice:

```text
primary recheck <= 2
secondary retention <= existing limit
diagnostic / broad exploration >= existing minimum
```

V58.18 reuses the existing V56.4 broadRoutingWeakness allocation. No new mix algorithm.

## Provider behavior

Question Bank already consumes `weakness_priority.primary_topics`.

Native / Hybrid / external-AI generation already consumes the same primary topics plus question_mix in StudyPracticePromptService.

Therefore the same bounded recheck topics are provider-neutral.

`focus_topics` remains empty during General Practice.

## Actor boundary

The handoff service receives explicit current actor identity:
- authenticated user_id
- anonymous actor_token

No fallback to another user's Plan history.

## Transparency

Persist the handoff snapshot inside `selection_context.strategy.routing_policy.plan_wide_weakness_handoff`.

Expose in Practice Strategy UI when applied:

`100問後のPlan全体再確認: Database / Availability（最大2問）`

Also state that remaining questions stay broad.

## No automatic state mutation

V58.18 does not:
- mutate Task progress
- mark Task complete
- create Evidence
- change Study Method Recommendation
- change mastery
- change V56.0 phase
- create a new weakness state database

It only changes the bounded next-question allocation when eligibility is satisfied.

## Provider / storage

DB-only policy projection before question generation.

No migration.

No extra AI call.

## Validation

Required:

1. <100 questions → no handoff
2. non-AP → no handoff
3. parent topic <3 exposures → no candidate
4. repeated exposure of only one unique question → no candidate
5. parent topic >=3, >=2 unique and >=60% → no candidate
6. parent topic >=3, >=2 unique and <60% → candidate
7. actor isolation
8. cross-Task Plan observations can create candidate
9. adaptive/focused Task mode → no application
10. broad_assessment + general_practice → application
11. exam_mode → no application
11. local broad_recheck topics keep precedence
12. max 2 total primary recheck topics
13. cooldown/mastered candidate suppressed
14. question_mix keeps broad exploration minimum
15. focus_topics remains []
16. Question Bank selection can consume applied topic
17. prompt exposes bounded primary topic allocation
18. strategy snapshot stores handoff metadata
19. UI explains Plan-wide recheck
20. no mutation/provider call from projection
21. V56.0 regression green
22. V56.4 routing regression green
23. V58.16 coverage regression green
24. V58.17 checkpoint regression green
25. V56.3 exposure rotation regression green

## Completion

After validation:
1. promote canonical V58.18 spec
2. update Product Spec / V41.4 / V56.4 / V58.16 / V58.17
3. close TEMP pointer
4. remove temporary validation workflow
5. create PR to main
6. stop before merge

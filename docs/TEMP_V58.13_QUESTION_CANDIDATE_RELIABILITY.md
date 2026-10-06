# TEMP V58.13 Question Candidate Reliability Signal

Status: implementation in progress  
Branch: `feature/v58-13-question-candidate-reliability`

## Goal

Reflect Question Candidate human-review and real reuse evidence into Practice
Reliability without treating learner correctness as question quality.

```text
Native AI Candidate generation
→ Human Review
   ├ promoted
   └ rejected
→ promoted Question Bank asset
→ actual assessed reuse
→ bounded Candidate operations signal
→ Practice Reliability / question_quality
```

## Core safety rule

Learner correctness is NOT a Question quality signal.

A difficult, valid question may have a low correct-answer rate. Therefore V58.13
must not use:

- learner score_percent
- per-question correct / incorrect rate
- learner weakness
- Task progression

to raise or lower Candidate quality.

## Scope

Candidate signal is scoped by `exam_profile_key`.

If the current Practice strategy has no exam profile key, Candidate Reliability
remains unavailable and the existing fixed Reliability score is unchanged.

## Human Review metrics

For the current exam profile:

- candidate_count
- pending_count
- reviewed_count
- promoted_count
- rejected_count
- promotion_rate_percent

`reviewed_count = promoted + rejected`.

Pending Candidates are excluded from promotion rate.

## Reuse metrics

Promoted Candidate Questions are linked through:

`PracticeQuestionCandidate.promoted_question_id`

V58.13 inspects at most the latest 500 StudyPracticeSessions and counts:

- selected_reuse_count
- assessed_reuse_count
- reused_question_count
- current_session_promoted_candidate_count

`assessed_reuse_count` counts promoted Candidate Question selections in Sessions
that have at least one StudyPracticeAttempt.

This measures operational reuse only. It does not inspect whether the learner
answered correctly.

## Signal maturity

Minimum Human Review sample before score adjustment:

`5 reviewed Candidates`

Below 5:

- status = observing
- applied_adjustment = 0

Evidence strength:

```text
review_weight = min(1, reviewed_count / 20)
reuse_weight  = min(1, assessed_reuse_count / 20)

evidence_strength =
  75% review_weight
  + 25% reuse_weight
```

This is a maturity / evidence-volume signal, not a statistical confidence
interval.

## Bounded score adjustment

Human Review center:

`70% promotion rate = neutral`

Raw adjustment:

```text
round((promotion_rate_percent - 70) / 5)
clamped to -6 ... +6
```

Applied adjustment:

```text
round(raw_adjustment * evidence_strength)
clamped to -6 ... +6
```

Only `native_ai` and `hybrid_ai` question_quality use this adjustment.

Question Bank does not receive an extra boost or penalty because its selected
questions are already under the reviewed Bank contract.

External AI is not affected because Canovia Candidate operations do not govern
that source.

## Reliability output

Add top-level:

`candidate_signal`

with:

- status
- exam_profile_key
- candidate counts
- promotion rate
- reuse counts
- evidence_strength_percent
- raw_adjustment
- applied_adjustment
- current session reviewed-Candidate count
- human-readable note

The `question_quality` metric also exposes:

- base_score
- candidate_adjustment

The final metric score remains clamped 0..100.

## UI

Practice Reliability shows a compact Candidate operations detail when an exam
profile signal exists.

It must clearly distinguish:

- Human Review outcome
- reviewed-question reuse
- Reliability adjustment

and state:

"学習者の正答率はQuestion品質の判定に使っていません。"

## Existing disclaimer

The current disclaimer remains: Practice Reliability is not measured AI
accuracy.

It is extended to explain that Candidate operations is an operational
human-review/reuse signal.

## No migration

All required Candidate, Question, Session and Attempt relations already exist.

## Provider boundary

V58.13 is DB-only.

It performs no Native AI or external provider request.

## Validation

Required:

- no exam profile → no Candidate adjustment
- fewer than 5 reviewed Candidates → observing / adjustment 0
- pending Candidates do not affect promotion rate denominator
- promoted/rejected Human Review produces deterministic bounded adjustment
- assessed reuse increases evidence strength
- selected but unassessed reuse is tracked separately
- learner correctness changes do not alter Candidate signal
- current Session reports promoted-Candidate selections
- native_ai question_quality applies adjustment
- hybrid_ai question_quality applies adjustment
- question_bank score is not adjusted
- external_ai score is not adjusted
- final score stays 0..100
- UI displays Candidate operations and learner-correctness disclaimer
- no provider call
- V41.9 Candidate operations regression remains green
- V41.10 Reliability regression remains green
- V58.12 Activity observation regression remains green

## Completion

After validation:

1. promote to `docs/V58.13_QUESTION_CANDIDATE_RELIABILITY.md`
2. update Product Spec / V41.10 / V41.9
3. retire TEMP to pointer
4. remove temporary validation workflow
5. create PR to `main`
6. stop before merge

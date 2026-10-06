# TEMP V58.17 Study Practice 50 / 100 Cumulative Checkpoints

Status: implementation in progress  
Branch: `feature/v58-17-practice-cumulative-checkpoints`

## Goal

Turn the existing 10-question-block Study Practice loop into visible cumulative 50 / 100 question checkpoints without changing routing authority.

```text
assessed Practice Attempts on current Task
→ normalized graded question events
→ cumulative 50-question checkpoint
→ cumulative 100-question checkpoint
→ visible progress in Practice UI
```

## Scope

- current Plan
- current Task
- current actor only
- all Study Practice providers

Checkpoint counting is Task-local. Plan-wide routing remains unchanged.

## Authoritative question event

A question counts only when `assessment.question_feedback` contains:

- non-empty `question_id`
- correctness in `correct / partial / incorrect`

One feedback id is counted at most once per Attempt.

Attempt `score_percent`, top-level strengths/weaknesses, draft answers, and Session preparation do not create question count.

## Provider-neutral counting

Question Bank, Hybrid, Native AI, and external-AI assessed questions can all contribute if they have valid graded feedback.

Question Bank provenance is optional extra diagnostics only.

## Question Bank repeat diagnostics

When an Attempt is linked to a StudyPracticeSession and feedback `question_id` matches `selected_questions.question_ref` with a numeric `question_id`:

- count unique Question Bank ids
- count repeated Bank exposures after the first

AI-generated questions without durable Bank ids still count toward 50 / 100 graded volume, but are not assigned fake uniqueness.

## Checkpoints

Milestones are fixed:

- 50 questions
- 100 questions

For each reached checkpoint, freeze a deterministic snapshot over the first N valid graded events in chronological order:

- correct
- partial
- incorrect
- observed_correct_rate_percent
- reached_at
- attempt_id

Observed correct rate is `correct / total`. Partial is not correct.

## Current progress

Return:

- assessed_question_count
- correct / partial / incorrect
- observed_correct_rate_percent
- next_checkpoint = 50 / 100 / null
- questions_to_next_checkpoint
- 50 / 100 checkpoint snapshots
- unique_bank_question_count
- repeated_bank_exposure_count

## History bound

Read at most the oldest 500 Attempts for the Task / actor.

This preserves the first 50 / 100 checkpoint snapshots. If more than 500 Attempts exist, set `history_truncated=true`. Once 100 questions are reached, exact later lifetime volume is not required by this feature.

## UI

Add a compact `CUMULATIVE CHECKPOINT` block to Study Practice near Practice Strategy.

Before 50:

- `32 / 50問`
- remaining count
- current observed correctness

After 50 and before 100:

- 50問 checkpoint reached + frozen result
- `72 / 100問` current progress

After 100:

- 50問 snapshot
- 100問 snapshot
- `100問 checkpoint達成`

Also show Question Bank unique / repeated diagnostics when Bank provenance exists.

## Authority boundary

V58.17 does not:

- change StudyPracticeStrategyService
- change StudyWeaknessPrioritizationService
- change StudyExamConvergencePolicyService
- change StudyPracticeRoutingPolicyService
- create Task progress
- mark Task complete
- trigger weakness reinforcement
- modify Study Intelligence mastery

The checkpoint becomes evidence for a later policy version, not authority in this version.

## Provider / storage

DB-only, deterministic, read-only.

No AI/provider call. No migration.

## Validation

Required:

1. no attempts → 0 / 50 waiting
2. invalid/ungraded feedback does not count
3. duplicate feedback id inside one Attempt counts once
4. Question Bank repeat exposure counts volume but not unique Bank count
5. Native/External AI graded question counts toward volume without fake Bank uniqueness
6. 49 questions → next 50 / remaining 1
7. 50 snapshot freezes first 50
8. 51–99 → next 100
9. 100 snapshot freezes first 100
10. partial is not correct
11. chronological ordering across Attempts
12. same Plan/Task actor isolation
13. another Task does not contribute
14. oldest 500 Attempt bound / truncation flag
15. Practice UI renders checkpoint block
16. GET is read-only/provider-free
17. V56.0 convergence regression
18. V56.3 exposure rotation regression
19. V56.4 routing regression
20. V58.16 AP coverage regression

## Completion

After validation:

1. promote canonical V58.17 spec
2. update Product Spec and V56.0 cumulative-checkpoint note
3. close TEMP pointer
4. remove temporary validation workflow
5. create PR to main
6. stop before merge

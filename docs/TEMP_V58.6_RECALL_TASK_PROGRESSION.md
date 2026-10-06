# TEMP V58.6 Recall Task Progression

Status: implementation in progress  
Branch: `feature/v58-6-recall-task-progression`

## Goal

Connect existing Recall learning results to Study Task progression without
treating self-rated Recall as equivalent to objective Practice assessment.

Existing V41.11 Recall already records:

- Again / Hard / Good / Easy reviews
- repetitions
- lapse_count
- interval_days
- due_at
- Task Evidence with confidence 0.75
- mastered candidate = repetitions >= 3 and interval_days >= 7

However Recall never advances or completes a Task.

V58.6 adds a conservative completion boundary:

```text
Recall reviews
→ deterministic deck mastery state
→ Task completion candidate
→ explicit human confirmation
→ Task done
→ next eligible Study Task
```

No Recall review alone automatically completes a Task.

## Applicability

Recall progression applies only when the current Task's primary Study Activity
is `StudyActivityPolicyService::RECALL`.

A secondary Recall deck on a Question Practice or Resource Study Task cannot
complete that Task.

## Ready-to-complete policy

An active Recall deck is a Task completion candidate only when:

1. the Task is not already done / cancelled;
2. Recall is the primary Study Activity;
3. at least one active Recall card exists;
4. every active card has been reviewed at least once;
5. every active card satisfies the existing `isMastered()` boundary;
6. no active card is currently due.

The existing mastery definition remains authoritative:

```text
repetitions >= 3
AND interval_days >= 7
```

V58.6 does not invent a second spaced-repetition mastery model.

## Human confirmation boundary

When the deck is ready, Recall UI shows a clear Task completion candidate panel.

The user must explicitly press:

`Recall定着を確認してTask完了`

Before mutation, the server re-evaluates the policy inside the mutation
transaction.

If the policy is no longer ready, completion is blocked.

## Task mutation

After explicit confirmation:

- `progress_percent = 100`
- `remaining_minutes = 0`
- `status = done`
- `progress_reason` records deterministic Recall mastery facts
- `next_action_note` points to the next eligible Task when one exists

The next eligible Task uses the existing Study Task progression ordering /
dependency logic rather than a new Recall-specific selector.

## Evidence

Explicit Recall completion records idempotent native Evidence:

- type: `study_recall_mastery_confirmed`
- stable external key per Task
- aggregate deck metrics
- confidence below objective Practice assessment

Review Evidence remains unchanged.

## UX

Recall page adds a compact progression section showing:

- reviewed / total
- mastered / total
- due count
- current progression reason
- explicit completion CTA only when eligible and editable

If Recall is not the primary Study Activity, the section explains that the deck
is supplementary and cannot complete the Task.

If the Task is already complete, no completion CTA is shown.

## Boundaries

V58.6 does not:

- change Recall scheduling
- change `isMastered()`
- auto-complete from a review
- auto-increase progress from study time
- treat Recall self-rating as objective Practice scoring
- change AI / provider traffic
- add a migration
- change billing / entitlement
- change Question Practice progression

## Validation

Required coverage:

- incomplete deck is not eligible
- fully mastered non-due Recall-primary deck is eligible
- non-Recall-primary Task is never eligible from Recall
- ready page renders explicit completion action
- server blocks stale / non-ready completion
- explicit completion sets Task done and records one idempotent Evidence
- next eligible Task respects existing dependency / ordering logic
- repeat completion does not duplicate Evidence
- V41.11 Recall regression remains green
- V41.10 Study Task progression regression remains green
- Study Activity Policy regression remains green

## Completion

After validation:

1. promote this spec to `docs/V58.6_RECALL_TASK_PROGRESSION.md`
2. update Product Spec / V41.11 follow-up status
3. retire TEMP to a pointer
4. remove temporary validation workflow
5. create PR targeting `main`
6. stop before merge

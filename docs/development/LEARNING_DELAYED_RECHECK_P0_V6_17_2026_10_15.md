# Learning V6.17 — Opt-in 24-hour follow-up after recorded recheck

**2026-10-15 JST · Learning lane · development integration only**

## Current factual baseline

- V6.11 suggests repeated-error topics only from two wrong **distinct source Question IDs**; once two latest eligible same-topic answers are correct, this immediate error candidate clears.
- V6.16 allows an actor to explicitly start a two-unseen-source Question Bank Run only while an immediate repeated-error candidate remains. After successful remeasurement, there is no equally explicit **later** recheck pathway.
- V6.15's >=24h source spacing indicator is *after the fact*, not a delayed reminder or scheduling engine.

## V6.17 bounded read-only cue

A new `LearningDelayedRecheckService` consumes **only the 60 newest, actor + Plan + Task scoped, non-Exam AnswerEvents** already loaded by `AdaptiveLearningHistoryService`. It proposes **one** deterministic topic when all hold:

1. Task not closed/done/cancelled and progress <100%.
2. Latest **two eligible, correct** same-topic answers (evaluation-adjusted answers ignored).
3. At least two **distinct source Question IDs with eligible older wrong answers** in that topic.
4. The immediate V6.11 repeated-error review cue for this topic is **not** currently active.
5. Last eligible topic answer has a valid past timestamp. The shown milestone is `last_answered_at + 24h`. That is a UX convention, not a validated spaced-repetition interval or mastery prediction.

Status is `later` before 24h, `due` once 24h elapsed. Page-load read only; **no cron, automation, email, push, OS notification, Task mutation, new migration or background timer**. No cue if all evidence is missing, mixed actor, excluded or closed Task.

## Consent and safety

- Both Study History and new Learning index render transparent evidence and concrete milestone. Before due: informative-only, no POST form. Due: user may inspect a published Bank selector containing **two active supported source Questions never previously answered by this actor in this exact Plan/Task**, using V6.16's full source-exposure subquery (corrected or Exam answers count as exposures).
- If no two eligible unseen source Questions, show explicit unavailable text and **never invent/AI-generate** a substitute.
- Explicit Understanding-mode POST sends `delayed_distinct=1` and topic + current actor/Task-scoped answer-window signature. Recompute entire cue and due status at POST, reject stale/forged/wrong actor/mixed immediate-candidate fields, require two valid questions, lock exactly these two immutable initial snapshots with **new queue policy `bank_topic_delayed_distinct_initial_v617`**, and separate start UUID. Idempotency requires matching policy, actor, Plan, Task and pack.
- After the learner really submits the later two answers, read-only history includes this Run in V6.14-15 source comparison. New current answer events automatically move the cue to `later` or clear it, never auto-update progress/Evidence/next Action. Normal and V6.13/V6.16 paths are unchanged.

## Verification and evidence boundaries

1. PHPUnit: before 24h no form/no Run; after a controlled synthetic 25h interval cue due; two distinct previous wrong IDs, two latest correct IDs, two fresh supported published Question IDs. POST creates independent Run and two real graded answers; no progress/time/Evidence/next Action mutation.
2. PHPUnit: wrong user, forged/mixed path, stale signature, Practice mode, deleted/closed Task, missing published/active two Question sources, insufficient distinct misses and future timestamp fail closed.
3. V6.11–V6.16 PHP suites and real browser tests rerun.
4. Separate **disposable loopback SQLite only** Chromium scenario creates Guest→Free user, Plan, Task, three real graded answers and V6.16 followup via actual UI; a **guarded CLI fixture shifts only five already browser-submitted AnswerEvent timestamps**, preserving their relative chronology, to simulate >26h. Browser reads due cue, user chooses a third Bank and grades two more distinct Questions; read-only DB verifier checks the third Run source IDs and unchanged Task. This is *not* a literal 26h browser run or real-world retention test.
5. All required exact-head checks green, full patch and latest base reviewed, no unresolved threads; squash into **development main only**.

## Release NO-GO

No inference of retention, AP item quality, official exam readiness, ability to notify users in background, device acceptance, Render or Aiven deployment. No automatic production promotion. `production` and release PR #513 remain separate **Draft NO-GO**.

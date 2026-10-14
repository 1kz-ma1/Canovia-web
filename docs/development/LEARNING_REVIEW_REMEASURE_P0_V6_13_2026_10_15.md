# Learning V6.13 — Visible Topic Review → Recorded Remeasure → Study Workspace

**2026-10-15 JST · Learning lane P0 follow-through, non-production.**

## Prior verified state

- V6.10: real Chromium Guest→Free Study Plan, first Task, published synthetic Bank grades/history and login resume.
- V6.11: recorded repeated misses produce a **tentative** review suggestion; editing Task.next_action_note requires actor authorization, explicit confirmation and fresh source signature.
- V6.12: read-only ranked Work surface across Tasks; no auto-replanning, Task progress or invented Evidence.

## V6.13 bounded flow

1. On an editable Study Plan's Work surface, show **この分野を復習する** next to the eligible per-Task review candidate. It navigates via GET to the existing 1-question Study Bank page with `review=1`; GET never writes.
2. Recompute the actor's actual scoped `AdaptiveLearningHistoryService` preview. Show a separate topic-review form **only for a current eligible topic**. Form exposes *published* Bank packs with **at least two active, independently addressable source Questions** matching its case-normalized `learning_metadata.concepts` or `weakness_targets`; all chosen Questions must satisfy the existing typed-answer Bank queue support check. If insufficient, display a clear explanation and preserve ordinary Bank practice.
3. User explicitly clicks to start an Understanding-mode Run, selecting the published Bank. Submit a review-specific UUID (distinct from the normal learning form), topic and bounded source-window signature. Only Understanding mode may use the focused entry. Existing POST `AdaptiveLearningController::start` checks Plan/Task editor auth, Study type, current actor's topic/signature and chosen Pack eligibility before creating anything. A changed answer/mistap adjustment, wrong Plan/Task/actor, forged topic or unsuitable Pack fails closed. No automatic AI generation, fresh prompt synthesis or payment.
4. The ordinary Bank start and focused start share a dedicated **12/min per authenticated user (guest IP)** limiter, isolated from unrelated numeric Study POST throttles. This keeps the original request bound without blocking a second learning Run after a long multi-step session.
5. Reuse current `AdaptiveLearningCandidateService` and `AdaptiveLearningBankQueueService` with one optional **initial-lock focus** parameter. Newly locked first two source Questions match the confirmed topic **even when the ordinary locked queue size is 1**; ordinary/new-unfocused Runs retain prior behavior. Later refill only re-ranks replaceable future candidates under existing policy; already locked snapshots never change. The new Run is distinguishable by `queue_policy_version=bank_topic_review_initial_v613`, with no database migration.
6. Grading is unchanged, immutable `LearningAnswerEvent`, using existing in-app typed answer flow; no fabricated `TaskEvidence`, `StudyPracticeAttempt`, mastery, time, Task progress or automatic next Action. The learning Run exposes a normal link back to the selected Study Workspace.
7. On return, V6.12 reads *all recent scoped answers again*. Two newest correct answers in the same topic remove its tentative repeated-miss review card. When scoped answered history exists but no qualifying candidate is visible, show a limited **今は繰り返し誤答の復習候補はありません** feedback panel; this is *not* proof of learning, recall, exam readiness or Task completion.

## Acceptance / security tests

- PHP: 2 historic misses on independent source Questions, unrelated Bank questions sorted earlier, valid signed focused Run locked with 2 matched source Questions; two real POST answer grades produce two latest correct events and remove proposal on re-read. No Task progress/time/action changes, no new TaskEvidence/StudyPracticeAttempt.
- PHP deny: invalid topic, stale source signature, actor without Plan rights, published but unsuitable Pack with only one matching Question, duplicate-source misuses. Ordinary Bank mode unchanged.
- Browser: existing V6.12 disposable first-user journey, historical 3 grades, V6.11 explicit next Action adoption, then Work→topic review form→start second Run→grade 2 correct answers→Work recalculation, read-only verifier confirms two distinct matching source Questions, 5 total graded answers, unchanged Task progress/Evidence and unchanged adopted Task Action.
- CI: dedicated `learning-v613-topic-review-loop.yml` with prior V6.11/V6.12 feature regressions and new V6.13 feature tests, pinned Playwright Chromium and isolated loopback SQLite; exact-head required CI must all pass before merge.

## Deliberate non-goals / release boundary

- Only the two **initial** locked questions are explicitly topic-scoped, not an open-ended personalized curriculum or evidence calibration. A small published Bank may repeat previously seen source questions; do not claim independent evidence of mastery from two new answers.
- Answer submission and start confirmation cannot be assumed globally serializable at a millisecond-scale interleaving. Existing actor signatures guard sequential stale starts; finer-grained concurrent update fences remain a separate gate.
- No official AP exam content audit, mobile/iPhone acceptance, proof of educational benefit, native PWA performance claim, real cloud resources, deployment or provider changes.
- PR #513 release remains a fixed-SHA draft and **NO-GO**; `production`, Render, Aiven, credentials and billing stay unchanged. Only `main` development integration permitted after full verification.

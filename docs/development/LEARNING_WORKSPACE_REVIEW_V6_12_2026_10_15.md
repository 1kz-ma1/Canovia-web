# Learning V6.12 — Plan-scoped Study Workspace Evidence Review Queue

**2026-10-15 JST · bounded Learning lane P0 continuation.** Based on V6.11 accepted Task confirmation, not a new general Plan-level Intelligence policy.

## Observed source architecture

- Study work surface is rendered by `StudyWorkspaceController` and `resources/views/workspace/study/views/work.blade.php` and already has a Study Adaptive Action/Recommendation composition. Preserve its current ranking and Work/Analysis separation.
- V6.11 `AdaptiveLearningHistoryService` reads immutable actor/Plan/Task-scoped Bank answer events and returns a tentative review topic only after **two distinct source Questions were missed** and not subsequently recovered by two recent correct answers. It does **not** create `TaskEvidence`, `StudyPracticeAttempt` or any task progress. Its existing confirmation route is the sole mutation point and rechecks signatures, Task ownership and expected Task next Action.

## V6.12 scoped behavior

- **Read-only sidebar-like section on the Work surface only**: list top three Task review candidates in the selected, writable Study Plan. Display Task title, recorded review topic, proposed action and observed distinct-missed-Question / incorrect answer counts. Link into the **existing** per-Task history and opt-in confirmation. Do not POST or adopt from the Workspace.
- The `StudyLearningNextActionQueueService` scopes source Learning Runs to the **same current actor**, the **selected Plan**, Understanding/Practice modes, and Tasks with recorded answers. Exclude unrelated Plans, other users, Exam runs, closed/100%-completed Tasks, views without editor access. Source window is **80 recent Runs** (to discover at most 12 unique Tasks); each Task uses V6.11's own latest 60-answer bounded window. These caps are intentional, not claims of exhaustive life-history diagnosis.
- Transparent stable ordering: **not yet adopted first**, then more **different missed source Questions**, then total recent wrong answers, then stored Task priority, sort order and ID. Display max three. A repeated miss of the *same* Question cannot inflate the distinct-source primary rank. An old candidate rescinded by two latest correct answers disappears immediately on the next read.
- Candidate text from Question snapshots is Blade-escaped. No new grading, derived mastery, confidence %, auto Task selection, Task/Plan mutation, new permission, schema, paid AI or external provider use.
- This bounded queue is *not* the top-level Study Adaptive Decision Engine, which still works with observed `TaskEvidence` and Study scope. The task order is an evidence-backed **review suggestion**, not a proven optimal next step or a calibrated exam readiness score.

## Acceptance

1. PHP tests with 4 Tasks and 3 independent eligible candidates: 3 distinct misses beats 2; tie breaks by stored Task priority; completed Task never shown. Existing V6.11 confirmation moves the adopted candidate below remaining unadopted candidates without modifying progress/time/Evidence.
2. Re-answering the same Question cannot fake another independent source. Two latest correct answers remove its review proposal. Alien user and other Plan events, Exam-only answers, non-editor views and Analysis/Preparation/History cannot leak the queue. Escape hostile concept text.
3. Disposable Playwright browser uses V6.10 synthetic Question Pack and **no seeded Plan/Task/Run/Answers**. It creates a Guest Study Plan, Free account, Task and real graded answer history, then **visits the actual Study Work page**: after two distinct missed Questions it sees the review queue while `next_action_note` remains unchanged. It opens existing history confirmation, confirms, returns to Work and sees “反映済み”. Read-only DB confirms no invented TaskEvidence or Task progress.
4. `.github/workflows/learning-v612-workspace-queue.yml` runs V6.11 regression plus V6.12 PHP tests and pinned Chromium against localhost SQLite. All required checks at exact PR head must succeed before any `main` merge.

## Release boundary and remaining unknowns

This does **not** prove true pedagogical effectiveness, official AP problem validity, cross-device reliability, richer cross-Task knowledge graph, global Plan priority optimization, or a fully serialized concurrent-answer/confirmation protocol. Physical iPhone/WKWebView and release-specific live safety remain open. The existing Release #513 draft/fixed SHA and `production` / Render / Aiven remain **NO-GO**, unchanged.

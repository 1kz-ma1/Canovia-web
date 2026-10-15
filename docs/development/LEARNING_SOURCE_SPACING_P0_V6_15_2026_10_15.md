# Learning V6.15 — Same / Different Question Source and >=24h Review Evidence

**2026-10-15 JST · Learning P0 · strictly read-only recorded history.**

## Verified design basis

- V6.13 establishes actor-bound **focused** Understanding Runs using published Question Bank Questions. The original chosen topic is *not* persisted; V6.14 computes the unique shared source-snapshot topic only when both first-two question answers exist. V6.14 compares older grades **only for the same source Question ID**.
- V6.15 extends only the authorized per-Task history view. It consumes the same at-most **60 latest actor/Plan/Task-scoped Understanding/Practice AnswerEvents**, with up to 3 most-recent focused Runs and first-two answer events. No schema, Task, new session, job, report snapshot, provider API or hidden AI mutation.

## Classification policy

- **Same Question revisited**: before the earliest answered event of this focused Run, there is an event for the same positive `question_id` in a different Run, within the actor-scoped window. Even if the old grade was marked accidental tap, this proves prior *exposure* and is **not** independent.
- **Different source Question in the same topic**: no prior exposure to that question ID in the bounded window, **and** at least one earlier, unadjusted answer for a *different* source Question with the uniquely supported topic. This demonstrates a distinct ID only, not content independence, transfer or correctness. This may include a question seen outside the last 60 records; label is deliberately scoped.
- **Unknown**: missing unique topic (in particular an incomplete first-two snapshot pair), absent prior topic answer, missing Question ID, or evaluation-adjusted current answer. A previously excluded answer cannot create a baseline grade but *does* record prior exposure.
- **24h or more**: first eligible focused answer timestamp minus timestamp of the **latest (highest ID) earlier eligible same-topic answer in another Run** is >=86,400 seconds. Less than 24h is explicitly distinguished. Missing, contradictory/reversed or excluded timestamps fail closed as unknown. Two questions answered consecutively in the focused Run must **never** be mistaken for spaced review.
- The original V6.14 before/after grade comparison and the V6.11 next-action source signature and ranking remain unchanged. Both new labels are descriptive **recorded facts**, never proficiency, readiness, retention, independent question quality, task progress, or successful study completion.

## Acceptance

1. PHP seeded synthetic, isolated-memory factory: prior two missed Questions; after >49h two **different source** Questions with the same unique topic are graded correctly. Expect 2 different-source, 0 same, spaced_24h, 49h, and unchanged Task progress, time, Action, TaskEvidence.
2. Existing question IDs repeated <24h are shown as same-source only; old excluded grade cannot fake a new source; current excluded answer cannot count.
3. Cross-user, cross-Task, Exam-only events cannot supply history; inconsistent timestamps, incomplete first-two evidence, or absent previous topic → unknown. Existing V6.11–V6.14 suites remain green.
4. Disposable Playwright browser from actual Guest→Free Study Plan and Task continues into focused Run, real recorded grades and history: both previously answered source Questions classified same-source and <24h. The >24h/different-question case is proven with controlled PHP test timestamps, not a claim to have fast-forwarded a real browser.
5. All required exact-head GitHub Actions checks green and PR mergeable into latest `main` before development-only squash merge.

## Release boundary

Test synthetic Question Banks are never a quality-approved AP release or proof of actual transfer. Bound at 60 events and 3 focused Runs. Physical iOS/WKWebView, Render, Aiven, real study efficacy and production release gates remain separate.

PR #513 remains **Draft / NO-GO**, with fixed audited candidate SHA and `production` untouched. No infrastructure provisioning, deployment, policy change or purchases.

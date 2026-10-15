# Learning V6.16 — Explicit Distinct Question Follow-up (P0)

**2026-10-15 JST · Learning lane · development only**

## Baseline and problem

V6.13 starts an explicit signed topic-focused Understanding Run from an existing repeated-error candidate, but may reuse exactly the previously answered questions. V6.14–15 read-only history distinguishes source Question IDs, changed grades and elapsed time, yet the learner cannot directly request a two-question *different source* follow-up. This slice adds that optional route without modifying Task/Evidence or pretending mastery.

## New action contract

- A current, actor-scoped **repeated-miss review candidate** from V6.11 is required. The ordinary focused review remains available. From the current review page, user additionally sees **「別問題だけで再確認する」** only with a public, published Question Pack containing two **active**, grade-supported Questions matching the exact topic and with **no prior submitted answers by this actor in this exact Plan/Task** for either source Question ID. These are distinct *source IDs*, not verified pedagogical independence.
- The previous-source check is **all saved LearningRunItems with an AnswerEvent** in this actor/Plan/Task, via a scoped SQL subquery, not the last-60-event V6.14 display window. Accidental-tap grade corrections and Exam answers still count as source exposure. The other user’s answers never disqualify a source for the owner; other Tasks are not used, clearly disclosed in the UI.
- Both GET and POST independently recompute availability; POST validates current V6.11 source signature, topic, Understanding mode, published pack, and two distinct supported unseen IDs. The request includes a separate explicit **review_distinct=1** option, not accepted without the review topic and source window. Stale/forged candidate or unsupported/insufficient pack -> HTTP 409. If no two unseen questions, the screen shows a clear unavailable state and offers the existing repeat-friendly review option; no AI/generated fallback.
- An explicit start UUID is separate from normal and V6.13 review form UUIDs. The new Run has queue policy `bank_topic_distinct_initial_v616`; replay of that UUID with a different policy is rejected. The **first two immutable locked question snapshots** must be the server-validated distinct Question IDs, including when global normal queue depth is 1. Later refill behavior is unchanged.
- The existing read-only history comparison also includes V6.16 focused Runs and displays `different_source` or `same_source` based on V6.15 policy (60-event display scope). No learning result automatically edits Task progress, remaining time, TaskEvidence, legacy StudyPracticeAttempt or Next Action.
- V6.15’s 24h observation is shown only after recorded answers. On the history page, an active candidate links to manually explore distinct-source availability; an absent candidate links to ordinary one-question study. **No automated next-day reminder, scheduling, repeated-run promise, or guaranteed future topic selection**. No user-proficiency assertion.

## Verification plan

1. V6.16 PHPUnit feature tests with isolated Question Bank and generated Plan/Task/AnswerEvent fixture: original two wrong source Questions plus two unexposed matching sources; explicit start locks exactly the latter two; real POST grades and history reflect different sources; Task/Evidence unchanged.
2. No two unseen sources -> empty state and no distinct start; ordinary V6.13 review form remains. POST forgery of topic, mode, stale hash, intruder owner rejection. Test global queue configured depth 1.
3. Existing V6.11–V6.15 suites regressions.
4. Real Chromium disposable browser with Guest-created Study Plan, Free account and first Task; 3 first actual graded questions lead to candidate and owner-confirmed Action; second synthetic published Bank has only two unseen Questions, **pre-seeded without users/Tasks/Answers**; user explicitly starts that Bank, grades two questions and visits read-only History verifying source classification; no TaskEvidence/progress mutation.
5. No `main` merge without exact-head relevant full CI, clean current base and reviewed patch.

## Non-goals and release gate

No claims about independently validated question quality, AP certification, durable mastery, study effectiveness or 24h recall. No device validation, production branch, Render, Aiven, billing or cloud changes. Release PR #513 remains **Draft NO-GO**.

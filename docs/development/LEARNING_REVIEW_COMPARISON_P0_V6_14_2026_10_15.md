# Learning V6.14 — Answer-event focused-review history comparison

**2026-10-15 JST · Learning P0, development integration only.**

## Existing verified context

V6.10 creates authentic user-owned LearningRunItem and LearningAnswerEvent rows using the Question Bank. V6.11 derives tentative actor-scoped review candidates; V6.12 surfaces them across Study Tasks without auto-updating progress; V6.13 starts an explicitly signed, focused Understanding Run using at least two locked source Questions and returns to Workspace after real grading. V6.13 does **not** persist an original focused topic or pre-review answer snapshot, so V6.14 may not invent either.

## Read-only V6.14 comparison contract

- On the **authorized per-Task history screen**, show up to **three** recent V6.13 focused Runs seen in the existing **60 event** window, in newest-first recorded-answer order; compare only their first two locked Question ordinals with recorded answers. No query outside the already actor/Plan/Task scoped Understanding/Practice answer set and no mutation.
- Each current answer is compared with the **newest earlier eligible answer of the same immutable source `question_id`** from a *different* Learning Run, within that bounded window. Match neither text similarity, common topic, answer order, question title nor model inference. Excluded prior answers do **not** count as a baseline. Excluded current answers do **not** count as success. Missing baseline means **not observed in this window**, not first time ever.
- Show per-question prior/current statuses, source Question ID, prompt, bounded comparisons and how many *comparable same-Question* events switched from incorrect to correct. All untrusted titles, prompts, concepts rendered via Blade escape; no raw HTML. When one first-two answer has not occurred yet, render partial evidence honestly.
- A focused Run is tagged with its V6.13 `queue_policy_version`. Only if both initially answered question snapshots share **exactly one** well-formed <=50-character concept/weakness name (case-insensitive) display that topic. With multiple/no shared labels or partial history, leave topic uncertain rather than guessing V6.13's originally selected topic (which was not persisted).
- A per-topic review status is **still suggested** when V6.11 review_topics currently includes that exact topic; **two recent correct** only if *the two newest eligible actor/Task-scoped answers for that exact topic* were both correct; otherwise status **undetermined**. This is an observed display rule, *not* a verified mastery event or a plan-completion signal.
- Existing V6.11 source signature, review topic detection, owner POST verification, Study Workspace queue ranking, legacy StudyPracticeAttempt, TaskEvidence and Task progress are entirely unchanged. No migration, background job, AI call, new user identity or new auth surface.

## Test proof

1. Feature tests: same immutable source Question IDs compare incorrect→correct and correct→correct; adjusted answer excluded from baseline/current, new Question without prior baseline, multi-user/multi-Task/exam separation, incomplete focused history, escaped untrusted snapshot strings. Check no Task progress/time/action/Evidence writes.
2. Existing V6.11/V6.12/V6.13 feature tests rerun to catch candidate/confirmation regressions.
3. Disposable Playwright browser: create Guest Study Plan and Free user; grade first 3 Q; choose/confirm next Action; start real V6.13 focused Run, grade 2 Questions correct; visit history. UI visibly shows q1 old incorrect→new correct, q2 old correct→new correct, topic recent-two-correct, without pretending general proficiency; SQLite verifier checks grades, source IDs and unchanged Task progress/Evidence.
4. Every exact PR-head required CI completed green; clean base/current diff reviewed before squash-merge into `main`.

## Deliberate limits / release gate

Comparison is **bounded to 60 actor/Plan/Task answer events and three focused Runs**, not a lifetime transcript. Same question re-exposure is not an independent validation of transfer or long-term retention. Some historical runs may have insufficient source metadata to identify a unique topic. A later Study Adaptive Decision policy should not treat these displays as automatic Task evidence.

This does not attest to real AP learning efficacy, iOS/WKWebView/iPhone acceptance, live cloud database behavior, device UX, production deploy or source copyright approval. Release #513 remains **draft NO-GO** at its fixed SHA; no changes to `production`, Render, Aiven, secrets, billing or release branch.

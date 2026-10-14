# Learning V6.10 — Disposable browser Study first-Task and Question Bank acceptance

**2026-10-15 JST — scoped implementation/acceptance contract.** This test must run actual Playwright Chromium against a localhost Laravel app. It is not a human study, official AP exam content certification, device acceptance, or production approval.

## Goal and trustworthy evidence

The Development V6.10 browser suite is separate. This Learning suite must prove a Study Plan can start without an injected Task and a learner can use **actual Study UI** to answer one question at a time, see grading, persist history, advance to the next Question Action, and resume after login. Successful Laravel HTTP integration alone is insufficient.

## Controlled scenario

1. Disposable SQLite/PHP server and browser session. Before browser use, a tightly gated CLI creates only one **synthetic published Question Pack with three original testing questions**, each with known single-choice grading rules. The pack content is not marketed or imported into customer records.
2. The browser creates a real **Guest** `資格学習` Plan from the manual UI, then registers a synthetic Free account and claims the Plan.
3. The browser opens the real Study Workspace and clicks the existing `study-action/execute` form. That application Action—not the CLI—projects its first Task. If the action is absent, the test **fails** and the first-user product gap must be repaired.
4. The browser opens the real Study Practice's Adaptive Learning link, chooses understanding mode and the synthetic Question Pack, and clicks the form to start an actual Learning Run.
5. Answer first question incorrectly, with a reasoning memo; verify wrong grade and explanation. Click the history link, verify a persisted answer, return through the Resume UI, and click **次の問題へ**.
6. Answer the second question correctly, see correct grading, advance to the third question, log out and log in via normal forms, and reopen the same Learning Run on question three.
7. Read-only CLI verifies one Task, two answer events (one correct), active Run on ordinal 3, unchanged zero Task progress, and no synthetic Task Evidence.

CLI `scripts/ci/learning_v610_disposable_bank.php` has modes `--seed`, `--lookup`, `--verify`. Only `--seed` writes, exclusively to QuestionPack/Question, before the browser journey. All modes reject non-testing, non-file-SQLite and non-loopback application configurations. No Test-only HTTP routes, provider keys, real external grading, Aiven, Render or production data.

## What this does not prove

- Question Bank content and grading are **synthetic and predetermined**; official exam reliability and pedagogy remain external review requirements.
- Clicking Next establishes **next Question navigation**, not that the recommendation system has selected the best next topic. **Task next_action_note is intentionally not rewritten from unconfirmed learning evidence.** Whether Study's personalized next Plan/Task Action improves after multiple verified answers remains a separate P0 acceptance condition.
- Does not validate paid Native AI, cross-device sessions, physical iPhone WKWebView, live production migrations or any `release/*` candidate.
- Release #513 remains at its fixed historical SHA and **NO-GO**. A passing CI run on this PR head cannot approve production promotion.

## Acceptance checklist

| Gate | This CI's possible evidence | Remaining |
| --- | --- | --- |
| Study first Task | Browser-clicked Workspace Action creates exactly one Task | First-user clarity and scope-quality evaluation |
| Practice and grading | Browser answer/grade for incorrect and correct responses | Official quality and provider model fairness |
| Persistence and continuation | Saved history, Resume UI, next question, logout/relogin | Cross-device, long-session expiry and adaptive quality |
| Task truth | Progress unchanged, no invented TaskEvidence | Study Evidence-to-Action policy acceptance |
| Native/production | Not exercised | iPhone and release-specific isolated safety gates |

This document records **intended CI assertions**, not an observed PASS, until the exact-head workflow succeeds.

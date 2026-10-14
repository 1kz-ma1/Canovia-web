# V6.4 — Fixed PR #513 route journeys on disposable SQLite

**2026-10-14 / status: feature PR under CI, not production deployment or human E2E.**

## Target / execution boundary

Original unmodified draft release #513: `bb8248f25545ae0d600fbb68f66c41c360004e2c`.
`production` and release branch remain unchanged.

GitHub Actions `.github/workflows/development-release513-v64-pinned-p0.yml` checks out the exact historical candidate under `candidate/` and checked V6.4 test source under `audit/`. It verifies the candidate HEAD, copies **only** the new test file into that ephemeral checkout, runs its Laravel HTTP route tests with ephemeral SQLite, and rechecks SHA. The copy is not a Git commit. No live Aiven database, Render, external AI, real GitHub app connection, account credential, payment, iPhone, or browser automation is used.

This is **Laravel HTTP route integration, not browser or hardware E2E**. The labels and output must reflect that difference.

## Test steps & what the assertions actually establish

| Area | New route integration | Scope not covered |
| --- | --- | --- |
| Study | Synthetic existing Free owner → POST Plan create (Task=0) → GET Study Workspace → seed one Task and exercise (test fixture) → HTTP answer → synthetic AI-assessment preview → explicit human Apply → Task progress/next_action changes persist → Workspace reopen | New public registration and first-run diagnostic, real provider response grading, a route that creates the first Task without fixture seeding, actual browser/session expiration, next recommendation driven by actual Learning evidence |
| Development | Synthetic existing Free owner → POST Plan create (Task=0) → GET Development Workspace shows initial action → seed one Task (test fixture) → HTTP prepare/reflect guided execution → TaskEvidence persists without autonomous progress change → reopen Workspace | Whether user can create a first Task without help, whether reflection automatically becomes a new prioritized Task, real GitHub history sync, full new-user sign-up/onboarding, mobile browser |
| Isolation | Second actor denied foreign Development Workspace and Guided Execution prepare; no foreign evidence persisted | Complete permissions/regression suite, Session/CSRF in live application |

Specifically, seeding a Task is a **test-only shortcut**. Success after that shortcut may establish that the underlying execution path works, **not** that the app takes a first-time user from an empty Plan to an actionable task. A synthetic `study_assessment` supplied through the existing import/assessment route is **not** proof the AI provider can grade correctly or safely under live credentials. Manual Apply is intentional. Development reflection evidence does **not** itself set Task progress; the test requires this boundary.

## Gating / report

Keep V6.3 `BLOCKED_NEEDS_P0_E2E` until:
1. **P0-03:** the first action/Task is created and executed from the normal UI without test fixture injection. Capture actual routes and UI behavior for Study and Development separately.
2. **P0-06:** after actual user action, the next personalized recommendation is shown based on saved real evidence (not only a manually provided next_action text). Check no automatic unconfirmed progress/completion and no duplicate evidence.
3. **P0-01/P0-02/P0-07:** new account onboarding, initial questions, Free eligibility and persistence including logout/login are one continuous real-browser scenario.
4. **P0-08:** the same cohort/user can be followed through the funnel with privacy preserved.
5. Real desktop browser and physical iPhone WKWebView acceptance at the finally selected/approved release SHA; independent Aiven schema/restore/Render Live/deploy and explicit owner approval as separate production gates.

Workflow green is scoped strictly to V6.4's disposable route tests at the pinned SHA. It does not refresh the historical PR #513's separate full required CI and cannot authorize production.

A failed CI is a **blocked test**: do not suppress the failing assertion, downgrade a meaningful test to a skip, update the release candidate, or claim the product gap is resolved. Diagnose and record actual failure. If failure is fixture setup or environment-related, repair the test without weakening the genuine acceptance requirement and rerun on a new feature PR head.

## Risk and changes

- New test file, separate candidate-checkout workflow, documentation only. No production code, DB migration, entitlement or Render/Aiven actions.
- Existing #513 and main stay separate.
- Exact staged test source is audited with this PR; no untrusted source-code download from an unreviewed branch.
- No real user data: synthetic factory-generated actors and tasks only.

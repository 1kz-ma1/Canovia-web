# Canovia V6.9 — Disposable real-Chromium Free journey acceptance

**2026-10-15 JST.** This is a browser-level smoke test on a **newly created Guest Plan and registered synthetic Free account** using a disposable SQLite database, local PHP server and actual Chromium input/click/navigation. It is **not** Render Live, Aiven, a human usability study, or physical iPhone/WebView acceptance.

## What the workflow actually does

`.github/workflows/development-v69-disposable-browser.yml` (new PR-to-main required check):
- Installs locked PHP/Node app dependencies and bundles assets; then installs a pinned Playwright Chromium **inside GitHub Actions only**. No persistent Playwright dependency, provider key, external backend, new test-only HTTP route, production changes, or billing.
- Generates a standalone local SQLite file and Laravel APP_KEY; sets `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `MAIL_MAILER=array`, `NATIVE_AI_DRIVER=disabled`, `QUEUE_CONNECTION=sync`. Web server listens on 127.0.0.1 only.
- Chromium navigates to the **real** manual Plan creation screen as Guest, inputs a Development goal and submits. It registers a fresh synthetic Free user using the registration form. This exercises the Guest-to-account Plan ownership claim, not a pre-authenticated PHP client.
- Only **after registration**, `scripts/ci/development_v69_seed_browser_task.php` creates **one synthetic starter Task** for the exact synthetic account/claimed Plan. This CLI fails closed unless APP_ENV=testing, DB is a file-based absolute-path SQLite, URL is loopback, an explicit CI fixture flag is set, and the known synthetic actor/Plan are present. It is **not** an app endpoint. This means **development first-Task generation is still not E2E-proven**.
- Chromium opens the real Guided Execution page, fills and submits preparation and reflection, sees the V6.7 confirmation form, explicitly confirms the next action, follows the V6.8 on-page jump to the prefilled preparation form, starts the next work session, logs out, logs in and returns to the exact same Plan/Task with the ongoing action.
- After browser actions, isolated CLI reads the DB to verify **two** Guided Execution rows, **one** reflected TaskEvidence, **unchanged** Task progress, and the **confirmed** next-action text.

The smoke asserts rendered UI, form/CSRF processing, authentication transition, persisted state and browser navigation. It uses **visible browser interactions**, not direct JSON route POSTs; only the initial Task exists through the explicitly bounded fixture.

## Acceptance boundary and release status

| Gate | V6.9 evidence | Remaining proof |
| --- | --- | --- |
| P0-01 identity/Guest claim/resume | Real synthetic browser registration and login after logout, claimed Plan checked server-side | Real mobile/WebView, cross-browser, human account experience |
| P0-03 first action | Guided Execution starter Task view/prep reachable | **First Task artificially seeded**: no evidence that a new Development user can create one without JSON |
| P0-04 execution | UI route for beginning a guided work session | Actual work happens outside Canovia; can only verify workflow recording |
| P0-05 durable evidence | Browser posts reflection and DB confirms one Evidence; re-login preserves same state | Full session expiry, cross-device re-login, production backups |
| P0-06 next action | Browser explicitly adopts reflection and starts second action without changing progress or adding evidence | Whether advice is actually effective or selected by AI; owner decision on quality |
| P0-02/P0-07/P0-08 | Some Free identity UI steps, no billing/provider | Personalized onboarding questions, entire first-session Free funnel, data-minimal production telemetry |
| Study | Not included in this Chromium script | Real Study browser practice/answer/phase rationale needs a separate browser scenario |
| iOS | Not included | Actual physical iPhone and WKWebView acceptance |

**Pass means only this disposable browser scenario passed.** It cannot upgrade the historical release #513 (fixed `bb8248f25545ae0d600fbb68f66c41c360004e2c`) into a new scope. These scripts execute `main` feature PR code, not the pinned historical release. `production`, Render, Aiven, customer records, pricing and the draft release remain untouched. Candidate status remains **`BLOCKED_NEEDS_P0_E2E`** pending full user-facing first-Task path, real learning/browser acceptance, hardware and separate release-safety gates.

## Troubleshooting

- If browser CI fails, check runner's failed step and isolate: app boot/migration, guest-plan redirect, registration claim, real Guided Execution form, or login/recovery. Never replace the real browser clicks with direct controller calls to obtain a green badge.
- A CI repair must preserve session/CSRF and form/UI assertions, keep the database ephemeral and rerun the entire exact-head workflow. A `PASS` is not a real-user timing study.
- No screenshot, trace or server-log artifact is uploaded automatically because it could inadvertently contain session identifiers or test credentials. Debug using sanitized local reproductions if necessary.

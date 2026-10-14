# Canovia Development V6.10 — Browser-created first Task, no DB seed

**2026-10-15 JST · implementation/acceptance specification.** This is a dedicated follow-up to [V6.9](DEVELOPMENT_BROWSER_P0_V6_9_2026_10_15.md) for **development mode only**, on a disposable Chromium/PHP/SQLite GitHub Actions runner. It is not a production or iPhone acceptance.

## Decision and scope

V6.9 proved Guest Plan → synthetic Free account → Guided Execution → Evidence → owner-confirmed next Action → logout/login continuity, but used a direct CLI **Task::create** fixture. V6.10 must remove that write from the first-Task journey.

The **existing** UI offers an optional “必要なら初期タスクを考える” link on an empty Plan, leading to an external-AI Plan-generation JSON import form. V6.10 exercises this **real user-facing import** through Chromium with an explicitly synthetic but schema-valid owner-entered JSON payload. No new Task endpoint, shadow authentication endpoint, AI provider, external key, or DB Task insert is introduced. This is the lowest-risk way to demonstrate that the existing front-end can create the first Task at all.

The browser does not automatically generate that JSON. **It must not be represented as an effortless or personalized zero-Task experience**: preparing or pasting valid JSON still imposes onboarding friction. That separate usability/Product P0 issue remains open.

## Browser flow and assertions

1. Actual Guest first-run entry if shown, then fill the real Plan form and submit category `個人開発`. Assert a concrete Plan ID from the Development Workspace redirect.
2. Register a **new synthetic Free account** in the real browser form. Open the claimed Plan's detail page and its visible optional first-Task planning link.
3. Open the existing JSON input disclosure as a person would. Input schema v2.0 `plan_generation` JSON targeted to the exact Plan ID, including **one** Task; submit by clicking “この回答を読み込む”. Verify the Task title is rendered on the Plan detail page.
4. Read-only CLI `scripts/ci/development_v610_verify_browser_task.php --lookup` confirms exactly one Task is associated with the specific synthetic account/Plan, initial Task progress is zero, and there is no existing Evidence or GuidedExecution. **CLI never writes a Task** and rejects non-testing/non-file-SQLite/non-loopback contexts.
5. In Chromium, open real Guided Execution, submit preparation, reflect, confirm the suggested next Action, use the on-page jump to start that Action, log out, log back in, and reopen the same Task.
6. Read-only `--verify` asserts exactly one Task, two GuidedExecution sessions, one TaskEvidence, unchanged zero Task progress, and persisted owner-confirmed `next_action_note`.

The browser journey must fail if the import UI, target Plan, actual persistence, confirmation, or resume step fails. Do not replace clicks with direct `POST` calls or database mutation.

## CI and production boundaries

- Workflow: `.github/workflows/development-v610-disposable-browser.yml`.
- No persistent Playwright dependency; Chromium is pinned/installed in the disposable runner only.
- Application runs on localhost with ephemeral SQLite, synthetic email/domain, generated APP_KEY, file session, provider AI disabled, array mailer, and no real user/customer records.
- V6.9 and V6.10 are **separate** CI histories and can coexist. V6.10 tests current development PR source, not the immutable Release #513 candidate.
- No modifications to `production`, Render, Aiven, WKWebView, release/PR #513, production data, billing or provider credentials.

## Release decision and remaining gates

| Gate | V6.10 can establish after green CI | Still required |
| --- | --- | --- |
| Development first Task | Browser-visible import from empty Plan, persisted under claimed Free owner, **no Task seed** | External JSON creation friction, native/no-JSON guidance, real non-synthetic user usability |
| Development execution | Original V6.9 flow with actual browser-created Task | External coding tool execution, human assessment of recommendation quality |
| Study | **No new evidence** | Study-first-user browser question creation/answer/grading/save/next Action, in a separate bounded scenario |
| iOS | **No new evidence** | Physical iPhone WKWebView manual acceptance, correct SHA deployed |
| Production | **NO-GO** | All pinned release #513 gates and independent owner approval |

Do not update status to release GO from a CI pass. This specification is a **conditional acceptance contract** until the exact PR-head Chromium and existing required CI checks have completed successfully.

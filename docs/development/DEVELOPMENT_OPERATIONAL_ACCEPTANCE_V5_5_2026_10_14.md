# Canovia Development Operational Readiness V5.5 — Stale-form guard and end-to-end acceptance

> Status: integrated into `main` via PR #631; **not verified deployed** (2026-10-14).
> Supersedes no production runbook or seven V53.8 authoritative Release Quality Gates.
> Requires V4, V5.0–V5.4 code; does not imply that a private Plan or a physical iPhone was accessed.

## Finding: two-tab recovery confirmation gap

V5.3's manual Render service confirmation already required the target's viewed revision. In V4 backup and restore confirmation forms, a stale tab did **not** submit a revision or selected storage category. If an owner opened a Staging/server-DB page, switched the target to Production or changed the storage to cloud files in another tab, the old form could record a new current-target or current-store confirmation without a renewed inspection. That is an invalid binding even though the original confirmation is human-only.

## V5.5 fix

Only `confirm_backup` and `confirm_restore` require these additional validated hidden form snapshots:
- `target_revision`: integer ≥1, exact target revision displayed during the owner's inspection.
- `expected_data_store`: enum from the V3 profile, exact storage category displayed during that inspection.

The controller validates `attest=yes` first (preserving V4 error precedence), then both snapshots, takes row locks in the existing transaction on the selected target and profile, and compares against current server records **before** creating any attestation. Missing or malformed snapshots get a validation error; changed revision or changed storage category gets HTTP **409** without writes. Existing V4 requirement for persistent data remains (invalid/missing storage = 422). Both server timestamp and recorded backup/restore revision/store still come from the locked DB records. The web form never allows a caller to supply them.

The `clear_backup` and `clear_restore` actions remain independent and owner-only; they do not create proof and do not require a revision snapshot. The owner should be able to rescind an out-of-date claim without refreshing the form. Service-identity confirmation keeps the V5.3 revision check.

This is a current-value fence, not a full historical storage-epoch ledger. A V3 storage classification switched A→B→A while a tab remains open cannot be distinguished from unchanged storage unless a future profile revision/epoch is introduced. It MUST NOT be represented as an authenticated provider attestation or a complete concurrency solution.

## Automated disposable end-to-end acceptance

`tests/Feature/DevelopmentOperationalAcceptanceV55Test.php` exercises the **real Laravel routes** with a real owned Development Plan fixture and isolated CI database:

1. Owner registers `public_launch`, `web`, `server_db` V3 profile and a Render Staging V4 target. Initial Work shows a nonconnected Render provider and manual actions.
2. Owner explicitly confirms service/backup/restore, with target/store snapshots, and sees `release_review` **only as next independent-review guidance**. No release GO or provider machine verification.
3. Change Staging→Production: revision increases, each prior confirmation remains but shows `stale`; old backup/restore confirmation forms return 409 and do not rebind history.
4. After new manual confirmation with new revision, all three can be updated; clearing backup leaves service and restore unchanged.
5. Independently change `data_store=server_db` to `cloud_files`: old-category backup/restore forms return 409; new-category explicit forms work.
6. Missing revision/category, collaborative Editor attempt and private Work inspection fail closed, with no TaskEvidence or PlanArtifact writes.

These tests do **not** access live Render, Aiven, ChatGPT, the user’s real Plan, or a hardware iPhone. CI-green is a limited code acceptance, not deployed or device verification.

## Manual Web and physical iPhone acceptance (NOT YET VERIFIED)

Execute **only after** a separately authorized non-production application deployment using a disposable account/Plan (never confidential production Plan data). Capture exact URL/environment, deployed commit SHA, date/time, operator, device/browser and redacted screenshots. Mark each case PASS/FAIL/BLOCKED independently:

| Case | Browser / physical iPhone checks |
| --- | --- |
| Open Work | Confirm Development Work opens from Plan, V3 profile and V4 environment panel is owner-only, no 403/500 or unexpected home reset |
| First configure | Choose `server_db`, Render Staging, nonsecret ID; ensure unconnected state, three pending manual checks, stable phone-width form without horizontal scroll |
| Manual inspect | Render link opens safely in another tab (mobile WKWebView new tab handling must be checked); no API key/token requested |
| Independent owner statements | Confirm service match, backup, isolated restore; each is clearly labeled **owner self-report**, and no green Release GO is displayed |
| Two-tab rebind | Keep old backup form open, change target in another session/tab, submit old form: 409 or equivalent error UI; no new confirmation bound to the new target |
| Storage change | Change V3 storage on another tab/device, submit old backup/restore form; 409, no new claim; refresh shows current category |
| Revocation | Clear one claim; ensure the other two remain and status returns to unknown for cleared claim |
| Privacy | Collaborative Editor and other owner cannot see private service ID or controls, and cannot POST confirmations |
| Expiry | Check synthetic-clock tests for 7-day service/backup and 90-day restore staleness; do not falsify actual timestamps in production |
| Release semantics | Even fully current human self-reports do not satisfy actual CI, deployed SHA, isolated restore evidence or V53.8 7 quality gates |

**Blocked until approved**: Production Aiven V5.3 nullable migration, exact production SHA, real service/deploy proof, physical iPhone E2E, public rollout. Do not mark these PASS based on a GitHub PR or synthetic fixture. No real Render workspace operation, credential, DB migration or deployment is part of this change.

## Scope, risks and rollback

- Changed code: V4 existing target controller validation, stored data-store comparison, recovery form hidden fields, V4 fixtures + V5.5 acceptance tests, dedicated Development CI.
- No migration or new persistence, external provider access, permissions, callback, tokens, background tasks, additional release gate or entitlement changes.
- PHP/Laravel validation + transaction safety verified in CI; user-facing HTTP 409 currently follows Laravel's generic error presentation (explicit friendly UX handling can be reviewed as a separate Experience-level change).
- Rollback at code level is reverting the V5.5 controller/form change; never loosen the strict fence merely to satisfy stale third-party forms, and assess compatibility if older deployed clients can still POST to the live app.

# Development Operational Readiness V5.3 — Revision-bound manual Render service attestation

> Status: **integrated into main via PR #625; not verified deployed** (2026-10-14). Deployment and production/Aiven schema upgrade remain separately gated.
> Source: [V4 explicit operator record](DEVELOPMENT_OPERATIONAL_ATTESTATION_V4_2026_10_14.md), [V5.0 independent provider observation guard](DEVELOPMENT_OPERATIONAL_PROVIDER_BOUNDARY_V5_2026_10_14.md), [V5.1 provider credential denial](DEVELOPMENT_OPERATIONAL_PROVIDER_CAPABILITY_V5_1_2026_10_14.md), [V5.2 read-only operator handoff](DEVELOPMENT_OPERATIONAL_RENDER_MANUAL_HANDOFF_V5_2_2026_10_14.md).

## Purpose / hard boundary

V5.2 provides a fixed Render dashboard link and checking guide without storing any outcome. V5.3 lets the authenticated **original owner** record only this bounded statement:

> I independently opened Render and manually compared the registered Render service ID and declared environment with the Render dashboard. I understand Canovia did not access Render and this is not external verification.

The statement is accepted only for a valid, selected Render service target. If the target does not match, the owner must correct the V4 registration instead of confirming it. The app never receives Render account credentials, API keys, workspace IDs, project source files, logs or free-text evidence.

This does **not** prove that the selected service is running, that the actual production deployment matches a GitHub SHA, that the environment is production, that a backup exists, or that isolated restoration succeeded. The status is always `operator_confirmed` / `stale` / `unknown`, **never** machine `observed` or `verified`.

## Persistence and actor boundary

An additive, nullable migration extends the existing `development_operational_targets` row with:
- `service_checked_at`: **server-generated** confirmation date/time
- `service_checked_revision`: snapshot of the target's monotonically increasing Revision
- `service_checked_environment`: snapshot of declared `staging` / `production`

No duplicate Plan/target or new evidence/grant table, no token, free text, remote workspace ID or external access. Target ownership is enforced by the existing Development-only controller guard: exact `Plan.user_id` matches the current session owner. Target confirmation is serialized by `lockForUpdate()` in a DB transaction. The confirmation form must supply `attest=yes` and an integer `target_revision`; a changed target with an old form gets HTTP 409 (no write). Recheck is an explicit new confirmation of the exact current target. `clear_service` independently revokes these three fields and never clears V4 backup/restore statements.

Claims rendered in the Work panel are owner-only, and **only** for a valid Render `srv-...` target. Others, including collaborative Editors, do not see the service ID, its recorded status, date/time, or the confirmation controls.

## Freshness / staleness

`DevelopmentOperationalAttestationService::projectServiceIdentity()` produces a **separate projection**, never passed into V2 six-check evidence and not used to satisfy V53.8's seven authoritative Release Quality Gates.

- Missing record → `unknown`
- Revision mismatch or environment snapshot mismatch → `stale` (historical date retained to show the prior manual action)
- More than 7 days old or more than 1 minute in the future → `stale`
- Exact current target and age within bounds → `operator_confirmed`

Seven days is a reminder to manually compare again, **not** the Render provider's evidence retention or a backup retention guarantee. Target change/rebind never promotes an old claim to the new environment. Backups and isolated restores retain V4 independent recency rules (7 / 90 days) and separate revocation.

## Rollout and database change

CI uses disposable SQLite/MySQL schemas. **This PR does not migrate production Aiven** or change the `production` Git branch, Live Render service, deployment, billing or permissions. Before deploying an application version that expects these new columns, the existing production release gate must explicitly assess the planned nullable ALTER TABLE against the current Aiven engine, table size and lock/rollback risk, run an approved snapshot/restore plan, and obtain separate operator authorization for actual DDL. No agent should interpret CI success or a merge to `main` as this approval.

No new background workflow, outbound HTTP, Task writes, provider credential handling, GitHub release-status writes or automatic GO gates.

## Acceptance / regression

- Explicit consent checkbox and exact revision mandatory; server controls timestamp/env snapshot.
- Cross-Plan, collaborative Editor, unauthenticated and unsupported provider cannot confirm; no other actor can see the record.
- Old-form confirmation returns 409 following target revision change. New confirmation on current target is allowed.
- Confirm and clear independent of V4 backup/restore.
- Unknown, expired, changed revision/environment and future-dated data show correct self-report status.
- V2 deploy / CI and backup / restore stay unknown when only this human check is recorded.
- Migration additive/up/down in disposable CI, lint/Blade tests, dedicated Development suite plus Workspace, Migration Recovery and Platform gates.

## Next

The separately reviewed **V6 provider capability investigation** may design true authenticated read-only service presence and live SHA proof only with genuine provider-enforced limited credentials, approved actor consent and a security-reviewed adapter. An operator's recorded self-report must never be converted to a synthetic provider receipt.

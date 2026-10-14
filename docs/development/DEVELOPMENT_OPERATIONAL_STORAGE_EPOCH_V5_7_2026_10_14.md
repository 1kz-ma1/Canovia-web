# Canovia Development Operational Readiness V5.7 — Storage Epoch / ABA Fence

> Status: **integrated into main via PR #638; not verified deployed**. No real Aiven DDL or physical-device acceptance (2026-10-14).
> Scope: owner-only manual Development Operational Profile and backup/restore attestations. Not an automated provider observation or a release authorization.

## Defect and policy

V5.5 protects backup/restore confirms against a target Revision mismatch and a changed **current** `data_store` value. That leaves an ABA gap: with two browser tabs open, an owner can change `server_db → cloud_files → server_db`, and the first tab's previous selected store again appears equal. Worse, already confirmed backup/restore self-reports can incorrectly appear current when the selected store returns to the original value.

Introduce a **monotonic storage epoch** independent of V4's target/environment Revision. A category returning to its previous value is **not the same observation epoch**. Never infer actual data/backups from a matching string.

## Additive schema

- `development_operational_profiles.data_store_revision`: unsigned integer, nonnull, default 1 for existing and new Plan profiles. It advances only when a successfully validated owner update actually changes `data_store` from its persisted value; stage/runtime and idempotent writes never advance it.
- `development_operational_targets.backup_store_revision` and `restore_store_revision`: nullable unsigned integers, snapshot of the profile epoch at the time each respective human confirmation is recorded.
- No additional evidence, secret, actor grant, automatic backup or database connection is stored.
- **Legacy confirmed rows** with NULL snapshot are deliberately projected as `stale`, not silently backfilled. The user must independently re-check and explicitly confirm with the new form. This is conservative; a previously completed human action is not deleted.
- Counters are bounded to the unsigned 32-bit type. Invalid or exhausted counters fail closed instead of wrapping.

The migration only runs in disposable CI databases in this PR. The live Aiven MySQL schema, table sizes, concurrent writes and migration lock/rollback risk require separate predeployment inventory, restore/backup plan, explicit owner permission and a pinned release candidate. V5.3's earlier additive migration remains independently unapproved for production; V5.7 cannot be deployed before both are ready.

## Transaction / authorization boundary

- `DevelopmentOperationalProfileController`: validates enum-only owner input, locks the existing Plan profile row, increments the epoch when category changes, and saves the update within the transaction. It does not acquire the target row lock, avoiding reverse lock ordering against recovery confirmation.
- `DevelopmentOperationalTargetController`: valid backup/restore form requires `attest=yes`, exact `target_revision`, `expected_data_store`, and new `expected_data_store_revision`. It locks the target and profile rows, checks all three scope values against current persisted records, and only then writes timestamp, target revision, store name and store epoch server side. Old forms or changed category/epoch return the existing V5.6 friendly HTTP 409 recovery page and do not write. Invalid/missing epoch is a validation error.
- Explicit `clear_backup` and `clear_restore` erase their own stored epoch as well as the relevant confirmation fields. `confirm_service` remains separate (target revision only) and cannot substitute for backup or restore.
- `DevelopmentOperationalAttestationService::project`: to remain `operator_confirmed`, each existing backup/restore claim must match target Revision **and** current store name **and** profile epoch. Mismatch or NULL epoch is `stale`; 7-day backup, 90-day restore and future-date guards are unchanged. The resulting owner statement does not satisfy independent CI, deploy SHA, authorized service verification or V53.8 GO gates.

Existing owner/Development Plan authorization, collaborator privacy, CSRF, generic rate limits, no credential inputs and no external Render API access remain unchanged.

## Automated acceptance

- End-to-end Laravel route test: explicit initial store/target/backup/restore, `server_db→cloud_files→server_db` increments epoch 1→2→3, target Revision unchanged, old evidence remains stale, stale POST returns 409 and current explicit POST records epoch3.
- Empty/incorrect form epoch errors, idempotent store write and stage/runtime-only updates do not advance revision, invalid category ignored, clear is independent, other owner is denied.
- Pre-V5.7 legacy claims with NULL epoch remain stale.
- V3–V5.6 fixtures updated to the new form contract; no new autonomous TaskEvidence or PlanArtifact records.
- Migration syntax/up/down and MySQL 8.0/8.4 schema compatibility, Blade compile, Development and Workspace regression CI required.

## Residual / rollout

This epoch tracks only **updates through the authorized V3 controller**; direct out-of-band DB changes are not covered. First-time concurrent creation races remain controlled by the unique Plan ID database constraint. A simple `main` squash merge is not a production deploy, a real backup, an Aiven DDL authorization, or a physical Web/iPhone smoke test. The V5.5/V5.6 manual acceptance checklist remains open.

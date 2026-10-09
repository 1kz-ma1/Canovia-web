# P0 — MySQL 8 Foreign-Key Recovery Gate (2026-10-09)

Status: **proposed implementation in isolated feature branch; no production acceptance or deployment**.
Tracking: [Issue #418](https://github.com/1kz-ma1/Canovia-web/issues/418).
Owner: Platform P0 DB; do not change auth/session and MCP in the same PR.

## Confirmed defect

Production Render logs recorded MySQL `1059` (identifier exceeds 64 characters)
for Laravel's generated foreign keys on:

- `intelligence_decision_traces.intelligence_state_snapshot_id`
- `learning_answer_evaluation_adjustments.learning_answer_event_id`

Both original migrations generated too-long names via `->constrained()`. On an
interrupted deployment, a table can exist with columns but missing later
constraints or indexes; an unconditional `Schema::hasTable` return can falsely
mark it migrated.

## Implemented changes

- Pin short foreign-key names `idt_snapshot_fk` and
  `laea_answer_event_fk`; also explicitly name the adjustment user FK
  `laea_user_fk`.
- On an existing table, verify the expected columns, referenced table/column,
  delete action, unique constraint and required decision lookup indexes.
- Recreate only absent, expected constraints/indexes. Incompatible existing
  foreign keys / missing expected columns **fail closed** rather than silently
  accepting corrupt structure. No destructive overwrite, truncate or data
  rewrite is performed by a migration `up()`.
- Add a dedicated **disposable MySQL 8** GitHub Actions service which runs the
  **full** Laravel migration chain, reruns it, removes selected foreign keys
  and indexes *only inside that CI database*, then invokes the exact migration
  `up()` twice and checks actual MySQL schema metadata.
- The CI test's DDL operations refuse non-`testing` environments, any driver
  other than mysql, any DB host other than loopback, any DB name other than
  `canovia_p0_ci`, or any user other than `canovia_ci`. It is never an
  operator command for Render/Aiven.

## Required safety review before merge or deployment

1. Review latest [Issue #418](https://github.com/1kz-ma1/Canovia-web/issues/418)
   and its production release hold; a green disposable CI is **not** permission
   to auto-deploy to production.
2. Prove the actual production schema/migration ledger and missing FK/index
   state using a separately approved **read-only**, non-personal inventory.
3. Confirm a restorable, tested Aiven production backup; approve exact repair
   SQL/DDL scope, recoverability and a maintenance window. Never run
   `migrate:fresh`, `DROP` or destructive rollback against production.
4. Check exact branch SHA on real MySQL CI and all other required CI. Do not
   merge if blocked, failed, stale, or if concurrent schema changes conflict.
5. After an authorized rollout, independently verify migration ledger,
   FK/index metadata, preserved user rows, logged-in session continuity,
   and an actual iPhone PWA test. Render `live` does not establish success.

No production Render/Aiven environment, private data, connection string,
credentials, staging MCP access, hosted IdP, or billing is changed here.

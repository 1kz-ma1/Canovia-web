# PHP 8.4.27 — immutable old-Live94 SQL query manifest (offline)

> 2026-10-23 / Track H. **NO AIVEN, NO EXECUTION, NO PRODUCTION GO.** Exact deployed old-Live source only; not the e858 94→113 release.

## Fixed source

- GitHub/Render old Live commit: `15abdc9de6f24b5250fb255c579d1aa55facaf1a`; migration Git tree `62dd8ce9bae3f73d1a390ea27242094e8165f9a2` with 94 reviewed migration files.
- Historical `scripts/sql/p0_mysql_readonly_schema_inventory.sql`: Git blob `a80067515d3555b20b46c7d3d875d9b545611a45`, **six top-level SELECT statements** with sections `connection`, `table`, `column`, `foreign_key`, `index`, `migration`.
- Historical `scripts/sql/p0_mysql_readonly_full_migration_ledger.sql`: Git blob `6d186f87075e6aec6fbceecac3c1e4eef9d47bda`, **one top-level SELECT**, section `migration_ledger`. It queries identifiers and batches, not personal records.
- The original collector executes these as two separate MySQL CLI commands, preceded by a third GRANTS command; **that collector remains unapproved**. Nothing here runs or changes it.

## What the new gate does

`scripts/ci/php8427_live94_frozen_sql_manifest.py` first calls PR #839's source gate. The old 94-migration tree, audited seven source blobs, source HEAD, tracked content and cleanliness must match byte-for-byte before any SQL bytes are examined. The new gate confirms the two exact SQL blob IDs again, rejects malformed/extended/duplicated statements, wrong order/section, unexpected comments and common SELECT-based exfiltration/locking function shapes. It emits SHA256 of the **seven original statements only**, their public section labels and fixed HOLD-style status. It **never exposes SQL text or any raw database output**. The strict splitter is intentionally limited to this previously reviewed, immutable source: it is not a general SQL parser and its static check does not formally prove absence of side effects in arbitrary SQL.

**No connection/runner/SQL execution code exists in this PR.** The gate does not access MySQL, Aiven, Render or credentials. Immutable Git blobs in an offline checkout do not prove the source remains unchanged after this check on an independently executed operator machine (TOCTOU): execution-time binding must be separately designed, reviewed and tested.

## Critical limit: targeted physical metadata is not complete DB proof

The historical schema inventory checks specifically selected tables, columns, FK rules and index names, plus three specific migration names. It is **not** an exhaustive metadata dump of all existing tables, columns, triggers, collations, other FK constraints or all migration consequences. The complete 94-ledger query compensates only for a migration ledger subset issue, **not** for physical schema coverage. Even an authenticated 94/94 applied result with zero pending migrations would still require separately accepted physical state scope and backup/recovery evidence before PHP runtime cutover.

`scripts/ci/php8427_live94_single_session_contract.py` from PR #841 models seven phases, of which its synthetic `inventory` phase summarizes **six actual SELECTs** and `ledger` summarizes **one**; no source/query transport is implemented. A future safe reader must bind **each of the 7 exact statement SHA256** to the real single-session transport and reconcile result authentication, per-query deadline, byte/row caps, roles, TLS hostname/CA and server/principal continuity. It cannot simply accept mock `SyntheticWitness` assertions as real Aiven evidence.

## CI and denial controls

The main-only workflow `php8427-live94-frozen-sql-manifest.yml` runs synthetic unit tests, checks out exact old-Live in an ephemeral detached Git worktree, verifies actual 6+1 statements and 94 migrations, then mutates the local old-ledger SQL to assert fail-closed BLOCK. It never connects to a DB or runs SQL.

Safety invariants from the generated manifest:
- `sql_executed=false`, `real_aiven_connected=false`, `tls_server_authenticated=false`, `effective_grants_verified=false`, `physical_schema_complete=false`, `remote_session_end_verified=false`, `backup_restored=false`, `production_go=false`.
- `verdict=REVIEW_REQUIRED` means source/statement layout review succeeded **only**; malformed or modified source is BLOCK.
- All exception/error codes are fixed public strings; there is no MySQL host, account, connection string, password, CA, raw ledger or private data in outputs.

## Next independently authorized work

1. Evaluate a **separate truly offline owned-MySQL fixture** proving query execution & observation against a disposable synthetic dataset, along with actual TLS peer identity and role-control denial; never point it at Aiven. No real connector, private login-path, SQL runner or operator GO in the current module.
2. Independently review a real-usable hardened reader proposal with exact source-at-execution binding, 7 fixed SQL statements, single bounded authenticated session, effective privilege semantics (active/mandatory roles), server identity recheck, STOP/no-retry, private memory/disk behavior, and incident/remote termination limits.
3. Only with a new owner-specific real Aiven read-only GO, privately verified target/CA/client/account, and suitable backup/recovery plan may an operator eventually collect current database metadata. Any incomplete visibility, unexpected pending migrations, mismatch or unknown local/remote termination means HOLD.
4. The PHP emergency candidate `71824ed16df0615e1860d7f576e6aea546561977`, GitHub protected `production` PR and five required CI contexts, owner production GO and separate Render deploy GO all remain unchanged and independent.

Tracked in [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488). **LIVE94_FROZEN_SQL_REVIEW_ONLY / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO.**

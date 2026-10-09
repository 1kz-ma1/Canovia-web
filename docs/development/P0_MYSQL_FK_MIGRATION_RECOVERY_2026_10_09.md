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


## Read-only production schema inventory — inspection prepared, NOT run

The checked-in [SELECT-only MySQL query](../../scripts/sql/p0_mysql_readonly_schema_inventory.sql)
reads **only** selected `information_schema.TABLES`, `COLUMNS`,
`KEY_COLUMN_USAGE`, `REFERENTIAL_CONSTRAINTS`, `STATISTICS` and the
**non-personal Laravel `migrations` ledger**. It checks whether two
affected tables and their parent tables exist, key columns, three expected
foreign keys (names / target / ON DELETE), expected unique/composite indexes,
selected migration names/batches, and the MySQL version. It does **not** read
`users`, `plans`, `tasks`, answer events, login sessions or any user row.
Every database statement is a `SELECT`. It does not open a connection,
apply fixes, set session options or grant database permissions by itself.

The accompanying [disposable-CI checker](../../scripts/ci/p0_mysql_readonly_schema_inventory_check.php)
refuses to run outside the pinned localhost MySQL-8 CI database
`canovia_p0_ci`. It executes the actual checked-in SQL **only against that
throwaway database**, rejects mismatched status, and writes a fixed pass/block
label without logging schema names, connection strings or personal records.

### Owner/operator-only production inspection

1. **First establish a safe route through Aiven**, outside this chat:
   identify the exact expected production MySQL service/database and engine
   version from the trusted Aiven console. Prefer a pre-existing read-only
   MySQL account with permissions to inspect `information_schema` and
   **SELECT only on `migrations`**. Do not add an account, alter grants,
   change database network allowlists or expose credentials automatically.
   The current connected GitHub/Render tools cannot query the Aiven MySQL
   tables directly. The Aiven Console's PostgreSQL PG Studio editor must
   **not** be mistaken for a MySQL query editor.
2. In a trusted MySQL client connected to the confirmed target, first
   verify current connection **privately**. Execute only the reviewed
   `scripts/sql/p0_mysql_readonly_schema_inventory.sql`, using a
   read-only credential or equivalent read-only access; never upload a
   dump, cookie, URL, password, user row or private configuration.
3. Record a **sanitized outcome** for each named check:
   `PRESENT/MISSING` table/column, `PASS/MISSING/MISMATCH` FK/index,
   `APPLIED/PENDING` selected migrations, engine version. A missing ledger
   table, wrong DB or query failure is a blocker, **not** proof of data loss.
   Any schema not exactly matching the expected pre-/post-fix state must be
   reviewed before generating targeted migration SQL.
4. This is a *targeted* audit of incident-critical schema, not a full
   comparison of every deployed migration nor a restorable backup check.
   Compare the complete migration ledger, Aiven backup/restore readiness,
   data preservation, login/PWA E2E and exact deployed SHA separately before
   changing the P0 release hold. Schema metadata does not authenticate
   individual users and does not certify production readiness.

Do not run `migrate:fresh`, `migrate:rollback`, DDL repair or speculative
production `php artisan migrate` as part of this inventory.


## Offline evidence triage (added 2026-10-09; no Aiven connection)

The [offline classifier](../../scripts/ci/p0_mysql_inventory_offline_triage.py)
takes an **operator-transcribed, explicitly allowlisted status-only** JSON
snapshot of the six query results from the SELECT-only inventory. It does
NOT execute SQL, connect to Aiven, read Render secrets or inspect an actual
production user. Create an unverified template locally with:

```shell
python3 scripts/ci/p0_mysql_inventory_offline_triage.py --template > local-unverified-p0.json
```

Populate only the enumerated statuses by comparing the SQL query output
**privately** on the correct DB. Never paste a MySQL URL, DB name, credential,
account email, session cookie, user row, free-form field or raw dump.
The classifier does not accept arbitrary JSON keys or unknown statuses,
never reflects input values to stdout, and always returns
`release_authorized=false` and `production_database_modified=false`.
Only share its generic, fixed classification codes if necessary.
The generated template defaults to **UNVERIFIED** and cannot pass.

```shell
python3 scripts/ci/p0_mysql_inventory_offline_triage.py --report local-unverified-p0.json
```

The `--report` program returns a code of 2 on `BLOCK` and 0 on
`REVIEW_REQUIRED`. **Exit 0 is NOT release approval** or a recommendation to
run a migration. The table below explains the conservative branch decisions:

| Actual metadata + ledger | Classification | Human next step |
| --- | --- | --- |
| Core identity/migrations/parent table missing, unsupported engine, no verified DB or incomplete inventory | `BLOCK` | Stop and confirm target, ledger and schema privately |
| Target table missing, target migration pending, its columns and constraints absent | `REVIEW_REQUIRED` | Backups / reviewed creation path before any migration |
| Target table present, required columns present, FK/index missing, target migration **pending** | `REVIEW_REQUIRED` | Review the tested idempotent interrupted-DDL recovery; never auto-run |
| Target table present, FK/index missing, target migration **already applied** | `BLOCK` | **Critical:** Laravel does not rerun applied historical migrations. A separately designed **forward-only reconciliation migration** must be reviewed after real evidence; editing the original `up()` alone cannot repair this state |
| Target table present but required columns missing; FK targets/rules wrong; migration marked applied with table absent | `BLOCK` | Stop. Independently assess data and manual repair scope without destructive rollback |
| Expected target tables/columns/constraints/ledger all match | `REVIEW_REQUIRED` | Schema snapshot is one gate only; validate complete ledger, backup/restore, actual login/PWA, owner release decision |

No synthetic test output demonstrates actual Aiven consistency or backup
restorability. These labels are decision *categories*, not a production
recovery plan. They must not be used to generate/execute SQL or bypass Issue
#418's merge and production safety hold. The offline classifier is tested on
synthetic complete, pending, partially-created, foreign-key mismatch, applied
drift, ledger-order mismatch, malformed input and output-leak scenarios in
the disposable MySQL CI workflow.


## Forward-only repair for *already applied* migration ledger (proposed, NOT deployed)

A new, separate **forward-only migration file** has been added to this same Draft P0 DB PR:
`database/migrations/2026_10_09_235959_reconcile_p0_mysql_applied_constraints.php`.

**Reason:** Laravel does not rerun a historical migration after its identifier is
already in the `migrations` ledger. Consequently, the edited historical
`up()` methods alone cannot recover a table with an *applied* ledger entry
but missing FK/unique/index metadata.

**Design:**
- Before any change, assert that both incident tables **and both historical
  migration ledger entries exist**. Missing history/table stops with a
  non-sensitive error, not a reconstructed table or overwritten ledger.
- Rerun only their known idempotent `up()` **constraint checks** on tables
  whose old migrations are applied. The earlier table-contract implementations
  verify expected columns and FK referential actions, restoring missing
  named FKs, unique constraints and indexes. Wrong FK references or columns
  still stop, instead of silently accepting drift.
- This forward-only `down()` is a **no-op**. Rolling back or dropping repaired
  schema objects would break the historical table contract and possibly
  disrupt existing features.
- The MySQL-8 CI runs full migrations first; with the historical ledger
  already APPLIED, it intentionally drops named FKs/indexes on **ephemeral**
  tables, calls the forward-only `up()` twice, verifies metadata, a
  synthetic pre-existing user and a synthetic row in the repaired
  decision-trace table are preserved. A negative case removes a
  synthetic migration ledger entry in CI, asserts the repair is denied, then
  restores only that synthetic ledger entry.

**Critical release restrictions:** This new migration would run automatically
in production after a merge into auto-deploying `main`. **Do not merge PR
#443** while the real Aiven schema/ledger, backup restore-readiness, migration
execution window and existing account PWA/login recovery remain unverified.
Even a passing disposable MySQL test is **not** approval to execute production
DDL. The tested synthetic user is NOT proof of all production user data
preservation. Wrong versions, different constraints, unverified production
rows or partially missing columns require a separate manual review.


## Full contract inventory coverage and Aiven backup-plan limitation (2026-10-09)

The first version of the read-only MySQL SQL reported only a **subset** of
the migration's schema contract, so a "all observed checks PASS" classification
could falsely suggest complete target-table coverage. This version explicitly
enumerates every one of the two historical migrations' **34 required columns,
5 foreign-key relationships and 11 required named indexes** (10 trace, one
adjustment). The corresponding offline classifier has the same enumerated
manifest; CI checks the manifests against one another and asserts that a
missing auxiliary FK/index/column blocks a historical APPLIED ledger state.
Legacy trace user/plan FKs may have a valid Laravel-generated name; the SQL
allows only the known legacy name or the reviewed short new name while
requiring correct parent, referenced id and ON DELETE behavior.

**Caution:** This is still a *targeted* incident-schema inspection; it does
not establish that every other Canovia table/index is intact. The baseline
manifest describes the reviewed code, not an observed Aiven schema. The SQL
uses exactly six SELECT statements and queries no personal/business rows.

**Aiven MySQL plan / backup gate, official references (checked 2026-10-09):**

- Aiven states that MySQL automated full backups are ordinarily daily and
  binlogs are continuous, but retention and available restore points depend
  on the actual service plan:
  https://aiven.io/docs/products/mysql/concepts/mysql-backups
- Aiven's Free MySQL tier advertises **backups but explicitly no forking**:
  https://aiven.io/docs/products/mysql/concepts/mysql-free-tier
- Eligible plans can fork an existing backup into an independent MySQL service;
  this **provisions a new service and may incur billing**. No such operation is
  permitted by this release checklist without separate owner authorization:
  https://aiven.io/docs/products/mysql/howto/fork-service
- Aiven documents logical `mysqldump` / `mydumper` export and restore;
  an export contains **sensitive production rows** and can consume database
  CPU, IO and storage. This is not a read-only metadata inspection or
  something CI should run against production automatically:
  https://aiven.io/docs/products/mysql/howto/migrate-database-mysqldump

### Evidence needed before changing the hold (operator, private)

1. Verify in the **Aiven Console** the actual MySQL plan, engine/version,
   service/database identity and its **Backups** panel. Do not assume the
   service is Free based on Render's separate Free Web plan.
2. Record only **non-secret** backup facts privately: a recoverable restore
   point available **before** the proposed DDL, retention, health and whether
   an **independent restore method is actually possible on this plan**.
   Backup-listed and restore-tested are distinct states.
3. On Free, **do not claim "fork & restore tested"**; fork is not offered. An
   alternative isolated restore test would require a separate approved
   environment or a locally controlled backup/restore plan, explicit budget
   and secret-data handling decision; no production dump, access expansion,
   cost or new service is approved by this document.
4. Separately review the exact current P0 migration chain (including new
   forward-only migration), observe the current production ledger and FK/index
   metadata using the SELECT-only SQL, and choose a controlled maintenance
   window. Stop on unknown/partial/wrong-version metadata.
5. Before authorizing main merge, confirm verified backup restoration,
   known rollback/forward-fix strategy for non-transactional MySQL DDL,
   production login/PWA acceptance, and that the plan can tolerate
   DDL/backup locks. The offline classifier *never authorizes release*.

Aiven backup and fork features are documentation facts, **not evidence that
Canovia has an accessible restorable backup or any particular paid plan**.

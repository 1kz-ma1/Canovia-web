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


## Local-only read-only SQL output ingestion — operator runbook (2026-10-09)

The audited SELECT-only SQL and fail-closed offline classifier are now joined
by a strict TSV **importer**:
`scripts/ci/p0_mysql_inventory_tsv_import.py`.

It accepts exactly the reviewed six SQL result sets in MySQL CLI
`--batch --raw --skip-column-names` tab-separated row format.
The importer **does not open a database connection**, does not call MySQL,
and never echoes raw input, FK names, batch numbers, credentials, server
packaging suffixes, unexpected rows or error messages. It outputs only the
allowlisted section/object status labels and a sanitized version triple.
It fails with fixed code `INVENTORY_IMPORT_REJECTED` for any missing,
duplicate, partial, invalid or unexpectedly formatted rows. CI tests the
normal, incomplete, stale, malformed, unauthorized and secret-like cases.

**Operator-only, on a trusted computer after independently confirming that the
configured MySQL login-path is the intended production Aiven service AND an
existing SELECT-only account:**

```bash
# Never execute in GitHub Actions or Render; no credentials in command history.
# mysql login-path must have been set up privately by the operator beforehand.
# Do NOT use the deployment account or relax Aiven network restrictions.
set -o pipefail
umask 077
mysql --login-path=canovia_p0_readonly --batch --raw --skip-column-names \
  < scripts/sql/p0_mysql_readonly_schema_inventory.sql \
  | python3 scripts/ci/p0_mysql_inventory_tsv_import.py --input - \
  > local-p0-sanitized-inventory.json

# Import success is NOT a passing schema; classify it independently.
python3 scripts/ci/p0_mysql_inventory_offline_triage.py \
  --report local-p0-sanitized-inventory.json
```

If MySQL client reports an error, **stop**, confirm the actual selected DB
and account permissions privately, and do not retry with root/write
credentials. This deliberately requires the operator to set up an appropriate
read-only MySQL client profile; neither the agent nor CI provisions one.
The MySQL client may display its own diagnostic to the operator's local
terminal; **never upload that output, a raw SQL capture or this login profile
to GitHub, a chat or public log**. The GitHub CI tests use only synthetic
status TSV and a separate disposable MySQL service, never live Aiven access.

No claim is made that the login-path named in the example exists, that the
production MySQL plan is known or that any existing account has logged in.
A sanitized status report is **not an attestation of database identity,
backup restorability, deployment readiness or owner release approval**.

Final release sign-off still requires all of the following **separately
verified** in the actual production environment: (1) true database target
and full migration ledger, (2) valid prior restore point and **tested**
restoration on an authorized isolated environment, (3) reviewed migration
and MySQL nontransactional DDL failure/forward-recovery plan, (4) an
explicitly approved maintenance window and current live/main/PR commit
mapping, (5) existing-account Safari/PWA/WKWebView login continuity,
(6) Learning resume/answer persistence, (7) the owner decision to lift
Issue #418's release hold. Neither importer nor classifier ever authorizes
a merge, migration, resource provisioning, or spending.


## Full migration ledger / source tree comparison (2026-10-09)

The prior SELECT-only inventory only checked three incident-related Laravel
migration identifiers. This is insufficient to know whether the **entire
production migrations ledger** agrees with the deployed migration source tree.
A separate operator-only query and offline comparator now address this gap:

- `scripts/sql/p0_mysql_readonly_full_migration_ledger.sql` contains exactly
  one `SELECT` of the **migration identifiers and batch numbers only** from the
  `migrations` table. It does NOT query users, sessions, tasks, answers or
  other private data, and does not modify the schema.
- `scripts/ci/p0_mysql_full_ledger_offline_check.py` compares that raw SQL
  response locally to every checked-in `database/migrations/*.php`
  filename from the **same exact source revision**. It rejects invalid,
  duplicated, malformed or unsupported names, nonpositive batches,
  unexpected/obsolete applied identifiers and empty/unreadable ledger data.
  Known un-applied files are `KNOWN_PENDING_MIGRATIONS` requiring a separate
  human review. A complete match is still `REVIEW_REQUIRED`, **never**
  release authorization.
- The comparator prints **fixed classifications only**, never raw migration
  names, file contents, batch numbers, connection strings or input lines.
  GitHub CI uses only a disposable MySQL instance and tests ordinary,
  tampered, duplicated, truncated, absent and unexpected-identifier cases.

Run locally on an operator-controlled computer only, with the original,
already authorized **read-only MySQL login profile** and a reviewed source
checkout corresponding to the candidate deployment:

```bash
set -euo pipefail
# Actual account/DB identity must be checked privately beforehand.
# No credentials or raw MySQL output should leave this trusted computer.
mysql --login-path=canovia_p0_readonly --batch --raw --skip-column-names \
  < scripts/sql/p0_mysql_readonly_full_migration_ledger.sql \
  | python3 scripts/ci/p0_mysql_full_ledger_offline_check.py --input -
```

**Interpretation:**

| Code | Meaning | Action |
| --- | --- | --- |
| `FULL_MIGRATION_LEDGER_MATCH_NOT_RELEASE` | Every checked-in migration is recorded as applied and no unexpected entries observed | Still confirm database identity, 34-column/5-FK/11-index schema, backup restoration, deploy SHA and E2E |
| `KNOWN_PENDING_MIGRATIONS` | Some source migration files have not been applied | Review **which** migrations are pending privately, migration ordering and planned DDL, backups and maintenance window before release |
| `UNKNOWN_APPLIED_MIGRATION` | DB ledger references a migration absent from the checked-out source tree | **BLOCK**: investigate branch/history mismatch or deleted/renamed migration; do not change ledger by hand |
| `LEDGER_EMPTY_OR_UNVERIFIED`, `FULL_LEDGER_INPUT_REJECTED`, `LEDGER_INVALID` | Missing, malformed, oversized, duplicate or otherwise untrusted evidence | **BLOCK**, re-check target, permissions and operator-only acquisition |

The comparator returns code 0 for `REVIEW_REQUIRED` **only because the
input was parsed safely**; it never approves a migration/merge. The
production Aiven database has NOT been accessed via these changes. A full
migration-name comparison does NOT certify physical schema integrity or
prove restoration. It must be paired with the incident-specific inventory,
owner-verified backup plan and full release checklist tracked by Issue #418.

When newer changes have been merged to main between metadata capture and
the proposed release, this audit must be repeated using the **actual
candidate deployed source commit**. A source/ledger match at one SHA is
not proof that an unrelated later auto-deployment remained safe.

## Disposable MySQL logical backup/restore simulation (2026-10-09)

**New CI regression, NOT an Aiven backup restore:** The disposable MySQL 8
workflow now runs `scripts/ci/p0_mysql_disposable_restore_smoke.sh` **after**
its full migrations, partial-DDL repair, target-table contract inventory,
and complete Laravel migration ledger audit.

- A hard-coded CI-only environment/connection guard requires
  `GITHUB_ACTIONS=true`, `APP_ENV=testing`, the exact throwaway
  MySQL user/database `canovia_ci/canovia_p0_ci`, loopback host
  `127.0.0.1` and matching **synthetic-only** disposable passwords.
  No Aiven API, real backup or production DB account is accessible.
- Create one fictional account record as a restore sentinel. Export the
  **synthetic** MySQL schema and rows with `mysqldump` using
  `--single-transaction --quick --no-tablespaces --set-gtid-purged=OFF`.
  The SQL file exists only inside a protected CI temporary directory and
  is deleted by a trap; no file artifact or raw SQL is uploaded.
- Abort if the destination schema `canovia_p0_restore_ci` already exists.
  Restore the dump into that independent schema without deleting or
  truncating the original. Confirm the fictional user row and target-table
  row counts, rerun the existing SELECT-only 34-column/5-FK/11-index
  inventory and *all-file* migration-ledger comparison against the **restored**
  schema. Neither tool can return `release_authorized=true`.
- On **only the restored second schema**, drop a reviewed foreign key to
  simulate corrupt/incomplete restoration; require the schema classifier to
  return `BLOCK`/`ADJUSTMENT_APPLIED_CONSTRAINT_DRIFT`. Verify that the
  original disposable source schema's foreign key is intact.
- This proves **a reproducible CI logical dump/restore pipeline** on
  synthetic MySQL 8, not Aiven's physical backups, retention, PITR, actual
  environment size, source-engine compatibility, real user-data consistency,
  or ability to restore a production Aiven backup.

**Real Aiven restoration remains a hard independent gate.** Current Aiven
documentation states that MySQL automatically takes backups, with retention
dependent on the actual service plan, while Free supports backups but **does
not support forking**. Verify the actual Canovia Aiven plan, recoverable
restore point and *authorized* independent restore method before any
production schema change. Do not automatically create a billed service,
export live user data, grant elevated DB access or move production snapshots
into GitHub Actions. A production restore drill requires its own approved
secure environment and a plan-specific operator procedure.

- Aiven backups: https://aiven.io/docs/products/mysql/concepts/mysql-backups
- Aiven Free limitations: https://aiven.io/docs/products/mysql/concepts/mysql-free-tier
- Aiven fork/restore: https://aiven.io/docs/products/mysql/howto/fork-service
- MySQL dump semantics and limitations:
  https://dev.mysql.com/doc/refman/8.0/en/mysqldump.html

**Auth status correction:** The owner has already reported that the earlier
iPhone login issue was resolved by correcting the production DB-name
environment variable. Do not imply this isolated DB restore test is intended
to debug the resolved login incident. The ongoing blocker is independently
observing the actual Aiven schema, migration ledger and backup restorability.


## Full disposable restore schema parity (2026-10-09)

The synthetic MySQL backup/restore drill now verifies **every table's
column definitions, indexes and foreign-key metadata**, not only the two
incident tables. The shared SELECT-only
`scripts/sql/p0_mysql_disposable_full_schema_fingerprint.sql` inspects
`information_schema.TABLES`, `COLUMNS`, `KEY_COLUMN_USAGE`,
`REFERENTIAL_CONSTRAINTS` and `STATISTICS`, excluding the connection's
database name so an original and independently restored disposable schema
can be compared directly.

The guarded `p0_mysql_disposable_restore_smoke.sh` runs the exact query on
both schemas, stores each result only in its **protected CI temporary
directory** and requires byte-for-byte metadata equivalence. After
deliberately dropping a reviewed FK on the **restored copy only**, a negative
check requires those fingerprints to differ as well as requiring the
previous incident classifier to `BLOCK`. It explicitly confirms the
source copy's FK remains intact. No table contents, credentials, SQL dumps,
raw metadata or user identifiers are logged or uploaded.

This expands test coverage beyond the narrower known-incident metadata and
is **not** a production Aiven restore result. It does not check production
row contents, Aiven physical backups, recovery point objective, restore
timing, environment identity or backup retention. The original owner
report that the iPhone login issue was solved by correcting the DB-name
environment variable remains authoritative; this DB check is a separate
pre-release recovery-readiness task. PR #443 remains Draft/unmerged pending
independent Aiven evidence and Issue #418 release hold.


## Two-target preflight before any MySQL reconciliation DDL (2026-10-09)

The forward-only migration `2026_10_09_235959_reconcile_p0_mysql_applied_constraints.php`
previously checked target table and applied migration names before making
DDL but delegated table-column/FK/index checks to each historical `up()`
one at a time. Because MySQL DDL is not transactional, a structural problem
in the **second** table might have emerged *after* the first table was
repaired. Now a full **read-only preflight** checks both contracts
**before running either historical migration**:

- Presence of all 34 required columns across both tables and applied
  historical migration ledger records.
- FK references: referenced table and `id` exist; any already-present FK
  has the expected target, referenced column and deletion rule; expected
  names are not occupied by another constraint.
- For a currently absent FK, reject **existing orphaned records** through
  an existence-only anti-join (no user rows or identifiers logged).
- Index column order, uniqueness and expected name conflicts.
- For a missing **unique** constraint, reject duplicate key values before
  attempting `ADD UNIQUE`. An existing incompatible nonunique lookup
  index blocks the migration rather than triggering partial earlier DDL.

Two disposable MySQL CI negative controls drop the first table's
snapshot FK, then independently construct **(a) an orphan record** in the
second table or **(b) an absent required second-table column**.
Both must reject preflight **before restoring the first-table FK**.
Finally they clean synthetic fixture drift and verify normal reconciliation
still repairs the valid schemas. The migration continues to be forward-only
and idempotent; no user data is automatically altered or deleted.

**Limitations:** Read-only preflight followed by DDL is not atomic. New
writes/concurrent deployments between validation and `ALTER TABLE`,
database resource limits, privileges and metadata locks can still cause
a later step to fail. The release still requires a controlled maintenance
window, reviewed real Aiven schema/ledger, an independently tested and
authorized backup restoration, and a forward-recovery strategy. A passing
disposable MySQL 8 test neither approves nor performs a production change.
iPhone login was separately resolved by the owner changing the DB-name
environment variable and is not part of this DB remediation.


## Database-wide MySQL foreign-key symbol and index collision controls (2026-10-09)

MySQL (InnoDB) **foreign-key constraint symbols must be unique within
the whole database schema**, unlike index names that are unique only within
their table. The two-target read-only preflight previously compared the
proposed FK symbol against existing keys **only on its own table**. A
symbol used by a *different* table could make a late `ALTER TABLE` fail
after earlier successful non-transactional DDL.

The forward-only reconciliation now also consults
`information_schema.REFERENTIAL_CONSTRAINTS` for the expected FK name
in the currently selected database before any DDL, but **only when the
expected FK is missing**. If the name is already occupied elsewhere,
it fails with the fixed code
`P0 recovery blocked: database-wide foreign key name collision.`.
No unrelated rows, user identifiers or cross-service schema are read.

Disposable MySQL 8 regressions independently verify that a missing FK on
the *first* incident table remains missing if a required FK name has
been occupied on an unrelated CI-only table; the fixture is removed and
normal forward-only repair then succeeds. A second regression creates an
index with the correct required **name but wrong column** in the second
table, asserts early fail-closed behavior without repairing the first,
then removes the synthetic collision and verifies successful recovery.

These tests exercise only pinned throwaway MySQL. They are not proof
of the actual Aiven schema, backup restorability or production permission
model. The Release #418 hold and Draft PR status are unchanged.


## Duplicate unique-key data preflight negative control (2026-10-09)

A further disposable MySQL 8 negative test now creates two fictional
`intelligence_decision_traces` records with the **same**
`decision_reference` after removing its unique index in the isolated
CI database. It also removes that table's snapshot FK. The forward-only
reconciliation must recognize the duplicate values **before any DDL**,
throwing the fixed `P0 recovery blocked: duplicate values for unique
index.` diagnostic, leaving both fictional rows untouched and the
snapshot FK still absent. The test then removes only its synthetic
fixture rows, reruns the migration, and verifies both the named FK and
unique index are restored.

This specifically tests the data integrity prerequisite of `ADD UNIQUE`,
complementing the same-schema foreign-key symbol and wrong-column index
name collision negatives. No customer data is queried or changed; the
permitted test environment remains pinned to disposable MySQL only.
The release hold remains independent of green CI and actual Aiven schema,
ledger and restorable production backup evidence must still be observed.


## MySQL child-parent FK type and SET NULL nullability preflight (2026-10-10)

The forward-only P0 reconciliation now inspects read-only
`information_schema.COLUMNS` metadata for any **missing** FK before adding
it. Besides the previously checked constraint symbol, parent table,
referenced ID and orphan rows, preflight requires child and parent
`COLUMN_TYPE` compatibility (including the signed/unsigned distinction)
and rejects a nonnullable child column whenever the proposed FK uses
`ON DELETE SET NULL`.

These prevent two additional potential MySQL `ALTER TABLE` failures
that could otherwise occur after the first incident table was already
changed. Two new **disposable MySQL 8 only** tests independently alter
the second table's synthetic `user_id` to (a) signed BIGINT against
the parent's unsigned BIGINT and (b) nonnullable unsigned BIGINT for
the required `SET NULL` action. In both cases the first table's
missing FK must remain missing when the forward-only migration aborts.
Each test restores the reviewed column definition and reruns the
idempotent recovery successfully. The tests never touch live Aiven.

Read-only preflight is **not atomic** with MySQL DDL: concurrent writes,
engine capabilities, privileges, metadata locks and changes between
checks can still cause later ALTER failures. No Aiven backup has been
restored and no production service settings or credentials have been
modified. Owner-reported iPhone login recovery (correcting the DB name
environment variable) remains closed. This PR remains Draft under #418.


## Operator P0 data preflight without Laravel boot (2026-10-10)

**Production-preferred path:** `scripts/sql/p0_mysql_select_only_data_preflight.sql`
contains only fixed `SELECT` statements. It does **not** load Laravel,
bootstrap PHP, migrate or issue DDL/DML. MySQL returns exactly **13**
fixed-label `PASS` / `BLOCK` rows for: MySQL 8 engine, 5 FK metadata
compatibility checks (types including signedness, `SET NULL` nullability,
foreign-key name collisions across the selected schema), 5 foreign-key
orphan existence checks and 2 unique-key duplicate existence checks
(excluding `NULL` values accepted by MySQL UNIQUE).

The offline `scripts/ci/p0_mysql_select_only_data_preflight_import.py`
requires all 13 distinct expected rows in strict MySQL CLI batch TSV
format; rejects unknown, partial, duplicate, truncated and malformed
output without ever printing raw rows or secrets. It emits only
`REVIEW_REQUIRED`/`BLOCK`, fixed reason families such as
`ORPHANED_REFERENCES_PRESENT` and
`DUPLICATE_UNIQUE_VALUES_PRESENT`, and hardcoded
`release_authorized=false`, `production_database_modified=false`,
`database_identity_independently_verified=false` and
`backup_restore_verified=false`. Exit code 0 means only that evidence
was complete with no blockers; it is **never permission to deploy or run
DDL**.

### Operator use: separate privately approved read-only MySQL identity

**Do not execute on Aiven until the operator has approved the check.**
First privately verify the actual Aiven service, region, DB plan, host,
database name and applicable schema, and review the exact PR SHA / SQL
content. Use **existing credentials with only SELECT permissions**,
not the application's production write-capable credential. The sample
uses an *operator-private* MySQL client login-path profile containing
the verified host and SELECT-only account; it does not create one.

```bash
set -euo pipefail
umask 077
# Login path and target DB MUST be verified by the operator on a trusted
# machine. Do not paste either or any credentials into GitHub/chat.
mysql --login-path=canovia_p0_readonly \
  --database='<privately verified database name>' \
  --batch --raw --skip-column-names \
  < scripts/sql/p0_mysql_select_only_data_preflight.sql \
  | python3 scripts/ci/p0_mysql_select_only_data_preflight_import.py --input -
```

Run the **existing** `p0_mysql_readonly_schema_inventory.sql` and
`p0_mysql_readonly_full_migration_ledger.sql` separately using their
reviewed offline importers before interpreting this data preflight.
The data-specific query will fail closed if relevant tables/columns are
absent; it does **not** certify that all expected named indexes/FKs are
present, every Laravel migration has been applied, or the database is
the intended Aiven service. It can perform substantial reads on large
tables: use an approved maintenance window with a tested rollback plan
and no expectation of instantaneous execution. Pipefail is required:
a MySQL client error cannot be treated as passing evidence just because
the offline importer produced JSON.

### Why the prior Laravel-based operator CLI is now test-only

The former `scripts/ops/p0_mysql_readonly_preflight.php` invoked the
preflight-only migration method without DDL but **bootstrapped Laravel**,
which may initialize unrelated services. It is therefore now
**fail-closed restricted to disposable GitHub Actions CI**
(`APP_ENV=testing`, `GITHUB_ACTIONS=true`,
`CANOVIA_P0_DISPOSABLE_MYSQL_CI=1`) **AND** is pinned to the exact
throwaway loopback MySQL account `127.0.0.1:3306/canovia_p0_ci`
(`canovia_ci`, dedicated CI-only test password and no `DB_URL`).
It rejects wrong host/database even if opt-in is present, before
bootstrapping Laravel, and must **never** be used against production Aiven. The forward-only migration itself continues to run
the same detailed preflight immediately before reviewed DDL if, much
later, a separate production migration is authorized.

CI independently tests the stand-alone SQL:
1. Complete SELECT-only source results on disposable MySQL 8 must be
   `REVIEW_REQUIRED` with `release_authorized=false`.
2. Synthetic orphan **and** duplicate-key records injected into a
   separate **restored throwaway schema** must produce
   `BLOCK` without exposing any row values.
3. Malformed/partial TSV, duplicated status keys, unknown sections,
   CRLF and invented credentials embedded in input are rejected.
4. The old PHP entrypoint still proves that valid synthetic preflight
   does not modify constraints, and refuses absent opt-in.

No customer rows, SQL dumps, raw MySQL diagnostics, credentials or
Aiven connection details are uploaded to CI. **This is not an actual
Aiven backup restoration or proof of production readiness**. Issue
#418 release hold, Draft PR #443, main autoDeploy and the owner's
resolved iPhone DB-name/login incident are unaffected.


## Actual Aiven MySQL 8.4 family: dedicated disposable CI acceptance (2026-10-10 JST)

Owner-provided Aiven Console UI evidence shows the live MySQL service is **Running / MySQL 8.4.8** (no SQL connection or schema-level inspection). The previous isolated schema-integrity workflow exercised `mysql:8.0` only, so a **targeted version-parity matrix** was added to `.github/workflows/p0-disposable-mysql-schema.yml` at commit `f1f089b741d14a0cbd222c883b2e24109c96fedd`.

- Matrix jobs `mysql:8.0` and `mysql:8.4` use separate disposable CI containers with the existing pinned `127.0.0.1/canovia_p0_ci` account, `APP_ENV=testing` and strict no-production guards.
- Each job checks the MySQL server's actual reported major/minor series, performs full migrations twice, an interrupted-DDL FK/index repair test, offline schema/ledger checks, a synthetic independent dump/restore and negative controls, and the SQL-only preflight/importer.
- **Both MySQL 8.0 and 8.4 jobs PASS**: [exact-commit P0 matrix workflow #37965417969](https://github.com/1kz-ma1/Canovia-web/actions/runs/37965417969).
- This is compatibility evidence for the **8.4 release family**, not proof of exact patch `8.4.8` parity, nor proof of real Aiven table state, existing DB privileges, production backups or migration/restore readiness. No production connectivity, Aiven data, secrets or Render service was used.

Aiven's official [Free MySQL tier documentation](https://aiven.io/docs/products/mysql/concepts/mysql-free-tier) states that Free has automatic backups **but cannot fork services**. The actual service plan shown to this assistant is still unverified; therefore do not assume a standard `Fork & restore` procedure is available. Aiven's [MySQL backup documentation](https://aiven.io/docs/products/mysql/concepts/mysql-backups) separately describes daily full backups plus binary-log based point-in-time recovery, with retention depending on the plan; historical backup *entries* are not proof that an independent restore was successfully tested. Before production DDL, verify the actual plan and supported recovery method privately and obtain an explicitly authorized, isolated restore drill where feasible. Do not propose a paid fork, upgrade or snapshot action without the owner's approval.

**Result:** `DISPOSABLE_MYSQL_8_0_PASS`, `DISPOSABLE_MYSQL_8_4_PASS`, `AIVEN_SCHEMA_UNVERIFIED`, `AIVEN_BACKUP_RESTORE_UNVERIFIED`, `RELEASE_AUTHORIZED=false`. Issue #418 hold and this Draft/unmerged PR remain unchanged.

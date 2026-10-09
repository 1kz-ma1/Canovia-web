# Production DB evidence gate — 2026-10-10

Status: **READ-ONLY TRIAGE / NOT RELEASE AUTHORIZATION**. Platform-owned decision note for [Issue #418](https://github.com/1kz-ma1/Canovia-web/issues/418) and [Draft PR #443](https://github.com/1kz-ma1/Canovia-web/pull/443).

This note changes no runtime code, migrations, databases, credentials, billing, Render setting or deployment. Its purpose is to stop expanding disposable test machinery without evidence from the **actual** Aiven production MySQL, while retaining the schema/data safety hold until the evidence supports a narrower decision.

## 1. Evidence ledger: observed vs unknown

| Observation (2026-10-09 unless specified) | Source / exact artifact | What it proves | What it does **not** prove |
| --- | --- | --- | --- |
| 14:02 JST: MySQL 1059 for the two long foreign-key symbols, in a Render exception that named `defaultdb` | Production Render log at 05:02 UTC; Issue #418 | That one deployment attempted invalid FK names on the then-selected connection | That the currently active, owner-corrected DB is `defaultdb`; or that the same failure persists there |
| Owner reported the iPhone sign-in problem resolved by correcting a DB-name environment setting | Issue #418, PR #443 | This **specific incident is closed by owner report** | The actual current DB name, MySQL schema integrity, PWA E2E or absence of other bugs |
| Four later production boots at 21:47, 21:59, 22:13 and 22:21 JST emitted `Nothing to migrate` | Render `Canovia` startup log; latest observed deployment `dep-db4ek9e0tbcc73d0g470`, commit `1153e31345ad988885d405e85bebc0c2ab34427e`, Live | The migration command completed without applying new migrations to the then-selected DB | Complete migration ledger/source parity, presence and correctness of FKs/indexes, durable backup, user-data integrity, or device acceptance |
| PR #443 has guarded historical FK fixes, a forward-only reconciliation, SELECT-only inspection tools and disposable MySQL 8 CI | PR #443 head `279fe06b5ea5aaee7db52a7d3a6de1f8d90ba3eb`; runs [37956354199](https://github.com/1kz-ma1/Canovia-web/actions/runs/37956354199), [37956354157](https://github.com/1kz-ma1/Canovia-web/actions/runs/37956354157), [37956354139](https://github.com/1kz-ma1/Canovia-web/actions/runs/37956354139) all successful | Synthetic engine-specific compatibility and tested fail-closed paths at that SHA | That production needs the same repair or is safe to mutate now; CI has non-failing warnings |
| Production service is configured to auto-deploy `main`; `docker/render-start.sh` runs `php artisan migrate --force` on boot | Render service metadata + `main` Docker/start script | Even a docs-only `main` merge can trigger a deployment and re-evaluation of migrations | That every docs-only merge necessarily makes a data change |

**Important correction:** do not infer the correct current DB name or an Aiven repair outcome from the previous `defaultdb` exception, the user's login recovery, or `Nothing to migrate`. Independently verify the *current* production target outside chat before interpreting schema output. No current Aiven connection, full schema/ledger or real backup restoration has been independently inspected via the connected tools.

## 2. Reuse the existing tools; avoid a second audit implementation

On a trusted operator machine, after independent confirmation of the Aiven service, region, expected database identity, actual MySQL engine/version, current plan and permission boundary, use an **existing** SELECT-only MySQL identity. Do **not** create/change DB users, allowlists, Render env vars, App credentials, or secrets as part of this issue triage.

Review the *exact source* on PR #443 before execution; all three artifacts remain **unmerged** and are not available on the current `main`:

1. [Targeted schema / FK / index / selected migration inventory](https://github.com/1kz-ma1/Canovia-web/blob/fix/p0-mysql-long-fk-recovery-20261009/scripts/sql/p0_mysql_readonly_schema_inventory.sql) with [status-only classifier](https://github.com/1kz-ma1/Canovia-web/blob/fix/p0-mysql-long-fk-recovery-20261009/scripts/ci/p0_mysql_inventory_offline_triage.py).
2. [Full Laravel migration ledger SELECT](https://github.com/1kz-ma1/Canovia-web/blob/fix/p0-mysql-long-fk-recovery-20261009/scripts/sql/p0_mysql_readonly_full_migration_ledger.sql) and [offline source comparison](https://github.com/1kz-ma1/Canovia-web/blob/fix/p0-mysql-long-fk-recovery-20261009/scripts/ci/p0_mysql_full_ledger_offline_check.py).
3. [Optional bounded existence-only orphan/duplicate and type preflight](https://github.com/1kz-ma1/Canovia-web/blob/fix/p0-mysql-long-fk-recovery-20261009/scripts/sql/p0_mysql_select_only_data_preflight.sql) and [strict fixed-status importer](https://github.com/1kz-ma1/Canovia-web/blob/fix/p0-mysql-long-fk-recovery-20261009/scripts/ci/p0_mysql_select_only_data_preflight_import.py), **only after** schema and ledger inspection and assessment of potential query cost on the real data volume.

Use the operator instructions in [PR #443's P0 MySQL gate spec](https://github.com/1kz-ma1/Canovia-web/blob/fix/p0-mysql-long-fk-recovery-20261009/docs/development/P0_MYSQL_FK_MIGRATION_RECOVERY_2026_10_09.md). The Laravel-bootstrap `scripts/ops/p0_mysql_readonly_preflight.php` has been restricted to disposable CI and **must not be used against production**.

Do not share DB name, host, login profile, connection URL, token, raw CLI output, dumps, user rows, identifiers or operational secrets in ChatGPT/GitHub. Only share approved sanitized outcomes: verified/unverified environment, schema status, complete-ledger classification, backup readiness and fixed PASS/BLOCK families. Permission errors and incomplete SQL output are blockers. A successful importer exit status is **REVIEW_REQUIRED**, never migration permission.

## 3. Evidence-driven classification and minimal remediation

| Verified actual Aiven outcome | Decision | Treatment of PR #443 |
| --- | --- | --- |
| Correct independently verified target; source/ledger consistent; required FK/index/schema constraints present; read-only preflight acceptable | **No confirmed active schema incident**; close/downgrade only the specific active incident after documented evidence; retain separate pre-launch backup/recovery review | Reassess and split the fresh-install long-FK *preventive* compatibility fix from any unnecessary production-reconciliation migration. Do not ship repair DDL just because CI is green |
| Expected migration not applied, corresponding table absent or partially created | **Controlled migration needed**, not cleared | Confirm exact pending DDL, data preservation, restorable backup and reviewed maintenance window; target the tested non-destructive creation/reconciliation path |
| Migration marked applied but FK/index missing | **Verified schema drift** | Historical `up()` edits alone cannot rerun; use reviewed **forward-only** reconciliation only after real preflight, restorable backup, and operator-approved migration |
| Wrong/unknown DB, missing columns, incompatible keys, unknown ledger entries, conflicting MySQL version, duplicate/orphan checks blocked, or no proven rollback | **BLOCK** | Do not merge/apply; investigate only the identified mismatch rather than adding broad new CI scaffolding |
| Aiven cannot be safely inspected with existing read-only access | **UNVERIFIED** | Keep migration-affecting PR on hold; request a separately authorized operator route and do not treat synthetic results as real |

The Aiven service plan, restorable recovery point, retention and (where permitted) a separately authorized restore drill remain independent checks. **No production backup, restore or DDL may be initiated from this triage.** MySQL DDL is not fully transactional; plan for forward recovery and concurrent-write/metadata-lock effects.

## 4. Release / merge boundary

- **Keep PR #443 Draft and unmerged** pending the actual Aiven evidence and per-change production review. A fresh current-main rebase, complete diff review, exact-head CI and schema-sensitive tests will be needed before any integration.
- **Do not interpret Issue #418 as proof that the old iPhone login incident is open.** It is owner-reported resolved; real multi-device regression remains a **separate pre-launch acceptance**.
- **Do not silently lift the blanket production deployment/migration safety hold yet.** Because the currently configured production startup runs `migrate --force` on every deploy, even a docs-only merge needs a release-risk decision until real Aiven state and pending migrations are understood. PR review may proceed independently without merging.
- Once the read-only evidence is complete, narrow the hold to actual migration/data risks. Evaluate safe non-schema changes separately under repository CI/merge rules and explicitly distinguish `PR merged` vs `Render live` vs `Aiven schema accepted` vs `device verified`.
- No change to My Workspace, MCP staging PostgreSQL, OAuth, iOS auth/session or billing is included.

## 5. Next evidence handoff (operator-controlled)

- **Still missing:** independently verified current Aiven DB identity/version, actual targeted schema status, complete migration ledger vs checked-in main, presence/absence of the two original FKs and needed indexes, and Aiven plan/backup restoration evidence.
- **Next action:** use the *already implemented* PR #443 SELECT-only tools against the **privately verified intended Aiven service** with a pre-existing least-privilege identity, using a trusted local client. Classify results; update Issue #418 with **sanitized status only**. The current ChatGPT GitHub/Render connection does not supply authorized Aiven SQL inspection.
- **Stop condition:** no existing read-only route, mismatched environment, data query too costly, or unclear backup mechanism → stop. Do not use the production app's write-capable credentials or broaden access for convenience.
- **Success:** an evidence-backed, small decision either to retire the active incident and retain a preventive fix, or to execute precisely one reviewed non-destructive, restorable schema repair. Avoid additional generalized P0 test expansion before this decision.

## 6. Owner-provided Aiven Console screen-recording observations (2026-10-10 JST)

**Provenance:** The owner supplied two short Aiven Console screen recordings in the chat and asked whether the requested Overview/Backup review was sufficient. These observations are UI evidence only. **Do not upload or reproduce the recordings, sensitive console information or private connection details on GitHub.** They do not authorize production SQL/DDL, credential creation, network changes, purchase or backup restoration.

| UI evidence | Status / boundary |
| --- | --- |
| Aiven MySQL service shows `Running` and maintenance version **MySQL 8.4.8** | Confirms displayed service health/version, not the currently selected Laravel database/schema, SQL privileges or data integrity. Existing disposable PR #443 CI targets MySQL 8.0, so production 8.4.8 compatibility should be explicitly reviewed before any DDL authorization. |
| Aiven Backups shows three **Full** entries: **2026-10-07 02:21:13 UTC**, **2026-10-08 02:22:10 UTC**, and **2026-10-09 02:22:09 UTC**; last is about 346.9 MiB | Establishes that backup entries exist. Does NOT establish ability to fork/restore, retention policy, consistency of target DB or that any backup is restorable. The latest visible backup **predates** the historical 2026-10-09 05:02 UTC MySQL 1059 error, so it is not a post-incident recovery checkpoint. |
| Aiven Overview shows network IP allowlist **Open to all** | Configuration exposure finding: must separately assess and reduce scope when a safe connectivity plan is known; do NOT impulsively change this setting and break Render's production DB connectivity. No change performed. |
| Overview references unavailable features on the Free tier; platform-trial banner also shown | Actual active Aiven **billing plan remains unverified** from these recordings, as do backup retention and fork/restore eligibility. Confirm in the authenticated billing/service plan area without sharing payment details. |

**Triage result:** `SERVICE_VERSION_OBSERVED` + `BACKUP_RECORDS_PRESENT`, but `SCHEMA_UNVERIFIED`, `LEDGER_UNVERIFIED`, `RESTORE_UNVERIFIED` and `RELEASE_NOT_AUTHORIZED`. Preserve Issue #418's data-change hold and PR #443's Draft status. The next evidence gap is not another screenshot of Backups: it is the actual schema/ledger via the existing, reviewed SELECT-only route with a verified production DB identity, plus separately demonstrated recovery readiness. No read-only Aiven access has been connected to this chat.

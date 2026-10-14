# Canovia MCP — Aiven MySQL production schema cutover & recovery operator runbook

Date: 2026-10-14 JST  
Status: **PREPARATION ONLY / PRODUCTION NO-GO / NO AIVEN ACCESS PERFORMED**  
Lane: Platform / OAuth / MySQL / audit continuity. This runbook complements, not replaces, [MCP real-account gates](MCP_REAL_ACCOUNT_CONNECTION_RELEASE_GATES_2026_10_14.md), [Platform release baseline](PLATFORM_RELEASE_BASELINE_AND_PREFLIGHT_2026_10_14.md) and [production recovery](P0_MYSQL_FK_MIGRATION_RECOVERY_2026_10_09.md).

## 1. Known vs unverified

- Repository implementation merged to development \`main\`: MCP nullable \`mcp_delegated_grants.expires_at\` and audited identity history with \`mcp_linked_subjects.active_identity_fingerprint\`. [PR #615](https://github.com/1kz-ma1/Canovia-web/pull/615) added **disposable** MySQL 8.0/8.4 and PostgreSQL 17 failure/retry/down-migration checks. These are evidence of tested code paths, **not** actual Aiven restore evidence.
- The general Platform baseline reports an **owner-provided** Aiven project \`pacekeeper\`, service \`mysql-2d55f17d\`, DB \`pacekeeper\`. **Do not trust these as current identifiers** without a fresh, independently approved provider-side read. No secrets, hostname, server version, grant records, migration ledger or actual backup time have been retrieved in this MCP workstream.
- This MCP-specific workflow targets exactly:
  - \`database/migrations/2026_10_14_000100_allow_nonexpiring_mcp_grants.php\`
  - \`database/migrations/2026_10_14_000200_preserve_historical_mcp_identity_links.php\`
- Production application startup currently runs migrations automatically (see \`docker/render-start.sh\`). Thus a first production boot of a new release **may apply DDL without an additional prompt**. Do not deploy code containing pending migrations before separate Aiven DDL approval, writer fencing, and backup/restore sign-off.
- Never treat a green GitHub check or an approved \`main\` merge as production promotion. Render production branch/service and this separate MCP synthetic Staging must stay isolated.

## 2. Owner/operator approval gates

Record a distinct explicit approval (or NO-GO) for EACH of:
1. Operator read-only live Aiven metadata/ledger audit, with agreed output redaction and no row contents.
2. Any backup or Aiven Fork & restore (possible new paid resources, plus handling of copied private data).
3. Maintenance window / stopping **all** old web and queued identity/consent writers.
4. Production schema migration and app release pinned to an immutable reviewed \`release/*\` SHA. Confirm explicit production branch/deploy GO separately.
5. Enabling production Auth0/OAuth flags; first ordinary-user Plan read; and emergency response policies.

This PR requests **none** of those approvals. It does not execute SQL or provision a fork.

## 3. Evidence collection — READ-ONLY only, owner controlled

Before requesting any of the above production actions:
- Check current Aiven project/service/DB through Aiven console, verified owner, service plan/region, backup retention, latest restorable point, PITR/Fork capability, expected restore cost, MySQL version and in-place upgrade/DDL limitations. Verify **actual** service identifiers rather than copying old report data into a connection string.
- Audit the operator's exact production release candidate SHA, \`production\` branch rules, Render Live SHA, and all pending migrations/DDL; report only schema names, versions, counts, statuses and immutable SHA identifiers.
- Run only from an approved read-only database identity in a restricted operator environment. Never place passwords, DSNs, SSH keys or live SQL credentials in ChatGPT, GitHub issue/PR, CI output or shell history. Do **not** ask an AI to execute this against the real DB without separate approval.

The following are **inspection-only SQL examples** for an approved operator, **not** instructions to run them now:

~~~sql
-- Verify actual engine/database and table/column shape, without row values.
SELECT VERSION() AS mysql_version, DATABASE() AS selected_db;
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('mcp_delegated_grants','mcp_linked_subjects',
                     'mcp_delegated_access_events','migrations')
  AND COLUMN_NAME IN ('expires_at','identity_fingerprint',
                      'active_identity_fingerprint','status','revoked_at',
                      'migration','batch','id','user_id','grant_id')
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- Verify index order and uniqueness. The expected post-cutover unique index
-- is mcp_active_subject_identity_unique, not a second global history UNIQUE.
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('mcp_linked_subjects','mcp_delegated_grants')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- Compare Laravel's migration ledger entries without exposing Plan data.
SELECT migration, batch
FROM migrations
WHERE migration IN (
  '2026_10_14_000100_allow_nonexpiring_mcp_grants',
  '2026_10_14_000200_preserve_historical_mcp_identity_links'
)
ORDER BY migration;
~~~

Missing MCP tables, insufficient privileges, duplicate active reservations, unsupported engine versions or ledger-vs-physical-schema disagreement are **STOP conditions**. Do not infer "not present" from a database permission error.

If aggregate counts are separately approved, output only **counts and pass/fail**, never full fingerprints, IDs, email, Plan title, JWT or consent payloads. For any access to copied real data, use an operator-private restricted restore environment; never the synthetic MCP Stage service.

## 4. Backup & restore gate (before any destructive/non-atomic DDL)

Aiven states that its MySQL backup strategy combines full backups and binary logs, and retention depends on the service plan:
- https://aiven.io/docs/products/mysql/concepts/mysql-backups
- https://aiven.io/docs/products/mysql/howto/fork-service
- https://aiven.io/docs/platform/concepts/service_backups

Aiven's Fork & restore creates a **separate service** and can restore a selected supported point in time; this may cost money, copy sensitive data and require an authorized plan. Do not create it without explicit owner approval. Service backup payloads are encrypted and not directly downloadable through the Aiven backup UI; an independently managed and encrypted \`mysqldump\` is a **different** backup method.

Evidence needed:
- Exact service/plan and actual backup timestamps plus earliest/latest restorable points confirmed through provider UI; current backup successful and estimated RPO/RTO assessed.
- Fresh, owner-approved encrypted export/PITR evidence, storage and ciphertext integrity hash; no plaintext offsite copies.
- A separately authorized **isolated restore** of the intended release snapshot (or explicit NO-GO if the plan cannot support it) with real query/connection verification, schema/migration integrity checks, no external webhook/jobs/email/AI/MCP enabled, no credentials reused, and restricted access to copied private records.
- Written choice between (a) snapshot restore with compatible app code and acknowledged possible lost writes, or (b) audited forward-only repair. Blind Laravel \`migrate:rollback\` is **not** a valid recovery strategy for these two migrations once indefinite grants or identity transfers exist.

Never claim backup-recoverable just because Aiven shows a scheduled backup or a CI job passed.

## 5. Controlled schema & application cutover

### Preflight / before user-facing downtime
1. Freeze a separate \`release/*\` candidate at an exact approved development-main commit. Obtain current production Live SHA and a complete migration list (not just MCP migrations). Compare code, physical Aiven schema and migration ledger.
2. Run all needed SQLite feature + disposable PostgreSQL 17 and target MySQL 8.x migrations, including idempotent retry, rollback refusal, and actual multi-session lock contention. Check target-engine parity, not "MySQL 8" generically.
3. Confirm every old Canovia MCP account-link, Plan consent, queue, scheduled task, deployment replica and background writer. A **legacy writer after the global fingerprint unique index is dropped can create an active link with NULL reservation**, defeating active-only uniqueness (reproduced in CI). Do not use an unreviewed mixed-version rolling deploy.
4. Verify backup/restore and identify recovery operator, outage window, bounded retry policy, switch-off path and real device smoke owner.

### Explicitly approved window (not an action by this AI)
1. Record last accepted write and announce maintenance. Fence/drain all legacy HTTP and queued identity/consent writers; disable automatic deployments and prevent any other instance restarting with the wrong code. Confirm a **machine-verified** writer-fence, not an operator's text assertion.
2. Verify backup and schema snapshot again. In a reviewed controlled sequence, apply \`000100\` nullable \`expires_at\`, then \`000200\` active-only uniqueness. \`000200\` intentionally builds the active unique index **before** removing the old global unique; MySQL DDL can autocommit and leave partial state on failure. Re-run and inspect only under maintenance.
3. Verify \`expires_at\` nullability, \`active_identity_fingerprint\` column/backfill, active-only unique, historical nonunique index, absence of old global unique and no duplicate live fingerprints; confirm audit and old finite grant counts are unchanged. No data deletion, reconsent or silent granting to force a green state.
4. Start only release-compatible application writers and verify the exact release SHA. Keep OAuth/MCP discovery/tools, Plan grants, external login **OFF** until an independent security acceptance. Do not enable Render Auto Deploy or repoint production branch without another explicit approval.
5. Validate account login, old Plan/tasks, edit/revoke, CSRF/session, queue and physical Web/iOS smoke as appropriate. Only then end maintenance; keep rollback/forward-fix operator available.

### Failure / STOP / recovery
- Before DDL: keep old application/DB with existing users; no changes.
- Partial DDL or incompatible old writer: keep maintenance / private reads OFF; analyze physical schema + migration ledger; use tested **roll-forward** repair or separately approved snapshot restoration. Do not let unverified mixed writers resume.
- Nonexpiring \`expires_at=NULL\` exists: \`000100.down()\` must throw; do not rewrite indefinite grants behind users' backs.
- Historical identity reuse exists: \`000200.down()\` must throw; do not delete audit or historical linkage to regain old global unique.
- Wrong issuer, wrong account, unexpected real Plan read, stale token after revoke, 429 burst, logging/token leak: independent emergency disable and incident review before reopening.
- Any rollback requiring new service/credentials or possibly losing writes requires **new operator approval**.

## 6. Production-first user access is separate

Never "test" by reading an arbitrary production Plan. After owner GO for (1) correct tenant/PKCE and ChatGPT registration, (2) compatible deployed schema/application, and (3) a selected plan's fresh explicit owner consent, test exactly **one** owned personal Development Plan at \`overview\` scope. Only on a second informed opt-in move to \`tasks\` (max 8 metadata-only items). Verify denial after revoke/unlink, immutable subject equality across two clients, user-facing audit/recovery and emergency OFF. No descriptions/evidence/GitHub credentials or MCP writes.

### Go/no-go record template (non-secret only)

| Evidence | State |
| --- | --- |
| Approved exact release SHA vs current Live SHA | NOT VERIFIED |
| Live Aiven service/engine/plan/retention identity | NOT VERIFIED |
| Current migration ledger and physical MCP indexes | NOT VERIFIED |
| Verified recent backup, isolated restore and RPO/RTO | NOT VERIFIED |
| Old app/queue writer fence and maintenance operator | NOT VERIFIED |
| Required CI and disposable MySQL compatibility | MUST RECHECK at pinned SHA |
| First-party and ChatGPT client subjects/issuer/audience | NOT VERIFIED on production |
| Per-Plan final consent and emergency revoke E2E | NOT VERIFIED on real account |
| Explicit operator DDL, release, OAuth and data-read approvals | NOT GRANTED |

**Verdict: NO-GO until all rows have independent evidence and explicit owner approval.**

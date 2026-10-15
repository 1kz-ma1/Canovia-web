# Aiven 19 migration fixed SHA — SELECT-only operator audit (not release GO)

> 2026-10-15. Only applies to Live Web Git SHA `15abdc9de6f24b5250fb255c579d1aa55facaf1a` (94 migration source files) → frozen review SHA `6d7dbcfedd35f33ba2827ed8c7c3d98ca30fc3d6` (113, +19). This is a production-readiness *preflight contract*, not authorization to query Aiven automatically, modify it or deploy.

## Why this is separate from the #513 gate

`release_488_mysql_ledger_gate.py` expects **exactly two** source-observation migrations to be pending and uses its own intentionally fixed signature. It must **not** be reused to approve, reject as unexpectedly pending, or inadvertently auto-classify the 19-migration candidate. The pinned 19 gate intentionally checks 94/113 exact Git SHA source sets and 13 fixed metadata signals. Its `REVIEW_REQUIRED` output means only "ledger and selected metadata match a plausible baseline or candidate"; it **never** means all MySQL schema, user data or migration permissions have been verified.

Implementation:
- `scripts/sql/prelaunch_pinned_mysql19_select_only_snapshot.sql`: SELECT only against `information_schema` and nonsecret `@@GLOBAL.log_bin` / `@@GLOBAL.log_bin_trust_function_creators`; fixed 13 status rows, **no users, Plans, questions, account identifiers or migration row values**.
- `scripts/ci/prelaunch_pinned_mysql19_offline_gate.py`: takes two locally checked-out Git trees at the pinned SHA plus local TSV outputs from the existing `p0_mysql_readonly_full_migration_ledger.sql` and the new snapshot SQL. Validates exact 94-vs-113 source sets and *exact* ledger membership; rejects partial, unknown or incompatible schema. No network/DB connection and no unsanitized output.
- `scripts/ops/prelaunch_pinned_mysql19_collect.sh`: separate operator-initiated wrapper, **disabled in CI**, requiring explicit opt-in and the existing narrowly scoped `REFERENCES` on the audited DB + `SELECT` on `migrations` only. Uses MySQL login path and CA `--ssl-mode=VERIFY_IDENTITY`, `umask 077`, private temp files; validates current-user grants before metadata or ledger queries. Never prints SHOW GRANTS or untrusted TSV content.
- `tests/ci/test_prelaunch_pinned_mysql19_offline_gate.py`: synthetic negative-control tests and output redaction. The existing disposable MySQL 8.0/8.4 94→113 upgrade workflow is extended to run the read-only metadata SQL and offline gate **both before and after** upgrade on throwaway schemas.
- **Least-privilege account rehearsal (2026-10-15):** the disposable migration CI separately creates a synthetic `p0_readonly_ci` principal with only schema-scoped `REFERENCES` and migration-table `SELECT`, runs `SHOW GRANTS FOR CURRENT_USER()` through the real MySQL client, validates the result against `p0_mysql_metadata_grants_guard.py`, proves `users` and `mcp_linked_subjects` SELECT are denied, and runs the 94 / 113 snapshot and ledger SQL using **this restricted principal**, not `canovia_ci` the writer. The root user is used only to set up the disposable CI grant and migrations. The CI principal is not the real Aiven auditor, nor proof that Aiven grants have been created or verified.

## Offline source-integrity preflight (no Aiven, mysql or permission)

Before providing database details, check out the exact two fixed source SHAs in separate clean local directories. Set only the two local filesystem paths `CANOVIA_PINNED_MYSQL19_LIVE_CHECKOUT` and `CANOVIA_PINNED_MYSQL19_CANDIDATE_CHECKOUT` and run:

```bash
bash scripts/ops/prelaunch_pinned_mysql19_collect.sh --preflight-offline
```

This does not read a MySQL login path, CA or database credentials, does not invoke MySQL, and has no Aiven/Render network operation. It reports `OFFLINE_SOURCE_INTEGRITY_CONFIRMED_NOT_AIVEN_READY` only if the **exact** source HEADs (`15abdc9...` and `6d7dbcf...`) are present, the physical/tracked migration manifest has exactly 94 and 113 files (+19), and audit SQL/collector/validators plus pinned migrations have **no local uncommitted edits, staged edits or untracked additions**. Matching Git HEAD alone is insufficient, because a local SQL or migration file can change without moving HEAD. Unknown or ignored extra migration PHP files also block. The output never includes local paths, credential values or migration identifiers.

A clean source preflight **does not verify** the real Aiven service, account, schema, TLS connection, backup or release eligibility. The actual collector still needs the existing narrow grants, verified CA and separate explicit operator authorization. `MYSQL_TEST_LOGIN_FILE` overrides are forbidden. Offline preflight mode should be completed before attempting any live database operation, and a production Web wake requires its own independent permission gate.

## One-command Mac preparation (2026-10-15; local Git only)

To avoid manually preparing three detached checkouts, use `scripts/ops/prelaunch_pinned_mysql19_stage_local.sh` from a **local checkout of Canovia-web** on the Mac. The script creates an entirely new private directory at the owner-selected absolute location; it does **not** overwrite existing directories, change the source repository, fetch from GitHub, contact Aiven, execute MySQL or wake Render.

```bash
# Run from the root of a locally cloned Canovia-web repository.
bash scripts/ops/prelaunch_pinned_mysql19_stage_local.sh \
  --destination "$HOME/Canovia-Aiven-Source-Review"
```

The local Git object database must **already contain** three exact immutable commits: audited tool `e04c646c78e021268801cacb1d8ef1b0ad276101`, old Live `15abdc9de6f24b5250fb255c579d1aa55facaf1a`, and candidate `6d7dbcfedd35f33ba2827ed8c7c3d98ca30fc3d6`. If a checkout is shallow or any object is missing, the tool **stops before creating a destination** (`PINNED_OBJECT_NOT_PRESENT_LOCALLY`); it never runs `git fetch` or updates a branch. The owner may separately refresh their local Git object database from their verified GitHub remote and rerun to a *new, nonexistent destination*.

On success this command creates only `$HOME/Canovia-Aiven-Source-Review/{audit,live,candidate}` plus `offline-verdict.txt`, with a private parent directory and **no production credentials**. `audit` is pinned to the independently CI-reviewed audit code from PR #752, rather than to a future moving `main`; `live` and `candidate` are exact source SHAs. It invokes only the reviewed collector's `--preflight-offline`, checks an explicit `PRODUCTION_RELEASE_AUTHORIZED: false`, and prints the sanitised classification `OFFLINE_PREPARED_NO_AIVEN_CONNECTION`.

**No live Aiven audit is run by the staging command.** It does not verify whether a real Aiven least-privilege account exists, whether its TLS CA is valid or whether current ledger has 94 migrations. After checking the offline result, separately review Aiven service identity and minimal grants privately, and obtain explicit Owner approval before performing any live SELECT. Do not share credentials, CA, host/user names, raw migration ledger or SHOW GRANTS in GitHub/ChatGPT. A clean local stage is **not** static or Web release approval.

## Operator-controlled procedure (only after explicit approval)

1. In Aiven Console independently identify intended service, TLS CA, MySQL major version, and the precise application database. Compare with the current Release Issue #488. Do not infer the target from an old shell profile or README.
2. Use an **existing** dedicated narrow *audit* principal with only `USAGE`, schema-level `REFERENCES`, and `SELECT` on `migrations`. Confirm via private `SHOW GRANTS FOR CURRENT_USER()`. Never use the app's privileged writer principal in this collector or issue new Aiven credentials/permissions in this procedure.
3. Locally obtain separate read-only Git checkouts at the **exact** above SHAs; inspect both locally; provide their absolute paths using `CANOVIA_PINNED_MYSQL19_LIVE_CHECKOUT` and `CANOVIA_PINNED_MYSQL19_CANDIDATE_CHECKOUT`. No moving branch, no unreviewed future migrations. The CLI rejects a changed checkout HEAD or an unexpected migration file set.
4. Supply the verified private `CANOVIA_P0_LOGIN_PATH`, `CANOVIA_P0_DATABASE` and `CANOVIA_P0_CA_FILE` plus deliberate `CANOVIA_PINNED_MYSQL19_OPERATOR_APPROVED=YES`, `CANOVIA_P0_TARGET_VERIFIED=YES`, `CANOVIA_P0_GRANTS_REVIEWED=YES`; on an **operator's local machine**, invoke `bash scripts/ops/prelaunch_pinned_mysql19_collect.sh`. This description is **not** a request to perform the audit without approval.
5. Only share the sanitized JSON result code/counts, not raw ledger/GRANTS/TLS details or application rows. `BLOCK` (including partial 92/93/95/etc ledger states or missing metadata) stops the review. `REVIEW_REQUIRED` indicates the exact metadata/ledger pattern matched, with **false** for all production release, runtime grants, recovery and device acceptance claims.

## Critical independent privilege gate

The disposable MySQL CI runs `SET GLOBAL log_bin_trust_function_creators=1` under **CI root only**, because some MySQL configurations with binary logging restrict creation of triggers/functions. The SELECT-only auditor sees the configured flags but **cannot test or prove the actual Canovia application/migration runtime principal's `TRIGGER`, `CREATE`, `ALTER`, INDEX or other DDL privileges**. Even an observed global `trusted_function_creators=ON` does **not** prove app-user grants; and the audit principal must never be upgraded to those privileges. If actual runtime privileges, Aiven policy or trigger compatibility cannot be established independently without unsafe changes, keep the candidate **HOLD/NO-GO**, do not attempt a production migration. Do not infer missing triggers from `information_schema.TRIGGERS` with restricted visibility.

## Other independent NO-GO gates

- Metadata does not certify real Plan/member data consistency, uniqueness conflicts, MySQL nontransactional DDL crash recovery or all 19 physical column/FK/index contracts. Preflight is intentionally *partial*, not a complete Aiven compatibility seal.
- Aiven Free backup existence is not an independently validated restore. Keep backup/restore, RTO, write coordination and forward repair open.
- Neither this script nor CI queries or modifies Aiven automatically. Do not run `artisan migrate`, `migrate:fresh`, `SET GLOBAL`, DML, grant changes or Render deployment through this procedure.
- No public Release Level, OAuth/MCP delegated grant, G3 storage or iOS device acceptance follows from a passing SQL preflight.
- Only [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488) tracks residual release risks; [fixed-scope evaluation](PRELAUNCH_FIXED_SCOPE_RELEASE_EVALUATION_2026_10_15.md) and [PR #729](https://github.com/1kz-ma1/Canovia-web/pull/729) remain separate evidence.

## Exact operator handoff and confidentiality boundary

Before the *first* real Aiven network connection, the owner must check that the existing narrow audit user is indeed available on the intended service, that `SHOW GRANTS` exactly matches the *same database identity* configured in the Laravel production connection, and that the two Git checkouts are at the historical Live and frozen release candidate SHAs. No credentials, raw grants, migration list, CA file, DSN, or app rows should be copied into GitHub issues or this chat. The MySQL operator collector intentionally **blocks** if login path, CA, local source folders, target verification, or any required human approval is absent. Its output is only a sanitized enum and counts. If any new privileges would need to be created or elevated, stop and request separate permission rather than silently change production.

The Make-believe audit account on disposable MySQL 8.0/8.4 demonstrates query feasibility and negative permissions, **not** evidence that the real Aiven `pacekeeper` database is at exactly 94 migrations or has compatible grant/trigger/DDL settings. The previous Render startup logs saying `Nothing to migrate` are useful but not a complete DB ledger. An actual Aiven metadata-only read remains a distinct owner-controlled operation before the Web wake /health test.

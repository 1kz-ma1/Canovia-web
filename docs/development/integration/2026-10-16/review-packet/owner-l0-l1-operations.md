# Owner execution ticket — general L0 / explicitly approved testers L1

2026-10-16 JST · **PREPARATION ONLY / HOLD**. Canonical record: [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6205143389). Candidate **`641e2105efccf6b1c2f13c3013eae0c638e5aa9d`**, branch `release/2026-10-16-641e2105`. The owner has selected this audience policy as the recommended proposal; this is not authorization to change actual settings or deploy. Keep the candidate pinned even if documentation main advances.

Reuse [eligibility assessment](initial-production-eligibility.md) and its existing PHP/browser/MySQL evidence. Do not rerun the full suites just to fill this ticket. The old `32733572` remains NO-GO. Do not use the old fixed 19-migration collector/gate or #513 for this candidate.

## 1. Audience setup and restoration — after the corresponding owner GO only

| Item | Owner action / acceptance |
| --- | --- |
| Private roster | Record stable user ID, privately verified identity, approval/approver, prior override, intended L1, start/end time, revocation operator and outcome. Keep the roster out of GitHub/chat. No automatic expiry exists: schedule manual revocation. |
| General audience | Effective `CANOVIA_PUBLIC_RELEASE_LEVEL=0` on every serving instance; verify the resolved level and cache/restart result after a separately approved change. Do not modify APP_KEY, session backend/cookie/domain or auth configuration as part of audience selection. |
| Existing grants | Privately inventory **all** existing overrides and administrator/preview assignments. L2/L3 grants continue to work above public L0; an admin selector/search is not proof of a complete inventory. Unknown recipients or unapproved higher grants → STOP. Resolve each under explicit owner approval; no bulk reset or deletion. |
| L1 testers | Existing Super Admin UI: `/admin/economy`, select the verified account, **User Release Level → L1 → Release Levelを変更**. Use the release override control, not the separate product/paid Entitlement grant controls. No direct SQL update or hidden API bypass. |
| Verification | Use ordinary general/tester accounts with **Admin Preview cleared to Public**. General L0: Overview/account/core Plan access; dedicated Study/Development direct links redirect, their operational writes deny. Tester L1: Study/Development works; Career/internal writes remain denied unless a separately reviewed grant exists. Foreign Plan access must remain denied. |
| Existing-user impact | Ordinary users lose dedicated Study/Development entry points at L0. Plans/tasks, saved Workspace preference and data remain; permitting L1 restores access. Explain this impact before rollout. L0 is not a universal kill switch for all legacy Plan/Task operations. |
| Remove/restore access | To revoke a tester, choose **Publicを継承** for that account and verify L0 denial on the next real request, including a cached deep link. To regrant, reverify identity/approval and select L1 again. Keep the prior-value record; do not blindly restore an old L2/L3/L4 value. Stored regular-user L4 is ignored by this candidate but can become dangerous with an older image. |
| Incident | Stop new grants; revoke affected L1 grants to Public and keep public L0. Verify every instance/cache and affected session. Release override revocation does not revoke external OAuth/MCP consent: use its existing revocation procedure if applicable. If L0/auth/health is broken, use the approved traffic stop and forward-recovery plan; no automatic old-image rollback or data deletion. |

Record only audience counts and pass/fail in #488. Actual global settings/grants and revocations are later owner operations, not performed by this ticket.

## 2. Owner-local Aiven read-only audit for all 20 source additions

**Prerequisites / STOP before connecting:** separate owner audit approval; privately verified Aiven service/database/engine; clean checkout at the full candidate SHA; MySQL 8.0/8.4 client; verified Aiven CA and existing metadata-only login-path. No CI/cloud unattended execution. No account creation/GRANT/REVOKE, network-policy change, migration or application boot here. If the account is missing/overprivileged, stop and use the separately approved [inspector setup procedure](../../../AIVEN_P0_INSPECTOR_TWO_STAGE_SETUP_2026_10_10.md).

Use a private operator Mac/terminal. The already configured non-secret variables are `CANOVIA_P0_LOGIN_PATH`, `CANOVIA_P0_DATABASE`, `CANOVIA_P0_CA_FILE`; credentials stay in the existing login-path. Never put passwords/DSNs in commands, environment variables, chat or GitHub. Do not print raw grants, SQL output or client errors. The following commands are prepared for the owner; the assistant has not run them against Aiven.

```bash
set -euo pipefail
umask 077
test "$(git rev-parse HEAD)" = 641e2105efccf6b1c2f13c3013eae0c638e5aa9d
test -z "$(git status --porcelain)"+: "${CANOVIA_P0_LOGIN_PATH:?existing metadata-only profile required}"
: "${CANOVIA_P0_DATABASE:?privately verified database required}"
: "${CANOVIA_P0_CA_FILE:?verified Aiven CA required}"
test -r "$CANOVIA_P0_CA_FILE"
test -z "${MYSQL_TEST_LOGIN_FILE:-}"
test -z "${MYSQL_PWD:-}${DB_PASSWORD:-}${DB_URL:-}${DATABASE_URL:-}"
audit_dir="$(mktemp -d "${TMPDIR:-/tmp}/canovia-641e2105-audit.XXXXXX")"
mysql_args=(--no-defaults "--login-path=$CANOVIA_P0_LOGIN_PATH"
  --ssl-mode=VERIFY_IDENTITY "--ssl-ca=$CANOVIA_P0_CA_FILE"
  "--database=$CANOVIA_P0_DATABASE" --batch --raw --skip-column-names
  --skip-auto-rehash)
mysql "${mysql_args[@]}" -e 'SHOW GRANTS FOR CURRENT_USER()' \
  > "$audit_dir/grants.tsv" 2> "$audit_dir/client-errors.private"
python3 scripts/ci/p0_mysql_metadata_grants_guard.py \
  --database "$CANOVIA_P0_DATABASE" --input "$audit_dir/grants.tsv"
mysql "${mysql_args[@]}" < scripts/sql/p0_mysql_readonly_full_migration_ledger.sql \
  > "$audit_dir/ledger.tsv" 2>> "$audit_dir/client-errors.private"
python3 scripts/ci/p0_mysql_full_ledger_offline_check.py \
  --input "$audit_dir/ledger.tsv" > "$audit_dir/ledger-verdict.json"
```

The grants guard permits only the existing metadata scope plus SELECT on `migrations` (and optional USAGE). Failure at any line → STOP; no more-privileged fallback. A checker's exit 0 / `REVIEW_REQUIRED` is **not GO**. Verify privately that every historical 94 migration ID is applied and pending IDs are a subset of the exact 20 additions; counts alone cannot establish this. This offline check reuses the same parser:

```bash
python3 - "$audit_dir/ledger.tsv" <<'PY'
from pathlib import Path
import runpy, subprocess, sys
gate = runpy.run_path('scripts/ci/p0_mysql_full_ledger_offline_check.py')
try:
    applied = set(gate['parse_full_ledger'](Path(sys.argv[1]).read_text()))
    expected = gate['expected_migration_names']()
    paths = subprocess.check_output(['git', 'ls-tree', '-r', '--name-only',
        '15abdc9de6f24b5250fb255c579d1aa55facaf1a', 'database/migrations'], text=True)
    baseline = {Path(p).stem for p in paths.splitlines() if p.endswith('.php')}
    ok = (len(baseline) == 94 and len(expected) == 114
          and len(expected - baseline) == 20 and baseline <= applied <= expected)
except Exception:
    ok = False
print('SOURCE20_SCOPE_REVIEW_REQUIRED' if ok else 'BLOCK_SOURCE20_SCOPE')
sys.exit(0 if ok else 2)
PY
```

Next collect **schema metadata only**, using the same verified client. No SELECT from users/sessions/learning/content tables. Each result stays in the private directory; stop on errors, incomplete visibility or unknown objects.

```bash
mysql "${mysql_args[@]}" > "$audit_dir/physical.tsv" \
  2>> "$audit_dir/client-errors.private" <<'SQL'
SELECT 'engine', VERSION();
SELECT 'table', TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME;
SELECT 'column', TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE,
 IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
ORDER BY TABLE_NAME, ORDINAL_POSITION;
SELECT 'index', TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX,
 COLUMN_NAME, SUB_PART, INDEX_TYPE, IS_VISIBLE
FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
SELECT 'fk', k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION, k.COLUMN_NAME,
 k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
 (k.REFERENCED_TABLE_SCHEMA=DATABASE()) AS parent_is_current_schema,
 r.UPDATE_RULE, r.DELETE_RULE
FROM information_schema.KEY_COLUMN_USAGE k
JOIN information_schema.REFERENTIAL_CONSTRAINTS r
 ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME
 AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION;
SELECT 'trigger', TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION,
 ACTION_TIMING, ACTION_STATEMENT
FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()
ORDER BY TRIGGER_NAME;
SQL
```

Match **every** applicable `up()` column/type/null/default/index/FK/trigger contract at the fixed source. An applied ledger row with a missing/wrong object, or physical remnants of a pending migration, is a partial-DDL STOP requiring review. A pending migration's absent new objects can be expected pre-state; do not label all pending objects as failures or assume absence proves readiness.

| Addition, in source order | Physical review target (full shape comes from that migration's `up()`) |
| --- | --- |
| 1 | `development_activity_observations`: source revision ID / source-updated timestamp |
| 2 | Same table: nullable `source_epoch` and its index |
| 3 | `plans`, `plan_members`: authorization epochs, unsigned/default 1 |
| 4 | `canovia_plan_authorization_epoch`, `canovia_member_authorization_epoch`: full MySQL trigger definitions |
| 5 | `canovia_member_binding_immutable`: full immutable-binding trigger definition |
| 6 | `study_recall_candidates.review_provenance` |
| 7 | `mcp_delegated_grants.expires_at`: nullable new contract |
| 8 | `mcp_linked_subjects`: active fingerprint, active unique and history index; removal of old global uniqueness in the correct order |
| 9–13 | `private_file_cleanup_entries`, `private_file_path_fences`, `private_file_upload_intents`, `private_file_generation_evidence`, `private_file_write_attempts`: all five shapes, uniques and dependencies |
| 14–15 | `development_operational_profiles`, `development_operational_targets`: original shapes / Plan relations |
| 16 | Targets: manual service-check fields |
| 17 | Profiles/targets: storage-revision fence fields |
| 18 | `mcp_study_result_proposals`: ownership/grant/task FKs, uniqueness/status/expiry |
| 19 | `learning_context_watch_states`, `learning_context_change_records`: both shapes, unique keys/FKs |
| 20 | `learning_context_source_epochs`: `id`, unique Plan FK with cascade, unsigned epoch default 0, `last_mutation_kind`, timestamps |

The full 20 filenames/blobs are the unchanged migration delta in the [existing manifest](../source-manifest.json); the candidate's complete inventory is in [its exact-SHA CI artifact](https://github.com/1kz-ma1/Canovia-web/actions/runs/38829612562). The old manifest's code observation SHA is historical; migration blobs are unchanged for this candidate.

**Trigger visibility is a separate STOP:** an empty metadata result does not prove no trigger exists. Obtain complete privileged metadata/definition evidence through a separately approved owner/DBA read, plus the **actual migration principal's** CREATE/ALTER/INDEX/REFERENCES/TRIGGER and managed binary-log policy evidence. Do not give the narrow auditor TRIGGER/admin rights merely to finish this ticket. No privilege, DDL-policy or Aiven plan change is authorized here.

Share only: candidate SHA, JST observation time, scope/ledger counts, `BLOCK`/`REVIEW_REQUIRED`, physical/trigger/privilege evidence completeness and redacted discrepancy codes. Keep service identity, roster, raw grants/schema/ledger/client errors private; retain/encrypt/dispose them under the owner's evidence policy. Do not put raw output in #488.

## 3. Minimal backup / isolated restore / device checklist

- [ ] Backup: independently confirm actual recovery-point timestamp, retention, consistency and owner-approved RPO/RTO. Snapshot existence alone is insufficient. No available restore route / paid plan or fork required without approval → STOP.
- [ ] Restore into a **separate verified target**, never over production. Block mail, jobs, webhooks, OAuth/provider calls and public traffic; use an isolated host/cookie/session scope. If encrypted-row readability is checked, deliver the original APP_KEY only through approved secret handling; never regenerate the production key or paste it into this ticket.
- [ ] On the isolated copy, verify privately retained user/Plan/session/evidence/identity data, FKs/indexes/triggers and old/new login/CSRF. Rehearse the actual audited pending set at candidate 641e2105, verify all 114 source/ledger postconditions and measure restore duration against RTO. Failure/unreadable encrypted data/missing acceptance evidence → STOP; our synthetic CI is not a substitute.
- [ ] Pre-deploy candidate acceptance: Safari/mobile/PWA on an approved isolated candidate target, general L0 and ordinary L1 tester accounts, login/logout/session, old Plan read, Study draft/repair/reload/assessment, Development next Action, foreign Plan denial and grant/revoke/regrant. Linux Chromium is not iPhone proof.
- [ ] Actual Live/iPhone/WKWebView acceptance is **after a separately approved verification deploy**, before broader tester grants. Verify the expected production merge SHA and its candidate-identical tree, trusted/canonical origin, health, login/CSRF and L0 first; then allow one approved L1 tester and repeat Study/Development/revoke. Xcode/App Icon acceptance remains open; any distribution-scope exception needs an explicit owner decision. No binary acceptance is inferred from a Web deploy.

## 4. Owner order and STOP sheet

Record **two SHA identities**: source candidate is always `641e2105...`; the later two-parent production merge has a different SHA. Verify that the merge retains the pinned release parent and its full tree equals the candidate, then require Render Live to equal that **production merge SHA**. If an isolated migration job uses `apply`, its full approved SHA must match that job's actual runtime commit; never copy `641e2105` onto a different runtime or leave Web startup in apply mode.

| Stage | Owner operation, only after its explicit approval | STOP / next permission |
| --- | --- | --- |
| A | Adopt candidate 641e2105 and private L1 roster; accept L0 impact; record effective Render/flags/session settings and production required-check protection | Wrong/moving SHA, unknown grants, unapproved user impact, unverified branch/Auto Deploy/protection → HOLD |
| B | Run the approved read-only Aiven audit above | Wrong identity/TLS/grants, historical ledger gap, unknown/partial schema, missing trigger/principal evidence → STOP; never auto-apply |
| C | Approve backup + isolated restoration and pre-deploy device checks | No independent restore/RPO/RTO or candidate audience acceptance → STOP; any exception needs a separate documented owner policy decision |
| D | Approve the exact migration/traffic plan: stop all old HTTP/queue/scheduled writers, obtain a consistent recovery point, apply audited pending migrations once in the fixed migration job **before new Web serving** | Missing writer fence, stale recovery point or any DDL failure → keep writers closed; review partial physical state and forward repair/restore, no auto retry/down/ledger edits |
| E | After schema/ledger/retained-data checks, separately authorize release→production **merge commit**, then one manual verification deploy with Auto Deploy OFF; normal Web startup is `check` | Wrong source/merge/Live SHA, startup/health/session failure → close traffic and invoke approved recovery; no automatic old-image rollback |
| F | Verify actual Live L0 on desktop/iPhone, then grant **one** approved L1 tester via existing UI; perform the device smoke above | Any leak, foreign access, consent/session problem or failed flow → revoke that grant to Public, keep general L0, stop expansion |
| G | Explicitly accept evidence before granting remaining roster members one by one; record each outcome and scheduled revocation | Unknown account/expiry/operator, incomplete smoke or unexpected higher access → STOP. General audience remains L0; no general L1 or Auto Deploy enablement |

Stages A–G are prepared actions, **not executed or approved by this document**. Production promotion, Render changes/deploy, Aiven connection/change and real audience changes remain prohibited for this assistant in the current task. Preserve #513 unchanged.

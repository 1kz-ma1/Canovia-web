# Release #488 — Aiven runtime DDL authority and independent recovery decision

> 2026-10-15 · State: **OPERATOR EVIDENCE OPEN / PRODUCTION NO-GO**. Source Live Web SHA: `15abdc9de6f24b5250fb255c579d1aa55facaf1a`. Exact review candidate: `6d7dbcfedd35f33ba2827ed8c7c3d98ca30fc3d6`. Never substitute moving `main`. The 94→113 synthetic migration and metadata checks passed in GitHub Actions, **not on Aiven**.

This is the next owner/operator checkpoint after [the SELECT-only Aiven metadata gate](PRELAUNCH_PINNED_MYSQL19_SELECT_ONLY_AIVEN_AUDIT_2026_10_15.md). No service, credential, backup, data or Render setting is altered by this document or its offline validator.

## What is independently known

- On both disposable MySQL 8.0 and 8.4, pinned Live source 94 migrations → 113 migrations applied successfully while preserving *synthetic* actor/member/observation/MCP link/grant rows. Those CI jobs use a disposable root account to set `log_bin_trust_function_creators=1`; they do **not** prove the managed Aiven application principal's privileges.
- GitHub `main` and Render deployment are separate. Render Web and static Welcome Auto Deploy are OFF; Live Web remains the historical SHA; planned production promotion is not authorized.
- Aiven's current MySQL Free tier documentation explicitly includes backups but says **no forking**: https://aiven.io/docs/products/mysql/concepts/mysql-free-tier . Its backup documentation discusses daily full backups and binlogs/PITR, but backup existence does **not** demonstrate operator-accessible restore or a rollback path: https://aiven.io/docs/products/mysql/concepts/mysql-backups .
- Aiven documents `CREATE`, `ALTER`, `DROP`, `INDEX`, `REFERENCES` and `TRIGGER` as distinct configurable service-user privileges: https://aiven.io/docs/products/mysql/howto/manage-service-users . Review the **actual Render Laravel DB migration principal**, not the purposely underprivileged read-only `canovia_p0_audit` account. The `TRIGGER` privilege, binary logging policy and runtime engine combination must all be reviewed. A metadata query run with the audit principal cannot prove this.
- `docker/render-start.sh` runs `php artisan migrate --force` on Web startup. Thus **testing a manual Web deploy in production would immediately risk DB writes**. Do not test runtime DDL permissions by attempting that deployment.

## 1. DDL principal authority gate — owner/operator only, no writes

1. Identify the Aiven service, `pacekeeper` database, TLS-pinned endpoint and the real DB account configured for Laravel migrations in the Render production service. Confirm *account identity and role* privately; do not paste environment variables, connection URLs, database usernames, SHOW GRANTS output or passwords into GitHub or ChatGPT.
2. With existing authorized access, inspect the **actual runtime principal's effective grants** (read-only `SHOW GRANTS FOR CURRENT_USER()` under that same principal, or equivalent owner-reviewed Aiven configuration). Verify scope of `CREATE`, `ALTER`, `DROP`, `INDEX`, `TRIGGER`, `REFERENCES` and application DML required for the new migrations. An account's mere presence in Aiven Users is **not** sufficient. If roles, grant scope, stored routine restrictions or grants cannot be interpreted accurately, status is **UNKNOWN/BLOCK**, not assumed pass.
3. Compare the 13-status SELECT-only metadata snapshot's MySQL version, `log_bin` and `log_bin_trust_function_creators` against the actual engine/trigger DDL policy. Do not change global variables/grants on Aiven. If trigger creation is unsupported or requires settings/privileges unavailable on the Free service, hold release and redesign/narrow the migration before another candidate SHA is frozen.
4. Record only a confidential reviewer sign-off outside the repo, then optionally enter *booleans only* in a local [attestation packet](#3-the-optional-strictly-offline-checker). This is human attestation, not independent technical proof.

## 2. Independent restore gate — Free plan cannot fork

Two viable paths to **consider**, each subject to a separate owner decision and data-protection controls:

- **Controlled logical export / isolated restore**: choose an operator-approved, encrypted private logical-backup method (e.g. Aiven's mysqldump/restore guidance: https://aiven.io/docs/products/mysql/howto/migrate-database-mysqldump ) and restore only into an isolated local or other **independent** MySQL 8.4 environment. Verify tables, ledger, row counts/foreign keys without exposing identities, PII or backups in source control, chat, or CI. Measure restoration duration and check retention/transport risk; do not perform export during heavy production load without scheduling and owner approval. Restoration requires sufficient privileges on the destination. Never restore into Live.
- **Plan upgrade / independent service backup fork**: a separately approved paid capability could allow a recovery rehearsal (Aiven's fork workflow is in its docs), but Free does **not** permit forking. Do not change billing or provision the service without explicit owner authority.

A preexisting Aiven Free full backup/screenshot, Aiven automatic power-on restore mechanism, an application image rollback, a synthetic GitHub CI restore, and an untested `mysqldump` command **do not satisfy** independent real-data recovery. Test on an isolated destination with proper consent and controls; protect any generated artifacts as regulated/private operational data.

Before DDL, define stop-the-world write coordination, session/queue/webhook handling, copy/preservation of changes after backup, a realistic RTO, and **forward repair** for MySQL's partially committed DDL. If there is no independently tested route or accepted rollback window, **no Web production deploy**.

## 3. The optional strictly offline checker

`scripts/ci/release488_runtime_ddl_restore_gate.py` consumes *one locally created JSON file* containing just exact pinned SHAs and the **20 fixed boolean manual attestation fields** grouped into `identity`, `ddl`, `recovery`. It validates field coverage and pins, rejects unknown/unexpected fields and unsafe data, and prints **only a hardcoded classification** (never raw evidence, names, hosts or account details). All fields start `false`. No ChatGPT/Aiven/Render network access or mutations occur.

To generate the **shape only** with default false answers on your own computer:

```python
from scripts.ci.release488_runtime_ddl_restore_gate import FIELDS, LIVE, PINNED
import json
packet = {"version": 1, "live_sha": LIVE, "candidate_sha": PINNED,
          **{s: {k: False for k in keys} for s, keys in FIELDS.items()}}
from pathlib import Path
path = Path.home() / "local-private-runtime-ddl-restore.json"
with path.open("x", encoding="ascii") as f:
    json.dump(packet, f, indent=2)
path.chmod(0o600)
```

Once an owner has independently reviewed a specific item, a boolean can be updated **locally**. NEVER copy raw `SHOW GRANTS`, backup content, host/DB credentials, full MySQL ledger or owner records into JSON. Store only on an encrypted operator device, outside the repository (the example uses the home directory). Restrict permissions before adding any real attestations. Keep it out of Git and ChatGPT; do not commit or upload. The validator explicitly cannot distinguish honest attestations from typed booleans:

```bash
python3 scripts/ci/release488_runtime_ddl_restore_gate.py --input /private/path/local-private-runtime-ddl-restore.json
```

- `BLOCK` if any required item is false, malformed, wrong SHA, duplicate field or unexpected field.
- If all 20 are true, output is still `REVIEW_REQUIRED` with `MANUAL_ATTESTATION_PACKET_REQUIRES_INDEPENDENT_REVIEW`, `release_authorized=false` and every technical-proof field **false**. A passing manual packet **never promotes a release**. The owner must separately review the underlying confidential evidence, restoration, effective grants, release scope, app flags and device acceptance.

## 4. Distinct remaining release decisions

Track in [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488): independently review real Aiven 94→113 ledger/physical schema (metadata audit not yet run), actual runtime principal DDL/trigger policy, independent restore with RTO/data protection, real device/user acceptance and explicit production owner GO. Static-only intro deployment may be approved separately without triggering a Laravel migration, but its real URL, routing and communication still need their own acceptance.

**The old `#513` 2-migration review, the pinned 19-migration review and future `main` SHA are separate candidates. Never merge into `production` or deploy based on a checklist passing alone.**

## 2026-10-25 Cutover recovery addendum (114-source; no real operations)

This supplements this existing recovery authority and the Shared DB Strategy §15;
older 113-count/pinned-source entries above remain dated evidence. Current source
has 94 preserved baseline migrations and 20 additions/114 total, not a measurement
of today's real ledger. Exact reviewed SHA/tree/CI and all-path inventory are
recorded in #488. No moving-main adoption or real backup/DDL/restore is authorized.

- **Before writes stop:** identify every Web/worker/cron/queue/webhook/auto-restart
  path, freeze the reviewed source and image/config/key/IdP/private-file origin,
  assign owner incident responsibility/contact and private evidence custody.
- **Before any DDL:** stop old new-write admission and all consumers/restart paths;
  independently observe in-flight/remote completion or remain HOLD. Inventory
  pending jobs without purge/replay. Take the separately approved fresh consistent
  backup AFTER this fence, record snapshot UTC/last-write point and restore window.
  Metadata-audit SELECT rights do not grant permission for a real dump or trigger
  visibility. Do not widen the existing narrow audit principal to pass checks.
- **Independent restore:** use a separately approved isolated destination with
  compatible MySQL/version/collation/engine/capacity/DEFINER/trigger/binlog policy.
  Encrypt dump/manifest/transport, keep key separate, define access/retention/
  verified deletion; preserve APP_KEY/private files and IdP settings privately.
  Compare schema and meaningful rows/IDs/Account→Plan→Task/history/Context,
  ownership/auth failures/FK/UNIQUE/trigger behavior. A row count alone is not
  parity. Record actual start/end/verification times, not synthetic CI RTO as real.
- **RPO/RTO:** owner must bound data between last consistent backup and write fence
  (RPO), and time to verified isolated restore+safe application recovery (RTO).
  No measured real time or accepted bound exists in this task. Aiven Free Full
  records are existence evidence only; Free/Developer Fork unavailability remains.
  Synthetic mysqldump→independent restore is NOT restoration of Aiven Full itself.
- **Partial DDL:** MySQL commits may precede a disconnect/failure. Keep all writers
  stopped; preserve observed ledger/schema and private errors for operator review.
  Do not retry because the process exited or a migration name is missing. Existing
  strict comparisons, M08 old-name/definition checks, collision/partial-prefix and
  M04→M05 trigger prerequisites remain. Reproduce the observed prefix in an owned
  DB and review a new exact-SHA forward repair; validate parity before resuming.
  No ledger edits, migrate:fresh, blanket rollback/down, destructive auto-repair or
  speculative constraint drops belong in the production runbook.
- **After reopening any writer:** do NOT simply restore the earlier snapshot.
  Account deletions, consent/grant revocations, Session invalidations and new
  Account/Plan writes since snapshot must not be resurrected/lost. Without a
  trusted reconciliation path/evidence, recovery is BLOCKING. A signature alone
  does not implement reconciliation. The no-post-snapshot-write window is usable
  only with an independently proven continuous fence and a fresh accepted backup.
- **Safe restart:** require actual writer absence, completed physical/ledger and
  data verification, recovery/incident acceptance, fixed repaired source/runtime/
  config/cache and a fresh scoped owner decision. Default startup is check-only;
  the approved one-off apply runs once while Web/consumers remain stopped. Do not
  use app image rollback as proof that the 114 schema accepts an old writer.
  Reopened remote/in-flight uncertainty, drift or a supervisor restart means STOP.

The current owned cutover rehearsal verifies local process order/SQLite data and
HTTP Session continuity; it does not execute the 15ab binary or real MySQL upgrade.
Existing MySQL8.0/8.4 upgrade/partial-DDL/data-retention/logical-restore proofs are
reused. Real backup/restore/reconciliation/principal/binlog and actual deployment
remain LIVE_VERIFICATION_REQUIRED / PRODUCTION_NO_GO_HOLD.

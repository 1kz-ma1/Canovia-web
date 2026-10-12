# Release #488 — Whole-production-delta safety review (2026-10-12)

Status: **PREDEPLOY / NOT AUTHORIZED**. Review only; no production data read/write or Render deploy. This is an operational snapshot, **not a certificate that a subsequent `main` is safe**.

## Verified starting point

| Surface | Pinned observation | Limit |
| --- | --- | --- |
| GitHub `main` at review start | `7f73cbdc4e620f5d36ce97a1b7ec169ebeff8e2b` | Re-pin if main advances; do not deploy a moving branch without exact SHA |
| Render `My Workspace` / Canovia production | `srv-dagfn2id0e5s73cac7ng` — `dep-db5fgl942hec738pbq30` **live** at `15abdc9de6f24b5250fb255c579d1aa55facaf1a` | Live deployment != latest main |
| Deployment policy | `autoDeploy=no` and `autoDeployTrigger=off`; Free Docker Web, Singapore | Must remain disabled; do not upgrade hosting |
| GitHub compare | `15abdc9..7f73cbd` = **28 commits / 152 paths**, including new Learning features | Review *all* paths, not only PR #490 |
| Production DB repair | #418 closed after verified FK 5/5, index 11/11, ledger 94/94 and owner Mac/iPhone smoke | Those were historical post-P0 observations, not a current SELECT-only preflight |
| Learning integration | #490 merged as `0d36dac`; source PRs #456/#457/#484 closed unmerged | Automated tests passed for integration, physical-device UX of new code unknown |

## Blast-radius and disposition

| Area | Actual unshipped changes | Gate |
| --- | --- | --- |
| Database — **schema write on startup** | Single added migration `2026_10_12_010000_add_source_revision_to_development_activity_observations.php`, adding nullable `source_updated_at` TIMESTAMP and nullable `source_revision_id` BIGINT UNSIGNED. No default data rewrites and no new FK/index in the source. | **HOLD until disposable MySQL 8.0/8.4 tests** run full migrations, repeat migrations and rehearse legacy synthetic row survival. Confirm exact pending migration against live read-only Aiven ledger with no leaked names/user rows. Existing production data may contain writes since the last backup. MySQL DDL is not automatically rollback-safe. |
| Database — concurrent ingress | Development GitHub observation service now records source revision/order and locks rows for serialization. | Check new columns exist before webhook/bootstrap workers write; verify ordering and actor/repository bounds; ensure worker restart behavior. |
| Auth0 / MCP and JWT | New `firebase/php-jwt`, alternate signed JWT provider, account link mode selection, OAuth/MCP configuration. All newly introduced switches default OFF **in source**. | Verify effective **production runtime flags**, not just code defaults. Explicitly keep private delegated MCP tools/account-link/Plan consent OFF until separately approved. Test token mode denial, issuer/audience/client binding, revoked permissions, and no unauthorized scope fallback. Never print environment values, secrets, bearer tokens or JWT claims. |
| Native AI | Purpose authorization checked before and after provider call; feature entitlement and actor/Plan/Task scoped. | Run focused denial tests and verify typical allowed user journey; no subscription purchase or new API call authorized. |
| Intelligence / Planning | State snapshot, evidence freshness, plan draft guards, multi-domain continuity. | Run previous and new suites; ensure no cross-tenant data flow, stale authorization accepted, or background mutation. These are substantive runtime changes even though they do not migrate schema. |
| Learning UI | StudyPractice Focus + grade-to-continue, per-answer draft, saved reasoning, read-only history & recommendation. | Regression and mobile/desktop smoke after release, not claimed complete by CI; verify no Task progress mutation merely for continuing. |
| Startup / build | `Dockerfile` and `docker/render-start.sh` compare unchanged in this range; `render-start.sh` **automatically executes `php artisan migrate --force` before starting php-fpm/Nginx**. Composer lock changes due to new JWT dependency. | Code merge does not deploy while Render autoDeploy is OFF. Triggering a manual deploy WILL run schema migrations; separate explicit go/no-go required. Evaluate build, dependency lock, migrations, app health and runtime logs. |

## Added disposable CI protections (this release)
1. `.github/workflows/p0-disposable-mysql-schema.yml` runs full Laravel migrations on MySQL **8.0 and 8.4**, repeats migration, checks prior P0 interrupted-DDL restoration and now requires `NextReleaseMysqlSourceRevisionMigrationTest`.
2. The new test re-creates legacy table column shape **only on the disposable isolated MySQL instance**, inserts synthetic legacy observation with authorized parent rows, applies only the new migration, verifies all existing identity fields and row counts remain and new nullable column metadata agrees, then checks a new revision value can be stored. A second full Laravel migration invocation follows; never call `down()` on live data.
3. Workspace CI runs Auth0 JWT/Account Link routing and denial, Native AI purpose fence and Development Observation ordering regressions together with prior Learning suites. This is automated coverage, not a real production OAuth or paid Native AI E2E.
4. Both workflows must complete **success at the exact PR SHA**. Record results before any merge.

## Production go/no-go (all steps not yet accepted)

- [ ] Re-pin exact GitHub `main` / release commit and compare with current Render live SHA; inspect new commits, migration diff, Docker startup, dependencies and any changed deployment toggles. CI result for old SHA does not cover new commits.
- [ ] Operator: obtain **fresh limited-scope SELECT-only** Aiven inventory of applied/pending migration names via approved restricted connection; expect exactly the one new nullable-column migration, and no unknown/unexpected pending DDL. Never accept summary count alone if it cannot distinguish an unknown pending migration. Do not use defaultdb; verify `pacekeeper` identity.
- [ ] Operator: ensure restorable **fresh** encrypted backup and separate tested recovery path, timestamp, capacity and agreed minimal write window. Previous P0 backup and restore evidence cannot stand in for an updated current snapshot.
- [ ] Explicitly inspect effective Render flags (MCP discovery/tools/delegated/consent, account link, Auth0 verifier selection), session/DB identity, Native AI driver and queue/bootstrap behavior **without exposing values**; preserve production MCP OFF unless owner separately approves activation.
- [ ] Review deployment startup `migrate --force` semantics, migration failure rollback contingency (fail closed; do not run `migrate:fresh`, drop columns or restore over live writes), and coordinated operator pause; agree exact target commit.
- [ ] Owner approves the manual Render deployment in its own step; do not turn on Auto Deploy. Live code remains old until that occurs.
- [ ] After deploy, read actual live SHA/healthy status and migration logs; check live schema columns/ledger with **read-only** metadata (no DDL by audit), SQL/5xx errors, login/session after restart, and the focused Learning flow across iPhone WKWebView/PWA/Safari and desktop.
- [ ] Keep rollback/backout strategy explicit. Application-image rollback does **not** rollback additive DB columns. If migration fails, avoid blind rollback/restore; halt, inspect affected data/state and choose an operator-reviewed forward repair.

**Security exclusions:** no user data, connection strings, secrets, sample production records, raw JWTs, backup file contents, workspace billing or production config edits are collected in this document. Issue #488 is source-of-truth for release gate and owner acceptance.


## Operator-only exact pending migration check (2026-10-12 follow-up)

The earlier P0 full-ledger classifier intentionally classifies *any known pending migration* as
\`REVIEW_REQUIRED\`; pending count 1 **does not prove that the one pending migration is
the expected release migration**. Release #488 therefore introduces an independent,
**opt-in** source-checked gate. It checks that **all** applied Laravel migration
IDs match tracked PHP files, the repaired P0 migration is present, and the *only*
pending file is \`2026_10_12_010000_add_source_revision_to_development_activity_observations\`.
It also reads **metadata only** for the old observation table and its two columns.
Any extra/unrecognized pending migration, unknown applied migration, partial
DDL, incompatible column types, missing table or invalid input blocks.

- The operator first verifies the **Aiven service + \`pacekeeper\` database name**
  and authenticates using the already reviewed, narrow metadata-only MySQL login path
  (schema \`REFERENCES\` and \`migrations\` table \`SELECT\`, no other data grants).
  Confirm the Aiven CA file and TLS \`VERIFY_IDENTITY\` behavior. If that read-only
  account is unavailable, **stop and request an operator path**, not a privileged
  fallback or Laravel production login.
- From a **pinned clean checkout** of the exact release SHA, run on the operator Mac
  (not GitHub Actions, Render shell, production app command or Aiven SQL web editor):

  \`\`\`bash
  CANOVIA_P0_OPERATOR_APPROVED=YES \
  CANOVIA_P0_TARGET_VERIFIED=YES \
  CANOVIA_P0_GRANTS_REVIEWED=YES \
  CANOVIA_RELEASE_488_CHECK=YES \
  CANOVIA_P0_LOGIN_PATH='replace-with-your-verified-local-profile' \
  CANOVIA_P0_DATABASE=pacekeeper \
  CANOVIA_P0_CA_FILE='/private/path/to/verified-Aiven-CA.pem' \
  bash scripts/ops/p0_mysql_operator_select_only_collect.sh
  \`\`\`

  Example profile/CA values are placeholders and must be replaced privately, never
  sent to a chat or issue. Never set \`MYSQL_PWD\`, \`DB_URL\`, app \`DB_PASSWORD\`,
  or run \`artisan migrate\` as part of this audit. Only safe fixed verdict codes
  and aggregates are emitted. Raw migration IDs live briefly in a private
  \`mktemp\` directory and are deleted on exit.
- Expected classification **before** the one additive migration:
  \`REVIEW_REQUIRED / EXACT_ONE_SOURCE_REVISION_MIGRATION_PENDING\`.
  If already applied with both correct nullable columns:
  \`REVIEW_REQUIRED / SOURCE_REVISION_MIGRATION_ALREADY_APPLIED\`.
  Either verdict is **not permission to deploy**. Any \`BLOCK\` or unexpected
  code means halt and reconcile live DB vs. exact checked-in migration set.
- A success result verifies neither the date/restoreability of an Aiven backup
  nor operator permissions, production MCP/Auth0 effective flags, worker state,
  app compatibility under load, client device behavior or release timing.
  Independently verify backup/restore and production flags without pasting
  credentials, connection details or raw app data.

The operator may share only the fixed \`release_488\` verdict code (not the raw
ledger or SQL), separately confirm fresh backup/restore evidence, and then
request a pinned-SHA manual deployment decision. Do **not** re-enable Auto
Deploy, run destructive DDL, or restore over live writes.

## 2026-10-13 update — dual migration gate, backups and isolated production

The original snapshot and prior pinned Aiven read-only inspection showed
**94/95 migrations** with exactly the source-revision migration pending.
That result applied to pinned SHA `3669c685` only and is **superseded for releases
containing later main commits**. New migration
`2026_10_13_021000_add_source_epoch_to_development_activity_observations`
adds nullable MySQL `CHAR(36) source_epoch` and a nonunique index. The
release gate now requires **both known pending migrations** and absent
columns/index, or both fully applied with valid metadata. Intermediate
partially applied, unknown pending, or mismatched column/index states BLOCK.
Expected restricted-Mac operator verdicts (still REVIEW_REQUIRED):
- `EXACT_TWO_SOURCE_OBSERVATION_MIGRATIONS_PENDING`
- `BOTH_SOURCE_OBSERVATION_MIGRATIONS_ALREADY_APPLIED`

The previously documented one-migration operator results **cannot** be reused
for this release. Re-pin the exact candidate SHA, use the previously reviewed
`canovia_p0_audit` login profile and verified Aiven CA to rerun
`CANOVIA_RELEASE_488_CHECK=YES` against the current pending set, and share
only sanitized verdict codes.

Owner screenshot from Aiven Free Backups confirms full snapshots from
2026-10-10, 11 and 12 UTC, latest 376.1 MiB, with PITR description.
**Restore testing remains unverified**: Free Fork & restore is disabled.
Do not upgrade/provision a new service without separate owner consent; decide
and document a practical recovery procedure and explicit residual-risk
acceptance before live schema writes.

A separate `production` branch has been initialized from the confirmed
Render live SHA `15abdc9`. Current Render remains on main with Auto Deploy OFF.
The intended long-lived policy is protected production promotions (preserving
ancestry via merge commits), one owner-approved manual initial deploy, then
auto deploy of owner-approved production commits **only**. See
`docs/CANOVIA_DEVELOPMENT_WORKFLOW.md`. GitHub admin branch protection and
Render watched-branch edits remain operator tasks. Both MySQL variants must
pass independent disposable tests for the two migrations before manual
release. iOS WKWebView receives live Web releases, so device verification
is mandatory.

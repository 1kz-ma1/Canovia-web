# Development Operational Readiness V5.8 — Migration compatibility, rollback and release gate

**State: implementation / disposable-CI-only, not a production audit or deployment.** 2026-10-14 JST.
**Owning lane:** Development; coordinate external deployment/DB actions with Platform.
**Source of authority:** [controlled production promotion](../CANOVIA_DEVELOPMENT_WORKFLOW.md), [V5.3 manual service confirmation](DEVELOPMENT_OPERATIONAL_MANUAL_SERVICE_ATTESTATION_V5_3_2026_10_14.md), [V5.7 storage ABA epoch](DEVELOPMENT_OPERATIONAL_STORAGE_EPOCH_V5_7_2026_10_14.md), [Aiven release hold](https://github.com/1kz-ma1/Canovia-web/issues/488).

## What V5.8 actually adds

1. A deterministic **read-only schema-only SELECT** at `scripts/sql/development_operational_v58_schema_snapshot.sql`. It reads only `information_schema.TABLES` and `information_schema.COLUMNS` within `DATABASE()`; its fixed eight output classifications cover the two existing tables and six V5.3/V5.7 added columns. It never reads Plan, User or Task rows.
2. An offline, exact-checked-out SHA comparison at `scripts/ci/development_operational_v58_release_gate.py` that accepts the fixed-schema TSV plus the **full Laravel migration ledger** from the existing select-only SQL. Only exact states are recognized. The status is always `BLOCK` or `REVIEW_REQUIRED` — **never GO**.
3. A destructive-schema-shape rehearsal **restricted to throwaway CI MySQL** 8.0 and 8.4, `tests/Feature/DevelopmentOperationalMysqlMigrationV58Test.php`: restore the old *column shape only* on a disposable MySQL, insert synthetic pre-migration Plan + Profile + Target rows, run V5.3 then V5.7 `up()` in that order, assert all pre-existing values survive, new service/epoch snapshots remain NULL, profile epoch defaults to 1, and old owner claims correctly become `stale`. The real migration ledger remains unchanged, and repeated Laravel migrate stays idempotent. **Never run this rehearsal against actual Aiven or any real database.**

No actual source of truth was read from Aiven as part of this work. This document does not certify the actual DB engine/version, current grant set, table size, backup retention, lock duration or restore capability.

## Exact release sequence: no implicit authorizations

**Gate 0 — Pin candidate & freeze scope**
- Recheck the latest `main`, and create **an exact immutable `release/YYYY-MM-DD-<sha>` reference** before any real DB inventory. Its SHA is the only acceptable source for the subsequent operator audit. Do not run a production audit against moving `main` and promote a different SHA.
- Review all commits/migrations between the verified Live SHA and candidate SHA, including unrelated P0/G3/Auth/Plan changes, Composer/deploy/startup configuration, worker tasks, payment/AI effects, and existing `production` branch policy. V5.8's two migrations are **not** necessarily the only migrations pending on the real DB. Any other pending migration makes the narrow V5.8 gate BLOCK until separately audited.
- GitHub Actions merge-result SHA and CI schema are disposable build evidence only, never an observed Live SHA.

**Gate 1 — Metadata-only inspection (owner/operator, separate approval)**
- Independently verify the exact Aiven production service, host, selected MySQL database (**`pacekeeper`**, not `defaultdb`), the effective DB account/grants, storage and connection override, the actual physical schema and Laravel migration ledger, at the pinned release SHA. Follow existing [P0 no-grant SELECT-only collector](../CANOVIA_DEVELOPMENT_WORKFLOW.md) and Issue #488. In an authorized operator shell, run only existing `scripts/sql/p0_mysql_readonly_full_migration_ledger.sql` and V5.8 SELECT query; keep raw outputs on the operator machine, not GitHub, CI, ChatGPT or permanent logs. Do **not** feed DB_URL, credentials or user rows into an AI tool.
- From an exact checked-out release candidate (`git rev-parse HEAD` = pinned SHA), run local:
  ```sh
  python3 scripts/ci/development_operational_v58_release_gate.py \
    --candidate-sha '<the-reviewed-full-40-char-commit-sha>' \
    --ledger '<operator-held-redacted-metadata-ledger.tsv>' \
    --schema '<operator-held-fixed-schema-states.tsv>'
  ```
- Only sanitized status codes may be shared; script never echoes raw inputs, SQL exceptions, hostnames, service identifiers or credentials. `REVIEW_REQUIRED` is an **invitation to continue human evaluation**, not an approval to run DDL. An unexpected/partial ledger or physical schema, missing tables, skipped prerequisite, invalid SHA or any unrelated pending migration is `BLOCK`.
- Do not assume the connected ChatGPT account/session is the application user's identity or a provider-limited read-only credential.

**Gate 2 — Compatibility / backup / production migration decision**
- V5.3 adds three nullable service attestation columns to `development_operational_targets`. V5.7 later adds three storage epoch fields: a nonnullable unsigned `development_operational_profiles.data_store_revision` with default 1, and nullable target `backup_store_revision`, `restore_store_revision`.
- Migration **order must be V5.3 before V5.7**; both table-creating migrations V3 and V4 must already be applied. Other pending migrations from the pinned release also need explicit reconciliation. Verify *actual* Aiven MySQL version, collation, table size, lock/ALTER algorithm, time/IO/cost budget, backups and a practical isolated recovery route. MySQL DDL is not assumed transactionally reversible; partial ALTER/migration ledger divergence is a hard stop.
- **Never deploy new V5.7 app code before the required columns exist.** The new code reads `data_store_revision`, writes recovery store epochs, and requires V5.3 service-check columns. Prefer additive, backward-compatible DB preparation under explicit owner approval **before** switching traffic to the new app SHA, with separate metadata/ledger verification after DDL and no auto-deploy. **Do not assume old Live code tolerates every other unshipped migration**; test pinned old Live app against the new additive schema and new app against it, on isolated disposable clones. Exact compatibility depends on the entire unshipped release diff.
- Existing old backup/restore confirmations have NULL storage epochs: remain **stale** until explicit owner reconfirmation. No backfill or silent promotion. No Plan/Task/evidence mutation or external provider proof follows.
- Backup existence != isolated restore success. If the operator cannot prove a usable restore route, document risk and obtain a separately explicit owner go/no-go. No actual Aiven schema modification is authorized by this PR.

**Gate 3 — Production promotion and controlled verification**
- Follow existing #488 production procedure: require approved protected `release/* → production` PR, mandatory unconditional MySQL 8.0/8.4 + Workspace + Migration Recovery + Production Promotion Gate, **merge commit** to preserve ancestry, and separate production go/no-go. Never merge/push/deploy to `production` as a consequence of `main` CI alone.
- First release must keep Render Auto Deploy OFF until separately approved manual deploy. Verify the **actual** Render Live SHA, deployment/migration success, login/session, read/write, Development and Learning behavior, iOS WKWebView + Safari/PWA + desktop, error rate, and backlog. iOS WebView loads live Web changes; a green web test does not certify iPhone behavior. Store evidence under the exact tested SHA with redacted metadata.
- Any mismatch, unavailable backup path, unexpected DDL lock, partial migration, failed CI, invalid release head, failed auth/session smoke or error surge: **STOP**, do not attempt the next phase, preserve evidence, obtain owner/platform incident review.

## Rollback matrix — no blind down migration

| Failure phase | Safe immediate direction | Forbidden shortcut |
| --- | --- | --- |
| Before schema change | Remain on existing Live SHA; cancel cutover, update audit/owner decision | Deploy new code anyway |
| One/two additive ALTERs complete, app still old | Freeze rollout, compare physical schema with exact ledger; diagnose partial DDL; stay on compatible old code only if verified | `migrate:rollback` / `migrate:fresh` against Live |
| New Web deploy fails but schema is compatible | Disable any auto deploy; explicitly roll back **Web image** to last verified SHA, retest sessions/iPhone; retain additive columns until reviewed | Drop V5.3/V5.7 columns or overwrite current data |
| Schema incompatible or data drift | Stop writers and unsafe deploys, preserve snapshot/log evidence via approved mechanism, use individually reviewed restore/forward-repair plan | Guess a repair SQL or restore over live writes |
| Aiven backup/restore unknown | BLOCK production promotion, perform approved isolated recovery rehearsal | Treat backup toggle or user self-report as restore proof |

## What the automation **cannot** establish

All CI checks run on synthetic disposable MySQL, **not** the current Aiven environment. The V5.8 offline classifier is not a signer, provider authentication boundary, deploy scheduler, DB migration command, backup evaluator or production permission system. Even a perfectly matching ledger and schema remain `REVIEW_REQUIRED` and six independent verification/authorization booleans remain false.

**Residual:** operator-held TSVs and release identity must be independently authenticated by a human (or a separately approved, scoped provider integration). The tool does not validate live service identity or encrypted transport and does not replace Issue #488's full ledger + data-integrity audits.

## Acceptance

- MySQL 8.0/8.4 CI migration rehearsal preserves representative Plan, owner, profile and target rows, creates exact column types/nullability/defaults, leaves legacy epochs NULL and stale, ledger remains idempotent.
- Exact-status offline classifier accepts both pending, V5.3-only applied, both applied as `REVIEW_REQUIRED`; mismatched physical state, skipped V5.3, unknown/other pending ledger, malformed/oversized TSV and SHA mismatch produce only `BLOCK`.
- All output remains fixed enums; no hostnames, DB passwords, user records, API keys or service refs; no external connections.
- Required Development, Workspace, Migration Recovery, Platform, and disposable MySQL workflows pass for exact PR HEAD.
- **Production Aiven DDL / Render / production branch / live iPhone acceptance: NOT PERFORMED.**

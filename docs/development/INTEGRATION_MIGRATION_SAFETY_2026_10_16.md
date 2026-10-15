# Integration migration / startup separation — candidate

Authority: integration master PR #769, [production workflow](../CANOVIA_DEVELOPMENT_WORKFLOW.md), Issue #488. The old #513 and fixed 19-migration audit remain unchanged. Source inspection found **94→114 / 20 additions** at main `626a4ea6`; actual Aiven pending migrations remain UNKNOWN.

## Startup policy

`docker/render-start.sh` now invokes `startup-migration-policy.sh` before caches or HTTP. Default `CANOVIA_STARTUP_MIGRATIONS=check` performs a read-only source/ledger fence. Pending, missing, duplicate, unknown or unavailable history blocks startup without DDL. Physical schema, privileges, backup quality and release approval are **not** attested by a matching ledger.

An operator-approved migration run is separate: `CANOVIA_STARTUP_MIGRATIONS=apply` requires a full lowercase `CANOVIA_APPROVED_MIGRATION_SHA` equal to the runtime's `RENDER_GIT_COMMIT`. Missing/mismatched metadata blocks before DB commands. This is a configuration fence, not cryptographic image attestation or owner approval evidence. The operator must independently verify service/image/candidate identity and authorization.

Approved application runs once, with exceptions suppressed. A connection loss may follow committed MySQL DDL; there is no automatic production retry or down/restore. On failure, hold traffic/writers and have the operator classify partial state using the exact source and reviewed forward-recovery plan. After successful application the ledger is checked again. The default check mode should be restored after the separately accepted migration operation. No hosted variable was changed by implementing this policy.

The existing closed synthetic MCP staging migration path is retained only for `APP_ENV=staging` + `CANOVIA_STAGING_ISOLATED=true` without an explicit mode. Its bounded, redacted retries are not production permission. Existing staging cache/authentication/bootstrap gates remain intact.

## Disposable verification

The new `integration_mysql_upgrade.py` identifies its own Docker container by fixed name, label and loopback port before resetting **only its synthetic DB**. It extracts all 94 historical migration sources from immutable `15abdc9d...`, seeds the existing unchanged synthetic fixture, and seals the old columns/rows across users, Plans, members, artifacts, observations, MCP subjects/grants and sessions. It then applies all 20 new migrations, repeats migration and checks preserved hashes, exact 114 source/ledger identity, the twentieth source-epoch table, active/revoked identity retention and actual MySQL old-writer epoch/rebind fences. It never contacts Aiven.

Run from the activated existing checkout:

```bash
php artisan test tests/Feature/ReleaseSchemaLedgerFenceTest.php \
  tests/Feature/RenderStartupMigrationRetryV569Test.php \
  tests/Feature/McpIsolatedStagingBootstrapTest.php --colors=never
python3 scripts/ci/integration_mysql_upgrade.py --series 8.0 --artifacts /tmp/integration-upgrade
python3 scripts/ci/integration_mysql_upgrade.py --series 8.4 --artifacts /tmp/integration-upgrade
```

The containers must first be created as documented in `.github/workflows/integration-migration-safety.yml`. The runner refuses another target and a dirty tracked source tree. Each result records the exact source SHA, engine version and Docker image identity. The fixed 114 check intentionally requires a new audit when migration scope changes.

Targeted local startup/ledger/isolated-stage tests: **29 tests / 352 assertions, passed**. Exact-commit MySQL execution results are recorded separately after the candidate is committed. The new workflow is also called **unconditionally** by production promotion checks on production PR and push SHA. Its registration does not configure GitHub required checks or permit deployment.

## Mandatory unresolved release gates

Before any production action: fixed reviewed main candidate; actual Live/branch/Auto Deploy; exact read-only operator schema/ledger/grants; tested independently restorable backup and RTO; old-writer quiescence and compatible migration order; session/CSRF/Web/PWA/iPhone acceptance; owner GO. Startup failure is a deliberate HOLD, not permission to set `apply` without approval. Old-image rollback may reopen authorization/publication behavior and is not approved merely by additive schema compatibility. No migration down, production restore, OAuth change or #513 modification is included.

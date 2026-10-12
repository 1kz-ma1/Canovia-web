# Canovia Release #488 — Production Render environment inventory and no-secret preflight

Status: **REVIEW ONLY / NEVER RELEASE AUTHORIZATION** (2026-10-13).
Service: Render **My Workspace -> Production -> Canovia**, NOT
`canovia-mcp-staging`. Live at last independent check: `15abdc9`,
Auto Deploy OFF, still watching `main`. The protected GitHub
`production` branch is **not deployed**; draft PR #513 remains a separate,
owner-controlled promotion. The operator must recheck before release.

## Scope and evidence boundary

The owner-supplied masked Render Environment video showed **variable names
only**, NOT actual effective values. GitHub source shows what Laravel
**would** read; a mask is not proof that production has the correct setting.
This contract does not read Render secrets via the connector, does not
execute application code in production, and does not authorize changing any
environment variable or service setting.

### Inventory from masked Production UI

| Domain | Observed variable names (not values) | Review rule |
| --- | --- | --- |
| Core | `APP_DEBUG`, `APP_ENV`, `APP_KEY`, `APP_NAME` | production / false / valid persistent key. Never rotate APP_KEY casually: existing encrypted sessions/data can break |
| Database | `DB_CONNECTION`, `DB_DATABASE`, `DB_HOST`, `DB_PASSWORD`, `DB_PERSISTENT`, `DB_PORT`, `DB_USERNAME` | Real Aiven MySQL **pacekeeper** only. Independent service identity requires human confirmation. Laravel's `DB_URL`, if ever set, takes priority over individual fields |
| Sessions | `SESSION_CONNECTION`, `SESSION_DRIVER` | Database/Redis choice must agree with connection and transport. Source defaults to HTTPS-only and HttpOnly in APP_ENV=production; no need to add variables solely to repeat safe defaults |
| Redis | `REDIS_URL`, `REDIS_PERSISTENT` | Only if Redis-backed services are used; inspect that session store is reachable and persists as intended |
| AI | `CANOVIA_NATIVE_AI_DRIVER`, `CANOVIA_NATIVE_AI_MODEL`, `OPENAI_API_KEY`, `OPENAI_BASE_URL` | Cost, API key, purpose authorization and entitlement must be reviewed before enabling paid native AI |
| GitHub | `GITHUB_APP_ID`, `GITHUB_APP_INSTALL_URL`, `GITHUB_APP_PRIVATE_KEY_BASE64`, `GITHUB_APP_WEBHOOK_SECRET` | GitHub App permissions and webhook signatures; do not show key values or rotate without plan |
| Operator/admin | `CANOVIA_ADMIN_EMAIL`, `CANOVIA_SUPER_ADMIN_USER_ID`, `FEEDBACK_ADMIN_PASSWORD` | Treat as private; no echoing or unnecessary inspection |
| Logging | `LOG_CHANNEL` | Verify secrets, tokens and private payloads are not logged |
| Staging leftovers | `CANOVIA_STAGING_DB_MODE`, `CANOVIA_STAGING_POSTGRES_ID`, `CANOVIA_STAGING_POSTGRES_USER` | **Cleanup candidates**, not evidence of staging execution in production. Do not delete without checking dependencies and redeploy impact |

Values not visible in the screenshot, including `DB_URL`, `APP_URL`,
`SESSION_SECURE_COOKIE`, `SESSION_HTTP_ONLY`, and `CANOVIA_MCP_*`
must be verified in the effective runtime before declaring success.
**Absence from a screenshot is not proof of absence from service settings.**

## Actual source behavior

- `config/database.php`: MySQL `url=env('DB_URL')` may override
  `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
  `DATABASE_URL` is **not** the configured MySQL `url` key.
- `config/session.php`: `SESSION_DRIVER` defaults to `database`.
  Default secure cookie is true if `APP_ENV=production`, HttpOnly true,
  SameSite lax; explicit unsafe values can override defaults. Never change
  session driver without a session migration/login continuity decision.
- `docker/render-start.sh`: on **every production deploy**,
  `php artisan migrate --force` runs before HTTP services start.
- `docker/mcp-staging-start.sh` requires `APP_ENV=staging` and
  `CANOVIA_STAGING_ISOLATED=true`. Existing names of staging variables
  alone do not trigger that startup path. They should ultimately be removed
  from production after a separately reviewed non-impact cleanup.
- `config/canovia_mcp.php` has separately gated MCP discovery, tools,
  account linking, consent, introspection and Auth0 JWT flags. **Production
  rollout of private delegated MCP is not approved as part of #488**;
  absence of obvious flags in screenshot is not an attestation.

## Optional operator-only secretless CLI (when trusted runtime CLI exists)

`scripts/ops/release_488_production_env_preflight.php` is a pure PHP
classification that reads the current process's environment *in memory*.
It loads **no Laravel application or database**, makes no network requests,
writes nothing and prints only fixed result codes. It never prints or hashes
secret values; no `printenv`, `env`, or raw `php artisan config:show`.

When available, run inside the **actual production service process
environment** using a trusted shell, after checking service identity and
the pinned audited source. The extra opt-in flag is set for this one command,
not saved as an environment variable:

```sh
CANOVIA_PRODUCTION_ENV_AUDIT_APPROVED=YES \
  php scripts/ops/release_488_production_env_preflight.php
```

**Do not run this on a Mac with local .env and label it a Render
production result.** Render Free may not provide interactive Shell.
Do not upgrade hosting or add HTTP diagnostic endpoints merely to use it.
Do not upload a production `.env` file, paste credentials, or run
`printenv` to overcome an unavailable shell. The operator can instead
compare effective booleans locally in Render UI, sharing only pass/fail
assessments and masked screenshots.

Result is `BLOCK` for any known unsafe configuration and
`REVIEW_REQUIRED` for a plausible shape; even an empty-warning
`CONFIGURATION_SHAPE_MATCH_NOT_RELEASE` is **not** confirmation
of independent DB identity, actual MySQL TLS handshake, authenticated
session continuity, successful Aiven restore, or a release go/no-go.
`release_authorized`, `production_identity_verified`, and
`backup_restore_verified` remain **false** unconditionally.

The synthetic tests under `tests/ci/test_release_488_production_env_preflight.py`
exercise both acceptable and denied shapes without exposing any Render
environment. CI is not a production environment audit.

## Final operator-only checklist before manual deployment

1. Confirm **Canovia Production** Render service ID, Aiven service and
   target database **pacekeeper** using the console independently; the
   prior restricted SQL audit has exactly **94 applied / 96 code / 2
   pending** at pinned `bb8248f`.
2. Verify **effective** `APP_ENV=production`, `APP_DEBUG=false`,
   stable APP_KEY, APP_URL canonical https, MySQL connection using actual
   resolved DB target and no unwanted DB_URL override. Do not share values.
3. Verify current cookie/session backend, Redis connection if used,
   login persistence after service restart, and iOS WKWebView same-origin
   authentication. Do not change COOKIE/SESSION without migration plan.
4. Confirm unapproved private MCP/Auth0 flags OFF independently;
   Native AI driver, key, billing guard and approved entitlements match
   planned public exposure. GitHub App keys are present/valid but never
   pasted. Staging-only keys are reviewed for later removal, not hot deleted.
5. Confirm backup location and **realistic restore procedure**; Aiven Free
   backup snapshots are visible but Fork & restore is disabled and restore
   has NOT been tested. The owner must accept or resolve the residual risk.
6. Re-review the **full pinned candidate diff**, protected production PR
   checks and active Render target branch. First deployment is manual with
   Auto Deploy OFF, then iPhone/browser smoke. Auto Deploy to protected
   `production` is enabled **only after** the first manual release passes.

Record the sanitized assessment in [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488).
Do not mark **PRODUCTION_RELEASE_AUTHORIZED** before the separate owner release decision.

# PHP 8.4.27 emergency Live candidate — no-auto-DDL boundary

> 2026-10-22 / Platform–Hotfix / **CANDIDATE TEST ONLY — PRODUCTION NO-GO**.
>
> This document describes a pinned candidate against the *actual old Live baseline*. It is not an operator approval, a deployment plan approved for execution, nor a reason to bypass GitHub protections. NEVER promote/deploy from this document alone.

## 1. Baseline, scope, and provenance

- Reviewed Live `production` + Render Canovia SHA: `15abdc9de6f24b5250fb255c579d1aa55facaf1a`.
- Reviewed main PHP hotfix: PR #832, integrated main `a42e05a5b359738e3e4c5d589a26b770e971e936`, **not** a suitable all-features production candidate.
- Prior original-Live image-only candidate: `fix/php-8427-live-baseline-20261022`, SHA `2b9a2baa62cdb472924271845335f075e1585267`, image build and isolated PHP 8.4.27 checks PASS. Preserve as immutable historical evidence; it *still contains unconditional startup migrations*.
- New corrected candidate: `fix/php-8427-live-no-ddl-20261022`, **forked directly from the same exact Live SHA** and not derived from moving `main`.
- Strict permitted diff against Live: `Dockerfile`, `docker/render-start.sh`, `scripts/ci/php_8427_no_ddl_live_boot_test.sh`, `.github/workflows/php-8427-live-no-ddl-candidate.yml`, and this documentation file.
- No changes to Laravel application logic, routes, Composer, migration sources, authentication, MCP, session behavior, billing/entitlements, existing data, or environment variables.
- PHP 8.4.27 is the patched 8.4 security release for `CVE-2026-109618` / `CVE-2026-109619`. Reference: https://www.php.net/releases/8_4_27.php

## 2. Startup safety contract

The historical Live `docker/render-start.sh` executes `php artisan migrate --force` on every boot. A Dockerfile-only patch cannot promise no DB DDL on redeploy.

The new **candidate only** changes that startup behavior:

1. Before running PHP/Artisan, require `APP_ENV=production`. Reject every other effective environment with exit 42.
2. Permit only `CANOVIA_STARTUP_MIGRATIONS=check` or its unset default, and require empty `CANOVIA_APPROVED_MIGRATION_SHA`. Reject explicit `apply`, unknown mode or approval SHA with exit 42 **before** any PHP/Artisan command.
3. Remove the historical automatic migration/retry block entirely. The legacy `config:cache`, `route:cache`, `view:cache`, and Nginx/PHP-FPM startup paths remain unchanged. These may execute Laravel bootstrap logic, so this proves absence of the *known unconditional migrate command*, not absence of all imaginable application-side effects.
4. Schema compatibility and a no-pending-migration ledger are **not** established by the startup guard. Before any real deploy, an operator must independently establish the physical schema/ledger, read-only metadata evidence, backup/recovery readiness, and production rollback/roll-forward plan. A patched image alone is not production acceptance.
5. The new no-auto-DDL mode must remain fail-closed; never quietly fall back to `artisan migrate` if a preflight fails. In a real live cutover with unexpected schema state, keep the service closed and investigate rather than applying DDL automatically.

## 3. Synthetic/offline verification

This branch includes its own workflow `php-8427-live-no-ddl-candidate.yml` triggered **only by pushes to this exact review branch**, not on `production` or Render:

- `scope-guard`: source is ancestor of pinned Live SHA; changed files match the allowlist; check exact vetted PHP Dockerfile and boot-script blob IDs; enforce no live migration invocation in the start script.
- `isolated-image`: pull official `php:8.4.27-fpm-bookworm`, record the resolved image digest, build the original Live application with `--pull --no-cache`, and check the effective PHP version, PHP-FPM, Nginx, Composer platform requirements, and application artifact presence.
- `no-ddl-boot`: run the **actual candidate startup shell** inside the throwaway built image with `--network none`. Replace PHP CLI and Supervisor with disposable stubs *inside that image only* so no Laravel code, DB, or network call executes. Confirm successful `check` path only invokes cache commands and that `apply`, invalid mode, non-production APP_ENV, and pre-set approval SHA all fail before any PHP.
- CI success is **source/runtime mock evidence only**; it is not a demonstration of real production startup, database compatibility, live PHP replacement, or absence of a prior compromise.

## 4. Why production promotion remains blocked

Production branch `15abdc9d` does **not** contain the newer `.github/workflows/production-promotion-policy.yml` and the checks it requires are currently only documented/implemented on development `main`. The normal `main` policy requires any `release/*` candidate to be an ancestor of `main`. This deliberately minimal old-Live hotfix branch is not.

Changing `production`, merging it directly, retargeting Render, disabling protection, force pushing, changing workflow gates solely to make green checks, or inserting large unshipped main changes is **not approved**.

A genuinely reviewed exception would require, in this order:
1. Separately owner-authorized *governing* emergency promotion policy that is actually enforced on `production` (not merely a passing workflow on `main`) and verified protected-branch PR/required checks. Verify what GitHub will enforce on the intended PR and the resulting merge SHA.
2. Exception strictly limited to the original Live parent `15abdc9d`, exact reviewed candidate SHA, and an explicit byte-for-byte changed-file/tree allowlist. The normal main-ancestry restriction must remain for all non-emergency candidates. No permissive generic `fix/*` exception.
3. Independent checks on the exact candidate SHA: image/source verification, fail-closed no-DDL runtime proof, schema/migration ledger metadata and physical checks, backup and recovery acceptance, session/auth/Web/iOS tests appropriate for the change. Invalid/missing evidence means NO-GO.
4. Owner must explicitly approve any production promotion. Use a reviewed **merge commit** (no squash, rebase, direct or force push), validate its actual tree against the approved candidate and check the pushed merge SHA.
5. Separately approve Render retargeting from `main` to `production`, and a pinned **manual** deploy while Auto Deploy stays OFF. Confirm Render is about to use only the approved exact SHA. Readiness and monitoring afterward cannot be inferred from GitHub CI.

No tools in this task authorize GitHub administration changes, `production` mutation, Render configuration, deployments, or Aiven access. Failure to enforce protected branch gates is a **hard stop**, not a reason to choose another publishing route.

## 5. Acceptance & residuals

- `HOLD`: observed pass/fail result of this branch's complete new CI.
- `HOLD`: live Aiven physical schema, applied migration ledger and backup/restore proof (no real DB read or writes).
- `HOLD`: whether old Live runtime has already been compromised; logs not assessed for this release.
- `HOLD`: `production`-enforced emergency policy + owner approval, source SHA of the future release branch, and verified branch protection.
- `HOLD`: effective production environment values for `APP_ENV`, `CANOVIA_STARTUP_MIGRATIONS`, and `CANOVIA_APPROVED_MIGRATION_SHA`; no env inspection here; no changes made.
- `HOLD`: actual Render Live php -v/digest, go/no-go, migration safety, and physical Web/iPhone smoke.
- **No production GO.** Never treat a main PR, Docker build or release candidate as a live patch. Avoid reverting a fixed runtime to a vulnerable image.

Authority: latest main `AGENTS.md`, `docs/CANOVIA_DEVELOPMENT_WORKFLOW.md`, `.github/workflows/production-promotion-policy.yml`, main `docs/development/PHP_SECURITY_HOTFIX_2026_10_22.md`, and Issue #488.

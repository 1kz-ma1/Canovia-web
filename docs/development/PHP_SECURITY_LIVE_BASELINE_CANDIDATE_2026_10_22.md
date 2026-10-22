# PHP 8.4.27 — Live baseline emergency candidate / NO-GO record

> 2026-10-22 / Platform–Hotfix / GitHub-only **candidate**, not a release or production authorization.
>
> **Do not promote, deploy, retarget Render, run artisan migrations, or touch Aiven based on this file.**

## 1. Evidence and exact baseline

- Prior source: `production` and Render Canovia Live commit `15abdc9de6f24b5250fb255c579d1aa55facaf1a` (2026-10-22 Render read-only audit).
- Development `main` already carries security-only PR #832 (squash `a42e05a5b359738e3e4c5d589a26b770e971e936`). **Main is not a production release candidate**, as other features and migrations remain unshipped.
- Emergency branch `fix/php-8427-live-baseline-20261022` was created from the exact Live commit. This isolated candidate is intentionally **not** derived from latest `main`.
- Runtime-only code patch: `Dockerfile` matches the already-reviewed main blob `dbdb69eb4041841cbec6808c8ab1327ac44b63be`; `php:8.4-fpm-bookworm` replaced with `php:8.4.27-fpm-bookworm` and a `PHP_VERSION_ID` build-time fail-closed guard.
- The only other changed files are this audit record and a GitHub-only isolated build workflow, which are not runtime application changes. Do not modify `composer.lock`, any application/migration/secret/entitlement files or the boot script in this candidate without restarting full review.
- PHP official security release: https://www.php.net/releases/8_4_27.php
- Two CVEs: `CVE-2026-109618`, `CVE-2026-109619`.

## 2. Independent verification contract

Workflow: `.github/workflows/php-8427-live-baseline-candidate.yml` (push to the exact `fix/*` candidate branch, or manually triggered on that branch).

Required PASS:
1. Check exact Live ancestry and a strict 3-file allowlist. Assert the candidate Dockerfile Git blob is byte-for-byte identical to the reviewed `main` Dockerfile.
2. Pull patched official image, record resolved RepoDigest, and build the **actual original Live Laravel image**, with Docker `--pull --no-cache`.
3. Run runtime checks without Docker networking: actual PHP version within patched 8.4 series, FPM config, Nginx config, Composer production platform requirements, and basic application artifact presence.
4. Never execute `docker/render-start.sh` or `artisan migrate`. Do not load production credentials, access Render, Auth0, Aiven, or a real database.
5. Treat GitHub workflow failure/pending as **HOLD**; passing CI is *only* offline image evidence, not a release authorization or a safety guarantee.

## 3. Discovered legacy start-up migration risk (P0)

In the currently deployed baseline, `docker/render-start.sh` runs `php artisan migrate --force` whenever the production container starts. The newer `main` has an explicit startup-migration policy, but that code is **not included** in this legacy-candidate backport.

A Dockerfile-only image update does not add DB migrations, yet a deployment of the old entrypoint could still apply already-present pending migrations or encounter partially applied schema. **Do not infer a no-DDL deployment from a no-DDL PR diff**.

Before *any* Live redeploy: require an independently reviewed startup/DB non-mutation guarantee, physical schema and applied migration ledger reconciliation, verified recovery pathway, and explicitly authorized operator release plan. Do not attempt a live database read/write or run the legacy entrypoint for evidence here.

## 4. Production promotion gate / why no production PR

Current `main` contains `.github/workflows/production-promotion-policy.yml` and `AGENTS.md` which enforce `release/* -> production`, with the candidate HEAD required to be in the current `main` ancestry. A new patch commit based on `15abdc9d` is NOT a `main` ancestor.

Therefore **this exact minimal backport cannot pass today's normal production promotion policy**. Do **not** rename the branch to `release/*` and pretend it meets the main-ancestry check, disable the gate, force-push `production`, cherry-pick unreviewed main scope, or use a manual Render deploy as a bypass.

Owner-approved follow-up is required to choose and implement an explicitly reviewed emergency promotion process that retains production auditability/branch protections and never bypasses applicable database/startup gates. The user has not authorized any `production` branch or Render mutation in this task.

Also Render Canovia currently watches `main` with Auto Deploy OFF. A manual Render deploy without changing the tracked branch could deploy moving development `main`, not the pinned candidate. No Render action is approved.

## 5. Outstanding acceptance and operator decisions

- `HOLD`: candidate image CI verification (until run result is individually confirmed).
- `HOLD`: corrected PHP version and image digest on **actual Render Live** (unknown until authorized deploy).
- `HOLD`: past compromise assessment and logs/forensic review (no proof of intrusion, no proof of absence).
- `HOLD`: resolve inherited unconditional startup migration risk without running it on live.
- `HOLD`: owner-approved emergency promotion route with `production` checks/constraints intact, current Aiven ledger and recovery acceptance.
- `HOLD`: bounded real Web/iOS smoke after eventual owner-approved cutover.
- **No production GO; existing integration freeze remains.** Never roll back to the known potentially vulnerable old base after a successful patch.

References: `AGENTS.md` on latest main; `docs/CANOVIA_DEVELOPMENT_WORKFLOW.md`; `docs/development/PHP_SECURITY_HOTFIX_2026_10_22.md` on main; main `.github/workflows/production-promotion-policy.yml`. These references are not replaced by this exception-record.

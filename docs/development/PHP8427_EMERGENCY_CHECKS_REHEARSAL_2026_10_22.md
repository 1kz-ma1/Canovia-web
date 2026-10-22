# PHP 8.4.27 — protected production CI rehearsal, not a deployment

> 2026-10-22 / Hotfix / **REHEARSAL ONLY / PRODUCTION NO-GO**  
> This is a new branch created from the previously isolated original Live-based candidate, not a mutation of `fix/php-8427-live-no-ddl-20261022`.

## 1. Pinned source, reproducibility and allowed scope

- Original Live / GitHub `production`: `15abdc9de6f24b5250fb255c579d1aa55facaf1a`.
- Original PHP 8.4.27 + no-auto-DDL candidate: `0ac198acf3c087652d0a164c55602fb60922a8d0`. Its runtime `Dockerfile` blob remains `03ead1ab2823f1345ffb237a68fb26a75b656edd` and `docker/render-start.sh` blob remains `97b9de037130e15989dc411db368e48c35580110`.
- Rehearsal branch: `fix/php8427-protected-ci-rehearsal-20261022`, forked from the original candidate. Never reuse as a normal main-derived release without a separately approved emergency exception.
- Additional changes on top of the original candidate are limited to three existing GitHub CI workflow event/compatibility blocks, the new `.github/workflows/production-promotion-policy.yml`, this record, and **the historical learning test `tests/Feature/StudyAdaptiveActionV536Test.php` only**. Its three `evaluate($plan)` calls now explicitly pass the already-frozen `now()` clock, without changing production application logic. Runtime `Dockerfile`, startup guard, migrations, Composer, auth, business behavior, or database are untouched.
- This is an **unapproved rehearsal branch**, not a production pull request. No PR to production is opened as part of this work.

## 2. Existing GitHub Ruleset stays active, and check names stay exact

Ruleset `24954459` currently requires a PR, merge commit, no force push, and precisely:

1. `Production promotion contract`
2. `workspace-resume`
3. `migration-recovery`
4. `P0 schema integrity (MySQL 8.0)`
5. `P0 schema integrity (MySQL 8.4)`

Changes *inside this rehearsal branch only* provide an unconditional `pull_request: production` and `push: production` trigger to each relevant workflow, **without** path filters. The disposable MySQL 8.0 and 8.4 jobs retain their original test bodies. Two legacy expectations were incompatible with the intentionally production-only no-DDL boot policy and have narrowly scoped replacements in this rehearsal branch:

- The former `V56.9 Render startup retry` test assumed automatic startup migrations. The `migration-recovery` job instead **builds the actual patched Docker image and executes `php_8427_no_ddl_live_boot_test.sh` within a disposable container using `--network none`**. Other recovery tests remain enabled.
- The combined `McpIsolatedStagingBootstrapTest` expects `APP_ENV=staging` to boot through the shared script; the new hotfix deliberately refuses staging to prevent unintended deployments. The `workspace-resume` job substitutes explicit production Workspace/Session and MCP authorization gate regressions, leaving the remaining workspace and consent tests enabled. The isolated MCP staging bootstrap capability is therefore **NOT ACCEPTED** for this production-only emergency image and must not be deployed to the MCP staging Render service.

The production-promotion job is a candidate-specific alternative to the normal `main` ancestry contract; it is never a general `fix/*` bypass. It verifies the original Live ancestor, the validated candidate ancestor, runtime Git blob equality, and an exact changed-file allowlist. It runs when proposed to `production` or pushed on `production` after merge. It also demands separate, owner-controlled proof of exact SHA and real Aiven schema/recovery attestations. The following GitHub repository variables must be explicitly reviewed/established **in a separate owner-approved step**; do not set them during rehearsal:

- `CANOVIA_PHP8427_EMERGENCY_APPROVED_SHA`: exact reviewed candidate SHA, equal to PR head SHA or the protected merge's second parent SHA
- `CANOVIA_PHP8427_LIVE_SCHEMA_ATTESTED=VERIFIED`: operator confirms current actual Live database schema and migration ledger matches the approved frozen source
- `CANOVIA_PHP8427_RECOVERY_ATTESTED=VERIFIED`: operator confirms credible backup/recovery and accepts residual restore risk

**Variables are manual attestations, not a substitute for performing an Aiven read-only audit or independently checking recoverability.** No repository variable is set, read, or modified by this task. Absence or mismatch **must fail the production contract**.

## 3. What this rehearsal can and cannot establish

Branch push tests should exercise the original `workspace-resume`, `migration-recovery` and disposable MySQL jobs with their real old-Live code. The emergency `source-integrity` job must confirm exact runtime blobs and allowed diff. The `Production promotion contract` job is intentionally **skipped for the rehearsal branch only**, not passed: it is required and cannot be skipped for a future `production` PR/push. Do not misread a skipped rehearsal status as satisfying any protected Ruleset requirement.

Even if all branch-specific jobs pass, GitHub's real `pull_request` check association and post-merge `push` checks still need verification via a separate owner-authorized **draft production PR**, and later a separate protected merge-SHA check. This rehearsal has neither. The GitHub official required-check rule can reject manual-only, path-skipped, wrong SHA or wrong GitHub App status; only actual events can establish check satisfaction.

### Inherited test-clock regression found in rehearsal

The unchanged old-Live `StudyAdaptiveActionV536Test.php` froze Carbon time at `2026-10-04`, with an exam deadline of `2026-10-20`, but its calls to `StudyAdaptiveActionService::evaluate($plan)` omitted `capturedAt`. The legacy `StudyPlanIntelligenceService` deliberately uses `new DateTimeImmutable()` when `capturedAt` is omitted, so a real CI run after October 20 classifies the task as `reduce_deadline_risk` rather than `establish_scope_baseline`. This explains the existing behavior without changing production logic.

The candidate modifies only the three test invocations to `evaluate($plan, now())`, making those tests use the intentional October 4 clock. The exact reviewed test blob `2dfe9ea91bf944f114ec558004046476b320a124` is enforced by the emergency source guard. This is a **test-only deterministic fixture repair**, not an adjustment to user study priorities. Await the rerun CI before treating the affected Workspace test as passing.

## 4. Hold gates before any real protected release

- Owner review and approval of the *exception design* itself; do not replace the normal `main` gate for other releases.
- Exactly pinned, reviewed rehearsal/candidate head, plus full diff from the actual Live SHA and no unreviewed migration/production env change.
- Current operator Aiven metadata-only physical schema + migration ledger, recovery plan/backup feasibility and effective Render environment flags. Prior `94/94` history is not current proof.
- Confirm the required five GitHub Actions check results with correct app identity on the exact **production PR** merge candidate, and verify that all required workflows are present on the candidate tree. If missing/pending/skipped, do not merge.
- Explicit owner **production GO** and a separate Render deployment GO. Merge only via the protected, two-parent merge route after all gates. The watched Render branch is currently `main`, Auto Deploy OFF; retargeting is a separate operation.
- Real PHP/digest after eventual release and Web/iOS smoke; no assert of remediated Live before that point.

**No production GO.** Neither this branch, main PR #832/#833, nor any green synthetic/test build updates the real live PHP runtime.

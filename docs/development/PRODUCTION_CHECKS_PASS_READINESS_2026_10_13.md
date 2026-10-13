# Canovia — Production auto-deploy readiness / checksPass rollout

Date: 2026-10-13
Status: **PREPARATION ONLY — NOT AUTHORIZED TO PROMOTE OR ENABLE AUTO DEPLOY**
Owner-controlled rollout; the initial production deploy, Aiven live audit, Render configuration changes, expenses and any branch-protection administration are separate actions.

## 1. Why the app currently looks older than source

| Component | Verified observation (2026-10-13) | Implication |
| --- | --- | --- |
| Render Canovia live | `15abdc9de6f24b5250fb255c579d1aa55facaf1a`, deploy `dep-db5fgl942hec738pbq30`, live | Latest main features **not** in iOS WKWebView/PWA/live Web |
| Render service | `srv-dagfn2id0e5s73cac7ng` in `My Workspace` / `tea-d4vb3f6mcj7s73djkrqg` | Operator-only configuration scope |
| Render git binding | `branch=main`, `autoDeploy=no` / `autoDeployTrigger=off` | No main merge currently triggers deployment |
| GitHub production | `15abdc9de6f24b5250fb255c579d1aa55facaf1a`, GitHub branch API reports `protected=true` | The **active repository Ruleset** was subsequently retrieved; CI and merge-method requirements verified, but approving reviews = **0**. See section 6 |
| GitHub main | `f324b286f9957e520946c4ebfa6ffbf14d38b5cd` at latest recheck | Unshipped relative to Render: **70 commits / 287 changed paths / 4 new migrations**. Re-pin again if it moves |
| Frozen release PR [#513](https://github.com/1kz-ma1/Canovia-web/pull/513) | `release/2026-10-13-bb8248f` → `production`, draft NO-GO | Source `bb8248f` pinned: **46 commits / 219 paths / 2 new migrations** vs live |
| Subsequent main changes | 24 commits / **2 more migrations** after pinned release; 4 migrations total | Cannot reuse `#513` dual-migration audit to deploy moving latest main |

The **four** source migration files found in latest `main` vs live as of the recheck are:

1. `2026_10_12_010000_add_source_revision_to_development_activity_observations.php`
2. `2026_10_13_021000_add_source_epoch_to_development_activity_observations.php`
3. `2026_10_13_110000_add_authorization_epochs_to_collaborative_plans.php`
4. `2026_10_13_120000_enforce_authorization_epochs_in_database.php` (**creates database triggers; requires explicit Aiven privileges and schema compatibility review**) 

**These are code diffs, not assertions that the live Aiven ledger shows all three pending.** Only the restricted operator-local SELECT-only inspector against exact pinned release SHA may determine actual live state. Current `#488`/`#513` dual-migration audit is specific to the frozen two-migration candidate.

Other pending PRs may advance main; snapshots here are dated and must be re-pinned for each release. Queue Worker is held; do not imply queued GitHub ingestion is fully operational.

## 2. Permanent branch/CI/CD topology

`main` = development integration, not a Render auto-deploy target.
`release/YYYY-MM-DD-<sha>` = immutable reviewed release candidate from a particular main ancestor.
`production` = protected branch containing only separately owner-approved release merges.
Render Web & iOS WKWebView = live service observing only `production` **after operator change**.

Desired CD after successful first manual release:

1. A release branch from **exact** reviewed main SHA; compare against **actual** live/production state, all files (code, permissions, migrations, Composer, Docker, web/iOS).
2. Operator-only privacy-safe Aiven ledger/metadata audit + fresh backup and viable recovery plan. Full MySQL 8.0/8.4 CI (all new migrations, historic rows, repeatability); synthetic CI ≠ real Aiven evidence.
3. `release/* → production` PR with required source PR CI, owner release approval, protected branch, no direct/force push, **Merge commit**, not Squash/Rebase.
4. Production push triggers **the same core checks on the exact pushed merge SHA**:
   - `Production Promotion Policy Gate` → `Production promotion contract` (merge + main release-parent ancestry)
   - `P0 schema integrity (MySQL 8.0)`
   - `P0 schema integrity (MySQL 8.4)`
   - `V56.12 Workspace Resume Validation`
   - `Production Migration Recovery Validation`
5. Render **After CI Checks Pass** (`autoDeployTrigger=checksPass`), never `On Commit`, consumes those push checks and deploys only when all detected checks are passing.
6. Post-merge review: verify exact live SHA, startup migrations, logs/5xx/SQL, session/login, Plan/Study/Development, iPhone WKWebView/PWA/desktop; keep rollback/forward-repair decision ready.

Render official documentation confirms `checksPass` waits for detected GitHub checks; **zero checks** or any failed checks prevents deployment, while `success`, `neutral`, and `skipped` count as passing. **This is not a substitute for branch protection**: required security checks must actually run and enforce success, not be optional/skipped.

Render docs:
- https://render.com/docs/deploys
- https://render.com/docs/blueprint-spec

### New source-level change in this preparation PR

Production merge push checks are enabled in:
- `.github/workflows/production-promotion-policy.yml`
- `.github/workflows/v56-12-workspace-resume.yml`
- `.github/workflows/v56-8-migration-recovery.yml`

`production-promotion-policy.yml` also checks on push that HEAD has **exactly two parents** and the **second parent is an ancestor of main**. This protects the CI side against direct/squash/rebase pushes and wrongly sourced merge commits, while GitHub protection remains the primary authorization control. The `production-mysql-integrity` reusable 8.0/8.4 workflow continues to run without changed-path filters for production pushes.

**Important propagation:** These workflows are currently changing in development main, **not** in the frozen earlier source `bb8248f`. The first live promotion of `#513` does not itself include this new post-merge CI code. The operator must verify that the updated workflows have reached the `production` branch **before** enabling `checksPass`; otherwise do not claim zero-check protection or a functioning auto-deploy gate. A later pinned release including these workflows, with all newly included migrations audited, may be necessary before auto-deploy activation.

## 3. Current release candidate decision

Two options — pick one explicitly, never silently replace a pinned SHA:

**A. Release frozen #513 first:** all its dual-migration gates, real restore-risk acceptance, branch rules, manual promotion/deploy and iPhone/Web E2E must pass. After initial acceptance, **keep Auto Deploy OFF** until a subsequent approved release also installs the push-CI workflows and validates their real production-merge runs.

**B. Re-cut fresh candidate after this PR reaches main:** includes new push-CI safety but also all later main changes, notably **four** currently added migration files vs live, including a trigger-creating migration. That requires a **new exact 4-migration operator gate**, a MySQL/Aiven trigger privilege and partial-DDL assessment, expanded disposable DB rehearsal and fresh owner approval. Current #488 dual-migration classifier cannot be treated as validating option B.

Prefer risk-scoped releases over automatically deploying latest main. The owner chooses when all required evidence is available. **Neither route is ready today.**

## 4. Operator-only activation checklist

### First controlled deployment (no automatic deployments)

- [ ] Choose and freeze exact `release/*` SHA. Document whole `live..release` changed paths, migrations, runtime security boundaries.
- [ ] Complete CI for the **same exact commit** and required production PR checks.
- [ ] Verify actual Aiven `pacekeeper` schema/ledger only through approved restricted SELECT-only operator path. No secrets or raw private data in GitHub/chat. Unknown/partial DDL is BLOCK.
- [ ] Independently verify a fresh backup with feasible restoration, and the owner explicitly accepts untested Aiven Free fork-restore limitations. Do not invent restore success.
- [ ] Verify Render effective DB/session/Native AI/MCP/Auth0 and queue flags without exposing credentials. Preserve private delegated MCP OFF; Queue Worker remains deferred and dependent capabilities remain gated.
- [ ] Verify GitHub `production` branch protection/ruleset required PR, required check names, owner signoff, no direct/force-push. API `protected=true` alone is insufficient.
- [ ] Approve `release/* → production` merge via Merge commit; confirm `production` SHA.
- [ ] With Render Auto Deploy still OFF, owner points Render at `production` and performs the **first manual deploy** only after a separate go/no-go.
- [ ] Verify live commit, migrations, 5xx/SQL and login; manually accept iPhone WKWebView, desktop/PWA, Learning/Development/Plan and privacy flows.

### Then turn on protected automatic deployment

- [ ] Source-level production **push checks are actually present on production** (not only in main).
- [ ] Confirm `production` merge SHA ran all required push checks and all completed successfully; correct GitHub required checks and Render checks mapping.
- [ ] Re-check Render is tracking `production` and live SHA equals the accepted production SHA. No unresolved migration, rollback or session issue.
- [ ] Owner explicitly enables **After CI Checks Pass**; never switch straight to `On Commit` or put Render back on `main`.
- [ ] Observe one deliberate, low-risk owner-approved subsequent `release/* → production` PR; verify after-merge CI, Render deploy trigger, live SHA, smoke checks. Turn Auto Deploy OFF immediately if safety conditions fail.

## 5. Current holds / missing evidence

- Newest `main` ≠ frozen `#513` ≠ Render Live. No implicit live sync.
- Two new migrations in `#513` and **four** migration source files in latest main; current actual pending ledger **unknown without operator recheck**. Newer triggers may require privileges not yet attested for Aiven production.
- Active `production` Ruleset requirements are now **readable** and documented in section 6. The key missing safeguard is mandatory owner/reviewer approval (configured review count is **0**). Owner review-control decision required before automated deployment.
- Backup snapshots were observed in earlier review, but tested restoration on Aiven Free was not verified. Do not claim recoverability.
- `#513` has explicit NO-GO gates; its PR CI and operator evidence must be rechecked at exact candidate SHA.
- Newly merged main features remain untested on the current live iOS/Web code until a controlled deploy.
- No Render, Aiven, branch settings, payment, MCP production privileges or queue service is modified by this preparation document or CI PR.

Related: [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488), [frozen release PR #513](https://github.com/1kz-ma1/Canovia-web/pull/513), [deployment workflow](../CANOVIA_DEVELOPMENT_WORKFLOW.md), [P0 operator playbook](LEARNING_RELEASE_488_PREDEPLOY_REVIEW_2026_10_12.md).

## 6. Direct ruleset and pinned-release verification — 2026-10-13

**Verified via read-only GitHub API**, not a proposal:

- [Canovia Production Protection Ruleset](https://github.com/1kz-ma1/Canovia-web/rules/24954459) — `active`; target **only** `refs/heads/production`; no bypass actors.
- Deletion protection and non-fast-forward protection are enabled; changes require a pull request; **merge commits only** (`allowed_merge_methods: [merge]`).
- Required checks: **Production promotion contract**, **workspace-resume**, **migration-recovery**, **P0 schema integrity (MySQL 8.0)**, **P0 schema integrity (MySQL 8.4)**.
- **Critical open gap**: `required_approving_review_count = 0`; no Code Owner or required reviewer is configured. The existing ruleset does not mechanically enforce a separate human release approval, even though `AGENTS.md` and the product owner policy require it. A CI-green bot or maintainer may technically be allowed to merge. Do **not** treat status `protected=true` as release authorization.
- Owner must decide a workable enforcement mechanism (e.g. one eligible *other* reviewer, restricted production merge actors, or another enforceable owner-only promotion gate). On a solo-maintainer repository, blindly requiring one approval may deadlock self-authored PRs, so confirm the actor model before changing rules. **This document does not change GitHub rulesets.**

**Verified on exactly pinned** [PR #513](https://github.com/1kz-ma1/Canovia-web/pull/513) `bb8248f25545ae0d600fbb68f66c41c360004e2c`:

- Current state **draft / NO-GO**, mergeable but **not authorized**. `production` still at `15abdc9`.
- GitHub commit checks (all **completed / success**): MySQL 8.0, MySQL 8.4, Production promotion contract, Workspace resume, Migration recovery.
- Delta from live: **46 commits / 219 changed files / 2 migration files**. This candidate does **not** contain the later authorization-epoch column and trigger migrations and does **not** contain the new post-merge production-push checks from PR #539.
- CI passing is **necessary, not sufficient**. The exact pending Aiven migration ledger/metadata, backup/restore feasibility, production-effective security flags, operator acceptance, first manual deploy and iPhone/Web smoke remain open.
- **Recommended controlled sequence**: keep frozen #513 as first manual-release candidate, complete the **two-migration** operator-only audit already present on that SHA, confirm owner restore risk/go-no-go, and deploy just that fixed target manually. Keep Auto Deploy OFF afterward. A later separate release that includes PR #539 push-CI code needs its own independent approval, and any included newer migration requires a correspondingly expanded audit. Enable checksPass only after its pushed production-merge checks are present, run, and observed successful.

**Operator-only safe step for the next owner action:** In the clean checkout of the pinned `release/2026-10-13-bb8248f` branch, follow the existing [#488 SELECT-only Aiven audit procedure](LEARNING_RELEASE_488_PREDEPLOY_REVIEW_2026_10_12.md). Use only an Aiven-verified restricted login path, `pacekeeper`, and verified CA. Opt in with `CANOVIA_RELEASE_488_CHECK=YES`; share only the fixed result code `EXACT_TWO_SOURCE_OBSERVATION_MIGRATIONS_PENDING`, `BOTH_SOURCE_OBSERVATION_MIGRATIONS_ALREADY_APPLIED`, or `BLOCK`, never credentials, raw rows, usernames, raw ledger or SQL output. **No production MySQL connection has been initiated here.**

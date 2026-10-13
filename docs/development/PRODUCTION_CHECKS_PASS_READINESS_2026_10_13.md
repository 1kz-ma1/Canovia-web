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
| GitHub production | `15abdc9de6f24b5250fb255c579d1aa55facaf1a`, GitHub branch API reports `protected=true` | Protection *rules/required checks could not be inspected* via connector (403). Never assume sufficient enforcement |
| GitHub main | `d1a8343c238cfb336d0dad53e1f177edc91a0c2a` at audit | Unshipped relative to Render: **67 commits / 282 changed paths / 3 new migrations** |
| Frozen release PR [#513](https://github.com/1kz-ma1/Canovia-web/pull/513) | `release/2026-10-13-bb8248f` → `production`, draft NO-GO | Source `bb8248f` pinned: **46 commits / 219 paths / 2 new migrations** vs live |
| Subsequent main change | 21 commits / 77 changed paths after pinned release; **one more migration** | Cannot reuse `#513` dual-migration audit to deploy moving latest main |

The three source migration files currently found in latest main vs live are:

1. `2026_10_12_010000_add_source_revision_to_development_activity_observations.php`
2. `2026_10_13_021000_add_source_epoch_to_development_activity_observations.php`
3. `2026_10_13_110000_add_authorization_epochs_to_collaborative_plans.php`

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

**B. Re-cut fresh candidate after this PR reaches main:** includes new push-CI safety but also all later main changes, notably **three** currently added migration files vs live. That requires a **new exact 3-migration operator gate**, expanded disposable DB rehearsal and fresh owner approval. Current #488 dual-migration classifier cannot be treated as validating option B.

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
- At least two new migrations in `#513` and three new migration source files in latest main; current actual pending ledger **unknown without operator recheck**.
- Branch `production` is marked protected, but ruleset / required reviewers / checks could not be read (GitHub integration 403). Owner verification required.
- Backup snapshots were observed in earlier review, but tested restoration on Aiven Free was not verified. Do not claim recoverability.
- `#513` has explicit NO-GO gates; its PR CI and operator evidence must be rechecked at exact candidate SHA.
- Newly merged main features remain untested on the current live iOS/Web code until a controlled deploy.
- No Render, Aiven, branch settings, payment, MCP production privileges or queue service is modified by this preparation document or CI PR.

Related: [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488), [frozen release PR #513](https://github.com/1kz-ma1/Canovia-web/pull/513), [deployment workflow](../CANOVIA_DEVELOPMENT_WORKFLOW.md), [P0 operator playbook](LEARNING_RELEASE_488_PREDEPLOY_REVIEW_2026_10_12.md).

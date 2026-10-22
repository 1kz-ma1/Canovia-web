# PHP 8.4.27 emergency promotion — protected-route audit and operator gates

> 2026-10-22 / Platform–Hotfix / **INTEGRATION READINESS ONLY — LIVE NO-GO**  
> This describes the current, observed GitHub configuration and a **read-only** preflight. Nothing here approves deployment, a production-branch update, Aiven access, or changes to GitHub Rulesets.

## Current immutable evidence

| Boundary | Value | Evidence type |
| --- | --- | --- |
| Current development main at preflight creation | `a42e05a5b359738e3e4c5d589a26b770e971e936` | GitHub branch read |
| Render Web Live and GitHub production | `15abdc9de6f24b5250fb255c579d1aa55facaf1a` | Previous live API and source branch reads |
| Offline PHP+no-DDL source candidate | `0ac198acf3c087652d0a164c55602fb60922a8d0` | Exact reviewed source, not a release |
| Candidate Dockerfile blob | `03ead1ab2823f1345ffb237a68fb26a75b656edd` | PHP 8.4.27 + patch floor |
| Candidate no-DDL startup blob | `97b9de037130e15989dc411db368e48c35580110` | Automatic migration removed; guarded production-only startup |
| GitHub offline candidate test | https://github.com/1kz-ma1/Canovia-web/actions/runs/39792307763 | CI 2/2 success; mocks, no actual DB |
| Render MCP Staging Live | `1e93b6ce17b29e0b029b78293462839fdcbd5a80` | Different environment, not patched |
| Render auto deploy | OFF for Web and MCP staging | Observed, not changed here |

## Verified GitHub production Ruleset (read-only, 2026-10-22)

The active `Canovia Production Protection` ruleset, GitHub ID `24954459`, covers only `refs/heads/production`. It forbids force push and deletion, requires a PR merged using **merge commits**, and requires these existing status names (without relaxing or renaming them):

- `Production promotion contract`
- `workspace-resume`
- `migration-recovery`
- `P0 schema integrity (MySQL 8.0)`
- `P0 schema integrity (MySQL 8.4)`

The ruleset reports **0 mandatory approving reviewers**. Therefore the human GO gate remains a separate, explicit owner decision, not something already enforced by an approval count. The exact Ruleset configuration must be re-read before any real promotion.

The old `production` tree lacks `.github/workflows/production-promotion-policy.yml`; its existing MySQL/Workspace/Recovery workflows target **main PRs**, not unconditional PRs/pushes against `production`. A green `main` PR or a successful candidate push is not evidence those five checks will actually run on the proposed production merge commit.

Current `main` normal policy requires a `release/*` branch and `main` ancestry. The emergency candidate is *deliberately based on old Live*, and is not a normal `main` release candidate. **Do not bypass the branch rules, quietly weaken the normal policy, copy an unverified main release, or merge without the separate owner GO.**

## Scope of this PR: new read-only source preflight

The included script `scripts/ops/php8427_emergency_promotion_preflight.py` reads only Git objects that CI explicitly fetched. It never accesses Render, Aiven, Auth0, cookies, or production credentials. It verifies:

- Exact old Live and candidate SHA and ancestor relationship.
- Exactly five allowed changed paths; byte-exact immutable blobs for the two runtime files and the three supporting evidence files.
- Existing legacy startup had `php artisan migrate --force`, fixed candidate lacks it, patched PHP floor and fail-closed environment/migration guards are present.
- Whether the *actual old production tree* contains the promotion workflow. **It currently does not.**

Output is a minimal `NO_GO` decision and machine-readable reason codes. This tool **cannot output GO, even with a successful source audit**. Tests include foreign files, deleted expected paths, changed hashes, moving SHAs, disabled guards, vulnerable PHP, missing required workflows, and attempts to infer approval from an intact source. A successful CI job means **the NO-GO determination is correct**, never that production is approved.

## Exact safe route to a future emergency promotion

1. **Separately authorize the proposal**, not the deployment: define a narrow, owner-reviewed emergency release lane that checks the old Live ancestor and exact reviewed runtime blobs. Any exception to `main` ancestry must apply only to the named, immutable emergency candidate, not arbitrary `fix/*` or future branches.
2. **Make the checks real**: ensure production PR and subsequent push are evaluated by workflows actually present at the proposed merge SHA, producing all five existing Ruleset context names. Test with a non-mergeable draft release PR if the owner approves that step. Fail closed if any check is missing, skipped, pending, or runs on the wrong SHA. Do not forge status contexts or change/delete the Ruleset.
3. **DB evidence, independently authorized**: compare the actual Aiven *current* applied migration ledger and physical schema to pinned `15abdc9d` / 94-migration source with operator-controlled read-only metadata, explicitly reject mismatches and unexpected DDL. The old accepted 94/94 check in Issue #488 is dated evidence, not a new real-DB read or guaranteed unchanged DB.
4. **Recovery and environment evidence**: verify real backup recency and a viable recovery procedure (a backup's existence does not prove it can be restored). Operator must verify exact production `APP_ENV`, startup migration mode, release SHA, database binding and all affected session/auth flags without revealing secrets.
5. **Explicit owner GO**: approve the exact candidate, diff, evidence, downtime tradeoffs, and guarded emergency procedure. No agent-controlled automatic merge to `production` from a general `main` permission.
6. **Protected production merge**: only after required checks and owner GO, merge with two-parent **merge commit**, never direct/force push, squash or rebase. Verify actual post-merge SHA and tree, and require push-SHA CI results.
7. **Render deploy as another approval boundary**: Auto Deploy remains OFF. Owner must explicitly set the watched branch to `production` and approve the pinned manual deployment only once the exact desired SHA and configured secrets/DB permissions have been validated. Confirm actual PHP version/digest, Web/iPhone auth/session/smoke and error monitoring. Do not roll back to an affected PHP image as a default remedy.

**Unresolved BLOCKING gates**: protected-branch workflow availability, current real Aiven schema/ledger, recovery feasibility, live environment classification, owner authorization for an emergency promotion exception, protected PR verification, actual deploy and physical device verification. The outage/security exposure risk stays open while Live runs an old image.

References: `AGENTS.md`, `docs/CANOVIA_DEVELOPMENT_WORKFLOW.md`, `docs/development/PHP_SECURITY_LIVE_NO_DDL_CANDIDATE_2026_10_22.md` on the pinned candidate, and release Issue #488. No changes to migrations, permissions, release levels, billing or production service were made.

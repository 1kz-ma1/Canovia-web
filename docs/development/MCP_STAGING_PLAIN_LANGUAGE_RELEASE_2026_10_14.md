# Staging MCP — plain-language connection UX release candidate

Date: 2026-10-14. **Proposed, not deployed**. Owner-visible synthetic fixture only.

## Origin and live boundary

- Currently Live isolated Render service `canovia-mcp-staging`: **`4b19469daf0fa65bcb0afba6b0c716c6ba40a0fe`**, confirmed immediately before preparation. This fixed SHA already retired the temporary Plan-read diagnostic while preserving generic post-revocation denial.
- This candidate branches from that exact Live SHA, **not moving `main`**. A dedicated `staging/mcp-plain-language-release-base-20261014` branch pins the identical ancestor for an isolated review.
- User-friendly Japanese wording has already been reviewed and squash-merged into `main` as **PR #578, SHA `8e6bdac7927fb42a202bdc9920ff250508f2c519`**. The source `main` commit is *not* a deployment target.
- The actual isolated Stage has differences from `main` in OAuth callback controller and failure diagnostic tests. Only the **seven fixed, user-visible flash text replacements** and the relevant staging assertion were applied to the Stage files, **preserving stage-specific OAuth denial diagnostics and verification behavior**. The Stage-owned Account view and other dedicated feature tests were matched to the already-reviewed code.
- The only intended diff is 6 presentation/test files, this handoff, and its narrowly targeted staged-release CI. No separate link/grant/subject/token/DB/client/resource/production logic has changed.

## What users now see

1. **本人確認** — choose the account used to protect the Plan.
2. **共有する計画を選ぶ** — explicitly approve the content and expiry, and see a truthful current grant status.
3. **ChatGPTで接続** — use the same account there as during Canovia verification.

OAuth, Auth0, `sub`, HMAC and client-identity debugging terminology is removed from visible instructions. Optional `うまく接続できないとき` explains mismatched GitHub vs Google browser sign-in, and the account page explicitly does **not** claim it can check the ChatGPT-side OAuth login. A formerly misleading success flash that said ChatGPT reads were not enabled now simply confirms the owner's Plan sharing and instructs them to check the separate ChatGPT connection.

All restrictions are unchanged: only the owner's exact synthetic Development Plan, explicit limited grant, permitted scope, finite expiry and immediate revocation are readable through separately authorized ChatGPT OAuth. The real owner's prior consent remains **revoked**. No real personal Plan content is shared.

## Verification and operator release boundary

1. Review this candidate against the pinned `staging/mcp-plain-language-release-base-20261014`. Confirm **only the approved UI copy, fixed response strings, tests, this document and release CI** changed. Verify `McpDelegatedPlanAccessPolicy.php`, `McpReadOnlyContextTool.php`, cryptographic verifiers, routes, middlewares, Stage environment and database have no diff.
2. Require all scoped CI passing: PHP and Blade view compilation, staged OAuth denial, identity-link/Plan sharing, revoked-grant and cross-actor regressions, and isolated Docker image build. Never suppress test failures to deploy the copy.
3. **Deployment is not implied by PR approval.** Owner must explicitly approve an immutable full candidate head SHA and manually select **Render → My Workspace → canovia-mcp-staging → Manual Deploy → Deploy a specific commit**. Do not target moving `main`, merge this Stage release PR into `main`, touch production/Aiven, rotate keys, create grants or enable Auto Deploy.
4. On Render Live with matching SHA, authenticated synthetic owner manually checks the human-readable Account view and sees **本人確認：済み** and **計画の共有：許可していません** following the earlier revocation. This UI status is local Canovia state only.
5. Execute a single read-only ChatGPT MCP `plan_id=1, scope=overview` request and verify exact generic denial `Plan unavailable or not authorized for the requested scope.`. Recheck Render logs without displaying personal tokens, subjects or credentials; no synthetic Plan content should be returned.
6. If unexpected, manually roll back **only isolated Staging** to prior validated Live SHA `4b19469daf0fa65bcb0afba6b0c716c6ba40a0fe`, retaining existing revoked grant. Distinct owner action and verification needed.

## General-user rollout still separate

These are **words and staged pilot affordances**, not onboarding automation, a working in-app ChatGPT connection button, new product permissions or permissionless access. General-user enrollment/expiry/identity recovery, telemetry and production security review remain future scoped work.

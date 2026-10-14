# MCP Staging — Non-destructive GitHub-only runtime policy indication

2026-10-14 JST. **Review candidate only; no Render settings or deployment changed by this PR.** This supplement is for the isolated synthetic actor's account UI only, building on the already deployed candidate commit `9cc3f361941facc23bc81958c6aa81134a75c046`. The original stage release [#630](https://github.com/1kz-ma1/Canovia-web/pull/630) stays open/Draft and should not be merged into its immutable comparison baseline. Review [Render drift incident/runbook](MCP_STAGING_RENDER_DEPLOY_DRIFT_INCIDENT_2026_10_14.md) before any operator action.

## Problem and acceptance boundary

The existing synthetic actor already has a valid **overview-only** indefinite grant for Plan ID 1. The authenticated account view immediately shows the successful share confirmation and hides the ordinary identity-link controls. This is intentional: unlinking to see a newly added `GitHubで本人確認する` button would revoke every related grant, and cannot be justified as a passive diagnostic.

This patch adds a **read-only policy indicator** to that existing success screen. It calls the current server's `McpOAuthAccountLinkConfiguration::githubOnlyEnabled()` and distinguishes `enabled` from `not-confirmed`. Unlike reading a persisted Render config entry, this check uses the Laravel runtime settings and readiness gate. It shows no Auth0 subject, credentials, historical login provider, ChatGPT connection state, raw environment variable, real Plan contents, token, or fingerprint. There is no new route, API, OAuth authorization attempt, database write or server instrumentation.

The notice explicitly states that **current policy for NEW links is not proof the ALREADY-linked subject was originally GitHub**, nor that the independent ChatGPT OAuth client has connected. Neither existing grants nor identities are automatically migrated or forced through login.

## Focused test

With a synthetic linked owner and active grant, assert `not-confirmed` when the flag is false, then enable only a transient *test config* value and assert `enabled` while preserving the exact same grant and link rows. Confirm no outbound HTTP requests and no identity subject appears in HTML. Revert the test config before existing revoke/denial assertions.

The original candidate's three scoped workflows still apply when compared to pinned base `release/mcp-staging-live-baseline-20261014`:
- `provider-routing`
- `auth0-sealed-config`
- `isolated-staging-boot` (actual isolated image with disposable PostgreSQL)

**Never deploy a moving main; no Render connector environment write.** A new immutable candidate SHA requires independent review and the product owner's exact-commit manual-deploy approval. Until deployed, this indicator is **not** present in the live site. No production, Aiven, billing or ordinary user Plan changes.

## Live acceptance sequence after separately approved deployment

1. Recheck the Render workspace/service, unchanged Auto Deploy OFF and newly deployed exact SHA; check startup and migration status, then confirm a signed-in synthetic actor sees the policy notice alongside the **same existing** overview grant.
2. Only if notice reads `enabled`, conclude that the current account-link server configuration enforces GitHub-only for **new** identity links. Do not infer that the linked subject, existing access grant or separate ChatGPT OAuth was newly verified.
3. A true **fresh GitHub social login** acceptance (correct signed `github|numeric-id` subject, wrong-account denial, consent/revocation) still needs a separately consented non-destructive fixture strategy or explicit owner approval for a destructive unlink. No forced resets to satisfy this UI check.

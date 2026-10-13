# MCP Phase 1 Auth0 JWT delegated read compatibility gate — 2026-10-13

**Status:** code preparation for a later separately approved isolated synthetic staging read. No stage or production MCP endpoint is enabled by this PR.

## Verified user journey

The owner completed in actual isolated staging: external Auth0 identity linking, fresh per-Plan consent with a short expiry, then revocation of the **one fixed synthetic Development Plan** grant. Stage deployment was pinned to `a08414b6d1122b3db15f18e3dae27b62f357b597`. This establishes the human consent lifecycle, **not** an actual ChatGPT-issued bearer token or an MCP tool response. The grant is now revoked by user action, so later positive read E2E requires **fresh, explicit reconfirmation**.

## Discovered incompatibility

`McpDelegatedPlanAccessPolicy::authorizedGrant()` had a hard-coded `CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED=true` guard inherited from the original RFC7662 phase. The real stage uses `CANOVIA_MCP_TOKEN_VERIFICATION_DRIVER=auth0_jwt`, the signed RS256/JWKS verifier ON, and introspection OFF. Thus a *valid*, independently JWT-verified principal still failed on Plan authorization even with an active grant.

## Patch

- Check `McpExternalTokenVerifier::mode()` instead: exactly one supported trust method and matching flags must be selected; no simultaneous or unknown verifier.
- The outer MCP controller continues to verify the bearer **cryptographically on every call**. This internal policy does not replace that verification or infer identity from user email.
- For `APP_ENV=staging`, additionally require isolated staging and the single immutable synthetic Plan and linked actor matching `McpStagingLoginPilotPolicy::isApprovedSyntheticPlan()`. Tampered fixtures and additional private Plans are rejected even if granted.
- All normal owner/collaboration/category, exact issuer/audience/client/scope, exp, subject-link, client-resource fingerprint, consent and revocation checks remain in force. Legacy introspection mode stays supported and independently tested.
- Cover signed RS256 JWT `tools/call` with a mock local JWKS, negative missing grant, wrong link-client token, scope downgrade and revocation. Synthetic stage fixtures and verifier-switch negative tests also run. No live ChatGPT OAuth assertion is made.

## Important remaining runtime gates (intentionally NOT changed)

The existing Render isolated-stage startup script, Nginx allowlist, Laravel staging middleware, staging login pilot and consent services **still disallow /api/mcp and require tools/policy OFF**. Turning the two global MCP switches on without a separately reviewed staging-only read pilot would cause startup to fail and must **not** be attempted.

A future dedicated PR must establish independent OFF-by-default stage-only synthetic read approval, exact method allowlist and issuer discovery, safe origin/auth challenges, replay/negative tests, and a deploy rollback procedure. Actual ChatGPT third-party `tpc_` token's verified client identity and resource/scope must be characterized without exposing bearer tokens or claims in logs. ChatGPT must **not** automatically discover user Plans; owner supplies exact synthetic Plan ID through a safe channel. Revoke a freshly consented grant during observed real `tools/call` and verify denial. No production release or user-data exposure follows from this preparation.

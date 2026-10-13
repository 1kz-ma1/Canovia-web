# MCP staging Auth0 identity-link failure diagnostics — 2026-10-13

## Incident and scope

User retested the Auth0/GitHub link in a separate Safari private session after deploying the synthetic Plan-consent pilot to isolated staging. The same user-facing message appeared: "外部IDの本人確認を完了できませんでした". This reduces the likelihood of stale browser cookies but does **not** prove the cause. Existing Nginx staging logs intentionally report method/status only; HTTP 302 alone cannot identify whether the IdP callback fails on session state, RFC9207 issuer, token endpoint, token shape, or signed JWT verification.

**This PR only introduces diagnostic categories; it does not lower OAuth validation or enable tools.** It is not evidence that Auth0 login now works. Existing production, Aiven, and Render stage settings are not modified.

## Privacy-bound stage diagnostic contract

- Allowed only when `APP_ENV=staging`, isolated stage flag, login pilot and account-link pilot are all enabled.
- Emits a single fixed application-log message `Canovia MCP staging OAuth failure` plus **one enum-valued** `stage_code`. No user ID, session ID, state, authorization code, issuer URL, token, JWT header, JWT claim, OAuth error code, provider response, HTTP body, exception text or user input is emitted.
- Any unknown/dynamic diagnostic string is discarded. No logging from production or unrelated stage modes.
- User-facing error remains the original generic Japanese message.
- The public-facing Nginx access-log format is unchanged and excludes query strings.
- The controller categorizes starting preflight, callback config/session/state/issuer/IdP error/code, token rejection, subject fingerprint, consent plan and final confirm. The provider adds bounded categorization for metadata, token endpoint transport/HTTP, token response schema and JWT verifier rejection.
- No diagnostic codes are persisted to the Canovia database or exposed through the stage account page. Read them through private Render logs only.

## Expected acceptance

1. Run the new `McpStagingOAuthFailureDiagnosticTest`, original `McpOAuthAccountLinkTest` and stage/Plan-consent regressions; ensure syntax/CI success and no user-visible sensitive diagnostic.
2. Merge to development `main` only after successful CI/review. **Do not deploy latest moving main to staging**. Re-check exact Render target and deploy a pinned reviewed merge commit with owner approval.
3. With existing stage login + Auth0 link switches unchanged, retry once in a normal fresh login session. Inspect Render `app` logs for the latest `stage_code`. Avoid logging or copying OAuth callback URLs, browser query strings and token response bodies.
4. React only to grounded code:
   - `link_callback_session` / `link_callback_state`: stage session persistence, browser tab/callback and session cookie behavior.
   - `link_callback_issuer`: verify actual RFC9207 `iss` presence and pinned issuer, without accepting an absent/mismatched issuer.
   - `link_callback_idp_error`: inspect Auth0 tenant authorized-client or social-connection setup privately; do not paste raw error descriptions.
   - `link_provider_token_http_rejected`: Auth0 client, callback, grants, and PKCE token exchange, without weakening client authentication.
   - `link_provider_token_payload_invalid`: Auth0 token-type/TTL/body schema.
   - `link_provider_jwt_rejected`: signed RS256 JWKS, exact issuer, resource audience, first-party client ID, scope and expiry; never decode or trust unverified claims.
   - `link_callback_plan_scope`: immutable synthetic Plan and active linked-subject match.
5. Only after the root cause is confirmed apply a separately reviewed, narrow fix and rerun E2E. Keep `CANOVIA_MCP_TOOLS_ENABLED=false`, `CANOVIA_MCP_DELEGATED_POLICY_ENABLED=false`; synthetic Plan consent alone does not authorize an MCP read.

## Compatibility and rollback

Previous generic errors, PKCE, state/issuer, one-time sessions, signed-token checks, scopes, time windows, routing and all disabled endpoints retain identical behavior. To roll back, deploy the previous stage SHA; do not alter production or reconfigure Auth0 globally just to make the test pass.

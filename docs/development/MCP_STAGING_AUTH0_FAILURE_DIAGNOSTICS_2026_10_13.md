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

## Concrete Auth0 interoperability hypothesis and conservative correction

Auth0's default tenant profile uses the OAuth `audience` request parameter to select the custom API when issuing an API JWT. The account-link authorization URL previously supplied **only** RFC 8707 `resource`. When Resource Parameter Compatibility Profile is disabled, Auth0 may ignore `resource` as the audience selector and issue an opaque/userinfo token or fail authorization; then Canovia's strict API JWT verification rejects the flow. This is plausible, **not yet confirmed** by an observed callback or trusted JWT.

The first-party Auth0 Link authorization URL now additionally supplies `audience` equal to the *same operator-pinned* `resource`. Only `auth0_jwt` verification mode is affected; legacy introspection providers still receive the previous exact `resource`-only request. Both identifiers are server-side and identical; no additional scopes or privileges are requested, and issuer/audience/client/scope/JWKS checks are retained. Auth0 explicitly permits both values and selects `audience` first if both are present. The third-party ChatGPT/CIMD flow still requires its own RFC 8707 compatibility and a later separate acceptance.

Official support:
- https://support.auth0.com/center/s/article/mcp-audience-error-with-auth0
- https://auth0.github.io/node-auth0/variables/index.Management.TenantSettingsResourceParameterProfile.html

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

## 2026-10-14 follow-up: 429 provenance and GitHub primary-subject classification

When the Auth0 confidential link preflight metadata GET returns exactly HTTP **429**, emit stage-only `link_provider_metadata_rate_limited`, reject the login attempt and do not proceed to consent. If the **Auth0 token POST** returns exactly 429, emit `link_provider_token_rate_limited` and fail closed. These categories mean **only** that the outbound IdP request received a 429 response; they do **not** prove Auth0 itself originated the throttle rather than a provider-side proxy. They say nothing about 429 generated by Laravel middleware, Render, the browser or the separate ChatGPT OAuth client. Do not retry either POST automatically.

The Auth0 signed GitHub-only path may fail on a verified non-GitHub primary `sub`. Previously `link_provider_github_subject_rejected` was not in the stage diagnostic allowlist, causing silent classification loss. It is now allowlisted for the isolated synthetic staging pilot only. It is **not** a request to disclose or transform the subject, fingerprint or login provider evidence in user-visible logs.

All three new diagnostics remain a fixed allowlisted `stage_code` with no dynamic data, body, headers, Retry-After, email, identity, token, URL or session identifier. Production and unrelated staging environments emit none. A stage deploy requires owner approval. Existing synthetic-only fixture scope, OAuth/PKCE/signature validation, rate limits and generic error UX remain unchanged. If a future 429 is reproduced, record only approximate timestamp/JST, click stage (link start, ChatGPT OAuth or token callback), and sanitized HTTP status; corroborate with private Render logs and Auth0 tenant counters **without** pasting callback URLs or secrets.

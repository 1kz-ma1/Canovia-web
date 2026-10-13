# ChatGPT Auth0 OAuth success / MCP action-discovery failure (2026-10-13)

## Actual operator observations (isolated stage only)

- Auth0 **ChatGPT** application logged **Success Login** and **Success Exchange**, including 2026-10-13 06:05 UTC. This proves a successful authorization-code exchange, **not** that Canovia accepted the access token.
- ChatGPT connector UI reported **Authentication succeeded, action discovery failed**.
- Render stage access logs during 06:18 UTC showed MCP POST **401** and OAuth discovery GET **200**. Safe log format deliberately omits URL, query, Authorization, request body and token details, so 401 cannot be conclusively attributed to a token-present request.
- Owner verified custom API Identifier exactly equals `https://canovia-mcp-staging.onrender.com/api/mcp`, ChatGPT third-party client ID matches the configured allowlist, access-token lifetime set to 3600 seconds, and Auth0 Resource Parameter Compatibility Profile enabled.
- None of these prove signed token `iss/aud/azp/scope/iat/exp` conformance in the real handshake. Do not ask for JWTs, authorization codes or callback URLs.

## Confirmed interoperability gap and bounded fix

Auth0 [Get Access Tokens](https://auth0.com/docs/secure/tokens/access-tokens/get-access-tokens) documents an RS256 access token that targets **both the requested custom API and issuer `/userinfo`** when `openid` scope is used. Before this patch, `McpJwtClaimConstraints` denied every two-audience JWT, including that exact documented pair.

After RS256/JWKS signature verification and strict issuer validation, the claims policy now allows only:

1. The exact configured MCP resource as a scalar `aud`.
2. An array containing the exact configured MCP resource alone.
3. An array of exactly two strings: that MCP resource plus `<verified Auth0 issuer>/userinfo` in either order, **only if** the verified token scope also includes `openid` and the original `canovia.development.read` check passes.

No generic extra audiences, userinfo-only audience, arbitrary issuer/userinfo endpoint, third audience, duplicates, or scope/client/time relaxation. All user-subject linking and fresh per-Plan consent rules remain untouched.

**This is a compatibility candidate, not a confirmed root cause.** In particular, no observed live access token or real ChatGPT `initialize` / `tools/list` success is available yet.

## Safe acceptance and blockers

- Require CI on exact PR head, including `McpJwtClaimConstraintsTest` and all existing JWT, MCP and staging isolation tests.
- Do not deploy from moving `main`. Review and deploy **one approved commit SHA** to isolated `canovia-mcp-staging`, Auto Deploy OFF; do not change production, shared IdP tenant, or service environment secrets.
- Retry ChatGPT OAuth and action discovery. Confirm an authenticated MCP `initialize` and `tools/list` result. If 401 persists, distinguish no Bearer delivered from actual JWT rejection, and investigate signed issuer, exact `aud`, OAuth `azp/client_id`, `canovia.development.read`, time bounds, Origin, and MCP request headers **without logging tokens or raw JWT claims**.
- Previously revoked synthetic Plan grant remains revoked. Only after action discovery succeeds may the owner separately re-consent the *synthetic Development Plan* for `overview` for one day. Then perform `tools/call`, wrong-Plan/scope negative checks, and revoke-after-read.
- The current pilot is **not** AP study access: it allows one synthetic Development Plan only. Real Learning/Study Plans and normal users require separate product/authorization design, audit, approval and deployment.

## Rollback

Revert this PR's claim-policy adjustment at an independently reviewed SHA. Alternatively disable the isolated staging synthetic-read, MCP tools, and delegated-policy flags together per `MCP_STAGING_SYNTHETIC_READ_PILOT_2026_10_13.md`. Never widen audience checks to “contains expected URL” on unrestricted arrays.

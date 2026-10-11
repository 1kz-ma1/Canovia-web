# Canovia ChatGPT MCP — Phase 1 Development Plan Read Acceptance

Date: 2026-10-11
Owner lane: Platform
Status: Implementation preparation only. **No real ChatGPT OAuth or MCP read acceptance yet.**
Decision: Phase 1 starts with the existing personal **Development** Plan and its two synthetic Tasks. Learning, Career and multi-domain discovery are explicitly deferred.

## Immutable operational boundary

- `main` before this slice: `15abdc9de6f24b5250fb255c579d1aa55facaf1a`; no direct changes to main.
- Production: Render `srv-dagfn2id0e5s73cac7ng` / Aiven MySQL; never use real user records, change production environment or deploy for the MCP pilot.
- Existing isolated stage: `srv-db43l4nlk1mc73emseig`; dedicated PostgreSQL 17 `dpg-db43rbbncjis73bmigi0-a` (Free, expiry 2026-11-08 01:04 UTC); synthetic Plan fixture previously persisted and verified, but **not** read via ChatGPT.
- Both services' Auto-Deploy remain OFF. All stage public Web/OAuth/MCP gates remain OFF. No stage redeploy, flags, public endpoints, IdP tenant, contract or billing change is authorized by this document.

## Narrow initial user experience

1. An authorized ChatGPT user connects the separately approved **synthetic** Canovia staging account to the IdP and approves **one** personal Development Plan.
2. ChatGPT calls existing `canovia_get_development_plan_context` with the exact owner-supplied `plan_id`; `overview` or explicitly approved `tasks` scope, `limit <= 8`.
3. Canovia independently verifies the token, immutable external subject-to-local actor link, current Plan ownership, category and specific active grant. Only bounded recorded metadata is returned.
4. Access by an unrelated user, mismatched client, unapproved Plan/scope, revoked grant, expired token, or disabled switch is denied. No write operation is exposed.

Phase 1 deliberately does **not** discover a Plan automatically, support Learning Plans, produce Mutation Candidates, or accept task mutations.

## Implemented in this preparation slice

- Add explicit per-tool `securitySchemes: [{type: "oauth2", scopes: ["canovia.development.read"]}]` to the existing read-only MCP tool descriptor.
- Test the exact declared scheme through the authenticated JSON-RPC `tools/list` response.
- Preserve all existing fail-closed bearer, OAuth metadata, exact audience/client/scope and Plan consent checks.
- This metadata alone does **not** initiate ChatGPT OAuth or prove a browser linking UI. Official guidance also requires appropriate tool-level runtime `_meta["mcp/www_authenticate"]` challenges when that auth interaction is needed. Evaluate the existing HTTP 401 resource challenge first, then separately design and test any additional tool-level challenge without leaking Plan existence or weakening transport authentication.

References:
- https://developers.openai.com/plugins/build/auth
- https://developers.openai.com/plugins/build/mcp-server
- https://auth0.com/docs/quickstart/backend/php
- https://auth0.com/docs/secure/tokens/access-tokens/get-access-tokens
- https://github.com/1kz-ma1/Canovia-web/pull/438

## Auth0 compatibility gate — NOT a provider decision

Auth0 is a **candidate**, not an active Canovia provider.

1. Confirm the intended tenant's actual Free/MCP/CIMD entitlements, tenant and client quotas, ongoing price, configured issuer, stable ChatGPT redirect, `iss` in authorization responses, S256 and `resource` support **before** provisioning or enabling anything. Approval is required before creating a tenant or changing billing/permissions.
2. Confirm that real tokens for the ChatGPT client and a separate Canovia account-link client carry a stable `iss+sub`, literal Development read scope, exact resource audience, expected client identity and a permitted lifetime.
3. Existing Canovia verification uses **RFC 7662 introspection**. Auth0 PHP API guidance uses **signed JWT/JWKS**; do not assume compatible introspection. Independently implement or select a maintained JWT/JWKS verifier on a separate feature branch only after confirming actual token claims and library API, with negative tests for algorithm confusion, invalid signatures, key rotation, issuer/audience/client/scope mismatch and expiry. Never parse unverified JWT claims for authorization.
4. Require single exact resource `aud` as in the current Canovia contract. Auth0 documents that requesting an API audience together with `openid` can add `/userinfo` as a second audience. Characterize actual tokens first; do not silently loosen Canovia's equality policy just to accept them.
5. Auth0 CIMD registration may map a public metadata URL to a **different internal client ID**. Record the actual issued identifier and prove it against verified tokens; do not equate the ChatGPT URL with a token claim by assumption.
6. Require both synthetic actor linking and fresh per-Plan consent. Verify positive and negative token/permission behavior **before** requesting stage public exposure.

## E2E acceptance (NOT MET)

- Exact isolated staging SHA confirmed and closed-stage fixture independently rechecked.
- Owner-approved IdP and tenant configuration, real ChatGPT client registration and Canovia link client; token verifier negative tests pass.
- Reviewed staging-only network/login/OAuth/MCP exposure with safe rollback.
- **Observed inside actual ChatGPT**: initialize, tools/list, valid tools/call returns the synthetic Plan title and both Task records; revoked access fails on subsequent real call.
- Document exact CI run, stage deploy and external ChatGPT E2E evidence separately. A mock token, test container or live Render health check cannot substitute.

No production release is authorized by successful staging acceptance.

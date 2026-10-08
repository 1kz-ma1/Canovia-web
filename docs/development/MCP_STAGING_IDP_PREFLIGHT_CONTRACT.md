# Canovia → ChatGPT MCP: isolated staging & OAuth IdP preflight

Updated: 2026-10-09
Status: **read-only compatibility checks implemented; no production enablement or real IdP tenant provisioned**.

## Current verified infrastructure boundary

Render currently has a production **Canovia** Web service (`pacekeeper-d3mm.onrender.com`) and a separate **HINANEX staging** static service. There is **no dedicated Canovia staging Web service** yet. Do not reuse HINANEX staging, production Aiven DB, production session encryption keys, OAuth credentials or user data for MCP experimentation. This repository change does **not** create a Render service, IdP tenant, paid subscription, production environment variable or user consent.

The code from PR #389–#395 implements discovery, RFC7662 access-token verification, private account linking via PKCE S256, explicit per-Plan consent and a single bounded MCP read-only tool, all behind independent OFF-by-default switches. Merging this preflight does not activate any of those switches.

## Proposed isolated staging architecture

```
ChatGPT test connection (private / staging)
    -> staging Canovia HTTPS /api/mcp (independent Render Web service)
       -> staging IdP issuer / registration / OAuth / introspection
       -> staging Canovia DB with SYNTHETIC accounts/Plans/Tasks only

Production ChatGPT connections: OFF
Production Canovia + Aiven DB: unchanged
```

Provisioning the isolated Web service/DB/IdP tenant may entail cost and requires an operator decision. Before provisioning, use a dedicated staging subdomain, independent DB connection and migration target, unique Laravel `APP_KEY`, isolated sessions/cache/queue and a separately generated high-entropy fingerprint key. Keep `APP_DEBUG=false`; avoid production email, notifications, webhooks, payment, background jobs and secrets; restrict test accounts. Check both Render spend and external IdP tenant limits before adding services.

Never point staging migrations to the production DB. Verify the actual DB host/database name out of band **without writing credentials into GitHub docs, ChatGPT prompts or logs**.

## Identity provider candidate evaluation

Identity-provider choice is **not yet finalized**:

| Candidate | Publicly documented capability | Must verify with an actual staging tenant |
|---|---|---|
| Auth0 | Auth for MCP and CIMD registration support; JWT/JWKS is a documented token validation model. | Whether the chosen tenant/plan supports **RFC7662 introspection with all of Canovia's strict claims**, or a separate maintained JWKS validator is required; exact client ID emitted after CIMD registration; canonical audience, PKCE, callback issuer support. |
| ZITADEL | OAuth introspection endpoint with `active`, `iss`, `aud`, `client_id`, `exp`, `scope` and Basic-auth API clients; DCR documentation. | Exact resource `aud` and immutable `sub` for both OAuth clients, RFC9207 `iss` on all callbacks, `canovia.development.read` scope, actual ChatGPT client registration and token method, availability for the chosen tenant/plan. |

An IdP's **feature documentation is not proof of compatibility for a specific tenant**. The current Canovia resource server is deliberately strict: only an active introspection response with exactly the configured issuer, canonical single resource audience, verified immutable subject, actual registered ChatGPT `client_id`, allowable lifetime and precise `canovia.development.read` scope can pass. Do not loosen these validations just to accommodate a provider. Auth0's mapped internal client identifier after CIMD registration may differ from the external CIMD URL; configure the **actual token client ID** only after confirming a real issuance path.

Current account-link PKCE client and ChatGPT OAuth client must be **separately registered**. The IdP must support S256 PKCE, `client_secret_basic` for Canovia's confidential linking client, RFC9207 authorization-response issuer, suitable `resource` handling and compatible token introspection. ChatGPT requires CIMD (`none` or `private_key_jwt`), DCR or a properly pre-registered client, plus its exact callback URI. **Do not invent ChatGPT callback IDs**. Read the URI from the actual connection setup; the stable redirect `https://chatgpt.com/connector_platform_oauth_redirect` is conditional on issuer-identification support.

## Operator command (run ONLY in isolated staging)

`php artisan canovia:mcp-staging-preflight --json`

- **No network**: checks that current server-side resource, introspection, confidential account-link client, consent, policy and MCP switch configurations are valid. Outputs only named `pass` / `blocked` / `not_probed` status codes, provider-mode names and a **manual acceptance checklist**. No URL, credential, secret, token, subject, fingerprint or private data appears in the result.
- Does not make the service ready by itself: without an explicit metadata probe the status is incomplete and the command exits nonzero.

`php artisan canovia:mcp-staging-preflight --probe-metadata --json`

- Fetches the **server-owned, validated HTTPS IdP discovery URL** exactly once with GET, no token or client credentials, no redirects, 2-second connect and 5-second total timeout. Only when trusted account-link configuration is valid; otherwise **no HTTP request** occurs.
- Requires matching issuer, authorization/token endpoints, `authorization_response_iss_parameter_supported=true`, PKCE S256, `client_secret_basic` for Canovia's confidential linking client, the read scope, advertised introspection endpoint exactly matching configured server introspection URL, and a usable ChatGPT CIMD or DCR registration path. Reports supported mode names only. Invalid/error/overlong/non-JSON metadata fails closed. It does not download `jwks_uri` or follow supplied URLs from the discovery document.
- Exit code **0** only means local configuration and this static IdP metadata inspection passed. `production_authorized` is **always false**, even on exit 0. It does not verify IdP-issued bearer-token claims, approve ChatGPT client registration, execute OAuth redirects, access any Plan, write audit/grant records or authorize switching ON production MCP.

For troubleshooting without any network use the first command. There is no option to pass bearer tokens or IdP secrets via CLI arguments (which would leak via process listing). The command is a **read-only preflight** and must never write a `real connection OK` marker automatically.

Example result shape (status names only; illustrative, not a real run):

```json
{
  "schema": "canovia.mcp.staging_provider_preflight.v1",
  "local_checks": {"resource_discovery": "pass"},
  "provider_checks": {"oauth_discovery": "not_probed"},
  "supported_client_modes": [],
  "preflight_passed": false,
  "production_authorized": false,
  "remaining_external_checks": ["provision_isolated_canovia_staging_service_and_database"]
}
```

## Required manual staging acceptance (still NOT completed)

1. Provision isolated Canovia staging Web service, DB, tenant and developer accounts **after confirming provider/Render pricing**.
2. Register the **Canovia confidential client** callback at exactly `https://<CANOVIA_STAGING_HOST>/account/mcp/link/callback`, set its own client ID and secret via staging-only secrets, enable PKCE S256 + RFC9207.
3. Configure protected resource `https://<CANOVIA_STAGING_HOST>/api/mcp`, staging issuer and API/introspection credentials. Configure a separate ChatGPT OAuth client (CIMD or DCR or approved predefined registration), exact shown callback URI and the **actual client ID found in issued token introspection**, not an unverified guess.
4. Run preflight with IdP metadata; fail and fix any blocked check.
5. Using synthetic data only, prove IdP issuer, immutable subject, `client_id`, single canonical `aud`, scope, expiration, token revocation and introspection access controls for **both** the confidential Canovia linking client and the ChatGPT client. A compatible metadata document is not enough.
6. Perform staging Canovia login → PKCE identity link → explicit same-subject per-Plan `overview` grant → ChatGPT OAuth connection → MCP initialize/list/read, then validate `tasks` scope and maximum 8 fields/Task titles.
7. Attempt a different user's Plan, a shared Plan, permission removal, expiry, subject unlink and malformed/forged tokens; no foreign Task content must be returned, and audit must exclude private payload. Also test token expiration/re-authentication and ChatGPT callback variations.
8. Review concurrency, rate limits, WWW-Authenticate, resource metadata, Origin and transport interoperability with ChatGPT's live client; investigate if the SDK/client requires a supported protocol tweak.
9. Get explicit release sign-off before changing **production** flags, credentials, DB linkage or allowing external access to real user data. Provide rollback instructions and verify disable switches actually restore deny-all. Device checks (iPhone/WKWebView/PWA/PC) will be performed later as requested by the owner.

## Security invariants

- Never use HINANEX staging or the live production database for token/client experimentation.
- Never include bearer tokens, introspection credentials, APP_KEY or fingerprint secret in source, PRs, screenshots, command output, notes or logs.
- Never infer that an OAuth issuer supports the resource audience, client mapping or actual revocation semantics from metadata alone.
- Never mark a Plan complete based on a returned Task percentage or PR/CI/deploy/device status inferred from MCP data.
- All real Canovia/ChatGPT OAuth and MCP execution switches remain OFF unless separately and deliberately enabled in isolated staging.
- Metadata checks require explicitly operator-configured URLs; no URL is taken from a Plan, token or untrusted request parameter.

## Reference documentation

- [OpenAI authentication for plugins](https://developers.openai.com/plugins/build/auth)
- [Auth0 CIMD registration API](https://auth0.com/docs/api/management/v2/clients/post-clients-cimd-register)
- [ZITADEL OAuth introspection endpoint](https://zitadel.com/docs/apis/openidoauth/endpoints)
- [ZITADEL DCR](https://zitadel.com/docs/guides/integrate/dynamic-client-registration)
- [MCP authorization](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization)

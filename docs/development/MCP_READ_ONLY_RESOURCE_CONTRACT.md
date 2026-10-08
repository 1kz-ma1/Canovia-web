# Read-only MCP Streamable HTTP Development Context — disabled in production

Updated: 2026-10-09
Status: **implementation present, separately OFF by default; no connected ChatGPT IdP.**

## What is implemented

`/api/mcp` can serve a deliberately small, stateless subset of the
[MCP Streamable HTTP](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports)
transport **only after** every required server-owned setting is enabled.
Without `CANOVIA_MCP_TOOLS_ENABLED=true`, the established deny-all probe
still returns 404 (Discovery OFF) or 401 (Discovery ON) on GET/POST/DELETE;
not even OAuth introspection occurs.

When deliberately enabled and all previous identity, consent and security
settings are ready, the endpoint supports JSON-RPC 2.0 POST:
- `initialize` (negotiates `2025-11-25`, `tools` capability),
  `notifications/initialized` (HTTP 202, no content), `ping`,
  `tools/list` and `tools/call`.
- Only `canovia_get_development_plan_context` is advertised and
  callable. Its inputs are `plan_id` (positive integer required),
  `scope=overview|tasks` (default overview), `limit=1..8` (default 5).
- `overview` returns a bounded Plan title and empty Task collection.
  `tasks` requires an **explicit tasks-level grant**, returning up to
  eight selected Task titles (<=160 chars), status and recorded progress,
  plus truncation indication. Task description, evidence, work logs, GitHub
  private repository data, owner tokens, user emails, plan resources,
  authorization credentials and full Eloquent models are never exported.
  The projection reuses the tested, whitelisted private preview surface
  but has its own `canovia.mcp.development_plan_context.v1` schema and
  `delivery=delegated_read_only`; no user activity is marked completed.
- Every authorized read writes a **metadata-only** event
  `context_read` (current actor ID, linked subject row ID, grant ID,
  overview/tasks scope and timestamp), never the payload, Plan/Task title,
  bearer, subject, client secret or cookies. The Plan, external subject link
  and grant are locked in one database transaction through the projection,
  so permission revocation or ownership changes cannot interleave with
  reading the whitelisted Task fields.
- Unknown Plan, other actor's Plan, missing grant and insufficient scope
  yield identical sanitized MCP tool errors; no unauthorized title/data
  leaks, no implicit Plan discovery, no bulk exports.
- Unknown JSON-RPC methods and invalid parameters produce protocol errors;
  notification-only `initialized` emits HTTP 202 without JSON; GET SSE
  and DELETE stateful sessions return 405 after authentication. The current
  server implements neither SSE streams nor MCP sessions, push messages,
  resources, prompts, mutations, write tools or private GitHub reads.

## OAuth and transport boundaries

**Every** active request, including initialization, tool discovery and
notifications, requires one server-to-server Bearer token. Browser session
cookies and Canovia app login are never accepted. Tokens must pass the
configured trusted issuer's RFC 7662 introspection with active status, exact
issuer, subject, ChatGPT **client ID**, resource audience, read scope and
unexpired lifetime. No token is cached or persisted.

The current private Plan must still belong to the **linked Canovia actor**,
be noncollaborative and Development-classified, and have an unrevoked,
unexpired **separately approved** grant for the exact linked subject, Plan,
ChatGPT client/resource, and `overview` or `tasks`. A
`prepared` preference, previously linked subject or callback alone never
grants access. Revocation and expiry are rechecked for every tool call.

OAuth failure returns 401 with RFC 9728 `WWW-Authenticate` resource
metadata and scope; the original error reason is not leaked. An active
browser `Origin` must be explicitly present in
`CANOVIA_MCP_ALLOWED_ORIGINS` as an exact HTTPS origin with no path,
including official ChatGPT origin(s) **only when actually verified**.
Originless server-to-server calls can proceed with valid bearer. No
arbitrary Origin is trusted and no permissive CORS header is sent. Requests
must use `Content-Type: application/json`, and `Accept` support
`application/json, text/event-stream`. JSON-RPC payload is capped at
8KiB; requests and outputs are never stored as access audit material.
All responses use `Cache-Control: no-store, private`.

### Configuration: keep OFF on Render

```env
CANOVIA_MCP_DISCOVERY_ENABLED=false
CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED=false
CANOVIA_MCP_ACCOUNT_LINK_ENABLED=false
CANOVIA_MCP_PLAN_CONSENT_ENABLED=false
CANOVIA_MCP_DELEGATED_POLICY_ENABLED=false
CANOVIA_MCP_TOOLS_ENABLED=false
CANOVIA_MCP_ALLOWED_ORIGINS=
```

The further issuer, exact `/api/mcp` resource, allowed ChatGPT client ID,
IdP introspection endpoint/credentials, Canovia account-link client and
keyed subject fingerprint key must already be correctly configured.
**Never** enable tools only because tests pass with `Http::fake` or
because a valid-looking mock grant row exists. Production has not
registered ChatGPT's OAuth client, an IdP tenant or redirect URLs.

## Remaining release gates

1. Choose and externally configure a real OAuth provider with verified
   issuer, Authorization Code + PKCE S256, CIMD/DCR or approved client
   registration, proper resource audience and compatible introspection
   claims (otherwise choose a maintained JWT validation library).
2. Perform dedicated IdP staging integration tests for Canovia browser
   identity binding, ChatGPT OAuth connect callback, explicit per-Plan
   consent and revoke/expiry across actors, tabs and sessions.
3. Security review token lifetimes, SSRF/introspection transport, session
   integrity, Origin/Host behavior, concurrency and audit volume/retention.
4. Verify actual ChatGPT connector round-trip and compatibility with
   real client handshake, using staging synthetic Plans only; explicitly
   enable production OAuth and tool flags **only** after acceptance.
5. User requested iOS/PWA/PC device verification as one later batch; it
   has not been performed by this implementation.

## Automated verification

`tests/Feature/McpReadOnlyResourceTest.php` requires off-by-default 404/401,
no network when disabled, strict bearer, 401 challenge, authentic IdP
claims, JSON-RPC initialize/list/notifications and read-only input schema,
one-Plan/one-scope/1–8 items, escaped Task description, precise audit,
unapproved prepared-only records, cross-user non-disclosure, nonexistent
Plans, scope downgrade, expired and revoked links/grants, Plan transfer,
collaboration/category changes, malicious Origin, malformed args and
unsupported write/session verbs. CI must still pass all prior MCP consent,
account link, revocation and platform regressions.

Sources:
- https://modelcontextprotocol.io/specification/2025-11-25/basic/transports
- https://modelcontextprotocol.io/specification/2025-11-25/server/tools
- https://developers.openai.com/plugins/build/auth


## Provider preflight without public activation (2026-10-09)

A new **CLI-only** `canovia:mcp-staging-preflight` command verifies
server-configured OAuth/Plan security switch readiness and, only with
`--probe-metadata`, inspects a pinned HTTPS issuer discovery document.
No token, account, Context or DB read/write occurs; no staging service,
IdP tenant, ChatGPT client or production flag has been provisioned.
The command never authorizes production activation—even when the metadata
passes. Actual token `aud` / `client_id` and revocation semantics still
require isolated IdP staging and end-to-end tests. See
[staging runbook](MCP_STAGING_IDP_PREFLIGHT_CONTRACT.md).

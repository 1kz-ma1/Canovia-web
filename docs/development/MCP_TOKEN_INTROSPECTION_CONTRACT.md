# OAuth bearer token verification — RFC 7662 introspection foundation

Updated: 2026-10-09
Status: **implemented, OFF by default, NOT connected to /api/mcp**.

## What this slice does

`McpAccessTokenIntrospector` validates a raw OAuth access token by asking a
separately configured, trusted OAuth authorization server to perform
[RFC 7662 token introspection](https://www.rfc-editor.org/rfc/rfc7662).
The provider is responsible for authentication of the introspection caller
and for **cryptographically verifying the token** (including its signature if
applicable). This is an alternative to locally verifying JWTs against JWKS,
**only where the chosen provider implements introspection and returns all
required claims**.

The verifier returns a `McpVerifiedTokenPrincipal` describing an immutable
external `(issuer, subject, client_id, audience, scopes, exp)`. It is
**not** a Canovia `User`, a linked Canovia account, a consent grant, or Plan
authorization. Caller code must never infer a user from an email address,
username, browser session or the previously saved `prepared` preference.

### Token and IdP security checks

- Disabled unless the separately gated `CANOVIA_MCP_DISCOVERY_ENABLED=true`
  and `CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED=true`, and the canonical
  HTTPS resource / authorization-server issuer / introspection credentials
  and exact approved OAuth client identifier are set.
- The endpoint must use HTTPS on the **same host as the configured issuer**,
  with no nonstandard port, username/password, query, fragment, localhost or
  raw IP host. The endpoint must be operator-configured, never derived from a
  request body, Plan ID, OAuth token or browser Host/Forwarded headers.
- Introspection POST uses confidential-client HTTP Basic authentication,
  includes only `token` and `token_type_hint=access_token`, follows **no
  redirects**, and has a 2s connect / 5s total timeout. It never caches
  token introspection results or logs the raw token, response or secrets.
  Do not enable HTTP request-body instrumentation for this endpoint.
- Requires HTTP 2xx JSON response (<=12KiB); `active === true`;
  exact `iss`, `client_id`, and one exact `aud` of the canonical resource;
  nonempty bounded immutable `sub`; access token type; integer future
  `exp` with at most 3600 seconds remaining; valid `nbf/iat` if present,
  including a maximum one-hour lifetime when `iat` exists; and the exact
  `canovia.development.read` OAuth scope. Other resources, clients, issuers,
  expired tokens and partial/prefix scopes fail closed.
- Transport failures, redirects, missing IdP config and malformed/oversized
  tokens or responses return `null` without retaining raw bearer material.

### Server-only configuration

```env
CANOVIA_MCP_DISCOVERY_ENABLED=false
CANOVIA_MCP_RESOURCE_URL=
CANOVIA_MCP_OAUTH_ISSUER=
CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED=false
CANOVIA_MCP_INTROSPECTION_URL=
CANOVIA_MCP_INTROSPECTION_CLIENT_ID=
CANOVIA_MCP_INTROSPECTION_CLIENT_SECRET=
CANOVIA_MCP_ALLOWED_CLIENT_ID=
```

Do not configure a guessed introspection URL or token to test production.
A provider that does not support RFC 7662 with the strict `iss`, `aud`,
`exp`, `client_id`, `sub`, and scope fields is **not compatible** with
this adapter. Choose an established, supported JWT/JWKS validation library
instead; do not hand-roll JWT signature verification or silently relax the
claim checks. ChatGPT supports CIMD client identity and PKCE S256; verify
the provider's exact issuer, client metadata, redirect URI, resource indicators
and required token fields before enabling any integration.

## Separately staged Plan authorization policy (still disabled)

`McpDelegatedPlanAccessPolicy` and initially empty `mcp_linked_subjects` /
`mcp_delegated_grants` tables can check an external subject's durable
fingerprint, current Canovia owner/Development/noncollaborative state and
exact Plan/client/resource/scope/time-limited grant. It is intentionally OFF
and not called by `/api/mcp`. No link-creation or formal-consent path exists.
See [delegated Plan policy contract](MCP_DELEGATED_PLAN_POLICY_CONTRACT.md).

## What remains absolutely denied

- `GET|POST|DELETE /api/mcp` continues to **always return 401** if discovery
  is configured and 404 otherwise. It deliberately does not call the new
  introspector. No MCP JSON-RPC tools, OAuth issuance or token consumption
  routes have been introduced.
- A verified external subject is not automatically associated with a
  Canovia user. A separate, user-initiated, protected account-linking flow
  must bind the stable `(iss,sub)` to the authenticated Canovia actor
  and record immutable linked-identity evidence.
- A later OAuth consent exchange must require a fresh owner decision for
  **client + Plan + scope + expiry**, then persist revocable grants separately
  from sharing preferences. The `prepared` status is never consent.
- Every delegated read must recheck the linked current Canovia actor,
  Plan ownership, Development classification, collaborative status, selected
  scope, grant validity, token audience and client, expiry and revocation.
- Only after all layers are independently tested may the actual MCP JSON-RPC
  handler and read-only tool be wired. No automatic Task update, GitHub write,
  background read or silent private-Context export.

## Validation

`tests/Feature/McpAccessTokenIntrospectorTest.php` uses isolated fake
IdP responses and rejects forged/expired/cross-audience/cross-client tokens,
bad issuer, missing scope, endpoint spoofing, provider errors and redirects.
`tests/Feature/McpProtectedResourceGateTest.php` verifies that even enabled
introspection is not used by the still-deny-all MCP probe. These must run
in required CI with PHP lint and existing MCP/Plan isolation tests.

Official sources:
- [OpenAI MCP authentication](https://developers.openai.com/plugins/build/auth)
- [MCP authorization](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization)
- [RFC 7662](https://www.rfc-editor.org/rfc/rfc7662)

## Canovia's separate account-linking client

An optional `verifyAccountLink` method reuses strict IdP introspection
requirements but compares `client_id` with **Canovia's own confidential
account-linking OAuth client**, never ChatGPT's client ID. This runs only
after PKCE code exchange and independently validated callback state+issuer.
The resulting `sub` is a prerequisite for an owner-confirmed identity
link only; it does not authorize private MCP reads. See
[account linking](MCP_OAUTH_ACCOUNT_LINK_CONTRACT.md).

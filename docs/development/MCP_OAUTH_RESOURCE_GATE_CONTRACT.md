# MCP protected-resource boundary — deny-all discovery foundation

Updated: 2026-10-09
Implementation status: **foundation only, no ChatGPT connection or private data access**.

## Status and threat model

The first-party Development Workspace already offers GitHub-first specifications, a manual private Context preview, and `chatgpt` sharing **preparation preferences**. A saved preparation is NOT an OAuth authorization grant.

ChatGPT / Codex access to private Canovia Plan data requires a real OAuth 2.1 Authorization Code + PKCE (S256) authorization server and an MCP resource server that authenticates bearer tokens on **every request** and checks per-user/per-Plan grants. Public GitHub read access or a Laravel browser session is not sufficient.

The present slice implements only the **protected resource discovery and unauthorized challenge boundary**:

- `config/canovia_mcp.php` sets `discovery_enabled=false` by default.
- `GET /.well-known/oauth-protected-resource` and `GET /.well-known/oauth-protected-resource/api/mcp` expose the RFC 9728 JSON resource description **only** when all server-owned configuration values are valid.
- `GET|POST|DELETE /api/mcp` is an **authorization probe**. Disabled or invalid config => HTTP 404. Even when discovery is enabled, it **always responds 401** with a `WWW-Authenticate: Bearer resource_metadata="...", scope="canovia.development.read"` challenge; a supplied Authorization header adds `error="invalid_token"`. There is no JSON-RPC tool handler or token verifier yet.
- Discovery lists exactly one read scope, the configured canonical HTTPS `/api/mcp` resource and one verified-format HTTPS OAuth issuer URL. All URLs originate from server config, never incoming Host, Forwarded or user-provided Plan IDs.
- No public Task/Plan endpoint, JSON-RPC method, OAuth login callback, client registration, token, identity binding, consent grant or database write is implemented. An existing Canovia session and a `prepared` ChatGPT preference are ignored by the MCP probe.
- Responses use no-store and anti-content-sniff headers, are throttled and return no Plan/Task data. No third-party API calls are performed.

## Configuration — intentionally OFF on Render

```env
# DO NOT enable in production until a real vetted OAuth AS is configured.
CANOVIA_MCP_DISCOVERY_ENABLED=false
CANOVIA_MCP_RESOURCE_URL=
CANOVIA_MCP_OAUTH_ISSUER=
```

For isolated staging discovery tests only, a valid configuration would use `CANOVIA_MCP_RESOURCE_URL=https://your-canonical-host/api/mcp` and `CANOVIA_MCP_OAUTH_ISSUER=https://your-identity-provider/issuer`. Resource identifiers must be exact HTTPS strings, with no HTTP, credentials, query, fragments, untrusted host or arbitrary path. **Never** configure an invented issuer or use the Canovia web login page as the OAuth issuer. A set of syntactically valid URLs by itself does not mean ChatGPT can link accounts.

The OAuth identity provider must be selected and set up separately. No `/.well-known/oauth-authorization-server` document is served by Canovia in this stage: that discovery endpoint is the real provider's responsibility. The provider must handle `resource`, audience, client identity/CIMD or DCR, actual authorization/consent, PKCE S256, short-lived token issuance and revocation.

## Independent token verifier foundation (2026-10-09)

A disabled-by-default `McpAccessTokenIntrospector` now supports RFC 7662 introspection at a **server-configured, HTTPS, same-issuer** IdP endpoint, returning only an external identity description after strict token audience, issuer, client, lifetime and scope checks. This does not authenticate a Canovia User, does not constitute consent, and is **not invoked by /api/mcp**. A provider must explicitly support RFC 7662 with all required claims. If the chosen IdP instead uses signed JWTs without compatible introspection, select a maintained signature-verification library and document the specific issuer/JWKS before enabling it. Details: [Introspection contract](MCP_TOKEN_INTROSPECTION_CONTRACT.md).

## Next independent implementation gates

1. Select a security-reviewed OAuth identity provider; confirm OpenAI client identification (prefer CIMD, or DCR / pre-registered client) and production callback URI, token methods and configured resource audience.
2. Add a verified token validation layer through an established, actively maintained OAuth/JWT library (or a reviewed introspection API), checking signatures, issuer, exact audience/resource, time validity, client identity and scopes; never accept an arbitrary bearer string or Canovia session as external auth.
3. Implement a **new** explicit in-flow user consent screen that binds the current Canovia owner, one current personal Development Plan, read-only scope, expiry and client. The previous preparation record can at most pre-populate that screen; it does not authorize access. Record approval and revocation audits without raw Context or tokens.
4. On every read re-check current owner/domain/collaboration state and that grant is active, not expired or revoked; deny on any mismatch. Expand negative tests for cross-account, prepared-only, reassigned/shared Plans, token replay, scope and audience confusion, provider errors and session cookies.
5. Only after 1–4 pass implement the actual MCP JSON-RPC read-only tool and advertise `securitySchemes`. Test on an isolated environment, then deploy separately. No auto-write, Task progress inference or GitHub write is permitted.

## Required verification

Run `tests/Feature/McpProtectedResourceGateTest.php` in required CI. Tests must cover:
- Empty/off config: all metadata + MCP endpoints 404 even for forged bearer.
- Explicit valid config: correct metadata at both public URLs; cannot be poisoned by `Host`.
- Enabled probe: 401 plus correct challenge on GET, POST and DELETE, even with forged bearer or login session.
- Valid Canovia session + pre-saved `chatgpt` preference: no Task/Plan leakage and no state mutations.
- HTTPS validation rejects malformed issuer/resource URLs, credentials, embedded newline, query, localhost, fragments and noncanonical resource path.

Relevant sources:
- [OpenAI: plugin MCP authentication](https://developers.openai.com/plugins/build/auth)
- [OpenAI: build an MCP server](https://developers.openai.com/plugins/build/mcp-server)
- [MCP authorization specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization)

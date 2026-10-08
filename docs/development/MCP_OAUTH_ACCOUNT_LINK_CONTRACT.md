# Canovia OAuth account linking — Authorization Code + PKCE S256

Updated: 2026-10-09
Status: **OFF by default. Browser actor / IdP subject linking only, no external Plan consent, no live MCP tools.**

## Two separate OAuth clients are mandatory

The Canovia MCP resource server uses an approved ChatGPT client identifier
(e.g., a CIMD URL) to validate **ChatGPT-provided access tokens**. The
**Canovia account-linking application** is a different confidential OAuth
client, used only when a person already logged into Canovia chooses to link
their Canovia account to their immutable identity at the selected IdP.

**Never** reuse ChatGPT's client ID, credential, redirect URI or authenticated
MCP token as proof of the Canovia browser user's identity. Never identify
an actor by matching an external email address.

## Active code path (disabled until deliberately configured)

1. A Canovia session actor explicitly chooses **認証プロバイダーで本人確認を開始**
   from Account. This POST is CSRF-protected and throttled. Older pending
   linking state is removed.
2. The server fetches only the operator-configured, HTTPS OAuth metadata URL,
   verifying the exact `issuer`, authorization/token endpoint URLs,
   `code_challenge_methods_supported` includes `S256`,
   `authorization_response_iss_parameter_supported === true`,
   `token_endpoint_auth_methods_supported` includes `client_secret_basic`,
   and advertised read scope. Missing/incompatible metadata aborts before
   redirecting.
3. A fresh 256-bit `state` and PKCE verifier are created. The session holds
   only a SHA-256 state digest, verifier, actor ID and issue timestamp.
   Browser redirect includes `response_type=code`, linking client ID,
   operator-pinned HTTPS callback, exact `resource`, scope, `state`,
   `code_challenge` and `code_challenge_method=S256`.
4. The OAuth callback is accepted only for the **same currently authenticated
   Canovia actor and session**, within 5 minutes, with exact state and the
   exact RFC 9207 `iss`. The pending state is consumed **before** validating
   or exchanging code. Errors, stale/replayed, missing issuer, actor change
   and ambiguous callbacks abort.
5. The backend exchanges the code using the linking client's confidential
   Basic authentication and PKCE verifier, HTTPS token endpoint pinned to
   the same issuer host, without redirects, with fixed 2s connect / 5s total
   timeouts, bounded token response and **no refresh token persistence**.
   The returned access token must be `Bearer` with an integer lifespan
   up to 3600s.
6. That token is checked by the **real IdP's RFC 7662 introspection** through
   the existing server-only introspection credentials. It must be active,
   issued by the exact issuer, target only Canovia's MCP resource audience,
   contain the exact linking client's `client_id`, immutable `sub`,
   expiration and `canovia.development.read` scope. ChatGPT-client tokens
   **cannot** be used for account linking.
7. After verification, store **only** a keyed HMAC-SHA256 fingerprint of
   `(issuer,sub)` in a 5-minute Canovia session and return to Account. No
   DB mutation yet. User explicitly chooses **本人IDの紐付けを確定** with
   CSRF-protected POST. A transaction locks the Canovia actor, rejects a
   subject belonging to another user, prevents multiple simultaneous active
   ChatGPT subject links, and stores the fingerprint / link timestamps only.
   Record a metadata-only `subject_linked` audit. Relinking the same
   previously revoked subject after fresh OAuth verification is allowed,
   but all previous Plan grants stay revoked.
8. User may cancel before confirmation. Pending/verified session data is
   single-use and expires after 5 minutes. OAuth code, raw IdP subject,
   access/refresh token, verifier and provider secrets are not persisted
   beyond the necessary short-lived server session (the verifier is consumed
   at callback).

The `mcp_linked_subjects` row is **NOT** an active Plan sharing consent.
`mcp_delegated_grants` is not written or activated by account linking.
The existing `/api/mcp` remains deny-all even after a successful link, and
the owner may later unlink through existing Account revocation controls.

## Config — DO NOT enable on production before provider validation

```env
CANOVIA_MCP_DISCOVERY_ENABLED=false
CANOVIA_MCP_RESOURCE_URL=
CANOVIA_MCP_OAUTH_ISSUER=
CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED=false
CANOVIA_MCP_INTROSPECTION_URL=
CANOVIA_MCP_INTROSPECTION_CLIENT_ID=
CANOVIA_MCP_INTROSPECTION_CLIENT_SECRET=
CANOVIA_MCP_ALLOWED_CLIENT_ID=
CANOVIA_MCP_IDENTITY_FINGERPRINT_KEY=
CANOVIA_MCP_ACCOUNT_LINK_ENABLED=false
CANOVIA_MCP_ACCOUNT_LINK_CLIENT_ID=
CANOVIA_MCP_ACCOUNT_LINK_CLIENT_SECRET=
CANOVIA_MCP_ACCOUNT_LINK_METADATA_URL=
CANOVIA_MCP_ACCOUNT_LINK_AUTHORIZATION_ENDPOINT=
CANOVIA_MCP_ACCOUNT_LINK_TOKEN_ENDPOINT=
CANOVIA_MCP_ACCOUNT_LINK_REDIRECT_URI=
```

The link client ID **must differ** from `CANOVIA_MCP_ALLOWED_CLIENT_ID`,
which is ChatGPT's OAuth client. The callback must exactly equal the canonical
resource HTTPS origin + `/account/mcp/link/callback`. IdP metadata,
authorization and token endpoints must have the exact configured issuer host,
no userinfo, query, fragment, insecure scheme, nonstandard port, localhost
or IP-literal host. All URLs come from server config, never `Host` headers.

The provider must be selected and externally configured first. **Auth0 is a
candidate**, not a connected Canovia IdP today. Provider support for RFC9207,
PKCE S256, client-secret Basic, resource indicator/audience and the required
introspection claim set must be confirmed using a staging tenant. If it
cannot provide those, adapt the architecture after a security review; do not
remove the validation checks merely to make login succeed.

## What remains before ChatGPT itself can connect

- Select/verify the production IdP and register ChatGPT CIMD/DCR client,
  callback URI(s), supported OAuth/PKCE flow and actual resource audience.
- Build a separate **explicit OAuth in-flow Plan consent UI** bound to
  verified user identity, client/resource/Plan/scope/expiry, plus append-only
  approval events and immediate revoke.
- Only then implement the actual read-only MCP JSON-RPC tool using the
  token verifier + current delegated Plan policy on every request. Never
  infer Task completion or mutate progress from the linked identity.
- Run production configuration/staging security tests and later the
  user-requested batch iOS/PWA/PC device validation.

## Verification

Feature tests must cover disabled/invalid provider config; incompatible
discovery; OAuth redirect parameters, PKCE digest and state; replay/expiry;
issuer mismatch; another Canovia actor; bad token audience/client claims;
explicit confirm/cancel; cross-user subject uniqueness; safe re-link after
revoke; account UI not exposing tokens or external subject; no new Plan grant,
no automatic MCP activation and no DB writes before confirmation.

Sources:
- https://developers.openai.com/plugins/build/auth
- https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization
- https://www.rfc-editor.org/rfc/rfc9207
- https://www.rfc-editor.org/rfc/rfc7636

# MCP personal Development Plan sharing approval — fresh PKCE + two-step consent

Updated: 2026-10-09
Implementation status: **internal, disabled by default. Not live ChatGPT MCP connectivity.**

## Why a separate approval is mandatory

Three distinct Canovia/ChatGPT actions have different authorization meanings:

1. **準備設定 / prepared**: save a proposed scope and lifetime for a personal
   Development Plan. Not consent, no outside access.
2. **外部ID紐付け / linked**: the Canovia session owner has confirmed a
   stable, identity-provider-verified `(issuer,sub)` after confidential OAuth
   Authorization Code + PKCE S256. Not a Plan grant.
3. **Plan共有許可 / consented**: the linked owner chooses exactly one Plan,
   `overview` or `tasks`, an explicit 1/7/30-day grant lifetime, completes
   **fresh** PKCE IdP authentication for the *same linked subject*, reviews a
   server-generated scope/Plan/lifetime confirmation, and POSTs a second
   explicit permission approval. Only then is `mcp_delegated_grants`
   activated for the current actor, subject link, Plan and approved ChatGPT
   client + resource.

This is **Canovia's own resource-sharing approval**, not the external IdP's
OAuth consent screen for ChatGPT; a ChatGPT OAuth token still needs issuer,
audience, client/scope verification, and an independently established MCP
connection before any data is readable.

## Config — keep disabled until the external IdP is verified

```env
CANOVIA_MCP_DISCOVERY_ENABLED=false
CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED=false
CANOVIA_MCP_DELEGATED_POLICY_ENABLED=false
CANOVIA_MCP_ACCOUNT_LINK_ENABLED=false
CANOVIA_MCP_PLAN_CONSENT_ENABLED=false
# Existing server-managed issuer, resource, confidential account-link client,
# ChatGPT allowed client ID, introspection credentials and HMAC key also
# required; do not set guessed values in Render.
```

`plan_consent_enabled` is a **separate OFF-by-default switch**. It does not
activate `/api/mcp`, which remains an unauthenticated denial probe; nor does
setting it issue an external OAuth token. It is useless without verified
account-link configuration, valid resource/issuer, approved ChatGPT client
identifier, and a sufficiently strong HMAC key.

## Owner flow

- Personal **Development Work** displays a compact area identifying the
  current Plan, scope `overview|tasks` (default overview), and finite
  approval lifetime `1|7|30` days (default 7). Only the current primary
  owner of a noncollaborative Development Plan with an active linked IdP
  subject and explicitly enabled configuration may initiate OAuth.
- The POST is CSRF-protected/throttled. It validates Plan ownership and
  scope/lifetime **server-side**, then obtains exact current IdP metadata and
  starts a new state/PKCE S256 transaction for the *Canovia confidential*
  account-linking client; it does not accept a GitHub connection, cookie,
  user-entered subject/email or a ChatGPT bearer as proof of identity.
- The callback is the established, operator-pinned
  `/account/mcp/link/callback`. Its pending session includes a distinct
  `purpose=plan_consent`, the original authenticated actor, Plan ID, scope
  and finite days. It consumes state before a single code exchange, verifies
  RFC9207 issuer, introspects the result and checks exact
  `issuer,sub,client,audience,read-scope,exp`. It must match the **already
  linked** subject fingerprint and current personal Development ownership.
  A consent callback must **not** become a subject-link callback, and vice
  versa.
- Only a 5-minute authenticated account session can show a final consent
  card. It displays Plan name, ChatGPT-approved client, `overview` or
  `tasks`, 1/7/30-day lifetime, and a warning that full Task descriptions,
  logs, evidence and secrets are not shared. Another explicit CSRF-protected
  POST confirms, while a separate cancel POST discards pending evidence.
- The confirm consumes the session proof on *every* attempt. A transaction
  locks the current actor and Plan, verifies current ownership/
  Development/noncollaborative status, linked subject (not revoked) and
  current server-approved ChatGPT client/resource, and only then creates or
  renews a unique Plan-specific grant. Re-consenting to a narrower scope
  replaces the old scope, never accumulates privileges. All approval and
  renewal transitions are appended as metadata-only `grant_consented` or
  `grant_reconsented` events (actor/link/grant IDs, allowlisted scope,
  timestamp). No token or external identity string is stored.
- Existing revocation and unlink operations remain immediately effective.
  A verified-but-not-confirmed session **cannot** override a Plan transfer,
  later collaboration, expiry or a newly revoked identity link.
- Client/browser displays are always rechecked for current ownership before
  rendering the Plan name; transferring a Plan must not disclose its new
  owner's content in the account confirmation.

## Invariants

- A `prepared` row, even if `scope=tasks`, cannot activate a grant.
- A `linked` row without fresh same-subject OAuth verification cannot
  activate a grant.
- Only actual final confirmation creates/renews a grant; denied/expired/
  replayed/cancelled callbacks do not.
- The allowed `client_id` in the saved grant identifies ChatGPT, not the
  separate confidential account-linking client used to verify the actor.
- No team/guest/study/creative Plan is consentable, even when an old category
  label or collaboration permission exists. Each grant is tied to one Plan,
  one client+resource, one linked actor, one explicit scope and expiry.
- No grant operation changes existing Plan/Task progress or external GitHub
  state. The MCP route still rejects everything: implementation of a read-only
  MCP JSON-RPC handler is a **future independently reviewed PR**.
- Tests use mocked IdP responses and isolated database entries; no real
  Auth0 or other IdP was connected. Production switches remain OFF and device
  tests are deferred at the product owner's request.

## Remaining activation gates

1. Select and configure a real compatible OAuth 2.1 IdP (CIMD/DCR,
   PKCE S256, exact RFC9207 issuer, OAuth metadata, `resource` audience,
   compatible RFC7662 introspection or maintained JWKS alternative).
2. Register client identity and exact callback URIs; validate independent
   Canovia client subject link and IdP ChatGPT client login end to end in
   staging, including cross-device flow.
3. Review the true MCP resource server read-only tool. Verify the bearer on
   **every** call and enforce current linked owner, active grant, scope and
   expiry before the bounded Context projector returns any private data.
4. Security review rate limits, consent history, revoked/relinked sessions,
   callback replay, concurrency and provider outages before enabling any
   production switch.

Sources:
- https://developers.openai.com/plugins/build/auth
- https://www.rfc-editor.org/rfc/rfc7636
- https://www.rfc-editor.org/rfc/rfc9207
- https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization

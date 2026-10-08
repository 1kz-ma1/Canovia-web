# MCP delegated consent revocation & audit — session-only

Updated: 2026-10-09
Status: **Implemented revocation path, NO live OAuth or MCP private read**.

## This release

The account screen gains a separate `外部AIの共有許可・紐付けの解除`
section. It lists the logged-in user's **own** still-active grant records
(including expired grants, which can be revoked) and own still-linked
external identity records. These tables start empty in production. They
can only be populated in isolated tests until an independently reviewed
dual-auth linking flow and formal OAuth consent flow exist.

Two session-protected and Laravel-CSRF-protected DELETE actions exist:
- `DELETE /account/mcp/grants/{grant}`: revoke the selected original
  owner's Plan grant only.
- `DELETE /account/mcp/subjects/{subject}`: unlink the external subject and
  atomically revoke **all** Plan grants associated with it, including
  grants for a Plan since transferred, recategorized or made collaborative.

Both endpoints are throttled and verify that the current **Canovia session
user owns the original grant or linked subject** using actor-scoped DB queries
before writing; another user receives 404. No knowledge of a Plan owner token
or old collaborator membership permits cancellation of somebody else's
record. The record's original grant owner may revoke after Plan transfer,
but the account UI must not expose its current/new owner's Plan title.

Updates are DB transactions (with deadlock retries) and every actual state
transition emits metadata-only rows to `mcp_delegated_access_events`:
actor ID, linked subject ID, optional grant ID, event kind, allowlisted scope,
server timestamp. No raw OAuth tokens, user email, issuer subject, client
identifiers, fingerprint contents, Plan titles, Task descriptions, cookies,
secret keys or Context payloads are stored in the audit. Repeat revocations
are idempotent: no additional events, no reactivation and no new access
grants. Revocation is one-way until a separately reviewed future OAuth
consent process; there is deliberately **no** consent approval or link
creation endpoint in this slice.

The source table is append-only through the application service: it supports
only INSERT on revocation, with no update/delete API. Account deletion may
delete its associated rows to fulfill data erasure. Migration retries must
preserve previously recorded events; MySQL partial table creation must not
cause a destructive re-create.

## Security implications

The existing `McpDelegatedPlanAccessPolicy` already rejects a
`revoked_at` timestamp or a `status=revoked` grant/link on every query.
Unlink therefore terminates simulated future eligibility immediately.
No new grants, bearer tokens, ChatGPT client registrations or background
tool calls are created.

**The actual `/api/mcp` route remains an always-denied probe**, even if
the account screen displays synthetic link/grant rows or IdP verification
is configured. `CANOVIA_MCP_DISCOVERY_ENABLED`,
`CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED`, and
`CANOVIA_MCP_DELEGATED_POLICY_ENABLED` stay OFF in production.

Tests insert artificial grant/link records only in an isolated DB to
verify cross-account 404, guest redirects, Plan-transfer privacy, specific
grant-only revoke, subject-wide cascading revoke, current policy rejection
after revocation, idempotence, non-disclosure, and safe migration replay.

## Next before ChatGPT is a working connector

OpenAI recommends an existing OAuth identity provider; **Auth0 is a
candidate, not yet selected**. A real integration requires confirming
supported CIMD or DCR, authorization-code + PKCE S256, resource indicators
and access token audience/issuer validation, exact registered callback
URLs, and the selected IdP's introspection or vetted JWKS support.

Do not build an OAuth callback until the IdP's verified identity model is
known. The future link/consent flow must authenticate the Canovia actor
**and** the verified IdP identity, show the requested provider/client,
current personal Development Plan and scope, record the owner's explicit
decision, support expiry and revocation, and never infer an account from
the IdP email. Only then can the read-only MCP handler consume verified
tokens, identity links and current grants.

Real-device testing (iOS WKWebView, PWA, desktop) remains a separate later
batch per the owner's explicit instruction.

Sources:
- https://developers.openai.com/plugins/build/auth
- https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization

## Relink after revocation

After a user explicitly unlinks, they can only reconnect the same IdP
subject through a fresh, short-lived, verified OAuth authorization-code +
PKCE handshake and a separate final confirmation. Any previously revoked
Plan grants remain revoked; relinking never automatically approves them.
See [account linking](MCP_OAUTH_ACCOUNT_LINK_CONTRACT.md).

## Revocation vs fresh consent

A formerly revoked grant can be reapproved only through a new, valid
PKCE IdP subject verification and explicit Plan-specific consent. Relinking
an external subject alone never restores its previous grants. A Plan transfer
or collaboration change between verification and final approval must block
grant activation. See [explicit Plan consent](MCP_EXPLICIT_PLAN_CONSENT_CONTRACT.md).

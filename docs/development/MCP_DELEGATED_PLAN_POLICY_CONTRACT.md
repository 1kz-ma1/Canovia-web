# MCP delegated Plan authorization policy — inactive foundation

Updated: 2026-10-09
Status: **Internal policy and schema only. Not a real OAuth connection or live MCP tool.**

## Purpose

Validating a token against an OAuth authorization server proves an **external**
identity, not that the individual owns a Canovia account or has consented to
share a specific private Plan. A persisted `development_ai_sharing_preferences`
row marked `prepared` also does not constitute consent.

This slice introduces two **initially empty** tables and a default-off,
read-only authorization policy to prepare the next validated integration
stage. No public link/grant creation endpoint, consent UI, OAuth redirect,
callback, token exchange, JSON-RPC handler or private Context read is
introduced. `/api/mcp` remains deny-all.

### Persisted entities — deliberately without provider secrets

`mcp_linked_subjects` holds a unique, HMAC-SHA256 `(issuer, sub)` identity
fingerprint and the currently bound Canovia `user_id`, status, link and
revocation timestamps. Only a **future dual-auth identity linking flow** may
create an actual `linked` row after independently verifying the current
Canovia session and the IdP subject. Provider e-mail, user-entered IDs,
name, raw `sub`, bearer/refresh tokens and passwords are not persisted.

`mcp_delegated_grants` holds the verified subject-link ID, Canovia actor,
selected Plan, HMAC-SHA256 `(client_id, resource)`, explicit private Context
scope (`overview` or `tasks`), consent timestamp, expiration, status and
revocation timestamp. The default status is **revoked**. No runtime code
currently issues or activates grants. A future OAuth consent UI must
record a new actor-confirmed approval event and allow immediate revocation
before active grants can exist.

Fingerprints use a dedicated randomly generated high-entropy
`CANOVIA_MCP_IDENTITY_FINGERPRINT_KEY`, **not** `APP_KEY` nor any user
provided value. Key rotation invalidates old fingerprints (fail-closed);
plan a verified migration/relink instead of silently changing the key.

### Default-off internal policy

`McpDelegatedPlanAccessPolicy::allows` is an **internal testable policy, not a
resource-server authorization middleware**. It returns `false` unless
all of the following hold:

1. Server-owned protected-resource discovery, token introspection and
   delegated policy switches are explicitly enabled; an exact configured
   issuer, canonical audience, permitted OAuth client ID, future access-token
   expiration (<=3600 seconds) and read scope match.
2. The external immutable `(issuer,sub)` has a uniquely linked,
   nonrevoked subject record, and both fingerprint keys are available.
3. The selected Plan still belongs to that exact Canovia user, is NOT
   collaborative, and remains Development-classified. A previous owner,
   guest, team member or an updated shared Plan is never sufficient.
4. A **separate** still-active, unrevoked, unexpired grant exists for the
   exact subject-link, actor, Plan and `(client,resource)`; it has a
   positive consent timestamp not in the future.
5. The requested scope is either `overview` or `tasks`. `tasks`
   requires an explicit `tasks` grant. No mutation or bulk Task context
   export can result from this policy itself.

No Task, Plan, Evidence, WorkLog or GitHub data is queried or updated by
the policy. It makes no HTTP requests, logs no token and returns only a
Boolean, not Context. The only code permitted to call it in the future
must first obtain `McpVerifiedTokenPrincipal` from the vetted IdP
verifier — never construct one from request JSON.

### Safety locks / production config

```env
CANOVIA_MCP_DISCOVERY_ENABLED=false
CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED=false
CANOVIA_MCP_DELEGATED_POLICY_ENABLED=false
CANOVIA_MCP_IDENTITY_FINGERPRINT_KEY=
```

Even if an administrator manually enables all three switches, **no MCP
endpoint invokes this policy**: `/api/mcp` still always returns 401
(discovery enabled) or 404 (disabled). Existing prepared sharing preferences
do not create any subject link or grant. Do not enable or configure any of
these settings on Render merely because this PR is merged.

### Still required before genuine ChatGPT direct access

1. Choose and configure a real OAuth 2.1 IdP supporting CIMD/DCR,
   Authorization Code + PKCE S256, exact resource indicators and supported
   token verification. Verify callback and issuer metadata end-to-end.
2. Implement dual-identity linking with one-time state/CSRF protections and
   immutable issuer/subject proof; never infer Canovia user identity from
   provider e-mail.
3. Implement an explicit OAuth in-flow consent screen presenting the client,
   Plan, scope and lifetime; record append-only approvals/revocations with
   actor validation, no raw tokens. Respect changed plan permissions.
4. Review a new MCP request handler that calls the IdP verifier on **every
   request**, resolves the link, evaluates current grant + Plan permission
   and only then exposes bounded, read-only Context. Test across concurrent
   actors and external malicious data.
5. Add rate/AI provider cost bounds, audit without Context bodies, load tests,
   rollback and targeted staging verification before any production enablement.

### Test evidence

`tests/Feature/McpDelegatedPlanAccessPolicyTest.php` simulates future
link/consent rows **inside isolated test DBs only**. It asserts no access
with only a `prepared` preference, no access by default even if simulated
link/grant rows exist, overview/task scope isolation, revocation, expiry,
ownership transfer, collaboration, Study classification, changed
issuer/client/audience/subject/scope, malformed or rotated HMAC key, plus a
unique external subject mapping. A migration-retry-safe table setup and
existing deny-all MCP probe tests remain required.

## Session-only cancellation and audit (2026-10-09)

The account page now lets the original Canovia actor withdraw a simulated/future
specific grant or unlink an entire external subject, atomically revoking its
grants. No user registration, OAuth callback, consent approval or direct MCP
read route has been introduced. An append-only metadata audit records actual
transitions. Linked subjects and grants are not active in production.
See [revocation contract](MCP_DELEGATED_REVOCATION_CONTRACT.md).

## Browser actor → IdP subject verification (2026-10-09)

A separate **disabled by default** confidential Canovia OAuth account-linking
client can verify the currently signed-in Canovia owner and a stable IdP
`(issuer,sub)` through a session-bound OAuth authorization-code + PKCE S256
flow. It checks provider discovery, the RFC 9207 callback `iss`, and
RFC 7662 access-token introspection before offering the owner a separate
confirmation action. Only the identity HMAC fingerprint is stored; **no
Plan sharing grant is created**. The ChatGPT OAuth client is separate.
See [OAuth linking contract](MCP_OAUTH_ACCOUNT_LINK_CONTRACT.md).

## Explicit, verified Plan sharing approval (2026-10-09)

The OFF-by-default, session-authenticated Plan approval flow uses fresh
Authorization Code + PKCE verification for the *already linked* subject
and requires separate final owner confirmation before the internal
`mcp_delegated_grants` row is activated. No MCP read endpoint uses
these grants yet. See [explicit Plan consent](MCP_EXPLICIT_PLAN_CONSENT_CONTRACT.md).

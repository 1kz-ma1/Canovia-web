# Canovia / ChatGPT MCP — OAuth client registration acceptance

Date: 2026-10-09
Stage: My Workspace / `canovia-mcp-staging` (still **Web/OAuth/MCP OFF**).

## Current reality and distinction

A registered OAuth client that imitates an AI host in Keycloak's
GitHub Actions test is **not ChatGPT**. Real ChatGPT requires an actual
plugin connection and the exact callback/metadata URI the ChatGPT
interface supplies. No real Canovia Plan or person is to be used
until synthetic account link, consent and tool read pass review.

The current official OpenAI plugin/MCP authentication reference supports
three registration paths:

1. **CIMD** (Client ID Metadata Document): preferred when the authorization
   server supports it. ChatGPT advertises a stable
   `https://chatgpt.com/oauth/client.json` client ID metadata document
   when the IdP advertises RFC 9207 issuer identification. ChatGPT's
   published authentication methods include `none` and
   `private_key_jwt`. Keycloak's CIMD feature is **experimental**.
2. **DCR** (RFC 7591): if configured for DCR, ChatGPT calls the
   `registration_endpoint` once per connection and reuses the
   client ID. A DCR endpoint being advertised is not proof that
   anonymous, unauthenticated registration is authorized or safe.
3. **Pre-registered client**: possible when the real ChatGPT callback
   and client details are explicitly available to the operator.

Official sources:
- https://developers.openai.com/plugins/build/auth
- https://www.keycloak.org/securing-apps/mcp-authz-server
- https://www.keycloak.org/securing-apps/client-registration

## Disposable DCR compatibility trial (authenticated registration)

Initial real GitHub CI returned **HTTP 403** from Keycloak 26.8.0
for anonymous public-client DCR. This is the expected secure closed default,
but is **not compatible with ChatGPT auto-registration through anonymous DCR**.
A registration endpoint in the metadata does not mean unauthenticated
registration is permitted.

The CI now requires anonymous registration to remain denied; then a
separately authenticated disposable administrator creates an Initial
Access Token usable for exactly **one client** and at most two minutes.
A second registration request presents that token to prove the
OIDC/DCR protocol itself works without making the issuer open to
anonymous registrations. ChatGPT cannot supply this operator-generated
initial access token: **even if that second test passes, use CIMD or
pre-registration for a real ChatGPT connection**, or separately review
a strictly restricted anonymous DCR registration policy.

The GitHub workflow `.github/workflows/mcp-keycloak-disposable-oauth-lab.yml`
now runs `scripts/ci/keycloak_chatgpt_dcr_smoke.py` against a
**third disposable Keycloak 26.8.0** instance on loopback. This is
separate from the previously green synthetic user PKCE and RFC 7662
tests. The script first rejects unauthenticated registration, then uses a **one-use initial access token** for a public-client DCR request with
`token_endpoint_auth_method=none`, exact callback under `.invalid`,
and only the consented development read scope; then attempts a
real login, S256 code exchange, and exact MCP resource/introspection.
All cookies, codes, passwords, tokens and subjects remain within
CI memory and the ephemeral realm is discarded.

A failed experiment must **not** cause enabling unrestricted anonymous
registration or disabling token audience checks on any hosted issuer.
Record the error as a provider configuration gap, and use a secured
CIMD/pre-registered path or narrowly controlled DCR under separate
approval. Any DCR deployment requires explicit registration policy
on allowed origins, trusted hosts, client scopes, redirect allowlist,
quota/abuse prevention and removal/revocation. Never silently enable
open-to-the-internet anonymous client registration.

Acceptance checklist for an actual hosted connection:

- [ ] Provider is independent, durable, HTTPS-only and cost-approved.
- [ ] Provider metadata exposes issuer, registration approach and PKCE S256.
- [ ] If CIMD is chosen, experimentally validate Keycloak 26.8's
      `cimd` trusted-domains and resource-indicator client policy,
      client assertion method intersection, stable ChatGPT client ID
      and no untrusted URL fetches.
- [ ] If DCR is chosen, restrict abuse and prove the actual registration
      response, client type, allowed callback and read-only audience.
      Do not assume anonymous DCR is available by default.
- [ ] Exact ChatGPT callback is copied from the plugin management
      screen; never infer it from this synthetic test.
- [ ] Two-provider-client flow includes separate Canovia account-link
      RP and confidential resource-server verifier whose client ID is
      exactly the MCP URL.
- [ ] The **real Canovia staging owner** deliberately confirms
      subject association and a **single Plan** grant. Linking alone
      never grants access.
- [ ] An authentic ChatGPT bearer reaches the read-only tool with
      matching immutable `iss+sub`, exact `aud`, scope and caller
      identity; the same token fails immediately after consent revoke.
- [ ] All stage/production controls, subscription upgrade, backup,
      rollback and privacy review signed off separately.

**Boundary:** no Render service or database has been added,
no flags switched, no real ChatGPT connection established and no
billing contract changed by the CI experiment.

## Real Keycloak 26.8.0 DCR security baseline (CI, 2026-10-09)

Repeated disposable CI runs identified the following **real responses**
without ever weakening provider policies:

- Keycloak OIDC discovery advertises `registration_endpoint`.
- Anonymous OIDC Dynamic Client Registration returns **403** under
  default anonymous registration policy.
- With a *single-use, 120-second Initial Access Token*, attempting to
  include the extra `canovia.development.read` client scope at
  registration returns **403** (`insufficient_scope` under Client
  Scope Policy). This is an appropriate least-privilege protection.
- Omitting that unapproved client-scope request lets the **public**
  test client register through the real Keycloak OIDC DCR endpoint,
  with `token_endpoint_auth_method=none`, generated `client_id`
  and the selected callback.
- The newly registered client is **not allowed to use**
  `canovia.development.read` during authorization by default; the
  OAuth redirect returns **`invalid_scope`** before user login.
  Registration success is emphatically **not** permission to read
  a user's MCP Plan.

The regression now accepts **only** the safe default `invalid_scope`
denial or a full correctly-validated user PKCE + audience/subject/scope
grant if a future Keycloak version changes the defaults. All unexpected
errors must fail CI, and **neither result** should be interpreted as
authorization to expose unrestricted anonymous DCR publicly.

### Selected direction for the live ChatGPT connection

Keep **pre-registration** as the low-surprise initial fallback if ChatGPT's
exact callback and chosen client registration mode support it. Otherwise,
prioritize an explicitly reviewed **CIMD** design using ChatGPT's real
`https://chatgpt.com/oauth/client.json`, `none` or
`private_key_jwt` and Keycloak's separately pinned experimental CIMD
client policy. Its trusted-domains, JWKS, allowed resources and redirect
validation must be tested before hosting or activation.

The **default Keycloak DCR configuration is not plug-and-play with
ChatGPT**. A DCR-specific rollout would require a separate opt-in,
client registration policies, read-scope allowlisting and exact
resource-audience policy; this has not been authorized.

Next true blocker is an externally reachable, production-mode,
persistent HTTPS IdP with acceptable capacity/database and explicit
owner signoff. Until then the staging Web remains non-public and
OAuth/consent/MCP switches remain OFF.

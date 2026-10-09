# Canovia MCP / Keycloak: disposable OAuth protocol lab (2026-10-09)

## Goal

Before hosting an external IdP or opening the existing MCP staging Web,
prove on an ephemeral, local-only Keycloak **26.8.0** instance whether the
OAuth protocols required by Canovia can be satisfied **without changing or
loosening Canovia's strict token validation**.

The source of truth is the linked code and the CI result, **not** an inference
from Keycloak docs. This file documents the experiment, not authorization
to expose accounts or MCP resources.

- Workflow: `.github/workflows/mcp-keycloak-disposable-oauth-lab.yml`
- Probe: `scripts/ci/keycloak_mcp_protocol_smoke.py`
- Keycloak image: `quay.io/keycloak/keycloak:26.8.0`, version pinned; NOT `latest`
- Flags: `--features=resource-indicators,cimd`, both experimental
- Source: https://www.keycloak.org/securing-apps/mcp-authz-server
- Container runtime guidance: https://www.keycloak.org/server/containers

## Isolation and costs

The test runs solely inside the GitHub Actions runner, with Keycloak bound
to `127.0.0.1:18080`, a disposable H2 realm, and a **2 GiB memory cap**.
CI creates random short-lived administrator and OAuth client secrets
inside the runner, never in source control. It creates **no user accounts**,
no Canovia database connections, no real ChatGPT credentials and no
persistent service. It deletes its container and test-realm file on exit.

Keycloak `start-dev` is for this **localhost-only throwaway job**. Do not
copy it into a network-reachable Render Web service or use it as a
production deployment recipe. It has insecure development defaults.

The service names/URLs used in the test identify **no reachable connection
to Canovia data**; the MCP resource URI is an inert audience string:
`https://canovia-mcp-staging.onrender.com/api/mcp`.
The production service, Aiven MySQL `pacekeeper`, Render staging settings,
existing synthetic Plan, flags and billing are untouched. Review hosted
IdP pricing and any required paid upgrade only when necessary and with
explicit owner approval.

## Required acceptance signals

With a runtime-imported synthetic realm and two separate registered
clients (resource and confidential dummy caller), test:

1. RFC 8414/OIDC issuer, token, authorization and introspection endpoints,
   advertised PKCE S256 and callback `iss` capability.
2. The **actual** confidential test client's RFC 8707 `resource`
   request issues a bearer access token. The RFC 7662 response must have
   `active=true`, immutable non-empty `sub`, exact `iss`,
   `client_id`, **one exact resource URI** in `aud`,
   the literal `canovia.development.read` scope and expiry <= 1 hour.
3. A token request specifying an unregistered, wrong resource must be
   rejected with `invalid_target`.
4. Introspection of a fabricated invalid bearer must yield `active=false`.
5. Only named `pass` / `blocked` codes may reach CI logs — never
   raw tokens, secrets, claims, IDs or account information.

**A completed green job is evidence for these specific machine-level
checks only.** A failing job documents a real provider/configuration
gap; do not weaken Canovia verifier's single-resource `aud` check to
make it green.

### Explicitly out of scope

This first protocol probe uses **client credentials / service-account**
tokens, *not a signed-in person*. It DOES NOT prove Authorization Code +
PKCE browser login, user consent, same `issuer+sub` across two clients,
ChatGPT registration or callback, dynamic client registration, resource
binding at both the authorization and token endpoint, token revocation,
account linking, or Canovia's private MCP Plan-read response. A successful
service token must never be mapped to a user or a private Plan.

Next: a separate disposable human-actor authorization-code/PKCE test
that proves both `resource` parameters match and validates a per-user
introspection response, followed by a separate hosted IdP decision. The
already deployed staging HTTP/OAuth/MCP gates must remain OFF until
those tests, owner approvals and signed-off consent controls are complete.

## Progress tracker

- [x] Experiment and safe, ephemeral CI implementation prepared
- [x] Real pinned Keycloak 26.8.0 protocol job passed with exact
      resource URL, strict RFC 7662 introspection and negative-resource
      checks (GitHub Actions run 37909449399, 2026-10-09 09:10 UTC).
      Re-run the updated PR's full CI before merging.
- [x] Disposable, **real synthetic human** Authorization Code + PKCE,
      callback state and issuer, explicit `sub` introspection mapper, exact
      resource audience and a second caller with the same immutable
      issuer+subject verified (CI run 37914942240 on 2026-10-09).
      This is **not** actual ChatGPT or Canovia account consent.
- [ ] Same-actor separate client registration, explicit Plan consent and
      cross-actor revocation tested
- [ ] Hosted IdP capacity / budget approved, if necessary
- [ ] Real ChatGPT MCP read-only connection verified (not yet connected)


## Concrete Keycloak 26.8.0 result / necessary OAuth client identity

The first real test identified a **security-significant incompatibility**:
since Keycloak 26.6.2, RFC 7662 introspection returns `active=false`
unless the authenticated introspection client is itself a member of the
access token's `aud` claim. This is a Keycloak security safeguard,
not a reason to relax Canovia's single exact resource audience contract.

Official upgrading guide:
https://www.keycloak.org/docs/26.8.0/upgrading/

A strict configuration **does work**, without Keycloak's deprecated
`allow-token-introspection-without-audience-check` compatibility switch:

1. Register the confidential **MCP resource-server client** with
   **client ID equal to** `https://canovia-mcp-staging.onrender.com/api/mcp`
   and Keycloak client attribute `resource_url` equal to that exact URI.
2. Keep a different **OAuth caller/ChatGPT client ID**. Give the caller an
   audience mapper whose Included Client Audience is the *resource-server
   client*; request exact `resource` and the allowed read scope.
3. Let the Canovia token verifier authenticate to the introspection
   endpoint using its resource-server client ID and its own confidential
   secret. Introspecting as the caller, with a token intended only for
   the resource, is correctly denied by modern Keycloak.
4. Because the resource-server client ID contains `:` and `/`, encode
   each client credential using RFC 6749 §2.3.1 form encoding **before**
   assembling HTTP Basic. Plain raw Basic incorrectly interprets the
   client ID as `https`. Canovia's `McpAccessTokenIntrospector` and a
   regression test now implement this exact encoding, without changing
   audience, scope or expiry validation. The change remains feature-
   gated OFF in production.
5. Never place the confidential resource verifier's credential in a
   public ChatGPT client, browser or log.

Actual protocol lab result (run `37909449399`):
`oauth_metadata_issuer_pkce_introspection: pass`;
`real_token_rfc8707_audience_and_rfc7662_introspection: pass`;
`foreign_resource_invalid_target: pass`;
`introspection_invalid_token: pass`.
No real token, client secret or user information was printed. The
workflow sets `production_authorized=false` and
`chatgpt_oauth_end_to_end=not_tested`.

**Do not overclaim:** this validates Keycloak service-account token
and protocol compatibility, plus separately faked PHP HTTP header tests.
It is not a live signed-in end-user grant and does not prove Canovia's
separate ChatGPT/actor identity-link lifecycle. Keycloak's resource-
indicators and CIMD remain experimental; pin versions and repeat the
full browser OAuth/PKCE and revocation suite before any public exposure.


## Second acceptance slice: real synthetic human Authorization Code + PKCE

The follow-up disposable Keycloak CI script
`scripts/ci/keycloak_user_pkce_smoke.py` imports the same pinned Keycloak
version and creates **one fictitious human account** plus two distinct
OAuth RP clients for testing. This user exists only inside the throwaway
realm on the CI runner. It never exists in Canovia (production OR stage).

A standards-based OAuth browser simulation makes a GET to the real
authorization endpoint with unique `state`, `nonce`, S256 challenge,
exact protected `resource` and the read-only scope; it logs in with
the synthetic account through the actual Keycloak HTML login form.
The handler **never follows any redirect outside loopback**: its
registered redirect destinations are deliberately invalid hosts and
are captured without network requests or leakage of code/state.

Expected assertions:

1. Authorization callback carries the exact request `state`, expected
   `iss` and a one-time authorization code (no error).
2. A request with the wrong PKCE verifier cannot exchange the code.
3. A fresh authorization with correct verifier succeeds with a
   short-lived token specifically for the one MCP resource URL.
4. Keycloak RFC 7662 introspection uses the *confidential resource
   verifier client* (whose ID equals the exact resource URL); the
   access token's `aud` contains **only** that URI and has the
   agreed read-only scope, original RP `client_id`, issuer and subject.
5. A second independently registered test RP performs its own login
   for the same fictitious person. Both tokens have exactly the same
   immutable `iss` + `sub` with **different client IDs**.
   The second RP is **NOT actual ChatGPT**: do not treat its token as
   a ChatGPT OAuth approval.
6. A fresh auth code bound to the true resource cannot be exchanged
   by substituting a different resource at the token endpoint
   (the provider must reject it with `invalid_target`).
7. Logs never print codes, real/fictional passwords, tokens or the
   internal subject. All CI credentials are short-lived and random.

This **does not create consent** in Canovia, issue a real ChatGPT
credential or open any hosted endpoint. Keycloak's test RPs do not ask
for an interactive consent screen; Canovia per-Plan sharing consent and
revocation are separate, still-unmet acceptance criteria. The browser
simulation is not a WebView/mobile acceptance test.

The new workflow step must be green on the **current branch SHA**
before this acceptance is claimed. No Render environment flags, Aiven
production records, staged fixture records or hosted Keycloak billing
are changed.

Pending after this slice: synthetic Canovia account-link credential
rotation, hosted IdP feasibility, staging HTTPS/oauth reachability
review, explicit per-Plan consent, genuine ChatGPT client registration,
cross-user denial, production readiness and rollback.


### Actual CI results and Keycloak user-mapper requirement (2026-10-09)

[GitHub Actions run 37914942240](https://github.com/1kz-ma1/Canovia-web/actions/runs/37914942240)
passed both the original client-credentials protocol test and the separate
browser-user PKCE test. The latter emitted only the following safe statuses:
`synthetic_keycloak_user_realm_ready: pass`,
`authorization_code_browser_login_and_rfc9207: pass`,
`wrong_pkce_verifier_rejected: pass`,
`user_link_client_resource_bound_introspection: pass`,
`same_user_separate_client_issuer_subject: pass`,
`resource_changed_between_auth_and_token_denied: pass`.
No real users, actual ChatGPT credentials, Canovia Plan content or
production/staging database access participated.

Keycloak 26.8.0 did **not** include the immutable `sub` in the
human-user introspection payload by default, even though the caller token
was valid and carried `username`. The correct fix for this throwaway
realm was an explicit `oidc-sub-mapper` with
`access.token.claim=true` and `introspection.token.claim=true`
on **both** RP clients. Do not use mutable username or email as an
identity key, and never loosen Canovia's immutable-subject guard.

The browser simulator also needs a trusted-loopback-only cookie policy to
accept local Keycloak Secure cookies under HTTP; it **never follows off-host
redirects**, instead intercepting test callbacks under invalid domains.
This exception is confined to disposable CI. A hosted IdP must use HTTPS
and normal browser cookie restrictions.

**Next gate remains**: external OAuth hosting and real client
registration/PKCE callback, user-controlled synthetic credential,
Canovia same-subject account link and explicit per-Plan consent,
revocation and negative cross-user tests, then actual ChatGPT MCP
read-only invocation. No paid service or staging public access
was enabled by these tests.

## Full synthetic Canovia authorization lifecycle (2026-10-09 follow-on)

The initial disposable Keycloak 26.8.0 GitHub Action has **already**
independently validated a real human's authorization-code PKCE flow
and immutable `iss+sub` stable across two registered clients. Its
provider is not hosted and its identities are not available to Render.

The newly added **Canovia application-side** integration test
`tests/Feature/McpSyntheticOAuthConsentReadLifecycleTest.php` exercises
the real session routes, database and JSON-RPC tool **with simulated
HTTPS IdP responses** following those verified claim relationships:

1. A valid separate AI-client bearer before account linking cannot read.
2. The authenticated Canovia owner starts an OAuth link; callback verified
   by exact issuer and state does **not** create a linked subject until a
   second user confirmation; linkage alone does **not** create a Plan grant.
3. A fresh independent PKCE OAuth ceremony for exactly one personal
   Development Plan is needed. A verified callback still cannot grant
   anything until the owner explicitly confirms scope (`tasks`) and expiry.
4. Only after that confirmation does the separate AI-client bearer
   retrieve bounded Task fields through `/api/mcp`; Task descriptions,
   owner tokens and unapproved/other-user Plans remain inaccessible.
5. Revocation immediately denies the exact same previously valid bearer.
   Replaying the consumed confirmation cannot reinstate consent, and a
   second Canovia user cannot unlink the owner's identity.

**Critical boundary:** successful test results prove only app
integration against a **mocked** IdP; they are not a live network link
between the actual Keycloak user and the existing Render PostgreSQL
synthetic Canovia user. No new bypass CLI, auto-consent endpoint,
production MCP flag, real ChatGPT client, provider hosting or paid
contract is introduced. Full hosted IdP + callback + authenticated
session + user-driven approval + ChatGPT read remains outstanding.

The status of this test is determined by the specific GitHub PR CI,
not by the implementation of the test alone.

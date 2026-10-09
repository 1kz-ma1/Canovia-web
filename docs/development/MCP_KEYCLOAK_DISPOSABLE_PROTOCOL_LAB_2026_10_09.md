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
- [ ] PR CI success for **real** pinned Keycloak 26.8.0; report resulting
      named protocol diagnostics and remediate any provider mismatch
- [ ] User-bound Authorization Code + PKCE, callback issuer, resource
      consistency and introspection verified
- [ ] Same-actor separate client registration, explicit Plan consent and
      cross-actor revocation tested
- [ ] Hosted IdP capacity / budget approved, if necessary
- [ ] Real ChatGPT MCP read-only connection verified (not yet connected)

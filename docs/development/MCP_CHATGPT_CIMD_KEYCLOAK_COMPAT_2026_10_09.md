# ChatGPT CIMD × Keycloak 26.8.0 — real compatibility gate

Status: **experimental / not accepted for hosted use**, 2026-10-09.
Owner lane: **Platform**, repository `1kz-ma1/Canovia-web`.

## Why this slice exists

The merged disposable Keycloak tests already prove machine RFC 8707 / RFC 7662
(#430), human OAuth Code + PKCE (#431), second-human separation and revocation
(#434), and locked-down DCR (#435). None registers ChatGPT itself.

OpenAI currently prefers **Client ID Metadata Documents (CIMD)**, using the
stable client ID `https://chatgpt.com/oauth/client.json` where RFC 9207
supports the stable callback. The OpenAI authentication documentation says
ChatGPT now advertises plural `token_endpoint_auth_methods_supported` and
also publishes a transitional singular `token_endpoint_auth_method`
preference. Keycloak's *current* MCP integration guide meanwhile explicitly
says ChatGPT's plural-only metadata is unsupported by Keycloak because its
built-in executor expects a singular field. **Do not infer that either guide
describes the exact live document on test day**: measure it.

Sources, recheck before enabling the connector:
- OpenAI: https://developers.openai.com/plugins/build/auth
- Keycloak: https://www.keycloak.org/securing-apps/mcp-authz-server
- Keycloak 26.8 client policy executor config:
  https://www.keycloak.org/docs-api/latest/javadocs/constant-values.html

## Implemented disposable verification

`scripts/ci/keycloak_chatgpt_cimd_smoke.py` is run as a separate step of
`.github/workflows/mcp-keycloak-disposable-oauth-lab.yml` on matching PRs.
It uses **the existing version-pinned 26.8.0 Docker image** and its own
localhost-only imported fictional realm. Random admin and fictional user
passwords live only for the CI run. The script:

1. Fetches exactly one public HTTPS URL for **ChatGPT's actual CIMD**
   (`https://chatgpt.com/oauth/client.json`) with redirects rejected, a
   5 KB upper bound, and no sensitive headers. Requires the stable client ID,
   expected stable redirect and both published token endpoint auth choices;
   does **not** print or persist its JSON.
2. Starts a 2 GiB-capped throwaway Keycloak with
   `--features=cimd,resource-indicators`. `start-dev` is strictly local.
3. Installs Keycloak's built-in `client-id-metadata-document` executor
   and `client-id-uri` condition by **localhost Admin REST**. Both are
   restricted to `https` and `chatgpt.com`. The executor disallows HTTP,
   requires same-domain callback/URI metadata, only confidential clients, and
   permits only exact `https://canovia-mcp-staging.onrender.com/api/mcp`
   as the RFC 8707 resource indicator.
4. Attempts the first real authorization request using ChatGPT's **public
   document URL as client_id**. Acceptance here means Keycloak served an
   actual browser-login form after applying metadata policy; **it is not a
   user login, token, delegated Plan consent or ChatGPT round trip**.
5. Ensures a foreign resource and an untrusted CIMD URL cannot reach login.
   Any unexpected compatibility result fails the CI gate; no negative-path
   bypass or Canovia verifier relaxation is permitted.
6. Discards the entire realm and container on exit. Only named result codes
   appear in CI, never response bodies, callbacks, tokens or subject IDs.

The test is intentionally **live-metadata-sensitive**: an upstream ChatGPT
metadata edit, network outage or unhandled Keycloak incompatibility blocks
the job. Such a failure is a recorded test finding, not permission to make
the identity server permissive. Docker's outbound public metadata fetch
remains limited by the Keycloak policy domain allowlist (not a network-wide
egress firewall); hardened hosted IdP requires separate network controls.

## Acceptance and fallback

- CI green on **exact final PR head**: compatible with *initial CIMD
  metadata resolution and browser authorization entry* **only**.
- CI red: capture sanitized named failure code and decide between (a)
  reviewing a newer pinned provider version, (b) an independently audited
  adapter or (c) a **pre-registered, exact-allowlist ChatGPT OAuth client**.
  Do **not** casually deploy a custom Keycloak extension to parse metadata
  or globally open anonymous DCR. One-use Initial Access Tokens are not a
  ChatGPT automatic registration mechanism.
- Not yet accepted in either outcome: real ChatGPT `private_key_jwt`
  signed assertion exchange, real per-human scopes, exact introspection
  `sub` / single `aud`, stable issuer in callbacks, Canovia actor
  linkage, explicit Plan-level grant, revoke-after-use, and device E2E.
  Validate with a disposable person and fictional Plan **before** opening
  any public staging surface.
- **No Render environment, service, plan, DB, private synthetic owner,
  domain, security flag, hosted issuer or billing change authorized here.**
  Existing Canovia production and physically isolated MCP staging remain
  closed. The next IdP hosting choice still needs a fresh 2 GiB compute +
  separate durable DB + HTTPS + backup / incident / rollback **price and
  authorization decision** by the owner. The Free staging Postgres expires
  on 2026-11-08 (UTC); do not use it to host Keycloak.

### Verification record

This implementation starts as **unverified**. Record the actual GitHub
Action run ID and its pass/block code only after observing the exact commit.
A PR merge is neither actual OAuth connection nor deployment acceptance.

## Observed actual CIMD result (2026-10-09)

GitHub Actions [run 37921631740](https://github.com/1kz-ma1/Canovia-web/actions/runs/37921631740) passed retrieval and structural validation of the live public ChatGPT CIMD and registration of strict Keycloak policy, but **failed initial OAuth authorization with HTTP 400**. The named failure was `client_metadata_fetch_failed`, and there was **no dynamically created CIMD client**. A follow-up run [37922019705](https://github.com/1kz-ma1/Canovia-web/actions/runs/37922019705) found redirect and client-metadata processing references in sanitized Keycloak logs, without proving one specific root cause. The existing RFC8707/7662, user PKCE, multiple user isolation/revocation and deny-by-default DCR checks passed.

**Current Keycloak 26.8.0 CIMD compatibility: BLOCKED/NOT ACCEPTED.** Do not attribute the error solely to the plural metadata field without further proof. Do not advertise working CIMD in a hosted IdP and do not loosen Canovia token checks.

### Opt-in static pre-registration alternative (CI verification still required)

The local disposable test now attempts an explicitly registered ChatGPT client **after** classifying the exact, previously observed CIMD rejection. It disables the experimental CIMD policy only in that ephemeral realm; registers the exact public client ID with `client-jwt` signed assertion authentication, verified same-origin public JWKS URL, the one stable redirect URI and S256 PKCE; and demands the correct resource audience mapper and immutable-subject mapper. It must admit only the registered client to browser authorization, and deny a foreign client ID or wrong resource. Public-key JWT assertion exchange itself **is not exercised** and cannot be declared ready.

Acceptance requires CI to distinguish `real_chatgpt_cimd: blocked_by_pinned_keycloak` from `real_chatgpt_exact_static_registration: pass` and still report `chatgpt_private_key_jwt_code_exchange: not_tested`. Even on a green CI, real ChatGPT token exchange, actor and Plan consent, revocation and host cost authorization remain outstanding. No hosted provider, Render resource, credentials or paid plan is created by this PR.

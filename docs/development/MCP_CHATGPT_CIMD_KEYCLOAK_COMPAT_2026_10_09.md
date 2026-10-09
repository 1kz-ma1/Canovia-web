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

### Root-cause evidence and remaining resource gate

**CIMD rejection cause narrowed by actual Keycloak logs:** [run 37922512647](https://github.com/1kz-ma1/Canovia-web/actions/runs/37922512647) classified an `UnrecognizedPropertyException` together with the production metadata field `token_endpoint_auth_methods_supported`. This supports a Keycloak 26.8.0 JSON metadata parser incompatibility for ChatGPT CIMD, matching the published Keycloak integration caveat. The public document was fetched successfully from the GH runner and the strict CIMD policy installed; the provider itself did not auto-create the client.

**Separate pre-registration proof:** The same run successfully created a strict static `client-jwt` OAuth client with the real ChatGPT client ID, verified public JWKS URL, single stable callback and PKCE setting; the resulting Keycloak authorization endpoint presented the login form. This proves only static client setup and authorization **entry**. It did not and cannot prove a real ChatGPT signed JWT assertion or a Canovia consented token without a real ChatGPT client connection.

**Important negative finding:** Keycloak still presented the login screen with an incorrect RFC8707 resource for that explicitly registered client. The release gate is therefore **not satisfied** at authorization entry. A hosted pilot MUST prove the wrong resource cannot produce an accepted issued token (and ensure Canovia's strict single-audience validator rejects it). This lab does not exchange a signed ChatGPT token, so its CI emits `static_wrong_resource_token_exchange: not_tested` and `static_wrong_resource_release_gate: blocked` rather than pretending the negative check passed. Separate synthetic machine / human PKCE tests continue to cover the provider's token-bound resource behavior for other test clients.

Never allow this compatibility CI alone to authorize a deployment, billing action, public registration, user consent or MCP Plan read. Keycloak 26.8.0 should be selected only with **static pre-registration as an explicit, independently reviewed alternative** and all hosted OAuth, token, signed-assertion and cost gates fulfilled.


## Additional acceptance slice: disposable *synthetic* private_key_jwt (2026-10-09)

The real ChatGPT client cannot sign a test assertion in this CI runner, and Keycloak 26.8.0 native CIMD remains **blocked**. To test Keycloak's actual **token endpoint** and the negative resource behavior independently of the unavailable real ChatGPT signing key, this branch adds \`scripts/ci/keycloak_chatgpt_static_jwt_smoke.py\` to the pinned disposable workflow.

This script uses a **separate, invented JWT-authenticated OAuth RP**, never the public ChatGPT client ID. An OpenSSL-generated disposable RSA private key is confined to the runner and **is not mounted into the Keycloak container**. Only its one-day, public self-signed certificate is embedded in the localhost-only temporary realm. The fake human logs in through the same real Authorization Code + S256 browser harness already used for the two-person subject isolation test, with callbacks intercepted at \`.invalid\`.

**Acceptance, per exact CI commit:**
1. Correct RS256-signed \`private_key_jwt\` + matching S256 verifier must exchange the fake user's code for a short-lived access token.
2. RFC 7662 introspection as the **distinct confidential MCP resource client** must return \`active=true\`, exact issuer, immutable \`sub\`, synthetic RP \`client_id\`, only the protected MCP URI in \`aud\`, read scope, bearer type and <=3600s expiry.
3. A token request substituting a foreign resource for a code authorized to the true resource must fail as \`invalid_target\` **without issuing a token**. A forged client assertion and incorrect PKCE verifier must fail independently.
4. RFC 7009 revocation using a new valid signed client assertion must make the formerly active token inactive.
5. Remove the entire synthetic realm, private keys and container; CI emits only fixed safe statuses and no secrets, tokens, codes, user subjects or HTML.

**Interpretation:** Even if this independent synthetic exchange passes, it **does not prove** actual ChatGPT signing, JWKS rollover/retrieval, real ChatGPT callback, production token validation, per-Plan consent, or a working Canovia MCP connection. Do not promote static pre-registration or native CIMD to release-ready on that basis alone. If it fails, the exact failed assertion is an actionable Keycloak/token-configuration gap and must remain fail closed.

### Production P0 dependency (hard merge gate)

[Issue #418](https://github.com/1kz-ma1/Canovia-web/issues/418) requires production MySQL migration integrity and PWA login restoration before new \`main\` merges, including docs/CI-only merges, because \`main\` can auto-deploy. **Keep PR #438 in Draft and do not merge** until the P0 owner confirms the gate resolved and the exact final SHA passes all CI. This disposable investigation does not change Render, Aiven, hosted IdP, paid plans or environment flags.

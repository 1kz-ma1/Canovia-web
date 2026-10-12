# Canovia ChatGPT MCP — Phase 1 Development Plan Read Acceptance

Date: 2026-10-11
Owner lane: Platform
Status: Implementation preparation only. **No real ChatGPT OAuth or MCP read acceptance yet.**
Decision: Phase 1 starts with the existing personal **Development** Plan and its two synthetic Tasks. Learning, Career and multi-domain discovery are explicitly deferred.

## Immutable operational boundary

- `main` before this slice: `15abdc9de6f24b5250fb255c579d1aa55facaf1a`; no direct changes to main.
- Production: Render `srv-dagfn2id0e5s73cac7ng` / Aiven MySQL; never use real user records, change production environment or deploy for the MCP pilot.
- Existing isolated stage: `srv-db43l4nlk1mc73emseig`; dedicated PostgreSQL 17 `dpg-db43rbbncjis73bmigi0-a` (Free, expiry 2026-11-08 01:04 UTC); synthetic Plan fixture previously persisted and verified, but **not** read via ChatGPT.
- Both services' Auto-Deploy remain OFF. All stage public Web/OAuth/MCP gates remain OFF. No stage redeploy, flags, public endpoints, IdP tenant, contract or billing change is authorized by this document.

## Narrow initial user experience

1. An authorized ChatGPT user connects the separately approved **synthetic** Canovia staging account to the IdP and approves **one** personal Development Plan.
2. ChatGPT calls existing `canovia_get_development_plan_context` with the exact owner-supplied `plan_id`; `overview` or explicitly approved `tasks` scope, `limit <= 8`.
3. Canovia independently verifies the token, immutable external subject-to-local actor link, current Plan ownership, category and specific active grant. Only bounded recorded metadata is returned.
4. Access by an unrelated user, mismatched client, unapproved Plan/scope, revoked grant, expired token, or disabled switch is denied. No write operation is exposed.

Phase 1 deliberately does **not** discover a Plan automatically, support Learning Plans, produce Mutation Candidates, or accept task mutations.

## Implemented in this preparation slice

- Add explicit per-tool `securitySchemes: [{type: "oauth2", scopes: ["canovia.development.read"]}]` to the existing read-only MCP tool descriptor.
- Test the exact declared scheme through the authenticated JSON-RPC `tools/list` response.
- Preserve all existing fail-closed bearer, OAuth metadata, exact audience/client/scope and Plan consent checks.
- This metadata alone does **not** initiate ChatGPT OAuth or prove a browser linking UI. Official guidance also requires appropriate tool-level runtime `_meta["mcp/www_authenticate"]` challenges when that auth interaction is needed. Evaluate the existing HTTP 401 resource challenge first, then separately design and test any additional tool-level challenge without leaking Plan existence or weakening transport authentication.

References:
- https://developers.openai.com/plugins/build/auth
- https://developers.openai.com/plugins/build/mcp-server
- https://auth0.com/docs/quickstart/backend/php
- https://auth0.com/docs/secure/tokens/access-tokens/get-access-tokens
- https://github.com/1kz-ma1/Canovia-web/pull/438

## Auth0 compatibility gate — NOT a provider decision

Auth0 is a **candidate**, not an active Canovia provider.

1. Confirm the intended tenant's actual Free/MCP/CIMD entitlements, tenant and client quotas, ongoing price, configured issuer, stable ChatGPT redirect, `iss` in authorization responses, S256 and `resource` support **before** provisioning or enabling anything. Approval is required before creating a tenant or changing billing/permissions.
2. Confirm that real tokens for the ChatGPT client and a separate Canovia account-link client carry a stable `iss+sub`, literal Development read scope, exact resource audience, expected client identity and a permitted lifetime.
3. Existing Canovia verification uses **RFC 7662 introspection**. Auth0 PHP API guidance uses **signed JWT/JWKS**; do not assume compatible introspection. Independently implement or select a maintained JWT/JWKS verifier on a separate feature branch only after confirming actual token claims and library API, with negative tests for algorithm confusion, invalid signatures, key rotation, issuer/audience/client/scope mismatch and expiry. Never parse unverified JWT claims for authorization.
4. Require single exact resource `aud` as in the current Canovia contract. Auth0 documents that requesting an API audience together with `openid` can add `/userinfo` as a second audience. Characterize actual tokens first; do not silently loosen Canovia's equality policy just to accept them.
5. Auth0 CIMD registration may map a public metadata URL to a **different internal client ID**. Record the actual issued identifier and prove it against verified tokens; do not equate the ChatGPT URL with a token claim by assumption.
6. Require both synthetic actor linking and fresh per-Plan consent. Verify positive and negative token/permission behavior **before** requesting stage public exposure.

## E2E acceptance (NOT MET)

- Exact isolated staging SHA confirmed and closed-stage fixture independently rechecked.
- Owner-approved IdP and tenant configuration, real ChatGPT client registration and Canovia link client; token verifier negative tests pass.
- Reviewed staging-only network/login/OAuth/MCP exposure with safe rollback.
- **Observed inside actual ChatGPT**: initialize, tools/list, valid tools/call returns the synthetic Plan title and both Task records; revoked access fails on subsequent real call.
- Document exact CI run, stage deploy and external ChatGPT E2E evidence separately. A mock token, test container or live Render health check cannot substitute.

No production release is authorized by successful staging acceptance.


## Auth0 public discovery characterization (2026-10-11)

Status: **synthetic/offline tests only**, not a hosted tenant acceptance and not ChatGPT E2E. The product owner approved proceeding with a **Free Auth0 evaluation**, with no authorization to create paid services or expose staging endpoints. There is no authenticated Auth0 tenant management connection in this development session; no tenant or client has been created.

The existing `McpStagingProviderPreflightService` validates Canovia's actual RFC 7662 introspection-based runtime configuration; it is not an Auth0/JWT verifier. Do not disable or weaken it to mark Auth0 ready. A separate **diagnostic-only** script in `scripts/ci/mcp_auth0_metadata_characterization.py` evaluates public OAuth discovery metadata without requiring private credentials, Canovia stage access, user accounts or bearer tokens. Its output consists only of named statuses, with `stage_mcp_activation_authorized=false` and `real_chatgpt_oauth_verified=false` for every outcome. Absence of advertised capabilities is reported, not silently filled in.

### Verification

```bash
# Local synthetic fixtures, no external network calls or tenant required.
python3 -m unittest discover -s tests/ci -p test_mcp_auth0_metadata_characterization.py -v

# After a Free tenant exists, only its *public* Auth0 domain is needed.
python3 scripts/ci/mcp_auth0_metadata_characterization.py \
  --tenant-domain YOUR-TENANT.us.auth0.com
```

Optional GitHub Actions `MCP Auth0 Public Discovery Characterization` runs this same check via `workflow_dispatch` with a public tenant domain, **never** a token, client secret, account password or user data. A failed check can mean the metadata does not advertise the needed feature; it cannot prove Auth0 is impossible to configure. It must be separately investigated before any activation.

### Free tenant operator checklist (requires human signup, never paste secrets in GitHub/chat)

1. In the official [Auth0 dashboard](https://manage.auth0.com/), create/use a dedicated **Free evaluation tenant** disconnected from production accounts; verify current Free entitlements, Auth for MCP availability and billing policy in the dashboard. Do not add a payment method or subscribe to add-ons without an independent approval.
2. Share **only the public tenant domain** (for example `sample.us.auth0.com`), or execute the public metadata characterization in the workflow. Never paste credentials, access/refresh/ID tokens, JWT strings, synthetic-user passwords or internal Render URLs.
3. Confirm the Auth0 tenant's **Resource Parameter Compatibility Profile** is supported/enabled for the MCP `resource` parameter before any real authorize attempt. This is an Auth0 tenant-only setting that still requires a separate reviewed operator action.
4. Inspect the CIMD Management API response privately. Auth0 maps the ChatGPT external metadata URL to a tenant-internal client ID; compare the actual authorization result and **cryptographically verified** token claim with the allowlist. Never assume external URL and internal ID are interchangeable.
5. Create separate clients for ChatGPT and for the Canovia account-link. Use the exact callback URI advertised by the current ChatGPT connection UI; do not infer stable callback support if RFC 9207 is unadvertised.
6. The existing Canovia token verifier is RFC 7662 only. Auth0 often issues **RS256 JWTs** and ChatGPT may request `openid` by default. Auth0 documents that an API audience plus `openid` can yield `aud=[API,/userinfo]`, violating our **single exact** audience rule. Treat the resulting real token as incompatible and keep stage sealed until a reviewed security-preserving design and proper JWT/JWKS verifier address this. No unverified JWT claims, no wildcard audience, no removed scope checks.
7. Never enable Canovia stage OAuth/login/MCP flags solely because public metadata passes. User-to-Plan authorization, real ChatGPT OAuth, negative tests and human-approved staging exposure are separate gates.

Official references:
- https://auth0.com/jp/pricing
- https://auth0.com/blog/auth0-auth-for-mcp-servers-generally-available/
- https://support.auth0.com/center/s/article/mcp-audience-error-with-auth0
- https://auth0.com/docs/secure/tokens/access-tokens/get-access-tokens
- https://auth0.com/docs/api/management/v2/clients/post-clients-cimd-register
- https://developers.openai.com/plugins/build/auth

**Pending:** A human-created Free Auth0 test tenant and public domain, actual domain-specific metadata, original Auth0 client registration/claim proof, reviewed JWT cryptographic verification, per-Plan synthetic consent, staging rollout approval, and observed ChatGPT E2E. None is claimed complete.


## 2026-10-12 VERIFIED: actual owner's Auth0 JP Free evaluation tenant (public metadata only)

The owner created and disclosed the **public** evaluation domain `canovia-mcp-dev.jp.auth0.com`. Verified **actual public provider responses** from an isolated GitHub Actions job. This supersedes the historical "no tenant created" observations above, which were true when first written. No Auth0 Management API credentials were used, no client or API was created by the agent, and no private user identities, bearer tokens or Canovia resources were touched.

- Observation PR: [#464](https://github.com/1kz-ma1/Canovia-web/pull/464); GitHub CI [38152126711](https://github.com/1kz-ma1/Canovia-web/actions/runs/38152126711), original [38152021249](https://github.com/1kz-ma1/Canovia-web/actions/runs/38152021249).
- Verified issuer exactly `https://canovia-mcp-dev.jp.auth0.com/`.
- OIDC discovery `/.well-known/openid-configuration`: **PASS** for exact issuer, tenant-owned HTTPS authorization/token/JWKS endpoints, PKCE `S256`, authorization response `iss` per RFC 9207, and available `none` plus `private_key_jwt` token endpoint methods.
- OAuth AS discovery `/.well-known/oauth-authorization-server`: **accessible**, issuer exact, `S256` advertised. `registration_endpoint` present; **not proof that unauthenticated DCR is enabled/allowed**, nor that a real ChatGPT client has registered.
- Public JWKS `/.well-known/jwks.json`: at least one RSA key advertises `alg=RS256`. This proves a verification key is discoverable; it **does not validate any token** or authorize the Laravel MCP server.
- `client_id_metadata_document_supported`: **NOT ADVERTISED** in either observed discovery endpoint. Cannot claim CIMD is enabled or unsupported without checking actual tenant entitlement and registration. Auth0 Management API supports explicit `/api/v2/clients/cimd/register` registration separately.
- `introspection_endpoint`: **NOT ADVERTISED**. Existing Canovia `McpAccessTokenIntrospector` cannot be declared compatible with Auth0 on the evidence. A separate signed JWT/JWKS verifier remains a necessary candidate, behind tests and an independent security review.
- `scopes_supported`: standard OIDC scopes, but **does not advertise `canovia.development.read`**. This is not proof the custom API cannot support that scope; the tenant's MCP-specific API and permission still need to be created/configured and tested with real tokens.
- Resource Parameter Compatibility Profile: **NOT VERIFYABLE FROM PUBLIC METADATA**; operator must check tenant Advanced settings. The token must be bound to `https://canovia-mcp-staging.onrender.com/api/mcp`.
- `actual_chatgpt_client_id`, `iss+sub` across separate account-link and ChatGPT clients, token `aud`, signature, scope, lifetime/revocation and real `tools/call`: **NOT TESTED**.

### Concrete owner setup, isolated Auth0 Free tenant only

**No production changes or external MCP exposure.** Settings apply only to the owner-created isolated JP evaluation tenant. Keep tenant on Free; **do not** add payment methods or buy addons. Record screenshots of dashboard settings if any menu differs, avoiding private tokens/credentials.

1. [Auth0 Dashboard](https://manage.auth0.com/) → select `canovia-mcp-dev` → **Settings → Advanced**. Enable **Resource Parameter Compatibility Profile** (RFC 8707 `resource` → correct API audience). Save. This is required for ChatGPT to ask for tokens bound to the specified MCP resource URI; keep DCR and third-party features disabled unless separate approval confirms the needed client-registration path. Source: https://support.auth0.com/center/s/article/mcp-audience-error-with-auth0
2. **Applications → APIs → Create API**. Set **Name** = `Canovia MCP Staging Read`; **Identifier** = `https://canovia-mcp-staging.onrender.com/api/mcp` **exactly** (without a trailing slash; immutable once created); signing algorithm = **RS256**; JWT profile = **Auth0** initially (re-evaluate against actual audience/client/scope claims); user access policy = **Allow via client-grant** where available (explicit per-client API permission; never default allow-all). Auth0 does not need to contact the staged MCP service when this API identifier is registered. Source: https://auth0.com/docs/get-started/auth0-overview/set-up-apis
3. In the new API **Settings**, cap **Maximum Access Token Lifetime** to **3600 seconds or less** to align with the current strict Canovia token lifespan. **Enable RBAC** for scoped access and **Add Permissions in the Access Token** only if that is necessary and understood; the Canovia verifier must still validate the **literal `scope` claim** and must never substitute permission names from unrelated claims without design review. Source: https://auth0.com/docs/get-started/apis/api-settings
4. In the API **Permissions**, add **`canovia.development.read`**, description `Read an explicitly approved personal Development Plan`. Do **not** add mutation or admin scopes. Source: https://auth0.com/docs/get-started/apis/add-api-permissions
5. In **Settings → Advanced**, check whether **Client ID Metadata Documents (CIMD)** can be enabled on this Free tenant. Do not blindly open anonymous Dynamic Client Registration (DCR) as a fallback: third-party clients also require an explicit API client grant. Public discovery omitted the CIMD flag, so this remains unresolved. Do **not** perform ChatGPT client registration until the OAuth client-ID mapping, account-link client and security gates are reviewed.
6. Share only which items are set/enabled (not credentials), or use a settings screenshot with secrets hidden. No Auth0 API/client secret, JWT access token, user password, management token, refresh token, admin invite, or fake Plan data is needed at this step.

### Remaining engineering work and acceptance

- Implement and independently review JWT/JWKS validation for access tokens with **exact** issuer, checked signature/RS256/KID and refresh/key-rotation safety, true OAuth client identity, immutable `iss+sub`, audience policy, `canovia.development.read`, `nbf/iat/exp` <= 3600 seconds; strict negative tests. Do not relax Canovia's one-audience rule based on a mere Auth0 OIDC token with `/userinfo`. If actual requested token has two audiences, stop for a separate security model decision.
- Validate CIMD or restricted pre-registration end to end with the actual ChatGPT OAuth client and exact callback. `registration_endpoint` on public metadata is not enough.
- Independently verify and approve Canovia isolated staged service's public login/OAuth/MCP gates, exact deployed SHA, synthetic local actor linking and per-Plan permission. The existing Free stage DB expires on 2026-11-08 UTC.
- **Actual ChatGPT E2E remains NOT DONE**. The public OAuth and JWKS checks are **PASS**, but real ChatGPT `tools/list`, `tools/call` and revocation behavior are still unknown.

## 2026-10-12 owner-confirmed Auth0 API and Resource Profile

- Owner confirmed via Auth0 Dashboard that the custom API named **Canovia MCP Staging Read** already exists, its Identifier is exactly `https://canovia-mcp-staging.onrender.com/api/mcp`, and the permission `canovia.development.read` is already registered. Do not edit the built-in **Auth0 Management API**.
- Owner additionally confirmed the **Resource Parameter Compatibility Profile** is **ON** in the dedicated Free tenant `canovia-mcp-dev.jp.auth0.com`. This is an operator report, **not** cryptographic evidence of a token bound to the intended resource.
- No user/token/password/secret was provided. Registration of the actual ChatGPT OAuth client, verified signed JWT claims, separate Canovia account-link OAuth client, and consent remain pending.

### Next fail-closed security implementation slice

- Introduce `app/Services/McpJwtClaimConstraints.php`: pure, signature-**independent** policy checks intended **only after** trusted JWT/JWKS cryptographic verification. Allows an independently verified Auth0 default-token `azp` **or** RFC 9068 `client_id` matching the **actual Auth0-issued** client ID; fails closed when neither/both present or mismatched.
- Reject multi-audience tokens, wrong issuer/resource, malformed/expired/future/overlong lifetime, absent exact `canovia.development.read` scope, and malformed subjects. Preserve legacy introspection-only live runtime; do not wire any raw decoded claims into MCP endpoints.
- `tests/Unit/McpJwtClaimConstraintsTest.php` adds positive and negative claim-policy regression cases. This proves **claim constraints only**, never RS256 signature validation.
- **UNRESOLVED:** choose a maintained JWT/JWKS verifier (e.g. a Composer-library dependency with a reviewed lockfile), enforce allowed algorithms, `kid`/key rotation, trusted fixed JWKS URL, cache and network caps, and reject invalid signatures/alg confusion. Then confirm a **real** Auth0-issued token's `aud`, `iss+sub`, `azp`/`client_id` and `scope`. No anonymous DCR enablement and no synthetic stage exposure by this PR.
- Compatibility nuance: Auth0 default JWT access tokens use `azp`, whereas Auth0's RFC 9068 API JWT tokens expose `client_id`. Do not change the Auth0 access-token profile merely to satisfy this policy: compare actual verified claims, actual issuer and client registration first. Sources: https://support.auth0.com/center/s/article/receive-the-client-id-claim-instead-of-the-azp-claim-in-an-auth0-access-token ; https://auth0.com/blog/jwt-access-tokens-profiles-now-in-ga/ ; https://developers.openai.com/plugins/build/auth

### Operator boundary

The human-visible Free tenant settings are now in place. For future registered-client work, **do not paste an Auth0 access or management token** into ChatGPT or public GitHub issues. Check dashboard CIMD or restricted client registration separately, as discovery had no CIMD advertisement; the public Auth0 `registration_endpoint` is not proof of usable unauthenticated DCR. A client created through Auth0's CIMD Management API can have a different internal `client_id` than the external metadata URL.

**Actual ChatGPT-to-Canovia read E2E still NOT DONE.** This commit changes no authentication routes, flags, env vars, packages, database schema, service deployment, or authorization grant data.

## 2026-10-12 Phase 1A — Auth0 RS256/JWKS cryptographic verifier (PR #469)

**Status:** Security-infrastructure implementation and synthetic signed-token CI, **not an active MCP / Auth0 / ChatGPT connection**. This slice extends PR #467's post-verification claims policy without changing current RFC 7662 runtime handlers.

- Added `app/Services/McpAuth0JwtTokenVerifier.php` using maintained `firebase/php-jwt` library (Composer range `^7.2`; lockfile pin `v7.2.1`, resolved in a clean GitHub CI runner with Composer). This validates actual RS256 signatures using selected trusted Auth0 JWKS public RSA keys before applying the previous `McpJwtClaimConstraints` contract and constructing an immutable `McpVerifiedTokenPrincipal`.
- Source of public keys is **only** the operator-configured exact issuer `https://*.auth0.com/` + fixed `/.well-known/jwks.json`. No client token/headers/payloads control the fetch destination. Unrecognized `kid` can trigger at most one refresh per issuer per minute; public-key cache TTL is 300s. Disallow JKU/X5U/embedded JWK/crit headers, algorithm confusion, weak/inapplicable or duplicate keys, response redirects, oversized bodies, unexpected content types and malformed JWTs.
- The verifier is guarded by independent config `CANOVIA_MCP_AUTH0_JWT_VERIFICATION_ENABLED=false` by default and `McpProtectedResourceConfiguration::isReady()`. **NO controllers, OAuth account-link flows, Plan authorization, scheduled jobs or routes invoke it**. Enabling this setting alone does not authorize data reads or change the existing introspection mode.
- Synthetic negative/positive RSA tests cover signed JWT acceptance, signature/payload tampering, wrong RSA key, HMAC/none algorithms, JKU injection, wrong issuer/audience/client/scope/time, key rotation, unknown-key request caps and provider/JWKS errors. Dedicated CI uses the committed `composer.lock` and locked dependency installation, not a blind dependency update during production deploy.
- The issuer is configured but has not been set on Render; no Management API credentials, staging environment values, secrets, stage deploy, production deploy, paid service, live token, or real user records were used. The user-reported Auth0 test API/resource profile settings and custom scope remain separate external facts.

**Next gates:** Actual Auth0 client-registration mapping (CIMD or explicitly restricted registration), Auth0 verified real token format (`azp` or RFC9068 `client_id`, single exact `aud`, exact scope), independent Canovia confidential account-link client with PKCE and explicit per-Plan consent, review of wiring this **existing verified-signature service** into the separate closed MCP middleware/account-link paths, staging deployment approval and a real ChatGPT Plan read/revocation test. Before then, tools remain OFF and E2E acceptance remains NOT MET.


## 2026-10-12 Explicit JWT / RFC7662 runtime routing (PR #pending)

**Scope:** A strictly mutually exclusive token verifier for existing MCP resource and the separate Canovia owner-login OAuth account-link + Plan consent. Code-only, **NOT a live Auth0 or ChatGPT connection**.

- Default `CANOVIA_MCP_TOKEN_VERIFICATION_DRIVER=introspection` retains the preexisting RFC7662 behavior. Alternative `auth0_jwt` requires `CANOVIA_MCP_AUTH0_JWT_VERIFICATION_ENABLED=true` with `CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED=false`. Both ON, both OFF, mismatched mode, or unknown mode **fail closed**. No fallback or inference from token format.
- MCP resource uses an RS256-verified subject with exact client, issuer, canonical audience, scope and expiration. Canovia account-link OAuth code exchange requires a **different** confidential app client ID verified by the same exact RS256/JWKS signature policy.
- Account linking keeps explicit session-bound PKCE S256, state, RFC9207 callback issuer, safe OAuth HTTPS URLs, owner confirmation and subsequent separate Plan grant confirmation. Neither cryptographically verified JWT nor a linked identity alone authorizes private Plans.
- General Auth0 OIDC discovery omits custom API scopes. JWT mode no longer requires that custom read permission be listed *in general provider metadata*, but actual signed access-token `scope=canovia.development.read` remains an exact mandatory authorization check. Legacy RFC7662 metadata requirement is unchanged.
- No source file, merged PR, or synthetic test automatically enables production, stage OAuth, JWT verification, MCP, Plan consent or public network reachability.

### Remaining external acceptance and operator setup

1. Register a **separate Auth0 Regular Web Application** for Canovia owner-account linking, with callback `https://canovia-mcp-staging.onrender.com/account/mcp/link/callback` and confidential client-secret Basic / PKCE authorization code. Store secrets only in the isolated stage Render environment after explicit staging approval; never in chat/GitHub.
2. Choose a controlled ChatGPT OAuth client registration. Auth0 public discovery did not advertise CIMD. Its restricted Management API method `POST /api/v2/clients/cimd/register` requires scoped management credentials and reports the actual registered **internal client_id**. It is not consent to activate open DCR. The exact ChatGPT CIMD document and callback must come from the actual connector management UI, not guesses.
3. Verify real signed Auth0 tokens from each distinct client for exact `iss`, `sub`, `azp` or `client_id`, `scope=canovia.development.read`, allowed lifetime and single precise `aud=https://canovia-mcp-staging.onrender.com/api/mcp`. Tokens bearing a second `/userinfo` audience remain rejected until a separate reviewed resource-binding security decision.
4. The legacy `McpStagingProviderPreflightService` remains RFC7662/introspection-oriented. Its failure in JWT mode does not imply the new verifier lacks signature checks; a mode-aware read-only preflight and independent operator review are needed before stage activation.
5. Require separate staging deploy/security decision, synthetic Canovia owner and one Development Plan consent, and observed ChatGPT initialize, tools/list, tools/call, negative grants and revocation. The existing stage HTTP/OAuth/MCP flags remain OFF.

Official references: https://developers.openai.com/plugins/build/auth ; https://auth0.com/docs/api/management/v2/clients/post-clients-cimd-register

## 2026-10-12 Auth0 Canovia account-link application prepared; registration readiness

- Owner confirmed that a separate **Canovia MCP Staging Link** Auth0 Regular Web Application was created, with **Authorization Code** grant enabled and exact **Allowed Callback URL** `https://canovia-mcp-staging.onrender.com/account/mcp/link/callback`. Treat this as user-reported dashboard setup, not proof of a successful OAuth code exchange. Do NOT place its client secret in GitHub issues, PRs, logs or ChatGPT messages.
- Issuer/test tenant: `https://canovia-mcp-dev.jp.auth0.com/`; API identifier `https://canovia-mcp-staging.onrender.com/api/mcp`, permission `canovia.development.read`; owner previously confirmed Resource Parameter Compatibility Profile ON. Actual issued JWT `aud`, `scope` and `azp`/`client_id` for either OAuth client **remain unobserved**.
- In JWT mode the operator preflight now marks introspection as **not applicable** and permits missing custom API scope in public OAuth discovery, because Auth0's general metadata is not a full custom API permission list. Conversely it explicitly reports **requires_signed_token** and **requires_issued_client**, and `preflight_passed=false` even for otherwise valid public metadata. Do not misreport DCR's advertised registration URL or CIMD's advertised flag as successful enrollment.
- Registration plan: Official ChatGPT OAuth guidance prefers CIMD. For RFC9207-compliant stable issuer, ChatGPT's CIMD metadata document is usually `https://chatgpt.com/oauth/client.json` and OAuth callback is usually `https://chatgpt.com/connector_platform_oauth_redirect`, but **read the exact current values shown for the user's intended ChatGPT MCP connection**. Auth0's tenant previously omitted `client_id_metadata_document_supported` and CIMD must be checked independently.
- Auth0 Management API has `POST /api/v2/clients/cimd/register` with `{ "external_client_id": "<exact ChatGPT metadata URL>" }`. Registration requires a suitably scoped **tenant Management API access token** (`create:clients`; `update:clients` for later upserts), generated and used only in the user's private management environment. Do not paste any bearer token or app secret into chat. Registration may produce a distinct internal `client_id`; inspect the returned client ID without revealing tokens and bind it only after actual signed JWT claims confirm client identity. Do not enable anonymous DCR as a workaround.
- **No management API credential or client registration is performed in this code-only slice.** Live Render staging/billing/deployment and MCP exposure stay unchanged. Completion requires a separate confidential client code exchange, verifiable single-resource signed token from each distinct client, explicit per-Plan consent, exact ChatGPT tool read and revocation checks.

Official references: https://developers.openai.com/plugins/build/auth ; https://auth0.com/docs/api/management/v2/clients/post-clients-cimd-register


## 2026-10-12 ChatGPT CIMD / Auth0 Free tenant registration decision

### Verified public metadata versus real client registration

- OpenAI's public stable CIMD metadata endpoint is `https://chatgpt.com/oauth/client.json`; its `client_id` exactly matches that URL, `redirect_uris` includes `https://chatgpt.com/connector_platform_oauth_redirect`, and it advertises **both** `none` and `private_key_jwt` token endpoint auth methods plus `S256`-compatible OAuth Authorization Code client use. Actual per-connection callback and client document must still match the ChatGPT Plugin management UI. Official docs: https://developers.openai.com/plugins/build/auth
- Auth0 official CIMD docs specify **Settings → Advanced → Client ID Metadata Document Registration ON** for discovery, followed by **Applications → Applications → Create Application → Import from URL**, paste exact CIMD URL, **Preview**, inspect client name and callback, then **Create**. This is an admin UI action that needs no external Management API bearer token copied into ChatGPT. Official: https://auth0.com/docs/get-started/auth0-overview/create-applications/register-applications-with-cimd
- Crucial **Free-plan constraint**: Auth0's manual CIMD docs state `private_key_jwt` authentication is **Enterprise-only**. Public `none` plus PKCE is the possible Free path; success requires actual tenant Preview/Create and token exchange. The OpenAI CIMD declares both accepted methods; **do not assume** Auth0 chooses `none` automatically or that registration necessarily succeeds.
- **2026-09-30 compatibility caveat**: a public Auth0 community report recorded `cimd/preview` returning valid but `cimd/register` failing with HTTP 400 `invalid_client_metadata` for a ChatGPT per-connection CIMD. This is anecdotal (not independently reproduced here), but relevant: treat **registration success** separately from **preview success**. https://community.auth0.com/t/auth0-cimd-registration-appears-incompatible-with-the-current-chatgpt-client-id-metadata-document/203817
- Dedicated public-only probe `scripts/ci/mcp_chatgpt_auth0_cimd_preflight.py --live` checks the exact pinned ChatGPT CIMD URL/JWKS and the existing Auth0 test tenant's OIDC metadata. Its only output is protocol status flags; it fetches no private Canovia URLs, credentials, OAuth code, ChatGPT user identity or Plan. `public_metadata_compatible_with_free_candidate=true` is **NOT** successful client registration or E2E.

### Staged operator steps and fail-closed decisions

1. In the already-created free test tenant `canovia-mcp-dev.jp.auth0.com`, enable **Client ID Metadata Document Registration** (if present and allowed under Free) under **Settings → Advanced**, separately from the previously confirmed Resource Parameter Compatibility Profile ON. Do NOT enable unauthenticated DCR.
2. In **Applications → Applications → Create Application → Import from URL**, paste `https://chatgpt.com/oauth/client.json` only if the ChatGPT connection UI confirms this exact metadata URL. Click **Preview** and confirm the client name, allowlisted stable callback and chosen `token_endpoint_auth_method`. If the method requires `private_key_jwt` on Free or the preview shows invalid/mismatched URI, **do not create**.
3. If Preview is valid and the client selects `none`/PKCE under Free, choose **Create**. Treat `201`/dashboard success plus a new application listed as registration evidence. If Create returns `invalid_client_metadata` despite valid Preview, **stop**; collect only the error code and non-secret validation warning, consult Auth0 Support and consider a deliberately pre-registered ChatGPT OAuth client as a separate reviewed path. Do not silently enable open DCR.
4. After registration verify third-party application API access policy, only the `Canovia MCP Staging Read` API identifier and `canovia.development.read` delegated scope, and the actual mapped internal `client_id` versus `external_client_id` without treating the latter as the former.
5. Only later, under separate staging deployment/authorization, inspect an actual issued Auth0 JWT's verified `iss`, `aud`, `azp`/`client_id`, `sub`, `exp`, `scope`; require exact one-resource audience. A signed JWT and successful ChatGPT registration do **not** imply an approved Canovia local account identity or Plan grant.

**Current outcome:** Auth0 API and separate Canovia confidential link app are owner-prepared; ChatGPT client registration unverified, issued ChatGPT token unverified, staging remains sealed, real ChatGPT Plan tool-call not executed, both Render services Auto-Deploy OFF, no paid plan or Management API token used.

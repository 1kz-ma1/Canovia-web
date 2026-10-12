# Auth0 / Canovia MCP — Sealed staging wiring acceptance (2026-10-12)

Status: operator-only configuration preparation. No external ChatGPT Plan read, verified OAuth authorization code or stage activation.

## Operator-owned Auth0 steps

- In the test tenant, verify the already-imported ChatGPT CIMD application has actually been created and that the registered callback is the exact redirect shown by the ChatGPT connection UI. In the strict third-party mode, the internal Auth0 Client ID begins with tpc_. It is not interchangeable with the external CIMD metadata URL. Verify none/PKCE S256 as a candidate usable on Free; do not assume its actual callback or token exchange succeeded.
- The existing first-party Canovia MCP Staging Link app is separate. Confirm its Authorization Code grant and the precise callback https://canovia-mcp-staging.onrender.com/account/mcp/link/callback. The existing API has Per-app User-Delegated Access controls: verify this app independently has canovia.development.read authorization where required. The ChatGPT grant alone is not enough.
- Do not grant machine-to-machine Client Access or unrestricted default third-party permissions. A domain-level connection change affects all registered third-party apps in this tenant, so review that impact before changing it.

## Private Render staging configuration (no activation)

All values below are stage-only; no environment edits are authorized by this document. Production and its credentials must remain untouched.

Non-secret pinned values:
- CANOVIA_MCP_OAUTH_ISSUER = https://canovia-mcp-dev.jp.auth0.com/
- CANOVIA_MCP_RESOURCE_URL = https://canovia-mcp-staging.onrender.com/api/mcp
- CANOVIA_MCP_TOKEN_VERIFICATION_DRIVER = auth0_jwt
- CANOVIA_MCP_ACCOUNT_LINK_METADATA_URL = https://canovia-mcp-dev.jp.auth0.com/.well-known/openid-configuration
- CANOVIA_MCP_ACCOUNT_LINK_AUTHORIZATION_ENDPOINT = https://canovia-mcp-dev.jp.auth0.com/authorize
- CANOVIA_MCP_ACCOUNT_LINK_TOKEN_ENDPOINT = https://canovia-mcp-dev.jp.auth0.com/oauth/token
- CANOVIA_MCP_ACCOUNT_LINK_REDIRECT_URI = https://canovia-mcp-staging.onrender.com/account/mcp/link/callback

Private values to enter exclusively into the Render staging Environment UI:
- CANOVIA_MCP_ALLOWED_CLIENT_ID: the independently verified issued internal Auth0 third-party ChatGPT client ID (tpc_ prefix), not its external URL.
- CANOVIA_MCP_ACCOUNT_LINK_CLIENT_ID: Client ID for the existing confidential Canovia MCP Staging Link app, distinct from ChatGPT.
- CANOVIA_MCP_ACCOUNT_LINK_CLIENT_SECRET: matching confidential secret, never copied into chat, GitHub or logs.
- CANOVIA_MCP_IDENTITY_FINGERPRINT_KEY: new dedicated high-entropy random private key (minimum 32 characters); preserve it after linking, because rotation invalidates existing fingerprints.

Until further separate approval keep ALL of these false: CANOVIA_STAGING_WEB_ACCESS_ENABLED, CANOVIA_STAGING_WEB_ACCESS_EXPLICITLY_APPROVED, CANOVIA_MCP_DISCOVERY_ENABLED, CANOVIA_MCP_TOOLS_ENABLED, CANOVIA_MCP_ACCOUNT_LINK_ENABLED, CANOVIA_MCP_PLAN_CONSENT_ENABLED, CANOVIA_MCP_DELEGATED_POLICY_ENABLED, CANOVIA_MCP_AUTH0_JWT_VERIFICATION_ENABLED, CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED. Keep the existing isolated pinned PostgreSQL; no Aiven DB, production APP_KEY or integrations.

## Secretless offline acceptance

The operator CLI php artisan canovia:mcp-auth0-sealed-wiring-check --json reports named pass/blocked codes, not actual configuration values. It has no network calls, DB queries, token handling, browser endpoints, client registration or Plan reads. A positive configuration_ready is not a real issued token or user consent: stage_activation_authorized and production_authorized always remain false.

When approved, a one-shot private Render staging setting CANOVIA_STAGING_VERIFY_AUTH0_WIRING_ON_START=true runs that check at the closed staging boot, after existing migrations and before HTTP startup. A failed check aborts the new deployment and does not open an OAuth/MCP route; startup logs show only configuration ready or blocked. Return this setting to false after acceptance. Use neither this switch nor the check on production. Render Free may not provide interactive shell access, which is why the sealed-startup check exists.

## Remaining live protocol gates

ChatGPT defaults to requesting OIDC scopes advertised in the authorization server discovery, but Auth0 currently documents that strict third-party apps do not support OIDC scopes and ID tokens. This remains a possible incompatibility: do not infer success merely from CIMD import or the client grant. Verify actual requested scopes and live authorization result privately, without sharing authorization codes or tokens. Do not tamper with provider discovery, enable public DCR, broaden scope grants or weaken the precise single-audience signed JWT contract merely to force a login.

After a separate approval, staged browser access for a synthetic user, real signed JWT verification (issuer, audience, exact issued client identity, scope and timestamps), same-subject account linking, individual Plan consent and ChatGPT tool-call/revocation testing are still required.

References:
- https://developers.openai.com/plugins/build/auth
- https://auth0.com/docs/get-started/applications/third-party-applications/security-controls
- https://auth0.com/docs/get-started/applications/third-party-applications/configure-third-party-applications

## 2026-10-13 Correction — connector-specific ChatGPT callback and OIDC scopes

**Do not use the global legacy ChatGPT CIMD callback as evidence that a newly created ChatGPT plugin has the same callback.** Use the exact redirect URL and CIMD document displayed for the intended connector. OpenAI now documents new connector callbacks as `https://chatgpt.com/connector/oauth/{callback_id}`; the previously imported global `https://chatgpt.com/oauth/client.json` document still lists a legacy callback. The Auth0 third-party client grant and `tpc_` Client ID must match the registered **actual connection**, not just the global import.

A candidate workaround for ChatGPT OIDC defaults versus Auth0 strict third-party OAuth-only scope restrictions exists via plugin `defaultScopes: []`, `baseScopes: ["canovia.development.read"]`, and `oidcEnabled: false` where the supported plugin configuration allows. This is untested, not a reason to loosen Auth0 or Canovia authorization. Detailed diagnostic and example: [callback and scope acceptance](MCP_CHATGPT_AUTH0_CALLBACK_SCOPE_ACCEPTANCE_2026_10_13.md).

# Canovia MCP — RFC 9207 conditional ChatGPT callback and OAuth scope acceptance (2026-10-13)

Status: documented candidate only. No Auth0 registration change, runtime login, token verification or MCP opening.

## Correct callback selection — issuer identification controls the mode

**Correction to PR #498:** OpenAI's official OAuth guidance selects the **stable** ChatGPT callback `https://chatgpt.com/connector_platform_oauth_redirect` for ChatGPT/Codex connections when the authorization server advertises `authorization_response_iss_parameter_supported=true`, and returns the exact same `iss` parameter in **every** successful and error authorization response. This applies to **new connections too**; the stable callback is not exclusively a legacy-connector artifact.

If the authorization server **does not** meet this RFC 9207 contract, ChatGPT uses the **connection-specific** `https://chatgpt.com/connector/oauth/{callback_id}` callback. OpenAI likewise selects the stable CIMD document `https://chatgpt.com/oauth/client.json` for RFC 9207-capable servers; otherwise a callback-specific `https://chatgpt.com/oauth/{callback_id}/client.json` is used. Neither mode may be guessed solely from the connection's creation date.

Source: https://developers.openai.com/plugins/build/auth (Redirect URL and OAuth flow).

**Actual Auth0 UI evidence as reported by the owner:** the already-imported ChatGPT third-party client has an Auth0-assigned `tpc_` Client ID and Allowed Callback URL **exactly** `https://chatgpt.com/connector_platform_oauth_redirect`. These are consistent with the RFC 9207-stable path. Auth0 issuer metadata was publicly characterized as advertising RFC 9207 in CI, but **the actual callback's `iss`, client identity mapping, PKCE code exchange, and JWT claims remain unverified**.

### Remaining test-tenant operator review (do NOT duplicate existing app)

1. Confirm `https://canovia-mcp-dev.jp.auth0.com/.well-known/openid-configuration` continues to advertise `authorization_response_iss_parameter_supported=true`, pinned issuer and S256. The real authorization response must **actually** include the exact `iss` on both success and errors. Metadata alone cannot prove it.
2. Check ChatGPT's intended MCP connector management page: its displayed CIMD URI / callback must correspond to the **RFC 9207 stable mode**. If ChatGPT itself displays `/connector/oauth/{callback_id}` instead, use its exact displayed URI as the source of truth and investigate before OAuth activation. Do not invent `callback_id` or create another Auth0 client automatically.
3. Confirm the Auth0 imported third-party Client ID begins `tpc_`, has a usable `none`/PKCE S256 flow for the tenant's Free plan, and was granted only `canovia.development.read` User-Delegated Access to **Canovia MCP Staging Read**. A matching `tpc_` format does not prove a real JWT `azp`/`client_id`.
4. Independently confirm **Canovia MCP Staging Link**'s exact callback `https://canovia-mcp-staging.onrender.com/account/mcp/link/callback`, distinct first-party confidential credentials, and its own delegated API grant when required. Do not reuse ChatGPT's third-party client for local subject linking or share secrets in chat.

## OpenAI plugin-side OIDC mitigation candidate (NOT a deployed plugin)

OpenAI's current MCP plugin packaging specification supports `baseScopes`, `defaultScopes` and `oidcEnabled` under each MCP server's `extensions.com.openai.auth`. Auth0's enhanced third-party applications do not support OIDC `openid`, `profile`, `email` scopes/ID tokens; OpenAI's defaults may request OIDC scopes advertised in Auth0 discovery. Where the current plugin builder or supported connection workflow accepts these OAuth override fields, **test** the following *declarative candidate* in an isolated unpublished plugin:

```json
{
  "$schema": "https://agent-plugins.org/schemas/1.0.0/mcp.schema.json",
  "mcpServers": {
    "canovia-development-read": {
      "type": "streamable-http",
      "url": "https://canovia-mcp-staging.onrender.com/api/mcp",
      "extensions": {
        "com.openai": {
          "auth": {
            "type": "oauth",
            "client": { "mode": "cimd" },
            "authorizationServerBase": "https://canovia-mcp-dev.jp.auth0.com/",
            "resource": "https://canovia-mcp-staging.onrender.com/api/mcp",
            "baseScopes": ["canovia.development.read"],
            "defaultScopes": [],
            "oidcEnabled": false
          }
        }
      }
    }
  }
}
```

`defaultScopes: []` disables **default-added** scopes; `baseScopes` retains the exact intended read permission; `oidcEnabled: false` opts out of OIDC for the plugin when supported. These declarations are **not proof** that the installed ChatGPT connection honors overrides, that a strict Auth0 third-party login succeeds, or that the correctly registered `tpc_` client is used. **Do not edit global Auth0 discovery to remove OIDC capabilities** to make this work, and never weaken signed JWT issuer/audience/scope/client constraints.

The active Render stage is currently sealed, returning `/up=200` and OAuth/MCP/login `503`. No real OAuth request should be attempted until separate stage access authorization, synthetic Canovia account credentials and linked subject verification are prepared. On the first explicitly authorized OAuth test, record only non-sensitive diagnostic categories:
- Actual connector's CIMD URI vs registered external client identity and **exact callback URI** (avoid logging codes or request state).
- Whether third-party client authentication supports `none`/PKCE or fails with `invalid_client`.
- Whether the **requested** scopes contain exactly `canovia.development.read` and **exclude OIDC scopes**.
- Auth0's sanitized OAuth error code and client/API permission state.
- Only after private signed JWT verification: `iss`, exact single `aud`, read `scope`, verified `azp` or `client_id` and clock claims. Never copy access tokens to chat/GitHub.

The existing public-only script `scripts/ci/mcp_chatgpt_auth0_cimd_preflight.py --live` still characterizes **only the stable global CIMD document**; it now reports `current_connection_callback_verified=false`, `current_connection_cimd_verified=false` and `oidc_scope_interoperability_verified=false` irrespective of public compatibility.

References:
- https://developers.openai.com/plugins/build/auth
- https://developers.openai.com/plugins/build/plugins
- https://auth0.com/docs/get-started/applications/third-party-applications/security-controls
- https://auth0.com/docs/get-started/applications/third-party-applications/configure-third-party-applications

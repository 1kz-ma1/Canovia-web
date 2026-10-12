# Canovia MCP — exact ChatGPT OAuth callback and scope acceptance (2026-10-13)

Status: documented test candidate only. This does not register or reconfigure a client, enable OAuth/MCP, reveal secrets or deploy code.

## Important distinction — old global CIMD vs current connection

The public legacy global ChatGPT CIMD document `https://chatgpt.com/oauth/client.json` still advertises the old `https://chatgpt.com/connector_platform_oauth_redirect` callback. That document proves only its own public legacy shape. **The actual connector being created may use a different, connection-specific CIMD metadata URL and its callback**. Current official OpenAI authentication guidance states that a newly configured ChatGPT plugin callback is shown in the plugin management page and follows `https://chatgpt.com/connector/oauth/{callback_id}` (the literal placeholder is not a usable redirect URL). The old legacy redirect continues working for already-published connections. Never assume they are interchangeable.

**Owner review (Auth0 test tenant only)**

1. Before creating the ChatGPT MCP connection, check that its actual configuration/management UI displays the exact client identification method and, when provisioned, a real callback URI. Until that value exists, keep authorization closed. Do not invent a callback ID.
2. In the already-imported ChatGPT Auth0 third-party application, compare its Allowed Callback URL against **the exact callback shown for that same ChatGPT plugin**. The global `/oauth/client.json` import may have authorized only the old legacy redirect. If so, do not assume it authorizes a new `/connector/oauth/{callback_id}` callback. A correctly imported and authorized connection-specific CIMD registration may be required. Do not open anonymous DCR or duplicate applications blindly.
3. Verify actual client type and token endpoint method: third-party Auth0 Client ID of the form `tpc_...` (issued internal identity, not CIMD URL), `none` + PKCE S256 where Free permits, and the specific API's `canovia.development.read` **User-Delegated** client grant. `tpc_` prefix alone does **not** prove the external-client mapping, callback, token claims or entitlement.
4. Also review the **existing first-party Canovia MCP Staging Link** client's separate API grant, Authorization Code setting and `https://canovia-mcp-staging.onrender.com/account/mcp/link/callback`. Do not reuse the ChatGPT client for local Canovia account linking. Keep all secrets inside the Auth0/Render private dashboards.

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

The existing public-only script `scripts/ci/mcp_chatgpt_auth0_cimd_preflight.py --live` still characterizes **only the fixed global legacy CIMD URL**; it now reports `current_connection_callback_verified=false`, `current_connection_cimd_verified=false` and `oidc_scope_interoperability_verified=false` irrespective of public compatibility.

References:
- https://developers.openai.com/plugins/build/auth
- https://developers.openai.com/plugins/build/plugins
- https://auth0.com/docs/get-started/applications/third-party-applications/security-controls
- https://auth0.com/docs/get-started/applications/third-party-applications/configure-third-party-applications

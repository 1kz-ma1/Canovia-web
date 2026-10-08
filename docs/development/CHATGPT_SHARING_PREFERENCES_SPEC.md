# ChatGPT Development sharing preferences — preparation only

Updated: 2026-10-09. Status: CURRENT IMPLEMENTATION for **preparation and cancellation**, NOT an activated connector or OAuth consent.

## Problem and decision

Canovia stores private Development Plan information. ChatGPT MCP OAuth cannot be considered authorized solely because the user has linked GitHub, authenticated to Canovia in a browser or saved a proposed sharing scope. Before building an OAuth server and enabling externally accessible tools, Canovia needs a **session-authenticated, owner-only preparation and cancellation UI**.

The initial provider identifier is fixed to `chatgpt`. Users configure one owned personal Development Plan at a time (one row per user/Plan/provider), the maximum proposed scope and a finite preparation lifetime. This never creates a token, OAuth client registration, signed share URL, public API, active access grant or remote connection. Preparation records are **NOT consent**; the actual OAuth consent screen will require another explicit user action once the end-to-end flow exists.

## Proposed limits

- `scope=overview|tasks`, default `overview` (Plan title only).
- `duration_days=1|7|30`, default 7; expiry is calculated at write time, never unlimited. Expiry of a preparation is not a token lifetime.
- A row is `prepared` or `revoked`. An expired preparation is effectively inactive and is displayed as expired. No row or status grants external read access, **even while prepared**.
- Preparing again may replace the scope/expiry of the same row, including after explicit revocation. No auto-renewal, no silent scope expansion.
- Cancellation is idempotent and works even if the Plan has since changed to shared/non-development; it must not require the Plan to remain eligible, so its owner can always cancel a stale preparation. Only the preference creator can cancel, even after Plan ownership is transferred; cancellation must not disclose new-owner content.
- Audit each actual creation, update and cancellation to an append-only table, recording the actor, provider, Plan/pref ID, scope and bounded expiry. No private Context contents, session cookies, OAuth tokens, provider secrets or external requests are logged.
- All operations are web-session authenticated, CSRF-protected and throttled. Authorization is checked **before database mutations**. Prepare requires the logged-in primary owner, `is_collaborative=false` and current Development profile. Other users/team Plans/guest-owned Plans/Study/Creative Plans must fail closed.
- Store only the selected IDs, provider key, scope, timestamps and status in the persistence layer. Add no private Task/Plan descriptions, other users' data or credentials.

## UI

In Development **作業** for eligible owned personal Plans, show a compact `ChatGPT共有の準備設定（未接続）` section with proposed scope, 1/7/30-day lifetime, a **`準備設定を保存（まだ接続しない）`** action and **`準備設定を取り消す`** when a prepared/expired preference exists. Display provider, scope, expiry and status clearly. Do not present saving as a real OAuth authorization, and do not copy private Context implicitly.

The account page lists all still-prepared entries (including expired entries) for the current user, with a cancellation form. If ownership has changed, hide the Plan's current title. This is an essential cancellation fallback when a Plan is no longer accessible through Development Work.\n\nThe existing owner-only manual Context preview remains independent. UI renders escaped labels; submissions use Laravel CSRF-protected forms and redirect back to Development Work. No JS dependency for save/revoke.

## Current MCP OAuth discovery/probe implementation (no delegated access)

A disabled-by-default `/api/mcp` authorization probe and RFC 9728 protected resource metadata routes are in place as a separate slice. They never accept bearer tokens or return private Context, even if the user previously saved a `prepared` sharing preference. See [MCP OAuth resource gate contract](MCP_OAUTH_RESOURCE_GATE_CONTRACT.md). Actual ChatGPT authorization, formal consent, an OAuth provider, token validation and JSON-RPC tools are still **not implemented**.

## Separate read-token verification component (not a connection)

The disabled `McpAccessTokenIntrospector` can verify compatible RFC 7662 introspection claims from an explicitly configured real OAuth provider. It is not wired to the MCP route and cannot authorize Plan access or convert preparation into approval. The verified external subject still needs a separate, consented, immutable actor identity binding. See [MCP token introspection contract](MCP_TOKEN_INTROSPECTION_CONTRACT.md).

## Future OAuth/MCP — separate gated phase

Never expose the new tables as an access-check substitute. A secure MCP read requires ChatGPT-specific client identification (CIMD/DCR/pre-registered), resource metadata and authorization-server metadata, OAuth 2.1 Authorization Code + PKCE S256, `resource`/audience binding, short-lived tokens, scopes, revocation and token verification on **every** tool call. Do not use existing HMAC activity credentials as OAuth.

Implement independently with an established OAuth provider where possible, and require a fresh explicit consent with selected Plan and scope rather than silently upgrading a prepared row. Enforce **current** actor/Plan/repo membership and revocation each time, rate limits and full unauthorized/read-only regression tests. ChatGPT OAuth connection UI is not available from this slice; no external private read is supported.

Official source of current platform constraints: https://developers.openai.com/plugins/build/auth and https://developers.openai.com/plugins/build/mcp-server

## Acceptance gates

- Create, update, expire and revoke preferences with no external read mechanism.
- Unauthenticated, unauthorized, collaborative and non-development creation rejected.
- Revoke remains possible if Plan becomes collaborative/non-development or is transferred to another owner; other actor rejected.
- No Task/Plan mutation, external GitHub request, OAuth token, data leak or false `connected` status.
- Tests in required CI, migration retry safety and Render deploy monitored separately.

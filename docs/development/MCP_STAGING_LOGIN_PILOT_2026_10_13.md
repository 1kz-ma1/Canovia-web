# Canovia Auth0 — closed-to-login-only staged acceptance (2026-10-13)

## Boundary and status

**Preparation only: neither login nor OAuth is activated by merging this change.** Actual stage and production are both unchanged. The first safe phase allows ONLY the existing synthetic user to sign into the isolated Render PostgreSQL staging service. It is not ChatGPT OAuth acceptance.

- Staging Web service: `srv-db43l4nlk1mc73emseig`, `https://canovia-mcp-staging.onrender.com`, Render My Workspace `tea-d4vb3f6mcj7s73djkrqg`; auto deploy off.
- Pinned disposable staging PostgreSQL: `dpg-db43rbbncjis73bmigi0-a`, `canovia_mcp_staging_db`. Free expiry 2026-11-08. No production data.
- **Production and Aiven must never be changed** for this acceptance.

The first-party Auth0 Link application, ChatGPT third-party client, issuer and credentials have passed the **offline nine-item wiring inventory**. This does not prove a real OAuth token or real ChatGPT connection. The owner previously completed a one-time synthetic password rotation, confirmed by staging startup, then the temporary rotation environment variable was cleared and follow-up stage came up `Live`.

## New fail-closed gates

Before touching Render environment, merge this code only after dedicated PHP, migration and isolated Docker CI are green. The whole site may **not** be exposed by turning on the older pair of Web flags: a third, independent `CANOVIA_STAGING_LOGIN_PILOT_ENABLED` is required. With it, both Nginx and global Laravel middleware allow only:

- GET/HEAD `/login`, GET/HEAD `/account`, POST `/logout`
- POST `/login` with the **fixed synthetic** email `mcp-synthetic-owner@canovia.invalid` only; remember-me is disabled
- Health `/up` only. Stage login and account are self-contained, no `/build/`, external fonts, scripts or product header. `/build/` is denied with other normal-app routes.

A fourth, **independently approved** `CANOVIA_STAGING_ACCOUNT_LINK_PILOT_ENABLED` extends Laravel to `/account/mcp/link/start`, `/account/mcp/link/callback`, `/account/mcp/link/confirm` and `/account/mcp/link/cancel`, and only while discovery, signed Auth0 JWT verifier and link gates are on, with introspection, delegated policy, Plan consent and MCP tools **still off**. This link phase is *not* automatically enabled by the first phase.

Nginx denies unrelated routes (including `/`, `/health`, `/register`, `/forgot-password`, `/api/mcp`, `/.well-known/oauth-protected-resource`); Laravel repeats deny rules by route and method and prevents any authenticated non-synthetic actor from proceeding. OAuth callback query strings containing authorization codes are **never logged in the pilot Nginx access-log format**. Security responses are 503/no-store, never identity/secret-specific.

## First manual stage-login acceptance (owner confirmation required)

1. Deploy the PR-merged `main` to **staging only, while all Web/OAuth/MCP switches remain OFF**. Prove `/up=200`, `/login=503`, `/account=503`, `/api/mcp=503`, `/`=503. Record stage SHA. Do not redeploy production.
2. In isolated stage environment only, set `CANOVIA_STAGING_LOGIN_PILOT_ENABLED=true`, `CANOVIA_STAGING_WEB_ACCESS_ENABLED=true`, `CANOVIA_STAGING_WEB_ACCESS_EXPLICITLY_APPROVED=true`. Keep `CANOVIA_STAGING_ACCOUNT_LINK_PILOT_ENABLED=false`, all discovery/JWT/link/Plan/MCP flags OFF and the already rotated synthetic password **cleared from Render**. Auth0 issuer/IDs/secrets remain unchanged in private Render settings.
3. Render may deploy on env updates even with autoDeploy=off. Watch and record **one** stage deployment to Live; stop and roll back on any failed boot/health. Check unauth `GET /login` allowed, `GET /account` redirects to login, but `GET /register`, `/`, `/api/mcp`, `/.well-known/*` remain blocked. Never put tokens/credentials in logs or chat.
4. The owner signs in manually through the staging login form using the **synthetic email above** and the **rotated password stored in the owner's password manager**. The result must redirect to `/account`. No real-person email, account or production credential is used.
5. If the login fails, leave link/MCP flags OFF, inspect only sanitized status categories and close stage before changing any OAuth configuration.

## Subsequent, separate acceptance

Only after step 4 and fresh review of Auth0 callback, issuer, strict audience, signed JWT, approved `tpc_` client, API scope grants, PKCE, and CSRF, consider switching `CANOVIA_STAGING_ACCOUNT_LINK_PILOT_ENABLED=true` **plus required discovery/JWT/link flags**, still with MCP tools/Plan sharing OFF. Observe real Auth0 authorization and verify that its callback includes an exact `iss`. Do **not** conflate Canovia account-link callback with the separate ChatGPT callback; actual ChatGPT OAuth and bounded Plan grant are later gates.

Rollback stage immediately by setting `CANOVIA_STAGING_WEB_ACCESS_ENABLED=false`, `CANOVIA_STAGING_WEB_ACCESS_EXPLICITLY_APPROVED=false`, `CANOVIA_STAGING_LOGIN_PILOT_ENABLED=false`, `CANOVIA_STAGING_ACCOUNT_LINK_PILOT_ENABLED=false` while other OAuth/MCP switches stay OFF. The production service and DB remain unchanged.

Related: [Phase-1 read acceptance](MCP_CHATGPT_PHASE1_DEVELOPMENT_READ_2026_10_11.md), [Auth0 wiring operator guide](MCP_AUTH0_STAGING_WIRING_OPERATOR_2026_10_12.md), [callback/scope acceptance](MCP_CHATGPT_AUTH0_CALLBACK_SCOPE_ACCEPTANCE_2026_10_13.md), issue #462.

## 2026-10-13 pilot UI verification after successful synthetic login

The owner manually signed in and confirmed login succeeded in the isolated stage. The first screenshot showed the regular application layout without CSS, and staging access logs showed multiple denied GETs alongside successful login GET 200. The original full layout depended on normal application assets and background route surfaces intentionally blocked by the synthetic-only Nginx allowlist. The fix serves two standalone staging-only views with embedded CSS: `auth.staging-pilot-login` and `auth.staging-pilot-account`; neither uses `@vite`, the production shell, service worker, PWA or external links. The stage account view is deliberately limited to the fixed synthetic user's identifier, link/confirmation status and logout; ordinary Canovia Plan or account deletion content is never rendered. Log out returns to `/login`, never the blocked `/`. Stage Nginx denies `/build/` entirely after this change. No production UI code or production config is changed.

Keep signed Auth0/JWT/link/discovery OFF until the next, independent staged link test is approved; staged UI work **does not** authenticate an Auth0 identity. Deploy only to staging in first sealed mode, then re-enable only the existing pilot flags as reviewed. The owner may need to sign in again after stage redeploy because isolated file sessions may restart. Never record the rotated password or Auth0 authorization response code in chat or GitHub.

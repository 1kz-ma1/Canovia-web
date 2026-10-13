# Canovia MCP — isolated synthetic Development Plan consent pilot (2026-10-13)

Status: **Implementation only; OFF by default. No staging deployment or environment activation in this PR.** The owner manually completed the isolated Canovia login and Auth0 subject-link confirmation. This does not prove ChatGPT OAuth or MCP reads.

## Scope and boundaries

- Target ONLY the existing synthetic user `mcp-synthetic-owner@canovia.invalid` and one private noncollaborative Development Plan with the exact `McpStagingSyntheticPlanFixtureBootstrap::REQUEST_ID`, title and owner. No real user Plan or Task enters the isolated database.
- Owner chooses `overview` or `tasks` with expiry 1/7/30 days. Each grant requires **fresh** Auth0 Authorization Code + PKCE S256 for the same linked external subject and a separate owner confirmation within five minutes.
- Start, callback, confirmation, grant transaction and revocation enforce the approved fixture. Database locks and live ownership/collaboration checks prevent confirming a different or transferred Plan.
- A linked identity is NOT consent. A prepared preference is NOT consent. Scope downgrade/renewal requires a fresh OAuth and explicit confirmation. Revoked grants cannot authorize a read.
- Nginx still refuses `/api/mcp`, `/.well-known/*`, registration and the normal Canovia app. Laravel stage policy independently allows only login/account, IdP link, the bounded Plan consent actions and owner grant DELETE. The standalone UI uses no external JS, PWA or normal product assets.
- **MCP reads remain OFF:** `CANOVIA_MCP_TOOLS_ENABLED=false` and `CANOVIA_MCP_DELEGATED_POLICY_ENABLED=false`. Enabling consent does not establish ChatGPT OAuth or open any HTTP MCP route.
- Only stage in Render `My Workspace` and isolated staging Postgres can be affected by future separately approved activation. No production, Aiven, billing, Render service changes or secrets are changed by this implementation.

## Independent stage operator switches

Only after **separate approval**, retain the previous Login/Link/Web/DB configuration, and enable BOTH of these additional flags on the *staging service only*:

- `CANOVIA_STAGING_PLAN_CONSENT_PILOT_ENABLED=true`
- `CANOVIA_MCP_PLAN_CONSENT_ENABLED=true`

Required unchanged stage flags: `CANOVIA_STAGING_LOGIN_PILOT_ENABLED=true`, `CANOVIA_STAGING_ACCOUNT_LINK_PILOT_ENABLED=true`, `CANOVIA_MCP_DISCOVERY_ENABLED=true`, `CANOVIA_MCP_ACCOUNT_LINK_ENABLED=true`, `CANOVIA_MCP_AUTH0_JWT_VERIFICATION_ENABLED=true`, `CANOVIA_MCP_TOKEN_VERIFICATION_DRIVER=auth0_jwt`; keep `CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED=false`, `CANOVIA_MCP_DELEGATED_POLICY_ENABLED=false`, and `CANOVIA_MCP_TOOLS_ENABLED=false`. The existing staging-specific public Web flags, pinned PostgreSQL and private Auth0 client/keys are also mandatory. Never paste secrets in GitHub or a chat.

Both consent switches must match. A consent switch ON alone blocks the linking/consent UI and causes a Docker boot failure; the new flag ON with link pilot OFF also fails. The URL route allowlist is method-specific and the Plan-ID is checked against the fixture inside each controller action and again before the database grant.

## Acceptance before any activation

1. Relevant staged feature tests, startup guard negative matrix, PHPUnit and Docker CI green on the exact reviewed SHA; mocked OAuth is not real IdP acceptance.
2. Separate explicit owner approval for staging-only deploy of a pinned SHA; verify stage health and `/api/mcp`/normal application lockdown.
3. Separate explicit owner approval to change ONLY the two new stage flags; after Live, the existing linked synthetic actor should see the immutable synthetic Plan consent card, not other Plans.
4. Owner selects scope/1–7–30-day period, performs fresh Auth0 OAuth and individually confirms. Verify only one metadata-only grant and audit row for the fixture, without exposing codes, tokens, fingerprints or secrets.
5. Negative manual acceptance: tampered Plan, wrong actor/client/subject, expired state, unapproved scope, replay, cancelled consent, revoke and lost ownership; confirm MCP GET and POST still blocked even with a token.
6. Independent later gate: real ChatGPT OAuth client `tpc_` proof, RFC9207 issuer `iss`, exact JWT single audience, scope, PKCE, actual `tools/list` and synthetic Plan reads/revocation. Do not activate the MCP route in this pilot.

## Rollback

While the consent pilot is still accessible, revoke the synthetic grant with the owner-only action. Reset BOTH new flags to `false` in staging and verify the resulting deployment Live. Previously stored grants remain inert because MCP tools/delegated policy are OFF. Do not alter production or the Auth0 app-wide social connection.

References: [staging login pilot](MCP_STAGING_LOGIN_PILOT_2026_10_13.md), [explicit Plan consent](MCP_EXPLICIT_PLAN_CONSENT_CONTRACT.md), [ChatGPT Phase 1](MCP_CHATGPT_PHASE1_DEVELOPMENT_READ_2026_10_11.md), [Auth0 callback and scopes](MCP_CHATGPT_AUTH0_CALLBACK_SCOPE_ACCEPTANCE_2026_10_13.md).

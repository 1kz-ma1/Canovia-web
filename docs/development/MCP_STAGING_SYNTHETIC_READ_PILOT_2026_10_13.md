# Staging synthetic MCP read pilot — implementation and activation gate

Date: 2026-10-13 · Owner lane: Platform · Status: implementation only / OFF by default

## Confirmed before this PR

On the real isolated Render stage, the synthetic owner completed Auth0/GitHub identity linking, explicit per-Plan consent, and subsequent revocation. **The grant was revoked.** Real ChatGPT third-party OAuth and any MCP tools/call remain unverified.

PR #546 already upgraded `McpDelegatedPlanAccessPolicy` to accept a cryptographically verified `auth0_jwt` principal with an additional strict single-synthetic-Plan restriction under `APP_ENV=staging`.

## One new independent approval

`CANOVIA_STAGING_SYNTHETIC_READ_PILOT_ENABLED=false` by default.

When disabled, existing stage login/consent works with tools/delegated policy both OFF and the original Nginx config. The new feature is *not* activated by merging code, by earlier consent, or by `CANOVIA_STAGING_MCP_EXPLICITLY_APPROVED` alone.

Only after separate owner approval for this exact isolated stage and pinned, tested deploy may **all** required conditions be enabled:

- `CANOVIA_STAGING_SYNTHETIC_READ_PILOT_ENABLED=true`
- `CANOVIA_STAGING_MCP_EXPLICITLY_APPROVED=true`
- `CANOVIA_MCP_TOOLS_ENABLED=true`
- `CANOVIA_MCP_DELEGATED_POLICY_ENABLED=true`
- Existing approved stage Web/Login/Link/Plan-consent flags all true; pinned Render PostgreSQL and isolated marker remain enforced; `CANOVIA_MCP_PLAN_CONSENT_ENABLED=true`.
- Existing Auth0 RS256/JWKS verifier remains exclusively `auth0_jwt`; introspection stays OFF. Do not update client, audience or secrets based on guessed claims.
- **Do not change Render flags until a separately reviewed, explicit owner approval**. Partial/mismatched switches are rejected at shell boot and Laravel middleware.

## Exact independent HTTP allowlist

- Only `POST /api/mcp` reaches the stateless bearer-verified MCP controller. `GET/HEAD/DELETE /api/mcp` do not.
- Only `GET/HEAD /.well-known/oauth-protected-resource` and `GET/HEAD /.well-known/oauth-protected-resource/api/mcp` expose non-private OAuth resource discovery metadata.
- Existing session-only synthetic login, account, Auth0 callback, consent and grant revocation paths are preserved.
- The separate Nginx config blocks registration, normal Canovia pages, all other API paths and static/PWA assets. The Laravel global middleware independently checks a matching method/path and verified pinned PostgreSQL.
- The bearer must pass exact signature, issuer, single audience, actual allowlisted Auth0 third-party client identity and read scope before returning even `tools/list`. A verified token alone does not authorize Plan data.
- `tools/call` uses explicit `plan_id`, scope `overview` or `tasks`, limit <= 8. The downstream policy accepts only the pinned private synthetic Plan and the linked actor with a fresh, unexpired, independently granted scope. It never exports descriptions, evidence, private credentials or another Plan.
- OAuth callback URL codes and bearer tokens are not included in Nginx logs.

## Acceptance steps after CI and merge

1. Review GitHub PR, required CI including startup guard tests, exact pinned stage target and user authorization. Never deploy moving `main`; deploy one approved SHA only.
2. On the stage with existing MCP switches OFF confirm original login/consent still works and `POST /api/mcp` remains 503 (Nginx). No new external connectivity before explicit approval.
3. **Separate activation approval:** after positive and negative tests, enable the four switches above together. If startup fails, return to previous OFF state; do not remove any guard.
4. GET resource metadata should return a non-private issuer/scope document; anonymous POST MCP returns 401 `WWW-Authenticate`, not any Plan data. POST with forged or missing token denies. Unrelated site paths still blocked.
5. Inspect actual ChatGPT OAuth connection's imported Auth0 `tpc_` client, exact callback and supported scopes; verify signed token client/audience/scope server-side, never paste token/JWT or callback URL in chat. A matching client name alone is insufficient.
6. Re-consent the *previously revoked* synthetic Plan for **overview, 1 day**, then call `initialize`, `tools/list`, and `tools/call` from the actual ChatGPT connection using the exact synthetic `plan_id` displayed in the staging account UI; verify the single Plan title and no Tasks in overview scope.
7. Fresh consent to `tasks, 1 day` if specifically approved; verify only the two invented Task titles/status/progress, maximum eight; negative on wrong Plan, wrong scope and token.
8. Revoke from the stage account UI, repeat the same real `tools/call` and confirm denial. No silent reconnection or granting after revocation.
9. Record exact CI SHA, Render live SHA, stage negative and positive HTTP results, and actual ChatGPT read+revocation evidence. Mock tests, signed local JWT tests and Render health alone are **not E2E success**.

## Rollback and boundaries

Revoke the synthetic grant first; switch the new read flag and both MCP tools/policy flags OFF together, retain independent login/Plan consent flags. Redeploy the previous reviewed stage SHA as needed. Render Auto Deploy stays OFF. Production Canovia, Aiven MySQL, billing, real user Plans and real ChatGPT user data are out of scope. No automatic Plan discovery, edits or mutations.

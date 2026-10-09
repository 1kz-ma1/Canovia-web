# MCP staging — synthetic read fixture and next OAuth acceptance

Updated: 2026-10-09. **Implementation and acceptance are separate.**
Owner policy: keep production Canovia running; do not change production
service/DB, login, credentials or billing to prepare this fixture.

## Known, pinned boundaries

- Render workspace: **My Workspace** (`tea-d4vb3f6mcj7s73djkrqg`).
- Production Web: `Canovia` (`srv-dagfn2id0e5s73cac7ng`), production Aiven
  MySQL database name **`pacekeeper`**. This is NOT `defaultdb`. The
  incorrect name caused valid legacy sign-ins to be rejected on 2026-10-09;
  restoring `pacekeeper` recovered them. Never copy a staging DB URL,
  a Postgres driver or staging APP_KEY into production.
- Staging Web: `canovia-mcp-staging` (`srv-db43l4nlk1mc73emseig`),
  `https://canovia-mcp-staging.onrender.com`, no auto deploy.
- Staging database: isolated Render PostgreSQL 17,
  `dpg-db43rbbncjis73bmigi0-a`, database
  `canovia_mcp_staging_db`. The existing staging service is connected:
  previous real startup migration and HTTPS readiness evidence passed.
  External DB IP allowlist stays empty.
- **All stage Web access, OAuth and MCP read switches remain OFF by default.**
  The public site only responds to the `/up` health probe. Never set
  public-access flags merely to make a test pass.
- Free PostgreSQL expires **2026-11-08**; no Free backups. Do not use it for
  valuable or personal data, and do not assume tests survive its expiry.

## Implemented: private, deterministic fixture — not yet live acceptance

The existing `canovia:mcp-staging-create-synthetic-owner` command creates
one fixed synthetic user only with the approved, closed, pinned database
and a private high-entropy staging password. It does not create OAuth
links or grants.

`canovia:mcp-staging-create-synthetic-plan --json` now creates exactly
one private, noncollaborative Plan and two synthetic Tasks for that user
**only after the owner already exists**. The Plan uses a fixed UUID in
`creation_request_id` for idempotency. Its title and task titles are
invented; no personal or production content is copied.

- Production, a non-pinned DB, unexpected identity/Plan/Task data,
  an open staging Web, or active MCP tools fail closed.
- An exact rerun returns `already_present` and does not update data.
  Unexpected existing fixture content blocks instead of being overwritten.
- The CLI prints only `blocked`, `created` or `already_present`;
  never prints user email, Plan ID, owner token or private password.
- It does not create IdP identities, external subjects, authorization
  codes, bearer tokens, consent, delegated grants, or audit records.
- **Code merge is not approval or proof of live fixture creation.**

An operator may deliberately enable one *closed staging* boot with the
following flags, after verifying actual stage service identity and existing
PostgreSQL config. Set the synthetic password privately in Render; never
include it in source code, terminal transcripts or chat.

```env
CANOVIA_STAGING_SYNTHETIC_OWNER_BOOTSTRAP_ON_START=true
CANOVIA_STAGING_ALLOW_SYNTHETIC_OWNER_BOOTSTRAP=true
CANOVIA_STAGING_SYNTHETIC_PLAN_FIXTURE_ON_START=true
# CANOVIA_STAGING_SYNTHETIC_OWNER_PASSWORD=<private, fresh, 24–128 chars>
```

This requires `APP_ENV=staging`, `CANOVIA_STAGING_ISOLATED=true`,
`CANOVIA_STAGING_DB_MODE=render_postgres`, a pinned private PostgreSQL
connection, both staging Web-access switches `false`, and every
MCP tool/authentication switch still OFF. Set startup flags back to
`false` and remove the private bootstrap password after the one-shot
staging boot. No staging Environment flag is changed by this PR.

Before a live one-shot boot, verify the deployment's exact SHA, current
flag values through the private Render dashboard, and ensure the startup
guard rejects any production-like configuration. Do not enable this
on the production Web service or during a staging public access experiment.

## Next acceptance gate: real ChatGPT OAuth test (not completed)

1. Confirm the synthetic-only fixture exists using a **private,
   read-only stage-local** check, without exposing account data publicly.
2. Select and provision a compatible IdP (Keycloak is a candidate, not
   configured). Evaluate resource audience, RFC 7662 introspection,
   PKCE, issuer, discovery, supported client registration and cost.
3. Register separate Canovia account-link and ChatGPT clients with
   the **actual** ChatGPT callback URI, and verify issuer, audience,
   client ID, scope and token expiration using test-only subjects.
4. Obtain deliberate, scoped same-subject per-Plan consent for the
   synthetic Plan; run MCP initialize / tools/list / tools/call.
5. Validate wrong-user Plan, disabled gates, revoked grant, expired
   token, missing scope and forged token all fail closed. Validate
   read-only payload fields, audit without personal data and rollback.
6. Public release is a separate decision: no user Plan access
   or production MCP switch until security and release checks pass.

## Costs and release gate

The owner will **upgrade paid services when actually required**, not
as part of this fixture PR. At the final **pre-publication launch readiness
review**, recheck Render's live pricing/free-hour limits, whether the
production Web needs the smallest paid compute, staging/IdP costs and
storage/backups, actual mail delivery, session reliability, security,
rollback and user-facing end-to-end login. Obtain explicit owner approval
before changing a paid plan or incurring a new recurring charge.

The authoritative checklist is
[release environment/billing readiness](RELEASE_ENVIRONMENT_BILLING_READINESS_2026_10_09.md).

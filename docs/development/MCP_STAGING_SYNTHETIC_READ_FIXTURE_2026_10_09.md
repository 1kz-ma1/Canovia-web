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

## Live closed-stage acceptance (2026-10-09, later than the preparation notes above)

**The synthetic fixture is now provisioned on the existing isolated stage.**
After PR #424 merged as `a2d0ebdd3395b8a33329c9819746e072297a8744`:

1. Only `canovia-mcp-staging` in **My Workspace** was temporarily armed
   through its private Render environment, not the production service.
2. Deployment `dep-db49r860tbcc73dlmfmg` reached **Live**. Private logs
   confirmed pinned `render_postgres`, migrations, synthetic owner bootstrap
   completed, and synthetic private Plan fixture completed. Command outputs
   suppressed identity, passwords, IDs and Plan content.
3. Bootstrap approval, owner startup and Plan startup switches were reset
   to `false`; the private synthetic bootstrap password was cleared.
   Sealed follow-up deployment `dep-db49rrks728c73a36fkg` reached
   **Live** with no synthetic-bootstrap command on startup. Its logs showed
   migrations complete and `HEAD /` plus `GET /` blocked with HTTP 503.
4. **What this proves:** the initial creation commands exited successfully
   against isolated staging PostgreSQL and the stage remained sealed.
   **What it does not yet prove:** after-restart DB row integrity, a browser
   sign-in to the synthetic owner, external IdP identity association, token
   issuance, or any actual ChatGPT MCP read. No account-link or grant was
   intentionally authorized.

The synthetic account password used for initial provisioning is deliberately
not exposed or retained in source or chat, and its Render environment
variable is cleared. Before real browser/OAuth sign-in, establish a new
private password via a deliberately reviewed *staging-only* credential
rotation procedure; merely rerunning `firstOrCreate` does not change an
existing user's password.

### Read-only persistence check (subsequent PR)

The new CLI `canovia:mcp-staging-verify-synthetic-plan --json` needs no
bootstrap secret and prints only `ready` or `blocked`, with
`production_authorized=false`. It checks one fixed synthetic owner,
one private Plan and two exact Tasks, and rejects any foreign user,
unexpected data, tampered fixture, prior IdP association or delegated grant.
The validator runs only after strict staging/DB guards, and it never
mutates a row or discloses account information.

To verify persistence on the existing **closed staging** service, the
operator can temporarily set `CANOVIA_STAGING_VERIFY_SYNTHETIC_FIXTURE_ON_START=true`
and redeploy *only staging*. Startup logs report generic readiness and
fail-closed on any mismatch. Keep `CANOVIA_STAGING_WEB_ACCESS_ENABLED=false`
and every OAuth/MCP switch OFF. This switch does not enable public HTTP
access or send test user content to a third-party IdP.

Record the exact staging deployment/CI evidence after the first live check;
until then, treat this code and its CI as implementation, not live persisted
fixture verification.

## Verified sealed-stage persistence acceptance (2026-10-09, latest)

**ACCEPTED: synthetic owner, Plan and Tasks persisted through the sealed stage
restart. This supersedes earlier "not yet checked" notes above.**

- [PR #426](https://github.com/1kz-ma1/Canovia-web/pull/426):
  branch tests / migration regression / isolated PostgreSQL Docker smoke all
  passed; squash-merged `6a69daa150de57435348c88b42f4f39929ccaec1`.
- On the actual *isolated* Render stage, one deliberately armed
  `CANOVIA_STAGING_VERIFY_SYNTHETIC_FIXTURE_ON_START=true` run used
  the new **read-only** `canovia:mcp-staging-verify-synthetic-plan --json`
  and emitted only `MCP isolated staging synthetic fixture verification:
  ready (details suppressed)`. Stage deploy
  `dep-db4a1j3bc2fs73b53ds0` finished **Live** at
  `2026-10-09T08:08:37Z`.
- This verifier requires the exact pinned PostgreSQL and private
  staging configuration. It checks one fixed synthetic owner, the private
  Plan, two expected Tasks and absence of unrelated account or
  delegated consent/grant/audit rows. It does **not** disclose IDs,
  password hashes, tokens or Plan text.
- The verification boot switch was subsequently returned to `false`.
  Original owner/Plan creation flags and temporary provisioning password
  were already cleared and remain OFF/empty.
- No change to production Aiven MySQL `pacekeeper`, environment variables,
  Render compute plan or production MCP exposure was made.

**Still pending:** a supported external OAuth IdP and its real registered
clients; staging-only synthetic-user credential rotation before browser
sign-in (the initial secret was intentionally cleared); token introspection
and exact audience; same-subject Plan consent; actual ChatGPT tool invocation.
Do not infer completion from the fixture acceptance.

For the staged IdP pilot, Keycloak's official documentation supports
resource-indicator audience binding only with the experimental
`resource-indicators` feature enabled and an MCP resource client whose
`resource_url` matches the exact protected resource. It also supports
DCR, while CIMD is experimental. Verify actual token claims in a disposable
provider experiment before any Web/MCP gate is opened:
https://www.keycloak.org/securing-apps/mcp-authz-server

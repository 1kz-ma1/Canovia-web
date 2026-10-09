# Canovia / ChatGPT MCP — OAuth provider decision and staging Postgres

Date: 2026-10-09
Decision: **Keycloak is a conditional technical frontrunner, not yet a configured IdP**.
Stage DB: **Render Free Postgres created and available; app NOT connected yet**.

## Why provider selection is a hard compatibility gate

Existing Canovia resource configuration requires all of the following:

- Authorization Code + PKCE S256, RFC 8414 OAuth metadata with exact configured issuer, RFC 9207 callback `iss` on success **and error**, and separate confidential Canovia account-link OAuth client.
- ChatGPT OAuth client registration by CIMD, DCR or reviewed pre-registration. Record the **real issued client ID**, not a guessed ChatGPT URL.
- Proper RFC 8707 `resource` audience: the **single exact** resource identifier `https://canovia-mcp-staging.onrender.com/api/mcp`.
- RFC 7662 introspection responding with `active=true`, `iss`, immutable `sub`, exact `client_id`, the single resource `aud`, bearer type, `exp` <= 1 hour, and precisely `canovia.development.read`.
- Stable `issuer+sub` shared across the Canovia confidential account-linking client and the ChatGPT connection.
- Current, separately approved personal Development Plan `overview` or `tasks` grant, with owner, expiry and revocation checks on **every** call.

These conditions are not to be weakened merely because a specific identity provider
returns a different claim set. The existing preflight checks metadata only; it
does not make claims about real issued access tokens.

### Candidate comparison (published platform documentation)

| Provider | Relevant support | Blocking risk / outcome |
|---|---|---|
| **Keycloak** | Official MCP integration instructions; RFC 7662 introspection, RFC 7591 DCR, RFC 9207 issuer, experimental RFC 8707 resource-indicators and CIMD. Enabling resource-indicators and configuring `resource_url` can produce exact `aud` binding. | **Conditional technical frontrunner.** Keycloak is self-hosted; official container guidance suggests >=750 MiB RAM (2 GiB production recommended), making a 512 MiB Free Web instance an unsafe assumption. Experimental MCP features must be pinned to a tested release, not `latest`. **Not deployed.** |
| Auth0 | Auth0 for MCP/CIMD integration guidance and a Resource Parameter Compatibility Profile for `resource` audience. | The current **RFC 7662-only Canovia verifier** is not a guaranteed match. Historical Auth0 documentation/community answers explain that JWT/JWKS verification is its primary API bearer validation model. Do **not** substitute an unreviewed hand-built JWT validator or assume introspection exists for the chosen tenant. |
| ZITADEL | Official RFC 7591 DCR and RFC 7662 introspection with confidential resource client auth. | ZITADEL's documented DCR implementation **ignores the `resource` parameter for `aud`**, and DCR-project JWT audiences include multiple client IDs. This conflicts with Canovia's exact, single resource `aud` requirement. Do not enable a weaker audience check to use this provider. |

**Decision:** prioritize *Keycloak* for a separately reviewed isolated IdP pilot
**only after** confirming hosting costs and resources. Do not create another
Render Free Web service, start an insecure development-mode public Keycloak,
open DCR registration globally, or change real Canovia OAuth env flags yet.
The current Canovia staging service will remain closed until identity
boundaries, synthetic users and token contract have been tested.

Primary sources:

- https://www.keycloak.org/securing-apps/mcp-authz-server
- https://www.keycloak.org/securing-apps/oidc-layers
- https://www.keycloak.org/server/containers
- https://auth0.com/docs/api/management/v2/clients/post-clients-cimd-register
- https://support.auth0.com/center/s/article/mcp-audience-error-with-auth0
- https://zitadel.com/docs/guides/integrate/dynamic-client-registration
- https://developers.openai.com/plugins/build/auth

## Independently provisioned stage DB (confirmed)

- Render database: `canovia-mcp-staging-db`, id `dpg-db43rbbncjis73bmigi0-a`
- Region: **Singapore**, plan: **Free**, PostgreSQL 17, dedicated database `canovia_mcp_staging_db`
- Owner workspace: same as staging Canovia Web, *not* Aiven production
- Render status: **available** at last check
- **Hard expiration: 2026-11-08 UTC**, and a grace period to upgrade before deletion. There is no Free backup/snapshot. Use **synthetic data only**, not any important records.
- The DB's external IP allowlist is empty; its external connections are blocked. Keep it that way. Render's external read-only DB connector also cannot access it until an IP allowlist is changed—**do not widen access**.
- It has **not** been attached to Canovia Web. The existing staging Web is still on disposable SQLite, with closed /login/OAuth/MCP surfaces. **No Postgres migration has run.**
- The staging Dockerfile now supports `pdo_pgsql` and a separate, OFF-by-default `CANOVIA_STAGING_DB_MODE=render_postgres` guard. The guard accepts only the exact **private internal** resource hostname, verified database name/user, valid PostgreSQL URL, and pinned resource ID. Other PostgreSQL endpoints, DB URLs, production Aiven and mismatched DB settings cause exit 42 **before migrations**.

## Safely attach the private DB (NOT COMPLETED)

Connection credentials are deliberately not available through the current connected
Render database metadata action. **Never paste a DB password or complete URL into
GitHub, ChatGPT, commits, screenshots, shell command arguments or deploy logs.**

1. In the [Postgres Dashboard](https://dashboard.render.com/d/dpg-db43rbbncjis73bmigi0-a) use **Connect → Internal Database URL**. Verify the hostname is exactly `dpg-db43rbbncjis73bmigi0-a` and the database name is `canovia_mcp_staging_db`. If Render issued a *different* internal hostname, **stop and update the pinned guard by reviewed PR; do not bypass it**.
2. After the PR implementing the pinned guard and `pdo_pgsql` has passed CI and merged, go to the [Canovia staging Web → Environment](https://dashboard.render.com/web/srv-db43l4nlk1mc73emseig). Set **only that staging service** `DB_URL` to the internal URL in the private Render UI; set `DB_CONNECTION=pgsql`, `DB_DATABASE=canovia_mcp_staging_db`, `CANOVIA_STAGING_DB_MODE=render_postgres`, `CANOVIA_STAGING_POSTGRES_ID=dpg-db43rbbncjis73bmigi0-a`. Leave `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`, `DATABASE_URL` and production integration secrets absent.
3. Keep `CANOVIA_STAGING_WEB_ACCESS_ENABLED=false` and every MCP/OAuth flag OFF. Never import Aiven data or a production Laravel APP_KEY.
4. **Manual deploy**, since the staging service has autoDeploy OFF. Do **not** trigger a deploy while the DB connection variables are only partially set. If the guard rejects any value, leave staging closed, investigate the DB URL **privately**, and do not weaken the host policy.
5. In Render logs verify migrations complete without connection details printed; `/up=200`, login/OAuth/MCP GET/POST=503 from independent GitHub Actions. On the DB Dashboard, inspect table creation for the synthetic schema only.
6. To roll back, use staging-only environment variables to restore the original disposable SQLite mode, unset `DB_URL`, and manually redeploy. Do not alter any production Render or Aiven service.
7. After the Web is successfully connected to the pinned staging DB (with all HTTP except /up closed), use the new opt-in CLI `php artisan canovia:mcp-staging-create-synthetic-owner --json` **only inside the staging runtime**. Its separate `CANOVIA_STAGING_ALLOW_SYNTHETIC_OWNER_BOOTSTRAP=true` switch and `CANOVIA_STAGING_SYNTHETIC_OWNER_PASSWORD` (random, at least 24 characters) must be supplied via private environment; never CLI args, logs or chat. The CLI refuses production, open staging, other databases and weak credentials. It creates only `mcp-synthetic-owner@canovia.invalid` with an irreversible password hash and no linked external identity or Plan grants. Remove the bootstrap flag and password after use. This is **implemented but not yet executed on real Render**; the connected Render tools do not provide an application shell to run the CLI. Do not enable public registration to work around that limitation.
8. Track **2026-11-08** expiration before deciding on a paid DB or replacement. No automatic extension, deletion or payment has been scheduled.

The connection cannot be done by inventing a Postgres URL: the Render
metadata connector intentionally omits passwords and external-connection
allowlists are closed. A secure Render-native internal environment-variable
reference can also be used if the stage is later managed as a Blueprint,
without changing production.

## Real OAuth test acceptance: still pending

Before promoting any provider, run private staging token experiments with
the actual two OAuth clients and both tokens. Verify the client-id and
single `aud` for each callback/introspection response, PKCE, state, issuer,
scope, expiry, revocation, consent narrowing and cross-account denial.
Proof must use the *real* IdP server rather than GitHub CI mocks.
ChatGPT's actual client registration and connector activation remain
a separate gate. Until then, `CANOVIA_MCP_TOOLS_ENABLED=false`.


## Disposable PostgreSQL migration compatibility gate

A dedicated GitHub Actions step now boots `postgres:17-alpine` on a
private ephemeral Docker network alongside the **actual** built Canovia
staging PHP image, then applies all Laravel migrations twice and asserts
that the user/Plan/MCP subject/grant/audit tables exist. This catches
DB portability issues before attaching the dedicated Render PostgreSQL.
The step bypasses the **instance-specific startup host guard only inside CI**
using entirely fake credentials and `APP_ENV=testing` to target its own
local container. It has **no connection to** the created Render staging DB,
production Aiven DB or Render app secrets; the temporary DB is deleted
after each run. The actual Render DB still requires a separate private
staging env update and security review.


## PostgreSQL-only public health gate (pending release)

The isolated staging `/up` handler now reports HTTP **200** only when
all of the following are true in PostgreSQL mode:

- `APP_ENV=staging` + `CANOVIA_STAGING_ISOLATED=true`.
- `CANOVIA_STAGING_DB_MODE=render_postgres`, exact known resource ID
  `dpg-db43rbbncjis73bmigi0-a` and the pinned private internal
  hostname/user/database matching the hard fail-closed boot contract.
- The actual database answers `SELECT current_database()` with
  `canovia_mcp_staging_db`.
- Required migrated tables `migrations`, `users`, `plans`,
  `mcp_linked_subjects`, `mcp_delegated_grants` and
  `mcp_delegated_access_events` all exist in `public`.

Any mismatch, connection error or missing migration returns the same
**503 Staging service unavailable** response with `no-store`, never
the hostname, DB URL, password, SQL exception or schema content.
The default SQLite stage remains `/up=200`; production is unaffected.

The GitHub Actions staging Docker smoke also starts a **real isolated
staging app image with PostgreSQL** on a disposable Docker network,
using the exact pinned synthetic hostname/DB name/user and an explicitly
fake password. Its shell startup guard must pass *without bypasses*,
its migrations/DB health check must pass, and login/OAuth/MCP must
still return HTTP 503. That is still **not a live connection** to
the created Render PostgreSQL; the actual internal URL must be set
privately in the Render staging Environment first.

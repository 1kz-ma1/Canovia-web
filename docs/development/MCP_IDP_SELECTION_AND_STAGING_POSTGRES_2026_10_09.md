# Canovia / ChatGPT MCP — OAuth provider decision and staging Postgres

Date: 2026-10-09
Decision: **Keycloak is a conditional technical frontrunner, not yet a configured IdP**.
Stage DB: **Existing isolated Render Free PostgreSQL 17 connected to staging Web; live DB/schema health and external lockdown accepted on 2026-10-09**.

## CURRENT acceptance — live isolated PostgreSQL cutover (2026-10-09)

The existing **Free** Web service `srv-db43l4nlk1mc73emseig` is now
running against the existing **Free PostgreSQL 17** resource
`dpg-db43rbbncjis73bmigi0-a` in Singapore. This replaces the earlier
SQLite-only status described in the historical preparation steps below.
The environment update and deploy were **staging only**, with no
new Render resource, external IP allowance, new paid plan, Aiven change,
production secret, synthetic owner, IdP tenant or OAuth/MCP enablement.

- Stage deployment: `dep-db460sflk1mc73ev4g3g`,
  commit `f2f969aa43d2c2a58e0030db727a195343cc5b2e`;
  Render confirmed **live**.
- Sanitized boot log confirmed
  `configured database mode=render_postgres (connection not yet verified)`
  and `MCP isolated staging migrations: completed (details suppressed)`.
- The [external HTTPS acceptance workflow](https://github.com/1kz-ma1/Canovia-web/actions/runs/37875765667)
  was **rerun following this deployment** and completed successfully.
  `/up=200` depends on a live connection, exact database identity and
  six required migration tables in PostgreSQL mode, so the successful
  independent probe is additional proof beyond the boot marker.
- Public login, account, OAuth, MCP GET/POST, legacy health and static
  asset probes all remained **503** with generic non-cached responses.
- The earlier attempts failed closed at boot for mismatched
  `DB_DATABASE`, then missing `DB_URL`, then missing
  `CANOVIA_STAGING_POSTGRES_USER`. The name was corrected using
  nonsecret Render metadata; the user entered the **private** internal
  database URL directly in Render Environment. No secret value is
  included in this document or GitHub.
- The existing DB's external IP allowlist stays empty. Metadata
  inspection confirmed PostgreSQL was available; no public SQL access
  or table browsing was used. **Do not infer created synthetic users or
  successful end-to-end OAuth from this acceptance.**

**Remaining gates:** safely provision a synthetic-only owner inside
the isolated app without opening registration; select and verify a
real IdP/ChatGPT client registration, PKCE, exact audience and introspection;
independently review access before enabling any browser/OAuth/MCP flags.
Keep both staging Web access flags and all OAuth/MCP switches **OFF**.
Free PostgreSQL expiry remains **2026-11-08 UTC**, with no free backups.
Do not store important or production data in this environment.

**Historical note:** headings and statements below dated before this
cutover preserve implementation reasoning and are not the current live
status. The earlier manual attachment steps have now been executed.


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
2. After the PR implementing the pinned guard and `pdo_pgsql` has passed CI and merged, go to the [Canovia staging Web → Environment](https://dashboard.render.com/web/srv-db43l4nlk1mc73emseig). Set **only that staging service** `DB_URL` to the internal URL and `CANOVIA_STAGING_POSTGRES_USER` to the matching actual DB username using Render's private UI (ideally the two Render-native `fromDatabase` references described below); set `DB_CONNECTION=pgsql`, `DB_DATABASE=canovia_mcp_staging_db`, `CANOVIA_STAGING_DB_MODE=render_postgres`, `CANOVIA_STAGING_POSTGRES_ID=dpg-db43rbbncjis73bmigi0-a`. Leave `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`, `DATABASE_URL` and production integration secrets absent.
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


## Secretless, Render-native staging DB attachment (2026-10-09, prepared — not applied)

Render's official Blueprint `fromDatabase` references can retrieve the
**existing** database's internal `connectionString` and `user` without
copying either value to a GitHub repository, chat conversation or command
line. A manual-reference Blueprint is provided at
`deploy/render-mcp-staging-existing-postgres-cutover.yaml`.

At the time of PR #403, the literal staging DB username `canovia_mcp_staging_db_user`
was treated as an **unverified assumption**. A subsequent read-only Render
`get_postgres` metadata inspection on 2026-10-09 confirmed that the
existing resource `dpg-db43rbbncjis73bmigi0-a` reports
`databaseUser=canovia_mcp_staging_db_user`, alongside
`databaseName=canovia_mcp_staging_db`, region Singapore, PostgreSQL 17,
Free plan, and status available. This metadata corroborates identity but
**does not** expose or validate the private internal URL or establish
that the Web app is connected. To prevent
mistaking a guess for evidence, the startup guard, staging HTTP `/up`
health check and synthetic-only account bootstrap now require an
independent `CANOVIA_STAGING_POSTGRES_USER` value to exactly match the
`user` in the configured `DB_URL`. Prefer Render's live
`fromDatabase: {name: canovia-mcp-staging-db, property: user}` reference
to supply this value, rather than copying a literal identity. The host/resource ID, scheme, DB name, port,
absence of query/fragment, DB isolation and closed MCP gates remain enforced.

**Important: this is only a reference; do not blindly create a new
Blueprint.** Render may replicate resources with suffixes if a newly
created Blueprint matches existing infrastructure. The only acceptable
preview identifies the **existing** staging Web service
`srv-db43l4nlk1mc73emseig` and the **existing** Free PostgreSQL
`dpg-db43rbbncjis73bmigi0-a`, and shows **no new Web/DB,
price upgrade, extra secrets, production service or automatic deploy**.
An existing Blueprint managing this service must be edited instead of
creating a second one. In Render, generate/import the existing service's
configuration and keep automatic Blueprint sync **disabled**. Compare
the prepared reference line by line and review the preview before
authorizing any sync. If the UI cannot guarantee this, use the existing
staging Web service's private Environment settings; **never** supply a
URL/credential through chat.

No Blueprint has been created/synced and no Render DB connection has been
changed by this PR. The standalone staging SQLite service remains live
and closed with `CANOVIA_STAGING_DB_MODE=sqlite`. The private Postgres
connection has not been tested on Render.

References:
- https://render.com/docs/blueprint-spec
- https://render.com/docs/infrastructure-as-code
- https://render.com/tutorials/postgres-on-render/connection-strings


## Read-only operational handoff after PR #403 (2026-10-09)

Read-only Render inspection confirmed:

- Existing Web service `srv-db43l4nlk1mc73emseig` remains **Free**, Docker,
  Singapore, `main`, and has automatic deploy **off**.
- Its latest deployment `dep-db44jqrbc2fs73ajkg20` for commit
  `51097fb8545f1da4d61b6386f63c1368faf7245d` reports **live**.
  This is a deployment status, **not** proof of a PostgreSQL connection.
- Existing PostgreSQL `dpg-db43rbbncjis73bmigi0-a` reports
  `databaseUser=canovia_mcp_staging_db_user`, version 17, Free, available,
  expiry **2026-11-08T01:04:45Z**, and an **empty external IP allowlist**.
- The Render service metadata interface does not report environment variable
  values, so the active `CANOVIA_STAGING_DB_MODE` and runtime HTTP results
  are **not freshly verified** by this read-only inspection.
- A separate public URL inspection did **not** successfully fetch the
  `/up` or `/login` pages. No live HTTP status is claimed from that attempt.

**Next gated operation:** review the existing service's Blueprint ownership
and any proposed changes before attempting `fromDatabase` references.
Do not create a second Blueprint or Web/DB service. If no safe existing-service
binding workflow can be proven, stop at the current isolated deployment and
use the private Render dashboard for a single coordinated environment update.
No credential, URL containing a password, production integration or
external IP allowlist change should enter GitHub or chat. Only after the
actual existing Web service is connected and manually redeployed should
an independent HTTP smoke check verify `/up=200` and `/login`, OAuth and
MCP access remain 503. If any gate fails, roll back to staging-only SQLite.

**Change scope:** this handoff is a documentation reconciliation; it has
not changed live Render environment settings or initiated a deploy.


## V59 staging web-access interlock (merged #405, CI passed / not deployed)

The isolated staging application remains **fully closed**. To prevent a
single accidentally enabled configuration key from exposing login, account
linking or the MCP resource, the shell startup guard and Laravel HTTP
middleware now jointly require **all** of the following before the
staging Web surface can open:

1. `CANOVIA_STAGING_ISOLATED=true` and `APP_ENV=staging`.
2. `CANOVIA_STAGING_WEB_ACCESS_ENABLED=true`, **and separately**
   `CANOVIA_STAGING_WEB_ACCESS_EXPLICITLY_APPROVED=true` after an
   operator-approved staging-specific review.
3. `CANOVIA_STAGING_DB_MODE=render_postgres` with exactly the pinned
   independent staging database identity; the HTTP middleware additionally
   verifies a live PostgreSQL connection and all required MCP migration
   tables. Disposable SQLite is **never eligible** for public Web access.

If any condition is absent, non-health endpoints remain HTTP 503.
The existing default `false` settings remain in both Render YAML
references, and neither Blueprint reference is automatically applied.
This is **not authorization to enable either flag**: IdP, synthetic account,
OAuth client registration, token verification, same-subject binding,
cross-account denial and external security acceptance remain separate gates.
Do not use the approval flag to bypass these prerequisites.

Existing live staging env values were not inspected or changed for this
PR; production code continues to bypass these rules unless its
`APP_ENV` equals `staging`. A full live/real-IdP security review is
required before any public access is permitted.


### V59 web-access interlock acceptance evidence (2026-10-09)

PR [#405](https://github.com/1kz-ma1/Canovia-web/pull/405) was
squash-merged as commit `795949915325b516bf9073a34e805814e5fa01ba`.
The PR-head GitHub Actions jobs all **completed successfully**:

- [MCP isolated staging Docker smoke](https://github.com/1kz-ma1/Canovia-web/actions/runs/37874300721): real staging image build, SQLite closed startup, disposable PostgreSQL 17 Laravel migration, pinned Postgres boot and health.
- [MCP live staging HTTP lockdown](https://github.com/1kz-ma1/Canovia-web/actions/runs/37874300807): existing deployed Render staging /up 200; public account, OAuth discovery/callback, MCP GET/POST closed at 503.
- [Production migration recovery](https://github.com/1kz-ma1/Canovia-web/actions/runs/37874300705): passed.
- [Workspace resume / MCP regression](https://github.com/1kz-ma1/Canovia-web/actions/runs/37874300740): passed, including `McpIsolatedStagingBootstrapTest`.

This evidence proves the *repository change* against tests and the
*previously deployed stage* remained closed. It is **not** evidence that
the updated Web-access interlock has deployed to Render or that the real
Render staging PostgreSQL is attached. The existing staging service has
auto deploy off; subsequent live post-deploy checks are required.


### Staging-only Nginx bypass closure (PR #407 — live redeploy pending)

After deploying #405/#406 to the existing Free Render stage
(`dep-db452ujtqb8s73e3so6g`, commit `4cf895d`, status `live`),
the expanded real-network HTTPS smoke confirmed `/up=200` and
`/=503`, but discovered `/health=204`. Root cause: the shared
production Nginx `docker/nginx.conf` serves `/health` directly
before Laravel's staging middleware. It also has static asset
locations that similarly bypass the Laravel gate. No private data
exposure was demonstrated, but this violated the previously stated
"only /up public" staging contract.

The isolated staging boot script now installs
`docker/nginx-mcp-staging-closed.conf` **only when public staging Web
access is disabled**. This Nginx config does not serve legacy health,
static files or PHP directly. It routes exact `/up` to Laravel for the
existing DB/schema readiness check; every other URL, including
`/health`, static files, and direct `/index.php`, receives the
generic `503 Staging service unavailable.` with `no-store`.
The ordinary production `docker/nginx.conf` is left untouched.
For a future **separately approved** public-stage OAuth experiment,
the pre-existing two-flag and pinned-Postgres startup/HTTP gates remain
mandatory; that future access must receive its own security review.

Regression gates: PHPUnit asserts the closed Nginx contract; the
isolated Docker smoke exercises both SQLite and pinned disposable
PostgreSQL 17 startup through actual Nginx; and the existing live HTTPS smoke confirms the previously closed
authentication/OAuth/MCP GET and POST surfaces while the old staging image
remains deployed. Since the existing image is known to expose the old
`/health=204` behavior, the expanded **static and legacy-health HTTPS
checks must be run only after the patched image is deployed** as a
separate follow-up PR. This two-phase release does not treat the
pre-deployment smoke PASS as evidence that the Nginx bypass is fixed.
**This file update is not proof of deployment of the fix**.
Do not mark the live stage fully closed until the amended commit
deploys and the post-deploy HTTPS checks pass.


### Post-deploy Nginx closure accepted (2026-10-09)

The existing Free staging Web service ran commit `5e8915b` (#407) as
Render deploy `dep-db457g3l550s73aju1r0`, status `live`. The
separately executed [PR #408 external HTTPS smoke](https://github.com/1kz-ma1/Canovia-web/actions/runs/37875765667)
passed with `/up=200` and the login/OAuth/MCP, legacy `/health`,
static-file and direct PHP entry-point probes returning HTTP 503.
PR #408 was merged. This establishes *closed external staging* behavior
for the SQLite deployment; it does **not** prove attachment to Render PostgreSQL.

### Staging-only migration failure redaction and operator gate (merged and live)

The shared production startup script previously printed raw Laravel
migration failures. During the proposed PostgreSQL cutover, an exception
might contain host or connection metadata. The startup script now uses
a separate branch **only when** both `APP_ENV=staging` and
`CANOVIA_STAGING_ISOLATED=true`: it suppresses all migration/cache
command output (including exceptions), retries failed staging migrations
within the pre-existing retry budget, then prints only generic successful
or failed status messages. The production path retains its pre-existing
migration log/retry behavior. The isolated staging entrypoint also
prints a safe `configured database mode=sqlite|render_postgres` marker
after the identity guard has passed. **This marker is not proof that the
database is connected.** A disposable Docker test intentionally connects
with an *incorrect synthetic password* and fails the migration, asserting
that its canary, full URL and SQLSTATE do not appear in logs.

**Operator-only cutover, still unperformed:**

1. Verify the Render Web **existing ID**
   `srv-db43l4nlk1mc73emseig` and DB **existing ID**
   `dpg-db43rbbncjis73bmigi0-a`; both are Singapore Free resources.
   Inspect the *Internal Database URL* directly in the private
   [database dashboard](https://dashboard.render.com/d/dpg-db43rbbncjis73bmigi0-a).
   Never transfer the URL or password into GitHub, chat or CI logs.
2. The current Render connector accepts only **literal environment
   variable values** and cannot set `fromDatabase` references. Its
   external PostgreSQL read-only query was also **blocked** by the
   correctly empty IP allowlist. Do not loosen the allowlist. A
   reviewed existing-service Blueprint sync is acceptable only if
   the preview targets the same Web/DB resource IDs and creates
   **no duplicates**. Otherwise use the [existing Web Environment UI](https://dashboard.render.com/web/srv-db43l4nlk1mc73emseig)
   privately.
3. Atomically prepare the **existing staging Web's** `DB_URL`
   from the private Internal Database URL, and set
   `DB_CONNECTION=pgsql`, `DB_DATABASE=canovia_mcp_staging_db`,
   `CANOVIA_STAGING_DB_MODE=render_postgres`,
   `CANOVIA_STAGING_POSTGRES_ID=dpg-db43rbbncjis73bmigi0-a`,
   `CANOVIA_STAGING_POSTGRES_USER` to the actual Render DB user
   (ideally via Render-native `fromDatabase: user`). Use **Save only**
   until all values have been reviewed together; never deploy a
   partly configured environment. Existing staging-specific
   `APP_KEY` and all closed access/OAuth/MCP flags remain unchanged.
   Check that `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`,
   `DATABASE_URL` and all production integration secrets remain absent.
4. Deploy **only** the existing staging Web manually. Inspect the
   generic mode marker and `MCP isolated staging migrations: completed`
   in Render logs, then verify from an independent HTTPS runner that
   `/up=200` while all protected/static/login/MCP endpoints remain
   `503`. In PostgreSQL mode `/up=200` requires a live query
   against the pinned database and the six migrated tables.
   An isolated success marker without healthy `/up` is insufficient.
5. If anything fails, use **Save only** to restore
   `CANOVIA_STAGING_DB_MODE=sqlite`,
   `DB_CONNECTION=sqlite`,
   `DB_DATABASE=/var/www/html/storage/app/staging/mcp.sqlite`,
   and **remove** both `DB_URL` and
   `CANOVIA_STAGING_POSTGRES_USER`. Then manually redeploy the
   existing stage. Preserve all access/OAuth/MCP flags OFF.
   Do not touch production Canovia, Aiven, HINANEX or paid plans.

**Stop condition:** without access to the private Render Dashboard
environment/binding UI, ChatGPT cannot safely execute step 3 through
the currently available connector. Do not replace that operation with
a guessed URL, a literal secret in code, or public database access.


### Cutover-logging protection acceptance evidence (2026-10-09)

- [PR #409](https://github.com/1kz-ma1/Canovia-web/pull/409)
  merged to `main` as `19c80f28f7e2c22c3d463d87de3ced6faeddf3c3`.
- PR-head [Docker migration isolation run 37876396208](https://github.com/1kz-ma1/Canovia-web/actions/runs/37876396208)
  passed: real image boot in both SQLite and disposable PostgreSQL 17,
  synthetic wrong-password rejection, no canary/DSN/SQLSTATE emitted,
  PostgreSQL migrations and Nginx lockdown.
- [Workspace resume/MCP suite 37876396248](https://github.com/1kz-ma1/Canovia-web/actions/runs/37876396248)
  and [production migration recovery 37876396204](https://github.com/1kz-ma1/Canovia-web/actions/runs/37876396204)
  both passed.
- The existing Free staging Web was manually redeployed as
  `dep-db45dorncjis73bs7120` at PR #409's merged commit;
  Render reported **live**.
- Render's **actual startup logs** showed
  `configured database mode=sqlite (connection not yet verified)`
  followed by `MCP isolated staging migrations: completed (details suppressed)`.
- The [external HTTPS lockdown job 37875765667](https://github.com/1kz-ma1/Canovia-web/actions/runs/37875765667)
  was **rerun after this deploy** and passed again, validating public
  `/up=200` and all protected/static/legacy health probes closed.
- The **actual Render database is still not connected**. Do not confuse
  these readiness/security acceptances with the future, separately gated
  Render-native PostgreSQL cutover or real OAuth tests.

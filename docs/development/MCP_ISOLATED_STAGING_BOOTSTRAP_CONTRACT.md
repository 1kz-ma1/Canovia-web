# Canovia MCP — isolated Render staging bootstrap (Free service provisioned)

Updated: 2026-10-09
Status: **Free Canovia-only staging service provisioned and initial deploy live; all account/OAuth/MCP endpoints remain configured closed.**

## Why a staging environment must be isolated

As inspected from the connected Render workspace, Canovia currently runs as a **Free** Web service using the production `Dockerfile`, in Singapore. HINANEX staging is a **separate project** and cannot be used for Canovia MCP experiments. There is no existing Canovia staging Web service or Render Postgres database.

Render's Free services in the **same workspace share 750 instance-hours/month**. The production Canovia Free service can already use most of this budget, and adding even a free staging Web service could cause Free services to suspend when the pooled budget runs out. Render Free Postgres also expires after 30 days. The owner must review the billing dashboard before initiating the opt-in Blueprint sync. **Product-owner policy (2026-10-09): move the production Canovia Web service from Free to Render's lowest-cost paid compute plan when Canovia launches publicly.** Current listed smallest paid Web compute is **$7/month per service** on the Render Hobby workspace ($0/month base, plus compute); confirm rates at the actual change. A paid production instance is distinct from a paid workspace subscription. This does NOT approve paying for staging immediately.

Relevant Render sources:
- https://render.com/docs/free
- https://render.com/docs/blueprint-spec
- https://render.com/pricing

## Provisioned separately from production

`deploy/render-mcp-staging.yaml` is an **opt-in** Render Blueprint describing a **new**, separate `canovia-mcp-staging` Web service:

- `plan: free`, `region: singapore`, `autoDeployTrigger: off`.
- Uses the **different** Dockerfile `Dockerfile.mcp-staging`, not the production `Dockerfile`. It installs `pdo_sqlite`/SQLite development libraries alongside the normal runtime.
- Uses **ephemeral** SQLite file `/var/www/html/storage/app/staging/mcp.sqlite`, a local session/cache driver and synchronous queue. No Aiven/Render database URL or production credentials, no outbound SMTP, GitHub App, LLM or payment keys.
- Requires a staging-only `APP_KEY` with a `base64:` prefix. Generate it independently using `php artisan key:generate --show` (or an equivalent cryptographic random generator) and input it **in the private Render dashboard**, never in GitHub.
- Requires `APP_URL` as the **actual** assigned HTTPS `*staging*.onrender.com` origin with no path/query/fragment. The Blueprint prompts for APP_KEY and APP_URL with `sync: false`. Verify the exact assigned hostname before continuing.
- All MCP, OAuth link, Plan consent and delegated-policy flags start **OFF**.
- Public Web traffic starts **closed**, with only `/up` open for Render health. `CANOVIA_STAGING_WEB_ACCESS_ENABLED=false` MUST remain set until a separately reviewed private-access gate and synthetic user provisioning exist.
- Startup script `docker/mcp-staging-start.sh` executes **before** the production migration boot sequence and returns exit 42 for mismatched APP_ENV/APP_URL, external DB host/URL, unsafe DB driver/path, shared session cookie, insecure mode, integration secrets and unsanctioned MCP tools.
- On valid startup, the script creates the **isolated local SQLite file** and only then invokes the existing `docker/render-start.sh` for migrations/cache/nginx. The production `Dockerfile` and its startup remain unchanged.

This is a **disposable smoke-test** stage for container boot and OAuth discovery preflight, *not yet suitable for full ChatGPT OAuth session testing*. With a Free, ephemeral filesystem, SQLite and session data can be lost on restart/deploy/sleep. It is **not durable** and no backup is guaranteed. A dedicated durable staging database is needed to reliably test real OAuth sessions, concurrent revocation and scope history.

## Operator instructions — do NOT execute until resource impact accepted

1. Check <https://dashboard.render.com/> for the workspace's **remaining Free service hours** and monthly pipeline usage. The production upgrade to the lowest paid Render Web compute plan is planned at **public launch** (not now); do not confuse it with buying a Pro workspace subscription. When production is paid, its instance will no longer consume Free Web hours, but a Free staging service still has its own limits and costs may vary. Stage can reduce resources available to the live Canovia Free service; don't infer zero impact from `plan: free`.
2. Create a **separate Render Blueprint** using `deploy/render-mcp-staging.yaml`. Do not sync or attach the Blueprint to the existing `Canovia` service. Check that the service name is `canovia-mcp-staging`, distinct from the production `Canovia`.
3. Input **fresh** `APP_KEY` and the exact staging-origin `APP_URL`, with `APP_ENV=staging`, `DB_CONNECTION=sqlite`, all external credentials absent, MCP switches OFF, and no production environment group copied.
4. Before starting the public service, verify the separate Dockerfile and startup guard; `php artisan canovia:mcp-staging-preflight --json` is a local/metadata compatibility check (currently expected **blocked**, since the OAuth issuer is unconfigured).
5. Deploy manually only when the available Free hours are sufficient or a safe billing plan is explicitly accepted. Verify `/up` is healthy. Verify `/login`, `/api/mcp` and `/.well-known/oauth-protected-resource` return **503** from the locked staging boundary and no personal Plan data is accessible. Confirm the production Canovia endpoint and DB remain unchanged.
6. Keep the stage closed. The next independently reviewed phase is provisioning **durable staging storage**, a restrictive browser-access gate and synthetic accounts, followed by a verified IdP tenant. Do not simply set `CANOVIA_STAGING_WEB_ACCESS_ENABLED=true` on a publicly reachable demo; it would open the entire Laravel site.
7. For teardown, delete **only** `canovia-mcp-staging` from Render after checking the service ID and name; never touch production Canovia or HINANEX. No persistent DB needs deletion for this disposable SQLite variant.

## Local, no-network guard verification

To test the isolated configuration without booting migrations or making network calls, run the shell script with `--check-only` **in a disposable local environment** where the required `CANOVIA_STAGING_*`, `DB_*`, `APP_*` and session variables are set. It returns `PASS (config only)` when valid, or exit code 42 with generic `BLOCKED` details otherwise; it never prints credential contents.

In addition to PHP/shell configuration assertions, a dedicated **ephemeral GitHub Actions Docker smoke** now builds `Dockerfile.mcp-staging` into an actual container, tests that a production-like environment exits before startup, and boots a disposable staging instance with its own randomly generated testing-only `APP_KEY` and SQLite. It checks that migrations and `/up` succeed and that `/login`, `/api/mcp` and OAuth metadata return HTTP 503. The image and container are destroyed after the job. It does not create a Render service or consume Render Free Web instance-hours. The smoke test **must pass** before real Render provisioning; failures mean this Blueprint is not deployment-ready.

Automated CI includes `tests/Feature/McpIsolatedStagingBootstrapTest.php`. It checks current staging HTTP lock behavior, unmarked environment rejection, production route nonregression, refusal of production-like DB URLs, hostnames, integration credentials, and MCP activation without a separate stage authorization, as well as Blueprint isolation/auto-deploy-off invariants.

## Still not completed

- The isolated Free Render staging Web service exists; however, no durable staging DB, IdP tenant, ChatGPT client registration or real OAuth end-to-end test exists.
- No public staging web access, account linking, consent or MCP reads.
- Render staging consumes shared Free instance-hours only while its instance is running; initial deployment and external checks use some of the monthly allowance.
- No production flags/keys or cost plan changes.
- No iPhone/PWA/PC device verification (owner requested one combined later test).

A future production connection requires the formal plan in
[MCP staging IdP preflight](MCP_STAGING_IDP_PREFLIGHT_CONTRACT.md) and a
real provider/client acceptance test. Never turn the default-off flags on
merely because the Docker image builds.

## Actual Render isolated staging provisioned (2026-10-09)

The owner confirmed the workspace's Render included-usage panel showed
**48.78 / 750 Free Web instance-hours consumed** before provisioning.
A separate **Free Web** staging service was created by the Render connector:

- Service name: `canovia-mcp-staging`
- Service ID: `srv-db43l4nlk1mc73emseig`
- URL: https://canovia-mcp-staging.onrender.com
- Dashboard: https://dashboard.render.com/web/srv-db43l4nlk1mc73emseig
- Docker source: `main`, `Dockerfile.mcp-staging`
- Render region/plan: `singapore` / `free`
- Auto deploy: **OFF**; no production service settings were changed.
- First deploy: `dep-db43l57lk1mc73emsg3g`, commit `d5865f8`,
  Render reported **live**, and its startup log showed the isolated SQLite
  migrations completing successfully.
- Staging-only `APP_KEY` was generated independently and configured via
  Render service env vars; its value is intentionally not stored in GitHub.
- Browser access: **OFF**; OAuth discovery, introspection, account linking,
  Plan consent, delegated policy, MCP tools: **ALL OFF**.
- The staging service **has no Aiven/Postgres database, external IdP, user
  records or real-world Plan data**. Its SQLite DB is ephemeral on sleep,
  restart and deploy.

This was an explicit owner-approved **Free staging deployment**, not a
paid upgrade or production launch. Render Free Web services automatically
spin down after about 15 minutes without inbound requests; spun-down hours
do not count against the monthly shared 750-hour pool. Therefore **do not
schedule keepalive pings**; check usage periodically and suspend/delete the
isolated service in Render if monthly hours become constrained. The connected
Render tools currently do not support service deletion or suspension.

A dedicated public-network GitHub Actions HTTP smoke job,
`.github/workflows/mcp-staging-live-http-smoke.yml`, now checks exact
`/up=200` and account/OAuth/MCP `503` via the Render domain. It runs
once as a PR check or manually; **not** on a recurring cron that prevents
Free spin-down. **External HTTP security boundary verified:** GitHub Actions run
`37867353868` completed successfully against the actual staging Render URL:
`/up` returned HTTP **200**; login, register, account, GET MCP, OAuth
metadata and POST MCP JSON-RPC all returned HTTP **503** with the generic
closed message and no-store cache protection. This is a verified external
lockdown smoke, **not** a real OAuth/ChatGPT user connection.

Next steps: select an IdP tenant, provision durable *staging-only* storage
and a reviewed stage access-control mechanism before opening any OAuth,
account or private MCP capability. Existing Canovia production remains a
Free service until the owner's **public-launch** paid-compute plan change.

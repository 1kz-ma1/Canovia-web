# Pre-Launch Web wake: passive Render evidence and operator-only HTTP smoke gate

**2026-10-15 · NO PUBLICATION AUTHORIZATION** · Follow [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488). This is additional evidence for the already fixed [Static release `320364717b9cdd3e0328445f9d0cb46582977fe9`](https://github.com/1kz-ma1/Canovia-web/tree/release/static-welcome-2026-10-15-3203647) and separate Web candidate `6d7dbcfedd35f33ba2827ed8c7c3d98ca30fc3d6`.

## 1. Passive findings — no incoming Web HTTP was initiated

On 2026-10-15 the operator inspected **already existing** Render log entries for the Web service `srv-dagfn2id0e5s73cac7ng` in **My Workspace**. Between `2026-10-14T00:00:00Z` and `2026-10-15T04:38:00Z`, log filters showed **11 complete startup cycles**, each with `Running Laravel migrations...`, `Nothing to migrate`, and `Starting Canovia with Nginx + PHP-FPM on port 10000`. Latest observed cycle: migration start **2026-10-15T04:21:49Z**, `Nothing to migrate` **04:21:51Z**, Web start **04:21:58Z**. These timestamps are **13:21 JST**.

This evidence means the *historical deployed source's* startup migration checks were no-op **at those points in time**, and the startup command reached its Web-server phase. It is **not** a fresh `SELECT` of Aiven's `migrations` table, does not prove exact 94/113 ledger membership, and does not imply the separate 19 migrations can run. Render `request` log queries for the same window returned **zero matches** for all requests, `/health` and `/feedback`; HTTP metrics queries returned empty series. That is *insufficient telemetry*, **not** evidence that those endpoints succeeded or were unused.

The connected Render state remained `Canovia` Web `autoDeploy=no/autoDeployTrigger=off`, Live Git SHA `15abdc9de6f24b5250fb255c579d1aa55facaf1a`; `Canovia-Welcome` Static `autoDeploy=no/autoDeployTrigger=off`, Live Git SHA `ee533028c866e201d61c1430c15eaedb9c843524`. The Static release candidate SHA is frozen and distinct from the continually advancing `main`.

**Do not infer that read-only GET cannot write:** current Live `docker/render-start.sh` runs `php artisan migrate --force` before starting Nginx/PHP-FPM. A Render Free Web spin-up from GET may start this process; Laravel Web guest routes can also create/update session storage.

## 2. The operator-only, guarded real HTTP smoke

`scripts/ops/prelaunch_web_live_smoke.mjs` is a **locally run, non-CI** check. It only supports the exact official Render Web host `pacekeeper-d3mm.onrender.com`. It is **not run in GitHub Actions** (the workflow instead tests that CI and default invocations are blocked). The URL and request paths are **immutable code constants**, with TLS hostname verification and no redirects or cookies.

**Four required self-attestations, all verified independently and privately *before* trying a real GET:**
1. `CANOVIA_WEB_WAKE_OWNER_APPROVED=YES`: explicit operator/owner acceptance that even `GET /health` can wake Render and execute the current Live startup migration command;
2. `CANOVIA_WEB_LIVE_SHA_OPERATOR_VERIFIED=YES`: latest Render deployed SHA read back as **`15abdc9...`** and both Auto Deploy toggles OFF;
3. `CANOVIA_WEB_DB_LEDGER_OPERATOR_VERIFIED=YES`: actual Aiven **old Web** source migration ledger and target DB independently checked with the correct least-privilege audit account; the previous **11 no-op startup logs alone are NOT a substitute**;
4. `CANOVIA_WEB_RESTORE_RISK_OWNER_ACCEPTED=YES`: operator and owner have reviewed actual backup/restore risk, write coordination and the planned failure response. This is an attestation, **not** proof of successful restore.

The program requires the exact CLI argument `--live-operator-approved`, refuses `CI=true` or `GITHUB_ACTIONS=true`, rejects DB secrets and generic/custom input URLs. It requests **at most one unauthenticated GET `/health`** with `Origin: https://pacekeeper-welcome.onrender.com` and checks **204**, `X-Canovia-Ready: 1`, Access-Control-Allow-Origin and Access-Control-Expose-Headers; disallows redirects and prints **a sanitized status code only**, no HTML, raw headers, cookies, host accounts, or credentials. Even a healthy response is **`REVIEW_REQUIRED` / `releaseAuthorized=false`** and does not demonstrate Safari cross-origin JavaScript behavior.

Only with an additional independent `CANOVIA_WEB_GUEST_FEEDBACK_OWNER_APPROVED=YES` does it make a second anonymous `GET /feedback`. That GET **could create a database session**. Response is classified as guest HTML vs status/redirect/invalid, with all raw body discarded. This does not test login, CSRF or persisted session, and cannot infer other paths are live. Neither request is executed as part of this change.

### Operator invocation (run **only after** requirements above)

From an operator-controlled computer, not in GitHub Actions, supply the five exact flag names as locally approved environment variables; no URLs, credentials or DB connections should be passed. Example shows the gated *code path*, **not an instruction to set YES without evidence**:

```bash
CANOVIA_WEB_WAKE_OWNER_APPROVED=YES \
CANOVIA_WEB_LIVE_SHA_OPERATOR_VERIFIED=YES \
CANOVIA_WEB_DB_LEDGER_OPERATOR_VERIFIED=YES \
CANOVIA_WEB_RESTORE_RISK_OWNER_ACCEPTED=YES \
node scripts/ops/prelaunch_web_live_smoke.mjs --live-operator-approved
```

For separate Guest `/feedback` review, add `CANOVIA_WEB_GUEST_FEEDBACK_OWNER_APPROVED=YES` **only after** explicitly approving potential guest session side effects. A passing status code does not authorize a deploy or a Web 19-migration release.

### What the CI tests prove — and do not prove

`tests/js/prelaunch-web-operator-live-smoke.test.mjs` exercises static pure validators for negative/wrong status/redirect/cors/exposed header and verifies that invocation in default/CI modes **aborts before any network call**. The new GitHub workflow intentionally never sets up a live Web GET. The existing mock Chromium suite exercises the UI Ready and Support behavior separately. **Actual Aiven ledger, Web headers, Laravel guest, Auth0 callback, Render custom domain, iPhone Safari and restore remain unverified.**

## 3. Static-only manual release remains a separate decision

Render official [Deploy docs](https://render.com/docs/deploys) and [CLI reference](https://render.com/docs/cli-reference) explicitly support deploying an **immutable commit** with `render deploys create [serviceID] --commit [SHA]`. The connected Render `trigger_deploy` tool does **not** expose `commitId`, so invoking it while the linked branch `main` can change would violate the fixed-SHA release constraint. The safe operator path after separate Static-only release consent is:

1. Verify `release/static-welcome-2026-10-15-3203647` still points exactly to `320364717b9cdd3e0328445f9d0cb46582977fe9`, all static tests passed, Live Static pre-deploy SHA `ee533028...`, root `static-welcome` / publish `.`, both Auto Deploy OFF.
2. Use only an explicitly SHA-selected deployment in Render Dashboard or the operator's approved Render CLI (example syntax **for future owner-authorized execution only**): `render deploys create srv-dahn5k142hec739l0fmg --commit 320364717b9cdd3e0328445f9d0cb46582977fe9 --wait`. Confirm workspace and target service independently. No hooks/tokens in source control.
3. Read back **exact live SHA** from Render and perform actual browser/iPhone Safari checks of `/intro.html`, `/index.html`, `?destination=support`, CORS/Ready/retry, return visitor/Guest and cookie/session. If any failed check, hold promotion and use owner-reviewed **static-only** rollback; never touch Web/Aiven.
4. Static manual publication still requires explicit owner GO, and the Web's new 19 migrations remain **NO-GO**. Do not deploy merely because this document exists or an offline test passes.

## 4. Status at the end of this evidence collection

- Passive Live-startup observation: **DONE; 11 old-source no-op migration cycles**. Not a database-ledger certification.
- Static frozen candidate and DNS/TLS/Chromium mock readiness: **DONE / REVIEW**.
- Owner-gated live smoke tool: **prepared; not executed**.
- Actual Aiven ledger, independent restore, real HTTP and Safari acceptance, Static-only owner deploy GO: **OPEN / HOLD**.

All findings should be updated in [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488). Do not publish raw application logs, usernames, customer data, secrets, or credential-bearing URLs.

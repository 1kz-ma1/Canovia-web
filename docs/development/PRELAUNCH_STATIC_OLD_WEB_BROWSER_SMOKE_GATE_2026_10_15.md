# Static-only release readiness — old-Live-Web smoke boundary

**2026-10-15 | Owner gate only; no production deploy approval.** Related: [IA-1](PRELAUNCH_IA1_STATIC_PUBLIC_INTRO_2026_10_15.md), [Public DNS/TLS](PRELAUNCH_STATIC_PUBLIC_DNS_TLS_ACCEPTANCE_2026_10_15.md), [Web/Aiven Release Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488).

## Proven versus still unknown

| Area | Evidence now available | NOT established |
|---|---|---|
| Web target host | GitHub Actions 2026-10-15: DNS/TLS `pacekeeper-d3mm.onrender.com` **valid**; old `app.canovia.com` **DNS_UNVERIFIED** | Render custom-domain ownership/routing, Laravel runtime response |
| Static current Live | `pacekeeper-welcome.onrender.com/index.html` publicly served the old Ready page; new `/intro.html` was **not live** | New Static code deployed, physical Safari rendering |
| Source compatibility | Exact current Live Web `15abdc9de6f24b5250fb255c579d1aa55facaf1a` has unauthenticated `GET /feedback` and `GET /health` with `Access-Control-Allow-Origin: *`, `Access-Control-Expose-Headers: X-Canovia-Ready`, `X-Canovia-Ready: 1`; new curated public roadmap/Support hash not in old Live | Whether deployed PHP actually serves those responses, production Session Domain, auth redirect and cookies |
| Browser contract | New Chromium synthetic Web adapter asserts correct Ready, missing-CORS/missing-Ready/503 fail closed, free Support goes to old `/feedback`, no arbitrary redirects or live HTTP | Actual web wake, CORS on network, real login/Guest session persistence |
| Database writes | No Aiven contact/migration in public DNS/TLS/synthetic Chromium jobs | Whether real Web service is safe to wake and has zero pending migrations |

## Phase 0 — preconditions before *any real Web HTTP request*

Real Web `docker/render-start.sh` runs `php artisan migrate --force` at service start (including wake). [Render Free](https://render.com/docs/free) notes inbound HTTP can spin up an idle Free service. Therefore a request that looks observational could **write Aiven schema** during startup. No GitHub Actions probe shall `curl` /health, /feedback, root/login or /robots.txt on either Web host.

Before an owner manually visits the Web host to test Static compatibility, an operator must independently confirm:
1. Render currently deployed SHA is still `15abdc9de6f24b5250fb255c579d1aa55facaf1a`; Web's Auto Deploy remains OFF. **No deploy/restart** merely to test.
2. Aiven is the **expected exact DB**, with a privately inspected migration ledger and no pending migration for the **currently running old Web source**, plus relevant backup/recovery risk accepted. The 94-vs-113 source-file count is **not** proof the actual production DB migration ledger equals 94.
3. The owner explicitly authorizes the **read-only browser observation despite possible wake/startup migration effects** after operator gate. If ledger, runtime account, DB target or restore safety is unknown, leave phase 1 **BLOCKED**. Do not create test users, write feedback, vote, change grants or run Artisan migrations to establish readiness.

## Phase 1 — owner-controlled Web smoke observation (NOT automated)

With phase 0 cleared, use a real desktop browser and iPhone Safari. Collect **only response/status descriptions** and exclude cookies, identities, session IDs, full URLs with sensitive query parameters, and credentials from evidence:

- Target `https://pacekeeper-d3mm.onrender.com` (the **configured** Static `appUrl`). At `/health`, after any cold start, the response should be 204, with `X-Canovia-Ready: 1`, `Access-Control-Allow-Origin: *`, and **exposed** `X-Canovia-Ready`. Test this in a cross-origin browser context, not only by copying headers into curl. Unknown/redirect/timeout/CORS error = **HOLD**.
- As a **guest**, visit GET `/feedback`, verify the existing free Support candidates UI renders without authentication/redirect-loop. Do not submit a vote/feedback during smoke.
- Validate app initial navigation and authentication/session cookie behavior under the **Render URL**. A cookie `Domain` restricted to `app.canovia.com`, incorrect `APP_URL`, or auth callback allowlist tied to that old domain may prevent sign-in. If these cannot be checked safely, postpone Static publication rather than falsely claim full app functionality.
- On Static's *current old Live*, `/intro.html` is **expected not to exist** until an owner approves deployment of a specific reviewed commit. No false success claim based on source HTML or local Playwright.

## Phase 2 — static-only deployment decision (separate explicit GO)

Static service: `Canovia-Welcome` (`srv-dahn5k142hec739l0fmg`), Publish Root: `static-welcome`. GitHub `main` may advance due concurrent Web/UI/learning PRs. **Never deploy a moving branch head without freezing the exact reviewed commit.** After all checks for the precise source SHA pass, pin that SHA as a review-only `release/static-intro-*` ref and record its tree and tested PR SHA in #488; verify before operator-approved deployment.

Render supports deployment of **one explicitly selected Git commit** (see [Render CLI docs](https://render.com/docs/cli): `render deploys create ... --commit`, or equivalent Render dashboard workflow). The connected Render `trigger_deploy` action exposes no commit selector. **Do not use that action for Static release while main can move**; it could deploy unrelated concurrent changes. No deploy hook URL, API token, credential or new workspace resources should be added to source control. No command should be executed until the owner separately approves the exact Static release SHA and static-only publication.

After *authorized Static-only* deploy: re-fetch Render Static live deploy and confirm **exact SHA**, test static `/intro.html`, the old `/index.html` ready/retry/returning flow, `?destination=support` to old Web `/feedback`, both phone and desktop viewports and Safari, and check that unpublished roadmap/planless capabilities are still described as future. **No Web production branch/Render Web deploy/Aiven migration**. Any mismatch = stop promoting; use independently reviewed static rollback.

## Status classification

- **Synthetic Chrome tests passed**: browser UX contract only.
- **Public DNS/TLS passed**: reachability of Render TLS, not actual Web readiness.
- **Operator Web smoke approved and passed**: only the tested runtime behavior, still not production Web 19-migration approval.
- **Owner-approved static-only deploy verified at immutable SHA**: Static intro may be called public only then.
- **Release #488 Web production GO**: separately requires Aiven DDL and restore evidence, scoped first-use acceptance and explicit owner consent. **Current state: NO-GO**.

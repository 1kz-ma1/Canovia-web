# Pre-launch Static Welcome — public DNS/TLS and static-only preflight

> 2026-10-15. **REVIEW ONLY / NO DEPLOY / NO RELEASE GO**. Owner and release-gate record: [#488](https://github.com/1kz-ma1/Canovia-web/issues/488). Static IA-1 specification: [PRELAUNCH_IA1_STATIC_PUBLIC_INTRO_2026_10_15.md](PRELAUNCH_IA1_STATIC_PUBLIC_INTRO_2026_10_15.md).

## What prompted this test

GitHub / Render sources are clear about the two different production states:

- Render **Canovia-Welcome Static** `srv-dahn5k142hec739l0fmg`, URL `https://pacekeeper-welcome.onrender.com`, watched `main`, **Auto Deploy OFF**, Live source `ee533028c866e201d61c1430c15eaedb9c843524`. `/intro.html` is implemented in development `main`, but its live delivery has not been verified or approved.
- Render **Canovia Web** `srv-dagfn2id0e5s73cac7ng`, Render URL `https://pacekeeper-d3mm.onrender.com`, **Auto Deploy OFF**, Live source `15abdc9de6f24b5250fb255c579d1aa55facaf1a`. The Static `config.js` Web destination is **`https://app.canovia.com`**. The custom domain's DNS/TLS health and routing to that precise Web origin are **not proved** by the Render service's default URL.
- Static content now calls the new public roadmap "準備中" and links the existing **old Web** `/feedback` free Support only. The `publicRoadmapReady=false` and `supportAnchorReady=false` gates must remain false until **independently accepted** Web publication. The existing Web Live `/feedback` route does not have the `#support-candidates` anchor or new release-card view.

Previous assistant-side external requests to the above domains failed in the execution environment due to DNS resolution, and webpage search could not load them. **That does not establish the sites are down**: a runner with usable public DNS and outbound TLS is required to distinguish the environment's restrictions from the site's real state.

## Safe live diagnostic (NOT a full E2E acceptance)

Workflow [`.github/workflows/prelaunch-public-dns-tls-readonly.yml`](../../.github/workflows/prelaunch-public-dns-tls-readonly.yml) calls `scripts/ci/prelaunch_static_public_dns_tls_probe.mjs` only from a separate GitHub Actions runner. It has *no* credentials or write permissions. All outgoing targets are immutable checked-in public hostnames.

1. **DNS + TLS handshake only** to `app.canovia.com` (Static's configured Web destination), `pacekeeper-d3mm.onrender.com` (Render's Web default domain) and `pacekeeper-welcome.onrender.com` (Static Render domain). The certificate must validate the requested hostname. **No HTTP request is made to either Web endpoint** and the probe never attempts to wake Laravel/Render Web.
2. **Two unauthenticated GETs solely to Static Render**: `/index.html` (expect a recognizable Welcome page) and `/intro.html` (classifies `INTRO_NOT_LIVE`, `INTRO_HTML_VISIBLE`, or `INTRO_HTML_UNVERIFIED`). It refuses all redirects, does not follow cross-origin links, caps response bytes and per-call timeout, and never requests user/session/Plan/Task data.
3. Offline Node tests require checked-in Static config fields **OFF**, exact allowlisted domain set, reject arbitrary hosts, verify the no-opt-in default blocks network calls and that even all green ends **`REVIEW_REQUIRED_NO_PRODUCTION_GO`**, not release authorization.
4. A public TLS test **does not** establish that `app.canovia.com` routes to the same Render service, or that `/health` CORS/readiness, custom domain aliases, HTTPS redirects or Web login/Guest flows work. Nor does `INTRO_HTML_VISIBLE` prove the deployed Git SHA. The static service's **actual Live SHA** must be independently read back from Render after an authorized manual deploy.

Any DNS/TLS failure is a **`BLOCK` for the domain acceptance criterion**, never grounds to rewrite the Static destination or enable features speculatively. Distinguish transitory CI network failures from a defective custom domain by independently checking the Render custom-domain console and DNS/TLS from an operator device. Do not "fix" auth/CORS via a blind redirect or move the domain to an unrelated host.

## Remaining Owner/device release gate

- [ ] Independently confirm the Web **custom domain** `app.canovia.com` is attached to `Canovia`'s intended Render service (DNS owner, custom-domain verification, current certificate). The Render `get_service` response only reports its default Render `serviceDetails.url`; it does not list custom domains.
- [ ] Confirm an authorized *operator-run* read-only browser check of `/health` at the actual configured URL, the `X-Canovia-Ready: 1` response when ready, necessary `Access-Control-Allow-Origin` from the static origin, and Guest GET `/feedback`. This should be coordinated because waking the Web may execute the existing `docker/render-start.sh` migration check; do **not** do it automatically in this DNS/TLS workflow.
- [ ] After **separately approved** Static manual deploy, confirm real `/intro.html`, `/index.html`, `?destination=support`, health/wake/retry, returning-visitor auto entry and no outdated public roadmap claim, on iPhone Safari and desktop/390px/1280px. The public Static test alone cannot replace a physical-device check.
- [ ] Owner explicitly accepts publishing Static-only while Web production remains unchanged. Confirm actual Render Live SHA and Auto Deploy remains **OFF**. Do not claim currently available "Planless 3-paths" or new Future features if Web is still old.
- [ ] Web `production` and Aiven migration/recovery remain separately **NO-GO**, pinned at `release/2026-10-15-6d7dbcf`; never promote moving `main` as part of a Static release.

**No service settings, deployments, credentials, Aiven data, app-code releases, paid resources or iOS binaries are changed by this preflight.**

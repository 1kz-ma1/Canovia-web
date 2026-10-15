# Pre-launch Static Welcome — public DNS/TLS and static-only preflight

> 2026-10-15. **REVIEW ONLY / NO DEPLOY / NO RELEASE GO**. Owner and release-gate record: [#488](https://github.com/1kz-ma1/Canovia-web/issues/488). Static IA-1 specification: [PRELAUNCH_IA1_STATIC_PUBLIC_INTRO_2026_10_15.md](PRELAUNCH_IA1_STATIC_PUBLIC_INTRO_2026_10_15.md).

## What prompted this test

GitHub / Render sources are clear about the two different production states:

- Render **Canovia-Welcome Static** `srv-dahn5k142hec739l0fmg`, URL `https://pacekeeper-welcome.onrender.com`, watched `main`, **Auto Deploy OFF**, Live source `ee533028c866e201d61c1430c15eaedb9c843524`. `/intro.html` is implemented in development `main`, but its live delivery has not been verified or approved.
- Render **Canovia Web** `srv-dagfn2id0e5s73cac7ng`, Render URL `https://pacekeeper-d3mm.onrender.com`, **Auto Deploy OFF**, Live source `15abdc9de6f24b5250fb255c579d1aa55facaf1a`. The former Static `config.js` Web destination was **`https://app.canovia.com`**, but **GitHub Actions public DNS returned `DNS_UNVERIFIED` on 2026-10-15**. The Render default domain **`https://pacekeeper-d3mm.onrender.com` had valid TLS**, so the development Static config now selects that **temporary official Render origin**. This source change is **not yet live** and does not prove Web CORS, Guest, login, Session Domain or iPhone usability.
- Static content now calls the new public roadmap "準備中" and links the existing **old Web** `/feedback` free Support only. The `publicRoadmapReady=false` and `supportAnchorReady=false` gates must remain false until **independently accepted** Web publication. The existing Web Live `/feedback` route does not have the `#support-candidates` anchor or new release-card view.

Assistant-side network requests were unavailable, so [GitHub Actions read-only probe](https://github.com/1kz-ma1/Canovia-web/actions/runs/38683027035) ran independently and classified: `app.canovia.com=DNS_UNVERIFIED`, `pacekeeper-d3mm.onrender.com=TLS_VALID`, `pacekeeper-welcome.onrender.com=TLS_VALID`, current Static `index.html=STATIC_OLD_OR_NEW_INDEX_VISIBLE`, and `intro.html=INTRO_NOT_LIVE`. This is concrete external runner evidence of a **broken or currently unpublished custom-domain DNS route**, not proof of a global outage.

## Safe live diagnostic (NOT a full E2E acceptance)

Workflow [`.github/workflows/prelaunch-public-dns-tls-readonly.yml`](../../.github/workflows/prelaunch-public-dns-tls-readonly.yml) calls `scripts/ci/prelaunch_static_public_dns_tls_probe.mjs` only from a separate GitHub Actions runner. It has *no* credentials or write permissions. All outgoing targets are immutable checked-in public hostnames.

1. **DNS + TLS handshake only** to `app.canovia.com` (Static's configured Web destination), `pacekeeper-d3mm.onrender.com` (Render's Web default domain) and `pacekeeper-welcome.onrender.com` (Static Render domain). The certificate must validate the requested hostname. Custom domain is a diagnostic while not in `config.js`, but its failure is recorded rather than hidden. **No HTTP request is made to either Web endpoint** and the probe never attempts to wake Laravel/Render Web.
2. **Two unauthenticated GETs solely to Static Render**: `/index.html` (expect a recognizable Welcome page) and `/intro.html` (classifies `INTRO_NOT_LIVE`, `INTRO_HTML_VISIBLE`, or `INTRO_HTML_UNVERIFIED`). It refuses all redirects, does not follow cross-origin links, caps response bytes and per-call timeout, and never requests user/session/Plan/Task data.
3. Offline Node tests require checked-in Static config fields **OFF**, exact allowlisted domain set, reject arbitrary hosts, verify the no-opt-in default blocks network calls and that even all green ends **`REVIEW_REQUIRED_NO_PRODUCTION_GO`**, not release authorization.
4. A public TLS test **does not** establish that `app.canovia.com` routes to the same Render service, or that `/health` CORS/readiness, custom domain aliases, HTTPS redirects or Web login/Guest flows work. Nor does `INTRO_HTML_VISIBLE` prove the deployed Git SHA. The static service's **actual Live SHA** must be independently read back from Render after an authorized manual deploy.

The preflight fails closed when the **configured** Web host, official Render Web or Static host is not TLS-valid. While the custom domain remains **unconfigured in Static**, its DNS/TLS result is an explicit *diagnostic* and does not block the **official Render URL** static-only preparation. Re-enable the branded domain only after independent DNS/Render ownership checks and actual browser Session/CSRF handling. The literal Render Web URL is grounded in the connected Render service; it is not an inferred or unrelated host.

## Remaining Owner/device release gate

- [ ] To restore the **custom domain** `app.canovia.com`, independently confirm DNS owner, Render domain attachment and TLS; on 2026-10-15 this host did **not resolve from the independent GitHub Actions runner**. Until resolved, the checked-in Static config points to the Render-provided `pacekeeper-d3mm.onrender.com` (which passed TLS-only probe). The Render `get_service` response returns its official URL but not attached custom domains.
- [ ] Confirm an authorized *operator-run* read-only browser check of `/health` at the actual configured URL, the `X-Canovia-Ready: 1` response when ready, necessary `Access-Control-Allow-Origin` from the static origin, and Guest GET `/feedback`. This should be coordinated because waking the Web may execute the existing `docker/render-start.sh` migration check; do **not** do it automatically in this DNS/TLS workflow.
- [ ] After **separately approved** Static manual deploy, confirm real `/intro.html`, `/index.html`, `?destination=support`, health/wake/retry, returning-visitor auto entry and no outdated public roadmap claim, on iPhone Safari and desktop/390px/1280px. The public Static test alone cannot replace a physical-device check.
- [ ] Owner explicitly accepts publishing Static-only while Web production remains unchanged. Confirm actual Render Live SHA and Auto Deploy remains **OFF**. Do not claim currently available "Planless 3-paths" or new Future features if Web is still old.
- [ ] Web `production` and Aiven migration/recovery remain separately **NO-GO**, pinned at `release/2026-10-15-6d7dbcf`; never promote moving `main` as part of a Static release.

**No service settings, deployments, credentials, Aiven data, app-code releases, paid resources or iOS binaries are changed by this preflight.**

## 2026-10-15 verified triage and source-only fallback

- Independent GitHub runner log: [public preflight run #38683027035](https://github.com/1kz-ma1/Canovia-web/actions/runs/38683027035), TLS good on both official Render hosts and `DNS_UNVERIFIED` for `app.canovia.com`. The previous main config was a broken or unverified Web entry point.
- Static source was updated to use the **exact official Render Web service URL** rather than the unresolvable custom name, with both Future release flags **OFF**. No changes to the actual Static/Web Live service configuration or deploy.
- The probe keeps testing the custom domain but only requires configured Render Web + Render Static TLS for the **transport-only** review result. **This cannot verify cross-site Ready CORS, Auth0 redirects, cookie domain, Guest /feedback, or actual running Laravel**. Those stay HOLD until an owner-coordinated Web HTTP/browser check (which may wake the app) and iPhone Safari acceptance.

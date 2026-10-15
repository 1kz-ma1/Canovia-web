# Pre-Launch IA-2d — Real disposable Chromium first-use journeys

**2026-10-15 | Bounded development-only proof. No public release GO.**

## Why this gate exists

IA-2a/b/c introduced optional diagnostics, conversational first-use and
planless browsing, but their first-run acceptance was previously based on
Laravel feature tests only. The existing disposable Chromium scripts
also selected the now-removed single-label Welcome button
`Canoviaと始める` and could fail before reaching their original
Development/Learning acceptance steps.

## Implementation / isolated test environment

- `.github/workflows/prelaunch-ia2d-disposable-browser.yml` launches a real
  PHP/Laravel server **only at 127.0.0.1:8796**, migrates an empty
  disposable SQLite database and installs pinned Chromium/Playwright
  on a fresh GitHub Actions runner.
- The browser test refuses to run without `APP_ENV=testing`,
  `DB_CONNECTION=sqlite`, `CANOVIA_PUBLIC_RELEASE_LEVEL=1`,
  `NATIVE_AI_DRIVER=disabled`, the exact loopback URL and explicit
  disposable fixture flag. No real credentials, HTTP test injection,
  fake Plan/Task APIs or synthetic DB Plan writes are used.
- Fresh independent browser contexts test:
  1. **Browse** Welcome -> Overview planless -> optional Diagnosis -> Skip
     -> Overview -> optional First Companion (mobile-sized viewport).
  2. **Diagnose** Welcome -> selected Study answers -> real Plan Seed
     -> explicit human acceptance -> editor prefill -> Plan stored ->
     Study Workspace -> submit first recommended Study Action.
  3. **Converse** Welcome -> user-text Goal Discovery -> rendered
     follow-up answer(s) -> explicit first Plan creation -> non-planless
     Overview. External AI is disabled, so conversation uses the
     actual deterministic fallback; this is not AI quality evidence.
- Eight earlier Development/Learning browser scripts now choose a
  real, visible submit button from the Welcome form rather than relying
  on the retired single label, preserving their original later flows.
  Their own path-filtered CI workflows must be green on the final HEAD.

## Proof boundaries

A successful run means actual **headless desktop Chromium** pointer
clicks, form input, CSRF, navigation and server persistence worked in
disposable testing SQLite. One Browse path is also exercised at a
mobile-sized viewport (still desktop Chromium, not iOS Safari).
It does **not** establish:
- live Render/Aiven recovery, physical iPhone WKWebView / Safari
  interaction, actual human comprehension, AI reasoning quality,
  approved exam material, production backup,
  entitlement rollout or release readiness;
- new AWS G3 provider proof (Issue #681 remains OPEN/HOLD);
- no-fault user journeys beyond the specific scripts.

Workflow succeeds only on real test assertions. A test failure remains
a **NO-GO** and must be fixed and rerun before development PR integration.

The first Study Action may lead to a practice setup awaiting a real
question bank; this browser journey does not invent or grade question
content. It checks actual Study Action submission, not completion of
AP study.

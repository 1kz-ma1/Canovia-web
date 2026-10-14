# Pre-Launch IA-2a — Personalization Skip without mandatory Plan creation

**2026-10-15 | Narrow slice of the active pre-launch IA proposal, not a launch authorization**

## Observed defect

The rendered V58.25 Personalization page offered **「今はスキップしてCanoviaを使う」**.
However, `PersonalizationController::skip()` redirected to `plans.create`, even
though Overview already renders a usable **no-Plan first-use workspace chooser**.
This conflicted with the existing [pre-launch IA specification](PRELAUNCH_PUBLIC_APP_FUTURE_IA_SPEC_2026_10_13.md)
and pushed users into creating a Plan before seeing what Canovia does.

## Smallest viable correction

- Keep the existing `POST /personalization/skip` endpoint, CSRF/throttle,
  `PersonalizationContextService::skip()` and skip telemetry.
- Redirect **only this existing Skip action** to
  `workspace.overview.index` instead of `plans.create`; do not save or
  create a Plan, Goal or personalized Plan Seed automatically.
- Existing Overview's actual no-Plan state remains the destination;
  workspace choice and later Plan creation are voluntary.
- Leave `FirstRunController::start()`, CoreStable L0 fallback,
  release-level gates, existing account resume and paid/AI entitlement
  conditions unchanged. This is not a new public Preview bypass.
- Do not auto-start the legacy Plan creation tour.

## Verification

Existing `PersonalizationBootstrapV5825Test` gains/updates checks for:
1. Logged-in actor skipping then **rendering** Plan-free Overview and later
   opening Plan creation deliberately, without having created a Plan.
2. New guest using **Welcome start → Personalization Skip → Overview**,
   observing actual no-Plan UI and the existing `PersonalizationSkipped` event.
3. Old automatic Plan tour remaining OFF when the optional editor is opened.

The rest of the old Bootstrap/seed-result tests remain unchanged. The
V56.12 Workspace Resume CI runs this suite plus its release-level guards;
other triggered checks must be green on the exact PR HEAD.

## Still open / outside IA-2a

This slice does **not** implement the full three-way first-run choice
(Diagnosis / Goal Discovery / Browse), a public Marketing Site, a
new Preview release gate, guest data expiry, Store release or
production acceptance. Real iPhone/Safari/PWA usability still
needs device evidence. G3 external AWS evidence remains
**OPEN / HOLD** under [Issue #681](https://github.com/1kz-ma1/Canovia-web/issues/681).
No Render, AWS, Aiven, IAM, paid services, new database migrations,
production branch or deployment are modified.

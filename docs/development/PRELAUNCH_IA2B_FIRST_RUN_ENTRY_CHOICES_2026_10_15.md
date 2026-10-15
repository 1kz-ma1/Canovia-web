# Pre-Launch IA-2b — Three explicit Welcome entry choices

**2026-10-15 | Development main only | not a public release or preview-permission increase**

## Contract and rationale

IA-2a removed mandatory Plan creation after skipping the existing
Personalization screen. The First Run Welcome still presented only one
`Canoviaと始める` POST that unconditionally entered Personalization in
L1. This patch allows a new eligible visitor or new-account user to
choose **how** to begin instead of requiring a diagnosis and then a Skip.

The three buttons submit the existing CSRF-protected `POST /welcome/start`
with an `entry` value. No extra route or persistence schema is needed.

| Choice | L1+ confirmed redirect | Content/side effect |
| --- | --- | --- |
| `diagnosis` | `personalization.show?source=first_run` | Existing self-reported diagnosis, Plan Seed only after explicit request |
| `conversation` | `plans.create` | Existing First Companion / Goal Discovery form, not an AI call on entering |
| `browse` | `workspace.overview.index` | Existing Plan-free Overview, no Plan or diagnosis written |

- Legacy requests that **omit** `entry` default to `diagnosis`
  for cached Welcome screens and old WKWebView clients.
- Any unrecognized `entry` fails Laravel validation **before**
  `FirstRunService::markPassed` updates the user completion timestamp,
  browser cookie or session. CSRF and normal protections stay in place.
- A user **without the L1 EarlyAccessCore capability** gets the prior
  L0 single CTA and `plans.create` response. Injected `browse`
  and `diagnosis` values cannot create an L1 route bypass. Capability
  is checked again on POST (the GET UI is never the authorization).
- The existing first-run pass is recorded only when a valid POST was
  accepted. Existing users who have passed the Welcome are still
  redirected away from the introductory screen to Home.
- There is no automatic Plan, Goal Context, subscription, paid AI
  request, or third-party disclosure merely by picking an entry.
  Subsequent existing features retain their own normal gates.

## Verification evidence required

`FirstRunUxV4123Test` covers all three first-run choices, guest and
registered actor, the real Plan-free Overview HTML, pre-pass rejection
of invalid values, legacy no-field POST and L0 fallback. It also
retains login/re-entry tests and the previous First Companion
availability. V56.12 Workspace Resume runs that test with
`PersonalizationBootstrapV5825Test` and
`ConversationalOnboardingV4116Test`; the exact PR HEAD needs green CI.

## Outside this slice

This is the smallest IA-2b choice UI. It does **not** ship a public
marketing site, a separate guest-preview entitlement, standalone AI
chat, per-choice analytics schema, App Store update, Render deploy,
Aiven migration or AWS provider attestation. A real mobile WKWebView /
Safari / desktop navigation and accessibility acceptance is still
required before production promotion. G3 Issue #681 remains HOLD.

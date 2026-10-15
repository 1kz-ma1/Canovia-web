# Pre-Launch IA-2c — No-Plan Overview first value and re-entry

**2026-10-15 | Development `main` only | Not a production launch**

## Problem seen on source inspection

IA-2a/IA-2b permit a new guest or account to enter Overview without
creating a Plan. That Overview previously began with a fairly abstract
`CHOOSE A WORKSPACE` list; it did not explain the near-term value
or show clear direct options for resuming the existing diagnosis and
First Companion journeys. It also based the first-use state on the
absence of only the Study, Development and Career *specialized* Plans,
so an actor with a generic Plan could be treated as a first-time user.

## Narrow implementation and truth boundary

- Define `isPlanless` using **the actual owned Plan collection**,
  not just three specialty Plan candidates; no new persistence and no
  change to Plan authorization/ownership queries.
- For true zero-Plan actors, describe an achievable existing loop
  in plain language: clarify → decide next action → record/review.
  This is introductory copy, not a claim of AI completion or results.
- Give explicit links to **existing paths only**:
  - **First Companion** at `plans.create`: available in stable Core;
  - **Personalization** at `personalization.show`: show only when
    `ReleaseLevelService::allowsMinimum(EarlyAccessCore, user, request)`;
  - **Manual Plan** at `plans.create.manual`: optional creation, with
    no pre-created Plan or automatic AI request.
- Preserve the existing Release-gated Workspace picker below and
  preserve the action-first Overview for actors with any existing Plan.
- When zero-Plan but no Workspace mode choices are permitted, avoid
  falsely rendering an empty "global current action" as an actual
  recommendation.
- Use semantic section heading, ordered steps, and explicit link
  targets/visible labels with mobile-compatible tap areas.

No links escape existing route middleware. No user content, AI calls,
files or Plan data are newly exposed or written from rendering Overview.

## Regression

`OverviewRelevanceV5865Test` checks:
- registered zero-Plan actor sees the explanation, optional choices
  and no empty priority cards;
- guest Welcome `browse` → Overview → either previously implemented
  diagnosis/First Companion without automatically creating a Plan;
- CoreStable L0 still withholds the L1 diagnosis link and its deep URL
  remains release-gated;
- an existing generic Plan does **not** trigger first-use;
- existing Study Plan action surfaces remain unchanged.

Run the broad V56.12 Workspace Resume suite (it already includes
`OverviewRelevanceV5865Test`), Development Prelaunch Preparation,
and every triggered required check on exact final HEAD.

## Boundaries

This does not create a new conversational model, public guest-preview
entitlement, anonymous persistent diagnosis, onboarding analytics
schema, paid AI usage, Store app, promotional site or rollout.
Desktop/Safari/iPhone WKWebView must still be smoke-tested at the
deployment gate. Production Render/Aiven/AWS, migrations, grants
and Release Levels remain unchanged. G3 Issue #681 stays OPEN / HOLD.

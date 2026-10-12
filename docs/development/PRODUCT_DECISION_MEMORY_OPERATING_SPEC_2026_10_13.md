# Product Decision Memory — GitHub-first Operating Specification

> Status: **ACTIVE — documentation/process only** (owner direction: 2026-10-13)
> Scope: Canovia internal product/architecture decisions and follow-up outcomes
> Runtime status: **no application feature, database schema, agent automation or telemetry connector is implemented by this document**
> Authority: latest `main`, `AGENTS.md`, `docs/CANOVIA_PRODUCT_SPEC.md`, implemented specifications, `docs/development/ROADMAP.md`; future proposals remain `docs/future/*`

## 1. Objective

Preserve *why a decision was made, what was expected, what was actually done and measured, and what was learned* without turning every chat message or small fix into a record. This is **Stage 0** of internal Product Memory for future Product Intelligence / Developer Pro. Recording decisions is approved as an immediate, low-overhead development practice; **Product Intelligence/Dev Pro runtime functionality is not promoted** by this document.

The records help future AI and humans inspect evidence, not assume that past judgments are true or that a future proposal is an implementation order.

## 2. What gets recorded

Record decisions with meaningful consequences for:
- product vision, scope, sequencing and first-party/partner selection;
- user-facing workflows, UX, retention, privacy and pricing boundaries;
- architecture, security, data authority and cross-app consent;
- adoption/retention hypotheses, release experiments and costly reversals;
- approved exceptions to an existing technical/product direction.

Usually skip trivial copy edits, small fixes with no meaningful tradeoff, routine implementation mechanics and duplicate decisions already recorded elsewhere. Link PR/Issue/active specification instead.

## 3. GitHub-native structure

- Operating policy: **this file**.
- Dated decision records: `docs/development/decisions/YYYY-MM-DD_<TOPIC>.md`.
- Current product semantics: `docs/CANOVIA_PRODUCT_SPEC.md` and versioned active specs, **not** decision records.
- Latest planned work / acceptance: `docs/development/ROADMAP.md`, **not** the decision folder.
- Concept-only proposals and independent-app designs: `docs/future/*`.
- Actual implementation evidence: GitHub PR, commit, required CI for an exact SHA, deploy SHA/environment, and device/user acceptance **separately**.
- Product result: post-release measured outcome and uncertainty, added as dated follow-up (do not rewrite the pre-release prediction).

No duplicated Canovia Task state, user personal history, external-model raw prompts, raw analytics exports, customer PII, credentials or production secrets in these Markdown files.

## 4. Minimum record contract

Use a short record or section with the following fields, omitting only explicitly inapplicable fields:

| Field | Meaning |
| --- | --- |
| ID / date / decision owner | Stable identifier, decision date and accountable human |
| State | PROPOSED, ACCEPTED, SUPERSEDED or REVOKED for the *decision* |
| Domain / scope | Product, Experience, Platform, Intelligence, Development etc. |
| Problem / context | What was happening and why the choice is relevant |
| Alternatives | Realistic options, including do-nothing, and tradeoffs |
| Decision / rationale | The chosen boundary; what is **not** authorized |
| Hypothesis / expected outcomes | Pre-outcome prediction, possibly `UNDEFINED` when not quantified |
| Safety / privacy guardrails | Non-negotiable constraints and rollback/stop conditions |
| Metric / baseline / window | Definitions, denominator, observation period, possible confounders; `UNMEASURED` until verified |
| Implementation / release evidence | Exact PR, SHA, CI, deploy and device proof; explicit UNKNOWN when absent |
| Observed outcome / evaluation | SUPPORTED / PARTIALLY_SUPPORTED / INCONCLUSIVE / NOT_SUPPORTED / CONTRADICTED / INSUFFICIENT_EVIDENCE, with confidence separately |
| Revisit trigger / next review | Evidence, date or external change that requires reconsideration |

Never invent numeric results. Provisional experiment thresholds must be labeled as **targets/proposals**, not observed benchmarks. No metric or AI confidence score overrides safety, privacy or product-owner approval.

## 5. Lifecycle and authority

1. **Before changing direction:** record the problem, options, human decision, expected effect and intended measurement. A strategic decision can be ACCEPTED even when the feature itself is only FUTURE / CONCEPT.
2. **Before implementation:** confirm latest `main`, applicable Product Spec, lane boundaries, impacted shared contracts, current rollout/release and permissions. Create a small Active Spec when promoting a Future Design.
3. **During implementation:** PR links back to the decision ID, relevant specs, acceptance tests, intended non-goals and risk. The PR is evidence, not automatically proof of deployment.
4. **After release:** attach dated, aggregated measured outcomes, caveats, changes occurring concurrently and links to verified evidence. Preserve original expectations verbatim.
5. **On reevaluation:** append a new dated section or superseding decision with clear parent link. Never silently rewrite the original decision to match the result.
6. **On AI use:** retrieval may summarize the record, but AI recommendations remain hypotheses; no automatic Task mutation, production deploy, billing, cross-app sharing or data access follows from a decision record.

Decision status is distinct from engineering status:
`ACCEPTED product direction != IMPLEMENTED != CI VERIFIED != DEPLOYED != DEVICE/USER VERIFIED`.

## 6. Internal Developer Pro staged approach

- **Stage 0 — NOW / process:** record consequential decisions and pre-outcome hypotheses in GitHub, with existing PR/spec evidence. This is the sole active newly approved work in this document.
- **Stage 1 — CANDIDATE:** minimally define feature/release/metric identities, manual hypothesis tracking and read-only aggregated telemetry checks after early access data exists.
- **Stage 2 — CANDIDATE:** deterministic comparisons, Evidence Health, confounders, outcome linkage and dated Decision Memory.
- **Stage 3 — FUTURE:** bounded AI analysis and Decision Packages, with reviewable provenance.
- **Stage 4 — FUTURE:** owner-approved Specification → Agent → tests → PR, measured release impact and strictly bounded change permissions.
- **Stage 5 — FUTURE:** productize a proven internal system as external Developer Pro, separate UX/entitlement/pricing/security after actual evidence.

Prioritize *internal learning leverage*, not a finished Dev Pro checkout/UI/enterprise feature. Do not couple this documentation work to production readiness gates or slow the safe early-access release.

Canonical future references:
- [Developer Pro / AI Development Orchestration](../future/DEVELOPER_PRO_AI_DEVELOPMENT_ORCHESTRATION.md)
- [Product Intelligence / Automatic Improvement](../future/PRODUCT_INTELLIGENCE_AUTOMATIC_IMPROVEMENT.md)
- [Product Creation System](../future/PRODUCT_CREATION_SYSTEM.md)

## 7. Privacy and data quality

- Capture **product decisions**, not end-user personal profiles or conversation extracts.
- Use aggregate or synthetic examples in repo documentation; avoid cohort sizes or small slices that re-identify users.
- Distinguish observed facts, user statements, derived interpretations and untested speculation.
- Validate event completeness, sample size, selection bias, deploy version and confounders before describing effects as causal.
- An outcome of `UNKNOWN` or `INSUFFICIENT_EVIDENCE` is valid. AI should not create a convincing story from faulty instrumentation.
- The privacy and security acceptance for a public launch is a separate Platform/release gate. This file does not certify security.

## 8. First record and cadence

Start with [2026-10-13 Canovia strategy and internal Dev Pro direction](decisions/2026-10-13_CANOVIA_STRATEGY_DEVPRO_WORLD.md).

Cadence: create/append when a consequential decision occurs; follow up when a linked release has comparable measured outcomes. Periodic review may identify stale decisions, but is not an autonomous background job. Do not require manual status entry on every work session.

## 9. Adoption criteria for this process

Successful Stage 0 means a later developer/AI can answer (with exact source links) **what was decided, why, what was expected, what actually shipped, and what remains unknown**, with fewer repeated discussions. If manual recording overhead grows, simplify the template before adding a database/UI.

# Canovia Future Architecture Overview

> Status: **Concept / Architecture preservation context**  
> Updated: 2026-10-07  
> Implementation status: **this document does not implement Future components**  
> Current authority: latest `main` → `docs/CANOVIA_PRODUCT_SPEC.md` → implemented/versioned specs → `docs/future/*`

---

## 1. What this document is

This document is **not a replacement architecture for current Canovia**.

It exists to show:

> how currently implemented Canovia capabilities may evolve and connect into a broader platform over time.

Future components are not current product requirements merely because they appear here.

AI Coding Agents must treat status labels literally:

- **CURRENT / ACTIVE** = implemented/current contract. Follow linked Active Specs.
- **PARTIAL / FOUNDATION** = some enabling foundations exist, but the broader concept is not complete.
- **FUTURE / CONCEPT** = architecture direction only. Do not implement without explicit promotion.

Promotion still requires:

```text
Future Design
→ latest main investigation
→ explicit product decision
→ versioned Active Spec
→ implementation
→ tests
→ Product Spec sync
```

---

## 2. Current → Future map

```text
CURRENT / ACTIVE
────────────────────────────────────────────────────────────

Canovia Core Loop
Goal → Plan → Task / Action → Evidence → Replanning
[ACTIVE]
  docs/CANOVIA_PRODUCT_SPEC.md

Personalization Bootstrap
Initial Diagnosis → Context → Plan Seed
[ACTIVE]
  docs/CANOVIA_PERSONALIZATION_SPEC.md
  docs/V58.25_PERSONALIZATION_BOOTSTRAP.md

Capability Activation
Need → Preview → Interest → Readiness → Setup
[ACTIVE]
  docs/V58.26_CAPABILITY_ACTIVATION_FOUNDATION.md

Living Profile
Self-reported / Observed / Inferred
Context Update Candidate
[ACTIVE FOUNDATION]
  docs/V58.27_LIVING_PROFILE_FOUNDATION.md
  docs/V58.28_STUDY_BEHAVIOR_PERSONALIZATION.md
  docs/V58.29_PLAN_LIFECYCLE_PERSONALIZATION.md
  docs/V58.30_PLAN_COMPLETION_FINGERPRINT.md
  docs/V58.31_RETURN_AFTER_ABSENCE_PERSONALIZATION.md
  docs/V58.32_CONTEXT_CANDIDATE_EVIDENCE_REOPEN.md
  docs/V58.33_STUDY_REVIEW_CYCLE_PERSONALIZATION.md
  docs/V58.34_LONG_USAGE_WINDOW_PERSONALIZATION.md

Study Workspace / Study Intelligence
[ACTIVE]
  docs/V56.13_STUDY_STATE_FIRST_WORKSPACE.md
  docs/V56.0_STUDY_EXAM_CONVERGENCE_POLICY.md
  docs/V58.3_STUDY_WORKSPACE_SURFACES.md

Development Workspace
[ACTIVE]
  docs/V54.4_DEVELOPMENT_WORKSPACE.md
  docs/V58.0_DEVELOPER_WORKSPACE_SURFACES.md

GitHub Integration / Evidence / Development Context
[ACTIVE]
  docs/V57.0_GITHUB_INTEGRATION_STABILIZATION.md
  docs/V57.5_GITHUB_CONNECTION_PRIVATE_REPOSITORY_UNIFICATION.md
  docs/V57.2_DEVELOPER_TASK_ASSOCIATION.md

Telemetry / Early Access Observability
[ACTIVE FOUNDATION]
  docs/V58.23_EARLY_ACCESS_OBSERVABILITY.md

Feature Access / Entitlement / Release Level
[ACTIVE]
  docs/V40.6_ENTITLEMENT_FOUNDATION.md
  docs/CANOVIA_MONETIZATION_SPEC.md
  docs/CANOVIA_RELEASE_LEVEL_SPEC.md

Execution Ecosystem Foundation
[PARTIAL / FOUNDATION]
  docs/V55.6_EXECUTION_ECOSYSTEM_FOUNDATION.md
  docs/EXECUTION_ECOSYSTEM_DRAFT.md

Opt-in Coding Agent Handoff
[PARTIAL / FOUNDATION]
  docs/V57.8_OPT_IN_CODING_AGENT_HANDOFF.md

                         │
                         │ evolve / extend
                         ▼

FUTURE / CONCEPT
────────────────────────────────────────────────────────────

Canovia Platform / Ecosystem
[FUTURE / CONCEPT]
  Identity Contract
  Shared Context
  Consent / Scope
  Cross-app Entitlement
  Activity / Capability Contract
  Curated Execution Network

Canovia Family
[FUTURE / CONCEPT]
  Canovia Core
  Developer Pro
  possible Study / Routine / Calendar products
  partner products

Sign in with Canovia
[FUTURE / CONCEPT]
  OAuth / OIDC / PKCE candidate
  identity != data permission

Shared Personalization Context
[FUTURE / CONCEPT]
  Account Context
  Domain Context
  App Context
  freshness / provenance / consent

Differential Onboarding
[FUTURE / CONCEPT]
  reuse authorized known Context
  ask only Missing / Stale / Unauthorized Context

Bidirectional Execution Ecosystem
[FUTURE / CONCEPT]
  Canovia → Execution Request → Provider
  Provider → Result / Evidence → Canovia → Replanning

Product Intelligence
[FUTURE / CONCEPT]
  Evidence Pipeline
  Hypothesis Intelligence
  Decision Memory
  Data Lineage
  Evidence Health
  Pattern Hierarchy

Opportunity Engine
[FUTURE / CONCEPT]
  detect unmet user / execution / provider opportunities

Automatic Improvement Engine
[FUTURE / CONCEPT]
  Reality → Evidence → Hypothesis → Decision
  → Implementation → Measurement → Learning → Revalidation

Product Creation System
[FUTURE / CONCEPT]
  Discovery → Value Design → Specification
  → Implementation → Review → Telemetry → Validation

Incubation / Product Factory
[FUTURE / CONCEPT]
  internal prototype
  → validated Canovia feature
  → standalone signal
  → spin-out
  → reconnect as Execution Provider

Developer Pro
[FUTURE / CONCEPT]
  productized Product Context + Product Intelligence
  + Development Orchestration

Multi-product Canovia Company
[FUTURE / VISION]
  multiple first-party products + partner network
```

---

## 3. Status matrix

| Area | Status | Current Source of Truth | Future direction |
|---|---|---|---|
| Canovia Core Loop | CURRENT / ACTIVE | `CANOVIA_PRODUCT_SPEC.md` | Remains orchestration / replanning core |
| Personalization Bootstrap | CURRENT / ACTIVE | `CANOVIA_PERSONALIZATION_SPEC.md`, V58.25 | Feeds shared/context-aware platform |
| Living Profile | CURRENT / ACTIVE FOUNDATION | V58.27, V58.28 | More domain adapters, freshness, confidence, external context |
| Capability Activation | CURRENT / ACTIVE FOUNDATION | V58.26 | Generic provider/capability activation |
| Study | CURRENT / ACTIVE | Study Active Specs | Could remain Core surface or become deeper first-party product; no decision here |
| Development | CURRENT / ACTIVE | Development Active Specs | Foundation for Developer Pro |
| GitHub Integration | CURRENT / ACTIVE | V57.x | One provider in broader execution/provider architecture |
| Telemetry | CURRENT / ACTIVE FOUNDATION | V58.23 | Input to Evidence Pipeline, not equivalent to Product Intelligence |
| Entitlement | CURRENT / ACTIVE | V40.6 + Monetization Spec | Could extend across Canovia Family |
| Release Level | CURRENT / ACTIVE | Release Level Spec | Remains separate from entitlement/future family identity |
| Execution Ecosystem | PARTIAL / FOUNDATION | V55.6 + draft | Curated provider network + bidirectional execution |
| Coding Agent handoff | PARTIAL / FOUNDATION | V57.8 | One execution provider pattern for Developer Pro |
| Shared Context across apps | FUTURE / CONCEPT | none | Platform Context Contract |
| Sign in with Canovia | FUTURE / CONCEPT | none | Identity + consent-scoped cross-app authorization |
| Differential Onboarding | FUTURE / CONCEPT | none | Missing-only questions |
| Product Intelligence | FUTURE / CONCEPT | Developer Pro Future Design only | Evidence → hypothesis → learning |
| Opportunity Engine | FUTURE / CONCEPT | none | Admin product discovery |
| Automatic Improvement | FUTURE / CONCEPT | none | Closed evidence / decision / learning loop |
| Product Creation System | FUTURE / CONCEPT | none | Repeatable product discovery/build/validation system |
| Canovia Family | FUTURE / CONCEPT | none | Multi-product family sharing platform contracts |
| Developer Pro | FUTURE / CONCEPT | Developer Pro Future Design | External productization of internal development/intelligence system |

---

## 4. Architectural direction

Canovia should not evolve toward:

> one giant application that internally implements every specialized activity.

The target direction is:

```text
Canovia Core
understands Goal / Context / Progress / Constraints
↓
chooses or recommends the next execution
↓
best available execution surface
  - Canovia Core
  - first-party specialist
  - partner provider
  - external tool
↓
Activity / Result / Evidence
↓
Canovia Context + Plan update
```

Canovia's long-term value is therefore the continuity of:

- user understanding
- goal/plan context
- execution selection
- evidence
- decision history
- replanning

not ownership of every UI.

---

## 5. Current Personalization → Future Shared Context

Current Active contract:

- Initial Diagnosis is a starting hypothesis, not a permanent profile.
- self-reported / observed / inferred remain separate.
- Living Profile can update derived context without rewriting user answers.
- high-impact changes can require confirmation.

Current Source of Truth:

- `docs/CANOVIA_PERSONALIZATION_SPEC.md`
- `docs/V58.25_PERSONALIZATION_BOOTSTRAP.md`
- `docs/V58.27_LIVING_PROFILE_FOUNDATION.md`
- `docs/V58.28_STUDY_BEHAVIOR_PERSONALIZATION.md`
- `docs/V58.29_PLAN_LIFECYCLE_PERSONALIZATION.md`
- `docs/V58.30_PLAN_COMPLETION_FINGERPRINT.md`
- `docs/V58.31_RETURN_AFTER_ABSENCE_PERSONALIZATION.md`
- `docs/V58.32_CONTEXT_CANDIDATE_EVIDENCE_REOPEN.md`
- `docs/V58.33_STUDY_REVIEW_CYCLE_PERSONALIZATION.md`
- `docs/V58.34_LONG_USAGE_WINDOW_PERSONALIZATION.md`
- `docs/V58.35_PERSONALIZATION_CONFIDENCE_CALIBRATION.md`

Future direction:

```text
Current user-scoped Personalization Context
↓
source-aware / scope-aware Context
↓
Account / Domain / App Context
↓
consent + freshness + provenance
↓
authorized Shared Context across Canovia Family
↓
Differential Onboarding
```

This Future document does not redefine current Personalization fields or database schema.

---

## 6. Current Execution Foundation → Future Execution Network

Current foundations already exist for:

- Execution Ecosystem domain model
- provider connection concepts
- GitHub execution/evidence
- opt-in Coding Agent handoff
- Capability Activation

Current Source of Truth:

- `docs/V55.6_EXECUTION_ECOSYSTEM_FOUNDATION.md`
- `docs/EXECUTION_ECOSYSTEM_DRAFT.md`
- `docs/V57.8_OPT_IN_CODING_AGENT_HANDOFF.md`
- current GitHub Active Specs

Future direction:

```text
Current provider-specific integrations
↓
normalized Activity / Capability Contracts
↓
curated providers
↓
optional bidirectional execution
↓
result/evidence return
↓
Canovia replanning
```

The Future model must not cause existing provider integrations to be rewritten prematurely.

---

## 7. Telemetry is not Product Intelligence

Current telemetry means:

- events are recorded
- funnels/activation can be measured
- release behavior can be observed

Product Intelligence is a future layer that additionally requires:

- evidence validation
- metric definitions
- hypothesis history
- confounder awareness
- confidence
- lineage
- decision memory
- revalidation

Therefore:

```text
Telemetry [CURRENT FOUNDATION]
!=
Product Intelligence [FUTURE]
```

---

## 8. Development Foundations are not Developer Pro

Current Development already has:

- dedicated workspace
- GitHub context/evidence
- implementation briefs
- triage
- opt-in Coding Agent handoff

These are real current capabilities.

Developer Pro is still Future / Concept.

```text
Current Development
+ Execution Foundation
+ Product Intelligence
+ Specification / Decision Memory
+ mature implementation orchestration
↓
possible Developer Pro product
```

Canonical Developer Pro Future Design:

- `docs/future/DEVELOPER_PRO_AI_DEVELOPMENT_ORCHESTRATION.md`

---

## 9. Future responsibility boundaries

Do not collapse these into one service:

```text
Identity
Context
Consent
Entitlement
Activity
Capability
Telemetry
Evidence
Hypothesis
Decision Memory
Pattern
Opportunity
Product Intelligence
Product Creation
```

These may share infrastructure later, but they represent different authority and trust boundaries.

---

## 10. Non-goals of this Future Architecture work

This Future Architecture documentation does **not** authorize implementation of:

- OAuth / OIDC Provider
- Sign in with Canovia
- Shared Context cross-app database
- Partner API
- Execution Marketplace
- Routine / Calendar standalone apps
- Opportunity Engine
- Automatic Improvement Engine
- Hypothesis Engine
- full Evidence Pipeline
- Data Lineage engine
- Product Generator
- automatic PR / merge
- multi-product billing
- organization tooling
- Developer Pro product launch

Any of these requires explicit promotion.

---

## 11. Future documents

This overview routes to three conceptual areas:

1. `docs/future/CANOVIA_PLATFORM_ECOSYSTEM.md`
   - Family / Identity / Context / Consent / Entitlement
   - Activity / Capability / Execution Ecosystem

2. `docs/future/PRODUCT_INTELLIGENCE_AUTOMATIC_IMPROVEMENT.md`
   - Evidence Pipeline
   - Hypothesis / Decision Memory
   - Pattern / Opportunity
   - Automatic Improvement

3. `docs/future/PRODUCT_CREATION_SYSTEM.md`
   - Incubation
   - first-party / partner
   - Product Factory
   - Developer Pro relationship
   - multi-product company vision

Developer Pro remains detailed separately:

- `docs/future/DEVELOPER_PRO_AI_DEVELOPMENT_ORCHESTRATION.md`

---

## 12. Promotion principle

The safest promotion order is generally:

```text
Current Foundation
↓
validate actual user / operator need
↓
small Active Spec
↓
bounded implementation
↓
evidence
↓
expand contract
```

Do not promote an entire Future Architecture layer at once.

# Adaptive Context Pipeline v0.5 — Non-destructive Migration and Acceptance Plan

> Updated: 2026-10-12 / Status: **review proposal, documentation-only**
> Review target: existing main implementation, not a greenfield app.
> Companion: [architecture candidate](ADAPTIVE_CONTEXT_PIPELINE_V05_ARCHITECTURE_REVIEW_2026_10_12.md).
> No code, DB, production, Render, OAuth, MCP, billing, release or pricing change is authorized by this document.

## 1. Source-of-truth hierarchy and audit scope

**Verified in this review**: static reading of selected main files/docs. **Not verified**: all callers of those services, current DB contents or migrations at runtime, CI/test execution, production deploy or physical-device outcomes. Names below do not establish that an end-to-end feature exists.

Authoritative starting points:
- [V53.0 Intelligence Contract](../V53.0_INTELLIGENCE_CONTRACT.md) and [V53.1 State/Evidence](../V53.1_STATE_EVIDENCE_FOUNDATION.md): reuse durable TaskEvidence/domain data; normalized EvidenceObservation, StateSnapshot, Decision, Action, Outcome.
- [V53.2 Decision/Readiness](../V53.2_DECISION_READINESS_ENGINE.md), [V53.3 Reasoning Router](../V53.3_REASONING_ROUTER.md): deterministic, explainable fallback; optional bounded AI.
- [V55.8 External Activity Validation](../V55.8_EXTERNAL_ACTIVITY_INTEGRATION_VALIDATION.md) and [V55.9 Authenticated Activity Intake](../V55.9_PROVIDER_CONNECTION_AUTHENTICATED_ACTIVITY_INTAKE.md): ExecutionActivity→TaskEvidence boundary, provider auth and scoped ingestion.
- [V58.72](../V58.72_MULTI_EVIDENCE_ACTION_DRAFT_REVISIONS.md), [V58.73](../V58.73_DOMAIN_AWARE_ACTION_DRAFT_EVIDENCE.md): explicit owner acceptance, revision/fingerprint conflict guards.
- [V58.26 Capability Activation](../V58.26_CAPABILITY_ACTIVATION_FOUNDATION.md), [Personalization Spec](../CANOVIA_PERSONALIZATION_SPEC.md): observed/self-reported/inferred split and existing GitHub-specific setup guidance.
- [MCP Read-only Resource Contract](MCP_READ_ONLY_RESOURCE_CONTRACT.md): independently gated, private delegated Development Plan read; **does not demonstrate live ChatGPT OAuth or write permissions**.
- [Product](../CANOVIA_PRODUCT_SPEC.md), [Monetization](../CANOVIA_MONETIZATION_SPEC.md), [Roadmap](ROADMAP.md), [State-first WIP](../wip/2026-10-08_STATE_FIRST_PRE_RELEASE_EXECUTION_SPEC.md).

## 2. Migration disposition: reuse / adapt / new only when justified

| Capability in v0.5 | Existing evidence (representative files) | Disposition / why |
| --- | --- | --- |
| Normalized facts | app/Intelligence/Adapters/TaskEvidenceAdapter.php; app/Models/TaskEvidence.php | **Reuse** existing source record and controlled projection; no duplicate universal raw fact DB by default |
| Domain state and decisions | app/Intelligence/{Study,Development,Career}/*PlanIntelligenceService.php; app/Intelligence/Services/{StateSnapshotStore,DecisionTraceStore}.php | **Reuse/adapt** existing domain pipeline, evolve provenance and user/purpose-scoped query APIs |
| Bounded reasoning | app/Intelligence/Services/ReasoningRouter.php | **Reuse**, prove allowed candidate set and deterministic fallback; do not add general autonomous AI router |
| Adaptive Learning | app/Services/AdaptiveLearningCandidateService.php | **Reuse**, preserve immutable answered/run snapshots; combine with cross-domain context only through explicit contract |
| GitHub observation | app/Services/GitHubDevelopmentObservationService.php; app/Models/DevelopmentActivityObservation.php | **Adapt** replay/reconciliation guarantees; do not migrate live observations blindly |
| Living Profile / User Fit | app/Services/{PersonalizationContextService,PersonalizationLivingProfileService}.php; app/Models/UserPersonalizationContext.php | **Adapt**, retain source layers and user choice; add derivation provenance/invalidations before cross-domain expansion |
| Goal context | app/Models/GoalContextFact.php; app/Services/GoalContextService.php | **Reuse**, fact confidence/confirmed/candidate/superseded distinction; Goal remains optional to initial activity capture |
| Evidence-first action proposal | docs/V58.72_* and V58.73_*; app/Models/PlanActionDraftRevision.php | **Reuse/adapt**, revalidation & owner confirmation instead of new unsafe mutation route |
| Capability selection + setup | app/Services/{ExecutionCapabilityResolver,CapabilityActivationService}.php | **Extend**, existing per-Task choice and GitHub-only activation are not yet a generic cross-domain Capability Registry |
| MCP actor/grant scope | app/Services/McpDelegatedPlanAccessPolicy.php; docs/development/MCP_READ_ONLY_RESOURCE_CONTRACT.md | **Preserve**, no weakening, expansion requires separate provider compatibility and scopes |
| Unified fact reference / lineage / invalidation | Multiple existing sources and fingerprints; no single verified implementation meeting all v0.5 conditions | **Prototype/adapt** with minimal index/dependency metadata only where gap is proven |
| Purpose-bound cross-domain resolver | Existing domain state and personalization, but not a verified central cross-domain policy-complete resolver | **New orchestration adapter**, not replacement of domain stores |
| Premium 2 multi-activity evaluator | Existing per-Plan action selection does not establish reliable AP-vs-development recheck loop | **New bounded slice**, approval-safe and opt-in |
| Capability Readiness across domains | GitHub-specific V58.26 foundations | **Generalize incrementally**, published status/entitlement/access/coverage kept separate |
| Pricing Free/Premium 1/Premium 2 | current docs/CANOVIA_MONETIZATION_SPEC.md uses Free/Premium/Pro/Dev Pro | **Separate decision & migration**, no automatic rename, purchase enable, price or entitlement rewriting |

## 3. Critical static-review flags: investigate, do NOT claim a live defect

### G1. State snapshot tenant/actor scope (security review P0 before shared Resolver)
The static signature of app/Intelligence/Services/StateSnapshotStore.php::latest(domain, scopeType, scopeId) does **not itself** include user_id. It may rely on authorized caller-provided scope. **Before exposing it through a shared actor-facing interface**, enumerate all call sites, tenant/guest/team cases and row-level access, and add explicit actor-bound service contracts where needed. Verify foreign-user Plan/state references cannot be guessed or reused through cache. No unproven claim of existing information leakage.

### G2. Provider event ordering (data integrity P0 before expanded ingestion)
app/Services/GitHubDevelopmentObservationService.php::persist uses updateOrCreate with normalized external_key and overwrites state/last_observed_at. The method alone does not prove stale-delivery reconciliation. Trace the entire ProcessGitHubWebhookDelivery, authoritative snapshot, duplicate delivery and version checks. Add a failing test **before** any fix; never update prod webhook state blindly. Distinguish entity latest state from event history.

### G3. Derived context deletion and policy revocation (P0 before cross-domain/private external read)
V53 fingerprints and source references exist, but this review has **not proven** global dependency invalidation for a corrected/deleted source or a revoked consent. Map all persistent Intelligence Snapshot/Decision/Action, Living Profile, cached reasoning runs, AI context, search projections and read surfaces. Stale/private derivations must be blocked before recompute, including when recompute fails. Deletion/retention/legal audit exceptions need scoped owner decisions.

### G4. Premium semantics drift (product P1, separate PR)
Current published/internal specification Free=Execute / Premium=Guide / Pro=Understand remains canonical until replaced through a separately approved product/entitlement migration. The new tier terminology describes experience differentiation only. Avoid silently changing FeatureKey, license grants, checkout, advertising, billing, release or Native AI quotas.

### G5. Guest and collaborative data domains (policy P0 before multi-domain launch)
Some original Evidence/Study activity and plans support guest tokens, owner and collaboration. A design with nullable owner IDs must explicitly prevent cross-guest, foreign-Plan, team/nonteam and ownership-transfer leaks. Model source identity and authorization separately from a free-form reference string.

## 4. Staged implementation roadmap (proposal, not active backlog)

| Slice | Work | Minimum acceptance/gate | Safe exit / rollback |
| --- | --- | --- | --- |
| **P0: Baseline & contracts** | Inventory current stores, readers, references, missing coverage, domain adapters and authorization; tests for G1–G5 | Exact main SHA and owned code routes; a mapping signed off by owning lanes; failing repro if a defect is claimed; owner approves any new persisted sensitive fields | No runtime change; revert docs/branch |
| **P1: Evidence references and lifecycle** | Implement the thinnest shared fact view/index over existing source stores; event dedupe, provenance, tenant bounds, source revisions, invalidate-on-change | Permission/deletion/replay tests; tests prove no double count, no cross-user references and no stale restricted cache; bounded ingest latency | Flag off read projection, retain original source rows; any additive schema migrated separately |
| **P2: Free activity integration** | Learning + Development real data, timeline/calendar and measured ratios with explicit coverage; later Career after privacy acceptance | Observed facts match source sample; no false duration or aggregate achievement; corrections and missing connector shown; Free works without Native AI | Disable new projection/UI and fall back to existing workspace; preserve records |
| **P3: Purpose-bound Resolver** | Domain-aware contextual projection for selected authorized purposes; cached source+policy version, optional bounded AI; Premium 1 consumes domain detail | Mixed-owner, privacy, consent revoke, cache miss/hit, stale model, 3-domain isolation; baseline deterministic; token/cost caps | Feature-flag disable resolver while domain Study/Development continues |
| **P4: Premium 2 evaluation and proposals** | AP vs Canovia bounded state evaluation; Decision Context/deferral, domain session prepare and explicit commit | No duplicate nudge loops, unknown events not absence, stale approval rejected, idempotent commit, no Task status auto-change, accessible fallback without push | Switch off evaluator/notifications; no irreversible cross-domain actions |
| **P5: User Fit and Readiness** | Existing V58.26 guided activation generalized with declared implemented/readiness capability metadata | Real per-feature readiness, incomplete setup honesty, post-purchase value checks, privacy controls, downgrade/Free recommendation | Disable personalized recommendation, retain existing Feature Access / pricing rules |
| **P6: Commercial/release decision (separate)** | Evaluate prices/AI margins, tiers, grandfathering, ad unit economics, iOS StoreKit/payment rules, push permission and review policy | Explicit owner product/commercial authorization, migration contract, test purchase and rollback, App Store/operational readiness | No changes by default |

**Cross-cutting gate:** each slice is a separate short-lived feature branch and PR with exact CI, tenant isolation, relevant migrations independently rehearsed, deploy/device evidence separately recorded. Never merge this design PR and silently infer that production or billing behavior changed. Coordinate Intelligence (lead), Learning, Development, Expansion/Career and Platform/security through Orchestrator; shared Product/Entitlement hot spots require explicit conflict check.

### No speculative database migration

Do NOT start by creating a universal canonical_facts table or graph database. First demonstrate a query/data integrity gap that source-of-truth + normalized adapter + small fact index cannot meet. Test additive fields and migrations on disposable/isolated DB and verify preservation of existing data; production schema/data changes need a dedicated PR and reviewed release/rollback procedure. Never use production as a prototype.

### No speculative algorithm/product claim

Do not present current Adaptive Learning as guaranteed per-answer AI optimality; do not assert study pass probability from raw correctness alone. Do not claim current ChatGPT MCP reads/writes, native push, all social/profile data or autonomous execution works without observed real E2E evidence.

## 5. Acceptance suite and adversarial matrix

| ID | Scenario | Required result |
| --- | --- | --- |
| A01 | Foreign actor queries another owner's Study/GitHub/Career source, snapshot, derived result or public-API cache | Identical unauthorized response; no content, metadata or inferred attribute leaked |
| A02 | Guest vs registered actor and collaborative Plan member role changes | Guest and team scopes remain isolated, role revocation takes effect before next read/write |
| A03 | Exclude Career/secret category while other domains are authorized for ChatGPT output | Retrieval never includes excluded data; derived analyses cannot disclose hidden career fact indirectly |
| A04 | Correct/delete/retract a practice attempt after mastery and recommendation materialized | Dependent current data invalidates first; safe recompute or UNKNOWN; no old prediction read |
| A05 | Revoke connector/AI purpose scope during queued evaluation or active session | Pre-fetch, pre-disclosure and pre-commit policy checks deny newly forbidden operations |
| A06 | Same GitHub delivery replayed; provider reports old/new states out of order | No count inflation or stale state rollback; unknown ordering triggers verified reconciliation |
| A07 | Provider source offline or ChatGPT work unobserved | Output distinguishes unknown/not synced from inactivity and labels evidence coverage/time |
| A08 | User selects "development now" instead of proposed AP study | Decision stored as temporary choice, duplicate notifications suppressed; reevaluate only at valid trigger |
| A09 | Evaluator writes proposal → event handler runs again | Bounded processing, no identical proposal/notification storm; limits on cost and retries |
| A10 | Proposal accepted after source revision or permission change | Revalidate before commit; reject stale and offer updated diff; no accidental mutation |
| A11 | External AI sends "approved" without authenticated Canovia user | No commit; client-provided approval is untrusted |
| A12 | Commit replay / network timeout / partial external write | Exactly-once effect where supported; otherwise idempotency/reconciliation record and safe retry |
| A13 | 10 Learning questions, 10 commits and 1 interview on same day | Separate units and coverage, no meaningless universal effort percentage |
| A14 | New domain implements Facts but no capability operations | Source recording works internally; user-facing readiness does not falsely promise Premium 2 |
| A15 | Downgrade Pro → Free while data remains | No destruction of core facts; export/correction/deletion still accessible; premium analysis gated |
| A16 | Paid user missing GitHub/calendar connection | Shows exactly usable features, missing setup and optional value; no "fully active" mislabel |
| A17 | AI provider unavailable or cost limit reached | Deterministic fallback where meaningful; no dropped facts or false success |

Only claim an acceptance test **passed** after running it against the exact SHA and actual relevant backend. Test design ≠ passing test; CI ≠ deployed; deployed ≠ device-verified. Security invariants also require negative tests over multiple permission combinations and manual review.

## 6. Observable metrics and user-value evaluation

Engineering: source ingestion success/lag, duplicate replay rate, stale derivative withheld rate, resolver P95 latency, cache hits per policy scope, AI token/yen usage, evaluation recheck count and notification suppression, access denies, commit conflicts and partial operations.

User-facing value: time to first genuine automatically recorded activity, setup steps until first meaningful view, manual entry reduction, correction frequency, meaningful proposal acceptance/defer ratio (NOT a pure conversion target), user-reported interruption burden and whether a suggested transition actually helped. Avoid inferring effectiveness solely from app opens, commits or exam scores.

Cost/market evidence: compare Free infra per active user, Premium 1 AI costs, Premium 2 evaluation cadence and opt-in conversion/retention. Advertising-funded analysis needs privacy review and positive unit economics; no detailed personal context transferred to ad networks as a default.

## 7. Coordination and release checklist

Before implementation:
- [ ] Current main SHA and relevant open PRs, AGENTS and active lane handoffs rechecked.
- [ ] Owner and Intelligence/Platform review the contract changes and G1–G5 risk ranking.
- [ ] Existing source-of-truth models, all read call sites, cache usage and derived data lifecycles inventoried.
- [ ] Chosen smallest implementation slice has failure repros and explicit non-goals.
- [ ] Exact data/schema/API/entitlement changes reviewed separately and compatible with Free and guest/team usage.
- [ ] Privacy, retention, purpose-bound access, AI cost bounds and observability included.

For each implementation PR:
- [ ] Tests pass at exact SHA; required CI complete.
- [ ] Diff, permissions and production impact reviewed; additive migrations rehearsed separately if applicable.
- [ ] No automatic production deploy, external IdP enablement, MCP write, new payments or iOS push enabled by documentation.
- [ ] Regression and real-device acceptance recorded independently where relevant.
- [ ] Roadmap/Product/Monetization specification updated **only after** the related product/feature implementation decision, retaining historical traceability.

**Deliverable status: this review documents a feasible non-destructive path, not proven implementation, not authorization to change main or production.**

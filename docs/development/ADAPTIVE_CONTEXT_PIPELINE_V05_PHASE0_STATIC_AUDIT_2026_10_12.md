# Adaptive Context Pipeline v0.5 — Phase 0 Static Baseline Audit (2026-10-12)

> Status: **P0 STATIC AUDIT IN PROGRESS — NOT ACCEPTANCE / NOT IMPLEMENTATION APPROVAL**
>
> Base: `1kz-ma1/Canovia-web` `main@9f7ae169ff5b047d4177f2fabaa26d6d32909447` (merged PR #465).
>
> Scope: review of selected source paths and existing test coverage. No application, migration, provider, authorization, pricing, MCP or Render configuration changes. **No PHP/Laravel tests, isolated DB replay, production inspection, deployment or device validation were executed during this audit.** This is not proof that any vulnerability has been exploited.

## 1. Authority and non-goals

- Follow `AGENTS.md`, `docs/CANOVIA_PRODUCT_SPEC.md`, `docs/development/ROADMAP.md`, [v0.5 architecture review](ADAPTIVE_CONTEXT_PIPELINE_V05_ARCHITECTURE_REVIEW_2026_10_12.md) and [migration/acceptance plan](ADAPTIVE_CONTEXT_PIPELINE_V05_MIGRATION_ACCEPTANCE_2026_10_12.md).
- The v0.5 documents remain reviewed **proposals**, not promoted billing or universal runtime contracts. Existing V53/V55/V58 contracts continue to govern behavior.
- No new universal Fact DB, new AI router, premature Premium tier migration, external MCP read/write enablement, automatic Plan creation or source-evidence rewriting.
- This is a **bounded initial audit**, not a complete enumeration of every call site, data source, cache, guest/team path or live DB row. Recheck current main and shared ownership before implementing any fix.

## 2. Reusable implementation actually observed

| Concern | Existing implementation | Current assessment |
| --- | --- | --- |
| Source evidence/state | `TaskEvidence`, `ExecutionActivity`, `DevelopmentActivityObservation`, domain builders, `StateSnapshotStore` | Reuse; do not build second raw evidence store |
| Domain intelligence | Study, Development, Career `*PlanIntelligenceService` | Existing state pipelines are predominantly Plan-scoped; this does **not** establish a Plan-free unified activity experience |
| State/decision/action persistence | `StateSnapshotStore`, `DecisionTraceStore`, `ActionProjectionStore` | Fingerprints and reference identifiers exist; actor-scoped read and lifecycle propagation need verification |
| Signed provider activity intake | `ProviderActivityIntakeController` → `ProviderConnectionService` → `ProviderExecutionContextService` → `ProviderActivityIntakeService` → `ExecutionActivityService` | Connection authentication and opaque context checks precede persistence; business-key namespace remains a separate invariant |
| GitHub activity | `ProcessGitHubWebhookDelivery` → `GitHubDevelopmentObservationService` | On delivery, code fetches current GitHub entity snapshots; storage uses per-Plan keys |
| Deletion | `AccountDeletionService` | Explicitly deletes several Intelligence/Execution tables on *account* deletion; not evidence of per-source derived-context invalidation |
| Human choice | `ActionProjectionStore` and existing Plan Action Draft revisions | Current Action dismissal is not yet a general, independently durable cross-domain Decision Context |

## 3. Phase 0 risk register (static evidence vs proof)

### G1 — State Snapshot actor isolation: **P0, boundary not established**

- `app/Intelligence/Services/StateSnapshotStore.php`: `latest(domain, scopeType, scopeId)` has no actor/authorization parameter. `persist()` uses `state_reference` as its `firstOrCreate` identity.
- `app/Intelligence/Support/IntelligenceFingerprint.php`: state/snapshot references include domain, scope and facts, **not an explicit actor key**. Distinct Plan IDs help when Plan scope is verified; they are not a general-purpose tenant security boundary.
- The inspected `IntelligencePresentationHistoryService::forPlan()` reads by `plan_id` and domain. Current Study/Development/Career persistence passes `plan->id` and `plan->user_id`. This limits what can be inferred: an actual cross-user read was **not** reproduced.
- **Gate:** inventory all public/controller/MCP and internal readers, verify authorization *before* Plan acquisition, mixed guest/team/ownership cases, and test direct foreign-scope access. Design an actor-bound read API before adding a common Resolver. Preserve historical snapshots; no destructive rewrite by inference.

### G2 — GitHub ordering/replay: **P0, partially mitigated, not proven**

- `GitHubDevelopmentObservationService::factFromTarget()` fetches **current** GitHub pull/issue/branch/commit/deployment state through the repository writer rather than trusting the webhook body as the current state.
- `persist()` uses `plan_id + provider + external_key`; the `development_activity_observations` migration enforces that unique key. Existing test `DeveloperActivityObservationV571Test::test_repeated_delivery_updates_same_observation_idempotently` covers same-entity replay.
- However, `updateOrCreate()` updates state and `last_observed_at` without comparing source revision; overlapping requests, stale upstream reads, changed/removed visibility and bootstrap/webhook races are not covered by the inspected test.
- **Gate:** deterministic tests for new→old/old→new delivery, concurrent writes, retry after fetch failure, event vs entity identity and an authoritative GitHub snapshot with a newer revision. No live webhook history rewrite.

### G3 — Derived-context invalidation: **P0, not proven**

- V53 Intelligence snapshots/traces/projections contain evidence references and fingerprints; account deletion explicitly removes several dependent rows.
- There is no demonstrated **global** dependency invalidator for an individual changed/deleted source or a revoked provider/purpose grant across Intelligence, Living Profile, AI context projections and caches.
- **Gate:** enumerate source-to-derivation references and read surfaces, then test a source correction, source deletion and permission revocation **after** materialization. Restricted old output must be blocked before any retry/recalculation, including failure paths. Do not equate account deletion with source-specific invalidation.

### G4/G5 — Entitlements and guest/team: **separate commercial gate / P0 policy gap**

- Existing Free/Premium/Pro/Dev Pro entitlement remains authoritative. Proposed Premium 1/2 terms do not grant features or change prices.
- Actorless/nullable IDs and collaborative/guest Plans require role, claim, plan ownership and deletion tests before cross-domain exposure. No current leakage is asserted.

### G6 — Plan-free activities: **product invariant, partial foundation**

- `ExecutionActivityService::record()` accepts an authenticated `userId` or `actorToken` without a Plan; `linkToTask()` creates a later Plan/Task link and TaskEvidence projection.
- The current authenticated provider intake nevertheless resolves a Task context and links immediately. Existing Study/Development state evaluation is Plan-based.
- **Gate:** test unlinked fact visibility, owner/guest claim, later authorized association, stable source identity, unlink behavior and no phantom Plan creation. Do not claim the full Free Plan-free experience already exists.

### G7 — Dismissal and Decision Context: **P0 before Premium 2**

- `ActionProjectionStore::persist()` resets `dismissed_at` to null for the same `action_reference` on re-persistence. This may serve existing V53 UX, but cannot represent a durable explicit `defer` or `reject` policy for v0.5.
- **Gate:** distinguish proposal lifecycle from the authenticated user's choice; test same-revision re-evaluation, materially changed evidence, expiry, multi-device competition and notification suppression before adding a cross-activity evaluator. Do not silently change existing V53 Action behavior.

### G8 — External business-key collision: **P0 highest-priority negative test**

- `ProviderActivityIntakeController` authenticates the connection and `ProviderExecutionContextService` resolves an allowed context.
- However, `ProviderActivityIntakeService::accept()` passes the provider's caller-supplied `external_key` to `ExecutionActivityService::record()` unchanged, together with `connection->user_id`. The `record()` lookup and the DB unique index are `provider_key + external_key` only, **not owner-/connection-qualified**. `record()` updates `user_id` and metadata when a key already exists.
- `linkToTask()` checks Plan mismatches **after** the initial `record()` write. Since the two calls are not enclosed in one encompassing transaction, a later rejection cannot be assumed to undo the prior reassignment. This is a **credible static isolation risk**, not a proven live incident.
- Existing `ProviderConnectionActivityIntakeV559Test` covers signed authentication, same-connection replay, context not reusable on another connection and revoked credentials. The inspected tests do **not** submit the *same valid business key from two different authenticated actors/connections*.
- **Gate A20 first:** exercise distinct actors and separate valid contexts with identical `external_key`; assert unchanged initial owner/content, no cross-user linkage, and preserved ability to record legitimate second actor activity. Repeat with same actor/different connections, guest actor and concurrent requests. Review the existing global unique index, migration/backfill strategy, evidence references and old idempotency behavior before changing persisted keys. A rejection-only workaround may prevent reassignment but does **not** fully satisfy A20's two-owner data-preservation contract.

## 4. Execution order and acceptance artifacts

1. **G8, A20:** construct isolated signed-intake negative reproduction, prove current behavior against a disposable DB, select owner/connection-qualified business identity and a backward-compatible rollout. Separate **code/migration PR**, with migration rehearsal and rollback strategy. No production data mutation until verified.
2. **G1, A01/A02:** enumerate snapshot history/current read routes, build actor/team/guest negative tests and add fail-closed actor-bound access wherever shared Resolver would expose snapshots.
3. **G2, A06:** expand GitHub observation replay/order tests, determining whether provider-authoritative fetch already satisfies each scenario; change only the remaining gap.
4. **G3, A03–A05:** map every materialized derivative and purpose-bound cache; define invalidation/revocation semantics and enforce deny-before-regenerate.
5. **G6/G7, A18/A19:** verify Plan-free activity and user decision persistence separately before Free timeline or Premium 2 UX.
6. **G4/G5 and A07–A17:** require explicit product/access/commercial decisions before any paid-tier or cross-domain rollout.

For each slice: new branch from latest main, bounded regression tests, inspect full diff, required CI and migration rehearsals where relevant, then reviewed PR; merge only after the `AGENTS.md` gates pass. Record CI, merge, Render deployment, OAuth/MCP and device verification as **separate** evidence. A proposed test is not a passed test.

## 5. Current verification status

| Evidence | Status |
| --- | --- |
| PR #465 merged and latest main reviewed | **Confirmed** at base SHA above |
| Static source review: G1/G2/G3/G6/G7/G8 + relevant existing tests | **Performed, bounded** |
| A01–A20 adversarial tests against current backend | **Not run** |
| Disposable DB migration/replay | **Not run** |
| Production DB/account data checked | **Not checked** |
| PHP unit/feature suite and CI for this audit branch | **Not yet verified** |
| Runtime/entitlement/API/MCP/Render/iOS changed by this audit | **No** |

**Exit criterion for Phase 0:** turn the above bounded findings into reproducible negative cases, complete read/write/source dependency mapping, and obtain clear per-slice implementation contracts. This document alone does not close Phase 0 or promote v0.5 to active product behavior.

# Adaptive Context Pipeline v0.5 — G3 Exit Audit / Decision Record (2026-10-12)

> Status: **CONDITIONAL / OPEN** — G3 has multiple merged and CI-verified safeguards; **full G3 exit is NOT certified**. This is a bounded source and CI audit, not a whole-repository proof or a release authorization.
>
> Baseline inspected: `1kz-ma1/Canovia-web` `main@36402448f38abdd335ca075654b020750454241b` (after #483), at 2026-10-12 Japan time. #481/#482 are separate MCP track merges preceding #483. Exact release/deploy SHA must be independently established.

## 1. Purpose and decision rule

G3 asks: when original Evidence, permission, ownership, task membership or delegated purpose changes, can **derived private context** be reused/disclosed or drive an important commit? Do not mark `closed` on the strength of one passed unit test, one feature mode, or a merged PR. Separate:

1. **Code present**: implementation merged in `main`.
2. **Isolated regression**: a named negative test and its exact-commit CI passed.
3. **Live policy verified**: on real isolated PostgreSQL with more than one worker and representative source/actor changes.
4. **Public release gate**: specific enabled product behavior uses only the protected routes, or its unsafe route is off.
5. **Full G3 exit**: all supported source/derived read/commit paths have an inventoried ownership+purpose contract, deletion/revoke behavior, and acceptance evidence.

Unknown is **not** pass. No inference that any privacy incident has occurred.

## 2. Current source → derivative → consumer inventory

| Data source / input | Current intermediate result | Reader / commit point | Merged protection / proof | Residual |
|---|---|---|---|---|
| Study Practice and Recall `TaskEvidence` | V53 Study State, Readiness, Decision, Action; Study Living Profile | `StateSnapshotStore::latestForPlan`, `IntelligencePresentationHistoryService`, `StudyActionTaskProjector::project` | #473 Study LivingProfile freshness; #474 V53 source signature; #483 locked Study Task commit; dedicated CI | Cross-worker source write versus projector commit not exercised on real PostgreSQL; non-TaskEvidence Study inputs outside official Study projector are not globally covered |
| GitHub repository / artifact and Task-linked Evidence | `TaskEvidence`, `DevelopmentActivityObservation`, Development State and Living Profile | `GitHubTaskEvidenceCommitGuard`, `DevelopmentDerivedContextFreshnessGuard`, Development V53 reader | #475 commit-time source/connection fence; #476 Developer profile and TaskEvidence write checks; #479 official builder-source replay | Historical observations after disconnect: explicit source retention/eligibility contract **not yet product-approved**. Remote disconnection not yet observed locally; no impossible retroactive deletion promise |
| Career capture, application, event, interview review | Career V53 State, Decision, Action | `IntelligenceDomainSourceFreshnessGuard`, current/history/feedback views | #479 official Career semantic state fingerprint replay; sanitized update notice; CI | Raw-only edits that do not alter the intentionally sanitized state projection are not a semantic change; other Career caches and off-builder readers need independent map |
| Native AI provider response | `NativeAiRun`, selected reasoning run, cached Decision | `NativeAiGateway::generateStructured`, `ReasoningRouter::route`, `ReasonedDecisionOrchestrator::evaluateAndPersist` | #477 router cache/in-flight checks; #478 gateway pre/post entitlement+Plan/Task; **this audit branch** adds final V53 AI Decision trace commit fence | Provider request already sent cannot be retracted. Feature entitlement is not MCP OAuth consent. Other AI caller post-return writes need individual review; narrow post-check grant TOCTOU remains |
| WorkLog, selected TaskEvidence, Plan Action Draft | `PlanActionDraft` and optional sequential steps | `PlanActionDraftController::accept` → Task creation | #480 hashes source revision and rechecks actor/Plan/source at locked approval commit, 409 stale, preserves self-report | Legacy V58.71 WorkLog source has no original full hash; content visible at review can be checked but hidden field changes cannot be retroactively proven |
| Plan-scoped V53 action + current Task | `IntelligenceActionProjection.projected_task_id` | `StudyActionTaskProjector::project` → Task association | #483 same-transaction Plan/Action locks, Study full projection replay, owner/editor reauthorization | Extra audit event is written after Task transaction. Source writers need shared lock discipline for a linearizable multiworker promise |
| OAuth / ChatGPT private MCP shared Development Plan | Controlled delegated context read, not Native AI generation | MCP OAuth token/grant/Plan read boundaries | Existing MCP off-by-default and grant/revoke read fences; separate staging Auth0 track (#481/#482) | **No live ChatGPT OAuth E2E** asserted. Never equate `FeatureKey::AutomaticAiExecution` with per-Plan delegated consent |
| User provided / inferred personal preferences | Living Profile candidate claims, dismissed/confirmed human decisions | `PersonalizationContextService::current`, `PersonalizationLivingProfileService` | #473 and #476 selected Study/Development dependency fences | Other domain inference provenance, arbitrary external source erasure, multi-domain purpose-bound Resolver are not complete |

**Bounded coverage:** This table lists inspected documented paths, **not all call sites** of the application. `StateSnapshotStore::persist` has globally unique `state_reference` with actor mismatch protection; that does not establish cross-user Plan-free snapshot identity (G6). Official Career/Development builder verification does not apply to arbitrary custom V53 scopes or manually assembled history.

## 3. Newly confirmed gap found in this audit: V53 post-Router commit

`ReasonedDecisionOrchestrator::evaluateAndPersist` previously called `ReasoningRouter::route()`, which authorizes before/after HTTP and on cache reuse, **then** persisted `DecisionTrace` without re-reading entitlement or Plan ownership. Grant expiry or a Plan transfer between successful `IntelligenceReasoningRun` creation and Decision materialization could attach an unauthorized AI-selected Decision even though every earlier provider check had passed.

This bounded fix reuses `ReasoningRouter::aiAuthorizationFailure()` at the **final AI Decision write**. It locks the current Plan for the duration of transaction and preserves the Router's owner-only/non-collaborative, feature entitlement policy. A newly revoked grant/owner/scope yields HTTP **409** before `StateSnapshot` or `DecisionTrace` is written. `ReasoningRun` remains an auditable prior provider result without Decision linkage. The entire Decision Trace + reasoning-run link is committed or rolled back together. Deterministic and already-fallback decisions retain their existing behavior.

Negative tests inject a grant revoke and Plan transfer *after Router success is recorded but before it returns*; verify no Decision or State persisted and no trace link. They run under the exact PR CI head, not proof of live multiworker serializability.

## 4. Release gates — priority and disposition

### P0 before **any enabled external/private cross-domain agent or automatic important mutations**

- **G3-R1 (OPEN):** enumerate all native-AI output consumers that store a derivative *after* `NativeAiGateway` returns; add explicit current actor, source-version, purpose and Plan recheck at their durable commit/read point. Where unavailable, **keep the external cross-domain resolver/write capability disabled**. The common gateway cannot authorize later writes on behalf of a returned model result.
- **G3-R2 (OPEN):** define a product-visible distinction between **disconnect**, **revoke sharing**, **hide old historical evidence**, and **delete source**. Retaining an old GitHub observation as user-owned history can be correct, but silently treating it as freshly synced evidence is not. Specify current/unknown/source-offline freshness semantics and retention before expanded connected context sharing.
- **G3-R3 (OPEN):** complete actor/scope/purpose dependency inventory across source stores and all materialized caches, including Plan-free/guest/team scopes and legacy snapshots. For unverifiable pre-signature rows, return UNKNOWN/review-required rather than certifying full source immutability.
- **G3-R4 (OPEN):** real isolated **PostgreSQL** acceptance for Plan transfer, member revocation, source correction/deletion/Task move and two concurrent accept/project attempts, checking zero partial Task and no foreign/old Decision. SQLite and ordinary feature test transactions are **not** equivalent to PostgreSQL two-worker proof.
- **G3-R5 (OPEN):** verify current production/staging deployed SHA, scoped permission/consent defaults, read performance and log signals. A GitHub merge/CI green is not production deployment, device E2E or privacy sign-off.

### Separate rollout / product policy gates (do **not** claim closed by G3)

- **G7 / Premium 2:** `ActionProjectionStore::persist()` intentionally resets `dismissed_at` on replay. This is **not** durable remembered deferral/refusal; needs an independently scoped Decision Context policy before interruption-heavy Premium 2.
- **G5/G6:** actor-owned Plan-free activity and guest/team association cannot be inferred from current per-Plan intelligence; require explicit link/unlink and cross-actor tests.
- **G4:** price and Entitlement definitions remain a separate commercial decision. No new paid tier is activated by this audit.
- **MCP Phase 1:** Auth0/CIMD staging constraints and public integration are separately gated; nothing here enables delegated private access or MCP write.

### Scope currently eligible for continued gated testing

Current **user-driven** Study execution and V58 owner Action Draft acceptance have narrow current-Plan/server-side commit checks and dedicated feature CI. They may proceed to representative isolated DB/device validation **without** representing the overall G3 as closed. Do not silently enable cross-domain AI auto-actions or grant data export.

## 5. Acceptance checklist / release evidence

| Proof | Current status | Required evidence to close |
|---|---|---|
| Named G3 code boundaries and negative examples | **Implemented in slices; CI** | PR #473–#480/#483 merge SHAs and exact test runs, plus this audit PR |
| V53 AI post-router Decision commit | **This PR; pending its CI until complete** | Two new negative tests, exact SHA green |
| Privileged historical source/cache deletion contract | **Open** | Approved semantics, source-family replay/deny tests |
| Legacy partial source identity coverage | **Partial** | explicit UNKNOWN/review gate or safe migration after data audit |
| Native AI caller outputs and purpose-bound post-return commits | **Partial** | call graph + per-consumer negative tests |
| GitHub disconnect retained-observation policy | **Open** | disconnect/revoke/delete matrix, offline/unknown UI contract |
| Actual multiworker PostgreSQL concurrency | **Not tested** | independent DB and parallel connection test evidence |
| Staging/live deployed SHA and iPhone/PWA/desktop | **Not certified in this audit** | environment evidence and device flows |
| Complete G3 exit decision | **NOT APPROVED** | all above required P0 acceptance, owner/security review |

## 6. Execution order

1. Merge this **bounded** V53 post-Router fix only after all relevant CI passes.
2. Establish the post-return Native AI output consumer list and earliest unsafe durable commit; one PR per proven gap. No new universal Fact table.
3. Write explicit connector disconnect/revocation retention semantics and tests (separate behavior-policy review).
4. Add isolated PostgreSQL two-worker acceptance job, fail closed on partial commit and repeat it before cross-domain enablement.
5. Re-audit legacy and guest/team materialized source references, log/measure stale-withheld rate and P95 read cost.
6. Only then change **G3** from OPEN to PASS; P1 architecture / Premium 2 remain separate.

No schema migrations, production operations, Auth0 staging activation, MCP sharing permissions, billing changes, or private user data are needed for this audit itself.

## 7. Follow-on slice — Companion after-provider response commit fence (2026-10-12)

This independent G3-R1 follow-on PR guards `CompanionConversationService::send` after `NativeAiGateway::generateStructured` has already returned but **before** writing the AI assistant message, suggested mutation candidates, or user-quoted Future Memories. The service locks the current Companion thread, Plan and Task, verifies the original user and thread target, and reuses `NativeAiPurposeAuthorizationGuard::failure` with `FeatureKey::CanoviaCompanion` at the durable write boundary. Changed thread/Plan/Task context or expired entitlement rejects the AI-derived transaction. The user's original message and the already completed `NativeAiRun` remain as separate auditable facts.

A dedicated workflow exercises a simulated revocation, Plan transfer, Task move and thread scope change **after a successful, authorized provider response**, along with a positive unchanged-context control and existing Companion regressions. These are isolated database feature regressions, **not** proof of simultaneous multi-worker PostgreSQL authorization or production deployment. The per-user product-grant write and grant-check are not serialized together; later policy retraction/read-surface rechecks are out of this slice. Historical previously saved assistant text is not retroactively erased. The feature entitlement remains independent of MCP OAuth / per-Plan external sharing consent.

**Exit remains CONDITIONAL / OPEN**. This slice does not establish all Native AI caller return/commit gates, all previously derived cache/record invalidations, connector history deletion semantics, or universal G3 acceptance. Refer to actual PR/CI and merge evidence rather than treating this document as proof of execution.


## 8. Follow-on slice — Recall AI candidate extraction commit fence (2026-10-12)

A subsequent bounded G3-R1 branch addresses both single-source and batch Recall AI output consumers. The baseline implementation permitted a successful Native AI response to create pending `StudyRecallCandidate` rows and mark `StudyRecallSource` ready without revalidating current actor entitlement, Plan/Task membership, or the private source material after provider return. Single-source and batch writes were not wrapped in a common all-or-nothing transaction.

The new `StudyRecallExtractionCommitFence` snapshots source metadata and SHA-256 of source text or saved bytes **before sending provider input**. At the candidate/source-result commit transaction it locks the Plan, Task and each Source in deterministic order and checks current authorized actor, edit role for collaborators, `AutomaticAiExecution` entitlement, original Source ownership and unchanged material/signature. A change refuses the entire AI-derived candidate/source-ready transaction, returning a retryable error with the already-completed Native AI run reference. Where the source still belongs to the original user/Plan/Task and is pending/failed, an extraction error marks it failed for the existing explicit V58.7 retry flow; deleted or retargeted source rows are never recreated by this code. No automatic deck promotion or Task mutation is added.

Dedicated negative tests simulate grant expiry, Plan transfer, Task move, source text edit/deletion and batch file replacement **after a successful provider response**, plus unchanged positive regressions. Reuse the existing Recall single/batch and Native AI feature tests. **This section describes the proposed PR diff, not proof of passed CI/merge/deployment until independently confirmed.**

**Residual G3 OPEN:** serialized Plan/Task/Source row locks are insufficient by themselves to prove cross-worker grant invalidation unless source and grant writers follow compatible locking. Previously materialized candidates, human-promoted Recall items, broader Native AI callers, historic invalidation and live multiworker PostgreSQL are out of scope. No schema migration, pricing, MCP activation, production deploy or credentials are included.

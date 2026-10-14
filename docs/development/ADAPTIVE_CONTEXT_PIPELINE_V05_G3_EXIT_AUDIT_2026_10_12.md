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


## 9. Follow-on slice — Study Scope Capture post-provider AI draft fence (2026-10-12)

**Scope/contract:** V53.4 `StudyScopeCaptureAnalysisService::analyze` retains its Free `FeatureKey::StudyScopeCapture`, private `InboxItem` upload, human review / manual confirmation, retry after provider failure and no automatic Task or State creation. Previously, after `NativeAiGateway` returned, `StudyScopeCapture.review` could be committed from stale input without a final ownership, entitlement, source or human-confirmation recheck.

The new `StudyScopeCaptureCommitFence` verifies a current Study Plan and owner/editor role, creator-linked private Inbox source and the existing purpose entitlement; it signs exact source note/metadata and private file bytes (SHA-256), plus Plan category/domain/deadline used in the prompt. The beginning-of-extraction `captured` reset is transactional. After the provider result but before any AI draft write, the service locks current Plan, Capture and Inbox rows, revalidates current authority/source fingerprint and ensures the Capture is still `captured`. The final `review` draft and model run linkage are atomic. Any human `confirmed` status prevents a delayed AI result overwriting the human decision. On ordinary provider errors, `failed` is recorded only if the same user-editable original target still validates; source files and manual entry/retry remain available.

Regression covers post-provider Plan transfer, domain reassignment, file/note alteration, source deletion, human confirmation and entitlement change, plus authorized Free extraction and provider-failure recovery. **The referenced PR must separately establish exact-SHA CI and integration; this note alone is not certification.** Cross-worker grant writer locking, historical confirmed scope re-invalidation after source correction, external source file concurrent write serialization and production/device checks remain OPEN under G3. No schema migration, billing change, MCP/OAuth activation, public sharing or production operation.


## 10. Follow-on slice — Goal Discovery reply and Canovia Memory commit fence (2026-10-12)

**Observed residual G3-R1 gap:** `GoalDiscoveryConversationService::respond` previously used `NativeAiGateway` for `conversational_onboarding_reply` (Free capability) and independently wrote AI-proposed literal-quote `FutureMemo` records followed by an assistant `GoalDiscoveryMessage`. A Goal Context ownership change, newly confirmed answer, fact mutation, Plan attach, feature revocation, or another message after the provider response could leave derived Memory/reply attached to an outdated or foreign context. Atomicity across these two derivatives was absent.

This bounded slice adds `GoalDiscoveryAiCommitFence` and an application transaction encompassing both AI-derived writes. A snapshot signature covers GoalContext identity, user/guest binding, attached Plan ID, goal/readiness/interaction state, all persisted Facts, and the recent Conversation messages used by the prompt. A final locked GoalContext re-read verifies actor identity, Plan ownership when linked, unchanged signature, and current `ConversationalOnboarding` entitlement and flag. Any stale/unauthorized reply is discarded with its candidate Memory; the original user message stays. Valid replies preserve the existing literal source-quote check and duplicate suppression. Provider failures and no-provider cases still have a deterministic response, but fallback writes also reject stale/foreign Goal Context. Guest deterministic access remains available; guest Native AI still cannot bypass `NativeAiPurposeAuthorizationGuard`'s verified-actor requirement. No Goal Fact/Plan/Task is automatically confirmed or mutated by the model.

Dedicated negative tests simulate ownership transfer, changed Facts, newly appended message, Plan attachment, revoked capability/flag **after successful provider response**. A rollback regression tests that failure to write the assistant does not leave AI-generated Memory behind. Free positive response, provider failure, guest fallback and existing V41.16 regression are included in a dedicated CI workflow. The feature is **not** G3 global completion: independent Fact/Message writers do not yet all coordinate on the GoalContext lock; multiworker PostgreSQL and past AI-derived Memory invalidation remain open. Historical user-origin content is not retroactively deleted. No schema migration, price/entitlement change, MCP access activation or production operation.


## 11. Follow-on slice — Inbox and Space Station Native AI suggestion commit fence (2026-10-12)

The residual `InboxIntelligenceService::suggest` consumer at both `InboxController::store` (chat intake, including Space Station) and `InboxController::suggest` (explicit interpret) previously saved the AI's `routing_suggestion` after `NativeAiGateway` returned, without rechecking current Inbox source, ownership, linked Plan or feature entitlement. An edit/deletion of private source content/file or human routing confirmation while the provider was in flight could have been overwritten by a stale recommendation.

This bounded G3-R1 change routes both entry points through `InboxIntelligenceService::suggestAndPersist` and `InboxAiSuggestionCommitFence`. Immediately before the model request, sign Inbox source text/URL/file SHA-256, metadata, status, ownership and Plan association. After the response, re-read the Inbox row with a lock, verify actor ownership or authenticated claimant of the exact private guest token, current `AutomaticAiExecution` feature, and linked Plan owner/editor right, and compare the complete original source signature; only then atomically persist the recommendation and `review` status. Career availability is evaluated at the final commit, not before the slow provider returns.

Denying a stale, archived, processed, deleted or retargeted source leaves the original Inbox Item and any human-confirmed routing untouched (a deleted item is never resurrected). No automatic Task, Plan, Evidence, or routed destination is created by AI. The pre-existing completed Native AI Run remains for audit. Unavailable AI still leaves a usable raw Inbox capture; manual routing remains the human authority.

The new negative regression suite injects source note and attachment replacement, deleted/moved Item, Plan transfer, prior human confirmation and entitlement expiry after provider success; the positive and compatibility suites exercise chat/Space Station and explicit suggestion paths. **Do not interpret this text as CI or deployment evidence until exact PR-head checks and integration are verified.** Remaining G3 gates include independent source/file writer serialization, historical suggestion revocation and all disconnected external evidence, two-worker PostgreSQL concurrency, and deployed/device acceptance. No DB schema, billing, MCP delegation, public sharing or production operation is modified.


**Legacy UI regression distinction at PR review:** running all historical `SpaceStationV442Test` and `SpaceStationIntakeHubV483Test` cases in this branch also surfaced two outdated Map presentation assertions: `test_capture_from_space_station_creates_only_inbox_item_and_returns_to_station_focus` still expects `LATEST INPUT`, and `test_docked_space_station_uses_current_plan_only_as_a_confirmation_candidate` expects `data-space-station-context-candidate`. Neither assertion tests AI candidate authorization or post-provider persistence, and the PR does not change Map views. The dedicated G3 CI runs the existing explicit routing/candidate and chat auto-interpret tests by name, plus all existing Inbox Intelligence and Native AI purpose regressions; outdated Map UI assertions remain separately unresolved rather than being mislabeled green. Their UI expectations require Experience/Map contract reconciliation outside this security slice.


## 12. Follow-on slice — Native Execution Packet session reuse/commit fence (2026-10-13)

**Observed:** `ExecutionPacketService::generateNative` returned AI-selected instructions after the gateway's post-provider authorization check. `ExecutionOrchestrationController::prepare` and `ExecutionDistributionService::prepare` then kept that response in a session-only Execution Packet. A Plan transfer, Task move, changed Dependency/Evidence, Task completion or product grant expiry **after** `NativeAiGateway` success but before the later session write could surface stale private instructions. Existing `context_fingerprint` on GET displayed stale warnings, but grant revocation did not explicitly hide the prior Native AI Packet; multi-target distribution could outlive its first target's inputs while generating later targets.

The bounded fence `ExecutionPacketCommitFence` re-reads and row-locks Plan/Task, verifies current owner/editor (not viewer) role, noncompleted Task, `AutomaticAiExecution` purpose entitlement and a fresh `ExecutionOrchestrationContextService` snapshot fingerprint. The native generator checks immediately after model return; the single-target controller checks again before session write. On stale/revoked input it stores neither the AI packet nor an old-context fallback handoff prompt. `show` invalidates stored native session output upon context or entitlement changes; manual external Packet imports clear stale native-run provenance. Multi-target Distribution rechecks all target fingerprints and permissions before returning the bundle and on subsequent read, redacting native Packet content for invalidated targets and persisting its redaction to the session. `ExecutionGitHubHandoffService::prepare` refuses a revoked Native AI source Packet even if the session was never revisited.

**Not a new durable model:** Execution Packet remains session-only, no automatic Task/Plan/GitHub writes; explicit human-reviewed GitHub handoff is still separate. Existing Free external-AI prompt/import and normal provider-error fallbacks remain. Regression tests inject post-gateway owner transfer, Task move, dependency completion, feature grant expiry and read-time revocation; two-target distribution simulation exercises invalidation of a previous result while a later AI call is running. Exact-head CI and integration must be separately verified.

**Residual OPEN:** locking during validation cannot serialize the later session store with independent Plan/Task/Entitlement writers. Real multi-worker PostgreSQL G3-R4, other native consumers, history/revocation G3-R2/R3 and production/device verification G3-R5 remain gates. No schema, entitlement policy, billing, MCP/OAuth or production infrastructure change.

**CI-discovered cache boundary:** The initial post-provider dependency-completion and two-target tests failed: `ExecutionOrchestrationContextService::snapshot` reused `CoreContextService`'s already-loaded Plan/Task graph within the same HTTP Request. Re-running that method without a cache bypass can return the **same old fingerprint** even after a committed dependency update. A scoped `freshSnapshot` uses a distinct Request identity to force fresh loading; the commit fence and distribution source/read checks now use it. This is a targeted invalidation at security-sensitive points, not global removal of the navigation performance cache. The exact-head dedicated CI must prove dependency-change rejection before merge.


## 13. Follow-on slice — Native Study assessment answer/session commit fence (2026-10-13)

**Observed G3-R1 risk:** `StudyPracticeOrchestrator::prepareAssessment` previously awaited `NativeAiStudyPracticeAssessmentProvider::prepare`, then independently updated `StudyPracticeSession.assessment_payload`; `StudyPracticeController::submitAnswers` normalized the result and persisted `StudyPracticeAttempt`, `TaskEvidence` and `ASSESSED` state with no final signature/actor/purpose verification. After provider return, an alternate autosave could edit `draft_answers`, a human could close the session, a Task could be reassigned, or a Plan/AI grant could be revoked, yet an old AI grading result could survive.

The bounded `StudyPracticeAssessmentCommitFence` captures the exact persisted question and answer draft, session state/identity, Plan domain/ownership/context and target Task properties before the provider call. The orchestrator only writes Native AI `assessment_payload` inside a final locked recheck of current Plan, Task and Session plus editor rights and `AutomaticAiExecution` entitlement. A second, identical fingerprint check protects the controller's durable Attempt+TaskEvidence+ASSESSMENT status transaction, with an error if any input has changed between AI completion and grading persistence. A stale/revoked Native AI result is withheld, rather than generating an old-source manual evaluation prompt. The learner's draft answers remain available for regrading against the new state.

Normal Free external AI handoff and deterministic Question Bank grading remain unchanged; direct Native assessment still requires an authenticated entitled actor and keeps the existing human-confirmed Task progress apply boundary. Dedicated negative tests simulate post-provider draft edit, session completion, question change, Task move/context edit, Plan transfer, and grant expiry, plus a positive assessment/attempt/Evidence case. Existing V41.8 and hybrid bank/native tests are run by dedicated CI. The NativeAI run may be marked provider-success even when its later consumer vetoes persistence: do not confuse provider call success with accepted grading.

**Residual G3 OPEN:** independent draft/source/grant writers do not yet guarantee two-worker serialization with these row locks; historic attempts/evidence eligibility following later source corrections, provider generation routes, model access to dynamic QuestionBank grading rules and real isolated PostgreSQL multiworker tests remain separate gates. No schema, prices, release flags, MCP scope, Render infrastructure or production data changed.


## 14. Follow-on slice — Native/Hybrid Study question generation commit fence (2026-10-13)

**Precedence:** merged PR #499 already owns the Native *assessment* result/source check and is preserved unchanged. The earlier overlapping draft PR #500 is superseded rather than merged over #499. This slice is limited to new *question generation* sessions and their candidate/demand derivatives.

**Observed:** `StudyPracticeOrchestrator::prepare` previously created a `StudyPracticeSession` immediately after `NativeAiStudyPracticeQuestionProvider` / the Native side of `HybridStudyPracticeQuestionProvider` returned. Subsequent `PracticeQuestionDemandRecorder` / `PracticeQuestionCandidateRecorder` writes could materialize old questions or leave partially saved derivatives if user Plan or Task was edited, owner/editor access removed, Premium grant expired or Study history changed while the provider request ran. Gateway's immediate post-provider entitlement check did not authorize this later materialization.

`StudyPracticeGenerationCommitFence` snapshots the original current Plan/Task row attributes and actor-bound recent practice Attempt records, revalidates Plan Study domain with `PlanCategoryProfileService` (including `workspace_domain_override='study'`), current Plan owner/editor, Task noncompleted status, actor-specific `AutomaticAiExecution` entitlement, and unchanged snapshot under row locks **inside the same DB transaction** that creates the generated Session, Demand and Candidate rows. A stale/revoked result does not create partial AI derivatives. The original NativeAiRun may legitimately indicate that the provider completed; provider success is not permission to commit stale content.

Dedicated positive/negative tests cover legitimate native generation, alternative Study category/domain, Plan transfer, Task revision or human completion, and grant expiry **after provider success**, with existing V41.8, Hybrid and #499 assessment regression. No controller UX, task progress, billing/entitlements, external AI manual flow, new schema, MCP scopes or production credentials are changed.

**Residual G3 OPEN:** later controller follow-up mutations, independent writers lacking matching locks, historical generated sessions after subsequent context edits, attempts outside the signed task, multi-worker PostgreSQL and deployed/device acceptance still require separate verification.

**Study completion compatibility:** An already-completed Task may still be used for review. The new generation fence denies cancelled targets but does **not** deny an unchanged `done` / 100%-progress Task merely for its status; changing status while the provider is running still changes the signed Task attributes and refuses the stale output. A dedicated regression protects this distinction.


## 15. Follow-on slice — Native/Hybrid Study generation replay and finalization fence (2026-10-13)

**Main baseline:** #501 already merged a G3-R1 post-provider transaction covering **first-time** Study Practice Session, Demand and candidate materialization. This new slice is deliberately confined to its still-unprotected reentry and controller continuation. Do not merge or duplicate the superseded #500 draft.

**Observed:** `StudyPracticeOrchestrator::prepare` had an early existing `prepare_request_id` replay that reconstructed Demand and Candidate records regardless of the initial Native generation's authority/source freshness. The controller's `prepareNative` subsequently performed a separate non-fenced READY/normalized-questions update and `NativeAiRun` attachment. Replaying the same request after the learner started answering could rewind a durable `IN_PROGRESS`/assessed Session and browser draft to READY/empty answers. Changes in Plan/Task or entitlement between the initial atomic creation and this later update could also attach old Native output.

**Bounded change:** Newly accepted Native/Hybrid Session `selection_context` carries its pre-provider `native_generation_source_fingerprint` from #501's exact Plan/Task/attempt source. Duplicate `prepare_request_id` for Native/Hybrid becomes read-only: compare the saved original fingerprint against current actor-specific Study/Plan/Task/history, purpose entitlement and editor access under Plan→Task→Session locks. Do not reissue Demand/Candidate or re-sign historical rows that lack original provenance (legacy rows remain available through normal Study resume/history, subject to their existing permission controls). A controller-finalization transaction checks this **same original fingerprint**, unchanged Session identity/status/question snapshot and absence of a concurrent draft before normalizing and binding a NativeAiRun. Invalid generated questions can mark a session abandoned only under the same conditions. Already-started exercises are never reset by a duplicate preparation.

**Regression** tests include exact-ID idempotent replay, changed Task replay, unverifiable historical row, concurrent learner draft, and Plan/Task mutation after the Orchestrator commit before controller finalization, plus previous generation/assessment/Hybrid suites. Normal Free/manual external AI flows and the separate Study assessment fence (#499) are preserved. No automatic Task progress, new schema, pricing/entitlement change, MCP/IdP access or production operation.

**G3 remains OPEN:** later browser session writes are not serializable with independent source writers; historical already-saved derivatives cannot be automatically invalidated without separate policy. PostgreSQL multiworker, external question-bank source revision and deployed/device end-to-end must be tested separately. Exact PR-head CI and merge are not inferred from this document.


## 16. G3-R2 bounded slice — GitHub historical observation versus current Task reuse (2026-10-13)

**Observed:** Developer Home previously called `DevelopmentTaskMatchService::refreshPlan` against all unresolved persisted `DevelopmentActivityObservation` records, even when the source Repository GitHub App connection had been disconnected or its `DeveloperGithubEvidence` capability had expired. The workspace still offered a Task link action from those observations and `DevelopmentTaskAssociationService::link` could create a new Task–Artifact relation based on the historical local record. This did **not** itself claim the remote GitHub source was freshly fetched, but did silently promote historical external evidence into a new current planning association.

**Bounded correction:** `DevelopmentObservationReuseGuard` verifies the current Plan's Development domain, owner entitlement, observation→same-Plan repository linkage and GitHub identity, repository status `connected`, positive current installation ID, and expected external-key repository prefix. It is a *local eligibility* test, not a remote GitHub HTTP check or historical revision proof. The matching path drops only automated `suggested` status/confidence when ineligible (does not modify ignored/linked human decisions). Developer Home preserves and explicitly labels original observations as **past / currently unverified**, excludes them from the actionable unresolved Task queue, and hides new Task-link forms. Direct forged or stale-browser link POSTs re-read/lock Plan, Task, repository and observation and refuse the new association before attaching any Artifact/Task or requesting Evidence. Existing linked Task relations and Evidence remain untouched historical records; source disconnection is not an erasure request.

**Regression:** dedicated negative tests for disconnect with previously suggested Task, direct stale-link POST, expired paid GitHub evidence entitlement, mismatched repository identity, preserved human linked history, plus existing Developer Home, webhook observation, and evidence/personalization regressions.

**Non-goals/open:** Do **not** certify pre-G3 observations as fresh solely because the same repository is *reconnected*; the observation schema currently lacks an immutable recorded source-installation epoch, and the local status check cannot establish original GitHub source continuity, deletion, or per-event freshness. This slice therefore does not approve expanded cross-domain AI/MCP replay or a global retention/deletion policy. A separate G3-R2 decision must define explicit owner-facing history hide/delete and reconnect provenance; R3/R4/R5 multi-scope, real PostgreSQL workers and live/device evidence remain OPEN. No data purge, schema change, OAuth/MCP scope, product pricing, paid entitlement or production operation.


## 17. G3-R2 continuation — GitHub Source Epoch and reconnect provenance (2026-10-13)

**Gap after #505:** local `connected` and `installation_id` checks do not show whether an observation came from the *same continuous authorization*. A GitHub App installation may be reauthorized for a repository using its **unchanged numerical installation ID** after a disconnect; the old activity then becomes erroneously actionable. Legacy activity has no verified installation lineage.

**Scoped contract:**

- Add an opaque random `source_epoch` (UUID) to `github_app_connection` after an authoritative connection check. Checks for the same continuously connected installation preserve it; new connections, reconnects, app setup starts, failed/lost confirmations and replacement installations mint a new epoch only after an authorized connected verification. The metadata update is serialized on the Repository Artifact row to avoid out-of-order connection updates. **Upgrade compatibility:** an already-connected legacy Repository without an epoch can mint its first epoch on the first successfully fetched, current-installation, current-owner GitHub observation under the locked commit gate. Only the fetched observation is signed; other historical observations remain NULL, and a multi-fact bootstrap refreshes its selected Repository after first mint.
- Add a **nullable** `development_activity_observations.source_epoch` in an additive migration. **Do not bulk-backfill** legacy rows. NULL means **unverified history**, regardless of their GitHub timestamp.
- An observation loaded before an external GitHub REST fetch retains its original epoch. When the response arrives, the writer checks that exact epoch and installation against the live, locked root before saving. Fresh observations carry that epoch. Existing immutable commit rows and identical revision rows may adopt the new epoch **only after an authoritative re-fetch**, without erasing a human decision. Older revision/same-version conflicting fields remain blocked by G2 ordering protections.
- Developer Home's G3-R2 action/Task matching fence requires exact observation→repository epoch equality, along with connection, owner, entitlement and identity. Developer personalization uses only currently signed observations; the source lineage fingerprint also includes epoch, so reconnect invalidates old derived recommendations.
- Historical Activity, previously linked Task Evidence and human decisions remain accessible subject to existing permissions, but **not automatically accepted as current**. No source data deletion, automatic Plan/Task mutation, extra GitHub API requests merely to view history, MCP scope expansion, pricing change or data backfill.

**Known limitations (still OPEN):** The source epoch proves current local verification provenance, not remote GitHub revocation or continuous operation between observations. A webhook received before reconnect may trigger a *new* authoritative REST fetch after reconnect; this verifies current provider state, not event delivery epoch. Existing historical linked TaskEvidence beyond this Observation path, full delete/hide semantics, live PostgreSQL two-worker and production/device checks remain future gates. Migration safety, exact-head CI and merge are assessed separately.


## 18. G3-R2 follow-on — GitHub TaskEvidence current-use fence (2026-10-13)

**Observed:** Even after #505 and #506 fenced raw GitHub `DevelopmentActivityObservation`, immutable `TaskEvidence` produced by the GitHub Return/Evidence adapters was still queried as if it were *current* on the Development Execution Context screen, V53 Developer Intelligence Collector, and user-selected `PlanActionDraftTypedEvidenceService`. An old persisted CI failure, merged PR, issue or deploy could reappear as a current suggestion after repository disconnection, owner entitlement expiry, or Source Epoch rotation, because its Eloquent payload fingerprint stayed unchanged.

**Scoped contract:**
- `GitHubTaskEvidenceCommitGuard` verifies the same pre-REST-fetched `source_epoch` against the locked current Repository Artifact as well as Plan owner, linked Task and current paid capability. Inside its existing write transaction it stamps **only actually recorded/refetched** TaskEvidence with `github_source_epoch` and `github_repository_artifact_id` metadata. PR callback can return multiple IDs; Push Branch and Commit evidence both receive the stamp. Existing unsigned TaskEvidence remains untouched historical evidence, not silently backfilled.
- `GitHubTaskEvidenceReuseGuard` reads current Plan owner/Development profile, GitHub App state, repository URL/identity, matching epoch and `DeveloperGithubEvidence` entitlement. It does not call GitHub on display. An unsigned, revoked, moved or mismatched GitHub source fails closed.
- Only eligible GitHub evidence contributes to the current V53 Development Evidence Collector or Developer Execution Context. Human-confirmed native quality-gate Evidence, non-GitHub Study/Career evidence and historical rows remain intact.
- New typed Action Draft selection excludes ineligible GitHub evidence. A stale direct POST cannot snapshot it. Critically, final Action Draft approval repeats the source eligibility test **within the existing locked acceptance transaction**, returning 409 even if the stored source hash is unchanged; no Task is created.
- Run positive and negative CI regression against linked GitHub Return/Evidence, Workspaces, V53 read models and V58.73 typed drafts. No schema change, deletion, billing, MCP scope, auto progress or production release.

**Still OPEN:** GitHub REST state correction after same-epoch source changes, previously persisted generic Execution/Companion/Reflection memory text, cross-domain inference source families, history hide/delete UI and two-worker PostgreSQL transaction ordering are separate G3 gates. This stage certifies only its named consumers, not global provenance or remote revocation.


## 19. G3-R2 follow-on — Companion + Execution derived current-context source fence (2026-10-13)

**Gap after #507:** The V53 Developer Evidence Collector, Developer Execution Context and V58 typed Draft flows already reject historical GitHub TaskEvidence. Two other *current AI-facing* projections still selected raw `TaskEvidence` without that gate: `CompanionContextService::snapshot()` and `ExecutionOrchestrationContextService::snapshot()`. A disconnected / reconnected GitHub source could consequently be surfaced as `recent_evidence`, or `guided_execution.latest_evidence`, and copied into current Companion prompts / Execution Packet contexts. The old Packet context fingerprint did not include the GitHub connection revision when no Evidence was selected, leaving a source-epoch-only reconnect unobservable.

**Bounded implementation:**

- Reuse existing `GitHubTaskEvidenceReuseGuard::eligible(plan,evidence)` in both AI-facing projections. Fetch a small bounded surplus of recent historical rows, then filter and keep only the original 6 (Companion) or 12 (Execution) current records. This avoids newer revoked facts starving valid historical **native** reflections and current signed GitHub facts.
- `CompanionContextService` derives `guided_execution.latest_evidence` from the already filtered list. User-authored/explicit FutureMemo records are never deleted, modified or auto-disqualified by GitHub disconnection: an explicitly saved user preference is **not** the same as an automatically inferred GitHub fact. Historic Assistant prose remains conversation history, not a newly verified provider observation.
- `ExecutionOrchestrationContextService` adds a deterministic **non-secret connection-lineage hash** to its canonical read-only snapshot, based only on ordered Repository Artifact IDs, URLs, connection status, installation ID and `source_epoch`. The existing `context_fingerprint` and `ExecutionPacketCommitFence` therefore become sensitive to revocation/reconnect even if the 12 Evidence entries remain empty. FreshSnapshot re-fetches via a cloned Request to bypass stale CoreContextService request cache; no cached/derived Packet remains authorized under a changed lineage.
- No old TaskEvidence or FutureMemo row is deleted, no Plan/Task mutation, no DB migration, no additional GitHub reads merely for AI prompt, no billing/entitlement or OAuth/MCP changes.
- Verification includes current signed, unsigned legacy, revoked, same-installation/new-epoch, re-fetched, native Evidence, explicit user memory, Packet fingerprint, plus existing Companion and Orchestration regressions.

**Residual G3 OPEN:** This does not retroactively remove quoted GitHub facts from historical assistant messages, manually captured FutureMemo, external handoff text already copied elsewhere, broad Reflection Timeline, other domain derivative families, independent PostgreSQL multi-worker serializability, or production/device evidence. Future integration must track source-tagged derivatives rather than pattern-match user-authored prose.


## 20. G3-R2 scoped continuation — Companion AI-derived candidate and message provenance (2026-10-13)

**Previously open gap after #510:** Companion's new AI prompts and Execution Packets filter invalid GitHub Evidence, but **a persisted Companion Mutation Candidate** can outlive the GitHub source which informed its original suggestion. A subsequently revoked, edited or reconnected Evidence record must not authorize a human click on a stale AI-generated Task/Plan mutation. Previously saved Assistant replies were also passed back into the next AI prompt as-is from recent conversation history.

**Bounded behavior:**
- Sign newly generated Companion Assistant responses and Mutation Candidates with a compact `source_provenance` list of exact eligible GitHub TaskEvidence IDs and content-revision hashes selected in the context snapshot. The source list is computed server-side, never accepted from the AI payload. The revision covers record ID, Plan/Task, type, provider key, full recorded metadata, event time and row update time. The historical record is unchanged.
- Before saving an AI reply, any Future Memo from explicit **latest user text**, or a new Candidate, check that every captured GitHub source is still available with the same content revision and current connection/entitlement. Provider completion by itself cannot authorize a derivative after the source changed during inference. If the check fails, return a typed source-changed error and save no Assistant reply/Candidate/Memory.
- Candidate preview flags stale signed derivatives and hides Apply, but continues to display the stored historical proposal and its Dismiss action; the **locked Apply transaction** repeats the guard before any Plan/Task mutation. Stale candidates are not resurfaced as pending continuity signals and cannot suppress a new proposal of the same semantic payload after revalidation.
- When building a **new** Companion model prompt, the saved historical Assistant reply is replaced with a neutral note if its signed GitHub source is stale. It remains fully visible as original thread history and is not edited, erased or mislabeled as user content. User-authored Future Memo, Native Evidence and human-confirmed changes are unaffected.
- Schema-neutral: use existing assistant/candidate metadata JSON and direct current Evidence lookups. No new migrations, blanket deletion, silent source repair, pricing change, OAuth/MCP change or production operation.

**Explicit limits:** Historical **pre-provenance** AI messages/Candidates cannot be reliably classified by looking for GitHub words in free text. This slice does not guess or auto-delete them: missing metadata means legacy/unknown, not proof of freshness; separate owner-facing review/quarantine design is required for existing unsigned derivatives. Also not covered: saved external handoff prompt reuse, generic Reflection/Timeline projections and multi-worker PostgreSQL transaction races. G3 stays OPEN pending those gates and live/device checks.


## 21. G3-R2 — Legacy unsigned Companion decisions and confirmed Execution Handoff replay (2026-10-13)

**Gap:** #515 signs new Companion AI derivatives, but older Assistant replies and pending AI Mutation Candidates have no source metadata. In a Plan that contains a GitHub Repository Artifact, it is not possible to establish that these older AI-generated suggestions did **not** rely on revoked GitHub state. Also, after a signed `prepare_execution_request` Candidate was approved and copied to session, `ExecutionOrchestrationController::prepare` could reuse the stored instruction after the original source became invalid. Batch Distribution or Execution GitHub Handoff could similarly carry that copied instruction even if a new Context fingerprint was calculated.

**Bounded correction:**

- For **unsigned legacy Companion AI derivatives scoped to a Plan with a GitHub Repository Artifact**, treat absent provenance as **unverified**, not as proof of GitHub origin. Do not parse historical prose to guess citations or delete any existing messages, Candidate or Tasks. Hide Apply for pending unsigned candidates (while retaining Dismiss and content), remove them from Continuity as current pending guidance, and replace unsigned historical Assistant text with a neutral marker only in **new AI prompts**. A new Companion proposal with signed current Evidence (including explicitly empty GitHub refs) is the safe way to proceed. Non-GitHub Plans preserve legacy human workflow compatibility and user-authored records.
- `ExecutionRequestHandoffService::sourceIsCurrent` validates the copied `companion_candidate` request against the durable applied Candidate, confirmed Plan, Task, owner, thread and **exact confirmed instruction**, and invokes the derived evidence signature verification. This is read-only; the session is not a durable grant. Other explicit human Inbox and Development Brief sources are unaffected by this Companion AI provenance requirement.
- The Execution screen drops only stale session request/Packet/external prompt, displays an explicit source-staleness notice, and leaves confirmed history in the original Companion Candidate. Direct prepare POST revalidates before creating external/Native output; multi-Task distribution and GitHub write preview/confirm refuse stale Companion origin (without touching unrelated requests). A previously prepared GitHub PR candidate is not an independent permission to write after source revocation.
- Regression asserts unsigned legacy candidate/history confinement, valid non-GitHub legacy flow, signed positive Companion Handoff, stale source after disconnect/reconnect, exact instruction matching, direct POST and batch/GitHub candidate replay refusal, plus existing Companion, Execution, Developer regression.

**Boundaries:** No guessed migration/backfill, no source deletion, no mass Archive, no user-authored Memory mutation, no GitHub REST write, no deploy, no schema changes. Historical unscoped AI text remains user-visible; user may still manually re-author or explicitly confirm a new request. Origin checks cannot revoke prompts already exported to *third-party* AI systems. G3 PostgreSQL multi-worker and production/device gates remain OPEN.

## 22. G3-R2 bounded continuation — Dashboard/Situation current Evidence freshness (2026-10-13)

**Observed gap after #517:** `DashboardPresentationService::build` eagerly loaded up to eight recent `Task.evidences` for each current Task and passed the unfiltered list into the current `recent_evidence` card and `PlanSituationResolver`. Consequently historical pre-epoch or revoked/reconnected GitHub `TaskEvidence` could be labeled `Canoviaが確認できた事実` in a current-facing projection, despite existing G3 fences in Development, Companion and Execution. This is a read/display eligibility gap, not evidence of a data breach or an unauthorized Task mutation.

**Bounded change:** Reuse `GitHubTaskEvidenceReuseGuard::eligible(plan, evidence)` in the Dashboard producer before any display/count/situation logic. Native and other non-GitHub evidence are left available, while GitHub legacy unsigned, disconnected, wrong-epoch, no-longer-entitled, moved or mismatched observations stay persisted as history but no longer count as current. Load a limited 24-row surplus instead of eight, and stop evaluating once three eligible rows have been found so revoked recent GitHub rows do not starve slightly older valid native facts. The existing per-Plan `recent_evidence` output and `PlanSituationResolver` therefore receive the same bounded eligible rows. No source row is edited, deleted or automatically re-signed; reauthorization alone never upgrades prior epochs.

**Verification contract:** Feature test covers 10 newer signed GitHub rows plus an older native observation, connected state, revocation, same-installation/new-epoch reconnect, one individually re-signed row, and an unsigned legacy row. It asserts current dashboard/situation counts and preservation of history. Run current G3 GitHub-derived context and Native Evidence regression along with the new dedicated CI. **Do not treat this text as CI-success evidence until the exact PR-head workflow completes.**

**Limitations:** This protects this Dashboard producer, not every Reflection/Timeline/history reader or an already-exported external prompt. Additional detached renderers and any real multiworker source invalidation still require separate G3 review. The increased (bounded) Evidence fetch and per-source guard are a P95/read-query performance acceptance item before wide public release. No migration, billing, OAuth/MCP scope, Render/Aiven/production branch, deploy or device action is authorized. G3 remains **OPEN**.

## 23. G3-R2 scoped continuation — Reflection historical source labels and current shortcut signals (2026-10-13)

**Observed gap after #518:** `ReflectionContextService::resolve` intentionally displays historical `TaskEvidence` from owned Plans in its Reflection Lens, but the Evidence lens still claimed all rows were currently “confirmed,” and the record presentation did not distinguish signed/current GitHub sources from unsigned, revoked or reconnected history. More importantly, `RecentReflectionShortcutCandidateService::records` counted every stored GitHub Evidence row toward **new, currently promoted** Reflection shortcuts, including revoked sources. `TimelinePageDataService` operates on WorkLogs, collaborative activity and completion events rather than these TaskEvidence rows; its canonical history is unaffected.

**Bounded current-versus-history contract:**
- Keep every authorized historical GitHub Evidence entry in Reflection Lens. Add a clear `historical_unverified` source status (and **過去の記録・現在は未検証** in subtitle, `PAST · UNVERIFIED` eyebrow and metadata) only when existing `GitHubTaskEvidenceReuseGuard::eligible(plan, evidence)` fails. Signed valid GitHub and native/human entries keep their original history presentation. Distinguish historical existence from eligibility; never parse old prose to infer provenance or rewrite the row.
- Reuse this exact guard **only for current shortcut scoring** in `RecentReflectionShortcutCandidateService`: exclude GitHub entries not currently authorized/signed before threshold, frequency, recency, continuity and explanation calculation. Existing WorkLog and native Evidence still count. No new Task/Plan mutation, no cached candidate row, no new model field, no GitHub REST on display.
- In the Evidence Lens summary, avoid implying that all historical TaskEvidence is verified *now*. Source invalidation is not an erasure request. The historical Timeline, completed Task, WorkLog and explicit user decisions remain visible under their existing permission checks.

**Acceptance:** deterministic CI regression covers valid signed source, disconnect, same-installation/new-epoch reconnect, unsigned legacy, one-row and two-row authoritative re-signing, retained revoked Reflection record with a user-visible unverified label, native record preservation and a surviving human WorkLog shortcut signal. Existing Reflection Map and V48.8 shortcut contracts must remain green. Test on current PR head; do not call a planned test a successful test.

**Bounded read-cost safeguard:** Reflection Lens still computes full historical counts but now verifies GitHub source status only for its displayed top 24 Evidence and top 24 Reflection entries (the Recent lens needs at most 16 Evidence rows). The shortcut prefilters its existing 14-day window **before** running current-source eligibility checks. Old historical items remain stored/countable and no pagination or history deletion is implied. Busy recent GitHub plans may still need batched/cached read-only eligibility evaluation under measured P95; never reuse a request cache at security-sensitive commit boundaries.

**Residual OPEN:** This slice does **not** certify direct remote GitHub status, historical free-text provenance, third-party exported prompts, all Reflection/Timeline off-builder consumers, full source deletion/hide policy, PostgreSQL two-worker isolation or deployment/device acceptance. Reflection Map still loads all owned historical TaskEvidence (pre-existing behavior), so P95 and query counts should be reviewed before public launch. No schema, Aiven/Render, OAuth/MCP, price, production branch or device operation.

## 24. G3-R4 bounded PostgreSQL Companion source commit serialization (2026-10-13)

**Confirmed race gap before this slice:** The G3-derived source guard checked GitHub `TaskEvidence` revision and Repository Artifact source epoch with normal SELECTs. A Companion persistence transaction (AI reply, candidate and Memory) or human candidate approval could therefore pass the last SELECT, then a separate PostgreSQL worker could revoke the GitHub Repository, change its epoch, or update an Evidence revision before the original derivative transaction committed. Ordinary sequential tests in earlier sections did not establish multi-connection serialization.

**Proposed commit boundary:** Add `CompanionDerivedEvidenceSourceGuard::isCurrentForCommit` as an explicitly transaction-only method. After locking the Plan/Task (where present), acquire `FOR UPDATE` locks on GitHub Repository Artifact rows for the Plan in ascending order, then the referenced `TaskEvidence` rows by ascending ID. Under these locks re-query current source epoch, ownership/entitlement and the exact Evidence revision by reusing `isCurrent`. Hold those locks until the caller's enclosing DB transaction commits or rolls back. Never hold this transaction across the external AI model request, GitHub REST call or third-party network. Apply at both Companion reply/Memory/Candidate persistence and human candidate approval. A source invalidated before the lock is acquired fails validation; a source update attempted after the lock waits or fails without changing committed source rows. Empty/legacy provenance retains the existing G3-R2 semantics; this is not a provenance backfill. Existing preview/history reads remain read-only and do not acquire commit locks.

**Acceptance proof:** A disposable PostgreSQL 16 GitHub Actions service runs real database migrations and an integration test using **independent PDO sessions**. Cases: (1) Companion starts first, validates signed source and retains repository/evidence locks until transaction commit; a rival connection cannot update either row (`55P03`) while the transaction is open. (2) A source revocation obtains its lock first and the Companion transaction cannot validate behind the uncommitted source update (bounded lock timeout; it must fail closed), then the previously valid signature fails after revocation commits. (3) A commit-only guard cannot run outside an explicit transaction. Existing SQLite unit/feature source-lineage and human-approval regressions must also pass on PR HEAD. These tests do not contact Aiven, production or third-party services.

**Known limits / G3 OPEN:** Proof is scoped to the Companion GitHub lineage and row-lock protocol. Other independently implemented G3 fences, provider entitlement/grant changes and third-party execution, wide source-event fan-out, PostgreSQL deadlock/retry policy across every writer, serializable predicate phantoms for newly inserted Artifact rows, p95 at scale, live deployment and physical device acceptance are not certified. Transaction isolation `READ COMMITTED` with row-lock retention is the intended narrow contract; timed-out competing writes are errors, not retries authorized by this test. Release or database cutover remains separately owner-gated.

## 25. G3-R4 continuation — GitHub observation/Companion Plan ↔ Repository lock ordering (2026-10-13)

**Specific concurrency defect:** `GitHubDevelopmentObservationService::persist` acquired `plan_artifacts` Repository `FOR UPDATE` and then `plans` `FOR UPDATE`. In contrast, G3 TaskEvidence and Companion commit paths acquire `plans` (and optionally `tasks`) **before** Repository. With PostgreSQL workers concurrently processing a GitHub webhook and approving a Companion candidate, the inverted order can create a Plan/Repository deadlock. It is a potential concurrency failure, not proof of an observed production incident.

**Minimal correction:** Following the authoritative external GitHub REST fetch (outside the transaction), lock the original selected `Plan` row first and compare its current owner to the captured owner; then lock the selected GitHub Repository Artifact row and verify it still belongs to exactly that Plan. Continue the existing validation for provider/repository name, installation, status, connection epoch, category/entitlement and source revision under both locks; the original selected root and Plan do not authorize themselves. Do not silently accept a relocated Repository or a newly connected same-installation source. Subsequent observation upsert remains serialized and the no-automatic-Task-completion contract is unchanged.

**PostgreSQL proof:** Dedicated ephemeral PostgreSQL 16 CI test invokes the real `persist` transaction with independent Laravel/PDO sessions. While another worker owns the Plan lock, the observation must time out on Plan **without first acquiring a Repository lock** (verified by SQL query instrumentation); that worker can then revoke the Repository and commit. After release, stale fetch cannot write. Reconnection with a fresh epoch still cannot replay old fetched facts; a separately fetched current-epoch fact can be stored and shows Plan `FOR UPDATE` before Repository `FOR UPDATE`. Confirm zero TaskEvidence and unchanged Task status. Existing V57.1 observation ordering, G2 GitHub revision and G3 source revocation regression must remain green on the exact PR head.

**Residual OPEN:** This verifies this specific inverse lock order, not a universal deadlock-free proof for every Canovia writer or an end-to-end concurrent web worker process. Companion candidate lock order, newly created Artifact predicate phantom, cross-service entitlement revocation, PostgreSQL retry/timeout UX, scale P95 and live/device acceptance remain separate G3-R4/R5 requirements. No migrations, production DB, Render/Aiven, OAuth/MCP or release operation.

## 26. G3-R4 — Companion human approval lock ordering and PostgreSQL rollback (2026-10-13)

**Observed lock-order risk after #524:** `CompanionMutationApplyService::apply` acquired a Candidate `FOR UPDATE` **before** locking its Plan and Task; G3 GitHub observation and TaskEvidence writers use Plan-first order. A concurrent Plan-first mutation and Candidate-first approval could create a cross-service wait cycle. This audit identifies a possible cycle; it does **not** assert that a production deadlock occurred.

**Bounded correction:** Treat the route-bound Candidate as an identity/target *locator only*. Snapshot original Plan/Task identifiers, lock the selected Plan, then Task, then fetch the Candidate under `FOR UPDATE`; compare its locked owner/target IDs to the initially selected IDs before doing any review, provenance verification or mutation. If the Candidate was moved, disconnected or dismissed while waiting, fail closed and leave the prior Task state and approval audit unchanged. Existing idempotent reply for an already-applied Candidate is preserved under the Candidate lock. The subsequent GitHub source commit guard retains the locks on Repository roots/Evidence until the outer transaction commits. This preserves `Plan → Task → Candidate → Repository → Evidence` in the bounded approval path; do not infer universal lock-order compliance by other writers.

**PostgreSQL acceptance:** Isolated PostgreSQL 16 test with two independent connections holds Plan `FOR UPDATE` in worker B. An attempted approval must timeout on Plan **without first locking Candidate**; B can dismiss Candidate, and a replay must be rejected with no `apply_request_id`, Task or candidate audit mutation. A healthy approval must lock Plan, then Task, then Candidate, apply the intended user-approved `next_action_note` exactly once and remain idempotent under the same request ID. Retargeting a Candidate after route binding must never silently apply to a different target. Existing human approval, Companion source lineage and Execution handoff regressions remain required on the exact PR HEAD.

**Retry and side-effect boundary:** Existing `DB::transaction(..., 3)` already retries database-classified deadlocks, not an open-ended user-visible replay. A failed DB transaction rolls back DB writes; its in-memory/session effects are not made transaction-safe by this lock-order change. In particular, `prepare_execution_request` copies an instruction to session during the approval transaction; separately verify rollback/session publication before claiming that *every* failure/retry is atomic. No external AI/GitHub call is added inside the lock. PostgreSQL all-writers fairness, live worker process E2E, owner/grant revocation, production and device acceptance stay **OPEN**.

## 27. G3-R4 — Companion approval session publication after durable DB commit (2026-10-13)

**Confirmed gap after #527:** `prepare_execution_request` human approval calls `ExecutionRequestHandoffService::confirmFromCompanion` **inside** the `CompanionMutationApplyService::apply` DB transaction. Before this slice the shared `confirm` immediately wrote a session `execution_orchestration.<plan>.<task>` value even though the Candidate had not been marked applied or committed. A later DB error/deadlock retry or outer transaction rollback could retain an apparently confirmed Execution Request even when the durable Candidate was pending. It was not evidence of an observed production incident, but the ordering violated the G3 human-confirmation commit boundary.

**Bounded fix:** Build and validate the same Execution Request and fingerprint in the existing approval transaction, but for `companion_candidate` only, register a Laravel `DB::afterCommit` callback to publish the captured session envelope. Require an active transaction; direct attempts to confirm an uncommitted Companion Candidate outside it fail closed. Laravel discards callbacks on rolled-back transactions and nested savepoints; a deadlock-retried attempt cannot publish an abandoned session. Existing Inbox / Development Brief confirmations retain their synchronous session updates, because they do not rely on a Companion candidate DB approval. No schema or permanent queue, no change to human-facing instruction or granted execution scope.

**Acceptance:** Dedicated disposable PostgreSQL 16 CI without test wrapping transactions verifies: (1) Session remains absent in-flight and after an intentionally failing approval, with Candidate status/request ID rolled back; (2) a successful transaction marks Candidate applied and publishes exactly after commit, with matching instruction, candidate and source eligibility; (3) an inner savepoint rollback discards the publication even if the outer transaction commits; (4) direct Companion handoff outside a DB transaction is refused. Existing Legacy Companion Handoff, V47.1 Execution Request, Companion Mutation Apply, and execution regeneration source-freshness regressions must pass on exact PR HEAD.

**Remaining risks:** A session transport failure **after** DB commit can prevent the handoff from being delivered even though Candidate is marked `applied`. A later idempotent apply currently returns "already applied" without independently reconstructing the session; recovery/replay delivery requires an explicit later acceptance slice. DB commit and durable session storage are distinct stores and no exactly-once distributed atomicity is claimed. Source invalidation after commit still makes the stale handoff unusable through downstream `sourceIsCurrent` fences. Third-party external execution, multi-worker session store ordering, entitlement revoke races, p95, production and device evidence remain **OPEN**. No Render/Aiven/production branch, OAuth/MCP or billing changes.

## 28. G3-R4 — bounded recovery of committed Companion Execution handoffs (2026-10-13)

**Gap after #528:** Moving the Companion session publication behind `DB::afterCommit` prevented phantom handoffs on rollback. However a committed Candidate can still lose its session transport (storage failure, expired session, different browser), while the pre-existing idempotent `apply` path merely returned `already_applied` without re-delivering anything. The `applied` Companion surfaces also offered no retry action. No recovery must ever re-apply Task/Plan changes or treat a fresh UUID as a new human confirmation.

**Bounded recovery:** For an **already-applied `prepare_execution_request` only**, accept only the persisted original `apply_request_id`. Re-lock Plan → Task → Candidate, verify current user/Task edit authority, and reacquire repository/Evidence source-row commit locks via `isCurrentForCommit`. `ExecutionRequestHandoffService::recoverCommittedCompanion` then compares the durable Candidate, owner, Task, type, applied target and original ID with `apply_audit.apply_request_id`, `applied_by_user_id`, `normalized_payload`, the exact approved `after` Execution Request and the unchanged Candidate payload. Only matching current-source, signed audit facts can reconstruct a **fresh session fingerprint** from the **same approved instruction/actor/time window**. Delivery is registered after DB commit (including nested transaction rollback protection); no new mutation, Candidate status or approval history is written. If any Execution session for that Plan/Task already exists, preserve its Packet and prompt rather than replacing it with old data (including a post-commit presence check). A new UUID never rehydrates a committed Candidate. Legacy unverified/unsigned and changed GitHub evidence remain history-only.

**User entry:** Both full Companion thread and palette show **実行リクエストを再開・復旧** on an eligible applied Execution Candidate, posting the persisted approval UUID to the existing protected apply route. No standalone unsigned replay API or external execution permission is created. The server remains the authority even if the UI label is exposed in stale pages.

**Acceptance:** Extend the disposable PostgreSQL `CompanionSessionCommitPublicationG3Test`: lost-session exact-UUID recovery without DB updates; preservation of newer Inbox/Execution session and no recovery using a new UUID; reject altered Candidate instruction/missing audit; reject revoked and different-epoch reconnected GitHub signed source without modifying the applied Candidate or Task. Existing source lineage, Legacy Companion/Execution Handoff, non-Execution Candidate idempotency and Workspace regressions must pass on exact PR HEAD.

**Open boundaries:** Session delivery/storage is still not an atomic transaction with PostgreSQL. No claim of exactly-once network delivery, cross-browser session recovery, time-travel restoration, independent GitHub REST verification on replay, or all-worker entitlements/deadlock immunity. The human must request bounded recovery while still authorized; stale sources require a new approved proposal. No schema, Aiven, Render, production branch, OAuth or release action.

## 29. G3-R4 — Companion Product Grant revocation commit serialization (2026-10-13)

**Specific gap after #529:** Companion already re-evaluated feature entitlement after its AI gateway returned, but `FeatureAccessService`/ProductGrantResolver read active `user_product_grants` using normal SELECT. Another PostgreSQL worker can update `expires_at` or DELETE the grant after the last entitlement SELECT but before the Companion's assistant text/Memory/Candidate transaction commits. Similarly the human Candidate apply and committed Execution replay checked entitlement only at the controller preflight, outside the mutation transaction. The gap is a potential interleaving, not a reported production incident.

**Bounded grant-lock contract:** New `CompanionEntitlementCommitGuard::allowed(User)` explicitly requires an open DB transaction. After target locks, acquire `FOR UPDATE` locks on **all existing Product Grant rows belonging to that actor in ascending ID order**, regardless of active status or grant source, then re-evaluate `FeatureKey::CanoviaCompanion` through the existing Free/Premium/Gift/Sponsor/Admin `FeatureAccessService` policy. Keep those locks until outer commit. Use it in Companion assistant/Memory/Candidate persistence after Thread/Plan/Task target locks and in human Candidate apply/recovery after Plan/Task/Candidate locks, but **before** GitHub Repository/Evidence source locks or Task/Plan updates. A competing UPDATE/DELETE to an existing row must wait or win before the read; no external model/provider call is added to the transaction. Preserve existing purpose/owner/collaboration checks, original approvals and all source lineage fences. Deny stale Product Grant entitlement rather than silently allowing a preflight result. The covered lock order is Thread/Plan/Task (as applicable) → Candidate (when present) → Product Grant → Repository → Evidence.

**PostgreSQL acceptance:** Disposable PostgreSQL 16 test uses two distinct sessions. Guard-first must block rival expiry until commit (`55P03`), then fresh authorization denies. Rival-expiry-first must block authorization until expiry is committed, and then deny. A deleted grant must prevent human Candidate mutation/audit. A real `CompanionMutationApplyService::apply` called inside a parent transaction must retain the grant row lock until parent commit and preserve Plan → Task → Candidate → Grant order. An alternative current Gift grant must remain eligible when a different Product Grant expired; expiry of the final grant must deny. Reject direct commit-guard calls outside transactions. Existing Native AI persisted-reply/memory/candidate and human-approval tests must pass on the exact PR HEAD.

**Remaining OPEN:** This does **not** cover insert phantom rows where an actor has no existing grant, dynamic administrator preview/config changes, billing-provider webhooks not updating `user_product_grants` in the same database, membership removal at a separate table, every other AI feature commit fence, or all-writer lock-order graph across services. Subscription cancellation and product policy semantics remain with EntitlementResolver. Scale P95 and live/device readiness are not certified; no migration, Render/Aiven, production branch, OAuth/MCP or release action.

## 30. G3-R4 — Companion collaborative membership revocation serialization (2026-10-13)

**Commit window:** G3's `NativeAiPurposeAuthorizationGuard::failure` and `PlanOwnershipService` historically read `plan_members` using ordinary queries (the latter can cache roles during a request). A parallel administrator can downgrade a collaborator from Editor to Viewer or remove the membership after the final SELECT but before Companion reply/Memory/Candidate commit, human Candidate apply or approved Execution Request re-delivery. A stale cached role is not commit authorization. In a shared Plan, Viewer is entitled to a read-scoped Companion conversation; Task/Plan mutation requires Editor/Owner. Disable-collaboration and Plan owner transfer are protected by the Plan lock, but membership changes need the membership row lock too.

**Bounded fix:** New `CompanionMembershipCommitGuard::canAccess(User, ?Plan, bool requiresEdit)` requires an open transaction, freshly reads the selected Plan (whose row the caller has locked), takes `FOR UPDATE` on the actor's existing `plan_members` row, and re-evaluates owner/collaboration/role from DB state instead of any request-scoped cache or eager-loaded relation. In `CompanionConversationService`, run the view-scope check after Thread → Plan → Task locks and before Product Grant/GitHub source locks and AI-derived writes. In `CompanionMutationApplyService`, after Plan → Task → Candidate locks, enforce edit permission for Plan/Task/Inbox changes and Execution preparation/re-delivery; personal Future Memo retains its established view-scope policy. Stop with no new derived writes or mutation audit on denial; preserve original human approval and source proof requirements. Planless/global Companion remains outside this membership gate.

**Writer alignment:** `PlanCollaborationController::updateMember/removeMember` now uses one transaction and locks Plan → Member before writing the role/deletion and recording the existing activity log, rechecking current Plan ownership and membership relation after locks. This avoids introducing Plan/Member inverted lock order against derived commits. Direct SQL writers are still serialized on the member row but may need separate lock-order audit where they touch more than one resource. Joining/collaborative toggles, other AI features, and member `INSERT` predicate phantoms are not certified.

**Acceptance:** Disposable PostgreSQL 16 CI uses an independent PDO connection to hold a deletion/role downgrade against a current Editor: Companion-first lock blocks the removal until commit; removal-first holds the membership lock and prevents authorization behind an uncommitted DELETE; a Viewer is allowed read scope but not Task mutation even if a stale `PlanOwnershipService` role cache still says Editor; real human Candidate apply must hold Plan → Task → Candidate → Member → Product Grant locks until parent commit; provider-return member revocation prevents new AI assistant/candidate/Memory persistence. Owner and Planless/disabled-collaboration boundaries and transaction-only requirement are also covered. Existing Product Grant, GitHub provenance, legacy Execution and workspace CI still required on exact PR head.

**Remaining OPEN:** Cross-service permission writes not using the aligned Plan → Member contract, Plan Member `INSERT` predicate gaps, user/session deletion, grant or subscription changes in external stores, Team/Provider permissions, broader AI workflows and Live multi-worker device E2E are not certified. No schema, Aiven/Render/production branch, OAuth/MCP or release operation.

## 31. G3-R4 — Plan invitations: join/disable/rotation lock-order and credential freshness (2026-10-13)

**Writer surface audit after #531:** The bounded Collaboration member role/remove writers acquire Plan → Member, while `PlanCollaborationService::addViewer` is called only by `PlanCollaborationController::join`. Both `joinByCode` and `joinByToken` originally loaded an eligible Plan without locks and then called `firstOrCreate` outside a transaction. If the Plan was disabled, its invitation rotated, its owner removed, or a concurrent join/remove occurred after this locator read, the request could still create a viewer row and `member_joined` history based on stale eligibility. This is a possible TOCTOU path, not a confirmed production incident. `PlanController::store` creates a newly owned Plan and may enable its collaboration flag; it does not itself issue a join. Owner/member role adjustments and removing members were covered by #531.

**Bounded update:** Treat initial invite-credential search as a locator only. Both logged-in join routes call the same transaction: `Plan::lockForUpdate` → validate **current** `is_collaborative`, non-null owner, and the exact current join code or invite token via `hash_equals` → existing unique `PlanMember::firstOrCreate` → `member_joined` activity log only on a newly created row. A stale code returns the original form with an error, a stale token is 404, and neither creates a membership or log. Owner rejoining doesn't create a member. Existing members retain their established Editor/Viewer role and are never downgraded via invite replay. The plan lock serializes concurrent member join/role/remove, invite rotation and plan disable. `PlanCollaborationController::enable/disable/regenerateInvite` re-lock the Plan and recheck current owner inside their own transaction; rotation logs in the same transaction. Guest invite preview remains a non-authoritative read; no member row is created until authenticated joining. No additional invitation capability is introduced.

**PostgreSQL acceptance:** A disposable PostgreSQL 16 acceptance file uses two independent sessions, injecting a plan disable/credential rotation between an initial invitation SELECT and the later join authorization. Expect no stale member insert, and no `member_joined` event. An outer transaction containing a valid join must retain the Plan lock until commit, blocking a rival disable (`55P03`). A repeat join never resets Editor role or emits duplicate events. A rival Plan lock blocks an administrator's invite regeneration; valid token and owner no-membership paths remain supported. Execute existing G3 membership revoke, Product Grant, GitHub source, approval/Execution handoff, Workspace and migrations suites on the exact PR HEAD.

**Remaining OPEN:** Wider writer inventory does not prove all future services follow the Plan-first lock contract. There are no universally locked predicate/insert fences for all membership and entitlement tables; `PlanCollaborationService::addViewer` assumes its sole active caller provides the Plan lock and credential revalidation. Other AI workflows, third-party invites, owner deactivation and Plan deletion E2E, rate-limit fairness, production and iOS/Web smoke remain unverified. G3 exit is not closed by this slice. No schema change, Render/Aiven or `production` branch action.

## 32. G3-R4 — Membership identity continuity / remove-and-rejoin ABA (2026-10-13)

**Newly audited interleaving after #532:** A row lock on the *current* collaborator membership ensures deletion or role UPDATE cannot commit between the final authorization SELECT and the AI derivative commit. It does not establish **membership identity continuity** across the external AI call or between candidate generation and human approval. An Editor can be removed, then legitimately rejoin (or be re-invited) to the same collaborative Plan; the new `plan_members` row may have the same actor, role, Plan, and current eligibility as the original, but that is a **new membership**. The prior generation's private context and Candidate approval should not be silently treated as newly approved. No production exploitation is asserted.

**Narrow fix:** `CompanionMembershipCommitGuard::capture(actor, plan)` records the actor's current scope before private Context/Native AI generation: `global`, `owner`, a `member` with exact existing `plan_members.id` and role, or `unavailable`. The latter fails closed before AI execution. `canAccess` already requires transaction + the caller's Plan lock and locks the current member row; an optional generation-time identity argument now must strictly equal the current owner/member identity. This rejects absent→new join, old member DELETE→INSERT with the same role, Editor→Viewer, and Owner↔Member scope changes before derivative commit, while preserving the current owner/global policy. The original member identity is persisted on the assistant's `membership_provenance` metadata and copied to its Candidate metadata. New Candidate human approval/recovery checks the stored identity **in addition to current edit/view permission**, human confirmation, entitlement, thread target and GitHub provenance. Pending-candidate de-duplication must not suppress a newly generated proposal merely because old (possibly different-membership) content matches.

**Acceptance:** Disposable independent-session PostgreSQL 16 CI checks remove→rejoin as the same Editor and rejection of old AI reply/Memory/Candidate, old-candidate approval denial, a fresh same-member AI reply and successful approved Task edit, missing-at-capture→subsequent member insert denial, and Plan-lock protection against a concurrent membership INSERT while derivative commit holds the Plan row. Existing earlier G3 source/grant/member/approval and Workspace CI remain required for exact PR HEAD.

**Deliberate compatibility limit:** Candidate rows already created without `membership_provenance` are still validated by the current member role and source/approval guards. They **cannot be certified against historic membership ABA**, because no identity proof was stored when generated. Closing this historical replay gap requires a separate owner decision on rejecting or invalidating unproven legacy collaborative Candidates versus preserving existing pending approvals. Likewise an Owner→different Owner→same Owner interval and rapid role flip→restore within one membership ID are not covered without a durable Plan/member authorization epoch. The insert race of any writer bypassing the audited Plan-first contract, admin overrides, other AI derivative pipelines and production/device validation remain OPEN. No schema, billing, Render/Aiven, OAuth/MCP, `production` or live deployment action.

## 33. G3-R4 — legacy collaborative Candidate provenance migration boundary (2026-10-13)

**After #533:** Newly generated Companion Candidates carry generation-time `membership_provenance` and are bound to a verified PlanMember identity. Pending Candidates created *before* #533 have no such record: even a currently valid Editor/Owner cannot prove whether the Candidate was generated before membership removal, owner reassignment or rejoining. An `apply_request_id`, an old source-provenance signature, a Plan association, or a current role does **not** reconstruct that missing historical identity. Legacy pending Candidates therefore cannot safely be promoted to live authority by a current-role check alone.

**Bounded fail-closed policy:** While a Plan is currently **collaborative**, any historical Companion Candidate missing an array `membership_provenance` is *history only* rather than a pending user-authorizable mutation. `CompanionMutationApplyService::apply` enforces this **after locking Plan → Task → Candidate**, before the member, grant and GitHub source checks and before writing a Task/Plan/Inbox/Memory, approval audit or an Execution handoff. This also blocks re-publication of an already-approved **Execution session** from an originally unproven Candidate using its original `apply_request_id`. It does **not** delete, alter status, silently approve, backfill a fabricated proof, or reverse historical applied mutations; harmless already-applied idempotency remains subject to its existing fences. Owner access does not bypass the historical-provenance check on a collaborative Plan because an owner could previously have been a member. Legacy Candidates on currently noncollaborative personal Plans and Planless Candidates preserve their existing approval behavior and downstream gates. Non-array malformed provenance remains denied by the established current-member identity guard.

**User experience:** The shared `CompanionMutationApplyService::reviewPreview` read model adds `membership_revalidation_required`. Both the full Companion thread and floating Palette explain the original membership cannot be verified, leave the Candidate visible for context and dismissal, and hide its apply and Execution-recovery buttons. Users ask Companion to make a **new proposal from the current Plan/Task context**; the existing #533 membership-aware pending-duplicate check does not suppress a new proposal based solely on old identical content. There is no new replay endpoint, mutation authorization shortcut, or automatic replacement. A direct HTTP submission of a hidden action is rejected on the server regardless of UI state.

**Acceptance:** Extend the existing isolated PostgreSQL 16 two-session membership suite to cover legacy shared Editor pending Task candidate with zero writes/audit, current Owner still denied historic unproven shared candidate, applied unproven Execution handoff not rehydrated, full Companion read model showing the warning with no action button, unchanged legitimate stamped Candidate happy path and noncollaborative owner legacy compatibility. Existing source/grant/session/approval/Workspace CI must succeed on exact PR HEAD. This does not claim all forms of historical delegated provenance can be recovered.

**Residual OPEN:** A Plan that was historically collaborative but is now noncollaborative has no durable old membership epoch or provenance proof; this narrow policy preserves personal-plan legacy compatibility rather than retroactively revoking all old candidates. Owner→other owner→same owner, Editor→Viewer→Editor inside one member row, user deletion/recreation, other derived workflows, provider entitlement/Policy changes and distributed session delivery require separate bounded audits. The user-facing re-proposal still requires current feature entitlement and human approval. No migration, Render/Aiven DDL, billing, OAuth/MCP, `production`, deployment or device changes.

## 34. G3-R4 — durable Owner/Editor authorization epochs against ABA (2026-10-13)

**Observed residual after #535:** A stable membership row ID and current role cannot reveal Editor → Viewer → Editor while Native AI is running or a Candidate awaits approval. The same is true for a Plan owner changing Owner A → Owner B → Owner A, or collaborative permissions being disabled then re-enabled. Eloquent model `updated_at` alone is not a reliable monotonic authorization version, especially when updates occur within the same timestamp precision window. No production exploit is asserted.

**Bounded schema:** Add default-1, non-null, unsigned BIGINT `authorization_epoch` to `plans` and `plan_members` via a reversible additive migration. All historic rows start at epoch 1; **never backfill a fabricated historical epoch**. `Plan` model `updating` hook increments its epoch when and only when `user_id` or `is_collaborative` is dirty. `PlanMember` model `updating` hook increments its epoch when and only when `role` is dirty. A no-op role save or cosmetic Plan update does not revoke existing AI work. The already-audited Plan-first controller joins, membership role/removal and Plan collaboration enable/disable obey Plan→Member lock order. `GuestPlanClaimService` now takes an actual Plan FOR UPDATE transaction to serialize changing a guest Plan owner to an authenticated user with a Companion commit or competing claims.

**Proof/authorization:** `CompanionMembershipCommitGuard::capture` includes `plan_epoch` for Owner and Member, and `membership_epoch` for Member, alongside stable owner user ID or member row ID + role. The transactional `canAccess` checks current role and current epoch-bound identity under Plan→Member locks. The original identity is already stored on newly generated assistant replies and Candidate metadata by #533 and applied/re-delivered only if identical. An Editor's downgrade and re-promotion, Owner A→B→A, collaboration OFF→ON and member replacement now fail instead of inheriting previous AI output or approvals.

**Legacy policy and UI:** Historical collaborative Candidate `membership_provenance` arrays created before this change lack `plan_epoch` or `membership_epoch`. Despite carrying a member row ID, they **cannot** prove the absence of an intervening role/owner mutation: they become **history-only** when the Plan is currently collaborative, blocked on apply/recover and marked as needing a new proposal in both the Companion page and Palette, using the existing #535 UI. Keep historic approval/audit records; do not infer past roles/epochs or mutate pre-existing rows. Owner-only legacy **private Plan** proposals with no epoch keep the existing compatibility policy, explicitly not certified for old Owner ABA. New private-Plan candidates carry the Owner epoch and are checked strictly.

**PostgreSQL verification:** Disposable 16 acceptance extends the existing membership-race tests: Editor→Viewer→Editor with original row and role identity fails old candidate apply; owner handoff and return on same Plan fails old owner proposal; collaborative OFF→ON fails old proof; no-op role/cosmetic Plan changes preserve identity; old pre-epoch shared proposal remains visible but unapprovable; guest→authenticated ownership claim advances Plan epoch under Plan lock. Existing entitlement, source, Session, Legacy Execution, collaboration-join, Migration Recovery and Workspace CI remain required against the **exact PR HEAD**.

**Remaining OPEN and release gate:** Eloquent model `update/save` writers participate; raw `DB::table(...)->update`, bulk `Model::query()->update`, and external direct SQL writer paths bypass Eloquent update hooks. Every new role/owner/collaboration writer MUST align Plan→Member locks and increment the epoch; audit and remove any bypass before calling the contract universal. Future multi-process/third-party writers may require DB-level trigger/change-event enforcement and separate tests. Existing live DBs need the additive migration before corresponding code rollout; **do not run it on Aiven or deploy Render from this development PR**. No `production` branch, OAuth/MCP, subscription, billing or live/user migration action. G3 remains OPEN.

## 35. G3-R4 — database-native epoch enforcement for bypassing Eloquent (2026-10-13)

**Residual proven by source review:** #537 increments `plans.authorization_epoch` / `plan_members.authorization_epoch` through Eloquent `updating` model events. `DB::table()->update`, raw `DB::statement` and independent database sessions **do not run those hooks**. Owner A→B→A or Editor→Viewer→Editor completed in raw SQL could therefore reuse an earlier Companion identity. Neither an `updated_at` timestamp nor a request-cache role check closes this ABA.

**Change in authority:** The database now owns increments. A second, ordered additive migration creates named trigger contracts on `plans` (changes to owner `user_id` or `is_collaborative`) and `plan_members` (changes to `role`). PostgreSQL uses BEFORE UPDATE PL/pgSQL and `IS DISTINCT FROM`; MySQL 8 uses BEFORE UPDATE and null-safe `<=>`. On every UPDATE, both engines assign the new epoch from **OLD epoch** (increment exactly once for an actual protected-field change, otherwise keep it unchanged), *including when callers supply their own epoch value*. This prevents stale Eloquent saves, bulk queries, direct SQL and independent clients from resetting, skipping or double-incrementing a generation. The previous Eloquent update hooks are removed. SQLite test environments use AFTER UPDATE OF protected fields triggers with a second epoch-only write because SQLite does not support BEFORE UPDATE assignment to NEW; this covers actual role/owner/collaboration changes but does **not** protect a direct SQL `authorization_epoch`-only overwrite. PostgreSQL/MySQL are the intended stronger enforcement contracts, and the SQLite limitation is explicit.

**Transaction and ordering:** Trigger writes are in the same transaction as the protected change; a rollback also undoes epoch progression. Existing Companion commit guard still obtains Plan→Task→Candidate→Member locks before checking epoch and writing AI-derived results. A direct permission UPDATE targeting the same locked rows is serialized by the DB row locks, even if it bypasses the Laravel controller. Raw SQL cannot automatically impose a global Plan→Member lock order across every future writer, so cross-table deadlock handling and unrelated external writers remain to audit.

**Acceptance:** The new `AuthorizationEpochDatabaseTriggerG3Test` runs on disposable PostgreSQL 16 and disposable MySQL 8.0/8.4 (and can run under SQLite), asserts raw SQL same-member role ABA and bulk owner/collaboration ABA invalidate prior Companion proof, non-auth/cosmetic and no-op writes do not increment, Eloquent updates increment exactly once, transactional rollback restores both fields, and explicit epoch spoof attempts are ignored on PostgreSQL/MySQL. Existing G3 membership, approval, grant, source, Execution, Workspace, Production Migration Recovery and disposable MySQL schema CI remain required against the PR HEAD. Direct-SQL acceptance uses per-test database transactions against an already-migrated disposable schema: PostgreSQL is freshly migrated in its dedicated step and MySQL uses the completed P0 schema job. This avoids re-running historic MySQL constraint migrations inside every regression case. Migration `down` removes these triggers/functions without dropping the preceding epoch columns; the previous #537 migration owns those columns.

**Production and security boundary:** This is **checked-in migration code on development `main` only**, not an executed Aiven DDL. MySQL production rollout requires distinct verification of actual target schema, trigger privileges (including `TRIGGER`), binary-log `log_bin_trust_function_creators` / privileged DDL policy (MySQL 1419), engine compatibility, backup/rollback and a separately authorized staged release. Do not run migration/deploy as a side effect of this PR. G3 remains OPEN for historical pre-epoch candidates, direct admin-privileged DDL that drops triggers, malformed external clients, all non-Companion pipelines, role-model correctness, permission-change deadlock telemetry, and physical iOS/Web smoke.

## 36. G3-R4 — immutable collaborator identity binding across raw DB writes (2026-10-13)

**Post-#538 audit:** The member permission proof binds the original `plan_members.id`, `plan_id` (via scoped lookup), `user_id` and role/epochs. Existing app writers create a member using `firstOrCreate(plan_id, user_id)`, only UPDATE `role` while holding Plan→Member locks, or DELETE the row. No legitimate product flow reassigns the same membership ID to a different actor or Plan. Nevertheless the database previously allowed raw/ORM UPDATE to `plan_members.plan_id` or `plan_members.user_id`. An external writer could move a persistent membership row to another actor/Plan and back, restoring all identifying fields without incrementing the `authorization_epoch` (which, as of #538, covered only role changes). A formerly generated Companion approval could then falsely appear continuous despite a change of membership identity. This does **not** establish a production exploit.

**Stronger contract rather than counting moves:** The correct domain operation for changing a participant or its Plan is **remove + new invitation/join** with a new membership row ID. A membership's `plan_id` and `user_id` are immutable after INSERT. Introduce a subsequent, additive **database** migration with an independent BEFORE UPDATE trigger, alongside the existing role epoch trigger, to reject any real change to either binding (including raw SQL, bulk updates and `forceFill`). A no-op assignment of the identical values remains permitted. PostgreSQL rejects with SQLSTATE `23514` from its explicitly named PL/pgSQL function; MySQL uses `SIGNAL SQLSTATE '45000'` after the existing authorization-epoch trigger; SQLite uses `RAISE(ABORT)`. Role-only updates remain supported and increment the existing member epoch exactly once; DELETE + INSERT remains the supported new-admission path. Both unchanged and rejected writes leave original role, row identity, epoch and AI Candidate provenance unchanged. No attempt to *infer* past membership relocation from stored epochs.

**Lock and independent-session acceptance:** Existing commit order remains Plan→Task→Candidate→Member. The immutable DB check covers direct SQL even when an unrelated writer does not take a Plan lock; a committed forbidden change is impossible. Dedicated PostgreSQL 16 testing first holds the Plan/member locks through a Companion read-guard transaction while an unrelated connection attempts a member rebinding (the rival waits or times out on the row lock), then verifies the same mutation is rejected by the trigger after locks release. Cross-engine disposable PostgreSQL/MySQL 8.0/8.4 tests reject raw SQL changing a member's actor, Plan or both actor+role/Plan+role; check rollback/savepoint integrity, no-op safety, and verify Eloquent `forceFill` cannot evade the database. Removal and rejoin creates a distinct membership ID and a valid fresh Companion permission proof while the old proof remains denied.

**Release/rollback boundary:** This migration is intentionally **after** the already-merged epoch-trigger migration; it does not change previously applied migration bytes. PostgreSQL functions use CREATE OR REPLACE to accommodate isolated `DatabaseMigrations` refreshes; down removes only this new binding trigger/function and leaves the existing epoch trigger. Creation of a second MySQL BEFORE UPDATE trigger still requires actual target database DDL privileges and binary-log policy (1419) verification: the CI-only `log_bin_trust_function_creators=1` setting is *not* evidence of production/Aiven permissions. MySQL DDL is not transactional, so operator rollout and abort/recovery require a separately reviewed window. No production/Aiven DDL, Render deployment, release promotion, user migration, or OAuth/MCP changes here. G3 remains OPEN for historical out-of-band writes before these triggers, independently managed DB/DDL privileges, cross-table lock-order audits and other AI-derived workflows.

## 37. G3-R5 — Native Study AI generation and grading membership epoch continuity (2026-10-13)

**Cross-surface audit (Companion to Native Study):** Both `StudyPracticeGenerationCommitFence` and `StudyPracticeAssessmentCommitFence` already reloaded and locked current Plan/Task, checked source fingerprints, active Editor role and purpose entitlement before publishing AI-derived results. But mere live Editor eligibility does **not** prove that the actor's membership epoch has remained continuous since the provider request. In shared Plans Editor → Viewer → Editor, or removal → new same-role membership, the previous generation source signature did not include member row identity and epoch. The assessment signature also omitted the Plan's owner/collaboration authorization epoch, permitting Owner A→B→A in an otherwise unchanged Plan. These are residual authorization ABA conditions, not a claim of confirmed production compromise.

**Guard reuse rather than a new epoch:** Before sending Native Study provider input, the existing source fingerprint now includes `CompanionMembershipCommitGuard::capture` for the current actor. Owner snapshots contain `owner_user_id` and `plan_epoch`; shared-member snapshots contain immutable `membership_id`, `role`, `plan_epoch` and `membership_epoch`. Existing Plan/Task/session/learning-result context remains included. This is a digest only; it does not persist raw private context or add a new table. At final commit, both Study fences acquire Plan→Task→(Session for assessment) locks and then invoke `CompanionMembershipCommitGuard::canAccess` for the editor actor to lock its member row and confirm current authorization. They regenerate the same scope digest under those locks and compare it to the pre-provider digest. Even if original role and Plan owner return, the generation epoch or new member row ID cannot match. Native AI results are then withheld instead of publishing questions, question demands/candidates, grading attempts or evidence. Ordinary uninterrupted owner/editor flows and authorized read-only history remain supported.

**Regression matrix:** Add Native Study provider-after-hook cases with Owner A→B→A (assessment), Editor→Viewer→Editor (generation and assessment) and member delete→same-role rejoin (generation and assessment). In each case, existing draft material remains, while stale generated sessions/question candidates/grades/evidence must not be persisted. Targeted feature suites run on SQLite with existing Native/Hybrid regressions and against disposable PostgreSQL 16 together with the existing membership race suite. Trigger-backed authorization epoch tests remain the source of truth for independent SQL updates (PostgreSQL/MySQL); this PR does not execute or modify any production DDL.

**G3 remains OPEN:** Native Study completion of this focused lane does **not** imply Inbox AI, Recall extraction, Study Scope capture, Execution Packet, Developer Context and other AI-derived workflows have identical membership continuity fences. Each must be individually audited for a generation-time membership proof, the same lock order at durable publication, source revision and entitlement revalidation. Product-specific idempotency and legacy session compatibility require separate tests. No change to Render, Aiven, `production`, release branch, paid entitlements or OAuth/MCP.


## 38. G3-R5 — Inbox AI linked-Plan authorization epoch continuity (2026-10-13)

**Observed gap after #543:** `InboxAiSuggestionCommitFence` rechecks the Inbox item's owner/token, original source bytes and metadata, current linked-Plan Editor access and Native AI entitlement at the durable `routing_suggestion` write. Its source signature did not include the Plan owner/collaboration authorization epoch or the linked editor's immutable membership ID/role epoch. An Owner A→B→A, Editor→Viewer→Editor, or Editor removal→same-role new membership while the provider is running could leave the source and final permissions apparently unchanged. This is a source-code gap, **not** evidence of a production exploit.

**Bounded change:** For an Inbox item linked to a Plan, the pre-provider source signature now includes `CompanionMembershipCommitGuard::capture` for the authenticated acting Owner or Editor. Deny absent/non-editing actors before sending the provider input. At durable commit, keep the Inbox source row lock and current Plan lock; call `CompanionMembershipCommitGuard::canAccess(..., requiresEdit: true)` to take the member row lock, then regenerate the epoch-bound source digest while those rows remain locked. Only an exact match can publish the AI routing suggestion. A returning role is not equivalent to uninterrupted authorization. No new source bytes, member IDs or derived signatures are written to Inbox metadata; they remain ephemeral SHA-256 material. The item still requires explicit human choice before routing to another resource. Planless Inbox inputs preserve existing user/guest token and purpose behavior (anonymous Native AI commit stays denied).

**Acceptance:** Extend `InboxAiSuggestionCommitFenceG3Test` with unchanged shared Editor success; Owner reassignment-and-return refusal; Editor downgrade-and-return refusal; same-role remove/rejoin refusal; and irrelevant Plan title updates remaining valid. Keep existing chat-capture fail-soft, source edit/file replacement/deletion, routing confirmation, ownership transfer, entitlement expiry and Space Station regressions. Run the Inbox AI suite both on its normal feature workflow and against disposable PostgreSQL 16 in the existing G3 race workflow. These are provider-after-hook ABA regressions, **not** independent-session proof of every Inbox row-lock ordering.

**Remaining OPEN:** Recall candidate extraction, Study Scope analysis, Execution Packet, Developer Context and other AI-derived pathways still require individual authorization-epoch audits. Inbox's existing Inbox→Plan→Member lock order must be compared against all independent Plan→Inbox writers for deadlocks before declaring universally race-free operation. Historic already-persisted Inbox suggestions do not carry reconstructable membership proof; no retroactive reassignment/backfill or automatic human approval is attempted. Existing production Aiven trigger migrations, staged release checks, Render/production branch, device and MCP authorization gates remain unchanged. No database schema changes, live deployment, or entitlement/billing change in this slice.


## 39. G3-R5 — Recall single/batch AI extraction authorization epoch continuity (2026-10-13)

**Gap after #544:** The common `StudyRecallExtractionCommitFence` already checks linked Plan/Task, current Editor permission, Automatic AI entitlement, original text/private-file SHA-256, source ownership/status and Source lock before persisting review Candidates and ready Source results. However, current role alone cannot distinguish Editor→Viewer→Editor, member deletion→same-role rejoin or Owner A→B→A during the Native AI provider call. An identical current owner/editor and unchanged Source could wrongly admit a derivative generated under a revoked membership. This is a code-path risk, not proof of production exploitation.

**Current bounded contract:** At the start of both single-source extraction and 2–5 file batch extraction, capture `CompanionMembershipCommitGuard::capture` for the acting authenticated Owner or Editor. Viewer/unavailable/anonymous admission does not pass this Native extraction fence. Keep the original proof in memory only, and require it as a nonoptional argument to the shared `assertCanCommit` call. Under the existing Plan→Task lock transaction, lock the actor's current member row and compare the exact originally captured owner/member epoch and immutable member identity via `canAccess(..., requiresEdit: true, expectedIdentity)`. Only then verify current Native entitlement and original per-Source fingerprints under ordered Source locks. Any epoch or member-row mismatch raises `native_ai_target_changed` before writing Candidates/Source ready results, and the existing failure handler deliberately does **not** mark a now-unauthorized Source as failed. Source edits and provider/entitlement errors retain their existing separately validated retry semantics. Successful results remain pending human review; this patch never promotes cards.

**Acceptance:** Existing `RecallAiCommitFenceV05Test` remains authoritative for grant expiry, source text/file edits, deleted/moved Source, task moves, atomic batch refusal and authorized owner happy paths. Add original collaborative Editor positive paths in single and batch, same-role rejoin and role ABA refusal in both, Owner A→B→A refusal in both, and a nonauthorization Plan title-edit positive case. Require the existing Recall dedicated workflow (including legacy single/batch) and the disposable PostgreSQL 16 G3 race workflow plus Migration Recovery/Workspace checks on the exact PR HEAD. Provider-after-return tests do not independently prove all concurrent row-lock schedules.

**Remaining OPEN / deployment boundary:** This slice does not audit subsequent human-review promotion of historical Candidates for post-generation membership invalidation; that is a distinct approval-time policy decision. Study Scope capture, Execution Packet, Developer Context and other derived artifacts remain individually pending. Global lock ordering with external Source writers, durable historical candidate lineage, and live MySQL epoch-trigger rollout must be reviewed before closing G3. No DB migration, source re-signing/backfill, paid AI entitlement change, Render/Aiven/production deployment, OAuth/MCP activation or device acceptance is authorized here.


## 40. G3-R5 — Study Scope Capture AI authorization epoch continuity (2026-10-13)

**Gap after #545:** `StudyScopeCaptureCommitFence` already seals a Study Plan's study-domain context and original private Inbox image/PDF bytes, locks Plan→StudyScopeCapture→InboxItem, checks active Editor and the Free `StudyScopeCapture` entitlement, and prevents late AI drafts from overwriting a confirmed scope. But a current-role-only Editor check could not detect Plan Owner A→B→A, Editor→Viewer→Editor, membership deletion→same-role rejoin, or collaboration OFF→ON while a provider was processing private scope input. This is a potential stale derivative, not an asserted production exploit.

**Bounded change:** Under the original prepare transaction and the final draft/failure transactions, take the original Plan→Capture→Inbox locks, then require `CompanionMembershipCommitGuard::canAccess` for the actor with `requiresEdit=true` and its member-row lock. Capture the Owner or Editor proof (`plan_epoch`, and for Editor `membership_id / role / membership_epoch`) from `CompanionMembershipCommitGuard::capture` while those locks are held, and include that proof in the pre-provider source hash and the newly computed post-provider hash. An ABA or new membership row invalidates the original hash even if the original role and source bytes have returned. The signature remains transient and is **never** backfilled or stored on Capture/Inbox, in Native AI run metadata, or in logs. Preserve the existing study-domain/source/entitlement checks, human confirmation boundary, and Free capability. Ordinary Plan title edits, which are neither provider input nor an authorization boundary, remain allowed.

**Failure and retry:** `StudyScopeCaptureAnalysisService::recordFailureIfCurrent` still goes through the exact same post-provider fence before marking a Capture failed. If the actor's authority was revoked or cycled, a late provider error cannot overwrite a newer capture state or falsely mark another user's source as failed. Ordinary provider errors retain the original private file and retryable Capture status. No Task, Intelligence State, progress, or confirmed `StudyScopeItem` is created by an AI draft.

**Verification:** Expand `StudyScopeAiCommitFenceG3Test` with uninterrupted collaborative Editor/Free success, Owner A→B→A, Editor→Viewer→Editor, member removal→same-role rejoin, collaboration OFF→ON, unrelated Plan title edit, and provider error after Editor ABA. Retain existing source byte/edit/delete, Plan domain transfer, human confirmation, entitlement and provider failure tests. Run existing dedicated Study Scope/V53 regressions plus this suite in the disposable PostgreSQL 16 G3 workflow; also require Migration Recovery and Workspace validation at exact PR HEAD. Provider-after-hook tests are not an exhaustive two-independent-session deadlock proof.

**Remaining OPEN and release boundary:** Study Scope human confirmation is a separate source-of-truth transition and older AI drafts lack historic membership provenance; promotion after membership changes needs its own bounded audit. Execution Packet, Developer Context and other AI derivative surfaces remain pending. Cross-writer Plan→Capture→Inbox→Member lock ordering, production MySQL trigger migration and real-device behavior are not certified by this code. No schema/DDL, Aiven/Render, `production` promotion, OAuth/MCP, payments or live deployment changes.


## 41. G3-R5 — Execution Packet Native AI authorization epoch continuity (2026-10-13)

**Observed gap after #547:** The session-only Execution Packet already compares deterministic Plan/Task/Dependency/GitHub Context at provider return, again just before session publication, on later single-Packet read and per-target Distribution bundle refresh. The `ExecutionPacketCommitFence` rechecked present-day Editor and `AutomaticAiExecution` entitlement under Plan→Task row locks. However, its `context_fingerprint` omitted the original Plan owner/member authorization generation; Owner A→B→A, Editor→Viewer→Editor, same-role leave/rejoin and collaboration OFF→ON could produce the same fingerprint after revocation. A stale Native model output could then be presented to users, or reused as a basis for a GitHub file-change preview, despite an intervening denial. This is a source-code risk, **not evidence of production exploit**.

**Bounded implementation:** At each Execution context snapshot, capture `CompanionMembershipCommitGuard::capture` for the authenticated actor and Plan and include the full Owner or member proof **only as an internal input to the SHA-256 `context_fingerprint`**. No member ID, role history or authorization epoch is added to the provider-visible Context, prompt, Native AI metadata or session fields. For unauthenticated Guest/external Context, preserve the existing path by using a constant `guest` proof; its other ownership rules remain unchanged. At the post-provider final check, keep Plan→Task locks and replace the present-Editor query with `CompanionMembershipCommitGuard::canAccess(..., requiresEdit: true)`, which locks the member row until the source fingerprint and entitlement are revalidated. Existing later `nativePacketCurrent` checks now invalidate the session even after a role is restored because the epoch/fixed member identity has advanced.

**Session and multi-target safety:** Keep the contract: Packet is a session projection, not a new durable domain entity or an autonomous mutation. Revoked native Packet is redacted at next single-Packet read; also clear an already-prepared GitHub file-change preview derived from that Packet. The multi-target Distribution refresh independently compares every target's epoch-bound fingerprint and discards only stale native target outputs. Existing source/entitlement and human-confirmation gates remain in force. Human-authored/external-AI packets continue through their original explicit import/confirmation flow; all Execution Context fingerprints now reflect acting identity revocation, so an external prompt produced under a different authorization generation also requires fresh preparation.

**Acceptance:** Preserve current execution/dependency/ownership/entitlement/GitHub source tests, then add uninterrupted shared Editor Native success, Owner A→B→A, Editor→Viewer→Editor, same-role rejoin, collaboration OFF→ON during provider call, post-session role ABA redaction and multi-target late ABA. Validate the dedicated Execution and GitHub regression workflow and a disposable PostgreSQL 16 G3 suite at the exact PR HEAD, together with Migration Recovery and Workspace checks. Provider callback ABA is not exhaustive independent-connection concurrency proof.

**Remaining OPEN:** Existing external packet and historical GitHub preview/approval paths still need separate human-action authorization and source lineage audits, along with Developer Context/Execution Request handoff, queued approvals and composite lock ordering. Runtime epoch guarantees depend on the database authorization-epoch triggers; passing isolated PostgreSQL does not prove deployment on live Aiven MySQL. No schema migration, production branch, Render/Aiven deployment, billing, MCP/OAuth or device E2E is performed in this slice.


## 42. G3-R5 — Development Implementation Brief → Execution Request source and authorization continuity (2026-10-13)

**Gap after #548:** V57.8 intentionally regenerates the confirmed Implementation Brief server-side and creates an external Coding Agent Execution Request without calling an agent or writing to GitHub. However, `ExecutionRequestHandoffService::sourceIsCurrent` treated every non-Companion request—including `development_implementation_brief`—as permanently current. A user-approved Brief sourced from Development Task/GitHub Evidence could remain in the session with a copyable execution prompt after the Task or repository connection changed or Owner/Editor membership went A→B→A / Editor→Viewer→Editor. On later Execution/Distribution/GitHub preview reads, an old human confirmation was incorrectly reused as if it were an independent new instruction. This is a code-level stale derivative risk, not evidence of production exposure.

**Bounded implementation:** On confirmed Development Brief handoff, create a short, per-actor source proof using `ExecutionOrchestrationContextService::freshSnapshot` and its existing epoch-aware SHA-256 `context_fingerprint`. Store **only this fingerprint** in the session-scoped `execution_request.source.context_fingerprint`, next to the existing bounded `brief_hash`; no owner/member identifiers or complete GitHub private evidence added. `sourceIsCurrent` now treats `development_implementation_brief` separately: require exact Plan/Task binding, canonical brief instruction SHA-256 and a present actor-bound Request; recompute a fresh Context from the live database; reject missing legacy proof, a changed source or unavailable authorization. Fail closed if refreshing Context fails. Re-check immediately before the Coding Agent handoff publishes a copy-ready prompt. Route the original Request through single Execution show/prepare, multi-target Distribution, and GitHub file-change prepare/candidate checks; historical Companion and user-authored Inbox contracts stay unchanged.

**Human action and revocation:** A revoked Developer Brief clears its confirmed Execution Request, handoff prompt, Packet and `development_agent_handoff` prepared marker at next read; an existing derived GitHub file-change preview is also cleared through the existing handoff invalidation branch. It does **not** revoke a completed GitHub action retroactively, delete user Evidence, or independently issue an external coding-agent command. The External AI path remains Free and requires a fresh human confirmation after Context changes.

**Acceptance:** Add integration tests that preserve valid confirmed Developer handoff, reject Task edits, GitHub connection/source epoch rotation, Owner A→B→A, Editor→Viewer→Editor and same-role remove/rejoin; fail closed on a legacy unsigned Development source; and refuse multi-target Distribution reuse. Run the existing G3 Legacy Companion / Execution Request regression (including V47.1/V57.8) and isolated PostgreSQL 16 membership race suite at the exact PR HEAD plus Migration Recovery/Workspace. The tests simulate revoked-then-restored epochs and do not independently prove every DB transaction ordering or deployment.

**G3 remains OPEN:** Full Developer AI/context generation, external file-change confirmation and GitHub write, historical review Candidate approval, and non-GitHub human-authored Context need their own audit. The live Aiven database's epoch trigger migration, plan/task/session lock interleavings, Auth0/OAuth/MCP status, production and iOS release gates are not validated here. No migration/DDL, new AI call, automatic external agent invocation, pricing/entitlements, Render/Aiven/prod deployment or device operation is performed.


## 43. G3-R5 — Study Scope human-confirmation provenance fence (2026-10-13)

**Gap:** The #547 AI post-provider fence seals Source bytes, Inbox context and Plan/Editor epoch through `review` draft persistence. However, `StudyScopeCaptureController::confirm` formerly loaded `draft_data` before its write transaction and trusted current route authorization when converting the AI excerpt to authoritative `StudyScopeItem`; Owner A→B→A, Editor→Viewer→Editor, a same-role rejoin, edited Source bytes, or a concurrent reanalysis could leave a stale AI review eligible for human promotion. The historical `draft_data` lacked origin proof, so past generations cannot be retroactively certified. This is a code-level risk, not evidence of production abuse.

**Scoped contract:** At successful AI draft commit, retain only the existing already-validated SHA-256 `StudyScopeCaptureCommitFence::prepare` signature inside `draft_data.source_signature`. This bound digest reflects original Plan study context, owner/member authorization epoch, source metadata and original private file SHA; no raw membership identity, credentials, or image bytes are added to the draft, prompts or logs. At confirmation, enter a single DB transaction, lock Plan→Capture→Inbox→Member using a new `assertCanConfirm` hook, validate actor's current Edit access and matching original digest for any AI review, then read the **locked live** AI draft and existing items, validate edits, atomically write authoritative `StudyScopeItem` records and mark Capture+Inbox confirmed/processed. Legacy unsigned AI review fails closed and can be regenerated. A `draft_index` cannot be confirmed while its Capture status is `captured`/failed or during reanalysis. Changes to Source bytes, metadata, owner/member epoch or study domain reject old AI proof.

**Manual / UX contract:** A Capture with a failed/unavailable AI analysis can still be manually filled and confirmed without requiring an old AI digest. Existing confirmed items can still be edited with human authorization. This is not an auto-promotion or a payment entitlement change. A failed review leaves all authoritative scope items and the private Inbox source unchanged; the user can reanalyze or manually confirm by entering fresh scope data in a retryable/manual state.

**Verification:** Extended `StudyScopeAiCommitFenceG3Test` validates signed AI happy path, human corrections, Owner A→B→A, Editor role ABA, same-role rejoin, changed file bytes, unsigned historical review denial, manual confirmation after AI failure and stale `draft_index` during reanalysis. Existing V53.4 study scope and V53.5 Intelligence tests remain required, alongside isolated PostgreSQL 16 G3 racing/epoch suite, Migration Recovery and Workspace checks at exact PR HEAD. Provider callbacks and tests do not exhaustively prove independently concurrent source-file writer interleavings.

**Remaining OPEN and deployment boundary:** Recall candidate approval still lacks durable original authorization/source provenance for historical candidates and needs a separate product-safe migration and legacy-review decision. Other G3 derivative approval surfaces, full lock graph, real Aiven/MySQL database trigger state and live iPhone/Web acceptance are not validated here. No DB schema/DDL or bulk legacy data rewrite; no Render/Aiven/production branch promotion, MCP/OAuth, billing or release mutation.


## 44. G3-R5 — Recall Candidate human review source/authorization provenance (2026-10-13)

**Gap after #551:** Single/batch Recall candidate extraction held Plan→Task→member→Source locks and verified original actor epochs and private source bytes before writing pending candidates, but the pending `study_recall_candidates` rows retained no immutable evidence of **which source content or authorization generation produced them**. `StudyRecallCandidateController::reviewBatch` loaded pending candidates before its mutation transaction and validated only present route access. Thus stale source, Owner A→B→A, Editor→Viewer→Editor, Editor remove/rejoin, Source move, and concurrent candidate changes could be promoted as authoritative Deck items despite an old AI result. Historical candidates cannot be retroactively proven safe by capturing today's epoch.

**Minimal schema extension:** Add one nullable JSON `study_recall_candidates.review_provenance` column; no destructive backfill. On single and batch post-provider success, after the Source is updated `ready` inside the **same** transaction, write a versioned proof to each **newly created candidate** only: SHA-256 of locked `StudyRecallExtractionCommitFence::snapshot(readySource)` (including source IDs/text/private file SHA/status), original author User ID, and SHA-256 of `CompanionMembershipCommitGuard` original Owner/Editor authorization identity. The original proof comes from pre-AI admission and cannot be recaptured after a membership ABA; do not store member row ID, Plan owner epoch, raw private bytes or prompt in the provenance JSON. Never sign a duplicate or legacy `firstOrCreate` row opportunistically.

**Human approval boundary:** New `StudyRecallCandidateReviewFence` validates a locked Plan, Task and current reviewer Editor, plus the original generating actor's current Editor identity, before Source and Candidate row locks. It checks immutable original generation proof against re-captured live actor membership epochs, current Source status/bytes/text and the locked Candidate/Source/Task bindings. Promotion, duplicate Deck item reuse, and reviewed status are committed in one transaction. An invalid candidate cancels the **entire** mixed selected batch. A plain candidate rejection never creates Deck cards and remains possible under current edit authorization.

**Legacy policy and visible consent:** NULL proof means genuinely historical/unverifiable. Preserve already-promoted Deck items, source history and pending candidates. An unsigned candidate does **not** auto-qualify: the reviewer must deliberately check a visible acknowledgment that they personally rechecked the historical content against the available, currently linked Source. The guard still demands a live `ready` Source, current plan/task binding and present Editor role. This is an explicitly user-authored adoption, NOT retroactive certification of the old AI generation. Non-null malformed or revoked proofs never accept this checkbox. The manual `ADD CARDS` path remains independent.

**Acceptance and tests:** Keep V41.12 single extraction, V58.8 batch extraction, retry/Source/Outcome continuity; prove newly generated single and batch per-source signatures, signed owner happy path and shared Editor review, Owner/Editor ABA, same-role Editor rejoin, text/file changes, mixed valid/stale batch atomic rejection, legacy denial without acknowledgment and success with acknowledgment, and fake/tampered signature denial even with acknowledgment. Run dedicated G3 Recall feature workflow and disposable PostgreSQL 16 G3 race/epoch integration plus migration recovery and Workspace at exact PR HEAD. Tests do not exhaustively prove every independent-transaction source file mutation timing.

**Operational boundary:** The nullable JSON field requires a *future reviewed release migration* before any production code using it; no Aiven/MySQL DDL, Render deployment, `production` branch promotion, UI device acceptance, billing, OAuth/MCP or production operation occurs in this PR. Existing historical candidate provenance remains unknown, not asserted safe, even when user manually adopts a card. Cross-writer lock graph and all other G3 remaining approvals stay OPEN.


## 45. G3-R6 — Cross-surface Task/Source lock order and Action Draft GitHub root retention (2026-10-13)

**Inspected scope:** Study Recall AI extraction/review, Study Scope analysis/human confirm, Companion derived Evidence, GitHub TaskEvidence producer, Plan Action Draft owner confirmation, and the independent PostgreSQL 16 source/epoch fixtures. Common writers preferentially acquire Plan → Task → authorization/member → GitHub repository artifact → Evidence. `PlanActionDraftApprovalSourceGuard::assertCurrent` was an inversion: after Plan and Draft, it locked WorkLog and TaskEvidence first, *then* their linked Task rows, and checked the GitHub repository root **without retaining its lock** through the final Task creation. Two concurrent independent transactions could take Task/Evidence in opposing order and deadlock; a GitHub disconnect between an unlocked root check and owner Task creation could leave an unauthorized derivative action. This is a potential code race, not proof of production exploitation.

**Bounded fix:** Require caller transaction, keep Plan → Draft as the owner approval entry, and pre-read only the relevant WorkLog/TaskEvidence **IDs, linked Task IDs and GitHub root IDs**, without treating them as authorization. Acquire related Task rows first by ID, GitHub repository PlanArtifact roots next by ID, then WorkLogs and TaskEvidence in stable ID order. Verify every pre-read parent Task ID, Evidence source and root binding *after* Source locks, failing closed if a competing writer retargeted or deleted any row. Perform GitHub reuse/epoch eligibility and full WorkLog/TaskEvidence signature comparisons under the retained Task/Root/Source locks, then execute owner-approved Task creation before commit. Pure self-report sources add no dependent row locks. Existing owner/explicit human confirmation, proposal revision and steps checks remain unchanged. No global lock reorder of unrelated execution paths is attempted here.

**Existing GitHub Return regression clarified:** V46.7's historical fixture intentionally has a GitHub root without `source_epoch`. Return/Review/Merge snapshots still render and persist as factual Evidence, but `GitHubTaskEvidenceReuseGuard` correctly excludes these **unsigned** historical rows from fresh AI Execution Context. The old test expected the Packet-stale warning after such a return even though no eligible Context Evidence changed; correct this test assertion to require that the warning is **absent** for this particular unsigned fixture. This does not loosen the runtime, suppress alerts for verified signed Evidence, or authorize any GitHub write.

**Independent-session acceptance:** The new isolated PostgreSQL 16 `PlanActionDraftApprovalLockOrderPostgresG3Test` exercises owner-authorized GitHub evidence approval while an independent PDO transaction attempts Task edit, repository revocation and Evidence edit, all blocked until the approval transaction ends; an uncommitted competing repository revocation must first prevent a new approval from reading stale connected state (lock timeout), followed by HTTP 409 once revocation commits. Mixed WorkLog+TaskEvidence approvals must retain locks on both sources and parent Task. Existing V58.71–V58.76 Action Draft, GitHub provenance and G3 PG source regressions remain acceptance gates at exact PR HEAD.

**Residual limitations:** This is a targeted inversion/TOCTOU fix, not an exhaustive proof of every concurrent writer or an observed live deadlock. Standalone source-file byte writers (outside DB locks), lifecycle write ordering across other domains, every PostgreSQL/MySQL isolation mode, and historical GitHub grant/trigger migration on live Aiven remain G3 OPEN. PostgreSQL 16 success does not certify production Aiven. No migration/DDL, Render, Aiven, MCP/OAuth, pricing, `production` branch, external GitHub mutations or device operations.


## 46. G3-R6 — Study Scope physical-file input fidelity and deletion serialization (2026-10-13)

**Audit of file writers and lifecycle:** Study Scope uploads a per-Capture UUID file under private Inbox storage, reads it to prepare a signed private-source SHA-256, then separately calls `Storage::get` to assemble provider `input_image`/`input_file`. No SQL row lock can make object/local file bytes transactional. A file replacement between the signature read and provider-input read, later restored to the original bytes, can pass the original final digest even though the AI actually saw different bytes (A→B→A). A second risk was `StudyScopeCaptureController::destroy`: it authorized and loaded a route model before its deletion transaction, deleted Capture/Inbox without acquiring the common Plan→Capture→Inbox locks or rechecking current membership/status, then deleted a file using the pre-fetched Inbox path **even if it was not owned by the Capture**. This is a potential TOCTOU issue, not evidence of a live exploit.

**Bounded input fix:** `StudyScopeCaptureAnalysisService` reads the provider file input **once**, stores only its SHA-256 alongside its in-memory base64 input, and asks `StudyScopeCaptureCommitFence::assertInputMatches` to compare that exact byte hash with the originally signed source while locking Plan→Capture→Inbox→Member before submitting the AI request. The new check confirms both the live storage/source signature and the prepared provider input digest; a changed/missing file rejects with `native_ai_source_changed`. It does **not** persist raw bytes or expose file contents in telemetry. Existing post-provider commit and final human-confirm source revalidation remain mandatory.

**Bounded deletion fix:** Human discard now obtains Plan→Capture→Inbox locks in one transaction, re-checks present Owner/Editor authorization with `CompanionMembershipCommitGuard` after those locks, refuses a current `confirmed` capture, and deletes the Inbox row only for the owned Capture source. It records a path for physical deletion only for that owned original source, after the database deletion successfully commits. An ongoing provider call that read the earlier material cannot commit a derived draft after deletion. Retained ordinary Inbox sources are never physically unlinked by the Study Scope discard path.

**Verification:** Extend `StudyScopeAiCommitFenceG3Test` with actual provider byte A→B→A mismatch, intact-input success, human discard during provider callback, last-moment confirmed status and unowned Inbox path preservation. Isolated `StudyScopeSourceFileRacePostgresG3Test` uses genuinely separate PostgreSQL 16 connections to show that a locked Plan/Capture/Inbox blocks external file metadata edits/deletion until commit, that prior rival locks delay the discard, and that post-edit signatures fail closed. Dedicated Study Scope workflow plus G3 PG16 source/epoch, Migration Recovery, and Workspace must pass at exact reviewed HEAD.

**Limitations / G3 OPEN:** This protects the application's identified Study Scope mutation path and exact input bytes, **not every external writer on an uncoordinated storage backend**, any filesystem that shares paths between unrelated records, or a malicious external actor with unrestricted object-store delete access. The analogous Recall snapshot-vs-input check and Inbox-to-Recall copy path still merit a separate bounded slice. File system deletion cannot be rolled back with SQL; unexpected delete failure may leave an orphaned private file for later cleanup. No schema migration, production storage deletion, Render/Aiven/production branch work, billing, MCP/OAuth, or real-device verification is performed.


## 47. G3-R6 — Recall single/batch exact provider bytes and source index fence (2026-10-13)

**Code-level gap after #554:** The Recall single/batch extraction captured `StudyRecallExtractionCommitFence::snapshot` (including the original file SHA-256), then separately used `Storage::get` to generate provider input payloads. Post-provider `assertCanCommit` rechecked only the original stored Source and current bytes, not the **specific immutable bytes actually submitted** to the provider. A private file A→B→A between the first snapshot, payload materialization and final write could authenticate AI-generated B as original A. For batched pages, a single intermediate page swap could corrupt `source_index` evidence while all final signatures matched. This is a potential race; no production exploitation is established.

**Bounded fix:** `StudyRecallCandidateExtractionService` and `StudyRecallBatchCandidateExtractionService` derive a SHA-256 hash from the **same in-memory bytes used to form their actual base64 input part**. Each batch hash is indexed by immutable `StudyRecallSource.id`; presentation `SOURCE_INDEX` and source order are not changed. New `StudyRecallExtractionCommitFence::assertInputMatches` runs immediately before the provider call: enter DB transaction, lock Plan→Task→member→all Sources by ID, re-evaluate original Owner/Editor epoch and entitlement against the exact pre-AI admission proof, re-hash live Source bytes, and compare each immutable original Source signature against an identical signature with that item's **actual provider-input bytes hash**. Demand an exact set of file Source IDs, rejecting missing, extra, swapped, invalid or changed hashes for the whole batch. Text input has no file hash and stays supported. This preflight does not replace the existing after-provider atomic candidate write guard, and retains per-Source candidate provenance plus subsequent human review protections.

**Failure semantics:** Fail before AI call on missing or changed private inputs with `native_ai_source_changed`, preserving normal retryable failed-Source handling; never produce a NativeAiRun or partial candidate set from a rejected input. The approval-side signed provenance check still inspects the committed ready Source and original authorization epoch. No raw bytes/signatures of authorization tokens are persisted in new records or telemetry.

**Verification:** `RecallAiCommitFenceV05Test` covers file A→B→A mismatch on single and one page within batch, swapped/missing `source_id` hashes, valid unchanged batch and text source, and missing file before provider with zero AI calls. New isolated `RecallInputFileRacePostgresG3Test` uses independent PG16 sessions to show pre-provider locks serialize a concurrent Source retarget/delete and refuse an earlier Source hash after the competing metadata edit commits. Run dedicated Recall workflow, all prior Recall single/batch/retry and authorization tests, isolated G3 PG16 suite, Migration Recovery and Workspace CI at the exact PR HEAD.

**Residual / G3 OPEN:** This verifies the bytes handed to the AI and source identities through local code paths; it is **not** a global filesystem mutex for uncoordinated external writers, malicious storage keys, or concurrent raw filesystem deletion that never uses DB metadata. Snapshot collision resistance assumes SHA-256. The Inbox→Recall file copy and cross-surface private storage lifecycle remain separate targeted audits. No migration/DDL, Render/Aiven, production branch, external GitHub actions, MCP/OAuth, payments or device work.


## 48. G3-R6 — Inbox→Recall version-sealed private file handoff and delayed routing acknowledgment (2026-10-14)

**Gap discovered:** `InboxRoutingService::toRecall` checked a request-bound Inbox file exists and used `Storage::copy` after initial HTTP authorization, without locked live Inbox, Plan, Task or original shared Editor epoch. It directly created `StudyRecallSource` with the copied path and began AI, then `route()` wrote `processed`/routing metadata to the *prefetched* Inbox after the provider response. A competing delete/retarget, file replacement A→B→A, or Owner/Editor revocation during the multi-step workflow could sign a version of the original the human had not reviewed, copy a mismatched file, or overwrite a newer Inbox status after the original authority had changed. Missing/failed storage copy return values were not checked. The Scope and Recall exact-provider fences did not cover the **earlier Inbox→Recall physical copy** boundary. This is a code-level race risk, not evidence of live misuse.

**Bounded copy contract:** New `InboxRecallMaterialTransferGuard::copy` locks Plan→Task→Inbox→Member in a short transaction, then checks the **live** Plan/Task relation, selected study category, original Inbox actor identity and current Editor right. Capture its exact Owner/member authorization epoch without retaining private role state in public metadata. Hash the original Inbox metadata and disk bytes as a local signature; read source bytes to memory once for the actual copy, compare those bytes to the original signature, write them under a distinct UUID private Recall path, validate the copied output byte hash, then verify the original Inbox source has not changed before inserting the pending Recall Source. Fail closed on missing/altered source and copy failure. If the DB transaction fails, delete the uncommitted new UUID file on a best-effort basis; **never delete the original Inbox file**. Text-only Inbox continues to generate a Recall text Source without a physical copy.

**Provider + finish:** `StudyRecallCandidateExtractionService` accepts an **optional** origin authorization proof from the handoff, rejecting a changed Owner/member identity **before** AI admission under a new epoch. The original Recall post-provider Source and membership commit checks remain in force. After successful extraction, `confirmProcessed` locks Plan→Task→Inbox→Member→new Source, demands exact original authorization and Inbox signature, confirms the new Source is still ready and belongs to this Plan/Task/actor, then (and only then) updates the original Inbox to `processed` and records `routing_confirmed`. `InboxRoutingService::route` bypasses its previous unconditional, stale-model Inbox write for this destination only. A concurrently edited Inbox stays intact and unprocessed; the immutable copied Source and its already-created review candidates remain available for explicit human review, rather than pretending the original routing succeeded. Revoked provider execution surfaces a user-visible retryable validation message without a 500. No DB locks are held over the network provider call.

**Acceptance:** `InboxRecallMaterialTransferG3Test`: original PDF/image source bytes preserved, byte-exact immutable copy, original/source distinct paths, signed candidate happy path, text compatibility, absent file refusal before provider, A→B→A copied-byte signature mismatch, mid-provider Inbox edit not overwritten at final acknowledgment, Owner A→B→A and Editor→Viewer→Editor output refusal, other-person Inbox denial, and successful shared Editor route. `InboxRecallTransferPostgresG3Test`: independent PG16 writer cannot edit/delete locked Inbox or retarget locked Task during copy; competing Inbox update serializes before copy; an original changed after transfer cannot be marked processed from the earlier snapshot. Existing Inbox routing, Recall source retry, source-to-provider commit, PG16 epoch, Migration Recovery and Workspace checks must pass at the exact PR HEAD.

**Operational/residual boundary:** This is a versioned **copy**, not a live link: changing/deleting the original Inbox after a successful handoff does not automatically delete previously copied Recall materials or Deck cards, and a post-provider original Inbox edit prevents only the stale `processed` acknowledgment (it cannot roll back an already-completed external provider call). Uncoordinated external storage writers outside the application's DB coordination, storage write eventual consistency, and unavoidable DB/file non-atomic orphan cleanup are still G3 OPEN. No schema migration/DDL, Aiven, Render, production branch/deploy, MCP/OAuth, billing or physical-device work.


## 49. G3-R6 — Plan/account file cleanup and surviving-reference fence (2026-10-14)

**Concrete audit findings after #563:** Deleting a Plan cascades `study_recall_sources` and `career_captures` rows, but `PlanController::destroy` previously physically deleted **only Career screenshot files**, leaving private Recall images/PDFs without a backing database row. Conversely, `inbox_items.plan_id` is **ON DELETE SET NULL**, so its stored Inbox file remains the personal owner's accessible content and must never be blindly deleted with the Plan. The account-deletion service collected candidate paths **outside** the deletion transaction, then deleted all collected paths without checking whether a still-live Inbox/Recall/Career record used the same key. It swallowed storage failures without a safe operator signal. If an outer transaction rolled back, file unlink must not occur prematurely. These are source-code risks, not confirmed live incidents.

**Bounded fix:** Introduce `PrivateFileLifecycleService` with an explicit file-backup/reference graph across `inbox_items.storage_path`, `study_recall_sources.storage_path`, and `career_captures.screenshot_path`. `PlanController::destroy` locks the live Plan and reconfirms Owner authorization, captures only the cascading Recall and Career file keys while still in its Plan deletion transaction, and cleans them **after** commit. Unlike Recall/Career, surviving Inbox rows become Plan-null and retain their file. The physical deletion routine de-duplicates paths, queries all three **surviving** reference tables, skips still-referenced paths, and performs best-effort storage deletion only after the corresponding DB rows commit. It also defers cleanup through `DB::afterCommit` if enclosed within a larger transaction, so nested rollback discards the deletion. `AccountDeletionService` now acquires the live User/owned-Plan locks and collects all deleted-row file paths inside the same transaction, then uses the same surviving-reference check. Missing files are idempotent; storage failure is logged using a **reason code only**, not a private key, file path, content, user identity or raw bytes.

**Acceptance:** `PrivateFileLifecycleG3Test` proves Plan delete removes orphanable Recall/Career files while retaining Inbox-null-on-delete bytes and row, foreign Inbox reference blocks deletion of a vanished Plan's Recall key, account deletion retains shared keys referenced by another account but deletes exclusively-owned files, outer transaction rollback preserves both DB and filesystem, and a failing storage provider cannot resurrect deleted DB rows nor disclose a private path in failure logs. Run new actual-commit `PrivateFileLifecycleG3Test` on disposable PostgreSQL 16 with `DatabaseMigrations`, including a **real HTTP account deletion** with session invalidation and cross-user preservation. Existing V56.1 account deletion and V58.11 Resource→Recall use `RefreshDatabase` wrappers, so physical unlinks are deliberately deferred until an outer commit and those legacy fixtures now assert retained bytes before rollback. The dedicated workflow runs the committed PostgreSQL acceptance; isolated legacy-suite runs on the current GitHub runner did not complete and are **NOT claimed as green**, so separate fixture/harness recovery remains open. Isolated PG16 plus dedicated private-file lifecycle feature CI and full Workspace/Migration checks required at exact PR HEAD.

**G3 remains OPEN:** This is **best-effort, not durable deletion**. Process death after SQL commit but before storage deletion, a deletion failure without an operator retry queue, uncoordinated external object-store writes, or an independently created DB reference in a tight check→unlink gap can still leave orphaned bytes or unexpectedly stale references. Those cases need a separate durable reconciliation/outbox or scheduled storage inventory implementation and explicit retention/legal policy. The Career single-capture delete path still deletes bytes before its DB row and deserves a separate lock-order/commit slice. No schema/DDL, production storage, Render/Aiven, `production` branch, OAuth/MCP, billing or device verification is affected.


## 50. G3-R6 — Career Capture physical screenshot deletion after committed row removal (2026-10-14)

**Additional deletion route after the independently merged private-file lifecycle work:** The newer `main` already has `PrivateFileLifecycleService` and post-commit, live-reference-checked cleanup for Account and Plan removal (see the prior audit entry), so no competing second helper or duplicate Account/Plan edits are introduced. The remaining `CareerWorkspaceController::destroyCapture` instead called `Storage::delete($capture->screenshot_path)` **before** deleting the CareerCapture row, under no live Plan/Capture locks. If the DB change failed or an outer transaction rolled back, a still-existing CareerCapture could refer to an already-deleted screenshot. A separate Inbox or Recall row may also still reference the identical path.

**Bounded correction:** Owner/Editor route authorization remains unchanged. Inside a short transaction, lock Plan→CareerCapture and re-check the capture's current Plan binding; delete the row and return its path. Pass that path to the existing `PrivateFileLifecycleService::deleteAfterCommit`, which delays unlink until the outermost commit, verifies surviving Inbox/Recall/Career references, and tolerates storage errors without undoing committed database changes. No new cleanup helper, schema migration, or physical file mutation before SQL commit.

**Acceptance:** Extend isolated PG16 `PrivateFileLifecycleG3Test` for Career Capture deletion within an enclosing commit (file remains until commit then removed), enclosing rollback (row and bytes survive), and a surviving independent Inbox file reference (bytes retained). Existing Plan/Account cleanup regression and migration/workspace CI remain gates at the exact PR HEAD.

**Residual:** Durable orphan-retry queues and storage lifecycle changes by external writers remain unimplemented and G3 stays OPEN. No production database, Render, `production` branch, OAuth/MCP, billing, iOS device, or deployed storage is changed.


## 51. G3-R7 — Durable encrypted private-file deletion outbox and verified bounded retry (2026-10-14)

**Post-commit gap:** Account/Plan/Career deletions acquired appropriate live DB locks and used `PrivateFileLifecycleService::deleteAfterCommit` to avoid physical unlinks on rollback. However, the file deletion intent itself was not durable: it was scheduled only from data returned **after** SQL deletion committed. Process death in the committed-row→best-effort-storage-cleanup gap, Storage::delete false/throw, or an unhandled worker failure could leave private bytes without a retriable owner path. Blind storage inventory deletion would risk erasing someone else's data. Study Scope private file cleanup also did not use the shared lifecycle service.

**New additive database contract:** `private_file_cleanup_entries` stores the default disk identifier, **encrypted storage path**, SHA-256 of bytes at deletion-intent registration, `pending` or `quarantined` status, attempt count, reason code and retry-after. No user content, file bytes, or path is emitted to CLI output or logs. The SQL migration is additive only and **not applied to production/Aiven** in this change. If migration is absent, new deletion code must not be promoted to live because the DB transaction would fail closed; production needs a separately reviewed Aiven schema/operator plan and release acceptance. Historical orphaned files are NOT reconstructed or backfilled from deleted records.

**Transactional creation:** Plan, Account, Career Capture and Study Scope deletion paths now register the cleanup entry **inside the same DB transaction that deletes the owning record**, so a DB rollback removes both the deletion and intent, while a successfully committed delete retains the outbox even if the PHP worker exits before cleanup. Laravel `DB::afterCommit` performs the immediate fast-path attempt only after the **outermost** commit. For Account and Plan, the source path collection remains under live owner/Plan locks. Existing Plan Inbox rows that survive Plan deletion remain referenced and retain private bytes.

**Retry and safety rules:** `PrivateFileLifecycleService::retryDue` selects at most 200 due `pending` rows per call, oldest first; each row is locked with `FOR UPDATE` to prevent duplicate concurrent workers. The worker decrypts the stored path, validates it belongs to a known private path prefix without traversal, verifies no current Inbox, Recall or Career row still uses the exact path, then checks current file bytes against the **at-enqueue SHA-256** before unlink. A still-referenced file is retained and its obsolete cleanup entry consumed; a file that no longer exists is idempotently completed; mismatched/replaced bytes or invalid provenance are quarantined for human review rather than auto-deleted. Storage unavailable or false delete causes bounded exponential backoff (1–1440 minutes), retaining the entry for an explicit later retry. Entries with unreadable initial bytes are quarantined, never auto-deleted from a guessed signature. Successful cleanup removes the outbox entry to minimize retention of encrypted personal path data. Logs contain **fixed reason codes only**.

**Operator controls:** `php artisan canovia:retry-private-file-cleanup --dry-run` returns aggregate pending/quarantined counts; `php artisan canovia:retry-private-file-cleanup --limit=50` executes one bounded pass with aggregate results. No schedule, queue daemon, automated Render cron, production path, or remote storage action is activated by the development PR. Operators must approve migration, storage isolation, recovery procedure, retention policy and scheduling before production use. Quarantined entries are never automatically retried or forcibly deleted.

**Acceptance:** Disposable PostgreSQL 16 `PrivateFileCleanupOutboxG3Test` covers process-interruption simulation (committed pending entry), CLI dry run, successful bounded retry, another actor's still-live reference, same-path A→B byte replacement quarantined rather than unlinked, transient storage delete false followed by backoff and retry, idempotent completion, outer rollback no outbox/no unlink, unreadable original safe quarantine, and unsafe non-private paths rejected. Existing Plan/Account/Career lifecycle PG16 and Workspace/Migration/G3 source race regressions remain CI gates. No direct Aiven/Render test is asserted.

**Remaining G3 OPEN:** SQL reference lookup→object delete is not a globally atomic cross-service operation. An uncoordinated external process could create a new live DB reference or overwrite a storage key in that narrow window; hash comparison cannot prove ownership if a replacement happens to carry the same bytes. Legacy pre-migration orphans are not discoverable without a separate authorized inventory process. Crashes **during** physical delete but **before** the outbox-row deletion are idempotently recoverable. Private-path encryption requires stable application key management. Quarantine triage, account erasure/retention policy, object-store versioning and monitoring must be approved before live release. No `production` branch, production DB migration, Render deployment/configuration, OAuth/MCP, billing or device work is performed.


## 52. G3-R7 — Read-only outbox health monitoring, quarantine triage and operator runbook (2026-10-14)

**Observed gap after #567:** The durable private file cleanup outbox had two useful CLI modes — a count-only `--dry-run` and a bounded manual retry — but no way to distinguish ordinary recently pending work from a stranded pending job, a malformed retry date, repeatedly failing storage, or quarantined entries requiring investigation. A failed DB connection or missing migration could also be misconstrued by a careless external monitor as zero pending records. No operator-owned orphan inventory or quarantine handling policy had been recorded.

**Implementation:** Add `PrivateFileCleanupHealthService::report` as a **read-only, aggregate-only** DB query over `private_file_cleanup_entries`. The public report schema contains `schema` (ready/unavailable), `health` (healthy/attention/unavailable), pending count, currently due count, count older than 24 hours, count with at least five retries, count missing the next retry date, quarantined count, unrecognized status count and a **fixed allowlist** of quarantine reasons (`source_unavailable`, `file_bytes_changed`, `invalid_encrypted_path`, `unsafe_cleanup_entry` and `other`). It does not select encrypted paths, storage paths, SHA hashes, tenant IDs or arbitrary `last_reason` values; the latter are only counted via allowlisted equality queries and the remainder is aggregated as `other`. Schema absence, missing required columns or query errors fail closed with `unavailable`, never `healthy`.

**CLI and gates:** New `php artisan canovia:private-file-cleanup-health --json --strict` prints only the fixed aggregate result, exits 0 for healthy, 2 for attention, and 1 for DB/schema unavailable. Without `--strict`, attention returns 0 for manual inspection (DB/schema unavailable still returns 1). No background runner or scheduler is installed; the existing manual retry command remains an explicit separate destructive action. A pending job just reaching its due time is *not* inherently an incident; stuck >24h, high retry count, null schedule, quarantine or invalid status is attention. A dedicated PostgreSQL 16 test suite checks routine pending, stale/high-retry/unscheduled/quarantine aggregations, unknown reason masking, strict/JSON exit codes, schema/db failure behavior and absence of path/ciphertext/identifier leakage. Both health and existing lifecycle tests run in the G3 private-file workflow.

**Operator controls:** `docs/development/PRIVATE_FILE_CLEANUP_G3_OPERATOR_RUNBOOK_2026_10_14.md` documents the interpretation/response matrix, production migration + stable APP_KEY + disk configuration gates, safe quarantine diagnosis, no-force-delete policy, and the fact that legacy orphan inventory is **not implemented**. No listing of production storage, live retry, path export, automatic orphan deletion, or monitoring service activation is performed. G3 remains **OPEN** because a live reference created by an uncoordinated writer between SQL check and storage unlink is not serialized, as is a same-byte storage key collision, and retention/legal/operator approval is still outstanding.

**Deployment:** Development-only code and disposable CI PostgreSQL 16. No new migration beyond #567, no Aiven schema execution, no production branch, Render deployment/config, OAuth/MCP, billing or device acceptance.

## 53. G3-R8 — Fail-closed destructive preflight for storage disk drift and partial schema (2026-10-14)

**Post-#569 review:** The durable outbox verifies path prefix, SHA-256 and current Inbox/Recall/Career references before physical unlink; the operator health CLI and quarantine runbook are present. Two additional fail-open conditions were identified in the worker: (1) it trusted the outbox `disk` string even when it no longer matched the default disk selected at registration, potentially redirecting a cleanup to a different storage namespace after configuration drift; (2) `isReferenced()` silently skipped a missing live-reference table/column via `tableHasPath()`, incorrectly treating incomplete schema as proof of no owner. The health command also checked only its reporting columns and could label a partial outbox schema healthy.

**Bounded code correction:** Before any live-reference read or Storage operation, compare the recorded disk with the current default disk and quarantine a mismatch with the allowlisted `storage_disk_mismatch` reason (do not retarget/delete). Fail closed when any Inbox/Recall/Career path table or column cannot be checked, or a reference query throws; use bounded exponential backoff with reason `reference_check_unavailable`. Continue to preserve existing still-referenced, changed-byte, absent-byte, hash-verification and transaction semantics. The health aggregator now requires essential outbox columns (including encrypted path, disk and expected digest) before returning `ready`. No arbitrary disk name, path, user identity or exception text is emitted to CLI or logs.

**Acceptance:** Extend disposable PostgreSQL 16 `PrivateFileCleanupOutboxG3Test` for a recorded disk mismatch, missing reference table and missing path column. Extend `PrivateFileCleanupHealthG3Test` for partial schema reporting and the fixed new quarantine reason. Existing dedicated G3 private-file workflow and release/Migration/Workspace regressions remain gates for an exact PR HEAD. The operator runbook §7 records the controlled response.

**Residual and release boundary:** This is a non-destructive safety tightening, **not** a proof that stored file paths can be deleted under uncoordinated writers. In particular, SQL reference check→Storage unlink remains non-atomic, same-byte key collisions remain uncertain, and prior orphan inventory still requires separate operator consent. Disk rotation of queued items is an explicit operator review task, not an automatic conversion. There is no new migration, scheduler, object-store listing, Aiven/Render/production branch change, live retry, deploy, billing change or device verification. G3 remains OPEN.

## 54. G3-R9 — Second surviving-reference fence after SHA-256 read (2026-10-14)

**Observed gap after #570:** The outbox locked its own row and checked surviving Inbox/Recall/Career database references **before** reading private file bytes to verify the enqueued SHA-256. A different writer might create a new reference while the checksum read is in progress, after the original DB reference query has returned no matches. Without another lookup, the worker could physically unlink a now-referenced file despite a correct file hash.

**Bounded implementation:** After the byte hash matches, query the complete live-reference graph **once more, immediately before unlink**, using the existing strict `isReferenced()` schema contract. If now referenced, retain the file and consume the obsolete outbox entry. If schema/read access disappears during the checksum window, preserve the file and schedule `reference_check_unavailable` retry, not `storage_unavailable` or blind deletion. Existing invalid path, disk drift, hash mismatch, quarantine, missing file, idempotence, post-commit enqueue and log-redaction contracts stay unchanged. No new DB schema, storage enumeration, cron or conditional object-store API.

**Acceptance:** Extend `PrivateFileCleanupOutboxG3Test` with checksum-read hooks: an independently committed PostgreSQL 16 Inbox reference between the two checks must prevent `Storage::delete`; disappearance of the reference schema at that point must retain the file with bounded retry. Existing PostgreSQL16 private-file lifecycle, PostgreSQL source race, Workspace and Migration Recovery CI remain mandatory at exact reviewed head. Operator Runbook §8 records the non-atomicity.

**Residual:** This narrows, but **does not eliminate**, the final database-check→object-unlink race on PostgreSQL READ COMMITTED. On MySQL's default REPEATABLE READ, the second non-locking SELECT may see the same transactional snapshot and miss even a committed cross-session reference; this stage makes no MySQL cross-session guarantee. Neither an outbox row lock nor two SELECTs prevent an independent writer from inserting a reference after the last check. Same-byte path reuse and provider-version races are likewise unresolved without coordinated path ownership and/or conditional object version deletion. A future cross-writer protocol must cover Inbox, Recall, Career, imports and external storage actors, using independently verified multi-session tests and a separately approved storage/provider contract. Production `private_file_cleanup_entries` migration still awaits operator/Aiven approval. G3 remains OPEN. No change to `production`, Render/Aiven services, live data, scheduled deletes, deployment or device verification.

## 55. G3-R10 — MySQL stale-snapshot reference safety and writer-coordination boundary (2026-10-14)

**Audit:** After #572, cleanup checks live Inbox/Study Recall/Career sources before checksum read and again before object unlink. On PostgreSQL READ COMMITTED, separate statements can observe a newly committed reference. On Aiven's MySQL-family `REPEATABLE READ`, the previous `exists()` plain SELECT could reuse an earlier snapshot even though another connection had committed a live reference, leading to unsafe unlink.

**Bounded code correction:** In `PrivateFileLifecycleService::isReferenced`, switch MySQL and MariaDB live-source checks to a real `SELECT id ... FOR UPDATE` through `lockForUpdate()->first(['id'])` (not `exists()`, whose query compiler need not retain the locking clause). This is an InnoDB current read that sees already-committed rows despite an earlier consistent snapshot. Preserve PostgreSQL's existing read behavior, all reference-schema fail-closed checks, SHA-256 matching, disk drift quarantine, outbox retry semantics, two-lookups, and path/log redaction. No DDL or queue changes.

**Acceptance:** `PrivateFileCleanupMysqlCurrentReadG3Test` opens a consistent-read snapshot on disposable MySQL 8.0/8.4, commits a new Inbox reference from an independent PDO session, verifies ordinary snapshot `EXISTS` is stale, and verifies the current-reading service detects that reference. A separate path with no owner remains unreferenced. The dedicated matrix and the established PostgreSQL16 lifecycle/source-race plus migration/workspace checks must pass at the exact PR HEAD.

**Limitations:** A missing path index means MySQL `FOR UPDATE` may acquire wide gap/next-key locks, with new contention/deadlock risk. The test does *not* exercise live Aiven or prove safety for writes that begin after the last lookup, uncoordinated external writers, same-byte path reuse or object-store generation changes. All native writers must share a formally designed path identity/lock protocol before claiming race closure, and safe storage backend semantics require additional approval. The full contract is not implemented in this stage; G3 remains OPEN. No new schema or production migration was applied; #567's `private_file_cleanup_entries` production release gate remains. No `production` branch, Render, Aiven, live storage, cron, deployment, billing or physical-device operation.

## 56. G3-R11 — Keyed durable path-fence foundation and opt-in first writer (2026-10-14)

**Follow-on to #574:** MySQL current reads eliminated the stale-repeatable-read reference query gap, but neither database query can atomically exclude a separate uncoordinated storage/DB writer from the check→unlink interval. The next design unit must be shared between storage writer and cleanup worker, not simply a third SELECT.

**Implemented bounded pilot:** Add a non-destructive, uniquely indexed
`private_file_path_fences` row store containing only HMAC-SHA256 keyed
disk/path fingerprints with a domain separator, never raw private paths.
`PrivateFilePathFenceService` validates path/disk and presence of APP_KEY,
requires an existing DB transaction, insert-or-ignores the persistent row,
then holds `SELECT ... FOR UPDATE` for that digest until the enclosing
transaction completes. `CANOVIA_PRIVATE_FILE_PATH_FENCE_ENABLED` defaults to
**false**; when true, the existing worker locks this row after its Outbox
lock and before references/storage I/O, and the Inbox→Recall transaction
locks its generated destination path before writing bytes and the DB source
record. Missing schema/key fails closed: worker records
`path_fence_unavailable` with non-destructive retry. Disabled mode keeps
the legacy cleanup/copy behavior unchanged.

**Acceptance:** A disposable PG16 suite must prove digest/path secrecy,
validation, idempotent row reuse, rollback, transaction requirement,
independent-session lock exclusion, opt-in physical cleanup and missing-schema
non-deletion, plus Recall copy integration. A disposable MySQL 8.0/8.4
matrix must prove independent-session lock blocking and row reuse.
Dedicated G3 PostgreSQL Source Race, P0 MySQL Migration, Migration Recovery
and Workspace Resume continue gating exact PR head.

**Intentionally NOT covered:** `InboxController::store`,
`StudyScopeCaptureController::store`, `StudyRecallCandidateController` and
some Career path references, direct SQL, object-store writers or earlier
untracked orphans. No full safety claim is made until every storage writer
uses the same lock and its storage lifecycle is managed. Legacy MySQL
path-column scans may hold broad next-key locks. The new fence rows are
retained without automatic pruning; APP_KEY rotation requires a coordinated
plan; storage read/hash/delete is still not version-conditional.
This is a new **additive migration** and *requires separate production/Aiven
operator review in addition to #567's Outbox migration*. No production branch,
Render, Aiven schema, live worker, cron, storage listing, billing, deployment
or device mutation was performed. G3 stays OPEN.

**Canonical scope/next-stage checklist:**
`docs/development/G3_PRIVATE_FILE_PATH_FENCE_IMPLEMENTATION_SPEC_2026_10_14.md`.

## 57. G3-R12 — Transaction-scoped native upload participation (2026-10-14)

**Gap:** #576 introduced the persistent HMAC path-lock row and pilot
Recall copy/Outbox participation, but ordinary Inbox, Study Scope,
Recall single/Resource and multi-file upload routes wrote bytes before
saving their owning DB reference and did not acquire the shared lock.
Exception cleanup could run after partial DB commits, potentially
leaving inconsistent owners or touching a reused path.

**Narrow opt-in implementation:** A new `PrivateFileUploadService`
requires the existing feature flag, an ordered set of generated private
destination paths and an owner-insertion callback. It acquires the exact
HMAC path rows in fingerprint sort order, refuses existing destination
objects without writing, physically uploads only under held DB locks,
then creates all owners within one transaction. On failure, it performs
best-effort unlink of newly claimed files while the transaction still
holds the fences, then rethrows for DB rollback. Inbox, Study Scope,
Recall single/Resource/batch routes use this service only with the
R11 flag ON; legacy OFF mode and post-commit AI behavior remain.

**Acceptance:** Test non-overwrite, failed-owner rollback, batch
all-or-nothing, and exact saved path lock identity with disposable
PG16 and existing route regressions. Keep existing PG16 source races,
disposable MySQL8 current-read/fence, MySQL P0 migration, Workspace,
Recall AI and Migration Recovery checks. Verify exact reviewed HEAD.

**Residual:** best-effort cleanup can fail and process death can leave
orphan files; external/direct storage/SQL actors still ignore fence;
some Career integrations remain uncoordinated; same-byte object
replacement/version semantics are unresolved. The permanent fence
table and Outbox table still need a separately accepted Aiven
migration and owner release decision. R12 is not a production
enablement, deploy, scheduler, live deletion, inventory or device
verification. **G3 remains OPEN.**

## 58. G3-R13 — Durable prewrite journal for interrupted private uploads (2026-10-14)

**R12 gap:** physical private bytes could be written during an
upload transaction and left orphaned by a worker crash, process kill,
storage rollback failure or provider error; an in-transaction recovery
record would roll back alongside its reference, losing evidence.

**Bounded fix:** Add `private_file_upload_intents` additive migration
with unique retained HMAC-SHA256 path digest, Laravel authenticated
encrypted path, disk, status and timestamps. With both
`CANOVIA_PRIVATE_FILE_PATH_FENCE_ENABLED` and new
`CANOVIA_PRIVATE_FILE_UPLOAD_JOURNAL_ENABLED` enabled, the upload
service first rejects an existing outer transaction, validates all
private keys, durably commits `prepared` intent rows for the full
batch **before storage I/O**, then executes existing fenced uploads
and owner inserts. A successful main commit settles to `committed`
outside the transaction; upload failure leaves `needs_review`;
journal settlement failure conservatively retains `prepared`.
Default mode leaves R12 behavior unchanged.

**Proof and boundary:** Synthetic failure leaves encrypted committed
journal rows after storage and DB rollback; simulated crash after
prepare preserves an unresolved intent without auto deletion;
historically claimed path cannot be silently reused; a nested outer
transaction fails before object write; read-only reporting and
strict schema checks do not leak paths. Tests run on disposable PG16
and MySQL8.0/8.4, with prior G3 lifecycle, source-race,
Workspace and Migration Recovery gates at exact PR HEAD.

**Not solved:** An intent is only a proposed write, never proof of
unreferenced bytes; no storage inventory, object-generation API,
reconciliation, manual reaper, pre-R13 backfill, partial-byte repair,
external-writer coordination, APP_KEY rotation or automatic cleanup
is authorized. The journal is a **third separate G3 schema gate**,
alongside cleanup Outbox and permanent path-fence schema. G3 remains
OPEN; no production, Aiven, Render, cron, billing, storage deletion,
app-store/device test or deployment is performed here.

## 59. G3-R14 — Bounded read-only upload-intent object/reference observer (2026-10-14)

R13 retained encrypted, durable `prepared` and `needs_review`
upload intents through process death and transaction rollback. It
did not inspect whether any surviving object existed or had a
current owning database reference. R14 implements an **optional
non-production, fixed-output observation** without deletion powers.

`PrivateFileUploadReconciliationPreviewService` is gated by
`CANOVIA_PRIVATE_FILE_UPLOAD_RECONCILE_PREVIEW_ENABLED=false` in
addition to the R11/R13 gates, and rejects production runtime.
The bounded 1–20 explicit CLI reads only aged `prepared` and
`needs_review` journal rows. It checks complete source schemas,
decrypts each private key internally, validates HMAC disk/path,
SELECTs whether the existing fence row is present, SELECTs all three
source reference tables, and tests the exact object key by
`Storage::exists`. No row creation/locking/mutation, object listing,
byte reads, file unlink, scheduler or provider credentials are added.
Only allowlisted aggregate counts are returned; corruption or storage
failures return `unverifiable` rather than exposing raw data.
Disposable PostgreSQL16 and MySQL8.0/8.4 tests cover redaction,
immutable no-write observations, all four reference/object cases,
staleness and limit gates, corrupted ciphertext, digest mismatch,
schema failure, opt-in disabled behavior and default CLI rejection.

**Still OPEN:** These are inherently racy observations, not a
generation-bound or atomic reference/storage snapshot. An object
without a currently visible reference is not classified as orphan;
false-negative provider `exists()` is possible. Legacy untracked
objects, direct SQL/Storage mutations, some Career paths, key rotation,
path-fence growth, crash recovery reconciliation and immutable provider
generation deletion remain unresolved. Three G3 production
schema/flag gates require separate owner/operator approval.
No `production`/Render/Aiven mutation, storage enumeration,
physical delete, cron, real account inspection, release or device
verification occurred.

## 60. G3-R15 — Double-sampled weak metadata and reference change observation (2026-10-14)

**Gap:** R14 observed file existence and DB ownership only once, so visible
concurrent changes between storage/SQL observations were not detected.
Generic Flysystem metadata cannot prove immutable provider generation
identity; interpreting it as one would introduce destructive ABA risk.

**Scoped developer-only implementation:** New OFF-by-default
`CANOVIA_PRIVATE_FILE_UPLOAD_CONSISTENCY_PROBE_ENABLED` adds a
`PrivateFileReconciliationSnapshotService` to R14's capped opt-in preview,
observing existing fence-row existence, Inbox/Recall/Career reference
booleans and targeted file exists/size/mtime twice. Changes are flagged
`unverifiable`, never orphan; equal samples retain ordinary observational
categories with explicit `weak_metadata_not_generation_bound` status
and `generation_verified=0`. Missing/invalid metadata is
`unverifiable`. No new table, provider VersionId capture, lock, writes,
object listing, checksum, upload/delete workflow changes, scheduler or
automatic retry. Existing R14 behavior stays unchanged with the new
switch OFF. Production runtime remains blocked regardless of flags.

**Acceptance:** Disposable PostgreSQL16 and MySQL8.0/8.4 tests simulate
object size changes and inserted DB references between samples, validate
the same-size/mtime replacement false-negative without claiming version
proof, fail closed on invalid provider metadata, check OFF/production
gates, redacted CLI outputs and verify that the observer never deletes.
Review exact PR-head CI, full diff, base stability and merge protection.

**Still open:** same-size/mtime ABA, provider generation-bound operations,
non-cooperating SQL/Storage/Career writers, object-storage failures,
APP_KEY rotation/retention and all three separate G3 live database schema
gates. This is **not** a source-of-truth object-generation verifier,
orphan classifier, production release or permission to delete bytes.
G3 remains OPEN.

## 61. G3-R16 — Provider-generation semantics audited; offline fail-closed contract (2026-10-14)

R15's two weak snapshots may miss ABA or a same-size/mtime overwrite.
R16 inspects Canovia's current filesystem config (default `local`,
`s3` declared) and relevant S3 API semantics, then adds an optional
offline `PrivateFileGenerationContractService` without introducing a
storage adapter, bucket request or secret inspection.

Under OFF-by-default
`CANOVIA_PRIVATE_FILE_GENERATION_CONTRACT_ENABLED`, development
operators may read a fixed-category driver and missing-capability
report. Production is hard-blocked. Synthetic VersionId comparisons
reject missing, literal-null and mismatched IDs; even equality is
reported as `version_match_unbound` with
`generation_verified=false` and `delete_authorized=false`.
The operator CLI never prints raw IDs, paths, bucket names or keys.
PG16 / MySQL8.0/8.4 tests cover no-side-effect classification,
local/S3/unknown driver, fail-closed token checks, credential
redaction, production deny and default flag disable.

**Still OPEN:** no authenticated write-time object VersionId is
captured or durably bound to reference/path; no actual provider
versioning/IAM/bucket policy verified; no S3 adapter execution,
VersionId-aware or If-Match-guarded delete, lease, immutable
generation contract, external writer coverage, APP_KEY rotation or
production approval. All three G3 DB migration gates remain
separately unapproved. No Render/Aiven/live Storage operations or
automatic cleanup are performed.

## 62. G3-R17 — Storage-independent synthetic write-evidence ownership model (2026-10-14)

**R16 gap:** existing upload-intent journal rows were bound to an
encrypted private path but not a specific source-record owner or a
write-time provider VersionId, and APP_KEY-derived HMAC digest rotation
was unresolved. There was no persistent evidence shape on which to
base a future versioning-enabled provider adapter.

**R17 pilot:** Adds
`private_file_generation_evidence` keyed by a unique foreign key to
a committed upload-intent row, with HMAC bucket/path binding,
allowlisted owner kind/ID, encrypted opaque synthetic VersionId,
explicit key epoch and synthetic-only provenance. Recording requires
test environment + separately enabled flags and verifies exact
owning DB reference while locked in one transaction. No real storage
calls or authorization for deletion. Read-only inspection fails closed
for owner/path/status changes, corrupted token, missing legacy evidence,
bucket/key epoch changes and unknown provenance. Every result forces
`generation_verified=false; delete_authorized=false`.

**Verification gate:** Disposable PG16 and MySQL8.0/8.4 schema/test
coverage includes committed-owner binding, failed/duplicate/invalid
records, legacy behavior, encryption/redaction, production/disabled
gates, epoch and bucket rotation incompatibility, safe CLI reporting
and unchanged physical bytes. Check exact reviewed CI HEAD before
development integration.

**Not resolved:** No real S3 adapter capture, versioning-enabled
bucket attestation, signed response provenance, immutable provider
generation/conditional deletion, independent keyring/rotation or
coordinated external writers. R17 introduces a **fourth** additive
production schema gate, not approval to run it. R11/R13/R14/R15/R16
default-OFF runtime behavior and G3 OPEN status remain unchanged.

## 63. G3-R18 — Strict synthetic write-response handoff, provenance still absent (2026-10-14)

**R17 gap:** A committed journal/owner evidence fixture accepted a
VersionId-like string, but did not model the boundary between
successful write adapter output and a durable owner binding.

**Bounded R18 implementation:** Add `PrivateFileWriteReceiptHandoffService`
with one independently disabled flag and a test-only hard gate. Parse
strict synthetic `PutObject` results without accepting arbitrary
extra fields, alternate origins, error HTTP statuses, ETag-only,
null/invalid VersionIds, changed owner/disk/key/bucket or intent
identities. Only after matching the authenticated journal path does
the existing R17 locked evidence writer persist a **synthetic_fixture**
row with unique intent identity. No additional database table,
real provider call, upload/delete path change, local-file I/O,
scheduled job or production enablement.

**Verification:** Disposable PG16 and MySQL8.0/8.4 runs test
untrusted fixture rejection, explicit context binding,
uncommitted intent, mismatch between intent and current owner,
duplicate response immutability, no secret echo, production and OFF
gates, and absence of object mutations. Exact PR SHA, complete diff,
reviewer gates and base mergeability must pass.

**Not solved:** The caller's origin and response are spoofable fixture
data, not authenticated live S3 provenance. R18 does not establish
generation proof, request/response atomicity, write-time receipt
durability, versioning status, ownership of bytes, conditional delete,
or support for a future keyring/APP_KEY rotation. No production
Aiven/Render deployment or live migration of the four previously
introduced G3 tables is authorized. G3 remains OPEN.

# Learning Context Watch v0.10 — additional Plan-first Task source writers (shadow-only)

Date: 2026-10-15 JST. Intelligence/Learning/Execution/Companion integration; **development main only**.

## Scope and motivation

v0.9 (#737) introduced an OFF-by-default monotonic Plan source epoch for first-party Task CRUD, owner-applied native practice and owner-decided external practice. The source epoch is **shadow-only**; historical hash v0.7 (#730) remains authoritative because uninstrumented source writers still exist.

This increment connects the following already-Plan-first **human-confirmed Study Task mutations** to that same source epoch ledger:

| Mutation path | Existing lock order | New epoch operation | Existing retry/no-op fence |
| --- | --- | --- | --- |
| AdaptiveLearningNextActionController::__invoke | Plan -> Task | `task_updated` after actual next Action note write | Exact owner-confirmed candidate retry returns before write |
| GuidedExecutionController::confirmNextAction | Plan -> Task -> latest valid Reflection/Evidence | `task_updated` after next Action note write | Equal action returns early; hash protects stale form |
| StudyRecallController::complete | Plan -> Task -> Recall/Evidence | `task_updated` after completed Task write | Already completed Task returns before record |
| StudyActionTaskProjector::project | Plan -> ActionProjection -> Task | `task_created` only when a new projected Task is inserted | Existing/linked Task reused; stale action denied |
| CompanionMutationApplyService::applyCreateTask | Plan -> Task -> Candidate | `task_created` after create | Existing approved candidate cannot execute twice |
| CompanionMutationApplyService::applyUpdateTask | Plan -> Task -> Candidate | `task_updated` only when Eloquent reports actual field changes | Same-value/new candidate writes do not increment; apply request deduped |

All records are performed **inside the same original DB transaction** while the current Plan is locked. If mutation, downstream audit or epoch fails, source update and epoch both roll back. The study-only/private/owner Plan constraint and strict feature gate from v0.9 apply. No new schema or epoch kinds.

## Verification

- Existing Adaptive Learning next Action end-to-end controller test asserts one increment on owner approval, unchanged on exact retry.
- Guided Execution Study-specific synthetic fixture asserts exactly one increment, no progress mutation. Original Guide-only tests remain.
- Recall verified completion test asserts one increment and none on repeated completion.
- Study Action projector fresh Task creation test asserts one, denies stale replay without new Task or epoch.
- Companion synthetic private Study Plan test covers one Task creation, one real Task field change, then one duplicate-value *separately approved* mutation with no epoch increment. Original entitlement, approval, deny and legacy Companion tests remain in SQLite regression.
- Existing two-physical-connection PostgreSQL17/MySQL8.4 contention workflow now verifies Guided next Action cannot commit Task or shadow epoch while a different connection holds the Plan; after release both commit. Existing first-party Task/write concurrency suites remain.

## Remaining limitations and explicit HOLD

**Do NOT use Epoch as a trusted source_revision or automatic Watch scheduler input.** The original historical hash is unchanged and remains the only freshness boundary. Uninstrumented paths include PlanReviewAssistant Task/Plan changes, some Plan metadata writes, direct SQL/model operations (including corrections and old proposal revocations), imports, other work-session/evidence paths, owner transfers and historical eligibility transitions. These can alter the current source without bumping shadow epoch. Full mutation provenance or a database-enforced epoch/change-log (plus historical and ownership/consent proof, rollback/idempotency and load testing) is required before replacement of O(N) capture or any notification delivery.

The source revision also deliberately excludes next_action_note's semantic content; some same-second note changes may be invisible to current timestamp-based revision. Shadow counter can count a write without implying it is an actionable user learning event. No new training claims or public roadmap readiness are implied.

Environment `CANOVIA_LEARNING_CONTEXT_EPOCH_SHADOW_ENABLED=false` and `CANOVIA_LEARNING_CONTEXT_WATCH_PERSISTENCE_ENABLED=false` remain OFF by default; synthetic Staging rejects the shadow. No cron, queue, AI action, MCP extension, email/push, Premium2, billing or entitlement changes, Aiven migrations or Render deploys are authorized by this PR. Public roadmap remains unchanged; this is internal correctness hardening not a launched feature.

# Learning Context Watch v0.6 — writer lock inventory and remaining auto-detection HOLD

Date: 2026-10-15 JST · Lane: Intelligence / Learning · Target: development `main` only.

## Why this slice exists

Source Capture (#707) and Watch Persistence (#711) read Study Plan, bounded active Task details, applied native practice and owner-approved external practice. These sources are revised while other routes commit; absent a common locking contract a Watch comparison can observe an intermediate mix of Task and evidence. In v0.5 (#720), TaskController create/update/delete and StudyPracticeController applyAssessment joined the **Plan → Task → Attempt** lock convention. This audit looked specifically for remaining direct writers to the bounded Study watch sources; it is not an exhaustive proof that all Canovia services follow the convention.

## Checked write paths and evidence

| Path | Source mutation / risk | Audit and v0.6 disposition |
| --- | --- | --- |
| TaskController::store/update/destroy | Task title/status/progress, active Task population | Already guarded by Plan-first lock in v0.5, verified two-connection tests |
| StudyPracticeController::applyAssessment | Native attempt `applied_at` and Task status/progress | Already Plan → Task → Attempt guarded in v0.5; repeat-apply regression passes |
| McpStudyResultProposalService::decide | Approved/rejected third-party practice record | Existing Plan → Task → linked Subject/Grant → Proposal lock before approval |
| StudyActionTaskProjector::project | Creates Task from confirmed adaptive Study Action | Existing Plan lock and source/projection checks inside transaction |
| CompanionMutationApplyService::apply | Create/update Task, update Plan | Existing Plan → Task → Candidate lock and membership validation. Separate G3 real-DB lock workflow covers its subset |
| PlanReviewAssistantController applied proposal | Plan and Task creation/change/cancel/reorder | Existing Plan-first transaction; Task rows subsequently locked for changes |
| StudyPracticeAssessmentCommitFence | Assessed Study Session state | Existing Plan → Task → Session transaction fence. **This is not the same as an applied attempt**, which is separately governed |
| AdaptiveLearningNextActionController::__invoke | Owner-confirmed next_action_note; Task `updated_at` contributes to revision hash | **Fixed in v0.6**: live Plan FOR UPDATE → live Task FOR UPDATE → owner+Study reauthorization → update |
| StudyRecallController::complete | Recall mastery can mark Task `done` and create Evidence | **Fixed in v0.6**: live Plan FOR UPDATE → live Task FOR UPDATE → refreshed authorization → Recall state/Evidence |
| GuidedExecutionController and other multi-domain Task writers | Task `updated_at`, activity metadata or remaining progress | **NOT covered** by this PR; cross-lane write inventory required, even if current bounded projection would not treat a field as an actionable event |
| Raw SQL, import scripts, admin operations, future provider links, direct Task model saves | Potential Task/Plan or applied evidence edits outside controllers | **NOT covered**. A universal Plan lock cannot be claimed |
| Source Capture source digest | Only top 8 + eligible counts, not complete historical corrections | **NOT fully solved** by row locking. Old-row correction/retraction and writer compliance must be decided before periodic checks |

**Key distinction:** `next_action_note` is not displayed inside Snapshot v1, but saving it updates Task `updated_at` and can invalidate a source revision. It therefore still needs correct synchronization; do not classify it as harmless merely because it is not directly visible.

## Concrete code and verification

1. `AdaptiveLearningNextActionController`: retain initial request authorization for UX, then inside the same DB transaction lock fresh Plan before Task. Re-evaluate Study domain and owner Task authorization under those locks, compute the current suggested next Action against locked Plan and write with source-signature/previous-hash verification. No automatic progress/mastery change.
2. `StudyRecallController::complete`: acquire fresh Plan row lock before Task and Recall cards and verify owner+Study domain at commit time. Pass locked Plan into mastery/progression decisions. Existing Evidence is recorded only in that guarded transaction.
3. In disposable PostgreSQL 17 and MySQL 8.4, extend existing independent-physical-connection Plan-lock tests to both routes, proving they do not bypass a Plan held by a rival connection and leave no partial Task or Watch change. Existing v0.5 tests remain.
4. Rerun `AdaptiveLearningNextActionV611Test`, `StudyRecallTaskProgressionV586Test` plus Study practice/Watch SQLite tests, broad Workspace/Platform/AI fences CI.

## Safety boundary and next gate

**Automatic scanning, scheduled jobs and notifications remain DISABLED**. These two additional source writers increase consistency coverage, not a platform-wide transactional guarantee. Before any auto Watch schedule, a dedicated follow-up must (a) inventory every remaining *actual* mutation to the bounded Task/evidence source via all controllers, services, admin and external integrations; (b) close lock-order gaps (or install a stronger revision-epoch protocol) and run real multi-connection racing tests; (c) validate source corrections outside the eight-row window and opt-out/Plan transfer as terminal suppressions; (d) enforce active consent duration, retention cleanup for dormant Plans, rate/quiet hours and per-user costs. Separate notification consent, delivery dedupe and platform/device E2E remain mandatory.

There are no migrations, database writes executed against user data, cron jobs, external MCP scope or premium entitlements in v0.6. `CANOVIA_LEARNING_CONTEXT_WATCH_PERSISTENCE_ENABLED=false` and synthetic Staging lockdown remain intact; production Aiven migration and Render release are separately HOLD. No public-roadmap status change is appropriate: this is an internal pre-release correctness improvement.

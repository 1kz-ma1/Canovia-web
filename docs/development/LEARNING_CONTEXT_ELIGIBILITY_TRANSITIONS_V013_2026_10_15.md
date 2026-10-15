# Learning Context Watch v0.13 — owner and sharing eligibility transitions, source correction audit

Date: 2026-10-15 JST. **Developer-main-only shadow-source instrumentation. No production deployment, Watch automation, Aiven DDL or notification delivery.**

## Background

The v0.7 authoritative Learning Context Source Capture allows only **currently owner-authenticated, private, non-collaborative Study Plans** with a non-empty title. A source_revision is not a permission token: the `isCurrent()` path always recaptures and denies old owners or ineligible Plans. The optional Source Epoch from v0.9–v0.12 is still an incomplete diagnostic counter, not an authorization fence.

Two real first-party source-eligibility transitions already hold the current Plan row before mutation but were missing from that optional counter:
- `PlanCollaborationController::enable/disable` — owner-approved private Study → collaborative (no longer eligible) → private Study (eligible again).
- `GuestPlanClaimService::claim` — valid owner-token Guest Plan becomes an authenticated private Study Plan and qualifies for a new owner-scoped capture.

## Implementation

- On **actual** private → collaborative enable, `record(lockedPlan, 'plan_updated')` **before** setting `is_collaborative=true`. After that write the Plan is ineligible for shadow recording. The old owner Context Watch capture must immediately return null even if the old hash was stored. No-op re-enable does not increment.
- On **actual** collaborative → private disable, record `plan_updated` **after** `is_collaborative=false` is saved, when the owner Plan becomes eligible again. No-op re-disable does not increment. Existing `Plan→authorization epoch` and member access protections remain authoritative for shared Plan permissions.
- On a **successful first Guest Plan claim**, record `plan_updated` after saving `user_id` in the same Plan-first transaction, only when the result is eligible owner-private Study. A competing different user with a retained guest cookie can no longer take ownership, and the same owner repeating claim does not increment.
- All three paths retain the existing Plan lock and access checks. If an epoch write fails, the Plan mutation rolls back. No new schema, mutation kind, UI entry point or external credential is introduced.

## Independent tests

- SQLite owner Study Plan: valid capture, share enabled ⇒ capture unavailable and prior revision cannot be used; re-enabled no-op leaves epoch at 1; share disabled ⇒ eligible capture again and epoch reaches 2; repeated disable stays at 2.
- SQLite Guest Plan claim: before claim no authenticated capture; valid owner token allows one claim and shadow epoch 1, a different authenticated user cannot read/capture or re-claim, repeated claim does not bump.
- Optional shadow flag OFF: existing share and Guest workflows continue without inserting any epoch rows.
- Atomic rollback test: an exceptional transaction after recording sharing exit leaves both Plan and epoch unchanged.
- MySQL 8.4 / PostgreSQL 17 two distinct physical connections: existing Plan held by separate connection prevents a share enable or Guest claim from completing; after lock release each succeeds and records the expected Epoch. No customer Plan or production database is accessed.

## Historical correction/deletion audit — still HOLD

Production Study Practice currently exposes application of assessment but not a dedicated user-facing **retroactive correction/delete API** for an already-applied attempt; MCP external results have an owner-approved decision flow but no regular retroactive rewrite interface. Tests such as `LearningContextSourceCaptureTest` and `LearningContextSourceEpochShadowTest` deliberately mutate older `StudyPracticeAttempt` / `McpStudyResultProposal` rows directly to show that **full-history hash catches revisions while the partial shadow epoch does not**. `StudyScenarioLabService` additionally seeds and replaces synthetic developer-controlled scenarios, which is **not proof** of real source-writer epoch completeness and must not be silently promoted to production.

Do not introduce a new user-facing grade rewrite/delete feature just to make Epoch look complete. If a real correction workflow is approved, it must perform parent Plan-first locking, currently eligible owner scope, source ID/Task membership checks, immutable original attempt provenance/audit, optional redaction and consistent source epoch within the **same** write transaction. Audit direct Model/SQL changes, data repair scripts, importers, role/ownership transfer and off-path source writers independently.

## Remaining correctness/security caveats

1. The first-party **public flag and non-Study category transitions** from `PlanController::update` and `PlanReviewAssistantController` can suppress shadow counter once the Plan becomes ineligible. Actual Context Watch capture already returns unavailable after these changes. An epoch is not a substitute for permission/consent revocation or cleanup.
2. A Plan may become eligible again within the same timestamp resolution; a full v0.7 hash can theoretically show A→B→A as unchanged if all current fields match. This per-mutator epoch diagnoses some of that, **but it still cannot prove completeness or safely authorize delivery**.
3. Direct SQL / historical corrections and other Plan owner-transfer paths still bypass the shadow. `plans.authorization_epoch` is a separate permission/version mechanism enforced by database triggers; it is **not** the Learning source_epoch and must not be conflated.
4. Watch persistence remains explicitly opted-in and disabled by default; no polling, queues, push, emails or automatic notifications are enabled. Do not infer release status from a green PR/CI; no public roadmap update is needed for this internal data-correctness patch.

## Next focused design review

Consider a **database-enforced source mutation/change journal** keyed by Plan with transactional trigger coverage for Task and owner-relevant historical study records; first separately audit transaction isolation, data repair paths and required privileges on disposable PostgreSQL/MySQL. Retain historical-hash correctness until it is proven safe to replace the O(N) scan; do not execute new production DDL on Aiven during this review.

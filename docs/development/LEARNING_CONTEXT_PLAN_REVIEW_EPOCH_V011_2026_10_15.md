# Learning Context Watch v0.11 — Plan Review epoch writer hardening

Date: 2026-10-15 JST. **Development main only; no automatic Watch, notifications, production deploy or Aiven DDL.**

## Background

The Study owner-approved Plan Review apply route is a compound **Plan + Task + WorkLog** writer. It previously acquired a Plan lock but discarded the freshly locked model and continued mutating the earlier route-bound Plan; it also did not bump the shadow-only source epoch introduced in v0.9–v0.10 (#737/#740). Companion's separately approved Plan metadata change similarly updated the Plan without recording the shadow epoch.

The current authoritative v0.7 source digest includes private Study Plan title, deadline, category, override and `plans.updated_at`, plus eligible Task title, status, progress and `updated_at`. An unrelated Plan attribute update may still change `updated_at` and thus the digest. Do not silently equate only visible semantic changes with a source revision.

## Implemented

- The Plan Review apply transaction **rebinds the current Plan from `FOR UPDATE`** and rechecks the route's current Plan authorization under that lock, rather than continuing from the stale pre-lock object.
- **After each actual saved mutation**, calls the existing `LearningContextSourceEpochService::record(lockedPlan, kind)` within the *same transaction*:
  - `update_plan` → `plan_updated` only if model attributes really changed.
  - `create_task` → `task_created`, one per inserted Task.
  - `create_work_log` with linked Task progress change → `task_updated`, not for a log with no Task progress change.
  - `update_task`, `cancel_task`, `reorder_tasks` → `task_updated` only where Eloquent detects a mutation. Reordering can affect `tasks.updated_at`, which is part of the current authoritative hash.
  - `update_availability` / `keep_task` do not directly mutate currently hashed Plan/Task source fields and do not create a synthetic event.
- Companion `update_plan` now records `plan_updated` after a real Plan model update. Same-value approvals do not bump. The existing Plan-first lock order, authorization and human-approval path remain intact.
- `LearningContextSourceEpochService::KINDS` includes the non-sensitive `plan_updated` code. This is shadow-only. It does not store Plan title, task title, score, user identity or raw evidence and adds **no DB schema, route, scheduler, MCP field or notification sender**.

## Verification and correctness boundaries

- Synthetic private Study Plan, multiple operations in a single Plan Review apply: deadline/title change, Task insert, Task progress update, cancel, reorder; assert per-operation epoch sequencing and data completion in one transaction.
- Same-value Plan Review and retry without the original session result in no extra epoch or duplicated PlanAdjustment.
- WorkLog-created Task progress change increments exactly once.
- Synthetic development Plan is not eligible for Study epoch even if the shadow feature is manually enabled in test.
- Real PostgreSQL17/MySQL8.4 physical two-connection contention: holding the Plan prevents simultaneous Plan Review updates or shadow Epoch; after release the same confirmed apply succeeds with matching epoch.
- Companion Study Plan metadata actual update increments once; same-value separately-approved update does not.
- Production safety still depends on the v0.9 optional `CANOVIA_LEARNING_CONTEXT_EPOCH_SHADOW_ENABLED=false` flag, and Watch persistence separately remains OFF. No real personal study data touched.

## Deliberately unaddressed

1. **Normal PlanController::update** currently validates and updates its bound Plan *outside a Plan-first DB transaction*. It can change deadline/title/eligibility without this Epoch; it is a priority next bounded slice. Do not enable automatic Watch before its authorization/Plan-write race is resolved.
2. Other Plan-side changes, bulk Task updates, historical corrections/deletions and direct SQL/model writers can bypass Epoch. The old full-history digest is still the **only authoritative freshness checker**. There is no universal epoch enforcement or automatic task monitoring.
3. Plan category/public/collaboration changes can make Watch ineligible; current shadow service only records while the **new** Plan state remains an eligible private Study Plan. Revocation/eligibility transition is not proven by an epoch; current capture returns unavailable, and schedule/delivery must not exist.
4. A mutation that saves an Eloquent Task or Plan twice in the same second can change Epoch while its current v0.7 timestamp-based digest stays the same. Per-mutation Epoch is not yet a sound replacement for either semantic change detection or historical hash; precise mutation source fingerprints and complete writer audits remain required.

No public-roadmap promotion: internal correctness work on development main does not constitute a released or validated feature.

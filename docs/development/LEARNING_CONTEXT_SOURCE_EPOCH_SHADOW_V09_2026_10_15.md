# Learning Context Watch v0.9 — source mutation Epoch, shadow-only foundation

Date: 2026-10-15 JST. **Development main only; NOT production DDL or automatic Watch rollout.**

## Why

The current Source Revision v0.7 (#730) hashes all eligible Task/native-applied/MCP-approved rows under hard bounds of 256/512 per Plan. It catches old revisions and deletions but is O(N) on every capture and fails closed over budget. Replacing the digest with a mutation epoch before every writer is instrumented would be unsafe: missing one old-row correction can falsely mark a snapshot fresh.

## This implementation

- Adds `learning_context_source_epochs`, a Plan-scoped one-row monotonic `epoch` with unique FK cascading on Plan deletion, and one non-sensitive mutation kind string. No raw Task/progress, assessment, question, title, user ID, evidence or API credentials.
- Adds internal `LearningContextSourceEpochService::record(lockedPlan, kind)` and `inspect(planId)`. The service requires a caller-held **Plan FOR UPDATE lock** and an active transaction; record commits/rolls back together with the source write. A counter exceeding the supported range throws to roll back.
- Gate `CANOVIA_LEARNING_CONTEXT_EPOCH_SHADOW_ENABLED=false` by default. Strict boolean `true` only; disabled on synthetic staging. If enabled but schema missing/partial or transaction invalid: fail closed on the instrumented write. Never treat a missing epoch as zero or falsely prove freshness.
- Instrument only audited first-party **Task create/update/delete**, **native Study practice apply**, **owner-approved external Study result decisions** under existing Plan-first transactions. Redundant accepted POSTs to already-applied practice and no-op decisions do not increment.
- Current capture's `source_revision` and `isCurrent` are **unchanged** and authoritative. Epoch is strictly shadow-only diagnostics and is *never read by Watch decision, notifications, MCP, or rendering*. Only validated internal callers should inspect it; no HTTP route.
- Dedicated tests verify gate OFF, monotonic increments, transaction rollback, idempotent/rejected unknown mutation calls, race ordering on two physical disposable DB connections, and a historical correction that changes the authoritative hash **without** changing this partial epoch, proving the shadow cannot currently replace the historical digest.

## Release risk

The new migration must not run on Aiven or Render without the separate owner-approved, pinned-SHA preflight, backup/audit and release gate. The default OFF means code integration on development main alone does not change existing normal writers. DO NOT flip the environment flag on unverified production without applying the table migration and comprehensive shadow integration tests.

## Known gaps before source epoch becomes authoritative

All writers, including Adaptive Learning, Study Recall, Guided Execution, Companion, review proposals, direct Model/SQL updates, imported/historical corrections and owner Plan changes, must be joined to the contract or a verified **database-level** mutation change-log/trigger fence. Timestamp precision, removal from eligibility (`done/cancelled/unapplied/rejected`), owner transfers and history deletes also need coverage. A direct SQL update bypassing `record` remains invisible to this shadow counter: therefore it cannot replace current full-history hash, cannot enable auto-polling, and cannot authorize notifications or Premium delivery.

Future work: reconcile source epoch vs authoritative hash under correction scenarios, choose a scalable source journaling mechanism and cost measurements, and certify every writer against real MySQL and device flows. `CANOVIA_LEARNING_CONTEXT_WATCH_PERSISTENCE_ENABLED=false` remains OFF independently.

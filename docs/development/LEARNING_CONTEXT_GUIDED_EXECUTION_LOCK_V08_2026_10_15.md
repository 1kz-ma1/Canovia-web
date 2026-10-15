# Learning Context Watch v0.8 — Guided Execution cross-domain Task mutation fence

Date: 2026-10-15 JST. Applies to development `main` only, no automatic polling or notification delivery.

## Problem confirmed

`GuidedExecutionController::confirmNextAction` can be invoked from multiple domains including an eligible Study Plan. Before this slice it re-authorized a route-bound Task **outside** its transaction and locked only the Task inside the transaction, updating `next_action_note`. The Task `updated_at` timestamp participates in the internal Learning source revision, so this was a cross-domain Task writer that did not cooperate with the parent `Plan::lockForUpdate()` lock used by Learning Context Watch. An ownership or membership change could also occur between the request's initial authorization and its mutation.

## Change

- On confirmation, keep existing early authorization and idempotency/ownership checks for UX, but **inside the DB transaction** lock the current Plan first, then the current Task with an explicit `plan_id` filter.
- Bind the live Plan to the locked Task model and perform fresh permission verification with a new `PlanOwnershipService` instance; this avoids reusing the per-request role cache from the initial pretransaction authorization.
- Only then read the latest reflected Guided Execution and validate its actor, Evidence link, completed state, next-adjustment length, current Task status and expected previous-note SHA-256; update only `next_action_note` (never Task progress).
- No change to `prepare`, `reflect`, `cancel` or evidence semantics. Those methods do not directly update the bounded active Task source fields. This patch does not claim that all evidence or administrative writes use a universal Plan lock.

## Verification

- The existing independent physical-connection `LearningContextPlanLockContentionTest` is extended to create a completed synthetic reflection, hold the parent Plan on a second PostgreSQL 17/MySQL 8.4 connection, and assert confirmation fails with DB lock timeout before Task mutation. After releasing that lock, the **same** confirmation succeeds; no spurious Learning Context change record or Task progress update is produced.
- Existing guided execution tests `GuidedExecutionNextActionV67Test` and `GuidedExecutionV4114Test` are added to SQLite regression alongside the existing Study/Watch suites.
- No customer data, OAuth credentials, production Aiven, Render deployment, or external AI are used. The disposable concurrency test is pinned to loopback and its exact synthetic CI database name.

## Remaining limitations

- This is another **participating writer** in the Plan-first protocol, not platform-wide serialization. Remaining Task writers, ownership/collaboration role changes and direct SQL still need audit and (where necessary) a shared transactional source epoch.
- `updated_at` timestamps at whole-second precision are insufficient to prove detection of two note-only changes within the same second. `next_action_note` is not currently a direct semantic input to the bounded Learning Context snapshot. This slice makes the writer wait on the Plan but does **not** claim to add a new actionable change type.
- Historical-source v0.7 uses a bounded full-history hash and refuses captures over 256 active Tasks / 512 eligible practice items; no scalable event-epoch protocol or dormant-record retention sweeper is implemented here.
- The default `CANOVIA_LEARNING_CONTEXT_WATCH_PERSISTENCE_ENABLED=false` stays OFF. Scheduling, notification permissions, delivery idempotency, additional transaction fences and separately accepted production release remain HOLD.

## Public roadmap relevance

An internal data correctness hardening step without a deployed or user-facing feature change. Do not mark the public roadmap feature as released or rolling out merely because this PR merges into development main.

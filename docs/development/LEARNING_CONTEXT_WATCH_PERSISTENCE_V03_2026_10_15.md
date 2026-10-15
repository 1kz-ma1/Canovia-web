# Learning Context Watch Persistence v0.3 — consented state, no delivery

Date: 2026-10-15 (JST). Owner: Intelligence (Learning source contracts; Platform privacy review).

## What this PR implements

This is a deliberately **OFF-by-default, first-party, single-Study-Plan persistence kernel** built on the Snapshot (#703), Change Detector (#704), and owner-bound Source Revision (#707) code. It does **not** schedule recurring evaluations, deliver alerts, issue MCP grants, expand AI memory, expose a new HTTP API, change Plan/Task, or enable Premium 2.

The gate `CANOVIA_LEARNING_CONTEXT_WATCH_PERSISTENCE_ENABLED=false` is in `config/intelligence.php`. Staging explicitly denies opt-in/advance; turning on the flag alone still does not create user consent or invoke anything. No route/job/push channel is registered.

### Durable schema (additive, non-production)

- `learning_context_watch_states`: unique Plan, user ID, enabled state, consent timestamp, source revision SHA-256, **Laravel encrypted baseline** containing the bounded internal Capture v0.2. Its encrypted Laravel cast means APP_KEY rotation requires a recovery/erase policy before public release; the service fails closed if decryption fails. No raw questions, full grading rationale, Task descriptions, tokens, or complete activity history.
- `learning_context_change_records`: unique `plan_id + dedupe_fingerprint`, source revision, **event-kind codes only**, status `review_only` or `superseded`, expiry. **Not an outbox**; there is no sender. Prior candidates become superseded whenever source revision changes. Replaying the same semantic transition is suppressed by database uniqueness.
- Foreign keys cascade on Plan/User deletion. `up()` checks required columns when tables exist and refuses incomplete preexisting schemas rather than silently replacing data. The destructive migration `down()` is for isolated rollback only; production rollback must preserve and explicitly audit personal records.

### Internal API and privacy boundary

- `optIn(User, planId)`: only when feature enabled, actor is the **currently authenticated Canovia session owner**, Plan is private, personal, Study, and capture is valid. First opt-in creates encrypted baseline; explicit reconfirm resets earlier candidate history. No connected UI yet, therefore end users cannot currently activate it.
- `advance(User, planId)`: on-demand first-party method; only for previously opted-in, same-owner private Study Plan. Plan-then-watch row locking; recapture and verify the actual owner/source revision before the transaction commits. Compare the source-derived bounded baseline and current capture. No actual activity is inferred when the sample is truncated or not comparable. If inconsistent, missing, unreadable, unauthorized or source-stale, fail closed.
- On a review-candidate change, persist only the approved kind code(s) and one pair fingerprint. On a repeated pair, return `duplicate_suppressed`. The encrypted baseline updates even for `changed_no_action`. Earlier records are superseded on a new revision, never sent. All results explicitly set `notification_eligible=false` and `mutation_performed=false`.
- `optOut(User, planId)`: authenticated **current owner**, available even when the feature is OFF or a Plan has become public/collaborative or no longer Study. Atomically erases baseline and all its change records. Former owners cannot retrieve or delete another actor's records; explicit new-owner opt-in replaces the old baseline without revealing prior content.
- Per-Plan change records are pruned at `advance()`: 30-day expiration and at most 32 retained. This is **opportunistic, not a scheduled deletion guarantee**; dormant Plans need a separate audited retention lifecycle before production activation. Opt-out and Plan/User cascade are immediate.

### Security and consistency caveats

The state-writing transaction locks the Plan and state row. Source capture also rechecks the same owner/Plan and recalculates the bounded source revision. **Legacy Study/Task writers that do not acquire the Plan lock can still race these reads.** This code does not claim an exact atomic event ledger or independent strong two-connection MySQL/Postgres snapshot-consistency proof. A second source check reduces stale writes but does not alone close every concurrent source mutation. Future notifications MUST have a separate final permission/revision fence, durable delivery-idempotency, preferences, quiet-hours, channel consent and source correction invalidation.

There is currently no user-facing consent UI, notification settings screen, polling job, asynchronous worker, Premium subscription tie-in, machine-triggered advance, external AI access or scheduled check. Explicit opt-in is an internal entrypoint reserved for a separately reviewed first-party UX.

### Release and acceptance

Feature tests cover off-by-default, opt-in authentication, encrypted stored baseline, no duplicate baseline rows, source revision updates, same-pair dedupe, superseding earlier candidates, opt-out even with OFF flag, wrong actor/foreign Plan, owner transfer, public/collaborative/domain change, Staging denial, migration replay and on-Plan-delete cascade. Existing Source Capture and Change Detector tests should remain green, as should the broad Workspace/release preflight suites.

**Migration impact:** two new MySQL/Aiven tables, foreign keys, unique constraints, indices and encrypted data-at-rest; no DDL run in the user's environments through this work. This migration is an independent **production RELEASE HOLD** item; follow the PINNED release/Aiven audit, exact SHA restore and manual deployment verification requirements. CI SQLite success is not proof of Aiven/MySQL lock behavior or safe migration on production schema.

### Follow-up, NOT part of this PR

1. Verified owner-facing opt-in / opt-out UI and actor authorization; consent expiry and privacy explanations.
2. Real database concurrency tests with Plan/Task/source writer lock adoption, per-source revision/erasure semantics, and approved retention cleanup on inactive Plans.
3. Optional internal trigger with bounded rate/quiet hours, dedupe across delivery, notification preferences and push permissions. No notification should ship simply because a record has `review_only` status.
4. Premium 2 orchestrated cross-Plan change detection: independent policy, entitlement and human confirmation for consequential changes.

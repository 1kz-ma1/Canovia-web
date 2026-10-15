# Learning Context Source Revision v0.2 — internal first-party capture

Date: 2026-10-15 JST. Lane: Intelligence + Learning.

## Status and release boundary
Read-only, on-demand INTERNAL capture plus current-revision check only. No persistent baseline, poller, scheduled run, notification, queue, schema migration, MCP route, billing change, or production/Staging deployment. The existing real AP pilot release HOLD stays effective.

## What it adds
Learning Context Snapshot v1 (PR #703) intentionally hides stable Task IDs and raw Study evidence from ChatGPT. The Change Detector v0.1 (PR #704) needs stable Task IDs and reliable source identity for local comparisons. It must not treat a snapshot read timestamp as a source-event revision.

LearningContextSourceCaptureService::capture(User actor, planId) only accepts an actual Canovia User object and one explicitly specified, private, non-collaborative Study Plan owned by that actor. It locks the Plan in a transaction and selects up to eight active Task rows (plus truncation), latest owner-APPLIED StudyPracticeAttempt rows and independently owner-APPROVED MCP study-result proposals. Every eligible record is scoped to the same user and Plan. Combined approved practice is bounded to eight records; no pending, rejected, unapplied, other-user or other-Plan data is included.

The returned internal envelope contains actor_id, plan_id, source_revision and a source-derived, bounded snapshot compatible with LearningContextChangeDetectionService. Task IDs exist only inside this INTERNAL projection; its delivery marker is internal_owner_scoped_read_only, not a claim that OAuth consent exists. The separately owner-consented external MCP tool and sharing contract are untouched. Raw questions, answers, Task descriptions, rationales and grade explanations are excluded.

The SHA-256 source_revision is derived from stable IDs, eligible row counts, current Plan details, Task state, applied study scores/weakness inputs and update timestamps. Its input can include assessment fields for invalidation, but only the hash is returned. Repeat reads do not change it simply because time advanced.

isCurrent(User actor, planId, expectedHash) re-evaluates current ownership, Study categorization and source rows, compares using hash_equals and returns false for malformed digests, change, deletion, Plan transfer or loss of permissions. It is a POINT-IN-TIME check, not a stored durable version or delivery authorization.

## Scope and limitations
- No durable baseline, event archive, notification lifecycle, user preferences, iOS push, cron, Premium 2 entitlement or ChatGPT monitoring.
- The read transaction locks Plan, but source writers that do not acquire the Plan lock can race. Multi-table consistency and isolation under concurrent writes need additional dedicated validation before any consequential use.
- Fingerprinting is bounded to the first eight matching source rows plus eligible total counts; an edit to an older out-of-window attempt with unchanged count may not affect the revision. No full historical correction/retraction stream is claimed.
- A newer reading is not a verified chronological ability trend, and a missing Task or tag is not verified completion or mastery.
- Existing StudyDerivedContextFreshnessGuard covers a different Living Profile derivation (up to twelve native attempts) and must not be equated with this multi-source capture.
- No external/real account data was read. No modifications to Render, Aiven, Auth0, actual user Plan records or the synthetic-only MCP Staging.

## PR acceptance
Run real disposable SQLite feature tests for identical read/revision, Task ID comparison, correction and deletion invalidation, unauthorised owner and Plan changes, no private payload in projection, excludes unapplied/other actor/pending/rejected attempts, confirmed external records, bounded Task list; additionally rerun the Change Detection v0.1 unit tests and the broad Workspace regression CI.

## Next independently scoped work
Durable Plan-scoped baseline and event persistence with schema and retention review, explicit opt-in, source revision rechecks, atomic dedupe/commit, source correction and revocation handling, safe migration/rollback and then separate quiet-hours/notifications E2E authorization. No automatic delivery until those gates succeed.

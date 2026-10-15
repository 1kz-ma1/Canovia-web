# Learning Context Watch v0.16 — filtered DB probe vs authoritative historical hash

Date: 2026-10-15 JST. **Test-only; no app runtime code, no migrations, no Render/Aiven changes, no auto-Watch or notifications.**

## Purpose

The v0.15 (#751) disposable MySQL 8.4 / PostgreSQL 17 experiment showed that filtering AFTER-ROW triggers can avoid irrelevant DML. In the single 72-UPDATE CI workload, PostgreSQL observed baseline 53.406 ms / unfiltered 58.857 ms / filtered 57.352 ms and MySQL observed baseline 43.921 ms / unfiltered 451.758 ms / filtered 63.161 ms. These **single, shared-host observations are not representative production benchmarks** or a dependable speedup claim; a real evaluation would need isolated repetitions, p50/p95/p99 and mixed concurrent workloads.

The critical issue remains **false negatives**: if a DB mutation can change the v0.7 Learning Context Source Capture hash without incrementing the experimental filtered probe, using the probe to bypass O(N) scanning would be unsafe. The new synthetic differential regression treats the current full hash as the testing oracle.

## Added disposable-only differential test

`tests/Feature/LearningContextSqlMutationDifferentialV016Test.php` runs **only on the exact pinned loopback disposable CI DB** and installs the previously developed `Tests\Support\LearningContextSqlMutationProbe::install(true)` per test. It covers the following controlled SQL operations:

| Source group | Differential corpus | Expected rule |
| --- | --- | --- |
| Plan | title and deadline; publicly visible eligibility revocation | Hash changes require probe increase; ineligible owner capture must immediately deny |
| Active Task | title, progress, timestamp, active→done→todo transitions, delete | Every hash revision requires probe increase |
| Non-hashed Task fields | `description` direct-SQL-only and same-value title writes | Both full hash and probe remain unchanged |
| Native practice | unapplied score, apply, applied score/weakness/assessment edits, revoke/reapply and delete | Unapplied change ignored; each actual eligible historical revision increases probe |
| MCP approved result | pending-only update, approve, correct count/tags, revoke/reapprove and delete | Pending-only mutation ignored; every approved-history transition advances probe |
| Bulk Task write | 12 active Task rows updated by one SQL statement; then a failed 12-row update | Exactly 12 increments, and rollback restores both full history and probe |
| Owner eligibility | direct `is_public=true` | Old authorized capture is denied, irrespective of counter |

Every case validates `source_revision` **before and after** the SQL operation, and compares the original epoch to the probe result. If the historical hash changed and the DB probe failed to increment, CI must fail. This is a bounded corpus only—not a proof that all possible columns, replica engines, triggers, bulk migrations, arbitrary SQL, transitions or unseen writer paths are covered.

The existing v0.14 and v0.15 suites remain required, including independent physical-connection Task/ledger lock inversion test and ephemeral DDL teardown. CI checks there is **no experimental ledger table** left afterward.

## Lock-order and correctness HOLD

The v0.15 NOWAIT regression proves opposite lock prerequisites are possible: one transaction can hold a Plan ledger row then request a Task row, while another can hold the Task row and request the ledger row through an AFTER UPDATE trigger. This is a **credible DB deadlock risk**; v0.16 does not change the lock ordering, does not trigger nondeterministic deadlocks, and does not add retries to production runtime. The probe and existing app-side Epoch are deliberately separate, so no double-counting or version-protocol migration is implied.

The current v0.7 bounded hash with per-capture owner/private Study eligibility and final `isCurrent()` checks remains the only authoritative freshness check. Do not rely on a trigger epoch to authorize messages or omit historical validation. Raw study content is never written to the experimental probe. Database-specific constraints (MySQL FK cascade non-triggering, PG trigger privileges, MySQL definer/binlog trust, DDL rollback/cleanup, non-monotonic lifecycle of duplicate IDs, timestamp precision, task reparent owner scoping, and archived historical rows) still require separate review.

**Decision:** research evidence for a possible future versioned source projection. No product release or server/database deployment is authorized by this test.

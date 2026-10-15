# Learning Context Watch v0.15 — filtered SQL probe and lock-order risk review

Date: 2026-10-15 JST. **Research-only, ephemeral tests on pinned disposable MySQL 8.4 / PostgreSQL 17; no production migration, runtime code, Aiven DDL, scheduler, notification or Render change.**

## Goal and experimental design

v0.14 (#750) proved a database-maintained transactional counter can observe direct SQL changes and rollback correctly. That probe deliberately increments on **every** relevant table DML, even when the authoritative v0.7 Study source hash does not change. v0.15 keeps the existing unfiltered probe **unchanged by default** and adds an opt-in, deliberately conservative `install(true)` filter **in PHPUnit-only support code**. It measures three fixed workloads: no probe, unfiltered probe, filtered probe. Every condition performs 72 identical type-of-operation direct SQL UPDATE statements; DDL and test fixture construction are excluded from the timer. CI logs report observed milliseconds; there is no absolute performance acceptance threshold due to shared-host variance.

## Filter boundaries

Only source tables already covered by the v0.14 synthetic ledger are instrumented. Counts are Plan-local and store **no question/answer, grading rationale, actual Plan/Task title, user identity or access credential**.

- `plans` UPDATE: **always count**. Any authorization or freshness-related field may change, including `updated_at`; no attempt to infer Plan eligibility from a trigger.
- `tasks` INSERT/DELETE: count only when status is `doing`, `todo` or `paused` (eligible for the current v0.7 Task history).
- `tasks` UPDATE: count only if either old or new status is eligible **and** any of `plan_id, title, status, progress_percent, updated_at` changes. This includes entering/leaving eligibility and movement between Plans; it avoids unnecessary increments for direct SQL description-only or exact same-value edits where no hashed column/timestamp changes.
- `study_practice_attempts` INSERT/DELETE: count only if `applied_at` exists; UPDATE: count if applied either before or after, **even if no revision-relevant column changes**. Thus it still overcounts harmless edits to already-applied records. The filter does not authorize who applied it.
- `mcp_study_result_proposals` INSERT/DELETE: count only when `status=approved` and `decided_at` exists; UPDATE: count when old or new qualifies; a pending-only mutation is ignored, an approval/revocation boundary is counted. Membership/owner checks remain outside this proof.
- Old/new Plan ID moves are recorded on both sides when the source update passes the relevance predicate. These counters are not permission proof, and no output is used to deliver a Watch message.

### Important revision coupling

The current hash includes `tasks.updated_at`, `plans.updated_at`, and the timestamps of included study results. Eloquent updates to Task description often update `updated_at`, which **must** count despite not being a semantic learning progress change. The no-count example uses `DB::table('tasks')->update(['description'=>...])` only, leaving `updated_at` unchanged. Changing the Source Capture schema may invalidate these predicates: the filter is experimental, not a durable production contract. Direct source edits cannot be safely categorized without authorization and provenance.

## Experimental tests

`tests/Feature/LearningContextSqlMutationProbeV015Test.php` (only under exact CI guard) adds:

1. Non-hashed Task description and completely unapplied practice insert/update/delete → no filtered increment; applied practice INSERT and SQL score correction and Task progress change → increment and full source hash invalidation where expected.
2. Completed/inactive Task INSERT/UPDATE/DELETE ignored; status boundary active↔inactive incremented.
3. 72 synthetic repeated description SQL updates under no trigger, unfiltered, and filtered trigger. Print all three elapsed milliseconds and exact unfiltered +72 vs filtered +0 mutation counts. **No fabricated throughput/speedup claim**, no pass/fail on wall-clock duration.
4. Two real physical connections with intentionally crossed locks: one holds the Plan's probe ledger row, the other holds the Task row. Both `FOR UPDATE NOWAIT` attempts to acquire the other's held resource must fail; this demonstrates the **AB/BA lock-order hazard**, not an actual database-deadlock outcome or guaranteed deadlock frequency. Nested savepoints isolate the expected PostgreSQL lock exceptions. Both sessions rollback, leaving Task and ledger unchanged.
5. Existing v0.14 unfiltered direct SQL historical correction/delete and transaction/ledger contention suites remain required. PostgreSQL/MySQL job verifies experiment ledger table is removed after *both* suites.

## Review / HOLD

Filtering could reduce write amplification but a trigger still adds extra row locks. In particular, Plan Review may lock Plan then write Plan-level epoch and then update a Task (Plan→ledger→Task), while direct SQL updates Task and its trigger then acquires the ledger (Task→ledger): **a plausible deadlock cycle**. This experiment exposes the inverted lock prerequisites without deliberately inducing nondeterministic deadlocks in CI. BEFORE any DB trigger migration, the lock protocol must be unified or redesigned and subjected to parallel-process deadlock/retry, bulk-update, timeout, capacity and database-privilege tests.

Other material blockers:

- MySQL foreign-key cascades bypass child-table DELETE triggers. Source-wide deletions and migrations require explicit deletion/retention semantics.
- Owner/collaboration authorization epochs, Study-only eligibility, historical score correction provenance, opt-in, retention and revoke-time cancellation are **not** captured by a generic row counter.
- Comparing one epoch does **not** materialize the eight-item projection or authorize a private Watch delivery; v0.7 bounded full-history hash stays authoritative.
- The probe's filtered predicates are conservative against today's v0.7 hash, but not a complete formal equivalence proof for all writes, all engine collations, nullable JSON, integer overflows, concurrent transfers, `TRUNCATE`, or arbitrary triggers.
- No DB functions or triggers are installed in any persistent/staging/production database. Both `CANOVIA_LEARNING_CONTEXT_EPOCH_SHADOW_ENABLED` and `CANOVIA_LEARNING_CONTEXT_WATCH_PERSISTENCE_ENABLED` remain OFF by default.

**Decision:** Continue experimental lock ordering and transaction/deadlock recovery research, not production integration. Never auto-enable Watch or treat filtered source epochs as a replacement for historical fingerprints.

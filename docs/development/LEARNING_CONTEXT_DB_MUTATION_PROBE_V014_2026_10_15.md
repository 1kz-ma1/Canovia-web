# Learning Context Watch v0.14 — disposable SQL source-mutation probe (research-only)

Date: 2026-10-15 JST. **No production DDL, no schema migration, no shadow epoch replacement, no Render deploy.**

## The hypothesis tested

The v0.7 authoritative Study Context source revision currently hashes all eligible source history (up to 256 active Task and 512 applied native / owner-approved external practice rows). This is deliberately O(N) with fail-closed caps. The opt-in v0.9–v0.13 app-instrumented source epoch can miss direct `DB::table()->update/delete` operations and history rewrites that do not call a PHP service. A **database-enforced, transactional mutation counter** may detect these paths even if application code bypasses the source epoch.

Do not confuse a DB mutation counter with correctness of a personal learning snapshot. Full payload validation, consent, actor identity, eligibility, provenance, non-duplication and legal retention remain separate.

## Implementation: ephemeral experiment, not an app feature

- `tests/Support/LearningContextSqlMutationProbe.php` is loaded **only from PHPUnit** and refuses to create/drop triggers or tables unless: `APP_ENV=testing`, `CANOVIA_LEARNING_LOCK_DISPOSABLE_CI=1`, DB host exactly `127.0.0.1`, database `canovia_learning_watch_lock_ci`, and driver/port exactly PostgreSQL/5432 or MySQL/3306. No new Laravel migration and **no runtime service route**.
- Creates an isolated `learning_context_probe_epochs` ledger in disposable CI only: one atomic monotonic counter and one 60-character operation *code* per plan ID. **No user IDs, Task titles, score, grade rationale, prompts, evidence, question or answer content**.
- Installs PostgreSQL `AFTER ROW` trigger function with `INSERT ... ON CONFLICT DO UPDATE` and corresponding MySQL `AFTER ROW` triggers with `INSERT ... ON DUPLICATE KEY UPDATE`. Covers `plans` UPDATE and INSERT/UPDATE/DELETE on `tasks`, `study_practice_attempts`, `mcp_study_result_proposals`.
- When a source row's `plan_id` is rewritten, increments both old and new Plan ledgers; the probe does not attempt to prove the source plan change was authorized.
- Source write and ledger increment share a real DB transaction: SQL rollback removes both. A separate physical connection holding the probe row lock must cause source writes to time out rather than commit an unlogged update. DB counter overflow should cause the source statement to fail (not wrap silently), a future proof case.
- The probe deliberately counts **all** DML on these tables, including unapplied attempts and Task description-only changes that do not enter the v0.7 full-history checksum. This yields false positives and write amplification. A counter movement therefore **never** signals actual learning progress or authorizes alerts.
- Teardown drops all experimental triggers, PostgreSQL function, and probe ledger after each test; CI confirms the experimental table is absent after the suite. Any live production installation would require separate owner-approved DDL migration, backup, privilege audit and rollback/deadlock analysis — none is authorized here.

## Test contract

The dedicated real-db test file `tests/Feature/LearningContextSqlMutationProbeV014Test.php` runs on GitHub Actions disposable PostgreSQL 17 and MySQL 8.4, after ordinary migrations and existing Plan-first lock suite:

1. Mutate a past applied native Study attempt *outside* the visible recent-eight window via direct SQL; correct score, then delete; each action advances the DB probe and invalidates the authoritative source revision without altering the projected snapshot.
2. Roll back a direct SQL grade correction; source row, probe epoch and authoritative digest all remain unchanged.
3. Reparent a Task between two existing Plans; both ledgers change and both original source digests invalidate, then source DELETE is counted.
4. Change an unapplied grade and an unprojected Task description; probe **over-counts** while authoritative hash remains identical, proving that probe cannot replace snapshot inference.
5. Direct-SQL `plans.is_public=true` revokes capture, and the probe counts mutation; the owner cannot reuse an old source revision as a security token.
6. Hold the ledger row from a separate physical connection; a direct Task SQL update times out, cannot commit, and after release commits with exactly one increment.
7. Correct then revoke then delete an approved external result directly; probe counts each operation and currently permitted Source Capture sees old result history invalidate.

## Known gaps — promotion remains on HOLD

- **DB isolation and lock cycles:** MySQL/PostgreSQL row-trigger ledger locks can introduce Task→ledger or Attempt→ledger waits that differ from the app's Plan→Task/Attempt→shadow-ledger lock order. Deadlock retries, contention under bulk updates, replication/privilege restrictions (MySQL trigger `log_bin_trust_function_creators`, Aiven privileges), connection-pool behavior and migration/rollback windows require additional audits.
- **Cascade semantics differ:** MySQL FK cascading DELETEs do **not** invoke child-table triggers; PostgreSQL behavior differs. A deleted Plan is no longer a valid capture anyway, but cascades/retention and ledger garbage collection require explicit design.
- **Change granularity and overflow:** The overinclusive probe counts non-eligible inserts/edits, bulk updates one per row, same-value SQL updates, and private data outside the source hash. It has no owner/consent snapshot and cannot distinguish historical mistakes, harmless corrections, or actual learning change. A trustworthy design would need an eligibility-aware, audited mutation journal and handling for absent/partial legacy ledger rows.
- **Counter alone cannot replace O(N) projection:** A monotonically increasing value only indicates a potential change; constructing the eight-item Snapshot and correctness/eligibility proof still needs appropriate source reads, or a separately validated materialized projection + owner-bound version protocol.
- **No automatic Watch/push/notifications/billing/API/MCP changes.** The current source hash is unchanged and remains the only freshness check. Experimental SQL probe does not feed the existing `learning_context_source_epochs` counter; the two are not equated. Do not install these triggers in Aiven, staging, Render, or any non-disposable database.

## Next decision

Measure source write overhead under multi-user concurrency and identify minimal snapshot-relevant trigger predicates, then separately review an **opt-in, versioned snapshot cache** and consistent owner/auth-epoch fence with retention and replay rules. Only after historical correctness, permission changes, failure recovery and rollback are proven should a real migration be proposed. This PR is strictly a proof-of-concept of engine behavior, not proof of safe production deployment.

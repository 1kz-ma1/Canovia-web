# Learning Context SQL probe v0.17 — uncontrolled Task-first writer and idempotent replay

Date: 2026-10-15 JST. Lane: Intelligence. This is **research in CI disposable MySQL 8.4/PostgreSQL 17 only**, not an approved schema, migration, retry service or rollout.

## Source and scope

- v0.14 (#750): disposable SQL mutation journal.
- v0.15 (#751): experimental conservative filter and NOWAIT reversed-lock hazard.
- v0.16 (#754): source fingerprint differential corpus against authoritative full-history capture.
- v0.16b (#755): real independent PDO deadlock, DB-selected victim rollback, cooperative Plan-first transaction completion.
- **v0.17:** model the as-yet-unresolved **uncontrolled direct Task UPDATE** that obtains Task before a trigger can modify the Plan ledger, plus a *separate*, synthetic transactionally journaled same-key replay experiment. Never conflate success of the latter with a solution to the former.

## Experiment 1 — uncontrolled Task-first inversion

Run two OS processes and independent real DB connections, with a bounded synchronized barrier:

- App-side example **A** obtains Plan FOR UPDATE, then synthetic probe ledger FOR UPDATE, and writes a synthetic ledger marker. It subsequently attempts Task FOR UPDATE.
- Uncontrolled SQL example **B** directly updates Task description, acquiring Task before any AFTER trigger and without app policy. It subsequently issues a direct SQL Task progress UPDATE; the filtered AFTER trigger must obtain the ledger while B still holds Task.
- Require exactly **one recognized deadlock victim** (PostgreSQL 40P01 or MySQL 40001/1213) and one commit; a lock timeout or unrecognized SQL error is a failure.
- DB-chosen victim is nondeterministic. Require rollback of all writes by the victim, including marker, description and Task progress; require exact ledger epoch (+1 only when raw SQL wins) and authoritative source fingerprint change only when progress UPDATE actually committed.
- This intentionally reproduces **Plan → ledger → Task versus Task → ledger**, not a solution. Application Plan-first alone **does not ensure** that arbitrary external SQL updates respect Plan-first.

## Experiment 2 — cooperating same-key replay

A disposable-only table learning_context_probe_requests is created by the test harness, **not through app migrations**. It stores a random opaque request key, synthetic Plan ID, Task ID and an operation fingerprint; no personal text, answer, owner credentials or tokens.

Two independent processes simultaneously try the same synthetic Task progress increment under a **fresh** full DB transaction:

1. Lock the Plan FOR UPDATE; read the journal via locking SELECT to avoid a stale repeatable-read snapshot.
2. If the same key already exists with the exact Plan/Task/payload fingerprint, return replayed **without any additional Task/epoch write**.
3. If the key exists with different payload, return rejected, no mutation. No data-dependent side effect.
4. On a new key only, lock Task, increment progress, and INSERT the request journal in the **same** DB transaction (the filtered Task trigger increments the probe ledger).
5. Require exactly one applied + one replayed, one Task increment, one probe epoch increment, one journal row. Running the exact same key again yields two replayed. Running the key with a different payload yields two rejected. Verify source fingerprint is unchanged on all repeats/mismatches.

**This is not automatic retry of a DB deadlock.** It establishes a limited same-Plan transactional deduplication primitive only. Actual production-safe retry additionally needs full business validation at each new transaction, verified actor/permission status, unique request identity scoped to the actor and operation, bounded exponential retry policy, authorization expiry handling and explicit outbox/external side-effect fencing.

## Safety and acceptance

- CI-only gate on main/worker: CI=true, APP_ENV=testing, CANOVIA_LEARNING_LOCK_DISPOSABLE_CI=1, 127.0.0.1 exact local port, canovia_learning_watch_lock_ci, canovia_ci user, empty DB_URL; reject before DB connection otherwise.
- Per-test isolated synthetic Plan/Task and ephemeral journal; abort on pre-existing table; process timeouts bounded, subprocesses terminated in finally; probe, ledger, synthetic journal removed after test including failures. CI must prove both scratch tables absent.
- Separate MySQL 8.4 and PostgreSQL 17 matrices; real deadlock and exact commit-level invariants; keep existing v0.14/v0.15/v0.16/v0.16b/SQLite checks intact.
- Update full-history Source Capture and permissions **neither** via this experiment; source revision and current owner authorization remain authoritative.
- Report exact PR HEAD CI, security boundary, log warnings, and deploy/device separately. Test design or single DB pass is insufficient.

## HOLD — production

No runtime PHP changes, schema migration, persistent trigger, database-wide lock policy, Render/Aiven/staging/production access, Watch switch, background monitoring, notification, request mutations, entitlement, MCP permission or real Plan data. Direct SQL source writes, Plan moves, bulk updates, foreign-key cascades, MySQL/PostgreSQL replication, external effects and multi-Plan idempotency cannot be declared solved. **Production trigger and automatic Watch rollout remain NO-GO.**

Files: tests/Support/LearningContextUncontrolledWriterV017.php, tests/Feature/LearningContextUncontrolledWriterV017Test.php, .github/workflows/learning-context-uncontrolled-v017.yml.

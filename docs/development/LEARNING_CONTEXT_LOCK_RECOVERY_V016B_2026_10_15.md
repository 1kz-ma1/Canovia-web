# Learning Context SQL Lock Experiment v0.16b — deadlock rollback and Plan-first recovery

Date: 2026-10-15 (JST). Lane: Intelligence. **CI-only disposable database research; NOT a production rollout.**

## Position in the implementation

- v0.14 (#750): ephemeral AFTER ROW triggers catch direct SQL mutations on a synthetic Plan-scoped ledger.
- v0.15 (#751): optional source-relevance predicate, three-way 72-DML observational timings and **NOWAIT-only** demonstration of opposing ledger/Task locks.
- v0.16b: **two separate OS processes / physical PDO connections** deliberately form a real blocking cycle on a synthetic Task and an ephemeral ledger. Capture the DB's chosen deadlock victim and confirm its **first SQL write** was rolled back; then run two new transactions acquiring **Plan → Task → ledger** in common order and confirm both commit. Independent of the v0.16 differential-fingerprint branch; neither changes runtime behavior.

## Acceptance contract

1. Only run when CI=true, APP_ENV=testing, CANOVIA_LEARNING_LOCK_DISPOSABLE_CI=1, explicit 127.0.0.1, port 3306 or 5432, DB canovia_learning_watch_lock_ci, user canovia_ci, and empty DB_URL. Parent and children independently reject other targets **before connecting**. No persistent DB, real users or production secrets.
2. PHPUnit installs the existing optional filtered probe and constructs synthetic Plan/Task rows. Worker A locks/writes the ledger while worker B locks/writes an un-hashed Task description. After both signal ready, they block on the other's row. Require **exactly one** database-reported MySQL SQLSTATE 40001/vendor 1213 or PostgreSQL SQLSTATE 40P01 deadlock victim **and exactly one commit**. A lock timeout is not a pass.
3. Confirm the victim's earlier SQL write rolled back, the winner's write survived, progress is unchanged, and no false epoch increment occurred.
4. In **fresh** independent transactions, both workers take Plan → Task → ledger, then each increments synthetic Task progress once. Both must commit, with exact +2 progress and +2 ledger epoch. This is **controlled resubmission**, not automatic unsafe replay of half-executed work.
5. Bound SQL and filesystem-barrier waits, terminate subprocesses on test failure, uninstall all temporary triggers/ledger and have CI verify the table is gone. Preserve v0.14/v0.15 and SQLite regressions.
6. Record exact PR HEAD and actual matrix outcomes; a PR or test design alone is not evidence of successful real-DB execution.

## Interpretation and limits

**Plan-first is a candidate for cooperating application writers only.** Uncontrolled direct SQL Task UPDATE obtains a Task row lock **before** AFTER triggers can acquire another lock. A trigger cannot simply impose Plan-first on arbitrary direct writes. Even a green experiment does not prove all-writer deadlock freedom, rate/capacity, exactly-once mutations, safe automatic retry, reparenting, FK cascade correctness, production DB privileges or external side effect rollback.

A deadlock victim must abandon its whole transaction. Safe retries require separate idempotency keys, validation, authorization checks and repeatable transaction boundaries before ever affecting user state. PostgreSQL and MySQL may select different victims.

## Explicit HOLD

Full-history Source Capture fingerprint plus actor/Plan authorization stay authoritative. Synthetic epochs never authorize a Learning Context notification. **No Laravel runtime writer change, migration, Aiven/Render/production/staging DDL, Watch scheduler, notification, entitlement, MCP access, or private Plan read.** Keep production trigger introduction NO-GO until uncontrolled writers, Plan move lock ordering, FK cascade bypass, bulk performance, replication, and bounded idempotent retry are designed and independently proven.

Files: tests/Feature/LearningContextSqlMutationDeadlockV016BTest.php, tests/Support/LearningContextDeadlockWorkerV016B.php, .github/workflows/learning-context-sql-deadlock-v016b.yml.

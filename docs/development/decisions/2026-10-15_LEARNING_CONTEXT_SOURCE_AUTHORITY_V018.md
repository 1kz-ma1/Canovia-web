# D-2026-10-15-01 — Learning Context source authority (v0.18)

> Decision date: 2026-10-15 JST
> Decision owner: Canovia product owner (scoped continuation of Intelligence investigation); technical policy recorded for development.
> State: **ACCEPTED for development authority boundaries; NOT authorization to deploy, enable, notify, or migrate production.**
> Domain: Intelligence / private Study Plan / Learning Context Watch
> Linked operating policy: [Product Decision Memory](../PRODUCT_DECISION_MEMORY_OPERATING_SPEC_2026_10_13.md)
> Implementation basis reviewed: development main `9aced51c5cf1284e91dbf4f0d18be8fd43594af9`

## Problem and decision boundary

Canovia needs to detect revisions to a **private, owner-scoped Study Plan** including historical Task/practice corrections without mistaking merely unchanged recent sample items for unchanged source data. Existing capture hashes all eligible source rows up to hard bounds (256 active Tasks, 512 practice records combined), but each authoritative check rereads history, consuming O(N) database work and failing closed beyond bounds. A monotonic epoch might eventually allow constant-size freshness comparisons, but **a missed writer creates an unsafe false negative**.

**Decision:** retain `LearningContextSourceCaptureService::capture()` plus `isCurrent()` as the **only authoritative source-revision and freshness mechanism** for this scoped Learning Context flow. Retain the **application-maintained** `LearningContextSourceEpochService` as **shadow-only optional diagnostics**; it may not short-circuit capture or be used by `LearningContextWatchPersistenceService`, permissions, recipient selection, or delivery. **Reject installing the SQL-trigger-based probe as production architecture** on current evidence; keep `Tests/Support/LearningContextSqlMutationProbe.php` strictly inside disposable tests. Both optional instrumentation/persistence switches remain OFF by default. This is a conservative **interim architectural decision**, not a claim that O(N) hashing is the final scalable design.

No application API/schema/service behavior is changed by this decision record and its CI regressions. Scope ends at existing owner-only private Study context; no automatic Watch, callback, scheduler, Push, MCP extension, cross-Plan visibility or Premium gating is approved.

## Alternatives evaluated

| Candidate | Correctness and security | Cost / operating complexity | v0.18 decision |
| --- | --- | --- | --- |
| **A: current full-history hash** | Detects included old-row corrections/deletions; owner/private/Study re-evaluated on each capture; denies beyond fixed row caps | O(N) per capture; DB work scales with history and concurrency; no unlimited polling | **AUTHORITATIVE**, only for already bounded internal use |
| **B: application shadow Epoch alone** | Atomic when writers hold Plan lock and call `record()` in one transaction; **misses raw/uninstrumented historical writes** | Cheap lookup with low added row write cost on cooperating paths; auditing every legacy writer is hard | **DIAGNOSTIC ONLY**; never authoritative |
| **C: DB AFTER ROW filtered trigger/ledger** | Observes many SQL mutations and rollbacks, but lock inversion with Task-first writers was reproduced; FK cascades, Plan moves, permissions, replication still unresolved | Write amplification, migrations, trigger privileges, two-engine behavior and DB-owned operational surface | **NOT ADOPTED for production** |
| **D: append-only domain change log / transactional outbox** | Potentially complete if every writer and historical correction has an audited, authorized event; can decouple refresh | Needs versioned schema, actor/Plan authorization, writer census, backfill and exactly-once/outbox processing design | **FUTURE CANDIDATE; not implemented** |
| **E: persisted materialized aggregate/fingerprint** | Could bound capture cost only with audited atomic maintenance, historical backfill and source-eligibility proofs | Migration/invalidation/backfill plus consistency and hot-Plan contention | **FUTURE CANDIDATE; not implemented** |

## Evidence catalog — bounded synthetic experiments, not product rollout

- [#730](https://github.com/1kz-ma1/Canovia-web/pull/730): historical-hash Source Capture foundation; see current service and source caps.
- [v0.9 shadow specification](../LEARNING_CONTEXT_SOURCE_EPOCH_SHADOW_V09_2026_10_15.md): original partial app-side epoch and explicitly uninstrumented historical update; current `inspect()` returns `authoritative=false`.
- [#750](https://github.com/1kz-ma1/Canovia-web/pull/750): v0.14 disposable AFTER ROW raw-SQL and rollback experiments.
- [#751](https://github.com/1kz-ma1/Canovia-web/pull/751): v0.15 filtered probe, lock inversion and one-time **observational** 72-update comparison.
- [#754](https://github.com/1kz-ma1/Canovia-web/pull/754): v0.16 fixed differential corpus tested against full historical source hash; this is NOT a formal proof for arbitrary SQL.
- [#755](https://github.com/1kz-ma1/Canovia-web/pull/755): v0.16b actual SQL deadlock victim rollback and coordinated Plan-first completion on disposable PostgreSQL 17/MySQL 8.4.
- [#756](https://github.com/1kz-ma1/Canovia-web/pull/756): v0.17 **uncontrolled** Task→AFTER trigger→ledger versus Plan→ledger→Task actual deadlock; separate synthetic keyed replay proves bounded deduplication, **not** production retries.
- Code: `app/Services/LearningContextSourceCaptureService.php`, `app/Services/LearningContextSourceEpochService.php`, `app/Services/LearningContextWatchPersistenceService.php`, `config/intelligence.php` and their feature tests.

**Performance:** The 72 direct SQL UPDATE runs in v0.15 observed PostgreSQL baseline/unfiltered/filtered 53.406/58.857/57.352 ms and MySQL 43.921/451.758/63.161 ms in one shared CI run. **No throughput, p95/p99, operational savings, or production superiority inference.** We have not measured real Study Plan fleet size, cap exceedance rate, capture p50/p95, DB resource consumption, or user notifications.

## Operational invariants and safety guardrails

1. Actor/Plan permission is checked on EACH capture and AGAIN when comparing a snapshot; an epoch, cache, stored consent or precheck cannot replace current owner/private/noncollaborative Study eligibility. A Plan changing hands or visibility must fail closed.
2. `source_revision` must cover ALL eligible Task and approved native/MCP practice historical rows within the bounded source caps, **not only eight visible items**; old revisions and deletes invalidate earlier hashes. Beyond bounds return unavailable, not an incomplete digest.
3. An unchanged shadow Epoch is NOT evidence the actual source is unchanged. An incremented shadow Epoch alone is also NOT evidence that a user-visible or actionable change occurred. Tests must cover **both divergences** while Watch is explicitly enabled only inside a test.
4. `advance()` remains a **trusted explicitly invoked internal function**; no new route, scheduler or delivery flow. Even when explicitly opted in, review candidates are `review_only`; `notification_eligible=false` and `mutation_performed=false` remain invariant.
5. The two flags in `config/intelligence.php` default FALSE. No new experiment alters environment variables on staging or production. The Source Epoch table already exists as a development-main migration, but its presence and PR merge do **not** authorize running production migration.
6. Treat raw SQL, bulk updates, rollback, historical correction, reparenting, FK cascades and privilege revocation as distinct integrity risks. Avoid retries inside a transaction after DB deadlock; future request-level retries require idempotent audited entrypoints, fresh authorization, side-effect fencing and bounded policy.

## Hypotheses / measurements / revisit

- **Expected behavior** (bounded regression): a raw SQL correction with an unchanged shadow Epoch still changes authoritative hash, while a diagnostic Epoch increment with unchanged source does not trigger a review. No automatic notification occurs in either case.
- **Measurement baseline:** historical hash row caps 256 active Tasks + 512 eligible practice rows; real per-Plan row distribution and runtime/lock p95 are **UNMEASURED**. Synthetic SQL microbenchmarks above cannot supply that baseline.
- **Revisit only when** (a) an actually observed capped-out owner Study Plan or measured capture latency warrants investment; (b) a complete writer/eligibility/transaction inventory and safe cross-engine source journal design is available; or (c) a release-backed incident exposes a correctness gap in the historical hash. Benchmark real capture p50/p95/p99, bounded concurrency, source row distributions and DB costs before comparing alternative architectures.
- **Required future acceptance:** documented stable source schema, all-writer audit (including raw SQL/history deletes, Plan transfers, FK cascades), permission/opt-out/revoke fence, no stale hash false negatives, rollback/crash/replay, hot-Plan throughput, DB privileges, backup/restore, deployment rollback, and a separately approved owner opt-in + delivery privacy review. Never auto-enable on cost alone.
- **No claimed outcome:** There is no production latency baseline, real notification outcome, customer deployment, manual owner release GO, or device evidence. This decision prevents unsafe premature promotion and can be superseded after independent new evidence.

## Implementation evidence / follow-up ledger

At document creation: architecture choice recorded; no runtime code, migrations, deploy or product availability changed. **Implementation PR: [#757](https://github.com/1kz-ma1/Canovia-web/pull/757)**, adding the v0.18 SQLite authority regression and keeping PostgreSQL/MySQL source-probe suites. The PR's exact-head workflow results and merge commit, rather than this decision record, determine CI integration status. This record is **not** evidence of deploy, device verification or permission to enable Watch.

**Next scoped improvement:** first instrument **read-only, privacy-safe aggregates** for the actual capped-hash path (capture duration bucket, capped-out count, eligible source cardinality) only after a separate telemetry/privacy/performance spec; no PII, raw contents, user-linked logs, automatic external send or new databases. Prefer delaying such work until it assists near-term release validation.

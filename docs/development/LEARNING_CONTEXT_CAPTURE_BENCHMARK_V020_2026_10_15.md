# Learning Context v0.20 — synthetic capture cost and correctness benchmark

Date: 2026-10-15 JST. Lane: Intelligence. Test-only, pinned ephemeral SQLite/MySQL 8.4/PostgreSQL 17.

Source authority: [v0.18 decision](decisions/2026-10-15_LEARNING_CONTEXT_SOURCE_AUTHORITY_V018.md). Instrumentation scope: [v0.19 local-only diagnostics](LEARNING_CONTEXT_CAPTURE_DIAGNOSTICS_V019_2026_10_15.md).

## Question

Does growing a **synthetic** private owner Study Plan's eligible Task or approved native Study history increase the cost of the **existing** `LearningContextSourceCaptureService::capture()` full-history revision fingerprint? What is the fixed-cost effect of enabling the in-memory, local/test-only histogram collector? Does the 256/512 hard cap still return unavailable with no truncated fingerprint on each DB engine?

This benchmark is repeatable code and transparent environment-qualified CI evidence; it does **not** establish a real-user latency, production throughput, percentile SLO, billing cost, or a recommendation to change the source-of-truth architecture.

## Fixed workload and statistics

- File: `tests/Feature/LearningContextCaptureBenchmarkV020Test.php`; one isolated synthetic owner with two separate private Study Plans.
- **Task curve:** eligible Task counts `0, 8, 32, 128, 256` with zero applied native practice history. Each workload is measured only after its rows are committed and a successful warmup.
- **Practice curve:** applied native Study history counts `0, 8, 128, 512` with exactly one stable eligible Task. A raw but synthetic JSON assessment is generated for each practice row. Count is the combined history cap for the current source implementation, although the benchmark's practice records are native only; **the mixed native+MCP-approved workload is not measured**.
- Two timing variants at each size: `off` (v0.19 diagnostic disabled), `on` (v0.19 enabled in `APP_ENV=testing`). A warmup is excluded from latency summaries; each variant then receives exactly **21 wall-clock nanosecond measurements**, with alternating measurement order to reduce position bias.
- Report `p50`, `p95` and `max` in milliseconds from 21 samples per variant using **nearest-rank** order statistics. These are test-run sample descriptors, **not production quantiles**. Printed fixed fields: database driver, test dimension, synthetic count, diagnostic mode, sample count, three timing summaries. NO actor/Plan IDs, revision hash, SQL, real content, errors, payload text or individual per-call values.
- Comparisons are **descriptive only**. No performance threshold is asserted, no benchmark automatically chooses an architecture, and shared-host timing jitter is expected. Ingestion or execution costs to construct fixture data, initial migrations and cold-start Composer/cache are excluded. Other consumers, round trips, proxy buffering, long-term contention, indexing/size distributions, backup and host resources are not represented.
- Each sampled result must equal the exact initial `capture()` result for that unchanged fixture, including the `source_revision` and projected last-eight values. A source count change must invalidate the prior full-history hash.
- After 256 Tasks, add a 257th Task; after 512 applied attempts, add a 513th. Both must reject capture and `isCurrent()`; instrumentation's fixed `task_cap` and `practice_cap` counters must reflect **both** denied checks. No partial digest.
- In-memory collector only; flag OFF by default and disallowed in staging/production. No control flow is affected by the synthetic timings.

## Strict execution boundary

- Three fresh GitHub Actions jobs. MySQL 8.4 or PostgreSQL 17 use the pinned **disposable** service `127.0.0.1`, database `canovia_learning_watch_lock_ci`, account `canovia_ci`, exact port 3306/5432 and no DB_URL. SQLite requires `:memory:`. All require `CI=true`, `APP_ENV=testing` and the real-engine jobs `CANOVIA_LEARNING_LOCK_DISPOSABLE_CI=1`. The test independently enforces these constraints **before fixture reads/writes**.
- No `RefreshDatabase` transaction enclosing benchmark samples. Fixture rows are added via explicitly bounded insert chunks of 40; a teardown cleans synthetic study rows/Tasks/Plans and user. No SQL triggers or probe DDL, no external source or provider calls. GitHub's ephemeral service container is removed after each job.
- Workflow path filters include the full-history capture service, local histogram, benchmark test, this specification and workflow. A future change to capture logic should rerun the benchmark, without adopting a false speed threshold.
- Evaluate and link exact PR-head CI, not default branch or stale run. The workflow prints synthetic observations directly in CI logs; these logs are reviewable as **ephemeral performance evidence only**. No artifacts of personal or private content should be uploaded.

## Outcome and remaining gaps

At specification creation the benchmark has **not run**; do not invent measurements here. After green CI, cite the run link and representative values with database name, synthetic fixture sizes and 21-sample qualifiers rather than claiming production improvement.

Other workloads not tested: mixed approved MCP + native counts, corrections/deletes during capture, independent raw SQL writers, revoked or collaborative Plans, hot concurrent Plan locks, queue/HTTP/network latency, device flows, broad PHP/DB version matrix, cold starts and realistic assessment sizes. The v0.14–v0.17 trigger research still has production NO-GO due to Task-first deadlocks; v0.18 full-history hash is authoritative, v0.19 diagnostic collector is local/test-only. **No migration, runtime Watch enablement, persistent/production telemetry, Aiven/Render operation, delivery, subscription, MCP permission or deployment is authorized.**

**Next gate:** decide whether real capture overhead/cap incidence warrants a separate opt-in privacy-safe measurement plan after public readiness. Even large synthetic timings alone are insufficient to change the production data authority model.

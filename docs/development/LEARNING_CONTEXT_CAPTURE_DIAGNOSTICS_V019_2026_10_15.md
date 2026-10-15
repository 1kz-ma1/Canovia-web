# Learning Context v0.19 — local capture-cost histogram (development/CI only)

Date: 2026-10-15 JST. Lane: Intelligence. Authority: [v0.18 source decision](decisions/2026-10-15_LEARNING_CONTEXT_SOURCE_AUTHORITY_V018.md). **This is not deployment or production observability.**

## Why this bounded slice

Existing `LearningContextSourceCaptureService::capture()` produces the authoritative, actor-bound historical source fingerprint for a private Study Plan, and fails closed when source counts exceed 256 eligible active Tasks or 512 eligible native+approved Study practice records. Real distribution of Plan sizes, capture latency and cap exceedance rate have **not been measured**. The test-only database-trigger research v0.14–v0.17 must not substitute for that source authority.

This slice adds a **measurement mechanism and synthetic tests**, NOT real-user metrics or a prod logging stream. Preserve the existing O(N) capture, eligibility checks, result shape, transactional behavior, hash, event semantics, epoch shadow and Watch OFF policy.

## Implementation and allowed data

- `LearningContextCaptureDiagnostics`: plain PHP in-memory histogram attached to each resolved `LearningContextSourceCaptureService`. It is not a singleton, global logger, database writer, cache, queue, endpoint or external publisher. Its `snapshot()` is a local PHP return value, not a route/action. No disk persistence or cross-request aggregator is guaranteed. It may be inspected by a **trusted local test or explicitly controlled development harness** only.
- `CANOVIA_LEARNING_CAPTURE_DIAGNOSTICS_ENABLED`: default false; **hard denied in staging and production regardless of flag**. Only `APP_ENV=local` or `APP_ENV=testing` with explicit true allows collection.
- A finally-bound, best-effort hook measures elapsed `hrtime(true)` for the whole existing capture attempt including SQL and limited projection processing; errors in diagnostic recording are swallowed and **never authorize or change** a capture. With the flag OFF there is no stopwatch, counter update or metrics egress.
- Fixed outcome labels: `captured`, `denied`, `task_cap`, `practice_cap`, `unavailable`, `error`. `denied` also covers ineligible Plan and wrong owner; reasons are deliberately indistinguishable. `unavailable` covers other conservative incomplete validations. `error` merges all thrown capture errors, never records exceptions or SQL.
- Coarse duration bins (nanosecond stopwatch rounded into **under 5ms**, 5–25ms, 25–100ms, 100–500ms, at least 500ms). **These histograms are not measured p50/p95/p99** until enough representative real capture observations are collected with a separate privacy/release review.
- Task source counts: not_counted, zero, 1–8, 9–32, 33–128, 129–256, over cap. Practice source counts: not_counted, zero, 1–8, 9–32, 33–128, 129–512, over cap.
- Only fixed-bin aggregate count totals. No actor/Plan/Task IDs, timestamps, IP/user agent, usernames, Plan title, domain, exam subject, score, weakness, answer, question, opaque request keys, source hash, Exception messages, raw elapsed values, exact source counts, raw SQL or device identifiers. No tags/custom dimensions. A rejected actor does not trigger source row counts.
- Memory lives only in a collector instance and is discarded with its PHP lifecycle. Do not claim this captures traffic across web requests or real users.

## CI acceptance

New SQLite `LearningContextCaptureDiagnosticsV019Test`:
1. OFF flag returns identical capture and zero metrics. A true flag remains denied under `staging`/`production`.
2. Successful authorized capture and denied actor increment only fixed buckets; the full-history hash, visible snapshot and `isCurrent()` stay unchanged.
3. 257 synthetic active Tasks fail closed, with `task_cap` and `over_cap` histogram but no partial digest.
4. 513 synthetic applied practice attempts fail closed, with `practice_cap` and `over_cap` but no partial digest.
5. Unknown outcome text and raw timer/row values cannot become dynamic labels; fixed threshold transitions and privacy-safe shape are checked.

The existing `learning-context-plan-lock-contention.yml` CI path filter must include the new capture service, new histogram class, test, spec and config; its SQLite regression includes this test, while existing real MySQL8.4/PostgreSQL17 lock/probe suites continue unchanged. Exact PR HEAD success for *all* required checks remains mandatory before integration.

## Explicit HOLD / next evidence

No migration, Render/Aiven/staging/production change, real personal Plan reads, Watch opt-in route, background polling, scheduler, notification, MCP permission, browser events, analytics exporter, telemetry transport, retained measurement database, external AI or premium feature is added.

This mechanism cannot quantify **real-world** efficiency until separately approved sampled/aggregated observations, collection purpose, access boundaries, deletion/retention and safe collection scope are defined and verified. In-memory diagnostics are per service instance; CI numbers are only synthetic. **No decision to replace the authoritative full-history hash or activate DB triggers is authorized.**

Future phase (separate PR and privacy/security review): decide whether an opt-in, coarse-grained, rate-limited, nonidentifying aggregate sink can be collected without logging per-user traces; verify denominator, percentile accuracy, concurrency effects, DB CPU/query cost, cap-rate and user-value relevance. A privacy-safe test snapshot is not consent for production telemetry.

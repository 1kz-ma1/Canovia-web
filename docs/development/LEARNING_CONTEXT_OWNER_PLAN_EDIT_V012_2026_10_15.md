# Learning Context Watch v0.12 — Owner Plan Settings Lock / Shadow Epoch

Date: 2026-10-15 JST. **Scope: development main only. Production, staging and automatic Watch are unchanged.**

## Confirmed source writer issue

`PlanController::update` previously validated permission on the route-bound Plan, calculated its deadline, domain and specialization defaults from that instance, and updated the Plan **outside** a parent row lock / DB transaction. It could race with Learning Context Watch's `Plan::lockForUpdate` and other Plan-first writers. The owner-only settings update also did not participate in the v0.9–v0.11 experimental Source Epoch for eligible private Study Plans.

The v0.7 authoritative Source Revision hashes Plan title, deadline, category, workspace_domain_override, public/collaborative state and `updated_at`. Eligibility changes are independently protected by a new authorized capture. A sound lock convention must encompass every saved Plan edit and only then reconsider replacing the O(N) historical hash.

## Change

- Retain early access authorization and normal Laravel input validation, but perform all mutable source reads and writes under a `DB::transaction` that locks the **latest** Plan `FOR UPDATE` first. Recheck **owner-only** permission on that locked Plan.
- Recompute `start_date`, `deadline`, domain/specialization and untouched-field defaults using the latest locked Plan, never the earlier route-bound Eloquent state. If a deadline would precede the current start date, fail with field-level validation before any write.
- On actual Plan model field updates, call `LearningContextSourceEpochService::record($live, 'plan_updated')` under the **same transaction**. This is a shadow-only, strict-boolean-OFF-by-default Study-only instrument. A same-value edit does not bump Epoch.
- Keep Plan activity and title-derived Goal Context synchronization **inside** the transaction so they roll back with the Plan if any downstream DB write fails. Existing owner-specific session exception reset for legacy Creative/Development remains a session mutation **after DB commit** only.
- No automatic Plan progress, Task status, AI, learning outcome, external grant or MCP scope change. Existing first-party Plan editing UX and redirect are preserved.

## Verification

- SQLite HTTP coverage: Study owner title + deadline change updates live Plan, changes authoritative source revision, bumps shadow Epoch once, and synchronizes Goal Context title. Same input repeated does not bump. Invalid date returns field-level validation and changes no Plan/Epoch/activity. Unauthorized actor gets 403. Ineligible Development Plan leaves no Study shadow. Explicit OFF flag preserves ordinary Plan editing.
- Real loopback disposable PostgreSQL 17 and MySQL 8.4: the second physical connection holds Plan `FOR UPDATE`; HTTP editor cannot commit a Plan title/deadline, shadow Epoch or related Goal Context until released. After unlock, all commit together. Repeated exact form does not create a second source Epoch.
- Existing Plan Review multi-source tests, Task/Study/Watch regressions, real connection contention and Workspace resume checks stay part of CI.

## Deliberate limitations and HOLD

- **No epoch authority:** uninstrumented Plan transfer, direct SQL/mass updates, old practice correction/delete, practice withdrawal, imports, legacy session/worklog writers and some ownership/eligibility changes remain unverified. A per-Plan Epoch does not prove every mutator calls it.
- An edit that converts an eligible private Study Plan to another domain or public/shared state makes current capture unavailable; the shadow service deliberately skips after-state-ineligible Plans. This suppression is not equivalent to an auditable opt-out/tombstone or notification cancellation proof.
- The regular form has no optimistic browser-version precondition, so two valid owner submissions serialize but later explicitly submitted field values may overwrite earlier ones. The Plan-first lock prevents interleaved half-commits, not all stale-form business conflicts.
- The current full-history digest uses timestamp inputs with finite precision; multiple writes in one second can produce a mismatch between shadow mutation count and digest revisions. Do not use Epoch for delivery, scheduler, premium/push notification or authoritative freshness.
- **Production boundary:** no new schema/migration, no production Aiven DDL, no Render deploy/autodeploy toggle, no OAuth/MCP configuration changes and no personal Plan reads. Both `CANOVIA_LEARNING_CONTEXT_EPOCH_SHADOW_ENABLED=false` and `CANOVIA_LEARNING_CONTEXT_WATCH_PERSISTENCE_ENABLED=false` stay OFF.

## Next engineering slice

Audit Plan transfer, publication/collaboration transitions and remaining source-model/direct SQL correction/deletion writers, then design a database-enforced mutation journal or universal transactional source epochs. Retain the current full eligible history hash until all writers, consent and retention/cancellation gates are independently verified on physical DBs. This internal correctness work does not materially change the public visitor-facing roadmap.

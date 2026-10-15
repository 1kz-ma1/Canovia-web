# Learning Context Watch v0.7 — historical source correction/retraction fence

Date: 2026-10-15 JST · Lane: Intelligence / Learning · Implementation target: development `main`; NOT production deployment.

## Confirmed defect

Previously `LearningContextSourceCaptureService` used **only the latest 8 approved/apply-complete attempts (plus one)** and an eligible-row **count** to form `source_revision`. Editing the score, assessment, weakness or timestamp of a row beyond the 8-item window could leave both count and latest records unchanged; the previously saved review candidate might remain current. The same applied to changes to active Tasks beyond the first eight. This is a correctness gap for any future automatic comparison or delivery. v0.6 (#725) addressed selected writers' Plan locks, not historical lineage.

## v0.7 behavior

- Preserve the **eight-item context projection** shown to the internal Context Detector and the separate, still unchanged MCP Snapshot v1 sharing contract.
- Hash **all eligible source rows within explicit caps**, deterministically by source type and stable ID, including current values and update timestamps. Source categories are: owner-specific `StudyPracticeAttempt` with `applied_at`, owner-specific `McpStudyResultProposal` that is currently APPROVED with `decided_at`, and currently active Tasks for that Plan (`doing/todo/paused`). Foreign owners/Plans, pending/unapplied/rejected entries never enter source data.
- Fingerprint input includes relevant assessment/weakness inputs for **historical correction invalidation only**. The capture *output* contains only SHA-256 revision and the prior bounded task/practice aggregates. Raw assessments, questions, answers, provider evidence and old topic labels are not exported to the Watch baseline/event table.
- Previous source fingerprint version was 2; this is fingerprint **version 3**, remaining on the same `canovia.learning_context_source_capture.v0.2` projection schema, because the shape and access scope are unchanged. A previously stored v2 checksum will change on first explicitly requested comparison, even without a human-facing change. The Watch treats that as `updated_no_candidate`, supersedes any previously open review candidate, and stores the new encrypted baseline; there is **no synthetic improvement, completion, alert or action**.
- No monitoring/scheduler/notification is connected. `notification_eligible=false` remains absolute.

## Strict finite-work and fail-closed budget

- At most **256 eligible active Tasks** and **512 total eligible owner-applied native + owner-approved MCP practice records** per Plan. Only records belonging to the authorized private Study Plan count toward these limits.
- A Plan exceeding either cap returns **null capture** and `isCurrent=false`. It cannot silently downgrade to the latest 8; `optIn` fails, and an existing Watch returns `capture_unavailable` without superseding, advancing baseline or recording new candidates. This is an explicit temporary correctness-over-availability compromise, NOT a scalable long-term event-sourcing/lineage design.
- No full raw question/answer record reads: capture selects `score_percent,weaknesses,assessment,applied_at,updated_at` for applied native sources and aggregates for approved MCP. Large assessment JSON within those bounded rows may still cost CPU/memory; **not suitable for unattended polling** without cost profiling, per-document size budgets or a transactional source mutation epoch.
- Historical reversal before a subsequent capture has no durable tombstone/history; if a source changes A→B→A entirely between comparisons, this scanner may see A only. It proves current-state freshness, not every intermediate event.

## Integrity, privacy and concurrency limitations

The parent Plan is `FOR UPDATE` locked during capture; selected Task/Study writers now follow that order, but **not every legacy or cross-domain writer has been proven to cooperate**. A future source-level epoch, consistent writer convention or transactionally appended event log must cover all Task status/progress/title, applied attempt correction/deletion, owner-approved proposal correction/revocation, Plan transfer and opt-out. Competing writes that ignore Plan locks may invalidate the guarantee until covered. PostgreSQL/MySQL real connection race tests from v0.5–0.6 address the participating paths only.

## Coverage, outstanding writer audit

- v0.7 tests: 12 old native records; changing old score or grading rationale and deleting old row all invalidate the hash while the latest eight projection remains equal. Old approved MCP result score correction/rejection similarly invalidates; active Task beyond first eight changed/deleted invalidates.
- Persisted Watch regression: a previously created review-only candidate is marked `superseded` after the older attempt changes, with no notification or fabricated new study event.
- 257 active Tasks or 513 eligible practice entries: no partial digest, `capture=null`, and old signature rejected.
- Continue later: `GuidedExecutionController::confirmNextAction` takes Task lock before updating `next_action_note`, a cross-domain Task `updated_at` source modification with missing parent Plan lock; not changed in this source-specific PR. Audit remaining direct model writes, admin/manual SQL, imports, Task progress from other interfaces, and correction/retraction paths before auto-watch activation.

## Release boundary and next decision

No DB migration, Auth0/OAuth change, Canovia real personal Plan reads, pricing/entitlement change, Render deploy, production promotion, or MCP exposure. Existing `CANOVIA_LEARNING_CONTEXT_WATCH_PERSISTENCE_ENABLED=false` remains unchanged. A merge to `main` is a development integration only. To unblock *periodic* checks later requires full cross-domain writer audit, scalable revision epochs/retention, consent validity checks, signed-off real MySQL concurrency acceptance and operator profile/cost thresholds. Notifications additionally require channel permission/quiet hours and an explicit separate launch gate.

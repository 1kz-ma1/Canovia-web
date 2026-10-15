# Canovia-owned Learning Context Change Detection v0.1 — internal review only

Date: 2026-10-15 JST
Lane: Intelligence, with Learning as snapshot/source owner and Platform as future notification-policy owner.
Status: **bounded deterministic comparison kernel**. No user notification or scheduled monitoring. No real user/Plan access. No release/deploy approval.

## Product boundary

The owner-approved Learning Context Snapshot v1 (PR #703) gives an external AI a narrow picture of a specific Study Plan. It does **not** transfer the responsibility for tracking important changes to ChatGPT. Canovia will ultimately own change observation, preference-aware advice and notification decisions, while external AI remains an optional dialogue/workflow consumer.

**This slice implements only an internal compare-and-classify kernel.** There is no persistent history/baseline, scheduling, event outbox, notification preferences, read API, new MCP tool, new feature flag, change subscription, user-facing signal or Premium 2 entitlement. A caller that wants automated tracking must first satisfy the later stages below. Existing Intelligence State Change Feedback for V53 traces is a separate implementation and is not retroactively claimed as a Learning Snapshot change-monitoring rollout.

## v0.1 shape and rules

Pure entry point: LearningContextChangeDetectionService::compare(previousInternalCapture, currentInternalCapture).

Each capture is an INTERNAL envelope containing actor_id (positive int), plan_id (positive int) and snapshot (the already-whitelisted LearningContextSnapshot v1 payload). The two bindings must match. **Trusted first-party code must establish that each capture belongs to the actor's currently authorized private Plan before calling it.** This class is NOT an authorization oracle or a safe endpoint for arbitrary user-submitted JSON. Both snapshots must have the expected schema, purpose, delivery and non-raw-evidence flag; no source text or full Eloquent models are accepted. Reject inconsistent sample counts, duplicate Task IDs/topic tags, invalid dates or incorrectly typed fields.

The comparator projects only semantically relevant bounded values:
- Owner-recorded Plan title and deadline.
- Up to 8 active Task IDs, titles, statuses and recorded progress; sort by Task ID to ignore presentation order.
- Latest bounded applied-practice sample counts, averaged/latest scores, external owner-approved source count and truncated marker.
- Up to 5 weakness topic tags and mention counts.

**Ignore** computed_at, practice/study read timestamps and the transient suggestion. A refreshed read with identical facts must remain unchanged; read timestamps are not actual source-event times. SHA-256 fingerprints and a pairwise dedupe fingerprint are calculated IN MEMORY only. They do not prove source authenticity, freshness or historical chronology. There is no durable cross-run replay prevention.

Return statuses:
- blocked: owner/Plan binding, schema, source shape or Plan title continuity cannot be reconciled, no data disclosed.
- unchanged: semantic fingerprints equal.
- changed_no_action: bounded facts changed, but evidence does not justify a review event.
- review_candidate: a narrow human check may be helpful; never an automatically sendable notification.

Up to 4 review event types, all provisional and explicitly **not exam / competence claims**:

| Kind | Necessary predicate | Do NOT claim |
| --- | --- | --- |
| owner_recorded_deadline_changed | An owner-entered date differs | Official IPA exam date was rescheduled |
| recorded_task_paused | Same Task ID changed from another active state to paused and neither Task list is truncated | User stopped studying, completed another Task or missed a deadline |
| recent_practice_sample_changed | Both score samples contain >=3 scored records, are nontruncated and averages differ >=15 points | True long-run ability changed; sample composition is comparable |
| recent_weakness_tag_priority_changed | Both recent samples contain >=3 applied attempts, nontruncated, and leading recorded weakness tag differs | Previous topic was mastered or new topic is objectively weakest |

Returned event values are derived only from approved bounded inputs. Never include raw questions, answer text, task descriptions, rationale, profile, externally sourced IDs/tokens or cross-Plan information.

All outputs strictly mark notification_eligible=false, notification_sent=false, mutation_performed=false, requires_source_revision_recheck=true and requires_owner_permission_recheck=true. **Do not wire this output to mail, push, notification DB/outbox or automatic planning actions.**

## Why this is intentionally conservative

Snapshot v1 has no immutable evidence revision, source event IDs or deletion/retraction lineage. Looking at two rounded aggregates (e.g. 65% -> 45%) does not prove worsening: the most recent sample may have rolled over. The clock of the snapshot read also says nothing about when the underlying change occurred. Missing active Task rows do not prove completion; absent weakness labels do not prove mastery. Until a first-party source-revision fence exists, an internal comparison must never become a push notification.

Changing a Plan title is deliberately blocked pending identity/source reconciliation, even if actor_id and plan_id in the envelope match; no raw old-title leakage when blocked.

## Acceptance and safe next slices

1. Pure local tests: stable time-independent hashes, deterministic dedupe, task order normalization, owner/date/status/sample/weakness conditions, small/truncated sample suppression, wrong actor/Plan, invalid schema/date/count/IDs, no implicit completion, no notification or mutation.
2. **Source-provenance capture**: first-party authorized capture with Plan ownership checks, source revisions, correction/retraction detection, immutable event identity and a safe quality gate. Integrate existing StudyDerivedContextFreshnessGuard principles without equating its existing 12-attempt signature with the complete v1 Context payload. This is NOT done by this slice.
3. **Durable idempotent state and event lifecycle**: separate scoped schema + transactional compare-and-commit, retention, correction/revoke tombstones, replay protection, baseline freshness and migration/rollback audit before any historical tracking.
4. **User-facing notification**: explicit preference, quiet hours, severity/actionability, cooldown, dedupe, mute/revoke, channel/OS permission, explainability, rejected candidate suppression and delivery E2E. Require independent owner approval before activation.
5. **Premium 2**: separate entitlement and multi-Plan decision contracts, actor/Plan policy, human approval of consequential mutations and cost/billing review. Does not follow from the presence of this detector class.

Safety: no new migration, no user data access, no cron/job registration, no OAuth/MCP broadening, no production/Staging/Render/Aiven/Auth0 writes, no subscription change or new model call. Test success demonstrates the pure comparison contract ONLY. Initial real AP owner pilot remains subject to the AP release-HOLD gates in MCP_AP_PRIVATE_PILOT_PINNED_RELEASE_INVENTORY_2026_10_15.md.

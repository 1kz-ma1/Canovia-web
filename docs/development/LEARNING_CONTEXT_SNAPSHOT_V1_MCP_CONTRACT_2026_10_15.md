# Learning Context Snapshot v1 — private AP study, owner-consented MCP

Date: 2026-10-15 JST. Owner: Learning (projection) + Platform (authorization).
Scope: bounded implementation in development `main`, NOT a real-account deployment, Premium 2 activation, notification rollout or personal AP Plan access.

## 1. Product intent

Canovia remains the authority for activity facts, context, changes, and later notification/replanning. ChatGPT is a purpose-limited consumer for AP question selection, explanation and interactive exercises, not a background observer or owner of personal memory.

Sequence: **recorded Facts -> bounded Learning Context Snapshot -> separately authorized MCP projection**. Follow-on work may implement **Context Change Detection -> approved internal advice / notifications -> cross-Plan Premium 2 orchestration**, but none of that is activated by this slice. This design builds on the existing [Adaptive Context Pipeline v0.5 review](ADAPTIVE_CONTEXT_PIPELINE_V05_ARCHITECTURE_REVIEW_2026_10_12.md); the architecture review alone does not assert an implemented Context Resolver.

## 2. Implemented contract

- New stateless pure `LearningContextSnapshotService::compose()`: composes two whitelisted, existing Study projections. It has **no DB write**, AI model call, provider request, notification, durable cache, inter-Plan inference or pricing/entitlement dependency.
- Existing `canovia_get_study_plan_context(scope=tasks, limit=8)`: owner-recorded Study title/deadline and at most eight active Task titles, statuses and recorded progress.
- Existing `canovia_get_study_practice_summary(limit=8)`: most recent owner-applied native attempts and owner-confirmed external result aggregates, mean/latest score and five weakness tags. Unapproved/rejected proposals and raw question/answer/assessment content are never used in the projection.
- New `canovia_get_learning_context_snapshot(plan_id)` returns `canovia.learning_context_snapshot.v1`: **Facts** (plan, bounded tasks, bounded approved aggregate), **observations** (top weakness tags with mention counts, sample coverage and confidence qualifier), **decision_support** (at most one tentative 10-question topic review hint; never a direct Task mutation), **sources** (read timestamps, NOT actual activity occurrence timestamps), and **limitations**.
- Raw Plan/Task descriptions, evidence, profile, reasoning text, tokens, answers, grading rationales, external repository content and other Plans are excluded. Missing practice data means `no_applied_practice_in_bounded_sample` and no recommendation; a missing weakness does **not** mean mastery.
- Weaknesses describe **recorded tags in a recent sample**, not independently confirmed competence. Scores are recorded, not exam-verified. Deadline is owner-recorded, not official IPA schedule. A snapshot is recomputed on request and intentionally not persisted.
- Snapshot does not imply Canovia has detected change, inferred urgency, changed Plans, scheduled a reminder, or notified the owner. Premium billing remains untouched.

## 3. Explicit authorization and privacy

- Entire MCP transport remains OFF by default; additional **`CANOVIA_MCP_LEARNING_CONTEXT_SNAPSHOT_ENABLED=false`** gate requires existing `study_read_enabled` + `study_summary_read_enabled`, valid **single AP pilot owner allowlist**, real non-staging environment, configured OAuth token verification and approved resource/client.
- **New `learning_context` Plan consent scope** is NOT an alias for existing `overview`, `tasks`, or `study_progress` grants. User selects it separately in the Study workspace, completes fresh GitHub/Auth0 verification and the independent Canovia final approval. Previous Study consents never auto-escalate.
- One exact owner-selected private, non-collaborative Study Plan ID; no Plan enumeration. The controller validates the single `plan_id` argument and never permits arbitrary expansions.
- Tool checks signed/introspected bearer, issuer, resource audience, ChatGPT OAuth client, active linked subject, owner, private Study Plan and currently active `learning_context` grant **under Plan/link/grant locks**. An outer DB transaction holds that authorization across the two underlying bounded reads. Revocation, downgrade, transfer, expired grants and wrong owner fail closed.
- The `learning_context` permission can read the already-approved bounded Task and aggregate projections to construct the Snapshot, **but cannot propose study-result writes**. Result write proposals retain a distinct `canovia.study.write` token AND exact `study_progress` grant and Canovia owner final approval. No automatic Task progress or assessment writes.
- Existing synthetic-only MCP Staging is hard-denied even when switches are accidentally enabled. No owner ID, live credentials or personal Plan IDs in GitHub/docs.
- Explicit note: current OAuth resource retains existing `canovia.development.read` for the private pilot; split Study-specific OAuth resource/scopes before any public release. The per-Plan grant is an independent, additional authorization constraint.

## 4. Tests and rollout gates

Feature tests cover default-off discovery, deliberate flag-on without new consent still denied, explicit new grant succeeds, narrow Fact/observation/hint output, hidden question/answer/description/rationale, no unauthorized writes, malformed arguments, wrong Plan, revocation with still-valid bearer, no applied evidence, synthetic Staging denial and fresh OAuth+final-confirm scope transition. The MCP Auth0 provider-routing workflow runs focused tests and PHP syntax checks.

No schema migration required for this slice: the existing delegated-grant `scope` field is VARCHAR(24). The older Canovia Live code still lacks these Study/MCP services and cannot consume this Snapshot without a **separately approved, SHA-pinned release**, exact Aiven migration/backup audit, independently configured OAuth and actual owner/Plan consent. [AP release HOLD](MCP_AP_PRIVATE_PILOT_PINNED_RELEASE_INVENTORY_2026_10_15.md) remains unchanged. CI green does not mean Render production, iOS/Web E2E, real AP data, Auth0 permission/client or staging deployment is proven.

## 5. Next independent slices (not silently enabled)

1. **Private AP read E2E:** production-safe authorized hosting & release path, real owner single-Plan consent, correct signed OAuth principal, wrong subject and revocation checks, Web/iOS smoke. Keep synthetic-only staging isolated.
2. **Context quality:** source event timestamps and revisions, provenance/coverage from underlying LearningRun/StudyPracticeAttempt/approved external results, uncertainty/calibration, source correction/deletion invalidation, richer decision history. Do not mislabel read time as event time.
3. **Canovia-owned Change Detection:** controlled stored state fingerprint + compared revisions, deduplicated events, thresholds, quiet hours and opt-in notification preference, explanations and cancelation/revocation behavior.
4. **Premium 2 Orchestration:** separately approved, actor-scoped multi-Plan coordination, prioritization, no automated consequential changes, entitlement/billing/cost review and real-device E2E.

**Status:** v1 implementation is scoped and OFF by default. No live enabling, actual personalized Context fetch, notification or Premium 2 subscription rollout.

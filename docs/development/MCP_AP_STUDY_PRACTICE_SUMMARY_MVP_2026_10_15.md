# AP Study MCP — bounded applied-practice summary MVP

Date: 2026-10-15 JST. Development-only implementation proposal. **No Render/production/staging deployment; no user data accessed.**

## User outcome
After a Study Plan owner explicitly links ChatGPT and permits the stronger `study_progress` scope, ChatGPT can retrieve up to eight recent owner-applied study attempts in an aggregated, read-only format. This supports choosing the next AP practice topic without asking the learner to retype historical scores. The existing synthetic Staging connection is NOT a real-study integration.

## Scope and data minimization
- Tool: `canovia_get_study_practice_summary(plan_id, limit=8)`.
- Requires original bearer verification + immutable subject / client / resource fingerprint + owner-only, noncollaborative `study` Plan + unexpired **per-Plan `study_progress`** grant. Every read is transactional with Plan/link/grant locks.
- Requires independent **`CANOVIA_MCP_STUDY_READ_ENABLED=true` and `CANOVIA_MCP_STUDY_SUMMARY_READ_ENABLED=true`**, both default OFF. Reject all study access when `APP_ENV=staging`, even with flags ON. No changes to synthetic-only Render service.
- The new `study_progress` permission specifically covers Plan overview, active Tasks and bounded applied-practice summary. Older `overview` or `tasks` consent cannot authorize the practice summary; a Development Plan can never request `study_progress`.
- Consent UI: third optional choice when separately enabled. Existing per-Plan fresh OAuth (PKCE) + independently confirmed owner action + revocation applies. 7-day default remains.
- Query only `study_practice_attempts` with matching **Plan and owner user_id** and a non-NULL `applied_at`. Limit 1–8 newest. Share only sample size, bounded average/latest scores, at most five short high-frequency weakness tags and the Plan title.
- Exclude: exercise questions, submitted answers, grading/rationales, non-applied drafts, raw attempt timelines, full history, descriptions, evidence, session keys, credential material, personally identifying account data.
- Metadata-only read audit; `completion: unverified` caveat. Scores and weaknesses are Canovia-recorded, may be AI-generated, are **not independently verified**. No writes or external evidence trusted.

## Important readiness gap
This is a code-level MVP, **not** immediately usable from the owner's current private Canovia MCP Staging plugin. That plugin intentionally reads a single immutable synthetic Development Plan and has Auto Deploy OFF.

Before live AP data:
1. Distinct owner-approved private environment/rollout with verified identity and OAuth resource configuration, restricted real-user permissions and audited privacy boundaries; owner must expressly approve any production service, Auth0 or database changes.
2. Provide the exact **owner-selected** AP Plan ID. Do not enumerate private Plans or guess IDs.
3. Explicitly consent `study_progress` for that Study Plan using the UI, verify owner, duration and independent final confirmation; check ChatGPT tool discovery, read response, downgrade and revoke.
4. Get a dedicated `canovia.study.read` OAuth scope for broader deployment; existing pilot reuses legacy `canovia.development.read` signed resource permission together with additional hard server feature switches and per-Plan grants.

## Next milestone: user-confirmed results writing
Add an independent WRITE scope, one-Plan-and-task-bound `record_study_practice_result` that starts with a proposal/preview and only commits after separate explicit owner confirmation; require stable idempotency key, bounds, audit and clear grading provenance. Do not use existing read grants for writes. The feedback loop should record ten-question AP rounds (correct count, covered topics, weak topics) without silently claiming generated assessment is authoritative.

## Deployment policy
No staging synthetic restrictions may be relaxed to consume real data. Never deploy a moving main through the Render connector. For any future owner-approved isolated rollout, pin the exact approved commit; keep production separate. Security tests must pass before development-main integration.

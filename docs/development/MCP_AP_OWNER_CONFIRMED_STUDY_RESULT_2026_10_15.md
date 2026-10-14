# Canovia MCP — AP result proposal + explicit owner commit (C1)

Date: 2026-10-15 JST. **Development branch only. No Render, Auth0, Aiven, production or user Plan changes.**

## User interaction

The AP learner answers e.g. ten questions in ChatGPT. A connected, properly write-scoped MCP client can **propose** the structured result (`plan_id`, `task_id`, immutable 64-hex idempotency key, total questions, correct questions, up to five short weakness labels).

The MCP call **does not confirm an assessment**, adjust Task progress or create a native `StudyPracticeAttempt`. It creates a **pending 30-minute proposal**. In Canovia's authenticated **Account → ChatGPTから届いた学習結果の確認**, the Plan owner sees the exact requested total, correct count, percentage and topics, and must separately approve or reject it through CSRF-protected, throttled POST. Only approved rows contribute to the read-only aggregate returned by `canovia_get_study_practice_summary`. Rejected, expired and pending rows cannot be read as study history.

The metadata `owner_approved_mcp_results_considered` distinguishes this source from native Canovia-graded `StudyPracticeAttempt` rows; no raw questions, answers, graded explanations, response text, identity tokens, session IDs or native Task updates are stored.

## Authorization boundaries
- Feature switch `CANOVIA_MCP_STUDY_RESULT_PROPOSALS_ENABLED` OFF by default. Requires Study read and summary flags too, resource enabled, verified bearer, authenticated per-Plan signed subject/client/resource and explicitly approved **study_progress** Plan grant.
- An **independent OAuth bearer permission `canovia.study.write`** is also required for proposal creation. The existing `canovia.development.read` permission is still used by the upstream shared verifier; the write permission is ADDITIONAL, not an alternative. Read-only bearer cannot list or invoke the write tool.
- Every proposal requires **another owner authorization** in Canovia, not a persistent standing autonomous-commit grant. This is the deliberate C1 security boundary. A future persistent per-Plan write grant would need separate schema, OAuth consent flow, revocation handling and authorization epoch fencing; do not assume current `study_progress` grants permit autonomous writes.
- `APP_ENV=staging` always denies the proposal and private Study tools, even when the switches are on; the existing staging service is synthetic Development-only.
- Every proposal is bounded (1–20 questions, correct count within total, at most 5 short tags, exactly one linked Plan/Task, no collaboration), rate-bounded (12 requests/24h per grant, max 3 pending per grant) and idempotent on the same grant+key. Conflicting key payload denied.
- Changing Plan consent forcibly rejects older pending proposals. Grant downgrade, expiry, revocation, linked-subject unlink, Plan ownership changes and Task cancellation all block future approval. Browser approvals are one-time state transitions under database locks.

## OAuth/Plugin rollout blocker (explicit)
The Auth0 API and ChatGPT connection in the existing staging pilot are currently configured for **read-only** operation. Merely merging code or changing feature flags does not cause a client to receive `canovia.study.write`. A separate registered permission and an explicitly owner-approved connector OAuth authorization are prerequisites. The MCP tool advertises a distinct write scope only after feature activation and only to a bearer carrying that scope. Review the OAuth provider configuration and consent UX before any live pilot; never grant extra permissions to all existing clients automatically.

## Remaining AP-learning-loop gaps
C1 approval records *count + weakness topics* in the isolated owner-confirmed results ledger and includes approved results in aggregate read. It deliberately **does not yet feed** the existing adaptive assessment engine / Study Task progression because those paths depend on independently assessed per-question evidence. The next slice can consume the confirmed source with bounded provenance and learner-selected topic recommendations without fabricating native question evidence, and should preserve the original learning system's quality gates.

## Acceptance (disposable local CI)
- No exposed write tool without flag/scope; no call authorization from read-only bearer.
- Bound and typed validation; wrong Plan/Task/owner/client/subject denied with no Plan title leaks.
- Duplicate same idempotency key returns same pending result; conflicting payload refused; 12/day and 3 outstanding bounds.
- No results counted in learning summary before owner approves; reject and expiry never counted.
- CSRF/session owner confirmation, one-time transition, grant revoke/downgrade/unlink and consent refresh fences, no auto progress.
- Migrations pass on SQLite and CI database engines. All existing MCP read-only / study regressions remain green.

**Release discipline**: Merge into development `main` only after clean CI. Do not deploy the moving `main` to Render. Do not alter synthetic staging pinned SHA or production branch. Any actual OAuth permission/provider configuration or real learner account write requires a new explicit owner approval.

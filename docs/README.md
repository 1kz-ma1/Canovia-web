# Canovia documentation index (2026-10-08)

## Parallel execution lanes (2026-10-09)

- [Parallel development operating model](development/PARALLEL_DEVELOPMENT_OPERATING_MODEL.md): lane ownership, concurrency limits, branch/worktree isolation, shared contracts, PR integration and verification vocabulary.
- [Active lane handoffs](development/ACTIVE_LANE_HANDOFFS.md): current lane intake, dependency queue and instructions for separate execution chats. This is a dated snapshot; re-check latest `main` and live PR/CI evidence.
- These are **development-process documents**, not new app features or a second roadmap. Product intent remains in [development roadmap](development/ROADMAP.md), with existing active WIP preserved.

## MCP staging and public-release readiness (2026-10-09)

- [Closed staging synthetic Plan/Task read fixture](development/MCP_STAGING_SYNTHETIC_READ_FIXTURE_2026_10_09.md): operator-only fixed sample Plan; no live OAuth/ChatGPT access yet.
- [Final pre-publication environment and billing checklist](development/RELEASE_ENVIRONMENT_BILLING_READINESS_2026_10_09.md): owner defers paid upgrades until needed and reviews pricing, backup, mail and session safety before public launch.

## AP科目A 2026 CBT模試の問題集審査（2026-10-09）

- [80問候補の品質・出典・監修・公開ゲート](learning/AP_A_2026_80_QUESTION_CANDIDATE_AUDIT.md)：IPA過去問35＋Canoviaオリジナル/類題45のDraft候補。IPA35問のCanovia独自解説案は追加済み（専門レビュー待ち）。実試験に対する範囲・問題精度・権利適合は未認定で、自動公開されない。解説承認とレビュー内容指紋の一致が必須。

## Authority
1. Actual latest main code and independently verified PR/CI/deploy/device evidence.
2. [Product specification](CANOVIA_PRODUCT_SPEC.md), [release levels](CANOVIA_RELEASE_LEVEL_SPEC.md), [monetization](CANOVIA_MONETIZATION_SPEC.md).
3. [Development roadmap](development/ROADMAP.md): planned work and evidence, **not** a Task database.
4. Historical `docs/V*.md` versioned specifications: retain for traceability; not all describe current UI.
5. Active drafts: [pre-release plan](wip/2026-10-08_STATE_FIRST_PRE_RELEASE_EXECUTION_SPEC.md) and [Learning draft](learning/adaptive-learning-experience-draft.md); do not delete before reconciliation.
6. [Future concepts](future/README.md): not an automatic implementation queue.

See [GitHub-native roadmap contract](development/GITHUB_NATIVE_ROADMAP_CONTRACT.md), [coding-agent GitHub pull handoff](development/AGENT_GITHUB_PULL_CONTRACT.md), [owner-only private AI Context preview](development/PRIVATE_AI_CONTEXT_PREVIEW_CONTRACT.md), [MCP OAuth resource boundary](development/MCP_OAUTH_RESOURCE_GATE_CONTRACT.md), [server-side token introspection](development/MCP_TOKEN_INTROSPECTION_CONTRACT.md), [inactive subject and Plan authorization policy](development/MCP_DELEGATED_PLAN_POLICY_CONTRACT.md), [MCP grant and external identity revocation](development/MCP_DELEGATED_REVOCATION_CONTRACT.md), [OAuth PKCE actor-to-IdP account linking](development/MCP_OAUTH_ACCOUNT_LINK_CONTRACT.md), [explicit per-Plan ChatGPT sharing approval](development/MCP_EXPLICIT_PLAN_CONSENT_CONTRACT.md), [off-by-default read-only MCP resource](development/MCP_READ_ONLY_RESOURCE_CONTRACT.md), [staging OAuth provider preflight and isolated environment contract](development/MCP_STAGING_IDP_PREFLIGHT_CONTRACT.md), [isolated Render staging bootstrap (manual opt-in)](development/MCP_ISOLATED_STAGING_BOOTSTRAP_CONTRACT.md), [OAuth provider decision and dedicated staging Postgres](development/MCP_IDP_SELECTION_AND_STAGING_POSTGRES_2026_10_09.md) (includes a reviewed, secretless existing-stage Render connection reference), [ChatGPT sharing preparation (not OAuth)](development/CHATGPT_SHARING_PREFERENCES_SPEC.md) and [development workflow](CANOVIA_DEVELOPMENT_WORKFLOW.md). The read-only GitHub-native Markdown importer, explicit PR/Issue/CI observations, and session-scoped context endpoint are implemented (PR #375–#380). Context sharing is user-triggered and limited in scope; there is **no Task auto-synchronization**, no unattended AI tool access, and no claim of deploy/device verification.

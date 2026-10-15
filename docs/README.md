# Canovia documentation index (2026-10-13)

## Product direction and decision memory (2026-10-13)

- [Product Decision Memory operating specification](development/PRODUCT_DECISION_MEMORY_OPERATING_SPEC_2026_10_13.md): **active lightweight GitHub documentation practice**, not a Dev Pro runtime implementation.
- [Core early access / internal Dev Pro / World strategic decisions](development/decisions/2026-10-13_CANOVIA_STRATEGY_DEVPRO_WORLD.md): explicit decision status, provisional validation signals, security/release boundaries and follow-up ledger.
- [Canovia World independent app concept](future/CANOVIA_WORLD_COMPANION_APP_CONCEPT.md): future-only first-party app candidate; no separate Beta launch or cross-app authorization implemented.


## Pre-Launch IA-2 — planless onboarding slice (2026-10-15)

- [IA-2d Disposable Chromium three-entry E2E](development/PRELAUNCH_IA2D_DISPOSABLE_BROWSER_E2E_2026_10_15.md): real loopback Chromium smoke for browse/diagnose/converse/first Study action, safe no-provider SQLite, and compatibility with older browser journeys. Not live mobile/browser acceptance.
- [IA-2c Planless Overview re-entry](development/PRELAUNCH_IA2C_PLANLESS_OVERVIEW_REENTRY_2026_10_15.md): explain first value without Plan, offer existing Companion, L1-gated diagnosis and optional manual Plan, retain mode choices; guard generic existing Plans from mistaken first-use onboarding.
- [IA-2b Welcome three-option choice](development/PRELAUNCH_IA2B_FIRST_RUN_ENTRY_CHOICES_2026_10_15.md): Diagnose, existing First Companion, or planless Overview. Legacy POST and L0 gate retained; no new release/AI access or production deployment.
- [IA-2a Personalization Skip → zero-Plan Overview](development/PRELAUNCH_IA2A_PLANLESS_SKIP_2026_10_15.md): fix the misleading Skip-to-Plan-creation funnel; validate authenticated and guest zero-Plan Overview, opt-in Plan creation and preserved release gate. **Development main only, not a public launch or release approval.**

## Pre-Launch public IA and staged participation (2026-10-13)

- [Pre-Launch Public Site / App / Future IA specification](development/PRELAUNCH_PUBLIC_APP_FUTURE_IA_SPEC_2026_10_13.md): **product design, not a release authorization**. Guest diagnosis/Plan Seed preview, marketing site vs My Workspace vs Canovia Future responsibilities, curated public roadmap, existing free Support reuse, no pay-for-priority, release gates and small implementation slices.

## Adaptive Context Pipeline v0.5 — architecture review (2026-10-12)

- [v0.5 architecture review candidate](development/ADAPTIVE_CONTEXT_PIPELINE_V05_ARCHITECTURE_REVIEW_2026_10_12.md): Shared Core + Domain Pipelines, Facts / Context / Capability contracts, policy and decisions. **Review proposal; not a runtime or billing change.**
- [v0.5 non-destructive migration and acceptance plan](development/ADAPTIVE_CONTEXT_PIPELINE_V05_MIGRATION_ACCEPTANCE_2026_10_12.md): existing V53/V55/V58 reuse, phased gates, risk and security tests. **Not an active implementation backlog.**
- [G3-R11–R31 private-file fencing, offline evidence and exit-decision HOLD](development/G3_PRIVATE_FILE_PATH_FENCE_IMPLEMENTATION_SPEC_2026_10_14.md): keyed persistent lock, coordinated native upload writers, prewrite recovery-intent ledger and non-production bounded no-delete preview; five additive migration gates, R21–R26 no-new-migration provider probe/ABA/provenance/trust/replay deny-only classification, and explicit manual review only. **G3 OPEN; not a live rollout.**
- [G3-R43 Inbox Plan Resource atomic confirmation](development/G3_INBOX_RESOURCE_ATOMIC_COMMIT_R43_2026_10_15.md): one Plan→Inbox→Member-locked transaction for authorization, URL admission, resource+activity+Inbox status; reject replay/stale source/Editor downgrade. **G3 OPEN, external AWS Issue #681 HOLD**.
- [G3-R42 Inbox Plan Resource URL authority validation](development/G3_INBOX_RESOURCE_URL_AUTHORITY_R42_2026_10_15.md): reject misleading GitHub/Drive/OneDrive hostname substrings and HTTP/userinfo links; keep official service domains. **G3 OPEN, Issue #681 AWS HOLD unchanged**.
- [G3-R41 Inbox lock-order inversion correction](development/G3_INBOX_PLAN_FIRST_LOCK_ORDER_R41_2026_10_15.md): align pre-provider and commit DB locks with Plan → Task → Inbox transfer; fail closed on plan retargeting; verified real-DB query ordering. **G3 OPEN; Issue #681 independent provider proof remains HOLD**.
- [G3-R40 external-provider GO / NO-GO checkpoint](development/G3_PROVIDER_AUTHORIZATION_DECISION_R40_2026_10_15.md): no additional synthetic proof layers; AWS capability boundary, actual independent evidence prerequisites, and explicit owner decision Issue #681. **Default HOLD; G3 OPEN; no cloud action**.
- [G3-R39 Inbox pre-provider live authorization and source admission](development/G3_INBOX_PRE_PROVIDER_ADMISSION_R39_2026_10_15.md): recheck locked live Inbox/Plan/Editor epoch and entitlement after provider-input read but before AI call; **G3 OPEN, no external generation proof**.
- [G3-R38 Inbox AI provider-input byte seal](development/G3_INBOX_PROVIDER_INPUT_SEAL_R38_2026_10_15.md): bind exact second-read attachment bytes to pre-request source/authorization signature before calling AI, deny missing/replaced files with no provider call; **G3 OPEN, not provider generation attestation**.
- [G3-R37 indirect Inbox upload and outbox enrollment provider-I/O HOLD](development/G3_INDIRECT_STORAGE_AUDIT_R37_2026_10_15.md): deny nonlocal legacy Inbox writes; quarantine nonlocal outbox enrollment without HEAD/GET/DELETE. **G3 OPEN, no AWS permission**.
- [G3-R36 rollback deletion and legacy writer safety boundary](development/G3_ROLLBACK_DELETE_BOUNDARY_R36_2026_10_15.md): non-local upload errors preserve ambiguous bytes with a durable review intent; unjournaled legacy writes are denied before remote I/O. **G3 OPEN, not S3 launch approval**.
- [G3-R35 external provider deletion HOLD and owner-gated next evidence](development/G3_PROVIDER_DELETE_HOLD_AND_NEXT_PROOF_R35_2026_10_15.md): non-local durable cleanup quarantined by default; AWS ETag/version semantics reviewed, separate provider owner GO still required; **G3 OPEN**.
- [G3-R34 real disposable Laravel/MySQL/S3 process crash evidence](development/G3_LARAVEL_MYSQL_S3_CRASH_R34_2026_10_15.md): four PHP crash/success states and persistent S3-compatible objects verified at exact CI HEAD; **not real AWS or production**.
- [G3-R33 disposable S3-compatible process SIGKILL/restart evidence](development/G3_DISPOSABLE_PROCESS_RESTART_R33_2026_10_14.md): real localhost HTTP, persisted versioned writes across container restart, **not AWS provider proof; G3 OPEN**.
- [G3-R32 disposable S3-compatible localhost HTTP trial](development/G3_DISPOSABLE_HTTP_TRIAL_R32_2026_10_14.md): observed version/ABA/concurrent PUT and lost-ACK behavior on ephemeral Moto server, **NOT AWS S3 attestation; G3 OPEN**.
- [G3-R31 pinned exit decision and external evidence gap audit](development/G3_EXIT_DECISION_R31_2026_10_14.md): seven source-contract checks at immutable Git SHA, no provider attestation or owner GO; **HOLD external proof / G3 OPEN**.
- [G3-R30 owner checklist and hypothetical scope-drift contract](development/G3_OWNER_SCOPE_CHECKLIST_R30_2026_10_14.md): fixed out-of-band review, strict unapproved synthetic operation quotas, no runtime enforcement or operator GO; **G3 OPEN**.
- [G3-R29 disposable provider approval request](development/G3_DISPOSABLE_APPROVAL_PACKET_R29_2026_10_14.md): owner-facing bounded scope/budget/rollback request, no provider access or verified consent; **G3 OPEN**.
- [G3-R28 offline provider evidence collection plan](development/G3_PROVIDER_COLLECTION_PLAN_R28_2026_10_14.md): required acquisition scope, role, secure custody and negative-test matrix, default no authorization or collection; **G3 OPEN**.
- [G3-R27 pinned offline provider-evidence inventory and manual handoff](development/G3_PROVIDER_EVIDENCE_HANDOFF_R27_2026_10_14.md): eleven fixed evidence requirements and reviewer roles, Git-pinned Markdown integrity check, always-denied release/retry/delete; **not a provider attestation**.
- [v0.5 G3 exit audit and current P0 evidence](development/ADAPTIVE_CONTEXT_PIPELINE_V05_G3_EXIT_AUDIT_2026_10_12.md): G3 source-to-derivative routes, merged guards, remaining high-risk commit/source revocation gaps and **conditional, not-closed exit status**.\n- [v0.5 Phase 0 static baseline audit](development/ADAPTIVE_CONTEXT_PIPELINE_V05_PHASE0_STATIC_AUDIT_2026_10_12.md): verified code paths, G1–G8 risk distinctions and prioritized A20/A01/A06/A04 regression gates. **Static only; runtime and CI results are not certified.**

## Parallel execution lanes (2026-10-09)

- [Parallel development operating model](development/PARALLEL_DEVELOPMENT_OPERATING_MODEL.md): lane ownership, concurrency limits, branch/worktree isolation, shared contracts, PR integration and verification vocabulary.
- [Active lane handoffs](development/ACTIVE_LANE_HANDOFFS.md): current lane intake, dependency queue and instructions for separate execution chats. This is a dated snapshot; re-check latest `main` and live PR/CI evidence.
- These are **development-process documents**, not new app features or a second roadmap. Product intent remains in [development roadmap](development/ROADMAP.md), with existing active WIP preserved.

## MCP staging and public-release readiness (2026-10-09)

- [Historic MCP Staging identity-link incident triage](development/MCP_STAGING_IDENTITY_LINK_MISMATCH_RUNBOOK_2026_10_14.md): fixed-code rejection vs different Auth0 subject/fingerprint hypotheses, updated with current Staging Live checkpoint; no real-account data or OAuth configuration changes.
- [Future Identity & Connection UX (candidate)](future/CANOVIA_IDENTITY_CONNECTION_UX.md): passkey/step-up/relink/optional connection-code concept only, never an OAuth/Plan permission bypass or current backlog.

- [**2026-10-14 real ChatGPT OAuth, scoped Development Plan/Task read & revocation acceptance**](development/MCP_CHATGPT_STAGING_REAL_OAUTH_READ_REVOCATION_ACCEPTANCE_2026_10_14.md): **PASS for one isolated synthetic staging fixture only**, including wrong-subject denial, approved overview/tasks reads and post-revocation denial. Staging diagnostic removal and all production security/rollout gates remain open; this dated evidence supersedes older *not-yet-tested* staging statements but does not imply production activation.
- [Isolated synthetic Development Plan consent pilot](development/MCP_STAGING_SYNTHETIC_PLAN_CONSENT_PILOT_2026_10_13.md): off-by-default staging-only, owner-verified scope and expiry; MCP reads stay disabled.
- [ChatGPT MCP Phase 1 Development-only read acceptance](development/MCP_CHATGPT_PHASE1_DEVELOPMENT_READ_2026_10_11.md): approved implementation scope, OAuth Tool declaration, Auth0/JWT gating, and real ChatGPT E2E proof still required.
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

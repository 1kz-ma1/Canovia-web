# Canovia Adaptive Context Pipeline v0.5 — Architecture Review Candidate

## 日本語サマリー（レビュー時の確認事項）

**設計の結論：既存のV53 Intelligenceを置き換えず、Learning・Development・Careerの専門処理を共通Contextとして安全に再利用する。** ユーザーの活動を事実として正規化して保持し、解釈や活動間の調整は利用目的に応じて必要な範囲だけ生成する。

- **Free**：日常活動の基本記録・可視化・訂正。**Premium 1**：適応学習など個別活動の最適化。**Premium 2**：学習と開発など、複数活動の優先判断・切り替え・再評価。課金境界は提案であり、現行Free/Premium/Proの実際の権限は変更しない。
- **共通契約は薄くする**：Fact（何が起きたか）、Context（そこから何が分かるか）、Capability（何を準備・実行できるか）を分離。カテゴリ固有の採点・CI判定・選考評価を共通スコアへ無理に統合しない。
- **自動化の標準**：準備と変更案作成までは自動化し、重要な変更は本人が承認。承認後も、権限や対象データが変わっていないことを再確認する。
- **安全性**：外部AIへの情報提供を含め、取得・分析・保存済み派生データ・出力に目的別の権限を適用する。元データの削除・訂正・許可取消しが発生した場合は、古い推定を利用させない。
- **保留事項**：具体的なDB型、依存関係の無効化粒度、外部サービスごとの観測能力、Premiumの価格・正式な料金移行、iOS通知と実機検証。

**この文書はレビュー候補であり、実装済みの宣言・本番公開・課金変更を意味しない。**



> Updated: 2026-10-12 / Status: **REVIEW CANDIDATE — NOT IMPLEMENTED AS A WHOLE**
> Owner decision: conceptual direction accepted in discussion; concrete schema, runtime behavior, entitlement migration, and rollout are NOT approved by this document.
> Scope: Canovia product-wide Context Foundation, Learning / Development / Career, Intelligence, Personalized Capability Activation, External AI.
> Companion: [non-destructive migration and acceptance plan](ADAPTIVE_CONTEXT_PIPELINE_V05_MIGRATION_ACCEPTANCE_2026_10_12.md).

## 0. Authority and decision boundary

**Current behavior is defined by latest main**, [Product Spec](../CANOVIA_PRODUCT_SPEC.md), [Monetization Spec](../CANOVIA_MONETIZATION_SPEC.md), [Personalization Spec](../CANOVIA_PERSONALIZATION_SPEC.md), versioned V53/V55/V58 specifications and actual release evidence. This proposal does not replace them, alter the canonical [Roadmap](ROADMAP.md), or instruct implementation agents to start all phases. Do not interpret architecture names as existing classes, tables, routes, commercial features, or completed device verification.

This candidate documents the proposed strategy: **Canovia turns permitted, ordinary activity into reusable personal context.** Goals, plans, task management and ChatGPT access are valuable producers/consumers of context, not mandatory prerequisites to recording it.

### Decisions already agreed conceptually

- Record individual verifiable activity facts first. Compose activity groupings only when needed.
- Normalize and validate a minimal, decision-relevant projection before placing it in shared context. Keep category-specific detail at its original owner; never blindly mirror raw provider payloads.
- Persist frequently reused derived context; calculate infrequent context on demand **only where the necessary source evidence and policy/model version remain available**. Exact reproduction of stochastic AI text is NOT guaranteed; traceable re-evaluation is the standard.
- Default interaction: automatically prepare, propose and let the user approve material action. Narrow low-risk, explicitly delegated automation is a future option, not today's implicit permission.
- Develop new domains incrementally via Facts → Context → Capabilities. **User-facing release waits until the advertised domain experience is truly useful**; no implied full Premium 2 support from Fact-only integration.
- Preserve domain expertise; standardize boundaries and interoperability, not a universal effectiveness score or common domain algorithm.

## 1. Value and pricing boundaries — proposed, not active billing

| Tier | What a user can actually achieve | Examples |
| --- | --- | --- |
| Free / Context Foundation | Capture, review, correct, visualize and perform basic activities without compulsory Goal entry | activity timeline, calendar, observed quantities, Learning answer/score record, developer activity record |
| Premium 1 / Context Intelligence | Improve **a particular activity** using domain-specific history and analysis | per-answer adaptive next-question choice; study weakness review; relevant domain forecasts with uncertainty |
| Premium 2 / Context Orchestration | Coordinate competing activities, constraints and goals, while respecting the user's choices | AP study vs Canovia development priority, timely switch suggestion, prepared review, deferral and later re-evaluation |
| Optional independent service | Specialized standalone value and cost, priced separately after validation | Dev Pro-like development lifecycle, future matching service |

Premium 1 may use some cross-category context if it improves a single activity. **Access to another category alone does not imply Premium 2.** Premium 2 is differentiated by ongoing negotiation of priorities, available time, proposed actions, and outcome-aware re-evaluation across activity boundaries.

This is a **proposed product-tier semantic**, not an authorization change. Existing Free / Premium / Pro / Dev Pro labels, FeatureKey, Entitlement, Release Level, AI Capacity and billing rules continue unchanged pending a separate owner-reviewed migration and cost model. Human access to correction/deletion/export is not a premium privilege.

## 2. Logical architecture

    Trusted Canovia activity / verified external connector / person-supplied observations
          |
          v
    Common governance (collection permissions, identity, purpose, retention)
          |
          v
    Domain adapter and ingestion (validate → normalize → dedupe / reconcile → persist)
          |
          +-- Existing specialized source stores (Study, Development, Career, Plan, ...)
          |
          +-- Canonical reference/index to the source fact (not a second raw evidence DB)
          |
          v
    Domain intelligence (existing Evidence → State → Readiness → Decision → Action)
          |
          +-- Derived state / relations with provenance, versions and invalidation
          |
    +-----+------------------------+
    |                              |
    v                              v
    Purpose-bound Context Resolver  Bounded Context Evaluator
    |                              |
    v                              v
    Free / Premium 1 / MCP views    Proposal → human decision → revalidate → commit
                                   |
                                   v
                              Outcome evidence → next evaluation

**Policy and governance run across every arrow**, not as a final filter. Source collection, source reads, materialization, cache hits, AI calls, external disclosure and writes all need server-enforced checks.

### Not a replacement for V53

The existing [V53 Intelligence Contract](../V53.0_INTELLIGENCE_CONTRACT.md) already defines EvidenceObservation, StateSnapshot, ReadinessAssessment, Decision, ActionProposal and OutcomeObservation, as well as a deterministic baseline and domain-specific builders. [V53.1](../V53.1_STATE_EVIDENCE_FOUNDATION.md) expressly prohibits a second raw Evidence database. The v0.5 Fact/Context/Capability terminology is a **cross-domain integration contract layered over those interfaces**, not a rewrite or a new source of truth.

- Fact Contract: domain-specific persisted evidence + normalized source identity and a bounded, searchable cross-domain reference. May map to TaskEvidence, ExecutionActivity, StudyPracticeAttempt, DevelopmentActivityObservation, Career events or other source data.
- Context Contract: derived State, Relations, Readiness and personal understanding with source fingerprints, authority, freshness, purpose visibility, invalidation semantics.
- Capability Contract: domain-registered operations and prerequisites, not the same as current simple per-Task ExecutionCapability selection; existing capability and activation services are reusable inputs.
- External MCP: a purpose-bound read projection **only when OAuth, actor-bound grant and provider compatibility have passed their own release gate**. No implicit write permission from this architecture.

## 3. Shared Fact / Context / Capability contracts

### 3.1 Fact reference envelope (logical fields, NOT an approved DB migration)

| Field family | Examples / rules |
| --- | --- |
| Identity and tenant | fact_ref, owner/actor identity, source namespace and immutable source key; resolve plan/team authorization independently |
| Classification | domain, event_type, source_kind: observed / self_reported / external_claim; do NOT place inferred statements in verified Fact |
| Time | occurred_at (may be unknown), observed_at; event_version/provider_changed_at when meaningful; ingestion time cannot prove ordering |
| Provenance | evidence reference, normalized fingerprint, original domain record reference + source version, schema_version |
| Status | active / corrected / retracted / unavailable; distinguish append-only event from latest mutable entity state |
| Data boundaries | domain_details_ref and controlled normalized fields, policy/permission reference, retention or deletion lifecycle reference |
| Quality | verified/claimed/unverified, partial coverage and missing/unknown source state |

The shared contract is thin. Each domain validates its own measurements; do not sum Learning question counts, GitHub commits and Career applications into a fake universal effort score. Show numerator, unit, observed interval, denominator/coverage and missing-source warning for activity ratios. Do not infer time spent from a PR merge.

Deduplication identity is scoped at least by authorized owner/connection and source entity/event; provider delivery IDs alone do not necessarily identify business activity. Replay, out-of-order and state transitions must converge; consult a provider-authoritative snapshot when update order is uncertain. Transactionally link or safely reconcile the source record and index; asynchronous indexing can be eventually consistent but must never fabricate records.

### 3.2 Context and reproducibility

Derived Context must distinguish **observation, user decision, model inference and confirmed correction**. A derivation reference carries:
- Domain / actor / permitted purposes; exact source references or a verifiable dependency-set version; source record revision/fingerprint.
- Derivation policy/model/builder version, processing time, validity window / expiry and confidence or explicit UNKNOWN.
- Observed coverage and last successful sync, including "not observed" versus "observed absent".
- Dependency status: valid / stale / invalid / regeneration pending / unresolvable.
- Confirmation state: suggested / confirmed / rejected / superseded, where relevant.

Frequently used state (e.g. mastery) may be materialized; other state can be resolved when needed. Rebuild may produce a new inference, not byte-for-byte the same AI wording. If the original evidence has expired or has been deleted, mark the historical result **not reproducible** instead of silently inventing a reconstruction.

Correct/withdraw a fact → invalidate dependent current context and recommendations **before** serving them; recompute only within permitted scopes. If a grant changes, invalidate or partition derivations/caches by authorization context and purpose. Never use stale restricted derived context during regeneration. Keep source-data deletion and any legally required minimal audit separate and explicitly scoped.

### 3.3 Capability contract, no universal domain algorithm

Each domain can advertise optional:
- capability_id / version and **actual implementation/release readiness**, not just a speculative identifier.
- typed inputs/outputs and allowed operation: read, prepare, propose, commit (if available).
- required data, freshness/coverage, connections, entitlement and explicit user authorization.
- risk classification, approval policy, side effects, idempotency boundary, failure/compensation behavior.

Shared orchestration submits **purpose, remaining time, priority and constraints**. The domain still chooses its professional execution method. Learning might prepare a 15-minute DNS review; Development might identify a bounded next task. Capability description **never grants** its operation's permissions.

A new domain may enter internally with Facts only. It is published only when its promised minimal usable domain journey is independently verified. Missing Context/Capability support is presented honestly by Capability Readiness.

## 4. Pipeline: Ingest, Resolve, Evaluate

### Ingest — event-driven, inexpensive and source-aware
1. Validate connector/browser/provider authentication, source and collection consent; derive actor, tenant, plan/task IDs server-side, never from unchecked payload.
2. Domain adapter validates schema; uses explicit allowlists and bounded values. Preserve domain-owned detailed records only where needed and authorized.
3. Reconcile stable business-event identity, current state and provider ordering; handle replay, update, deletion and retries.
4. Publish/maintain source reference and dependency invalidation, preferably through transactional outbox or a verified equivalent where cross-table/job consistency requires it.
5. Queue only affected domain derivations. Ingestion succeeds without synchronous AI.
6. Record metadata-only operational telemetry and safe failure state; not full sensitive payloads.

### Resolve — triggered by a permitted purpose
1. Authenticate actor and resolve a narrow purpose/capability; determine **allowed source set before retrieval**.
2. Verify dataset ownership, collaboration scope, per-provider access, consent, entitlement and release gates where relevant. A feature flag is NOT authorization.
3. Fetch only allowlisted facts/domain state; use cached derivations **only when source revision, policy scope and freshness match**.
4. Use deterministic reduction first and optional bounded domain reasoning. LLM input must be a restricted, evidence-labeled projection; untrusted provider text is data, never instructions.
5. Label unsupported inference, incomplete coverage and stale state; apply output-specific redaction. A private fact must not leak through a public inference.
6. Audit high-sensitivity and external reads without storing returned private content. Revocation is checked again at disclosure.

### Evaluate — bounded conditional work, NOT continuous surveillance
Only meaningful upstream changes or explicit user requests can trigger cross-domain reevaluation: evidence update, looming deadline, relevant schedule change, user priority decision, stale integration, or a configured recheck window. Use causal event type + evaluation key + source revision to avoid reevaluation loops; deduplicate identical proposals, cap retries, token costs and user notifications.

Proposal creation or dismissing a proposal must **not by itself** schedule an identical evaluation indefinitely. Never claim Canovia observed real-time ChatGPT work unless a separate user-approved integration actually supplied those events. Missing evidence reduces confidence; no data must not be interpreted as inactivity.

## 5. Decision policy and user control

**Default: Prepare → Propose → Approve → Revalidate → Commit → Observe Result.**

- Prepare may compute draft sessions, candidate schedules or reviewable changes with no irreversible side effect.
- Propose must explain why, evidence boundaries, expected benefit, assumptions and affected scope; allow revise / accept / defer / dismiss.
- Approval comes from an authenticated Canovia principal, tied to proposal ID, actor and revision; external AI assertions cannot approve.
- Commit rechecks current ownership/team role, data revisions, scope, policy, consent and idempotency. Stale proposals fail closed with updated diff.
- Domain commit performs only the approved allowed mutation, with local transaction and external side-effect reconciliation where atomicity is impossible.
- Outcome records **what is verifiably observed**; starting a session is not evidence it was completed, and accepting a suggestion is not proof of improved learning.

Decision Context preserves user selection, optional reason, time range, prior proposal, suppression/recheck boundary and cancellation. A refusal is not failure, nor a permanent goal priority. No recurring nagging. Notification intensity uses urgency, confidence, interruption state when actually known, quiet preferences and last dismissal; default to low-noise surfaces.

Low-risk delegated automated commits are **future work**: require separate bounded, revocable user authority, domain allowlist, audit and rollback/reconciliation tests. External publication, destructive actions, sensitive sharing and material goal changes never inherit implicit permission.

## 6. Concrete multi-domain example (illustrative, not observed user data)

- Learning source: "DNS: 7 of 10 answers correct" stored with question/run references and observed timestamp.
- Development source: GitHub PR merged; no asserted duration or current foreground activity.
- User's explicit choice: "continue Canovia design now", bound to a prior study prompt, with optional expiration/recheck condition.
- Free: show separate counts/units and dated timeline without inventing a combined score.
- Premium 1: the Learning adapter may choose next DNS questions using authorized answer history.
- Premium 2: cross-domain evaluator consults permitted exam deadline, learning evidence, future available time (if supplied), active priorities and prior refusal. It may suggest an estimated review and explain missing information.
- If the user chooses development, record deferral, suppress duplicate prompts, recheck only when conditions materially change; never auto-write Task progress.
- If user chooses study, prepare domain-specific review and start only on confirmation; record actual answers as fresh evidence and reassess afterward.
- ChatGPT/MCP work is UNKNOWN unless separately observed through a supported, consented channel.

## 7. Capability-based value discovery (proposed)

**User Fit → Capability Readiness → Guided Activation → Value Realization.**

Separate: user benefit/fit; feature shipped status; release flag; plan entitlement; source permissions; actual connection and data coverage; setup completion. **Entitlement never substitutes for capability readiness**, and a connection never confers a higher skill/guidance level.

Explain "this would help you balance AP learning and development" plus what is usable **today** and what specific setup is missing. Do not sell unavailable behavior as already active. Free continuation or downgrade may be appropriate. Initial implementation should extend [V58.26 capability activation](../V58.26_CAPABILITY_ACTIVATION_FOUNDATION.md), existing FeatureAccess/Entitlement and Living Profile provenance rather than creating a parallel paywall.

No price, advertising entitlement, checkout, subscription renewal behavior or ad-targeting personal-context transfer is approved here. Native AI use must be measured and bounded.

## 8. Safety, lifecycle and operating invariants

1. Purpose- and actor-bound enforcement exists **before** data fetch/AI call and at output/commit; all team scopes have independent access checks.
2. Shared cache entries must not cross actors, authorization revisions or purposes; derived projections inherit applicable source restrictions.
3. Corrections, retractions, source deletion, consent withdrawal and source disappearance cause invalidation and safe fail-closed reads. External copies already disclosed may not be recoverable.
4. Raw provider JSON, personal free text, prompt body, secret, token and private repository code do not enter generic Fact/State/telemetry by convenience.
5. No unbounded event fanout, infinite evaluation loops, irreversible automatic writes or false completion.
6. Observed/claimed/inferred/confirmed and unobserved/unknown are distinct across storage, evaluation and UI.
7. Feature readiness is not evidence of implementation, CI, deploy or device acceptance; release metadata is not an access grant.
8. Preserve existing Plan/Task, Guest, collaboration, Learning run/answer immutability, Career privacy, GitHub linkage and owner-confirmed domain semantics.
9. Operational support includes event lag, stale derivations, provider loss, partial failure and redacted audit signals.
10. Free's basic activity capture, visualizations and the user's core control of their data must function without compulsory AI calls.

## 9. Design review resolution and remaining approvals

| v0.4 risk | v0.5 design response | Evidence needed before declaring solved |
| --- | --- | --- |
| M1: unauthorized derived data leak | global policy, purpose-scoped retrieval and derivation-aware sharing | adversarial mixed-scope and cache isolation tests |
| M2: stale derived context after correction | source dependency revisions, invalidation before access | edit/delete/revoke → all current context safely re-evaluated or withheld |
| M3: duplicated/out-of-order activity | provider-scoped stable identity and version-aware reconciliation | replay and out-of-order simulation on each connector |
| M4: stale/false AI approval | authenticated user approval, revalidation and idempotent commit | stale proposal, another actor, consent revoke, double-submit negative tests |
| M5: unbounded evaluate/notifications | event dependency triggers, throttling and decision deferral | loop boundedness and notification suppression tests |
| M6: activity not observed | coverage, freshness and UNKNOWN | missing ChatGPT / calendar / provider test cannot claim inactivity |

**Not proven by this document:** end-to-end real-user value, price willingness, AI cost feasibility, raw data retention legality, every existing call site's scope protection, actual ChatGPT OAuth connection or iOS push delivery.

### Explicit open design choices for Phase 0

- Minimal cross-domain Fact index: reuse existing source tables and adapters first; add a table only if a concrete query/constraint cannot be met safely and economically.
- Source→derived dependency granularity, transactional invalidation and policy-version caching: require targeted failure-mode prototype/tests before schema freeze.
- Per-purpose access policy and consent revocation design for domain plus cross-domain + team collaboration.
- Cross-domain evaluator's concrete scheduling/attention policy and source coverage thresholds; do not treat a numerical confidence as calibrated unless empirically validated.
- Naming/migration from the current Free/Premium/Pro product model, with entitlement keys, grandfathering and measured unit economics **in a separate commercial decision**.

**Architecture review status: accepted as a candidate direction; production, payment, schema migration and AI action execution remain unchanged.**

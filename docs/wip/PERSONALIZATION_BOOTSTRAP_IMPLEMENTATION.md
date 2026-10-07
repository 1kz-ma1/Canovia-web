# Personalization Bootstrap — WIP Implementation Plan

> Status: Active implementation bridge  
> Started: 2026-10-07  
> Purpose: 次のチャット / 次PRへ中断なく引き継ぐための一時仕様  
> Delete condition: 全Phaseが恒久仕様へ吸収され、未完了項目が0になった時だけ削除する

## 0. Source of truth

Product intentは以下を優先する。

- `docs/CANOVIA_PRODUCT_SPEC.md`
- `docs/CANOVIA_RELEASE_LEVEL_SPEC.md`
- `docs/CANOVIA_PERSONALIZATION_SPEC.md`
- このWIPは「実装順序 / 現在地 / NEXT」のみを保持する

## 1. 実装前調査 10項目

### 1. 現在のOnboarding構造

- `FirstRunService` が新規account / guestの初回gateを管理
- `FirstRunController::show` はwelcome UI
- V58.25以前の `FirstRunController::start` は `plans.create` へ遷移
- authenticated existing userは `first_run_completed_at` がある限り強制gateされない
- Workspace固有のonboardingは `WorkspaceModeOnboardingService` が別責務で存在

Decision:
First Run自体を巨大化せず、Welcomeの次にPersonalization Bootstrapを置く。

### 2. 現在のPlan作成構造

- manual createは `PlanController::create/store`
- session `plan_create_prefill` を既に利用可能
- Workspaceから作る場合は `workspace_mode` でStudy / Development / Careerへ戻れる
- Plan作成後に既存AI task assistant / Workspace setupへ進む

Decision:
Personalization専用の別Plan creatorを作らない。
Plan Seed accepted時に既存 `plan_create_prefill` へ変換する。

### 3. Study / Developmentカテゴリ構造

- canonical UI名は Learning ではなく `Study Workspace`
- `WorkspaceMode::Study` / `WorkspaceMode::Development` が既存
- `WorkspaceModeRegistry` が説明・カテゴリ・onboarding stepを持つ

Decision:
Personalization domain keyは `study` / `development` をcanonicalにする。

### 4. GitHub Integration構造

- `GitHubIntegrationReadinessService`
- GitHub App / install URL / webhook / queue readiness
- `GitHubWorkflowController`
- Development WorkspaceからGitHub workflowへ接続可能
- GitHubはDevelopment利用の必須条件ではない

Decision:
Phase 1では接続を強制しない。
diagnosisから「GitHub capabilityを提案してよいか」を判定し、Value Preview + Interestまで。

### 5. User Profile / Preference

Userには:

- onboarding / first-run timestamps
- workspace_mode_preference
- release_level_override
- map personalization

はあるが、目的・経験・feature readinessを保持する専用Personalization Contextはない。

Decision:
one-to-one `user_personalization_contexts` を追加する。
guestはsession draftを利用し、account claimは後続Phaseで拡張可能にする。

### 6. 仕様書配置先

恒久:
- `docs/CANOVIA_PERSONALIZATION_SPEC.md`

一時:
- `docs/wip/PERSONALIZATION_BOOTSTRAP_IMPLEMENTATION.md`

Development / GitHubからの参照はPhase 2以降必要時に追加。

### 7. 今回のMVP範囲 — V58.25 Phase 1

実装する:

- Personalization Context foundation
- new First Run → Personalization Bootstrap
- existing user optional entry
- multi-select: Study / Development / まだ分からない
- Skip
- common minimal questions
- Study minimal questions
- Development minimal questions
- deterministic Guidance Level
- deterministic Plan Seed
- Plan Seed → existing Plan create prefill
- GitHub Value Preview eligibility
- GitHub Interest yes/no
- safe telemetry
- tests
- mobile-first view

実装しない:

- Career diagnosis
- Business / Habit diagnosis
- AI generated dynamic questions
- AI generated full Plan
- automatic observed-behavior profile mutation
- GitHub Guided Setup wizard
- repository auto-selection
- video asset pipeline
- advanced recommendation AI

### 8. DB変更

追加:

`user_personalization_contexts`

one-to-one with user.

Main fields:

- version
- context_revision
- self_reported_context JSON
- observed_context JSON
- inferred_context JSON
- guidance_level
- recommended_surfaces JSON
- feature_readiness JSON
- last_evaluated_at
- completed_at
- skipped_at

Source rule:

```text
Initial Diagnosis -> self_reported_context
Observed Behavior -> observed_context
Inference -> inferred_context
```

Phase 1ではobserved / inferredを自動適用しない。

Guest draftはsession only。

### 9. 影響範囲

- First Run start redirect
- Account settings optional entry
- new Personalization routes / controller / service / views
- Plan create prefill only
- Behavior event enum
- User relation
- migration
- regression workflow
- docs

既存Plan / Workspace / GitHub runtimeを置き換えない。

### 10. 実装順序

1. WIP + canonical spec
2. DB / model
3. Context / seed / eligibility services
4. routes / controller
5. Bootstrap UI
6. First Run / account optional entry
7. Plan prefill handoff
8. telemetry
9. tests
10. docs sync / PR

---

# 2. Phase roadmap

## Phase 1 — Bootstrap Foundation [IMPLEMENTED / MANUAL REVIEW PENDING]

Goal:
「何をしたいか」を短く理解し、0からPlanを書かずに既存Plan作成へ進める。

Acceptance:

- [x] Study / Development / unsure複数選択
- [x] Skip
- [x] conditional minimum questions
- [x] auth context persistence
- [x] guest session draft
- [x] Study / Development deterministic seed
- [x] seed accept → existing Plan form
- [x] GitHub suggestion only for suitable Development context
- [x] preview拒否でGitHubへ遷移しない
- [x] existing user optional
- [x] source provenance: self_reported / observed / inferred
- [x] Initial Diagnosisを初期仮説として扱うcontract
- [x] responsive mobile-first implementation
- [x] telemetry implementation
- [x] regression tests written
- [x] CI green
- [ ] Manual Release Review: mobile / desktop first-use

## Phase 2 — Capability Activation Foundation [IMPLEMENTED / MANUAL REVIEW PENDING]

Goal:
共通 `Need → Preview → Interest → Readiness → Setup` contractをComponent / serviceとして強化。

Acceptance:

- [x] common Capability Activation service boundary
- [x] GitHub guided readiness steps
- [x] capability lifecycle state persistence
- [x] setup started telemetry
- [x] setup completed telemetry
- [x] setup abandoned telemetry
- [x] Plan作成後のGitHub interest handoff
- [x] one-repository auto-candidate behavior
- [x] multi-repository no-auto-selection
- [x] existing GitHub Workflow reuse
- [x] operator / entitlement / user owner distinction
- [x] connected fact -> observed_context
- [x] self-reported experience / Guidance remains unchanged
- [x] dismissed / abandoned no persistent nag
- [x] Release Gate contract
- [x] regression tests written
- [x] CI green
- [ ] Manual Release Review: github_capability_activation

Deferred within Phase 2:

- capability registry for multiple providers
- setup resume notification
- additional capability adapters

## Phase 3 — Living Profile Foundation [IMPLEMENTED / MANUAL REVIEW PENDING]

Goal:
Initial Diagnosisを初期仮説として、Observed BehaviorからContext再評価候補を作る。

Core contract:

```text
Initial Diagnosis
→ User Context v1
→ Actual Behavior
→ Context Update Candidate
→ Auto Apply OR optional User Confirmation
→ User Context v2
→ Plan / UI / Guidance / Feature Recommendation refresh
```

Implemented:

- [x] self_reported / observed / inferred source separationを維持
- [x] inferred_context.update_candidates contract
- [x] candidate risk / confidence / status
- [x] low-risk auto apply boundary
- [x] recent Study / Development Workspace priority
- [x] workspace_change refresh trigger
- [x] capability_readiness refresh trigger
- [x] manual refresh trigger
- [x] GitHub connected -> advanced Development support candidate
- [x] high-impact confirmation required
- [x] confirm / dismiss
- [x] dismissed candidate is not recreated from same candidate key
- [x] Growth Experience entry: 「Canoviaが気づいた変化」
- [x] self-reported experience preserved
- [x] Guidance Level preserved
- [x] telemetry
- [x] Release Gate contract
- [x] regression tests written
- [x] CI green
- [ ] Manual Release Review: living_profile_context_update

Implemented next slice — V58.28 Study Behavior Adapter:

- [x] assessed Study Practice >= 3
- [x] observed study_behavior.stage = practice_focused
- [x] recent attempt count / average observation
- [x] low-risk auto-applied candidate
- [x] Growth Experience: 「演習中心の学習段階に入っています」
- [x] self-reported Study stage preserved
- [x] Guidance preserved
- [x] duplicate candidate / auto-apply suppression
- [x] regression tests written
- [x] CI green
- [ ] Manual Release Review: study_behavior_personalization

Implemented next slice — V58.29 Plan Lifecycle Refresh Triggers:

- [x] new_plan trigger
- [x] plan_completed trigger
- [x] observed plan_lifecycle context
- [x] createOrFirst retry dedupe
- [x] final active Task completion detection
- [x] cancelled Task excluded
- [x] owner-only Context update
- [x] no silent profile creation
- [x] bounded completed Plan ID memory
- [x] regression tests written
- [x] CI green
- [ ] Manual Release Review: plan_lifecycle_personalization

Implemented next slice — V58.30 Plan Completion Fingerprint:

- [x] material structural completion fingerprint
- [x] runtime status/progress excluded from fingerprint
- [x] first completion revision 1
- [x] same-structure reopen / re-complete suppression
- [x] material edit → next completion revision
- [x] new Task → next completion revision
- [x] V58.29 legacy memory silent baseline
- [x] cancellation-based completion reevaluation
- [x] trigger Task / completed Task semantic separation
- [x] regression tests written
- [x] CI green
- [ ] Manual Release Review: plan_completion_fingerprint

Implemented next slice — V58.31 Return-after-Absence Trigger:

- [x] account-scoped presence baseline
- [x] daily last_seen update
- [x] 14-day deterministic long-absence threshold
- [x] coarse telemetry buckets
- [x] one refresh per return day
- [x] Instant Navigation prefetch ignored
- [x] no forced re-onboarding
- [x] self-reported Context preserved
- [x] Guidance preserved
- [x] no silent profile creation
- [x] regression tests written
- [x] CI green
- [ ] Manual Release Review: return_after_absence_personalization

Implemented next slice — V58.32 Candidate Evidence Reopen:

- [x] Candidate signal_strength
- [x] Candidate evidence_fingerprint
- [x] Candidate evidence_revision
- [x] Dismiss snapshots current evidence strength
- [x] same evidence stays dismissed
- [x] same-strength fingerprint change stays dismissed
- [x] stronger signal reopens Candidate
- [x] pending Candidate silently updates current evidence snapshot
- [x] legacy dismissed Candidate compatibility
- [x] safe Development observed aggregates
- [x] reopened UI explanation
- [x] self-reported experience preserved
- [x] Guidance preserved
- [x] regression tests written
- [x] CI green
- [ ] Manual Release Review: context_candidate_evidence_reopen

Implemented next slice — V58.33 Study Review Cycle Personalization:

- [x] assessed Study Practice >= 5
- [x] distinct practice days >= 2
- [x] observed span >= 3 calendar days
- [x] same-day volume does not imply review cycle
- [x] observed spaced-review facts
- [x] low-risk auto-applied Candidate
- [x] Growth Experience: 「復習サイクルに入っています」
- [x] retention / mastery is not auto-claimed
- [x] self-reported Study stage preserved
- [x] Guidance preserved
- [x] later attempts refresh observed metrics
- [x] duplicate Candidate / Auto Apply suppression
- [x] regression tests written
- [x] CI green
- [ ] Manual Release Review: study_review_cycle_personalization

Implemented next slice — V58.34 Long Usage Window:

- [x] account-scoped first_seen baseline
- [x] active-day count
- [x] 30-day usage span threshold
- [x] minimum 6 active days
- [x] same-day navigation dedupe
- [x] V58.31 conservative backfill
- [x] long-absence precedence
- [x] one-time silent refresh
- [x] coarse telemetry buckets
- [x] no questionnaire / forced re-diagnosis
- [x] no silent profile creation
- [x] V58.32 duplicate reopen copy cleanup
- [x] regression tests written
- [x] CI green
- [ ] Manual Release Review: long_usage_window_personalization

Implemented next slice — V58.35 Confidence Calibration:

- [x] deterministic confidence calibration service
- [x] signal strength 1 / 2 / 3 -> low / medium / high
- [x] calibration version + basis metadata
- [x] pending Candidate recalibration
- [x] stronger-evidence reopen recalibration
- [x] confirmed Candidate compatibility
- [x] high-risk confirmation boundary preserved
- [x] self-reported Context / Guidance preserved
- [x] regression assertions added
- [ ] CI green
- [ ] Manual Release Review: personalization_confidence_calibration

Deferred next slices:

- stronger Development behavior signal expansion:
  - sustained activity across longer windows
  - additional repository complexity signals
- Guidance Level update candidate
- Plan-direction update candidate
- Feature Recommendation priority adapter
- Growth Experience copy variants

Do not implement periodic questionnaire spam.
Do not auto-reclassify experience level.

## Phase 4 — Domain Expansion [PLANNED]

Candidates:

- Career
- work / business
- habit / general
- richer Plan Archetypes
- AI-assisted seed refinement

---

# 3. Phase 1 product rules

- **Initial Diagnosis is a starting hypothesis, not a permanent profile.**
- 初回診断はユーザーを固定的に分類せず、伴走開始の初期仮説として扱う
- diagnosisはPersonality typeを出さない
- unanswered fieldはunknownとして扱う
- Skipは常に可能
- 「まだ分からない」選択時は追加質問を最小にする
- GitHubなしでもDevelopment Planを作れる
- GitHub Value Previewはeligible actorだけ
- Interest YesでもPhase 1ではGitHubへ強制遷移しない
- Plan Seedは完成Planではなく編集可能な初期骨格
- existing userへ強制表示しない
- feature eligibilityはRelease Level / Feature Flag / Entitlementと別軸

---

# 4. NEXT

Current state:

```text
PR #294 = merged
PR #295 = merged
PR #296 = merged
PR #297 = merged
PR #298 = merged
PR #299 = merged
PR #300 = merged
PR #301 = merged
PR #302 = merged
PR #303 = merged

Phase 1 automated validation = GREEN
Phase 2 automated validation = GREEN
Phase 3 Foundation automated validation = GREEN
V58.28 Study Behavior Adapter = GREEN
V58.29 Plan Lifecycle Refresh Triggers = GREEN
V58.30 Plan Completion Fingerprint = GREEN
V58.31 Return-after-Absence Trigger = GREEN
V58.32 Candidate Evidence Reopen = GREEN
V58.33 Study Review Cycle Personalization = GREEN
V58.34 Long Usage Window = GREEN
V58.35 Confidence Calibration = implemented / CI pending

Future Architecture remains Concept / Architecture preservation context.
V58.35 only updates the Overview's Current reference.
```

Current next action:

```text
1. Review V58.35 CI and merge its PR when green.
2. Keep WIP.
3. Manual Release Review remains:
   - personalization_first_use
   - github_capability_activation
   - living_profile_context_update
   - study_behavior_personalization
   - plan_lifecycle_personalization
   - plan_completion_fingerprint
   - return_after_absence_personalization
   - context_candidate_evidence_reopen
   - study_review_cycle_personalization
   - long_usage_window_personalization
   - personalization_confidence_calibration
4. Recommended next small slice after V58.35:
   - stronger Development behavior signal expansion
5. Do not use Future Architecture docs as implementation requirements.
```

When resuming after interruption:

1. Read this file
2. Check unchecked Phase 1 acceptance items
3. Check latest PR / main
4. Continue from NEXT
5. Update this file before stopping

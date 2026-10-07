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

## Phase 2 — Capability Activation Foundation [IMPLEMENTED / CI PENDING]

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
- [ ] CI green
- [ ] Manual Release Review: github_capability_activation

Deferred within Phase 2:

- capability registry for multiple providers
- setup resume notification
- additional capability adapters

## Phase 3 — Living Profile [PLANNED]

Goal:
Initial Diagnosisを初期仮説として、Observed BehaviorからContext再評価候補を作る。

Core contract:

```text
Initial Diagnosis
→ User Context v1
→ Actual Behavior
→ Context Update Candidate
→ optional User Confirmation
→ User Context v2
→ Plan / UI / Guidance / Feature Recommendation refresh
```

Source separation:

- self_reported / user_answered
- observed
- inferred / estimated
- confidence

Low-risk auto update candidates:

- recent domain
- recommendation ordering
- Feature Recommendation priority

Confirmation candidates:

- major experience-level change
- Plan-direction impact
- exposing advanced capability groups
- inferred user intent

Refresh trigger candidates:

- Plan completion
- new Goal / Plan
- long usage window
- return after long absence
- new domain adoption
- clear behavior shift
- advanced capability readiness reached

Growth Experience candidates:

- 「最近、開発の進め方が変わってきました」
- 「演習中心の学習段階に入っています」
- 「GitHub連携が役立つ段階になっています」

Do not implement periodic questionnaire spam.

Other candidates:

- Development behavior signals
- Study behavior signals
- guidance adjustment
- recommendation cooldown / dismiss memory
- feature readiness refresh

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
PR #294
V58.25 automated validation = GREEN

Passed:
- PHP lint
- Blade view cache
- migration
- PersonalizationBootstrapV5825Test
- FirstRunUxV4123Test
- ConversationalOnboardingV4116Test
- V58.20–V58.24 regressions
- Home continuity
- Specialized Workspace regression
- Production Migration Recovery
```

Current state:

```text
PR #294 = merged
Phase 1 automated validation = GREEN
Phase 2 implementation = complete on feature/v58-26-capability-activation-foundation
```

Current next action:

```text
1. Create V58.26 PR.
2. Run CI:
   - CapabilityActivationV5826Test
   - Personalization V58.25 regression
   - Release Review regression
   - Development Workspace regression
   - Migration Recovery
3. Fix Phase 2 blockers only.
4. After CI green:
   - mark Phase 2 CI green
   - keep WIP
5. Manual Release Review remains:
   - personalization_first_use
   - github_capability_activation
6. Phase 3 Living Profile remains deferred.
   Initial Diagnosis must remain a starting hypothesis, not a permanent profile.
```

When resuming after interruption:

1. Read this file
2. Check unchecked Phase 1 acceptance items
3. Check latest PR / main
4. Continue from NEXT
5. Update this file before stopping

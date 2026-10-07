# Canovia Personalization Specification

> Status: V58.32 Candidate Evidence Reopen implemented; automated validation pending  
> Updated: 2026-10-07

## 1. Purpose

Canoviaを「高機能なのでユーザーが設定を理解する必要があるサービス」にしない。

Goal:

> 高機能だが、そのユーザーに今必要なものだけが表に出る。

Personalizationは単なる初回アンケートではない。

入力を:

```text
Plan Seed
Feature Eligibility
Guidance Level
Recommended Surfaces
```

へ変換し、その後のCanovia体験へ利用する。

## 2. Responsibility boundary

Personalization Eligibilityは既存runtime accessと分離する。

```text
Release Level
= 世に出してよいか

Feature Flag
= runtimeで機能をONにするか

Entitlement
= actorが利用権を持つか

Personalization Eligibility
= 今このactorへ提案する価値があるか
```

Eligibility=trueでもAccessを付与しない。

## 3. Bootstrap UX

Principles:

- Progressive
- Skippable
- Immediately Useful
- No feature overload

Phase 1 domains:

- Study
- Development
- まだ分からない

Study / Developmentはmulti-select可能。
`unsure` は単独または他domainと共存してもよいが、追加質問を増やす理由には使わない。

## 4. Personalization Context principle

> **Initial Diagnosis is a starting hypothesis, not a permanent profile.**

日本語:

> **初回診断はユーザーを固定的に分類するものではなく、Canoviaが伴走を始めるための初期仮説として扱う。**

Canoviaは初回回答を永久的な事実として扱わない。

例:

```text
初回
self_reported development experience = beginner

実利用
GitHub利用増加
Repository複数
継続的なPR
高度Capability利用

将来
observed development maturity = higher
inferred recommendation = advanced support candidate
```

初回回答は「当時本人がそう回答した」という事実として保持し、
観測や推定で上書きしない。

### 4.1 Source provenance

Auth userはone-to-one Personalization Contextを持つ。

Persisted source layers:

```text
self_reported_context
observed_context
inferred_context
```

意味:

```text
self_reported
= user_answered / 本人が明示した情報

observed
= Canovia内の実利用から直接確認できた事実

inferred
= self-reported + observed等から推定したContext
```

将来のinferred entryは必要に応じてconfidenceを持てる。

例:

```text
self_reported_experience = beginner
observed_experience = intermediate
inferred_experience = intermediate
confidence = high
```

confidenceは推定の確からしさであり、本人回答を上書きする権限ではない。

Derived state:

```text
guidance_level
recommended_surfaces
feature_readiness
```

Phase 1では:

- Initial Diagnosisは `self_reported_context` のみ更新
- `observed_context` / `inferred_context` は空でよい
- Guidance / Plan Seedはself-reportedから決定論的に導出
- Behavior inference engineは実装しない

Guestはsession draftを利用する。

### 4.2 Context revision

`context_revision` を持ち、Personalization Contextが将来更新される前提を明示する。

`version` はschema / contract version。
`context_revision` は同一ユーザーContextの更新世代。

この2つを混同しない。

### 4.3 Context Update Loop

Long-term contract:

```text
Initial Diagnosis
↓
User Context v1
↓
Actual Behavior
↓
Context Update Candidate
↓
必要ならUser Confirmation
↓
User Context v2
↓
Plan / UI / Guidance / Feature Recommendation更新
```

Personalizationを一度きりのOnboardingとして扱わない。

### 4.4 Auto update vs confirmation

低リスクで自動調整可能な候補:

- 最近利用するカテゴリ
- おすすめ表示順位
- Feature Recommendation priority
- low-impact surface ordering

User Confirmationを挟めるべき候補:

- 経験レベルを大きく変更
- Plan方針へ影響
- 新しい高度機能群を表示
- ユーザー意図の推測が必要
- high-impact Guidance変更

将来例:

> 最近の利用状況を見ると、より高度な開発支援が役立ちそうです。表示しますか？

### 4.5 Refresh triggers

大量の定期アンケートは行わない。

Context再評価候補:

- Plan完了
- 新しいGoal / Plan作成
- 長期間利用
- 長期離脱から復帰
- 新カテゴリ利用開始
- 行動パターンの明確な変化
- 高度Capabilityの利用条件成立

Triggerは再評価の契機であり、毎回質問UIを出す意味ではない。

### 4.6 Growth Experience

Personalization更新を裏側のRecommendation処理だけに閉じない。

必要に応じ将来:

- 「最近、開発の進め方が変わってきました」
- 「演習中心の学習段階に入っています」
- 「GitHub連携が役立つ段階になっています」

のように、Canoviaが成長・変化に気づいたことを伝えられる。

Phase 1ではこのUIを実装しない。

### 4.7 Plan philosophy alignment

CanoviaのPlan思想と同じ。

```text
Initial Diagnosis = Initial Plan
Observed Behavior = Actual Result
Context Update = Replanning
```

最初のPlanを絶対視しないのと同様、Initial Diagnosisも絶対視しない。


## 5. Guidance Level

Internal only.

Initial values:

- guided
- standard
- compact

User-facingに「初心者タイプ」等のラベルを出さない。

Phase 1 deterministic rule:

- unsure / beginner signal → guided
- experienced Development with repository readiness → compact候補
- otherwise standard

## 6. Plan Seed

Plan Seedは完成Planではない。

Contains:

- workspace mode
- title suggestion
- description / context
- suggested phases
- category
- deadline if known

Phase 1はdeterministic archetype。

### Study

Qualification / exam-oriented:

```text
現在地確認
→ 弱点探索
→ 弱点補完
→ 分野横断演習
→ 本番形式
→ 直前調整
```

Generic Study:

```text
範囲確認
→ 基礎理解
→ 演習
→ 復習
```

### Development new

```text
Idea
→ Requirements
→ MVP Scope
→ Implementation
→ Testing
→ Release
```

### Development existing

```text
Current State
→ Issue整理
→ 優先順位
→ 改善
→ 検証
→ Release
```

Seed accepted:

```text
Personalization
→ plan_create_prefill
→ existing Plan create form
```

新しいPlan creatorを作らない。

## 7. Feature Eligibility — GitHub

Phase 1の最初のCapability example。

Development actorで:

- GitHubを利用
- Repositoryあり
- Development contextが明確

ならValue Preview候補。

GitHub未使用 / Repositoryなしactorへ接続を要求しない。

Phase 1:

```text
Need / readiness detected
→ Value Preview
→ Interest yes/no
→ save preference
→ normal flow
```

Interest YesでもGitHubへ強制遷移しない。

Guided SetupはPhase 2。

## 7.1 Capability Activation Foundation

V58.26でPhase 2の最初の実装を追加する。

Common lifecycle:

```text
Need
→ Preview
→ Interest
→ Readiness
→ Setup
→ Completed
```

GitHub Integrationを最初のadapterとして扱う。

State provenance:

```text
Interest
= self_reported

Activation lifecycle
= feature_readiness

Actual connection
= observed
```

Setup started / completed / abandonedは、本人の経験値やGuidanceを直接変更しない。

Repository candidate:

- 0件: Repository登録へ誘導
- 1件: candidateとして自動選択
- 複数: 自動選択しない

自動候補化はexternal action実行ではない。
GitHub App install / connectはユーザーの明示操作を維持する。

Canonical detail:

- `docs/V58.26_CAPABILITY_ACTIVATION_FOUNDATION.md`

## 7.2 Living Profile Foundation

V58.27でContext Update Loopの最小Foundationを追加する。

```text
Observed Fact
→ Context Update Candidate
→ Auto Apply OR User Confirmation
→ Derived Context update
```

Candidateは:

```text
inferred_context.update_candidates
```

へ保存する。

### Low risk

例:

```text
explicit workspace selection
→ recent domain
→ recommended_surfaces order
```

自動適用可能。

### High impact

例:

```text
GitHub connected
→ advanced Development support candidate
```

User confirmation必須。

確認しても:

- self-reported experience
- Initial Diagnosis
- Guidance Level

は変更しない。

V58.27 Refresh Trigger:

- workspace_change
- capability_readiness
- manual

Canonical:

- `docs/V58.27_LIVING_PROFILE_FOUNDATION.md`

## 7.3 Study Behavior Personalization Adapter

V58.28でStudyのdurable behaviorをLiving Profileへ接続する。

Deterministic first rule:

```text
authenticated Study user
+ same Study Plan
+ assessed StudyPracticeAttempt >= 3
→ observed_context.study_behavior.stage = practice_focused
```

これは能力評価ではなく、実際の学習行動の観測。

Derived low-risk state:

```text
feature_readiness.study.practice_focused.enabled = true
```

Living Profileでは:

> 演習中心の学習段階に入っています

というGrowth Experienceを表示できる。

V58.28は以下を変更しない:

- self-reported Study stage
- Guidance Level
- Plan / Task progress
- Study Practice strategy
- weakness / mastery model

Canonical:

- `docs/V58.28_STUDY_BEHAVIOR_PERSONALIZATION.md`

## 7.4 Plan Lifecycle Refresh Triggers

V58.29でCanovia CoreのPlan lifecycleをLiving Profileの再評価契機へ接続する。

Implemented:

```text
new Plan created
→ observed plan lifecycle
→ refresh trigger = new_plan

last active Task completed
→ observed Plan completion
→ refresh trigger = plan_completed
```

重要:

- Plan内容を自動変更しない
- self-reported Contextを変更しない
- Guidanceを変更しない
- Plan completion用の新しいDB statusを追加しない

Plan completionはcurrent Task stateから判定する。

```text
non-cancelled Taskが1件以上
+
all non-cancelled Tasks are done / 100%
```

同一Planのreopen → re-completeはV58.29では再発火しない。
将来evidence fingerprint / completion revisionで拡張する。

Canonical:

- `docs/V58.29_PLAN_LIFECYCLE_PERSONALIZATION.md`

## 7.5 Plan Completion Fingerprint

V58.30でPlan completionをPlan IDだけでなくmaterial structural fingerprintでversion化する。

```text
same structure
complete → reopen → re-complete
= same revision

material Plan / Task change
→ re-complete
= next completion revision
```

FingerprintはPlan主要条件 + non-cancelled Task構造から作る。

除外:

- status
- progress
- remaining time
- updated_at

V58.29 legacy memoryは最初のpost-upgrade completionでsilent baseline化し、偽の新completionを発火しない。

Cancellationによって最後の未完了Taskが除外され、残るactive Taskが全て完了した場合もPlan completionとして再評価する。

Canonical:

- `docs/V58.30_PLAN_COMPLETION_FINGERPRINT.md`

## 7.6 Return-after-Absence Trigger

V58.31で長期間利用後の復帰をLiving Profile再評価の節目へ接続する。

Account-scoped observed presence:

```text
observed_context.presence.last_seen_at
```

初回tracked visitはbaselineのみ。

```text
absence < 14 days
→ presence update only

absence >= 14 calendar days
→ trigger = return_after_absence
→ deterministic Living Profile refresh
```

Telemetryはexact daysではなく:

- 14_29
- 30_59
- 60_plus

のbucketだけを使う。

V58.31は:

- forced re-onboardingしない
- self-reported Contextを変更しない
- Guidanceを変更しない
- Planを変更しない
- Personalization未設定userをsilently profileしない

Canonical:

- `docs/V58.31_RETURN_AFTER_ABSENCE_PERSONALIZATION.md`

## 7.7 Candidate Evidence Revision / Reopen

V58.32でhigh-impact Context Update CandidateへEvidence revisionを追加する。

Core rule:

```text
dismissed candidate
+ same / similar evidence
→ stay dismissed

dismissed candidate
+ materially stronger evidence
→ reopen
```

Candidate metadata:

- signal_strength
- evidence_fingerprint
- evidence_revision
- dismissed evidence snapshot
- reopened_at / reopen_reason

Reopenには:

```text
current signal_strength > dismissed signal_strength
AND
evidence fingerprint changed
```

の両方を要求する。

最初の適用対象:

- `development_advanced_support`

Development observed facts:

- connected repository count
- recent Development activity count
- recent PR / Commit observation count

重要:

Repository activityはuser authorshipやexperience levelを意味しない。
高度なDevelopment支援が役立つ可能性のsignalとしてのみ使う。

Canonical:

- `docs/V58.32_CONTEXT_CANDIDATE_EVIDENCE_REOPEN.md`

## 8. Existing User

Existing userへBootstrapを強制しない。

Account / Settingsから:

> Canoviaをあなた向けに調整する

として任意起動可能。

既存Plan / Workspace preference / GitHub connectionを破壊しない。

## 9. Telemetry

Personalization / Living Profile server-side events:

- personalization_started
- personalization_completed
- personalization_skipped
- plan_seed_shown
- plan_seed_accepted
- plan_created_from_seed
- capability_preview_shown
- capability_interest_yes
- capability_interest_no
- capability_setup_started
- capability_setup_completed
- capability_setup_abandoned
- context_refresh_triggered
- context_update_candidate_created
- context_update_auto_applied
- context_update_confirmed
- context_update_dismissed

Metadataはdomain / seed key / capability key / guidance level等の安全なenumerationに限定する。
free text answerはTelemetryへ保存しない。

## 10. Privacy

不要なセンシティブ情報を収集しない。

Phase 1 questionsは:

- purpose/domain
- deadline
- weekly capacity bucket
- current stage
- experience
- GitHub use / repository readiness

等、Plan / feature guidanceに必要な範囲に限定する。

## 11. WIP continuation

Implementation progress / phase split:

`docs/wip/PERSONALIZATION_BOOTSTRAP_IMPLEMENTATION.md`

全Phaseが恒久仕様へ吸収されるまでWIPを残す。

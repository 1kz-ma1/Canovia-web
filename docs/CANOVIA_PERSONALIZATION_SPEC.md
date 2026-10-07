# Canovia Personalization Specification

> Status: V58.25 Phase 1 implementation in progress  
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

## 4. Context

Auth userはone-to-one Personalization Contextを持つ。

Concept:

```text
domains[]
common_context{}
domain_context{}
guidance_level
recommended_surfaces[]
feature_readiness{}
completed_at
skipped_at
```

Phase 1ではanswer history tableを作らず、current contextをJSONとして保持する。

初回回答を永久固定しない。
将来Observed Behaviorから更新可能なモデルとして扱う。

Guestはsession draftを利用する。

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

## 8. Existing User

Existing userへBootstrapを強制しない。

Account / Settingsから:

> Canoviaをあなた向けに調整する

として任意起動可能。

既存Plan / Workspace preference / GitHub connectionを破壊しない。

## 9. Telemetry

Phase 1 server-side events:

- personalization_started
- personalization_completed
- personalization_skipped
- plan_seed_shown
- plan_seed_accepted
- capability_preview_shown
- capability_interest_yes
- capability_interest_no

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

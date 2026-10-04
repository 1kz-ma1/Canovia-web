# Canovia Execution Ecosystem — Temporary Design Draft

> Status: retained concept / investigation brief  
> Created: 2026-10-04  
> Implemented foundation: V55.6  
> Purpose: preserve the broader Execution Ecosystem concept, future phases, monetization ideas, and repository-fit investigation.  
> Canonical implemented V55.6 contract: `docs/V55.6_EXECUTION_ECOSYSTEM_FOUNDATION.md`. Product-level authority remains `docs/CANOVIA_PRODUCT_SPEC.md`.

---

## 1. 背景

Canoviaは現在、目標・計画・タスク・実績・予定変更を一貫して扱い、ユーザーが「次に何をすべきか」を判断しやすくする伴走型サービスとして設計している。

今後価値を高めるうえでは、単なる計画支援だけでなく「実行」まで支援できることが重要になる。

ただし、実行手段は非常に多様である。

例:

- 資格・学習の問題演習
- 暗記
- タイピング練習
- プログラミング
- GitHub上での開発
- 読書
- 語学学習
- デザイン制作
- 就職活動
- 運動
- その他専門作業

これらすべてをCanovia自身が実装することは、個人開発として現実的ではない。

また、各分野では既に専門性の高い優れたサービスが存在する。

そのためCanoviaは、

**「すべての実行手段を自分で作る」のではなく、「最適な実行手段を計画へ接続し、その結果を再びCanoviaへ戻す」**

という方向を目指す。

---

## 2. 基本思想

```text
Goal
 ↓
Plan
 ↓
Action
 ↓
Execution
 ↓
Evidence / Result
 ↓
Progress Update
 ↓
Replan
```

Canoviaが中核として担当するもの:

- Goal
- Plan
- Actionの決定
- Execution先の選択
- 実績の統合
- 進捗評価
- Replan

Executionそのものは、

- Canovia Native
- External Application

のどちらでもよい。

重要なのは、

**Canoviaの中ですべてを実行できることではなく、Canoviaから最適な方法で実行できること。**

---

## 3. プロダクトとしての狙い

```text
Canovia
目標・計画整理
 ↓
専門アプリ
実行
 ↓
Canovia
実績・進捗更新
 ↓
Canovia
次の計画・優先順位を調整
 ↓
再び実行
```

Canoviaは、

**「ユーザーが長く滞在する場所」ではなく「ユーザーが何度も帰ってくる場所」**

になる。

専門アプリと利用時間を奪い合う必要はない。

---

## 4. Execution Ecosystem

将来的に、外部開発者が自分のサービスをCanoviaへ接続できる仕組みを提供する。

例:

- 問題演習サービス
- タイピングサービス
- 学習アプリ
- コーディングサービス
- 語学アプリ
- フィットネスサービス

```text
Canovia
 ↓
Execution App
 ↓
Activity Result
 ↓
Canovia
```

---

## 5. Planning UX と Execution UX を分離する

最重要UX原則:

**計画時には選択肢を広げる。実行時には選択肢を狭める。**

実行するたびに以下のような判断を要求しない。

```text
Canoviaでやりますか？
App Aですか？
App Bですか？
```

---

## 6. 計画作成時の Execution Setup

計画作成時、Canoviaが計画内容を分析し、利用可能な実行手段を提案する。

例:

```text
応用情報技術者試験 合格

この計画で利用できる実行手段

問題演習
・Canovia AI演習
・○○過去問アプリ

暗記
・Canovia
・△△Flashcards

プログラミング演習
・App X
```

このタイミングでユーザーが以下を選択する。

- Canoviaを使う
- 外部サービスを利用する
- アプリをインストールする
- アカウントを接続する
- 今回は利用しない

ここでExecution Pathを確定する。

---

## 7. 普段の実行時

Execution Setup完了後は、外部アプリを使うかどうかを毎回確認しない。

```text
今日のタスク

DB過去問 10問

[開始]
```

「開始」で既定Execution Pathへ自動ルーティングする。

- External Provider設定済み → 外部アプリを開く
- Canovia Native → Canovia内で開始
- 特別なProviderなし → 通常Taskとして処理

---

## 8. External Appを必須にしない

外部アプリはOptional Enhancementとして扱う。

```text
推奨アプリあり
 ↓
利用する
 → Execution Pathへ登録

利用しない
 → Canovia Nativeまたは通常タスクとして実行
```

「Canoviaを使うために複数アプリの導入が必要」という状態にはしない。

---

## 9. Execution Resolver

```text
Task
 ↓
Execution Requirement
 ↓
Execution Resolver
 ├ Canovia Native
 ├ Integration A
 ├ Integration B
 └ Integration C
 ↓
Selected Execution Path
```

初期ルール:

1. ユーザー指定の既定方法がある → それを利用
2. Canovia Nativeで十分対応可能 → Native
3. ユーザーが接続済みの専門サービスがある → 接続サービス
4. 候補が複数ある → 計画作成時のみ候補を提示
5. 特別な実行手段がない → 通常Taskとして処理

高度なAI選択やスコアリングは初期対象外。

---

## 10. Capabilityベースで管理する

Providerを単なるアプリカテゴリではなくCapabilityで表現する。

例:

```text
study.practice
study.practice.exam
study.practice.database
study.flashcard
typing.practice
coding.practice
coding.repository
language.vocabulary
fitness.running
```

将来:

```text
task
 ↓
required capabilities
 ↓
compatible execution providers
```

---

## 11. 同種アプリが複数存在する場合

「最強アプリを1個だけ決める」設計にはしない。

例:

```text
Typing App A
→ 初心者向け

Typing App B
→ 高度な速度分析

Typing App C
→ プログラミング向け

Canovia Native
→ 5分程度の軽い練習
```

将来的なResolver input:

- ユーザー
- 目標
- 計画フェーズ
- 現在の能力
- 利用可能時間
- 既存の利用環境

---

## 12. Execution Pathを再確認する条件

毎回再提案しない。

再評価条件の例:

- ユーザーが連携解除した
- アプリを削除した
- 現在の方法で成果が出ていない
- 計画フェーズが変わった
- ユーザーが変更を希望した
- 従来できなかった重要なExecution Capabilityが追加された

Marketplaceへ新規アプリが追加された程度では実行中ユーザーへ割り込まない。

---

## 13. Activity Integration

最初に実現すべき外部連携は、外部サービスからCanoviaへ「何を実行したか」を返すこと。

最小Activity例:

```json
{
  "type": "typing_practice",
  "title": "英文タイピング",
  "status": "completed",
  "started_at": "2026-10-04T20:00:00+09:00",
  "completed_at": "2026-10-04T20:21:00+09:00",
  "duration_seconds": 1260,
  "metrics": {
    "cpm": 263,
    "accuracy": 0.97
  }
}
```

External Provider側にCanovia内部の複雑なPlan / Task構造を理解させない。

外部側は基本的に、

- 誰が
- 何を
- どれくらい行い
- 結果がどうだったか

だけ送る。

Plan / Task / Goalとの関連付けはCanovia側が担う。

---

## 14. Integrationの発展段階

### Level 1: Activity Integration

```text
External App
 ↓
Activity
 ↓
Canovia
```

実行結果だけ受信する。最初のMVP候補。

### Level 2: Launch Integration

```text
Canovia Task
 ↓
Start
 ↓
External App
```

Deep Link / Universal Link / Web URL等を利用。

### Level 3: Adaptive Execution

将来:

```json
{
  "capability": "study.practice.database.join",
  "difficulty": 0.6,
  "duration_minutes": 20
}
```

外部サービスが条件に合わせたセッションを生成し、結果をCanoviaへ返す。初期対象外。

---

## 15. 開発者側のセルフサービス化

将来のDeveloper Portal:

1. Developer登録
2. Application登録
3. Client ID / Secret発行
4. OAuth Redirect URL登録
5. Capability登録
6. Activity Schema設定
7. Sandbox Test
8. Review
9. Publish

運営者との個別接続作業を最小化する。

---

## 16. Canoviaの既存カテゴリとの関係

### Learning

候補Execution:

- 問題演習
- 暗記
- 動画
- 電子書籍
- タイピング
- プログラミング演習

学習はExecution Ecosystemの最初の検証領域として有力。

### Development

Canovia上でIntegration開発自体をPlan化できる。

```text
Canovia Integrationを実装する

Step 1 OAuth
Step 2 Activity API
Step 3 Capability登録
Step 4 Sandbox
Step 5 Review
```

Canovia自身がCanovia Integration開発の支援ツールにもなる。

---

## 17. エコシステムとしての価値

```text
Canovia Users
      ↓
Execution Appsへの送客
      ↓
Developerの価値向上
      ↓
Integration増加
      ↓
CanoviaのExecution能力向上
      ↓
Canovia Usersの価値向上
```

狙いは、

**Canoviaの開発速度とCanoviaのExecution能力向上速度を分離すること。**

---

## 18. マネタイズ構想

### User Side

課金対象はCanoviaのオーケストレーション価値。

例:

- 高度な計画
- AIによる再計画
- 自動実績取得
- 複数サービス横断管理
- 自動化

外部アプリ利用ごとの課金にはしない。

### Developer Side

#### Free Integration

初期は基本接続無料。

- OAuth
- Activity API
- Launch Integration
- 基本Capability登録
- Developer Listing

#### Referral Fee

Canovia経由の有料契約成果報酬。

#### Developer Pro

将来のB2B SaaS例:

- Conversion Analytics
- Retention Analytics
- Capability Analytics
- A/B Testing
- Advanced API
- 大量API利用
- Webhook拡張
- Team Management

#### Sponsored Placement

推薦とは完全に分離した広告枠。

---

## 19. 非常に重要な推薦原則

**Recommendation != Advertising**

金銭を支払ったサービスを「最適」と判定しない。

```text
Execution Recommendation
!=
Sponsored Recommendation
```

Sponsoredは明確にSponsored表記する。

---

## 20. 初期段階ではMarketplaceを作らない

初期Non-goals:

- App Store形式Marketplace
- Reviews
- Ratings
- Ranking
- Revenue Share管理
- 高度な検索
- 大規模Developer Portal
- 複雑な審査システム

段階:

```text
Phase 1
Activity Integration

Phase 2
Launch Integration

Phase 3
Execution Setup UI

Phase 4
Developer Portal

Phase 5
Integration Directory

Phase 6
Marketplace / Monetization
```

---

## 21. MVP候補

今回すぐにフル実装しない。

まずCanovia内部でExecution概念を持てる構造を作る。

初期モデル候補:

```text
ExecutionProvider

id
name
type
  native
  external

capabilities[]

launch_type
launch_url

status
```

```text
PlanExecutionPreference

plan_id
capability
provider_id
user_selected
```

```text
ExecutionActivity

user_id
provider_id
type
title
started_at
completed_at
duration
status
metrics
evidence
external_id
```

名称は仮。既存ドメインモデルに合わせて最適化する。

---

## 22. UI MVP

候補はPlan作成後のExecution Setup。

```text
計画の準備

この計画を進めるために利用できる機能があります。

問題演習
● Canovia AI演習
○ ○○過去問

暗記
● Canovia
○ △△Flashcards

[この設定で開始]
```

外部Integration未実装時は、

- Internal Provider
- Mock External Provider

で設計検証してよい。

---

## 23. 今回の実装で重視すること

一気に実装しない。

まず現在のCanoviaアーキテクチャを確認し、以下を検討する。

1. Executionという概念をどこへ置くべきか
2. 現在のTask / Plan / Activity / Evidenceモデルとの関係
3. 学習UIにExecution Setupをどう組み込むか
4. 開発カテゴリへ将来的にDeveloper Integration導線を置ける構造か
5. External Provider追加時に既存コードを大幅変更しなくて済むか

そのうえで、

**最小限のDomain Model + Interfaceだけ先に作る**

方針を優先する。

---

## 24. Non-Goals

現時点では目的としない:

- 外部アプリMarketplace完成
- 外部Developer登録
- Revenue Share
- Developer Pro
- Sponsored Placement
- 完全なOAuth Platform
- 外部サービスとの実接続
- AIによる高度なExecution Resolver
- すべてのカテゴリ対応

将来構想として設計上阻害しないことだけ確認する。

---

## 25. 最重要設計原則

1. **Canoviaは実行手段を独占しない。**
2. **Canovia NativeとExternal Appを同じExecution Providerとして扱える構造にする。**
3. **計画時に選択し、実行時には判断を増やさない。**
4. **外部サービスを導入しなくても計画は成立する。**
5. **外部サービス側にCanovia内部構造を過剰に理解させない。**
6. **Capabilityを中心にProviderを接続する。**
7. **RecommendationとAdvertisingを分離する。**
8. **最初からMarketplaceを作らない。**
9. **Canovia自身の開発速度に依存せずExecution能力が増える構造を目指す。**
10. 最終的なCanoviaの役割は、**「何でもCanovia内でできるサービス」ではなく、「目標から実行までを最適な手段でつなぎ、結果を次の計画へ戻すオーケストレーションレイヤー」**とする。

---

## 26. Repository Investigation Request

実装前に以下を確認する。

1. 既存構造の調査
2. 影響範囲整理
3. Domain Model案
4. UI導入箇所
5. MVPとして今回実装すべき範囲
6. 将来拡張時に残すInterface

特に以下を重視する。

- Plan / Task / Activity / Evidence
- Learning UI / Study Workspace / Study Activity
- Development UI / Development Workspace / Execution Orchestration
- 現行Execution Modeとの責務分離
- Existing UX / compatibilityを壊さないこと

既存仕様やUXを変更する場合は、変更理由と影響範囲を明示する。

---

## 27. Repository Investigation Findings

### 27.1 Product-level direction is already compatible

The current canonical product specification already states that Canovia should not embed every execution environment.

The existing product loop is effectively:

```text
Input
→ Plan
→ Task / Current Action
→ Specialized Execution / Guided Execution / Timer
→ Evidence / WorkLog
→ Intelligence / Plan update
→ Next Action
```

The Execution Ecosystem is therefore not a replacement architecture.

It should be treated as an extension of the existing execution/evidence boundary so that the specialized execution step can resolve to either:

```text
Canovia Native
or
External Provider
```

without changing the higher-level Goal / Plan / Intelligence model.

### 27.2 Workspace Mode and Execution Mode are already separated

V55.5 established the current semantic boundary:

```text
Workspace Mode
→ domain state / Readiness / Gap / Decision / Current Action

Execution Mode
→ how the selected Task is executed

Operation surface
→ where the actual work happens
```

Current Execution Mode keys remain:

- study
- development
- career
- general

The Execution Ecosystem must sit **below Execution Mode**, not replace it.

Proposed layering:

```text
Workspace
→ Current Action / Task
→ Execution Mode
→ Required Capability
→ Execution Resolver
→ Execution Provider
→ Operation Surface
→ Activity / Evidence
→ Intelligence
```

### 27.3 Runtime already follows the "one primary action" principle

`PlanToolService` builds Task-level execution tools.

`ExecutionActionPolicyService` then reduces those choices to one primary execution action, with Timer as fallback.

The current runtime already avoids repeatedly asking the user which tool to use.

Therefore the Execution Ecosystem should preserve the current contract:

> one Task → one primary Start action at execution time

Provider choice belongs in setup/configuration, not in the normal Start flow.

### 27.4 Study already has a deterministic execution-kind resolver

`StudyActivityPolicyService` currently classifies a Task into:

- `question_practice`
- `recall`
- `resource_study`

with deterministic fit scoring.

This is a natural source for initial execution capability requirements.

The existing Study classifier should not be replaced by a second provider-specific classifier.

Instead:

```text
StudyActivityPolicy
→ execution requirement / capability
→ provider resolution
```

### 27.5 Evidence is already the canonical fact boundary

`TaskEvidence` stores factual observations about real Task execution.

Important current properties:

- Task-bound
- Plan-bound
- provider/source aware
- idempotent through `external_key`
- confidence aware
- metadata stored separately
- downstream Intelligence receives only normalized allowlisted facts

Existing sources already reserve:

- native
- github
- file
- image
- calendar
- external

GitHub integration is the strongest precedent:

```text
GitHub
→ authoritative provider observation
→ TaskEvidence
→ TaskEvidenceAdapter
→ normalized EvidenceObservation
→ Development Intelligence
```

The Execution Ecosystem should reuse this path instead of creating a second progress/evidence system.

### 27.6 Existing ExecutionAdapter is an evidence seam, not yet a provider catalog

The current `ExecutionAdapter` contract exposes:

- key
- Task support
- possible EvidenceSource values

Its documented responsibility is to turn external-environment state changes into Evidence.

No production implementation was found during this investigation.

Do not delete or repurpose this contract in V55.6.

Provider selection / launch orchestration should be introduced beside it, leaving this existing compatibility seam intact.

### 27.7 PlanActivityLog is not Execution Activity

`PlanActivityLog` is currently a collaborative-plan audit/timeline log.

It only records when a Plan is collaborative and is not a generic execution-result store.

Therefore the future provider-neutral Activity model must not reuse `PlanActivityLog`.

---

## 28. Critical Domain Decision: ExecutionActivity != TaskEvidence

The original ecosystem concept requires an external provider to be able to report:

```text
who
did what
for how long
with what result
```

without understanding Canovia Plan / Task IDs.

Current `TaskEvidence` cannot represent that intake state directly because both `plan_id` and `task_id` are required.

Therefore the recommended boundary is:

```text
External / Native Provider Result
        ↓
ExecutionActivity
(provider-neutral factual intake;
Plan / Task may still be unknown)
        ↓
Canovia linking / matching
        ↓
TaskEvidence
(Task-bound canonical observation)
        ↓
Existing Intelligence adapters
        ↓
State / Readiness / Progress candidate / Replan
```

This keeps two responsibilities separate.

### ExecutionActivity

Represents:

> What did an execution provider report?

It may exist before Canovia knows which Task should receive it.

### TaskEvidence

Represents:

> What factual observation is now attached to this Canovia Task?

It remains the canonical downstream boundary for Intelligence.

### Important consequence

Do **not** make external providers create `TaskEvidence` directly.

Provider payloads must not cross directly into Intelligence.

The existing `TaskEvidenceAdapter` allowlist boundary remains authoritative.

---

## 29. Capability Model

### 29.1 Capability keys should be semantic, not UI mode keys

Do not use:

```text
study
development
career
general
```

as provider capabilities.

Those are Execution / Workspace domain modes.

Capability keys describe what kind of work can actually be performed.

Initial candidate keys:

```text
study.practice
study.recall
study.resource
coding.repository
general.task
```

Future examples:

```text
study.practice.exam
study.practice.database
typing.practice
language.vocabulary
fitness.running
```

### 29.2 Do not over-granularize V55.6

V55.6 should not create topic-level capabilities such as:

```text
study.practice.database.join
```

until a real provider-selection use case requires them.

Hierarchical string keys leave room for later refinement without making the initial resolver complex.

### 29.3 Initial capability mapping

Recommended deterministic mapping:

```text
StudyActivityPolicy::QUESTION_PRACTICE
→ study.practice

StudyActivityPolicy::RECALL
→ study.recall

StudyActivityPolicy::RESOURCE_STUDY
→ study.resource

Development profile / repository execution
→ coding.repository

fallback
→ general.task
```

No AI call is required.

---

## 30. Provider Catalog: Registry First, Database Later

### Decision

Do not create a marketplace-style `execution_providers` database table in the first foundation release.

V55.6 has:

- no Developer Portal
- no external self-service registration
- no marketplace
- no review/publish flow
- no real third-party provider onboarding

A DB-backed provider catalog now would create lifecycle/admin requirements before they are needed.

### Recommended first abstraction

Introduce a provider catalog/registry interface with code-defined provider definitions.

Conceptually:

```php
ExecutionProviderDefinition

key
name
kind            // native | external
capabilities[]
enabled
launch metadata // reserved / optional in V55.6
```

Use stable string keys.

Possible initial definitions:

```text
canovia.study.practice
canovia.study.recall
canovia.study.resource
github
canovia.general
```

The exact final keys should be fixed during implementation tests, but the important rule is:

> Provider key identifies a concrete execution implementation/path, not merely a company category.

### Why a registry is preferred now

This matches the existing `WorkspaceModeRegistry` pattern and lets V55.6 validate the orchestration contract without building marketplace administration.

Future architecture can replace the implementation behind the same catalog interface:

```text
Static native registry
        +
Published external provider records
        ↓
Composite ExecutionProviderCatalog
```

Consumers continue resolving by stable provider key.

---

## 31. Plan Execution Preference

A small persistent preference is justified because the central UX rule is:

> choose during setup; do not ask again during normal execution.

Recommended table:

```text
plan_execution_preferences

id
plan_id
capability
provider_key
user_selected
created_at
updated_at
```

Constraint:

```text
unique(plan_id, capability)
```

### Why Plan-scoped first

The current product and specialized Intelligence loops are Plan-scoped.

A Plan-level preference gives enough persistence for MVP without prematurely introducing:

- global user preference inheritance
- Task-specific overrides
- organization policy
- provider ranking profiles

Future precedence can be added without changing the base record:

```text
Task override
→ Plan preference
→ User default
→ Resolver default
```

Only Plan preference is needed now.

### Provider key rather than provider FK

Use a stable `provider_key` initially.

This keeps preferences compatible with the code-backed registry now and a DB-backed provider catalog later.

---

## 32. ExecutionActivity Draft Model

A provider-neutral Activity intake record is recommended as part of the foundation, but without a public external API yet.

Candidate model:

```text
execution_activities

id
user_id nullable
actor_token nullable

provider_key
capability
external_key nullable

type
title
status

started_at nullable
completed_at nullable
duration_seconds nullable

metrics json nullable
metadata json nullable

plan_id nullable
task_id nullable
task_evidence_id nullable
linked_at nullable

created_at
updated_at
```

### Rules

1. `plan_id` and `task_id` are nullable at intake.
2. Provider payloads are normalized before storage; arbitrary raw payload must not be exposed to Intelligence.
3. A stable external key should support idempotent ingestion.
4. Linking to a Task is a Canovia responsibility.
5. Projection to TaskEvidence must be explicit and idempotent.
6. Activity receipt alone must not change Task progress/status.
7. `task_evidence_id` provides traceability after projection.

### Activity status and linking are separate concepts

`status` describes the external activity itself, for example:

- started
- completed
- interrupted

Whether Canovia linked the activity is represented by nullable Task/Evidence linkage rather than overloading the activity status.

---

## 33. Resolver Boundary

Introduce a deterministic `ExecutionResolver`.

Input:

```text
Plan
Task
required capability
available provider definitions
Plan preference
provider availability
```

Initial policy:

```text
1. Valid explicit Plan preference exists
   → use it

2. Suitable Canovia Native provider exists
   → use Native

3. Suitable connected external provider exists
   → use it only when policy explicitly allows it

4. No provider-specific path
   → preserve current ExecutionModeService / Timer fallback
```

### Important MVP constraint

Do not introduce provider ranking AI.

Do not let sponsorship or monetization affect this resolver.

Recommendation data and advertising data must remain separate inputs forever.

---

## 34. Relationship to Existing ExecutionModeService

Do not replace `ExecutionModeService` in V55.6.

Current responsibility:

```text
Plan / Task
→ study | development | career | general
→ specialized operation family
```

New responsibility:

```text
Task execution requirement
→ capability
→ provider
```

Target long-term composition:

```text
ExecutionModeService
        ↓
domain execution family

Capability Resolver
        ↓
what operation is required

ExecutionResolver
        ↓
which provider should perform it

Launch Resolver
        ↓
where Start should go
```

V55.6 only needs the first provider/capability resolution foundation.

Launch unification is a later phase.

---

## 35. UI Placement Decision

### Do not globally insert a blocking screen immediately after Plan creation

Current specialized onboarding already has meaningful first steps:

Study:

```text
Create Plan
→ Capture Study Scope
→ Record first Study Evidence
```

Development:

```text
Create Plan
→ Connect GitHub Evidence
```

Career:

```text
Create Plan
→ Capture real Career signal
```

A mandatory global Execution Setup redirect would disrupt these domain-specific flows and often occur before Canovia has enough Task context to know which capabilities are needed.

### Recommended UX

Execution Setup is a planning/preparation surface that appears only when there is a real decision to make.

#### Study

After enough Scope / Task context exists:

```text
Study Workspace
→ determine required study capabilities
→ if only one viable provider:
     resolve silently
  else:
     show Execution Setup once
→ normal Current Action
```

The setup can later live as a compact Workspace preparation card.

It should not appear merely because the architecture supports providers.

#### Development

Do not add a duplicate setup UI in the first iteration.

The existing GitHub workflow already behaves like an external execution/evidence integration.

V55.6 should map this conceptually into capability/provider architecture without adding another user decision.

#### Normal execution / `/navigate`

Never show a provider chooser on every Start.

Keep the current single CTA.

Future behavior:

```text
[開始]
→ resolve stored provider
→ launch directly
```

A separate "実行方法を変更" entry can exist in Plan/Workspace configuration when external alternatives become real.

---

## 36. MVP Scope Recommended for V55.6

V55.6 should be a **foundation release**, not the first marketplace release.

### Implement

1. Canonical Execution Capability representation
2. Provider definition DTO/value object
3. Provider catalog/registry interface and static implementation
4. PlanExecutionPreference model + migration
5. Deterministic ExecutionResolver
6. ExecutionActivity model + migration for unbound provider results
7. Activity → TaskEvidence projection boundary/interface
8. Plan model relationships
9. Unit/feature tests for the new contracts
10. Documentation synchronization after tests pass

### Do not wire into primary UX yet

V55.6 should not change:

- current Start CTA behavior
- `/navigate` provider UI
- Study Workspace visible flow
- Development Workspace visible flow
- Plan creation redirect
- Intelligence scoring
- Task progress mutation
- GitHub workflow behavior

This keeps the foundation low-risk and lets the next release validate UI with a mock/test provider without destabilizing current users.

---

## 37. Interfaces to Preserve for Future Expansion

Recommended conceptual interfaces:

### ExecutionProviderCatalog

```text
all()
find(providerKey)
forCapability(capability)
```

The implementation can be static now and composite/DB-backed later.

### ExecutionCapabilityResolver

```text
forTask(Plan, Task)
→ required capability(s)
```

The Study implementation delegates to the existing StudyActivityPolicy.

### ExecutionResolver

```text
resolve(Plan, Task, Capability)
→ selected provider resolution
```

Returns a decision object, not a redirect response.

### ExecutionLaunchResolver

```text
provider + capability + task context
→ internal route / web URL / universal link / deep link
```

V55.7でStudy Start向けの最初の実装を追加した。Nativeは既存routeを維持し、Validation External Providerだけ専用handoff surfaceへ解決する。Universal Link / Deep Linkは引き続き将来範囲。

### ExecutionActivityProjector

```text
linked ExecutionActivity
→ TaskEvidence
```

Projection owns:

- evidence source/type mapping
- confidence
- normalized metadata
- idempotent external key

### Provider Connection / Availability — future

A future interface should answer:

```text
Can this actor currently use this provider?
```

It can later hide:

- OAuth connections
- GitHub installations
- app installation state
- entitlement
- provider outage state

Do not embed those conditions directly into `ExecutionResolver`.

---

## 38. Existing Architecture to Reuse, Not Duplicate

Reuse:

- `StudyActivityPolicyService` for initial Study execution requirements
- `ExecutionActionPolicyService` for one-primary-action UX
- `ExecutionModeService` for execution family routing
- `TaskEvidenceService` for canonical Task-bound Evidence
- `TaskEvidenceAdapter` for allowlisted Intelligence facts
- existing Study / Development Intelligence
- existing GitHub Evidence flow
- Workspace-specific onboarding and preparation surfaces

Do not introduce parallel versions of:

- Task progress model
- Evidence model
- Study readiness
- Development readiness
- generic recommendation engine
- Plan category classifier
- Workspace Mode
- Execution Mode

---

## 39. Compatibility / Impact Assessment

### Database

Likely V55.6 migrations:

- `plan_execution_preferences`
- `execution_activities`

No existing column needs to change.

### Current models

Add relationships only.

No existing Plan / Task record requires backfill.

### Runtime

No provider/network request on ordinary GET pages.

No external API call is introduced.

No AI call is introduced.

### Intelligence

No scoring changes.

New generic Activity records do not affect Readiness until they have been:

1. linked to a Task
2. projected to a known TaskEvidence type
3. normalized by the existing Intelligence boundary

### Existing users

No setup modal or migration prompt.

No existing Plan is blocked because it has no execution preference.

Resolver must always preserve a valid Native/current fallback.

---

## 40. Recommended Implementation Sequence

### V55.6 — Execution Ecosystem Foundation

```text
Capability
→ Provider Catalog
→ Preference
→ Resolver
→ Activity intake model
→ Activity/Evidence projection contract
```

No visible UX change.

### V55.7 — Execution Setup Validation — implemented

Studyを最初のUX validation domainとして実装済み。

Validation setup:

- existing Canovia Native provider
- feature-flagged mock/non-production external provider

Validated:

- setup appears only when choice exists
- existing Study onboarding takes precedence
- selection persists per Plan/capability
- Start remains one-tap through the canonical Study action route
- Home / Overview share the same launch decision indirectly
- user can change method later
- projected Study Tasks inherit the Plan+Capability preference
- Native fallback always works
- no external API/OAuth/Activity callback is introduced

Canonical implementation spec: `docs/V55.7_EXECUTION_SETUP_VALIDATION.md`

### V55.8 — External Activity Integration Validation — implemented

Study Practiceで最初のreturn loopを実装済み。

```text
Validation Provider launch
→ normalized simulated provider result
→ idempotent ExecutionActivity
→ explicit Task link
→ study_practice_assessed TaskEvidence
→ TaskEvidenceAdapter
→ existing Study Intelligence
```

Validated:

- provider result redelivery is idempotent
- raw Activity metadata does not cross into Intelligence
- only completed Study Practice with a valid score becomes domain Evidence
- generic/non-completed Activity remains execution_activity_observed
- External score affects Study Intelligence through existing rules
- Task progress/status are not automatically mutated

Canonical implementation spec: `docs/V55.8_EXTERNAL_ACTIVITY_INTEGRATION_VALIDATION.md`

### V55.9 — Provider Connection / Authenticated Activity Intake — implemented

The first provider-neutral authenticated return path is implemented.

```text
user-owned ProviderConnection
→ one-time connection secret
→ encrypted secret at rest
→ short-lived opaque execution_context
→ HMAC-signed stateless Activity request
→ server-side user/provider/capability/Task resolution
→ idempotent ExecutionActivity
→ existing Evidence projection
```

Validated:

- native providers cannot create external ProviderConnections
- revoked connections cannot authenticate
- stale timestamp / invalid signature are rejected before persistence
- execution_context cannot be reused across connections
- payload-supplied routing IDs are ignored
- arbitrary provider metrics do not cross the normalization boundary
- Study Practice result reaches existing Study Intelligence
- Task progress/status remain unchanged

Canonical implementation spec: `docs/V55.9_PROVIDER_CONNECTION_AUTHENTICATED_ACTIVITY_INTAKE.md`

### Later — Real Provider Connection Validation

Use one real external provider to validate:

```text
provider-specific connection handshake
→ generic ProviderConnection
→ launch with execution_context
→ signed Activity return
```

Do not generalize into Marketplace/Developer Portal until a real provider contract proves the abstraction.

### Later — Launch Integration

Add external URL / Universal Link / Deep Link launch.

### Later — Developer Portal / Directory / Marketplace

Only after real integrations demonstrate repeatable provider contracts.

---

## 41. Decision Summary

The Execution Ecosystem should be implemented as an **orchestration layer inside the existing Canovia architecture**, not as a parallel subsystem.

The key structural decision is:

```text
Workspace / Intelligence
        ↓
Current Action / Task
        ↓
Execution Mode
        ↓
Capability
        ↓
Provider Resolution
        ↓
Execution
        ↓
ExecutionActivity
        ↓
TaskEvidence
        ↓
Existing Intelligence / Replan
```

For V55.6:

- keep current UX unchanged
- add the minimum provider/capability/activity domain foundation
- keep external integration optional
- keep Native fallback authoritative
- do not build Marketplace/OAuth/API/ads
- do not let Activity directly mutate progress
- do not bypass TaskEvidence / Intelligence normalization

This gives Canovia a stable path from today's Native/GitHub execution architecture to the future external Execution Ecosystem without requiring a large rewrite.

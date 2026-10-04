# Canovia Execution Ecosystem — Temporary Design Draft

> Status: temporary implementation brief  
> Created: 2026-10-04  
> Base: main after V55.5  
> Purpose: preserve the product concept and repository-fit investigation before implementation.  
> This document is not yet the canonical product specification. Promote validated decisions to `docs/CANOVIA_PRODUCT_SPEC.md` and/or a versioned V55.6 specification when implementation scope is fixed.

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

## 27. Investigation Notes

Repository-fit findings are intentionally left blank in the first commit.

The next update to this temporary document should record:

- current execution/evidence architecture
- model placement decision
- capability/provider contract
- MVP database boundary
- Study / Development integration points
- compatibility and migration impact
- explicit non-goals for V55.6

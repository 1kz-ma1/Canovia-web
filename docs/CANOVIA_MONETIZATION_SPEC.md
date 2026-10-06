# Canovia Monetization Specification

> Status: Product decision / implementation pending  
> Updated: 2026-10-07  
> Scope: Canovia-wide plan architecture, Study / Development boundaries, Early Access presentation

## 1. Purpose

Canoviaの課金は、基本機能を細かく封鎖するのではなく、Canoviaがユーザーの負担をどこまで引き受けるかで段階化する。

Core rule:

> 努力・実行そのものはFreeで成立させる。  
> 課金価値は、判断・理解・自動化によって摩擦を減らすことに置く。

Workspaceごとの別契約を基本にせず、Canovia全体で共通のFree / Premium / Proを持つ。
Developmentのみ、公開後のProduct Intelligenceを扱う専門追加プランとしてDev Proを将来候補にする。

## 2. Canonical plan model

```text
Free      = Execute
Premium   = Guide
Pro       = Understand
Dev Pro   = Observe & Improve the Product
```

### Free — Execute

Canoviaを使って、ユーザー自身が前へ進める。

- Goal / Plan / Task / Evidence等のCore Loop
- 基本実行・記録・振り返り
- AIを使わなくても成立する整理・連携・Prompt生成
- 課金しなければ努力そのものができない設計にしない

### Premium — Guide

Canoviaが次の判断を支援する。

- 軽量なAI推論
- 推奨
- 進捗・弱点・状況推定
- 方針 / Rules / lightweight specification generation
- AIへの指示品質向上

### Pro — Understand

Canoviaが、対象そのものを理解してContextを構築する。

- Repository / 教材 / 長期履歴等の内容解析
- 関連情報の検索・抽出
- Cross-context understanding
- 変更影響 / 関係性分析
- 高精度Prompt / Context generation

Premiumとの差は単なるAI回数ではなく、Canoviaが対象をどこまで理解できるかに置く。

### Dev Pro — Observe & Improve

Development専用の将来追加プラン。

- 公開後のTelemetry / Product Intelligence
- Feature Adoption / Funnel / Release Impact
- 改善候補・原因仮説・Decision Package
- 将来的なCoding Agent実装 / 検証 / bounded rollout

詳細は `docs/future/DEVELOPER_PRO_AI_DEVELOPMENT_ORCHESTRATION.md` を参照。

## 3. Study Workspace

Study専用Premium / Proという別契約は作らない。
Canovia Premium / Proの能力をStudyへ適用する。

### Study Free

役割: 学習を実行・記録する。

- Study Goal / Plan / Task
- 試験日・期限
- 今日の学習
- 学習実績 / 時間
- 演習
- 採点
- 正誤・解説
- 基本集計
- 基本的な問題生成
- 基本進捗

Invariant:

> 問題を解く → 採点 → 記録する、までをFreeで完結させる。

### Study Premium

役割: 何を勉強するべきかCanoviaが判断する。

Candidate capabilities:

- 弱点自動推定
- 得意 / 苦手による出題配分
- 最近の履歴を使った演習最適化
- 重複回避
- 復習タイミング
- 試験日からのペース調整
- 学習量の不足 / 過剰検知
- 今日のおすすめ高度化
- 軽量AI演習生成
- 回答傾向の簡易分析
- Plan修正提案

### Study Pro

役割: 教材と学習者の状態までCanoviaが理解する。

Candidate capabilities:

- PDF / プリント / ノート / 画像 / 試験範囲解析
- 教材から学習範囲を構造化
- 自動Task生成
- 教材内容に基づく演習
- 過去問 / 教材 / 履歴の横断Context
- 長期弱点履歴
- Knowledge State
- 学習速度 / 保持状況推定
- 高精度再計画

Representative experience:

```text
試験範囲を取り込む
→ 範囲を構造化
→ 過去の弱点と照合
→ Task / 優先順位を構築
→ 今日の学習を提示
→ 結果で更新
```

## 4. Development Workspace

### Development Free

役割: 開発を整理・管理する。

- Repository連携
- Repository metadata
- Issue / PR / Commit取得
- Plan / Taskとの関連
- 開発状況表示
- Evidence / 実績
- Release / Version基本管理
- 外部AI向け基本Prompt生成

基本Promptは既知のTask / specification / acceptance criteria / PR等をテンプレートへ構成し、Canovia側の高コストAIを必須にしない。

### Development Premium

役割: 開発判断とAI利用を支援する。

Candidate capabilities:

- PR内容から進捗推定
- Issue / PR / Commitから状況解釈
- 次のTask / 追加機能 / 改善候補
- 開発形態に適したDevelopment Rules
- Project固有Rules
- Coding Agent向け制約
- PromptへのRules自動注入
- lightweight specification generation

代表価値:

> AIを呼ぶことではなく、AIに適切な仕事をさせる。

### Development Pro

役割: RepositoryそのものをCanoviaが理解する。

Candidate capabilities:

- Repository内容解析
- 関連コード / file / test自動検索
- Repository構造理解
- Specification / Decision / Task / Issue / PR / Code横断Context
- 変更影響分析
- Implementation Context自動構築
- 高精度Prompt
- Implementation Review Context

代表価値:

> ユーザーがAIへ渡すContextを自分で探さなくても、Canoviaが必要情報を組み立てる。

## 5. Dev Pro boundary

Proまでは「作るための支援」。
Dev Proから「作ったものを実環境で観測し、改善する支援」。

Dev Proは通常Proの単純な機能上限ではなく、Development向け専門追加契約候補とする。

将来料金候補:

```text
Canovia Pro
+
Dev Pro base subscription
+
optional AI / Agent credits
```

無制限Coding Agent実行を前提にしない。
BYOKも将来候補とする。

## 6. Career boundary

Careerは有力な主要カテゴリ候補だが、Early Access時点では専用UI / 基本導線を統一してからBeta Previewとして出す。

Career固有の別契約は現時点では定義しない。
将来CapabilityをFree / Premium / Proの共通原則へ分類する。

## 7. Pricing / upgrade presentation

公開Pricing UIは、機能名の表だけではなく「体験差」を見せる。

短いPreview例:

### Development

```text
Free
Task → basic prompt

Premium
Task → Rules / reasoning → guided prompt

Pro
Task → repository retrieval → code/spec/test context → contextual prompt

Dev Pro
Release → telemetry → product insight → improvement proposal
```

### Study

```text
Free
Practice → score → record

Premium
Practice history → weakness detection → next practice optimization

Pro
Material / exam scope → parse → long-term weakness context → learning plan
```

Preview動画はComing Soon機能の価値説明に利用できる。

## 8. Early Access policy

Early Accessでは原則:

- Free: 実利用可能
- Premium: Coming Soon / preview
- Pro: Coming Soon / preview
- Dev Pro: Coming Soon / preview
- Career: UI統一後にBeta Preview

購入経路は、実際の価値・原価・運用が成立するまで公開しない。

Coming Soonは単なるロックではなく、将来的に「興味あり」「通知希望」等の需要シグナルを取れる余地を持たせる。

## 9. AI cost principles

- 非AI処理で成立するものはAIを使わない
- SQL / deterministic rules / statisticsを先に使う
- Contextを必要範囲へ絞る
- 軽量推論と高コスト推論を分離する
- Repository / material contextはcache / retrievalを利用する
- Premium / Pro / Dev Proでも「unlimited AI」を前提にしない
- Billing / Entitlement / AI Capacityは別責務のまま維持する

## 10. Compatibility with V41.5 economy

V41.5で実装済みの以下は、runtime entitlement foundationとして維持する。

- `FeatureAccessService`
- Product Grant
- Entitlement resolver
- AI Capacity separation
- Feature側へ課金ロジックを散らさない原則

一方、V41.5の `Premium Core + Purpose Pack` は当時のProduct shapeであり、2026-10-07時点の将来商品構成の正ではない。

この文書のProduct-level target:

```text
Free
Premium
Pro
+ Dev Pro add-on for Development
```

へ移行する。

この仕様追加だけでは、既存ProductKey / grant / entitlement設定を変更しない。
runtime migrationは別実装仕様で行う。

## 11. Undecided

現時点では固定しない:

- Premium / Pro / Dev Proの価格
- 年額
- trial
- AI quota
- Agent credit price
- BYOK対応範囲
- Early Access特典
- 正式課金開始時期

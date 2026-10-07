# Future Design

## Purpose

`docs/future/` は、Canoviaの**中長期構想・未実装の設計候補・将来のプロダクト/アーキテクチャ判断材料**を保存する領域です。

このディレクトリに文書が存在することは、その機能が現在実装済み・公開済み・ロードマップ上で直ちに着手対象であることを意味しません。

## Authority

実装判断では、次の順序を優先します。

```text
latest main implementation
→ docs/CANOVIA_PRODUCT_SPEC.md
→ relevant implemented/versioned specs
→ docs/future/*
```

Future Designは、現在のコードや確定仕様より優先されません。

将来構想と現行実装が矛盾する場合、現在の実装・確定仕様を正として扱い、Future Design側を再評価します。

## Agent Rule

AI実装エージェントは、`docs/future/` の内容を**既存機能・実装要件・未処理バックログとして自動的に解釈してはいけません**。

Future Designを実装へ昇格させるには、少なくとも以下が必要です。

1. ユーザーまたはProduct ownerによる明示的な着手判断
2. latest `main` の再調査
3. 現行Product Spec / relevant versioned specとの整合確認
4. 実装範囲・Non-Goals・Migration/Compatibility影響の確定
5. 専用branchでの実装・検証・PR

「Future Designに書かれている」という理由だけで新しいモデル、テーブル、API、課金、外部連携、AI自動化を追加してはいけません。

## Why Future Design Still Matters

Future Designは実装命令ではありませんが、現在の設計を不用意に閉じないための判断材料として利用します。

例:

- 将来必要になる責務境界を壊さない
- 一時的な実装都合でProvider/Context/Approval境界を固定しすぎない
- 現行Featureに将来の課金・広告・自動化責務を散らさない
- 将来のspin-outや外部Execution Provider化を妨げる強結合を避ける

つまり:

```text
Future Design
!= Current Requirement

Future Design
= Architecture preservation context
```

です。

## Status

Future文書は、必要に応じて次のStatusを持ちます。

### Concept

方向性や仮説を保存した段階。実装決定ではありません。

### Candidate

Product/Architecture上の採用候補。追加検証や意思決定が必要です。

### Validation Planned

限定的なprototype / experiment / user validationを計画している段階です。

### Promoted

実装フェーズへ昇格済みです。

Promotedになった場合、Future文書だけを実装Source of Truthにせず、正式なversioned specificationや`CANOVIA_PRODUCT_SPEC.md`へ必要事項を移行・同期します。

## Promotion Rule

Future構想が実装へ進む場合:

```text
Future Design
→ repository investigation
→ product decision
→ versioned implementation spec
→ implementation
→ tests
→ Product Spec sync
```

を基本とします。

Future文書は履歴・長期意図として残して構いませんが、「現在どう動くか」は実装済み仕様へ移します。

## Current Priority Boundary

Future Designの追加だけを理由に、現在の公開優先順位を変更しません。

現時点では:

```text
Canovia Core Loop completion
→ iOS Soft Launch / public validation
→ post-launch expansion
```

を優先します。

Developer Pro、完全自動開発、Marketplace、Developer Portal等は、明示的に昇格されるまで原則として公開後拡張です。

## Start Here

Read this first:

- [Canovia Future Architecture Overview](CANOVIA_FUTURE_ARCHITECTURE_OVERVIEW.md)

The Overview explicitly separates:

- CURRENT / ACTIVE
- PARTIAL / FOUNDATION
- FUTURE / CONCEPT

and links current behavior back to Active Specs instead of duplicating it.

## Documents

### Architecture overview

- [Canovia Future Architecture Overview](CANOVIA_FUTURE_ARCHITECTURE_OVERVIEW.md)

### Platform / Ecosystem

- [Canovia Platform / Ecosystem](CANOVIA_PLATFORM_ECOSYSTEM.md)

Covers Future direction for:

- Canovia Family
- Identity
- Shared Context
- Consent / Scope
- Family Entitlement
- Activity / Capability Contracts
- Curated Execution Network
- Differential Onboarding

### Product Intelligence

- [Product Intelligence / Automatic Improvement](PRODUCT_INTELLIGENCE_AUTOMATIC_IMPROVEMENT.md)

Covers Future direction for:

- Evidence Pipeline
- Hypothesis Intelligence
- Decision Memory
- Data Lineage
- Evidence Health
- Pattern hierarchy
- Opportunity Engine
- Automatic Improvement

### Product Creation

- [Product Creation System / Incubation](PRODUCT_CREATION_SYSTEM.md)

Covers Future direction for:

- Incubation
- First-party / Partner decisions
- Product Factory
- Multi-product vision
- small-team leverage

### Developer Pro

- [Developer Pro / AI Development Orchestration](DEVELOPER_PRO_AI_DEVELOPMENT_ORCHESTRATION.md)

Developer Pro remains a dedicated Future Design because its development-orchestration details are substantial.

## Current-vs-Future Rule

Do not use a Future document to redefine current behavior.

If a Future document references a current capability such as:

- Personalization Bootstrap
- Living Profile
- Study
- Development
- GitHub
- Telemetry
- Entitlement
- Release Level
- Execution Ecosystem Foundation

follow the linked Active Spec / latest main implementation for current semantics.

The Future document only describes how that current foundation may evolve.

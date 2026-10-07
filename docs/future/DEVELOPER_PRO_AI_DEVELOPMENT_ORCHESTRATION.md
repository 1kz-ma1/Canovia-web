# Developer Pro / AI Development Orchestration

> Status: **Concept**  
> Scope: long-term product / architecture context  
> Implementation status: **not implemented by this document**  
> Priority: post-Core-Loop / post-iOS-Soft-Launch expansion unless explicitly promoted  
> Current product authority: `docs/CANOVIA_PRODUCT_SPEC.md` and latest `main`

---

## Related Future Architecture

Developer Pro belongs inside the broader Future Architecture.

Read together with:

- `docs/future/CANOVIA_FUTURE_ARCHITECTURE_OVERVIEW.md`
- `docs/future/CANOVIA_PLATFORM_ECOSYSTEM.md`
- `docs/future/PRODUCT_INTELLIGENCE_AUTOMATIC_IMPROVEMENT.md`
- `docs/future/PRODUCT_CREATION_SYSTEM.md`

Responsibility split:

```text
Canovia Platform
= Identity / Context / Consent / Activity / Capability contracts

Product Intelligence
= Evidence / Hypothesis / Decision / Pattern / Opportunity

Product Creation System
= Incubation / product creation / first-party-partner decisions

Developer Pro
= external productization of proven development + intelligence orchestration
```

This document does not make Product Intelligence, Opportunity Engine, Product Factory, or cross-app Platform contracts current requirements.

---

## 1. Purpose

Developer Proは、CanoviaのDevelopmentカテゴリを将来的に発展させる構想です。

目標は単なるAI Coding機能ではありません。

Developer Proが担う価値は:

```text
Product Context
+
Specification
+
Repository Context
+
Development Plan
+
Implementation Agent
+
Testing
+
Review
+
Human Approval
```

を継続的につなぐことです。

目指す体験:

```text
Idea
→ clarify
→ specify
→ inspect architecture
→ plan implementation
→ implement
→ validate
→ review
→ human approve
→ learn
```

Canoviaは「コードを書くAI」を置き換えるのではなく、**何を・なぜ・どの制約で作るかを継続管理するContext / Orchestration Layer**を担当します。

---

## 1.1 2026-10-07 Product Direction Update

Developer Proの中心を「開発中の自動実装」だけに置かない。

Canovia Developmentの役割を、開発段階と公開後で分離する。

### Development stage

完成前はCanovia自身が高コストCoding Agentとして常時実装する必要はない。

Canoviaが担う:

```text
Product / Goal context
+ specification / decision history
+ repository signals
+ development state
+ Development Rules
+ context packaging
+ external AI prompt / handoff
```

外部Coding Agentが担う:

```text
bounded repository inspection
+ implementation
+ tests
+ PR-level execution
```

Canovia共通planとの関係:

- Free: Repository連携 / 基本管理 / non-AI中心のPrompt生成
- Premium: PR進捗推定、改善候補、Development Rules / lightweight specification等の軽量推論
- Pro: Repository内容を読み、Code / Spec / Test / Decisionを横断したContextを構築
- Dev Pro: 原則として公開後Product Intelligenceを主価値にする

### Observability-ready development

公開後に初めて計測を考えるのではなく、開発段階から:

- Feature purpose
- success condition
- Metric
- Event Definition
- Release
- Repository / Task relation

を残せる構造を想定する。

これは「今すぐ利用データをCanoviaへ送る」こととは分離する。

```text
Prepare for Product Intelligence opt-in
!=
Runtime end-user telemetry consent / connection
```

設計準備と実データ接続の同意を分ける。

### Post-release stage — Dev Pro core

公開後は:

```text
Observe
→ Interpret
→ Hypothesis
→ Improve
→ Verify
```

を継続するProduct Intelligence layerを主価値とする。

Candidate observations:

- Feature Adoption
- Funnel
- User Journey
- retention / return
- Release Impact
- error / latency
- intended feature usage vs actual usage
- feedback

Feedbackを待って手作業で改善するだけでなく、実環境Evidenceから改善候補を発見する。

### Data granularity

Raw eventをそのまま大量にAIへ渡さない。

```text
Raw Observation
→ deterministic / statistical aggregation
→ Product Intelligence
→ semantic summary
→ AI Context
```

UIで人間へ見せる粒度と、AI判断へ渡す粒度を分ける。

高コストAI / Coding Agentは、十分に絞り込まれた改善候補だけで起動する。


## 2. Relationship to Current Canovia

Developer Proは現在のDevelopment Workspaceを置き換えません。

現行Canoviaにはすでに:

- Development Workspace
- GitHub App / Repository integration
- GitHub Evidence sync
- Development State / Release Readiness
- Current Action
- Task execution orchestration
- Execution Ecosystem foundation

があります。

Relevant current specs:

- `docs/V54.4_DEVELOPMENT_WORKSPACE.md`
- `docs/V53.7_DEVELOPER_EVIDENCE_SYNC.md`
- `docs/V53.8_DEVELOPER_READINESS.md`
- `docs/V55.6_EXECUTION_ECOSYSTEM_FOUNDATION.md`
- `docs/V55.7_EXECUTION_SETUP_VALIDATION.md`
- `docs/CANOVIA_DEVELOPMENT_WORKFLOW.md`

Developer Proはこの上に:

```text
Development Workspace
→ Product / Project Context
→ Specification
→ Implementation orchestration
→ Coding Agent
→ Review / Approval
```

を追加する将来レイヤーとして考えます。

---

## 3. Core Thesis

AI Coding Toolが強くなるほど、差別化価値は単純なコード生成から移ります。

重要になるのは:

```text
何を作るべきか
何を守るべきか
何が既に決まっているか
どこまで変更してよいか
何を完了条件とするか
```

を正しく構造化し、必要な時にCoding Agentへ渡す能力です。

Developer Proの本質:

```text
Continuous Product Context
+
Living Specification
+
Repository Context
+
Development Planning
+
Coding Agent Orchestration
+
Human Decision
```

です。

---

## 4. Responsibility Boundary

### Canovia / Developer Pro

担当:

- Product intent
- Goal / Project context
- Requirement clarification
- Specification
- Architecture constraints
- Implementation planning
- Context packaging
- Approval orchestration
- Result / Evidence ingestion
- Spec consistency
- Progress / decision continuity

### Coding Agent

担当:

- Repository inspection for assigned scope
- code implementation
- tests
- lint/build fixes
- bounded refactoring
- implementation-level technical execution

原則:

> Coding Agentは「何を作るべきかをゼロから決める存在」ではなく、明確化された仕事を高品質に実装するSoftware Execution Agentとして扱う。

---

## 5. Specification-First Workflow

ユーザーが機能要求を出しても、即座にCoding Agentへ送ることを標準にしません。

Target flow:

```text
User
「チーム機能を追加したい」
↓
Canovia
目的 / 対象 / 制約 / 未決定事項を整理
↓
必要な意思決定だけ確認
↓
Specification
↓
Architecture impact
↓
Implementation Plan
↓
Human Approval
↓
Coding Agent
```

Canovia自身の現在の開発方法:

```text
相談
→ 仕様整理
→ latest repository調査
→ 既存構造との整合確認
→ 実装範囲決定
→ branch実装
→ test
→ PR
→ human merge
```

をプロダクト化する方向です。

---

## 6. Context Foundation

自動実装能力より先に、AIが安定して開発できるContext Foundationを整えます。

必要なContext category:

- Product intent
- Product principles
- Feature specifications
- Architecture decisions
- Repository structure
- Testing policy
- Agent instructions
- Security constraints
- Approval boundaries

概念構造例:

```text
docs/
├─ product/
├─ specs/
├─ architecture/
├─ future/
└─ ...
```

ただし既存Repositoryを強制的にこの構造へ移行しません。

Canovia自身は現在、versioned specsを`docs/`直下に持っているため、Developer Proは既存構造を尊重してContextを解釈する必要があります。

---

## 7. AGENTS.md as Context Entry

Developer Proでは`AGENTS.md`を重要なContext Layerとして扱います。

役割:

```text
Repository work rules
+
important boundaries
+
where to look next
```

想定情報:

- Product概要への入口
- Architecture boundary
- 変更禁止 / 注意領域
- Coding convention
- Testing requirements
- Documentation requirements
- Security constraints
- Approval-required changes

Example rules:

```text
変更前に関連仕様を読む

既存実装を確認してから新しい抽象化を追加する

Authentication / Billing / Permissions / destructive DB changeは
明示承認なしに大規模変更しない

Bug fixではRegression Testを追加する

Behavior変更時は対応する仕様書も更新する
```

重要:

> AGENTS.mdは巨大なProduct Specificationの代替ではない。

Agentが作業する際の**ルール・入口**として扱います。

Repositoryに存在しない場合、将来的に生成支援できます。

存在する場合は、不足項目や矛盾候補を提案できる構造を検討します。

---

## 8. Context Authority

Developer ProはContext sourceごとのauthorityを区別する必要があります。

概念:

```text
runtime / latest main
→ canonical current product spec
→ implemented feature specs
→ repository agent rules
→ future design
→ conversation / proposal
```

Future Designが存在しても、自動的にcurrent requirementへ昇格しません。

逆に、過去のConversationだけを根拠に現行コードを上書きしません。

---

## 9. Implementation Package

Coding Agentへ会話履歴やRepository全体を無制限に渡しません。

必要な情報だけを集約した`Implementation Package`を生成します。

Concept:

```text
Implementation Package

Feature
  何を作るか

Why
  なぜ必要か

Relevant Specs
  current authorityとなる仕様

Architecture Constraints
  守る責務境界

Affected Areas
  影響候補

Acceptance Criteria
  完了条件

Testing Requirements
  必須検証

Documentation Impact
  更新対象

Approval Constraints
  自動変更不可領域

Known Non-Goals
  今回やらないこと
```

効果:

- token消費抑制
- context confusion削減
- stale spec混入抑制
- 不要なRepository全探索削減
- Agentごとの責務明確化

---

## 10. Agent Context Strategy

「全情報を毎回全部渡す」ことを前提にしません。

Target:

```text
Product Context Index
↓
task-specific retrieval
↓
Implementation Package
↓
only relevant repository context
↓
Coding Agent
```

必要に応じてAgentが追加Contextを要求できる構造を想定します。

---

## 11. Ideal Automation Pipeline

Long-term ideal:

```text
User / Product Signal
↓
Canovia Context Engine
↓
Specification Agent
↓
Implementation Plan
↓
Human Approval
↓
Coding Agent
↓
Implementation
↓
Test / Lint / Build
↓
Self-fix
↓
Review Agent
↓
Specification Consistency Check
↓
Pull Request
↓
Human Approval
↓
Merge / Deploy
```

最初から完全自動Deployを目標にしません。

---

## 12. Human in the Loop

Developer Proは高い自動化を可能にしても、重要判断にはHuman Approvalを残します。

High-risk examples:

- Authentication
- Billing
- Permissions
- Privacy
- Security boundary
- destructive DB migration
- data deletion / retention
- public API breaking change
- production deployment
- major architecture change
- external legal / compliance impact

原則:

```text
AI can prepare
AI can implement
AI can validate
Human owns consequential approval
```

低リスク変更だけ将来的に自動化レベルを上げられる構造を想定します。

---

## 13. Automation Levels

Long-term candidate:

### Level 0 — Observe

観測・Context整理のみ。

### Level 1 — Propose

改善案・Implementation Planを提案。

### Level 2 — Branch / PR

承認済み仕様からbranch実装・test・PR生成まで。

### Level 3 — Preview / Staging

PR後のPreview / Staging Deployまで。

### Level 4 — Human-approved Production

Productionは必ず人間承認。

### Level 5 — Bounded Auto Production

明示許可された低リスク変更だけ自動Production。

Automation LevelはRepository / Project / change riskごとに異なり得ます。

これは現時点では実装対象ではありません。

---

## 14. Risk Classification

Automation Levelとは別に、変更リスクを分類できる構造を想定します。

Example:

```text
LOW
copy / isolated UI / tests / docs

MEDIUM
business logic / non-destructive schema addition / integration behavior

HIGH
auth / billing / permission / privacy / destructive migration /
production infrastructure / public API compatibility
```

Risk classificationはAIの「自信」だけで決めません。

影響領域・権限・データ不可逆性・rollback可能性を使います。

---

## 15. Context Health

将来的にRepositoryの「AI開発準備度」を評価します。

Working name:

```text
Context Health
```

Example:

```text
✓ Product purpose documented
✓ Architecture documented
✓ AGENTS.md configured
✓ Test policy available
✓ Current feature specification available

△ Billing partially documented

✕ Rollback policy missing
```

実装前警告例:

> この変更はBillingに影響しますが、Billing仕様が十分定義されていません。

目的:

> AIモデル能力不足とContext不足を区別する。

Context Healthはコード品質スコアや開発者ランキングではありません。

---

## 16. Context Health Dimensions

Candidate dimensions:

- Product Context
- Specification Coverage
- Architecture Documentation
- Agent Instructions
- Testing Policy
- Security Boundaries
- Migration / Rollback Guidance
- Deployment Policy
- Ownership / Approval Rules
- Observability

単純な総合点だけでなく、不足領域を具体的に示すことを優先します。

---

## 17. Living Specification

仕様は一度作成して放置しません。

Target loop:

```text
Code Diff
↓
Behavior / Architecture Impact Detection
↓
Specification Impact Check
↓
Spec Update
↓
Tests
↓
PR
```

Definition of Done:

```text
Code
+
Tests
+
Specification
```

が一致している状態。

「コードが動いた」だけを完了にしません。

---

## 18. Specification Consistency Review

Review Agentはコードスタイルだけでなく:

- Acceptance Criteriaを満たしたか
- Non-Goalsを越えていないか
- current Product Specと矛盾しないか
- relevant versioned spec更新が必要か
- Future Designを誤ってcurrent requirementとして実装していないか

を確認できる構造を目指します。

---

## 19. Product Improvement Automation

Developer Proは明示的な実装依頼だけでなく、将来的にProduct Dataから改善候補を発見できます。

Signals:

```text
Telemetry
Feedback
Errors
Feature Support / Votes
Retention
Execution rate
↓
Problem Detection
↓
Improvement Hypothesis
↓
Specification Draft
↓
Implementation Proposal
```

ただし:

> Problem Detectionから自動実装へ直結させない。

---

## 20. Decision Package

改善候補はAdmin / DeveloperへDecision Packageとして提示します。

Concept:

```text
Observation
何が起きているか

Evidence
根拠

Hypothesis
原因仮説

Proposal
変更案

Expected Impact
期待効果

Risk
リスク

Affected Areas
影響範囲

Implementation Cost
AI実装コスト概算

Decision Needed
人間が決めること
```

承認後にSpecification / Implementation Packageへ進みます。

---

## 21. Product Constitution

自動改善で単一KPIだけを最大化させません。

将来的なProduct Constitutionは:

- Product Principles
- Business Goals
- User Value
- UX Constraints
- Security
- Privacy
- Trust
- Long-term architecture

をContextへ含めます。

Examples:

```text
売上最大化だけを目的にしない
DAUだけを最大化しない
ユーザーの判断負荷を不必要に増やさない
短期KPIのために信頼を損なわない
安全境界を成長指標より下位に置かない
```

Product ConstitutionはAIに「自由な価値判断」を委ねるものではなく、Product ownerが定義した制約を機械可読なContextへする構想です。

---

## 22. GitHub Relationship

Developer ProはGitHubとの正式なAccount / Repository connectionを前提に検討します。

Target conceptual relation:

```text
GitHub Account
↓
Repository selection
↓
Canovia Development Plan / Project
```

Candidate inputs:

- Repository metadata
- Branch
- Commit
- Pull Request
- Review
- Issue
- CI result
- Release
- Deployment

Uses:

- automatic Evidence
- Repository Context
- Implementation orchestration
- PR generation
- Review
- release/deploy assistance

### Current architecture note

Canoviaには既にGitHub App / Repository Artifact / Evidence integrationが存在します。

Developer Proは新しい並行GitHub接続方式を無条件に作るのではなく、current integrationを再調査し、その上にAccount/Repository UXを発展させるべきです。

---

## 23. Development Workspace Positioning

Developer Proは最初から独立アプリとして始めません。

Initial product position:

```text
Canovia
└─ Development Workspace
   └─ Developer Pro
```

まずCanovia利用者で価値を検証します。

Validation metrics candidate:

- GitHub connection rate
- Developer Pro activation rate
- repeat usage
- AI implementation requests
- generated PR count
- PR adoption rate
- human modification after AI generation
- AI cost per implementation
- AI cost per accepted PR
- development time saved
- Canovia launches driven by Developer workflow

---

## 24. Spin-out Option

Developer Proが十分な独立価値を持つ場合のみ、Canovia公式専門アプリへのspin-outを検討します。

```text
Idea
↓
Canovia Internal Prototype
↓
Developer Pro
↓
Real User Validation
↓
Strong Product Signal
↓
Canovia Official Developer App
```

Candidate triggers:

- Developer機能だけで高い継続利用
- 専用UIが必要
- Canovia Core UIを圧迫
- 独立課金への支払意思
- Canovia Coreなしでも独立価値が成立
- Developer目的で新規流入

---

## 25. Responsibility After Spin-out

Concept:

```text
Canovia Core
What / Why / When

Developer App
How
```

### Canovia Core

- 何を作るか
- なぜ作るか
- いつ作るか
- 現在どこまで進んでいるか
- 他Goal / Planとの優先順位

### Developer App

- Repository inspection
- Implementation Plan
- code implementation
- tests
- review
- PR
- deploy assistance

共通Canovia Account / Context foundationを共有できる余地を残します。

独立後もDeveloper AppはCanovia Execution Providerとして接続できる構造を想定します。

---

## 26. Execution Ecosystem Relationship

Developer ProはExecution Ecosystemと競合しません。

Long-term composition:

```text
Canovia Current Action
→ coding capability
→ Developer Pro / Developer App provider
→ implementation
→ Activity
→ Evidence
→ Development Intelligence
→ next Action
```

Developer Pro自身もExecution Providerになり得ます。

このため、current Execution Capability / Provider / Activity / Evidence境界を不用意にDeveloper Pro専用へ固定しないことが重要です。

---

## 27. AI Cost Architecture

Coding Agentを常時起動しません。

基本:

```text
Cheap Context Processing
↓
Human Decision
↓
Expensive Coding Agent
```

低コスト処理候補:

- Context indexing
- telemetry aggregation
- spec retrieval
- requirement classification
- risk classification
- candidate grouping
- deterministic validation

高コストAgentは、実装や高度なRepository reasoningが必要な場合だけ起動します。

---

## 28. Cost Metrics

Measure:

- AI cost per implementation
- AI cost per accepted PR
- monthly AI cost per Developer Pro user
- retry rate
- self-fix loops
- Review Agent cost
- human correction volume
- abandoned implementation cost

Pricingより先に原価と採用率を観測します。

---

## 29. Monetization Direction

Current product-level direction:

```text
Canovia Free / Premium / Pro
+
Dev Pro add-on for Development
+
optional Implementation / Agent Credits
```

Dev Proは「Development Proという通常Pro tier」ではなく、Canovia Proより先にあるDevelopment専門のProduct Intelligence / automation追加契約候補として扱う。

Value boundary:

```text
Free      = manage / execute
Premium   = guide AI and development decisions
Pro       = understand repository / project context
Dev Pro   = observe released product and improve it
```

無制限AI実装を前提にしない。

Candidate cost models:

- included Agent credits
- usage-based extra credits
- BYOK
- provider-specific execution passed through separately

Canovia側が提供する価値は、単なるProvider API resaleではなく:

- context
- orchestration
- safety / approval boundary
- Product Intelligence
- verification continuity

に置く。

Pricingより先に、AI cost / accepted improvement / repeat usage / user valueを観測する。

既存V41.5 Product Grant / Entitlement architectureはruntime foundationとして再利用するが、当時のPurpose Pack product shapeをそのまま将来商品名として固定しない。

## 30. Partner Ecosystem Relationship

Developer ProはCanovia外部Execution Partner構想とも接続します。

Concept:

```text
Canovia Partner Invitation
↓
Developer accepts
↓
Existing Development Plan
↓
Canovia Integration Workstream
↓
Developer Pro assistance
↓
Activity / Launch Integration
↓
Review
↓
Compatible App
```

Developer ProがCanovia Compatibility対応を支援することで、Partner onboardingの技術コストを下げられる可能性があります。

---

## 31. Integration Grant

Partner向けDeveloper Credits / Integration Grantを将来検討できます。

Purpose:

> Canovia Integration対応によって増える追加コストを補助する。

Eligible candidate work:

- Account Link / OAuth
- Activity Integration
- Launch Integration
- Capability mapping
- Sandbox support
- Compatibility review fixes

Not eligible:

- app本体の一般機能
- Canoviaと無関係な通常bug fix
- unrelated product development

Grantは一般開発費補助ではなく、Canovia Ecosystem compatibilityの促進手段として扱います。

---

## 32. Partner Approval Boundary

Integration Grantが存在しても、Partner実装をCanoviaが無条件にmerge/deployする構造にはしません。

Partner側Repository ownership・approval rulesを尊重します。

Developer Proは:

- spec package
- implementation assistance
- compatibility tests
- review evidence

を提供し、最終権限はRepository ownerへ残します。

---

## 33. Canovia Self-Development

Developer ProはCanovia自身の開発にも利用可能な設計を想定します。

Target self-improvement loop:

```text
Observe
↓
Understand
↓
Specify
↓
Implement
↓
Validate
↓
Human Approve
↓
Measure
```

重要:

> 自己改善 = 自己判断による無制限自動変更ではない。

Canovia自身のProduct Constitution、AGENTS rules、approval boundaries、branch/PR workflowを同じように適用します。

---

## 34. Product Signal to Development Loop

Canovia自身の例:

```text
Telemetry / Feedback / Error
↓
Canovia detects candidate problem
↓
Decision Package
↓
Human selects direction
↓
Specification
↓
Implementation Package
↓
Coding Agent
↓
PR
↓
Human merge
↓
Post-release measurement
```

このLoopが成立すれば、Developer Proは単なるユーザー向け機能ではなくCanovia開発自体のdogfooding基盤になります。

---

## 35. Approval Constraints

Implementation Packageは「何をしてよいか」だけでなく「何をしてはいけないか」を明記します。

Examples:

- no destructive migration
- no billing semantics change
- no public API break
- no permission expansion
- no secret handling change
- no production deploy
- no architecture replacement
- no unrelated refactor

これによりCoding Agentの自由度を必要範囲に限定します。

---

## 36. Review Agent

Review AgentはCoding Agentと異なる責務を持つことを想定します。

Checks:

- Acceptance Criteria
- tests
- regressions
- architecture boundaries
- security constraints
- specification consistency
- unintended behavior changes
- docs sync
- migration risk

将来的には同一モデルを利用する場合でも、role/contextを分離します。

---

## 37. Self-fix Boundary

Test failureに対する自動修正は可能でも、仕様を変えてtestを通すことは許しません。

Allowed:

```text
implementation bug
→ self-fix
→ rerun tests
```

Not allowed:

```text
test fails
→ silently weaken Acceptance Criteria
→ modify spec to match bug
```

仕様変更が必要ならHuman Decisionへ戻します。

---

## 38. Repository Context Retrieval

Developer Proは毎回Repository全体を読み込むのではなく:

1. top-level instructions
2. product/spec index
3. affected module
4. dependent interfaces
5. tests
6. recent relevant decisions

のように段階的にContextを取得することを想定します。

これはImplementation Package生成にも利用します。

---

## 39. Context Freshness

Repository Contextにはfreshnessが必要です。

原則:

```text
latest main
> cached repository summary
> old conversation memory
```

Branch実装前には必ず最新baseを確認します。

古いspec summaryがlatest codeと競合した場合は再調査します。

---

## 40. Current Canovia Workflow as Reference

現行CanoviaのRepository workflow:

```text
latest main
→ dedicated branch
→ implementation
→ validation
→ PR
→ user review / manual merge
```

はDeveloper Proの初期Automation Level 2に近いreferenceです。

Developer Proは、この既存Human Approval workflowを壊さず自動化を増やす方向が適切です。

---

## 41. Product Constitution vs AGENTS.md

両者は別責務です。

### Product Constitution

「どんなプロダクト判断を守るか」

### AGENTS.md

「このRepositoryでどう作業するか」

Product ConstitutionをAGENTS.mdへ全文コピーしません。

AGENTS.mdは必要なauthority documentへ誘導します。

---

## 42. Data / Privacy Boundary

Developer Proは将来:

- source code
- private repository metadata
- product specification
- telemetry
- user feedback
- implementation prompts

を扱う可能性があります。

そのため実装前に:

- data retention
- provider sharing
- secret redaction
- repository authorization
- organization boundaries
- model training policy
- auditability

を明示する必要があります。

本Future Specはそれらの具体実装を決めません。

---

## 43. Security Principle

Coding AgentにRepository write capabilityを与える場合でも:

- minimum permission
- scoped repository access
- branch isolation
- protected main
- secret separation
- auditable actions

を基本とします。

Direct production mutationを通常経路にしません。

---

## 44. Deployment Principle

最初から自動Production Deployを目指しません。

Preferred maturity:

```text
local/branch validation
→ PR
→ preview/staging
→ human approval
→ production
```

Deployment automationはImplementation automationより後に成熟させます。

---

## 45. Observability

Developer Pro自身の品質を測ります。

Candidate metrics:

- specification clarification turns
- implementation success rate
- test-pass-after-first-run
- self-fix count
- PR acceptance rate
- human changes after generation
- rollback rate
- production defect rate
- cost
- latency
- context-health blockers

単純な「生成コード量」は成功指標にしません。

---

## 46. Failure Model

Developer Proは失敗理由を区別します。

Examples:

```text
insufficient product context
missing architecture specification
repository access failure
agent implementation failure
test infrastructure failure
approval blocked
provider outage
cost limit
```

すべてを「AIが失敗した」にまとめないことが重要です。

---

## 47. User Experience Principle

Developer ProのUIはAgent内部処理を過剰にユーザーへ露出しません。

ユーザーが主に理解すべきもの:

- 今何を決める必要があるか
- 何を実装する予定か
- 何が変更されたか
- 検証結果
- リスク
- 承認が必要か

内部promptやchain-of-thoughtの閲覧を前提UXにしません。

---

## 48. Product Improvement Safety

Telemetryから改善候補を出す場合も:

- correlationをcausationとして扱わない
- single KPI optimizationを避ける
- sample size / confidenceを考慮
- user trust / accessibility / privacy impactを確認
- costly implementation before validationを避ける

ことを原則とします。

---

## 49. Public Roadmap Boundary

この構想は長期的に重要ですが、現在の公開優先順位を遅らせる理由にはしません。

原則:

```text
Current Canovia Core Loop
→ iOS Soft Launch
→ real-user validation
→ Developer Pro expansion
```

Developer Proや高度な自動開発は、明示的なProduct判断がない限り公開後拡張です。

---

## 50. Promotion Criteria

Developer Proの一部をFutureから実装へ昇格させる場合、少なくとも以下を確認します。

- current user problem
- expected user value
- current Repository architecture
- smallest validation scope
- security/privacy impact
- AI cost
- Human Approval boundary
- non-AI fallback where necessary
- success metrics
- explicit Non-Goals

---

## 51. Initial Validation Order

将来的な推奨順序:

### Phase A — Context Foundation

- documentation indexing
- AGENTS awareness
- spec authority
- Context Health prototype

### Phase B — Specification / Implementation Package

- requirement clarification
- package generation
- human approval

### Phase C — Branch / PR Automation

- bounded implementation
- tests
- PR

### Phase D — Review / Spec Sync

- Review Agent
- Living Specification
- consistency checks

### Phase E — Product Improvement Proposal

- telemetry → Decision Package
- human-selected improvement

### Phase F — Higher Automation

- preview/staging
- bounded production approvals

この順序は固定Roadmapではなく将来設計候補です。

---

## 52. Non-Goals of This Future Specification

この文書の追加によって、以下を実装しません。

- Codex API connection
- automatic PR creation
- automatic Deploy
- Context Health runtime
- Developer Pro billing
- Implementation Credits
- Partner Credits
- AGENTS.md auto-generation
- specification auto-generation
- new advanced GitHub account connection
- autonomous Product Improvement
- automatic production changes
- standalone Developer App
- Developer Marketplace

---

## 53. Current Product Compatibility

このFuture構想を理由に:

- current Development Workspaceを再設計しない
- current GitHub integrationを置き換えない
- current Execution EcosystemをDeveloper専用にしない
- current Product Catalogを変更しない
- current AI entitlementを変更しない
- current iOS readiness workを遅らせない
- current public roadmapへDeveloper Proを割り込ませない

ことを明示します。

---

## 54. Core Design Invariants

1. Specification First
2. latest Repository is implementation truth
3. Future Design is not Current Requirement
4. Human owns consequential decisions
5. Coding Agent is bounded Software Execution
6. Context is retrieved selectively
7. Code / Tests / Specification should agree
8. Product metrics do not directly trigger implementation
9. Recommendation / Product Decision / Advertising remain separate
10. AI cost is measured before unlimited automation
11. Canovia Core and specialist Developer surface remain separable
12. External / spun-out Developer App can reconnect as an Execution Provider

---

## 55. Final Direction

Target experience:

```text
User shares idea
↓
Canovia understands intent
↓
Canovia clarifies only necessary decisions
↓
Specification becomes durable context
↓
Repository / architecture context is assembled
↓
Implementation Package is approved
↓
Coding Agent implements
↓
tests / review / spec consistency run
↓
PR is presented
↓
Human approves
↓
Canovia observes the outcome
```

For Canovia itself:

```text
Observe
↓
Understand
↓
Specify
↓
Implement
↓
Validate
↓
Human Approve
↓
Measure
```

Developer Proは「Codexを呼べるボタン」ではありません。

**CanoviaがProduct Context・Living Specification・Development Decisionを継続保持し、Coding Agentを安全かつ効率的に使うためのDevelopment Orchestration Layerになること**が、この構想の中心です。


---

## V57.8 implementation note

V57.8 promotes one narrow part of this future direction into the current
product:

```text
current Development Implementation Brief
→ explicit human confirmation
→ existing Execution Request
→ existing external Execution Orchestration handoff
```

It intentionally does **not** implement Developer Pro runtime, autonomous
coding, provider-specific Codex/Claude integration, or automatic PR creation.

The current provider-neutral Execution Ecosystem remains authoritative. A real
future coding provider should reuse `ProviderConnection`,
`execution_context`, signed Activity return and GitHub Evidence rather than
creating a Developer-Pro-only runtime.

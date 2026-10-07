# Canovia Product Specification

更新基準: 2026-10-07 / V58.19 + Early Access Monetization / Release Level product decisions

V41.8〜V41.12のNative AI Practice / Adaptive Learning Flow / Recall基盤を維持しつつ、V41.13ではTaskごとのPrimary Actionを1つに整理し、旧「今日」をメインナビから退役させてCanovia Inboxを追加する。Home=Now、Inbox=Input、Roadmap=Future、Timeline=Pastとして主要導線の責務を分離する。詳細は [V41.12仕様](V41.12_RECALL_CANDIDATE_IMPORT.md)、[V41.13仕様](V41.13_ACTION_INBOX_REFRAME.md) を参照。

この文書をCanoviaのプロダクトレベル仕様の正とする。旧PaceKeeper v16系のProject Overview / Requirements / Functional Spec / Future Ideasは履歴資料として扱い、現在仕様の判断には本書と各V40系実装ドキュメントを優先する。

## 1. Product Vision

Canoviaは、**頑張りたいけれど、頑張り方が分からない・やる気が続かない人が、目標や夢へ進めるよう支援する伴走型OS**を目指す。

中核となる体験は次の循環である。

```text
Inbox / 現実からのInput
  ↓
Plan
  ↓
Task / Roadmap
  ↓
Home / Primary Execution Action
  ↓
実行 / Specialized Tool / Guided Execution / Timer fallback
  ↓
振り返り / Evidence / WorkLog
  ↓
AI支援 / 計画更新 / 学習
  ↓
次の行動
```

Canoviaは「完璧な計画を守らせる」より、現実の行動・発見・制約に合わせて計画を育て直すことを重視する。

## 2. Product Principles

1. **Canovia本体は一人でも価値がある。** Socialが成立しなくてもPersonal Utilityだけでプロダクトが成立する。
2. **無料でも核となる目標達成体験を成立させる。** 基本思想は「努力する権利には課金しない。努力を加速する価値に課金する」。
3. **アプリが知っている事実を再入力させない。** Plan、Task、WorkSession、WorkLog等の既知情報を再利用する。
4. **FactsとInterpretationを分離する。** 作業事実と、AI/推薦による意味付けを分ける。
5. **収益化ロジックを機能へ散らさない。** Feature側はPremium/Pack/Gift/Sponsorを直接判定しない。Coinは直接Feature解放に使わない。
6. **Feature FlagとEntitlementを分離する。** 公開可否と利用権は別責務とする。
7. **RoadmapとRuntime Feature Controlを分離する。** Product statusがruntime enableを自動決定しない。
8. **DeployとReleaseを分離できる構造を目指す。** ただしApp Reviewを迂回するために使わない。
9. **ユーザーの声は需要シグナルとして使い、単純多数決でプロダクトを作らない。**
10. **将来機能のために現在のUXを複雑にしない。**
11. **時間の経過そのものをProgressの証拠にしない。** 時間はTask負荷・期限・今日の実行可能性を判断する目安として使い、進捗はTask状態・Milestone・Evidenceを優先する。
12. **実行環境をすべて内蔵しない。** Canoviaは外部ツールを置き換えるのではなく、Execution ActionとEvidenceを通じて現実の作業とPlanを接続する。
13. **管理のための管理を減らす。** ユーザーが別画面でContextを整理しなくても、会話・Inbox・Evidenceから必要な情報を安全に構造化して再利用する。
14. **最初の価値を課金前に体験できるようにする。** First Companion ConversationはFree Goal Pathに含め、継続的な摩擦削減をPremium価値とする。

## 3. Now / Next / Future

### Now

現在実装済み、またはV40.7で基盤を持つ領域。

- Guest / Account、所有権、共同計画
- Plan / Task / Roadmap / Recommendation
- Action Hierarchy（専用Execution Tool → Guided Execution → Timer fallback）
- Canovia Inbox（text / URL / image / PDF capture、private file、pending横断表示）
- Inbox Intelligence（destination suggestion → Human Review → Future Memo / Career / Recall / Evidence / Resource）
- AIはInboxでPlan / Task IDを決定せず、名前hintだけを返す境界
- Canovia Guide v2（Home / Inbox / Timer fallback / AI演習 / Recall / Resource / Collaboration）
- Goal Context Foundation（Desired State / Current State / Known / Unknown / Signal / Constraint / Driver）
- Goal Context Factのconfirmed / candidate / unknown分離とdeterministic Readiness
- Adaptive Goal Discovery（Goalだけ先に保存 / 1問ずつ / quick・text・skip適応 / Live Preview）
- Conversational Onboarding（新規Userの最初の入口をPlanフォームではなくFirst Companion Conversationへ変更）
- `conversational_onboarding` はFree CapabilityとしてNative AIを利用でき、provider停止時はdeterministic Goal Discoveryへfallback
- Goal Discovery ConversationはNative AIが会話表現を担当し、質問順・confirmed / unknown・Readinessは既存Goal Context PolicyをSource of Truthとして維持
- 新規AccountにGuest Planがない場合は登録直後にFirst Companionへ入り、Guest Planをclaimした場合は既存作業を優先してHomeへ戻す
- Future Memoは通常UXから退役し、既存table / serviceをCanovia Memoryの互換ストレージとして維持
- Invisible Memoryは直近User Inputに実在するliteral source quoteをserver-sideで確認した場合だけ自動保存し、推測・診断・属性推定を保存しない
- Premium Companionも同じMemory capture boundaryを使い、保存済みMemoryを最大8件だけContextとして再利用
- Manual Plan Formとlegacy Future Memo routeはcompatibility / control fallbackとして残し、通常導線では管理を要求しない
- Inboxの公開routing destination / AI routing schemaからFuture Memoを外し、legacy POST互換だけserver-sideに残す
- Provisional PlanへReadiness途中でも進める導線
- HIGH ReadinessはDesired State + Current State + Success Signalを必須化
- Initial Plan AIへGoal Contextを注入し、UnknownをMeasurement Taskへ変換するPolicy
- Guided Execution（Before Action → real-world execution → Reflection → Task Evidence）
- Guided Reflectionはself-report強度を保持し、Task Progressを自動変更しない
- Goal Pattern Demand / Tool Discovery（Goal Context・Guided Executionの実利用をread-only集計）
- Tool需要はcross-user demandを最優先Signalとし、AIが自動で新Toolを公開しない
- Canovia Companion Premium Foundation（global / Plan / Task scoped conversation）
- CompanionはGoal Context / selected Plan・Task / Recent Evidence / Inbox件数 / Current Screenを必要範囲だけ参照
- CompanionのAI提案はMutation Candidateとして隔離し、人が明示確認するまでDBへ反映しない
- Companion CandidateのPlan / Task / User IDはAI出力を信用せず、selected Canovia contextからserver-sideで確定
- Human-confirmed Mutation Applyはtype別allowlist / server-side validation / ownership check / transaction / auditを通す
- Companion経由ではTask Progress・done/cancelled・Plan公開/共同設定を変更せず、Evidenceと計画変更を分離
- Companion applyはapply_request_idとrow lockで二重mutationを防ぎ、before / after / blocked fields / applied targetを監査保存
- Contextual Companion EntryはCurrent ScreenからPlan / Task scopeをserver-sideで確定し、通常導線ではContext選択を要求しない
- Task / Guided Executionは同じTask conversationを再利用し、Guided Executionでは最新Task Evidenceもentry contextとして引き継ぐ
- Plan / Roadmapはselected Plan、InboxはItem単位の固有ContextからCompanionへ入り、linked Planが同じでもInbox conversationをgeneric Plan threadと混同しない
- Companion entryは元画面のsource path / routeをThreadに保持し、会話画面へ遷移した後もCurrent Screen contextを失わない
- Step 1で作成済みのglobal / Plan / Task Threadは同じscopeなら再利用し、Contextual Entry導入でConversationを不要に増やさない
- `/companion` の手動Context selectorは、別対象やglobal相談を明示選択するfallbackとして維持する
- Companion Continuityはpending Candidate / 前回会話後のEvidence / Known Unknown / next_action未整理を既存stateから決定論的に再構成する
- Continuityは最大3件を優先表示し、専用既読DB・Push通知・自動AI実行・自動Message生成を追加しない
- pending CandidateはConversation一覧でも件数を見せ、Native AIへ同状態を渡しつつserver-sideでもtype + normalized payload一致のpending Candidate重複保存を防ぐ
- Evidence follow-upは前回Assistant replyより新しいEvidenceだけを対象にし、一度Companionがreplyすれば自動的に解消する
- Known Unknownは同じFact keyがconfirmedになれば解消し、next_action signalはTaskへnext_action_noteが設定されれば解消する
- Companion messageはrequest_idで再送を吸収し、Native AI失敗後も同じ送信を安全に再試行可能
- Companionは `canovia_companion` と `automatic_ai_execution` を別Capabilityとして扱い、Premium Coreから利用権を供給
- WorkSession / Timer / WorkLog / Continuity
- Calendar / Availability
- AI JSON外部往復によるTask生成・計画更新
- 外部AI Handoff UIの統一（Promptはコピー中心、JSONは1クリック貼り付け＋手動fallback）
- AI Practice / Question Pack基盤
- AI Practice途中回答の自動保存・無通知再開
- AI Practice回答済み・評価済み状態の永続復元と次Step自動Reveal
- AI Practice評価JSON POST時のanswered状態durable recovery（PHP Session欠落時もDBから復元）
- AI Practiceのcurrent-step UIと構造化next_stepによる「次にやること」導線
- Home Plan Hub（CURRENT TASK / Task短縮一覧 / Plan Tools / 最近の活動）
- Focus Timerの任意Tool化と「時間=計画上の目安」方針
- TaskEvidence / TaskMilestone / ExecutionAdapter基盤
- AI Practice assessmentのnative Evidence自動記録
- Artifact / Focus Timerのnative Evidence自動記録
- Home Plan HubでCURRENT TASKのRecent Evidenceを表示
- Resource / Project Artifact
- Canovia Memory（旧Future Memo互換ストレージ。通常の手動管理導線は退役）
- Achievement / Timeline / Release Notes
- PWA / Offline / Safe Update
- Feedback自由記述 / Admin Feedback
- FeatureAccessService / Entitlement resolver境界
- FeatureKey一元管理
- FeatureFlagServiceの最小公開可否境界
- Canovia Future / Roadmap Votingの最小データモデルとSupport UI
- V41.5 Economy Catalog / Product Grant / source-specific Product Grant Entitlement resolvers
- AI Capacityの独立境界（standard / boosted）
- 決定論的Economy RecommendationとAdmin Economy Inspector
- 単一アカウントSuper Admin、Settings Hub、Complimentary Premium、Admin Free/Premium Preview
- Premium CoreのNative AI Practice（問題生成 / 回答評価 / manual fallback）
- Premium CoreのCanovia Companion（文脈整理 / 会話 / Mutation Candidate / Human-confirmed Apply）
- Hybrid Question Assembly（Question Bank優先、不足分だけNative AI補完）
- Practice Question Demand履歴（要求数 / Bank供給数 / 生成不足 / focus / coverage）
- Practice Demand Admin集計（exam profile / focus topic / Bank供給 / AI補完不足）
- Native AI生成問題のQuestion Candidate保存・人手レビュー・Draft Pack昇格フロー
- Study Task Progression（completion signal → mastery verification → next eligible Task）
- AI Practiceの完了前仕上げ確認（直近2回の安定確認・1回だけの高得点ではTaskを跨がない）
- AI Practice ResultのPrimary Action化と詳細評価の折りたたみ
- 次Task内容を使ったStudy Practice Strategy / Prompt handoff
- Study Activity Policy（Question Practice / Recall / Resource Study）とTask別のPrimary Activity選択
- TOEIC語彙・暗記TaskでAI演習をPrimaryにしない学習方法Policy
- AI Practice Reliability（出題内容 / 採点 / Coverage / 学習方法適合度）の視覚化
- Recall Learning Loop（Task Deck / Again-Hard-Good-Easy / spaced repetition / Task Evidence）
- Recallカード一括追加・重複防止・review idempotency・定着候補表示
- Recall Material Import（画像 / PDF / text → pending Candidate → Human Review → Deck）
- Recall Candidateのsource excerpt / confidence / private source provenance / batch promote・reject
- Native AI Responses APIのimage / PDF input対応（既存text-only caller互換）
- Native AI Run usage history（provider / model / AI Capacity / token usage / status）

### Next

iOS正式公開準備や、現行基盤を実運用へ接続する近い将来。

V41.16まで実装済み。次の大きな検討:
- First Companion → Provisional Plan後のNative task generationをFree/Premium境界込みでどこまで自動化するか
- Companion / Invisible Memory実利用データを見た上で、Proactive notification / follow-up通知を別バージョンで判断
- Memoryの確認・削除・AI利用停止をSettings内の最小Controlへ集約するかを実利用を見て判断

- iOSアプリ化とApp Store Review運用
- server-backed Feature Flag保存とAdmin操作
- platform / minimum app versionを使った公開制御
- Roadmap候補のAdmin管理（表示、投票受付、threshold、priority、status）
- SupportしたRoadmap Featureのstatus変化・Release通知
- Roadmap FeatureとRelease Notesの明示的な紐付け
- StoreKit / App Store Server API / Stripe等からProduct Grantへ同期するBilling Adapter
- Premium / Pro capabilityの具体実装とFeatureKey接続（Workspace共通tier。CareerはBeta Preview、Dev ProはFuture）
- failed Recall Sourceの再抽出UI（V58.7実装済み）・複数ページbatch ingest（V58.8実装済み）
- Plan Resourceからの安全なRecall material ingest（V58.11実装済み：URL非fetch + 明示material + Resource provenance）
- Recall成績をTask progressionへ使うPolicy（V58.6実装済み：Recall-primary Taskのみ、Deck全体定着 + 明示確認で完了）
- Listening / Dictation / Shadowing等のStudy Activity拡張（V58.10実装済み）
- Native AI usage historyを使ったquota / cost policy

### Future

需要確認後に実装判断する領域。

長期構想・未実装設計は `docs/future/` に分離して保存する。Future文書は現行仕様より優先されず、存在するだけでは実装対象にならない。実装へ昇格する際はlatest `main` を再調査し、versioned implementation spec / 本Product Specへ必要事項を同期する。運用ルールは `docs/future/README.md` を正とする。

Developer Pro / AI Development Orchestrationの長期構想は `docs/future/DEVELOPER_PRO_AI_DEVELOPMENT_ORCHESTRATION.md` を参照する。現時点ではCanovia Core Loop完成・iOS Soft Launchを優先し、このFuture Spec追加だけを理由に実装開始しない。

- Coin購入 / Coin消費 / Earn Coin
- Gift
- Sponsored Access
- 高度なpercentage rollout / audience targeting
- 公開プロフィール
- Public Challenge
- 同じ目標を持つ人の探索
- Achievement共有
- Cheer
- Follow
- Progress Feed
- Social notification
- private / shareable / publicの一般化されたvisibilityモデル

## 4. Monetization and Entitlements

Canoviaの現行Product-level課金方針は [Canovia Monetization Specification](CANOVIA_MONETIZATION_SPEC.md) を正とする。

Canonical plan model:

```text
Free      = Execute
Premium   = Guide
Pro       = Understand
Dev Pro   = Observe & Improve the Product
```

原則:

> Freeでも、自分で動けば目標へ到達できる。  
> 課金すると、判断・理解・自動化をCanoviaがより多く引き受ける。

Workspaceごとの別契約を基本にしない。

- Study / Development / Career等はCanovia Free / Premium / Proを共有する
- Study専用Pro / Development専用Proを別商品として増殖させない
- Dev Proのみ、公開後のProduct Intelligenceを扱うDevelopment専門追加プラン候補とする

Study:

- Free: 学習を実行・記録できる
- Premium: 弱点・次の学習・ペース等の判断を支援する
- Pro: 教材・長期履歴・Knowledge Stateまで理解してContextを構築する

Development:

- Free: Repository連携、基本管理、非AI中心の外部AI向けPrompt生成
- Premium: PR等からの軽量推論、Development Rules / specification / AI制約生成
- Pro: Repository内容を読み、Code / Spec / Test / Decisionを横断したContextを構築する
- Dev Pro: 公開後のTelemetry / Product Intelligenceから改善ループを支援する

`Billing`、`Entitlement`、`AI Capacity`、`Release Level`は別責務とする。

```text
Billing != Entitlement
Entitlement != AI Capacity
Entitlement != Release Level
```

`FeatureAccessService` をEntitlementの最終境界として維持し、Feature codeへProduct名・価格・Provider条件を散らさない。

V41.5で実装されたProduct Grant / resolver / Economy Catalogはruntime foundationとして維持するが、当時の `Premium Core + Purpose Pack` 商品構成は将来Product shapeの正ではない。runtime migrationは別実装仕様で行い、このProduct Spec更新だけでは既存ProductKeyやgrant semanticsを変更しない。

Early AccessではFreeを実利用可能とし、Premium / Pro / Dev Proは原則Coming Soon + previewで価値を見せる。料金・checkout・AI quotaは実利用・原価・支払意思を観測してから確定する。

AI原価については無制限利用を前提にせず、deterministic処理、Context retrieval、cache、小さい推論、高コストAgentを段階分離する。Dev Proでは将来、base subscription + Agent Credits / BYOK等を検討できる。

## 5. Feature Flag / Release Level

個別Feature Flagと、ユーザーへ提供する整合済みProduct構成を分離する。

詳細は [Canovia Release Level / Feature Flag Specification](CANOVIA_RELEASE_LEVEL_SPEC.md) を正とする。

Feature Flagの問い:

> この個別機能を現在有効にするか。

Release Levelの問い:

> このactorへ、どの整合済みCanovia構成を提供するか。

Entitlementの問い:

> 公開済みのこのFeatureを、このactorが契約・権利上利用できるか。

Release LevelはFeature Flagを束ねる上位のrelease contractであり、単なる「完成機能数」ではない。

初期分離:

```text
Public Release Level
Admin Preview Level
User Access Level
```

AdminのPreview変更はPublic Releaseを変更しない。Beta tester等はUser Access LevelでPublicより先の整合済みLevelへ進められる。

Initial product levels:

- Level 0 — Core Stable
- Level 1 — Early Access Core
- Level 2 — Product Preview
- Level 3 — Beta Expansion
- Level 4 — Internal Preview

Careerは専用UI / basic flowを統一した後、Level 3 Beta Previewへ載せる候補とする。

Release LevelはUI表示だけでなくNavigation / Empty State / Workspace Switcher / Deep Link / server route / permissionまで整合させる。上位Levelから下位Levelへ戻しても作成済みデータを削除しない。

Overrideの概念優先順位:

```text
Emergency Kill Switch
→ explicit safety / feature override
→ actor-specific beta/access override
→ Public Release Level
→ default feature state
```

Release LevelとEntitlementは別軸とする。Featureが未公開なら、Paid entitlementがあってもPublic利用可能にはしない。

V40.7の`FeatureFlagService`、既存Admin Free/Premium Preview、`FeatureAccessService`を置き換えず、その上にrelease maturityの軸を追加する。server-backed persistence / Admin操作は実装時にlatest mainへ合わせて設計する。

## 6. Deploy / Release / App Store

目標は、審査済み・配布済みコードについて、ユーザー向け公開タイミングをサーバー側で制御できること。

```text
Implement
  ↓
App Reviewで説明・確認可能にする
  ↓
Binary distribution
  ↓
Feature Flag off / internal
  ↓
段階Release
  ↓
必要ならoffへ戻す
```

Feature Flagは、審査されていない巨大な隠し機能を後から秘密裏に公開するためには使用しない。半年先のSocial全体を今から隠して実装することもしない。

## 7. Canovia Future / Feedback V2

Feedbackの主体を「自由記述だけ」から、Canovia側が整理した候補へSupportできる**Canovia Future**へ拡張する。

目的:

- 自由記述より参加ハードルを下げる
- プロダクト方向性をCanovia側で保持したまま需要を定量化する
- 自分がSupportした機能の進展を感じられる体験を作る

原則:

- 1 actor / 1 candidate / 1 Support
- Guestはbrowser actor token、Accountはuser idを投票単位にする
- thresholdは実装保証ではなく「Roadmap入り・正式検討開始の目安」
- 最終判断は運営側が保持する
- 従来の自由記述Feedbackは廃止しない

Roadmap status:

- `voting`
- `considering`
- `planned`
- `in_development`
- `ready`
- `rolling_out`
- `released`

理想的な流れ:

```text
Idea
 ↓
Voting
 ↓
Roadmap
 ↓
Development
 ↓
Release
 ↓
Release Notes
```

Roadmap statusとFeature Flagc񯨇򥋕連動させない。

## 8. Social Future

SocialはCanoviaの前提条件にしない。

拡張順:

```text
Personal Utility
 ↓
Outcome / Success
 ↓
Social Layer
 ↓
Network Effect
 ↓
Economy
```

Social候補はFutureとして保持し、需要が確認された機能だけを順次実装する。SNS化そのものを目的にせず、「自分自身が前に進める」という中核価値を優先する。

## 9. Visibility Direction

将来、共有対象には以下の概念を扱える余地を持たせる。

- `private`
- `shareable`
- `public`

ただし現時点で全モデルへ共通visibility列を追加することはしない。Featureごとの実要件が確定した段階で共通化を判断する。

## 10. Admin Direction

### Roadmap Admin

将来管理する項目:

- published / hidden
- voting enabled
- threshold
- priority / sort
- status

### Feature Flag Admin

将来管理する項目:

- enabled / disabled
- environment
- platform
- minimum version
- rollout
- audience

両者を同一テーブル・同一statusへ統合しない。

## 11. Data Boundaries

### Product Roadmap

`roadmap_features`

- feature_key
- title
- description
- threshold
- status
- sort_order
- is_published
- voting_enabled
- released_at

`roadmap_votes`

- roadmap_feature_id
- user_id nullable
- actor_token nullable
- voter_key

`(roadmap_feature_id, voter_key)`をuniqueとし、同一actorの重複Supportを防ぐ。

### Runtime Access

- Feature Flag: `FeatureFlagService`
- Entitlement: `FeatureAccessService`
- Ownership: `PlanOwnershipService`

3つは独立した責務として維持する。

## 12. Explicit Non-goals of V40.7

- Premium購入
- StoreKit
- Coin balance / ledger
- Coin購入・消費
- Earn Coin
- Gift
- Sponsor
- Paywall
- Social Feed / Follow / Cheerの本実装
- Roadmap Admin CRUD
- Feature Flag Admin UI
- DB backed remote flag persistence
- percentage rollout
- push notification
- Roadmap statusからFeature Flagへの自動同期

これらは仕様上Future/Nextとして保持し、需要とiOS要件が具体化してから実装する。


## 13. V41 Execution / Evidence Foundation

Canoviaの実行支援は `Task -> Timer -> WorkLog` だけを正規経路としない。

```text
Plan
  -> Task
  -> Execution Action
  -> Evidence
  -> Progress
  -> Next Action
```

Focus TimerはExecution Actionの一つであり任意。正確な作業時間を取得できない外部作業でも、GitHubのPR、ファイル更新、写真、Calendar、Canovia内部イベントなどのEvidenceからTaskの状態変化を扱える設計を目指す。

V41では `TaskEvidence`、`TaskMilestone`、`ExecutionAdapter`、保守的な `EvidenceProgressService` を基盤として追加する。AI Practice assessmentは最初のnative Evidenceとして自動保存する。

V41.1ではnative EvidenceをTask-linked Artifactの登録・更新とFocus Timer完了/中断へ拡張する。Artifactは中程度のconfidence、Focus Timerは低confidenceのactivity Evidenceとして保存し、どちらも単独ではprogressを変更しない。Plan HubではCURRENT TASKに最近のEvidenceを最大3件表示し、ユーザーへ「Canoviaが確認できた事実」を返す。

EvidenceからProgressやPlanを自動変更するPolicyは別責務とし、V41ではEvidenceProgressServiceは進捗提案のみを返す。将来AIが導入された場合も、Evidence収集・意味解釈・Progress変更・Plan最適化を分離し、確度の低い判断や大きな計画変更を無確認で適用しない。


## 14. V41.2 Adaptive Surface Engine

Plan Hubは固定Card一覧ではなく、Category Profileと現在Situationからregistered Surface Moduleを選択・並べ替えて構成する。

```text
Plan
  -> Category Profile
  -> Situation Resolver
  -> Surface Engine
  -> Plan Hub / Roadmap
```

現在のCategory Profileは study / career / development / creative / general。カテゴリは初期文脈を与えるが、実際のSurface表示はTask・Evidence・Artifact・期限等のSituationも使う。

例としてcareerではCareer Pipelineを表示し、activeな面接Taskが存在する間だけInterview Focusを追加する。studyではAI Practiceまたは直近評価がある場合にStudy Focus、development / creativeではArtifactが存在するときだけDelivery Focusを追加する。

AI導入後は自由なUI生成を許可しない。AIは `policyContext` に含まれるregistered module IDだけを使って順序・非表示を提案し、`applyDecision` がunknown IDを除外する。current_task / plan_toolsはprotected moduleとして非表示不可とする。

Roadmapは同じCategory Profileを利用し、V41.2では安全なtask_flow rendererを維持しつつ、study_map / pipeline / delivery_flow / milestoneをpreferred rendererとして保持する。専門renderer実装時にController/View契約を変えず差し替えられる。


## 15. V41.3 Career Capture / Interview Review

就活カテゴリでは入力負担を最小化するため、Application本体より先に `CareerCapture` を入口に置く。

```text
Screenshot / URL / future Email / Calendar
  -> CareerCapture
  -> Extract / Match
  -> CareerApplication
  -> CareerSelectionEvent
  -> Surface Engine
```

V41.3 UIではScreenshotとURLを利用できる。企業名が分からない段階でもpending Captureとして保存でき、Application作成を必須にしない。手動Application入力はfallbackとして折りたたみ領域に置く。

Screenshot本体は現行Render構成でローカルfilesystem永続性に依存しないよう、非公開のDB Payloadへ最大3MBで保存する。将来object storageへ移行するためのpath境界は維持する。

CareerApplicationが存在する場合、Career PipelineはTask推定ではなくApplication stageを優先する。存在しない既存PlanはV41.2のTask inferenceへfallbackする。

Interview Eventは予定時刻とReview状態からSurfaceを切り替える。

```text
面接前       -> NEXT INTERVIEW
面接後未振返 -> INTERVIEW REVIEW
振返済       -> RESULT WAITING
結果確定     -> Application更新
```

Interview Reviewの質問は `InterviewReviewQuestionService` が供給する。前回Reviewのnext_focusを次回質問へ引き継ぎ、final interview等のstageでも質問を追加できる。Question/Answerはprompt/source付きで永続化するため、将来rule sourceをAI sourceへ置換してもReview UI/schemaを変更しない。

Review完了・選考結果はTaskとの関連がある場合Native TaskEvidenceへ保存するが、これらの事実だけでTask progressを自動変更しない。


## 16. V41.4 Practice Calibration / Weakness Priority

AI Practiceは「誤答したtopicを次回focusへ入れる」だけではなく、誤りの質・再現性・補強コスト・最近の出題偏りを分離する。

```text
Answer
  -> Error Classification
  -> Weakness Priority
  -> Question Mix
  -> Provider Selection
```

`StudyPracticeExamProfileService` は試験形式と問題難易度の校正を担当する。V41.4ではAP科目Aを最初の専用Profileとして、single_choice 4択を基本にし、数値計算は不要な筆算精度で難しくせず、条件判断・概念統合・式選択で難易度を調整する。

`StudyWeaknessPrioritizationService` は直近最大8 Attemptからtopic単位のseverity / confidence / expected_gain / recovery_cost / saturation / error_typeを算出する。1回だけの誤答はsuspectedとしてPrimaryへ固定せず、複数Attemptで繰り返したものをconfirmedとしてPrimary候補にする。最新Attemptで古い単発弱点を明確に克服した場合はresolvedとする。

Question MixはPrimary / Secondary / Diagnosticに分ける。Confirmed weaknessがある場合でもDiagnostic枠を残し、既知弱点の補強によって別の弱点が見えなくなることを防ぐ。Suspectedしかない場合はPrimaryを0とし、Diagnosticを過半にする。

External AI assessmentはquestion_feedbackへ `error_type` と `weakness_topics` を返す。calculation_slip / carelessはconcept_gap等より弱いSignalとして扱う。原因を回答から確認できない場合はunknownを使用し、AIに誤答理由を断定させない。

Question Bank selectorはV41.4で `bank-v2-balanced`、V56.3で `bank-v3-exposure`、V56.4で `bank-v4-routing` へ更新する。Primary / Secondary / Diagnostic quota、V56.3のPlan-wide exposure rotationを維持しつつ、V56.4ではsubtopic cooldownとparent-topic recent exposure capを上位ルーティングとして追加する。External AI selectorは `prompt-v41.4-calibrated` とし、Canoviaが決めたExam Profile / Question Mix / Routing PolicyをPromptへ渡す。

AIのnext_step.focus_topicsは補助情報として残すが、V56.4以降は既に観測済みの学習上の弱点を微調整するだけで、新しい弱点を単独生成できない。correct + error_type=noneのreasoning品質改善はrouting weaknessへ昇格させない。最終的な出題配分・cooldown・mastery・parent capはCanovia Policyが決める。


## 17. V41.5 Economy Foundation

### 2026-10-07 product-shape compatibility

V41.5は現在もProduct Grant / Entitlement / AI Capacityのruntime foundationとして有効だが、当時定義した `Premium Core + Purpose Pack` は将来の商品構成の正ではない。

現行Product-level targetは `docs/CANOVIA_MONETIZATION_SPEC.md` の Free / Premium / Pro + Development専用Dev Pro追加構造を優先する。

この互換注記だけではruntime ProductKey / grant / resolverを変更しない。移行時は専用実装仕様・migration・regression testを作る。


V41.5は課金処理を導入せず、将来のPremium Core + Purpose Packを既存Entitlement境界へ接続できる基盤を実装する。

Canonical Products:

- `premium_core`
- `study_pack`
- `career_pack`
- `developer_pack`
- `creator_pack`
- `all_access`
- `ai_capacity_boost`

Product Grantは `user_product_grants` にProvider非依存の投影として保存する。raw receiptや課金Provider固有payloadはここへ保存しない。

Purpose PackはPremium Coreを前提とし、All AccessはPremium Core + Purpose Packsへ展開する。AI Capacity Boostは別軸でありAll Accessへ自動包含しない。

V41.5で追加するCapability-level FeatureKey:

- `study_long_term_weakness_profile`
- `career_native_capture_analysis`
- `developer_github_evidence`

これらは将来Capability用であり、現在のAI Practice / Career manual capture / Project Artifact manual linkingをFreeから奪わない。

Admin Economy Inspectorでは、ユーザーごとのProduct Grant、effective Products、FeatureAccessDecision、AI Capacity、決定論的推薦を確認し、開発用Grantを手動付与/解除できる。

Public Pricing UIは、実際のPremium価値と購入経路が成立するまで追加しない。


## 18. V41.7 Admin / Premium Experience Foundation

V41.7はNative AIや決済を実装する前に、運営者・Free・Premiumの体験を安全に分離し、Premium価値を実ユーザーで検証できる状態を作る。

管理者判定はProduct GrantやFeature Entitlementと混ぜない。

```text
AdminAccessService
  -> 運営者か

FeatureAccessService
  -> 公開済みFeatureを使えるか

ProductGrantService
  -> Premium等の商品権利を持つか

AdminPreviewContext
  -> Super AdminがFree/Premium体験を確認中か
```

Super AdminはCanovia上で昇格できるロールにせず、server-side設定の `CANOVIA_SUPER_ADMIN_USER_ID` と一致する1アカウントだけを正とする。移行中のみ既存 `CANOVIA_ADMIN_EMAIL` をfallbackとして使い、IDが設定された後はemail一致をAdmin根拠にしない。管理パスワードやsession flagを本番の権限昇格には使わない。

`/admin/*` は `admin.access` middlewareでserver-side保護し、一般ユーザーはURLを知っていても403とする。Super Adminは通常Admin表示では全FeatureKeyへアクセスでき、AI Capacityも検証用最大状態とするが、Product Grant上でAll Access等を所有しているものとして記録しない。

既存の「表示」入口は「設定」へ拡張する。テーマ・アクセント・表示密度は「表示」セクションとして保持し、アカウント・利用プラン・運営導線を同じSettings Hubへまとめる。管理者メニューとAdmin PreviewはSuper Adminにだけ表示する。

身近なユーザーやテスターへは `premium_core` を `source=complimentary` のProduct Grantとして無償付与できる。日常の無償付与UIからAll Access、Purpose Pack、AI Capacity Boostは付与しない。付与期間は無期限 / 30日 / 90日 / 任意期限。解除時はGrant rowを削除せず期限切れにしてmetadataへ解除情報を残し、最低限の履歴を維持する。

Complimentary PremiumはPremium Feature Accessだけを与え、AdminAccessは与えない。

Admin Previewは権限を書き換えずsession-scopedな表示・Feature Access contextとして実装する。

- Admin: 全Feature + boosted AI Capacity
- Free: Free Entitlementとして評価
- Premium: Free + Premium Coreとして評価し、AI Capacityはstandard
- Preview中もAdminAccessは維持し、管理画面から戻れる

V41.7ではNative AI実行、StoreKit / Stripe、公開Pricing / Paywall、Pro / All Access購入、AI使用量meteringは実装しない。最初のNative AI Practiceは次段階でPremium Coreの実価値として接続する。


## 19. V41.8 Native AI Practice

V41.8はPremium Coreの `automatic_ai_execution` を最初の実Capabilityとして有効化する。AI Practice自体はFreeのままであり、料金境界は学習機能の有無ではなく「外部AIとの手動受け渡しをCanoviaが引き受けるか」に置く。

```text
Free
  Prompt generation
    -> External AI
    -> JSON import
    -> Answer
    -> External AI assessment
    -> JSON import

Premium Core
  Native AI generation
    -> Answer
    -> Native AI assessment
    -> Evidence / Next Action
```

Native AIを呼ぶ判断は必ず `FeatureAccessService` の `AutomaticAiExecution` を通す。Product Grant、Admin、Premium Previewの具体条件をStudy Practiceへ直接埋め込まない。AI Capacityは引き続き `AiCapacityService` の独立責務とし、standard / boostedをProvider実行時のpolicyへ反映する。

Providerは既存Study Practice abstractionへ `native_ai` を追加し、Question Bank / External AIと同じSession・回答UI・Assessment・Evidence loopを共有する。Question Bankで十分なCoverageがある場合は決定論的なQuestion Bankを優先し、Coverage不足時にPremiumのNative AIを主導線として提示する。

Native AIはStructured JSONを返すが、Provider出力をそのまま信頼しない。Canovia側でflow、Plan / Task ID、Question schema、Assessment schema、score、recommended progress等を再検証する。Provider障害、quota、timeout、refusal、contract mismatchが発生した場合もAI Practiceを停止せず、既存のExternal AI handoffへ戻す。

`native_ai_runs` はProvider / model / purpose / capacity tier / token usage / status / errorを記録する最小usage historyとする。Prompt本文やAPI credentialは保存しない。V41.8ではusage historyを請求へ接続せず、将来のquota・cost policyの観測データとしてのみ使う。

Native AI server configurationはdefault disabledとし、API credentialをclientへ公開しない。StoreKit / Stripe、公開Pricing、token課金、Plan/Career/DeveloperのNative AI化、完全Native SwiftUI UIはV41.8のNon-goalとする。


## 20. V44 Hierarchical Map Navigation

Canovia Mapは、現在のおすすめTaskだけを示すExecution Viewではなく、Canovia全体の情報・機能を空間的に辿るNavigation Layerとして扱う。

階層:

```text
L0 Intent Hub
  -> L1 Domain
    -> L2 Plan
      -> L3 Execution
        -> Classic Surface / Execution Tool
```

L0は固定構造とし、中央にSpace Station、周囲に計画 / 実行 / 振り返り / 共同を置く。
Space StationはPrimary RecommendationではなくCapture / Companion / Inbox routingの常設Hubである。

Navigation GraphとAttention Stateは分離する。
Navigation GraphはEntity / Contextの存在とsemantic relationを決め、Attention Stateはimportance / position / size / glow / edge emphasisを決める。
原則は「おすすめだからNodeが存在するのではない。おすすめだから目立つ。」とする。

V44.2以降、Space Stationはtext / URL / screenshot / PDFを既存InboxItemへCaptureし、Inbox Intelligenceがrouting candidateを生成できる。
AIはPlan / Task IDを選択せず、candidate生成だけではcanonical dataを変更しない。
Human Review後に既存InboxRoutingServiceへ確定する。

V44.3ではL1 / L2を実装する。
L1 Domainは新規DB Entityではなく既存Plan.categoryから決定的にProjectionし、L2は選択DomainのPlanを表示する。
Domain / Graph / absolute node positionは永続化しない。

Semantic ZoomはIntent -> Domain -> Plan -> Executionという意味上の親子関係に沿うLevel移動として扱う。
`plan=<id>` を伴ってL3へ入った場合、user-selected PlanをExecution scopeとして優先し、そのPlan内部で既存guidanceを利用する。
plan指定なしのL3はV43互換挙動を維持する。

Spatial Memoryとして、L0ではSpace Stationを中央Graph Node、L1〜L3では右下固定Spatial Dockとして表示する。
Spatial DockはGraph EntityではなくNavigation chromeであり、Domain / Plan / Execution Graphを変形させずCapture / Companionを開く。
Deep LevelからCapture等を行った後も、validated structural pathだけを使って同じMap Level / Intent / Domain / Planへ復帰する。

Map hierarchy transitionでsessionStorageへ保持してよいのはzoom direction / depth / timestamp等の構造情報だけとし、Plan title / Task title / user input / AI contentを保存しない。
Map Telemetryもwhitelistされたnode_type / position_role / action_role等の構造metadataだけを保存する。

V44.4では固定Intent構造を維持したままPersonalized Satelliteを追加する。Satelliteは新しいcanonical Entityではなく、既存Plan / ToolをL0へ昇格するAttention表現とする。
初期weightは importance 35% / usage frequency 25% / recency 20% / continuity 20%、promotion thresholdは0.28、最大4件とする。ranking / layoutにAIは使わず、既存BehaviorEvent / WorkSession / StudyPracticeAttemptからrequest時に決定的に算出する。
Plan Satelliteは未完了Taskを持つPlanを対象とし、Shared Planは共同Intentへanchorする。Tool Satelliteは、V44.4初期版では安全なtool-level usage signalが存在するAI Practiceのみを対象とし、過去30日2回以上の利用をpromotion candidate条件とする。
Satellite row、absolute position、score historyは永続化しない。Map Telemetryへ保存するのは satellite_plan / satellite_tool、satellite-1〜4、action_role=satellite等の構造metadataだけで、promotion score / signal値 / Plan名 / user contentは保存しない。
V44.5では共同IntentのL1 / L2をpurpose-based projectionへ切り替え、「自分が進める / レビュー待ち / 相手待ち / 外部Toolで確認」からShared Plan / Artifact / Taskを辿る。通常IntentのDomain / Plan hierarchyは変更しない。
レビュー待ち・相手待ちはPlanArtifact.metadataの明示stateだけを使用し、GitHub PR URL、担当者、Activityから状態を推測しない。stateはactive / review / waiting / external_followupを許可し、新規defaultは未設定、自動Mutationは行わない。旧clientがfieldを送らないupdateでは既存stateを保持する。
GitHub / Drive / OneDrive等は外部確認先として再投影する。URL pathからGitHub Pull Request / Issueというlink kindは識別できるが、open / merged / approved / CI等のlive状態はConnectorなしでは断定しない。External actionは別tabで開き、Canovia内部MutationではないためLiving Reevaluation pendingを作らない。
共同L2からL3へはcollab_contextをstructural pathとして保持し、BreadcrumbとSpace Station returnでも同じPurposeへ復帰する。Map Telemetryはcollaboration_hub / collaboration_context / collaboration_item / action_role=external_tool等の構造値だけを保存し、Artifact名・URL・state・member名・Plan名は保存しない。

V45.0ではMobile MapをDesktopの縮小版ではなく、同じNavigation Graph / Attention Stateを縦長Viewportへ再投影するMobile Spatial UXへ変更する。Server側のcanonical / attention座標は変更せず、client側の`buildMobileBaseLayout()`でL0をSpace Station中央・Intent内周・Satellite外周の2リングへ再配置し、SVG edgeも同じmobile座標へ同期する。
共同を含むL1 / L2はHierarchy parentを中央に保ち、childrenをMobile専用軌道へ再配置する。V45.4.1以降は縦横の%を固定せず、実Viewportのwidth / heightから同じpixel radiusになるよう補正して、縦長画面で軌道が縦方向へ引き伸ばされないようにする。Desktop layout、Semantic Zoom URL、L3 Execution semantics、Spatial Memoryは変更しない。
Mobile Canvasは`clamp(42rem, calc(100dvh - 8.5rem), 50rem)`を基本とし、短い端末では39remへcompact fallbackする。L0ではNode内部のDirect Open pillとsubtitleを隠し、Node Focus → Context Surface → canonical actionを主操作にして情報密度を下げる。mobile absolute coordinatesは永続化・Telemetry保存しない。

V45.1ではMobile Node選択をDesktop Focus Modeから分離し、Map全体の位置を維持したままselected highlightとContext Surface差し替えだけを行う。これによりContext Surfaceを開いたまま別Nodeを直接タップできる。Close / Expandはdelegated clickでも処理し、Closeはhistory同期を待たず即時にSurfaceとselected stateを解除する。
Mobile Mapはtransformable sceneとして1本指の全方向Panと2本指Pinch Zoomを提供する。Mapの向きは回転させずSpatial Memoryを維持する。Zoomは0.82x〜2.2x、scale>=1ではscaled overflow / 2 + viewport 10%、scale<1ではviewport 4%をPan上限とする。右上に縮小 / 中央リセット / 拡大Controlを常設し、Resetでpan=0 / zoom=1へ戻す。
Node / SVG edge / decorative orbitは同じMap Sceneとしてtransformし、axis label / Space Station Dock / gesture controlsはviewport chromeとして固定する。Pan / zoom値・pointer履歴はruntime onlyで、DB / Projection Key / Telemetryへ保存しない。

V45.2ではMap routeを通常Document内コンテンツではなくFullscreen Map Shellとして扱う。body[data-route-name="map.index"]をshell stateのsourceとし、Map中だけDesktop Header / Mobile Header / Mobile Bottom Navigation / Footerを非表示にする。App Shell DOM自体は残し、Instant NavigationでClassicへ戻った際に再生成なしで復帰できるようにする。
Map mainは100dvh固定・overflow hidden・page paddingなしとし、上部のFullscreen Top Barと残り全域のMap viewportだけで構成する。Top Barにはcurrent context、breadcrumb / depth、Classic / Map切替を置き、long description / Map help / hero shortcut群はFullscreen modeでは表示しない。HomeSurfacePreferenceやSemantic Zoom URLは変更しない。
Context SurfaceはDesktopでもMapを押し縮めるsidebarではなく右側floating overlayとし、MobileはBottom Sheetを維持する。Map modeではBottom Navigationが消えるためMobile Surface / Space Station Dockのbottom offsetはsafe-area基準へ下げる。Fullscreen stateやviewport sizeは永続化・Telemetry保存しない。

V45.3では既存node.type / state / position_roleをPresentation-onlyのMapNodeVisualGrammarでsilhouetteへ変換し、各Nodeを文字だけでなく形でも判別できるようにする。space_station=station、intent/domain=planet、plan=moon、goal=star、primary task=rocket、other task=beacon、tool=module、evidence=archive、inbox=inbox-dock、satellite_plan/tool=satellite、collaboration系=crew familyとする。
visual kindはBlade/CSSでのみ利用し、Navigation Graph / Attention State / Projection array / Projection Key / Telemetryへ追加しない。Glyphは軽量DOM + border / gradient / clip-path / pseudo-elementsで描画し、外部画像・常時animation・大きなfilter blurは追加しない。Node本体のhit targetと座標は維持し、L3の一部Nodeはborder-radius/backgroundだけでsilhouette perceptionを変える。

V45.4ではMap Runtimeのhot pathを最適化する。pointermoveはJS state更新に限定し、Map SceneのDOM transformはrequestAnimationFrameで1frame1回へbatchする。同一frame内では最後のMap stateだけをcommitし、transformはtranslate3d + scaleの単一style writeとする。
Gesture中のviewport geometryはpointerdown時にgetBoundingClientRectでcacheし、pointermove中はlayout readを行わない。resize / pageshow / double-tap開始時にのみcacheをrefreshする。Node base positionとSVG edge visibility/geometryは前回描画signatureとの差分がある場合だけDOMへ書き、Context Surface templateはmount時にMap化してO(1) lookupにする。
Gesture中だけis-map-gesture-activeを付与し、Sceneへwill-change: transform、Node shadow簡略化、transition停止、edge optimizeSpeedを適用する。pointerup/cancelでfinal frameをflushして通常Visual Grammarへ戻す。Sceneにはcontain: layout paintを適用する。viewport cache / render signature / RAF queue等はruntime-onlyでDB / sessionStorage / Projection Key / Telemetryへ保存しない。

V45.4.1 reviewではFocusとSpace Station Dockのruntime stateを排他的にし、Dockへ入る前にFocusをclearする。Dock history entryからstale canoviaMapFocus IDを除去する一方、Back/Forward判定に必要なcanoviaMapFocusDepthは維持する。
Mobile Node切替は初回normalize後、previous / next selected Nodeだけを更新し、同一Node再タップ時は既存Context Surfaceを再cloneしない。manual pointerdown時はpending Map frameをflushしてview animationを停止し、直接Gestureをcosmetic animationより優先する。
Gesture中はMap Pageにもis-map-gesture-activeを付与し、Fullscreen Top Bar / Gesture Controlsのbackdrop blurを一時停止する。pointerup/cancel/destroyで通常表現へ戻す。decorative Glyphの英語title tooltipと不要になったMapNodeVisualGrammar::label、delegated close移行後に残っていたunused closeButton queryも削除する。
Mobile Node比率もFullscreen + Pan/Zoom前提へ見直し、画面内へ全Nodeを押し込むための正方形寄せを解除する。Space Station / Intent Planetは意味上の円形を保つため1:1、Personalized Satelliteは人工衛星らしい横長、Hierarchy child / parentは読みやすい横長カードへ変更する。L0/L1/L2のMobile座標はbuildMobileBaseLayoutへViewport実寸を渡し、同一ringのx/y半径がrendered pixel上でほぼ等しくなるようaspect compensationする。Server / Desktop座標は変更しない。

V45.5ではV45.4.1以前の実機動画を基にMobile Mapのusabilityを補正する。L3 Execution MapにもMobile専用role slotを導入し、Goal=最上部、Plan/Next=上左右、Primary=中央、Inbox/Tool=左右、Evidence=下へ配置してPrimaryとTool/Inboxのhorizontal gapを390px幅で約152px確保する。Mobileではaxis labelを非表示にし、方向文法はNode placementで伝える。
Map上の情報密度を下げるため、Mobile Nodeのsubtitle / Context micro-copy / non-external direct-open pillを非表示にし、Node body -> Context Surface -> canonical actionを基本導線とする。Top Barはkickerとdepth trackを隠し、titleを1行ellipsis、Classic/Map switchを小型化する。Zoom controlは縦3段から小型横並び、Spatial Dockはlabelを隠したicon buttonへ縮小する。
Mobile Context Surfaceはdefault max 38dvh、expanded 76dvhとし、実際にはdragできないdecorative handleを隠す。Space Station CaptureではTextをprimary inputとし、URL / screenshot / PDFはdetailsへ折りたたむ。
Map -> Mapのuncached Semantic Zoomではglobal route-loading overlayを使わず、current Sceneを残したままopacity低下 + Top Bar下の細いloading indicatorを表示する。semantic zoom / Classic-Map switch linksはlegacy global route lockをskipし、Instant Navigationに任せる。新しいpersisted stateやTelemetry fieldは追加しない。

V45.6ではFullscreen Map routeをApp shellの通常paddingから完全分離し、body / app-main / map pageをedge-to-edgeに固定する。layouts/appのpx-4 / py-5やUI densityの!important paddingよりMap route overrideを優先し、app-mainはinset 0 / 100vw / 100dvh / margin 0 / padding 0 !importantとする。Safe AreaはCanvas外余白ではなくTop Bar / Dock / Context Surface側で扱う。
Personalized Satelliteは「頻用項目一覧」ではなく独立Contextへの航路短縮に限定する。Task-bound Tool（AI Practice等）はL0 candidateから除外し、Shared Planも固定の共同Intentと意味が重複するためL0 candidateから除外する。Plan promotion thresholdは0.55、最大2件、同一anchor Intentから最大1件とする。importanceだけでは表示せずusage / recency / continuityを含む強いsignalを要求する。Node eyebrowは内部用のPLAN SATELLITE表記ではなくPLANへ簡略化する。fixed L0 5 node / Navigation Graph / Telemetry contractは変更しない。

## V46 GitHub Workflow

V46.0ではGitHubのBranch / PR / Issue / Review / CI構造をそのままUIへ複製せず、既存PlanArtifactを「今やる / レビュー待ち / 修正必要 / マージ待ち / 完了」というCanovia側の作業判断へ再投影するGitHub Workflow Hubを追加する。

Canovia workflow stateはPlanArtifact.metadataのgithub_workflow_stateへ保存するが、GitHub remote stateではない。GitHub API / OAuth / WebhookがないV46.0ではopen / merged / approved / changes requested / CI結果等をURLや利用履歴から推測しない。state未設定の既存Artifactは未整理として表示し、人が明示分類する。

GitHub URL parserはgithub.com URLに埋め込まれたRepository / Pull Request number / Issue number / Commit / Actions run / Branch pathのみを構造情報として読める。Quick CaptureではGitHub URL、Plan、初期Canovia stateだけでArtifact登録できる。remote network requestは行わない。

lane変更は整理操作でありGitHub execution evidenceではないためTaskEvidenceを生成しない。将来authoritative GitHub connectorから取得したcommit / PR / review / merge / CI eventはEvidenceSource::GitHub側で別管理し、このWorkflow Hubへnormalizeする。

Manual Workflow HubはProjectArtifact能力の延長とし、OAuth / Webhook / automatic evidence / live status synchronization等の外部自動化はdeveloper_github_evidence capabilityへ接続できる境界を維持する。


## V46.1 Conversational Inbox

Inboxは「先に分類してから登録するフォーム」ではなく、曖昧な意図・URL・画像・PDFをそのままCanoviaへ渡すConversational Intakeとして扱う。
primary capture surfaceは1つのcomposerとし、Title / Category / Plan / Taskをcapture前に要求しない。URLはcomposerへ貼り付けたHTTP(S) URLをsource_urlとして抽出し、画像 / PDFはattachmentとして扱う。

Chat modeもcanonical captureは既存InboxItemを使い、`metadata.intake_mode=chat` / `capture_surface=inbox`だけを追加する。Automatic AI entitlementがありNative AIが利用可能ならcapture直後にrouting suggestionまで自動生成し、失敗してもcapture自体は成功させる。capture直後はYOU / CANOVIAのConversation previewを表示するが、preview専用のchat tableは作らない。複数ターン相談は既存Canovia CompanionへInbox Contextを引き継ぐ。

Inbox Intelligenceはrouting candidateだけを作り、AIはPlan / Task IDを選ばない。candidate生成だけではcanonical dataを変更せず、既存InboxRoutingServiceまたはCompanion Mutation Candidateのhuman review後に確定する。判断できない入力はkeep_inboxへ残してよい。

V47 Execution Orchestrationとはrequest contractがcanonical化された後に明示的Execution Requestとして接続する。Inbox側で担当分配・dependency解決・暫定execution payloadを先行実装しない。Inboxはintent intake / clarification、Orchestrationはconfirmed execution distributionを責務とする。

詳細は `docs/V46.1_CONVERSATIONAL_INBOX.md` を正とする。

## V46.2 GitHub Repository Overview

GitHub Repository URLは「今やる / レビュー待ち / 修正必要 / マージ待ち / 完了」の1作業として扱わず、GitHub Workflow Hub上のRepository Root / Contextとして表示する。Repository自体はWorkflow Laneから除外し、同じPlan + repo_full_nameを持つPR / Issue / Branch / Commit / ActionsをRepository Overviewへ集約する。

Repository Overviewでは、Canoviaが把握しているGitHub object数、Canovia workflow state別件数、Artifactへ明示linkされたTask、直近referenceをまとめて表示する。Repository ArtifactがなくてもPR等のURLからrepo_full_nameが判明していればOverviewを構築できる。

Quick CaptureでRepository URLを登録した場合はartifact_type=repositoryとし、github_workflow_stateは保存しない。既存Repository Artifactにstateが残っていてもmigrationは行わず、Projection側でLaneから除外する。PR / Issue / Branch等は従来どおりworkflow stateを持つ。

V46.2でもGitHub API / OAuth / Webhookは導入しない。OverviewはCanoviaへ登録済みURLだけから構成し、未登録Branch / PR / Issue、remote status、default branch、CI結果等をRepository URLだけから推測しない。将来authoritative GitHub connectorを接続する場合も、生のGitHub hierarchyを増やすのではなくこのOverviewへnormalizeする。

詳細は `docs/V46.2_GITHUB_REPOSITORY_OVERVIEW.md` を正とする。

## V46.3 GitHub Repository Inspection

Repository URLをCanoviaへ登録した場合、Repository Rootを先に保存したうえで、`developer_github_evidence` 利用権があるactorについてはGitHub RESTからread-only snapshotの取得を試行する。外部取得に失敗してもRepository capture自体は成功させる。

snapshotは既存PlanArtifactの `metadata.github_repository_snapshot` に保持し、repository metadata / default branch / language / bounded Branch一覧 / Open PR / Open Issue / recent Actions / fetched_at等をRepository Overviewへ投影する。remote objectを自動でTaskやPlanArtifactへ大量生成せず、Canoviaの「今やる / レビュー待ち / 修正必要 / マージ待ち / 完了」もGitHub remote stateから自動変更しない。

Repository Overviewから明示的な「GitHubから更新」を実行できる。refreshにはPlan edit permissionとDeveloperGithubEvidence entitlementを要求し、Task progress / Plan state / TaskEvidenceは変更しない。Repository Rootに旧github_workflow_stateが残っている場合はrefresh時に除去する。

公開Repositoryはtokenなしで取得可能とし、optionalなserver-side `GITHUB_READ_TOKEN` は主にrate limit緩和用途とする。V46.3ではper-user OAuth / GitHub App installation / private repository user access / webhook / background pollingを実装しない。

詳細は `docs/V46.3_GITHUB_REPOSITORY_INSPECTION.md` を正とする。

## V46.4 GitHub App Review-only Write Layer

V46.4ではGitHub writeをread-only inspectionから分離し、`developer_github_write` capabilityとして追加する。Repository管理者がCanovia GitHub Appを対象Repositoryへ一度installした後、Plan edit権限とDeveloperGithubWrite entitlementを持つCanovia userは、個人PATやGitHub CLIを共有せずにRepository Overviewからレビュー用変更を提出できる。

認証はserver-side GitHub App ID / Private Keyから短寿命JWTを作成し、Repository installationを確認して一時installation access tokenを発行する。token / Private KeyをDB・Artifact metadata・logへ保存しない。GitHub App permissionはMetadata read、Contents read/write、Pull requests read/writeを最小要件とする。

V46.4のwrite contractは1 request = 1 text file、最大200KB。CanoviaはGitHubからdefault branchを取得し、そこから必ず `canovia/*` branchを新規作成してfile create/updateを1 commit行い、そのbranchからdefault branch向けPull Requestを作る。default branch direct push、force push、merge、branch delete、file delete、Actions workflow writeは実装しない。`.github/workflows/**` は明示拒否する。

Pull Request作成成功後のみGitHub PRをPlanArtifactとして `review` laneへ追加し、`github_write_origin` にrequested Canovia user / GitHub App実行 / branch / commit / file pathを記録する。Repository Rootには `github_last_write` の要約を残す。write成功だけでTaskEvidence・Task progress・Plan progress・完了状態は変更しない。

V46.3のservice-owned read tokenはpublic inspection専用のまま。V46.4はRepository ownerが明示的にinstallしたGitHub App installation scopeを使うため、そのscopeに含まれるprivate Repositoryへのwriteは可能とする。個人account credentialの共有とは扱わない。

Execution Orchestration / AIからの自動writeはV46.4の対象外。将来はExecution result → GitHub change candidate → Human confirmation → GitHubRepositoryWriter → PRという境界で接続する。

詳細は `docs/V46.4_GITHUB_APP_WRITE_LAYER.md` を正とする。

## V46.5 GitHub App Connection UX

V46.5では、V46.4のGitHub App Write Layerを一般ユーザー向けの接続UXへ拡張する。App ID / Private Key / PATはCanovia運営側だけがserver-sideで管理し、ユーザーはRepository Overviewの「GitHubを接続」からGitHubへ遷移し、対象Account / Organization / Repositoryを選択する。

Canoviaはinstall URLへone-time stateを付与し、15分間だけsessionへArtifact / Plan / Canovia user / repo_full_nameを保持する。GitHub Setup URLへ戻る `installation_id` はそのまま信用せず、state・現在のPlan edit権限・DeveloperGithubWrite entitlement・対象Repositoryを再確認したうえで、GitHub App JWTから対象Repositoryの現在Installationをserver-side取得し、Installation IDを照合して初めてconnectedとする。

Repository Artifactの `metadata.github_app_connection` にはconnecting / pending / connected / permission_update_required / revoked / verification_failedの状態と、installation_id / account / repository_selection / permission / management URL等の非secret projectionだけを保持する。App Private Key / App JWT / installation token / PAT / OAuth tokenは保存しない。

Organization policyでRepository Adminが直接installできない場合はpendingとして扱い、「Owner承認後に接続状態を確認」できる。CanoviaはOrganizationのGitHub App policyを迂回しない。Owner承認後にcallbackが再発しなくても、明示的な接続状態確認からGitHubの現在Installationを再評価できる。

Review-only write formはUI上connected時だけ表示するが、metadataをauthorityとはしない。実際のwrite時はV46.4 GitHubRepositoryWriterがGitHubからInstallation / permissionを再確認する。GitHub APIがwrite authority sourceであり、Canovia metadataはUX projectionである。

詳細は `docs/V46.5_GITHUB_APP_CONNECTION.md` を正とする。

## V46.6 Execution Result → GitHub Review Handoff

V46.6ではV47 Execution OrchestrationとV46.4 GitHub App Write Layerを、Human-confirmed GitHub Change Candidateで接続する。Execution Packetは「何をどう進めるか」の指示であり、actual code / file resultではない。担当者またはAIが実際に完成させた1 fileの内容を、現在のExecution Packetに紐づくsession-scoped Candidateとして準備し、人が確認するまでBranch / Commit / Pull Requestを作らない。

Candidate作成には現在TaskのExecution Packetを必須とし、Packetのcontext_fingerprintが現在Contextと一致することを確認する。Candidateにはcontext fingerprintとExecution PacketのSHA-256 hashを保持し、confirm時にもcurrent Context / active Packet hashが一致しなければwriteを拒否する。反映先RepositoryはAIに選ばせず、同Plan内のconnected GitHub Repositoryを人が選択する。

GitHubRepositoryWriterにはread-only `previewFileChange` を追加し、default branch上のtarget file存在状態 / SHA / byte sizeを取得する。Candidate preview後のconfirmではexpected file SHAを再確認し、他担当が更新・作成・削除していた場合はBranch作成前に停止する。これによりHuman Confirmation中のstale overwriteを避ける。Candidateのsource contentはsession backendへ平文保存せずLaravel Cryptで個別暗号化し、PR Artifact / Activity metadataへ本文を複製しない。

確認成功後はV46.4と同じreview-only flowで `canovia/*` Branch → 1 file Commit → Pull Requestを作成する。成功したPRだけをPlanArtifactとして `review` へ追加し、元Taskへ明示linkする。origin metadataには `source=execution_github_handoff` / target_task_id / context_fingerprint / branch / commit等を保存する。

PR作成だけではTask progress / Task done / Plan progress / TaskEvidenceを変更しない。GitHub review / CI / merge等をauthoritative eventとしてEvidenceへ昇格させる契約は後段とする。GitHub App secret / tokenをCandidateへ保存せず、default branch direct push / merge / force push / delete / `.github/workflows/**` write禁止も維持する。

詳細は `docs/V46.6_EXECUTION_GITHUB_HANDOFF.md` を正とする。

## V46.7 GitHub Return / Evidence Bridge

V46.7ではV46.6で作成したPull RequestのReview / Merge / optional CI結果を、GitHub App installation tokenでauthoritativeに再取得し、元TaskへEvidenceとして戻す。Return対象TaskはAI推論ではなく、PR Artifactへ明示linkされた同一Plan内Taskだけとする。

Pull Request本体とsubmitted reviewはPull Requests permissionから取得する。CIは企業Organizationで追加permission承認が難しい場合を考慮してoptionalとし、GitHub App installationがActions read / Checks read / Commit statuses readを持つsourceだけ取得する。permissionがないCI sourceを無理に呼ばずwarningとして表示し、Review / Merge Return自体は継続する。

PR Artifactの `metadata.github_return_snapshot` にはremote PR state / merged state / review summary / bounded CI projection / fetched_at / warningsを保存する。source code本文やGitHub access tokenは保存しない。Canoviaの `github_workflow_state` は作業判断レイヤーなのでremote stateから自動変更しない。

TaskEvidenceはEvidenceSource::GitHubとしてidempotentに記録する。reviewはreview ID、mergeはrepo + PR number、CIはrepo + PR number + head SHAをstable external keyとし、同じReturn Syncを繰り返しても重複Evidenceを作らない。MergeやCI successを確認してもTask progress / Task status / Plan progress / Dependency completionは自動変更しない。

GitHub Evidenceが追加・更新されるとExecution canonical Contextが変わるため、sync前のExecution Packetはstaleになる。Return Layer自身は新Packetを自動生成せず、「EvidenceをPlan / Taskへ反映」「担当Contextを再評価」から人が次の判断へ進む。

詳細は `docs/V46.7_GITHUB_RETURN_EVIDENCE.md` を正とする。

## V46.8 GitHub Evidence → Task Decision

V46.8では、V46.7で取得したGitHub Review / Merge / CI EvidenceをTaskへ自動反映せず、deterministicなTask Decision Candidateへ変換する。Candidate生成にAIは使わず、current Task state・linked PR Artifact・latest github_return_snapshotだけを入力とする。

TaskがactiveでPR mergeを確認し、否定的signalがない場合は「完了候補」を表示できるが、status=done / progress=100 / remaining=0への変更はHuman Confirmation後だけ行う。Changes RequestedまたはCI failureでは「修正継続候補」とし、status=doing / next_action_noteだけを更新してprogress_percent / remaining_minutesは変更しない。Approved / CI success / CI pendingなどmerge前のsignalではTask完了へ進めず、必要に応じて次の確認Actionだけを更新する。

MergeとChanges Requested / CI failureが同時に存在する場合はmanual_reviewとし、Canoviaがsignalの優先順位を決めない。ユーザーがcomplete / continue / waitから明示選択する。Taskがdone / progress>=100またはcancelledの場合はReturn Layerから状態を上書きしない。

反映直前にはGitHubを再取得し、表示時のsnapshot_fingerprintとcurrent Taskのtask_fingerprintを再検証する。確認中にGitHub remote stateまたはTask stateが変わっていた場合、最新Evidenceは保存するがTask mutationは停止し、最新状態から再確認させる。remote API callはDB transaction外、Task mutationだけをrow lock付きtransactionで行う。

反映成功時は github_evidence_decision_applied をPlanActivityへ記録し、action / PR Artifact / Evidence IDs / before / afterをauditする。Task mutation後は既存context_fingerprintによりExecution Packet / Distributionがstaleになり、Canoviaは新しいPacketを自動生成しない。

詳細は `docs/V46.8_GITHUB_EVIDENCE_DECISION.md` を正とする。

## V46.9 GitHub Decision → Execution Coordination Bridge

V46.9では、Human-confirmed Task DecisionによってTaskが完了した後、その完了がDependency graphと既存Execution Packet / Distributionへ与える影響をProjectionとして可視化する。最初の入口はV46.8 GitHub Evidence Decisionだが、Coordination自体はGitHub専用ではなく、status=doneまたはprogress_percent>=100のTaskから再利用できる。

source Taskをdependencyに持つ同Plan内のdirect dependentを現在のcanonical Task / task_dependenciesから再評価し、全dependencyが完了したactive Taskをready、他の未完了dependencyが残るTaskをblockedとして表示する。source完了だけを理由に後続Taskをdoingへ変更せず、blocked Taskでは残っているblockerを明示する。

direct dependentに生成済みExecution Packet / External Promptがある場合は既存context_fingerprintとcurrent Contextを比較し、古ければstale individual instructionとして表示する。PlanにDistribution Bundleがある場合はV47.2のrefreshStalenessを再利用し、stale / dependency_stateだけをsession Projectionへ更新する。既存Packet / Prompt / actor label / generation modeは自動変更・削除・再生成しない。

CoordinationからDistributionへ渡すのはready direct dependent、またはstale targetのうちcurrent dependency_state=readyなTask IDのsuggestionだけで、最大8件とする。suggestionはGET queryの短命なpreselectionであり、DistributionControllerはcurrent Planのactive Taskへserver-sideでintersectする。他Plan / done / cancelled / 不正IDは無視する。人がDistribution画面でtarget / actor / available minutes / generation modeを確認してsubmitするまでPacket / Promptは生成しない。

Execution OrchestrationにはEXECUTION COORDINATIONを追加し、READY NEXT / STILL BLOCKED / INDIVIDUAL STALE / DISTRIBUTION STALEを表示する。個別Orchestrationまたは「次の担当候補を分配画面で確認」へ進めるが、後続Task開始、担当決定、Packet再生成、Dependency変更、Task作成は自動実行しない。完了・中止済みsource Taskでは新規Packet生成 / External Packet importもserver-sideで拒否し、過去の生成内容は履歴表示だけにする。

詳細は `docs/V46.9_EXECUTION_COORDINATION_BRIDGE.md` を正とする。

## V46.10 GitHub App Webhook Return Sync

V46.10では、V46.7で手動だったGitHub Return SyncをGitHub App Webhookからbackground起動できるようにする。Webhook payloadはremote stateのSource of Truthとして採用せず、「GitHub側で再確認すべき変化が起きた」というtriggerだけに利用する。実際のReview / Merge / CI stateは既存GitHub App installation tokenでGitHub REST APIから再取得し、V46.7 GitHubReturnEvidenceServiceへ渡す。

Webhook endpointはstateless API route `POST /api/integrations/github/webhook` とし、browser session / CSRF cookie / First Run UI middlewareを使わない。Canovia運営者がGitHub App側とserver側へ同じ `GITHUB_APP_WEBHOOK_SECRET` を設定し、raw bodyに対するX-Hub-Signature-256 HMAC-SHA256をconstant-time比較する。signature不一致は永続化・queue dispatch前に拒否する。

raw webhook JSON、PR body、review comment、source code、signatureは保存しない。delivery ID / event / action / repo_full_name / installation ID / PR number list / processing status等のminimal routing factだけを新規 `github_webhook_deliveries` へ保存し、delivery_id uniqueでidempotency / retry / auditを担保する。

署名確認後のGitHub API取得は `ProcessGitHubWebhookDelivery` queue jobへ渡す。対象PR Artifactはprovider=github、PR number、parsed repo_full_nameから限定し、same Planのconnected Repository Artifactに保存されたinstallation_idがWebhook installation_idと一致する場合だけ処理する。Task mappingはPR Artifactへ明示linkされたTaskだけで、AI推論は行わない。

background automationにはUI actorが存在しないため、DeveloperGithubEvidence entitlementはPlan ownerをbilling principalとして評価する。未許可の場合はremote APIを追加取得せずEvidenceを作らない。Webhook経由でCapability境界を迂回しない。

background syncが変更できるのはPR Artifactのgithub_return_snapshotとidempotent GitHub TaskEvidenceまで。Task status / progress / remaining / Dependency / github_workflow_state / Evidence Decision / Distribution / Packetは自動変更しない。Task完了等はV46.8のHuman Confirmationを維持する。

productionではdatabase queue workerを常時動かす必要がある。Canovia運営者はGitHub App Webhook URL / secret / event subscriptionとqueue workerを初回セットアップし、一般ユーザーは従来どおり対象RepositoryへCanovia GitHub Appをinstall / approveするだけとする。

詳細は `docs/V46.10_GITHUB_WEBHOOK_RETURN_SYNC.md` を正とする。

## V46.11 GitHub Integration Diagnostics

V46.11では、GitHub App / Webhook / Queue / Repository接続 / Return Evidenceの本番状態をCanovia運営者が1画面で確認できるAdmin-only Diagnosticsを追加する。新規routeは `GET /admin/github` で、既存 `admin.access` boundary配下に置く。

DiagnosticsはGitHub App設定済み判定、Webhook Secret設定済み判定、Queue driver、接続済みRepository数、GitHub webhook Job滞留、failed Job数、Webhook delivery状態、最近のprocessed deliveryをread-onlyで集計する。Private Key / Webhook Secret / installation token / raw webhook payload / failed job exception本文は表示しない。

Worker processの生存はDB情報だけから断定せず、直近30分以内のprocessed deliveryがあれば「最近処理を確認」、5分以上のaccepted / processing deliveryまたはGitHub Queue Jobがあれば「滞留あり」、設定はあるがrecent deliveryがなければ「稼働未確認」と表示する。稼働未確認はWebhook trafficがないだけでも発生し得るため異常とは扱わない。

Overall stateは setup_required / attention / unverified / healthy を使う。GitHub App / Webhook / async Queueが未設定ならsetup_required、failed / stuck signalがあればattention、接続Repositoryまたはrecent worker observationがなければunverified、必要設定と最近の処理観測が揃えばhealthyとする。これはCanovia側の観測状態であり、GitHub / Render全体のSLA保証ではない。

Admin画面には現在hostから生成したWebhook endpointとQueue Worker command、本番有効化Checklistも表示する。一般ユーザーはこのAdmin surfaceを使わず、従来どおり対象RepositoryへCanovia GitHub Appをinstall / approveするだけとする。DiagnosticsからQueue retry / Webhook redelivery / GitHub App設定変更 / Task変更は行わない。

詳細は `docs/V46.11_GITHUB_INTEGRATION_DIAGNOSTICS.md` を正とする。

## V47 Execution Orchestration

V47.0ではTaskを単独で推薦するだけでなく、Plan全体のDependency・Evidence・Goal Context・制約を保ったまま、選択Taskへ「今この主体が何をすべきか」を渡すExecution Orchestration Layerを追加する。

Execution PacketはTaskとは別の永続Entityにしない。TaskをSource of Truthとし、CoreContextからExecutionOrchestrationContextServiceがcanonical snapshotを都度構築し、ExecutionPacketServiceがその時点の実行文脈を生成する。Packetはsession表示だけに保持し、DBへ二重保存しない。

Dependencyは従来の単一depends_on_task_idからtask_dependencies pivotへ拡張する。旧columnはrolling deploy / legacy import互換のため最初のDependencyだけmirrorするが、canonical graphはpivotとする。自己依存、cross-plan dependency、cycleは禁止し、Recommendation / Dashboard Guidance / Roadmap / Study progressionは全Dependencyが完了した場合だけ通常の開始候補として扱う。

deterministicなdependency_stateはready / blockedのみとし、blockedをTask statusへ保存しない。AIはblocked状態でもconfirmed inputだけで安全に進められるprepare / coordinate等を提案できるが、未完了Dependencyの成果を存在すると仮定して本作業を開始してはいけない。Native / external AIがblocked状態でexecuteを返した場合、Canovia側でclarifyへfail-safeしActionを破棄する。

Execution Contextにはplan / selected node / dependencies / blockers / dependents / available Resource・Artifact / active Tasks / protected scope / confirmed constraints / known unknowns / recent Evidence・実績 / personalization signalを含める。Task説明やEvidence内の文章はContext dataとして扱い、AIへの上位命令として解釈しない。

Contextには時刻を含まないcontext_fingerprintを付ける。PromptまたはPacket生成後にTask / Dependency / Evidence等が変わった場合、既存Packetをstale表示し、古い外部AI JSONのimportは拒否して現在Contextから再生成させる。

Native AI direct generationは既存AutomaticAiExecution entitlementを利用し、Free pathでは従来どおり外部AIへPromptをコピーして返却JSONをCanoviaへ読み込める。新しい課金境界は作らない。

Map L3ではCurrent / Next Taskから「今やることを生成」へ遷移できる。PacketからPlan / Taskを直接変更せず、結果反映は既存Plan Review / Artifact / Evidence経路へ戻し、提案→確認→反映の境界を維持する。


## V47.1 Execution Request Handoff

V47.1ではV46.1 Conversational Inbox / Canovia Companionで受け取ったfuture actionを、Human Confirmation後にV47.0 Execution Orchestrationへ渡すcanonical Execution Request contractを追加する。

Execution Request専用の永続Entityは作らず、active handoffは対象TaskのExecution Orchestration sessionへ保持する。Human Confirmationのauditは既存sourceに残し、InboxではInboxItem.metadata.execution_request、Companionではapplied Candidateのapply auditから追跡できる。TaskをSource of Truthとする原則は変更しない。Requestは「ユーザーが今回何を進めたいか」、Execution Packetは「現在のPlan / Task / Dependency / Evidenceから今どう進めるか」であり、両者を分離する。

Inbox Intelligenceは `execution_request` をrouting candidateとして提案できるが、Plan / Task IDはAIに選択させない。人がdestination / Plan / Task / 今回してほしいこと / actor type / optional available minutesを確認した後にだけExecutionRequestHandoffServiceへ渡す。過去の作業結果はtask_evidence、これから行う依頼はexecution_requestとして区別する。

CompanionはTask Context内で `prepare_execution_request` Mutation Candidateを提案できる。Candidateを人がApplyするまでOrchestrationへは渡さず、ApplyしてもTask / Plan / progressは変更しない。確認済みCandidateはhandoff auditとしてappliedとなり、Execution Orchestration画面へ遷移する。

ExecutionPacketServiceはHuman-confirmed requestをPromptへ含めるが、RequestはDependency / protected scope / confirmed constraints / canonical targetを上書きできない。blocked Taskのexecute fail-safeも維持する。Packet / PromptのResetでは確認済みRequestを保持し、Context変化後はPacketだけを再生成できる。

詳細は `docs/V47.1_EXECUTION_REQUEST_HANDOFF.md` を正とする。


## V47.2 Execution Distribution Bundle

V47.2では、1つのPlan Contextを保ったまま複数の既存TaskへExecution Packet / External AI Promptを分配できるPlan-level Distribution Boardを追加する。HINANEXのA〜E担当のような複数担当運用を一般化するが、AIへTask IDを選ばせず、人が現在Plan内の未完了Taskを最大8件選択する。

Distribution Bundleは永続Entityにせずsession上のProjectionとして保持する。担当ラベル（例: A / Validation担当 / Claude）もsession-scopedであり、権限・ownership・Task担当者を意味しない。PlanActor modelは追加しない。Task / Dependency / Evidenceが引き続きSource of Truthである。

各targetは既存ExecutionOrchestrationContextServiceでPlan全体Contextを再構築し、taskごとのcontext_fingerprintを保持する。生成後にTask / Dependency / Evidence等が変われば、そのtargetだけをstale表示する。他担当のPacketを自動破棄しない。

External AI pathは担当ごとにContext込みPromptを生成する。Automatic AI entitlement + Native AI利用可能時は、選択Taskごとに既存ExecutionPacketServiceを実行してPacketを生成する。Native生成が個別に失敗した場合はBundle全体を失敗させず、そのtargetだけExternal Promptへfallbackする。

V47.1のHuman-confirmed Execution Requestが対象Task sessionに存在する場合、Distribution生成でもそのRequestを引き継ぐ。Distribution自体はTask / Plan / Dependency / progressを変更せず、新しいExecution Requestも自動生成しない。

詳細は `docs/V47.2_EXECUTION_DISTRIBUTION.md` を正とする。


## V47.3 Map Semantic Interaction

V47.3では、V43.5で導入した「Node本体 = Context / 小chip = Navigation」という操作をContainer Nodeに限って改める。L0 Intent、L1 Domain / Collaboration Purpose、L2 Plan / internal Collaboration Itemなど、`navigation_kind=zoom-in` を持つNodeはNode本体のクリックをSemantic Zoomとして扱い、クリックした意味Contextを次階層の中心として、より具体的なNode / Actionを周囲へ展開する。右下のNavigation chipは明示的affordanceとして残すが、次階層へ進むための必須Gateにはしない。

Execution Task / Evidence等のLeaf NodeはFocus-firstを維持し、Context Surfaceは階層Navigationの前提画面ではなくContext Inspectorとして詳細確認・補助操作を担う。`direct` / `satellite` / `external` の明示Navigationを持つNodeはNode本体から直接destinationへ進める。external Toolは従来どおり別tabで開き、外部状態や完了を推測しない。

Pan / Pinch後のghost click抑止は非interactiveなMap Scene backgroundだけへ限定し、Node・Map controls・Spatial Dock・form controlsは抑止時間中でも即時操作可能とする。Focus中に別Nodeへ切り替える場合は同一Focus history entryをreplaceし、一度全体表示へ戻す操作や不要なBack履歴増加を要求しない。

共同PurposeのL2 Projectionで該当Itemが0件の場合は、center Nodeだけのdead-endを作らず、「共同Planを確認」「Space Stationで整理」というProjection-onlyのNEXT OPTION Nodeを周囲へ出す。Space Stationは現在の共同L2 structural pathを維持したままSpatial Dockとして開き、これらのNodeはDB Entityやcanonical mutationを追加しない。

既存のNavigation Graph / Attention State分離、Living Reevaluation、Projection Key、Map -> Map Instant Navigation、semantic departure / arrival、Space Station Spatial Dock、L3 Execution semanticsは維持する。ReflectionのDomain -> Plan hierarchy自体はV47.3では変更せず、今回の対象はInteraction Contractの整理である。Map position永続化、Reflection専用Graph、AI layout、課金・billing変更は行わない。

詳細は `docs/V47.3_MAP_SEMANTIC_INTERACTION.md` を正とする。


## V47.4 Reflection Map

V47.4では振り返りIntentを通常のDomain -> Plan hierarchyから切り離し、過去のcanonical factを「何を見返したいか」というLensから辿る専用Projectionへ変更する。

L1は固定のReflection Lensとして「最近の実績 / Evidence / 完了Task / 振り返り記録」を表示し、L2はそのLensに該当する既存Recordを表示する。最近の実績はWorkLogとTaskEvidenceを時系列で統合し、EvidenceはTaskEvidence、完了Taskはstatus=doneまたはprogress_percent>=100、振り返り記録はguided_execution_reflected / interview_review_completedの明示Evidenceだけを対象とする。deadlineや時間経過、通常Evidenceから完了・Reflectionを推測しない。

Reflection Map専用のDB Entityやhistory rowは作らない。Mapは既存WorkLog / TaskEvidence / TaskのProjectionであり、Source of Truthではない。L1 LensはV47.3 Semantic InteractionのContainer Nodeとして本体クリックでL2へSemantic Zoomし、L2 Recordは人工的なMap depthを増やさずPlan / Timeline等の既存canonical surfaceへ直接戻る。

対象Recordが0件の場合も中央1Nodeのdead-endにはせず、Projection-onlyの「Timelineを確認」「達成した計画を見る」を周囲へ出す。

Reflection hierarchyのdepth labelは Intent -> Lens -> Record -> Detail とする。V47.4のMap自体はL2までを利用し、Detailは既存surface側の責務とする。Space Station DockからCaptureした場合はreflection_contextをvalidation済みstructural pathとして保持し、同じLensへ復帰する。

既存のL0 fixed Intent、Navigation Graph / Attention State分離、Living Reevaluation / Projection Key、Map -> Map Instant Navigation、Space Station Dock、L3 Execution Map、Collaboration専用Projection、Timeline / Achievement画面は維持する。AIによる過去の生成・要約、Map position永続化、billing / entitlement変更は行わない。

詳細は `docs/V47.4_REFLECTION_MAP.md` を正とする。


## V47.5 Semantic Zoom Spatial Continuity

V47.5ではV47.3のContainer Node本体Semantic Zoomを、単なるMap route切替ではなく「選択したNodeの内側へ潜る」空間操作として知覚できるようにする。Navigation Graph / canonical stateは変更せず、Projection間の短命なvisual continuityだけを追加する。

zoom-in時はクリックしたNodeのviewport geometryを取得し、次Projectionに同じNode IDが存在する場合はそのNode、存在しない場合はcenter Nodeをarrival anchorとする。これによりL2 Plan -> L3でPlanがCurrent Taskへ変身して見えることを避け、同じ `plan:<id>` がL3に存在する場合はPlan identityを維持したままTask / Tool / Evidenceを新Projectionとして展開する。

zoom-out時は現在Projectionのcenter Nodeをdeparture sourceとし、遷移先で「遷移前のstructural routeへzoom-inするNode」を検索してreverse anchorとする。解決できない場合は既存shell-level animationへfallbackする。

既存 `canovia.map.semantic-transition.v1` session payloadには direction / depth / timestamp に加えて、短命な `from_route` / `source_node_ref` / viewport geometryのみ保持できる。Plan・Task title、description、Evidence summary、user input、AI output等の内容は保存しない。payloadはarrival時に即削除し、geometryはDB / cookie / BehaviorEvent / Projection Keyへ保存しない。

arrivalではanchorがsource geometryからcanonical positionへ移動し、その後周囲のNode / Edgeが展開する。最終位置は常に既存Map layoutでありanimation overrideは残さない。scaleは0.52..1.9、translationは±2400pxへclampし、sessionStorage改変を含む極端なgeometryでも画面を飛ばさない。`prefers-reduced-motion: reduce` ではnode-to-node animationを無効化し、canonical geometryを維持する。

V47.3のgesture直後click、Focus切替、Context Inspector、V47.4 Reflection Map、Collaboration Projection、L3 Execution semantics、Living Reevaluation、Instant Navigationは維持する。Navigationをanimation完了待ちで遅延させず、AI layoutやcanonical position永続化、billing / entitlement変更は行わない。

詳細は `docs/V47.5_SEMANTIC_ZOOM_SPATIAL_CONTINUITY.md` を正とする。


## V47.6 Context-Centered Execution Map

V47.6では、V47.5実機確認で残った「PlanをSemantic ZoomしたのにL3でCurrent Taskが中央を奪う」問題を解消する。Execution Mapでは `center_node_id` と `primary_node_id` を分離し、Planが解決できるL3では `center_node_id = plan:<id>`、Current Taskがある場合のみ `primary_node_id = task:<id>` とする。中央は「現在いるContext」、Primaryは「今おすすめするAction」を表し、「中央 = Primary Action」という旧契約は廃止する。

L3の通常ProjectionはPlanを中央に固定し、Current TaskをAction側の強調Node、Next Task / GoalをFuture、ToolをAction、EvidenceをPast、Inbox / DependencyをInput側へ配置する。新しいstructural position roleとして `context-plan` / `action-primary` を追加し、BehaviorEventでは本文を追加せずroleだけをallowlist対象とする。既存roleは互換性のため削除しない。

Current Task / Next Task / Dependency TaskはLeaf NodeとしてFocus-firstを維持する。Taskを押した場合は既存Focus ModeによりTaskが中央となり、Planはcurrent-context neighborへ移動する。Focus解除またはBackでPlan-centered L3へ戻る。新しいL4 routeやDB Entityは作らない。

V47.5のSpatial ContinuityではL2とL3の同一 `plan:<id>` をidentity anchorとして利用できるため、Plan NodeがそのままL3 centerへ移動し、Task / Tool / Evidenceが周囲に展開する。Primary Actionの推薦ロジック、direct launch、Execution Orchestration、Living Reevaluation、Collaboration L3、Reflection Map、Space Station Dockは維持する。

Desktop / MobileともPlan Contextをcenterとする。Mobileは既存pixel-balanced layoutでPlan=50/50、Primary Action=右上、Next Task=上、Goal=左上、Tool=右、Evidence=下、Inbox=左へ再配置する。通常L3ではPlanをcyan context emphasis、Primary Taskをviolet primary-action emphasisとして「場所」と「おすすめ」を視覚的にも分離し、Focus中はPlan center用emphasisを外す。

詳細は `docs/V47.6_CONTEXT_CENTERED_EXECUTION_MAP.md` を正とする。


## V47.7 Map Presentation Foundation

V47.7では、Canovia Mapを将来のPrimary UIへ昇格させるため、Canvasと詳細表示の責務を分離する。Map Canvasは「どこに何の役割があるか」を伝え、具体的なTask名・Artifact名・summary・progress・meta・actionはDetail Paletteで確認する。

`MapNodePresentation` をview-only grammarとして追加し、Plan / Domain / Intent / Collaboration Context等のContainerは固有名を表示する一方、Task / Evidence / Tool / Inbox / Collaboration Item等のLeafは「おすすめ」「次にやる」「前提」「Evidence」「レビュー待ち」「相手待ち」「外部確認」等の役割名を表示する。Navigation Graphが保持するcanonical label / eyebrow / subtitleは変更せず、Palette sourceと内部契約にはそのまま残す。

Leaf Node本体はPalette-firstとし、direct destinationが存在しても即遷移ではなく詳細を先に開く。ContainerのSemantic Zoom body entry、Personalized Satellite、small direct-open controlは維持する。ユーザー向けの `Context Inspector` 表記は「詳細」に変更し、内部data attribute / JS contractは互換性のため維持する。

Collaboration L2で該当Artifact / Taskが0件の場合、genericな「共同Planを確認」NodeからClassic一覧へ抜けず、既存Shared Planを最大6件そのままMapへ投影する。各Shared Planは `plan:<id>` identityを使ってcollaboration L3へSemantic Zoomするため、V47.5/V47.6のspatial continuityを継続できる。Shared Plan自体が0件の場合のみPlan作成を次の選択肢として出し、Space Stationは既存Spatial Dockを利用する。

Classic routeは削除しないが、Map上では「Classic Plans」ではなく「一覧で見る」として補助Viewへ降格する。長期的な完成条件は主要操作を `Map -> Detail Palette -> Action` で完結させることであり、ClassicはData/List Viewとして残す。

V47.7のPresentation Grammarは、今後のMap Data Layers、スマホのホーム画面のような複数Map Page / Preset、RoadmapのSpatial Projectionに共通利用する。Roadmap Mapでは並行Task・dependency edge・Task group・milestone・blocked / ready stateを空間的に表現する想定だが、V47.7ではRoadmap本体、Layer設定永続化、Map Page永続化、drag & drop position保存は実装しない。

詳細は `docs/V47.7_MAP_PRESENTATION_FOUNDATION.md` を正とする。


## Map Node表示・スマホUIの改善

Leaf Nodeは原則として具体名と短い役割を表示し、詳細で状態・表示理由・既存の操作を確認する。ただしPrimary TaskはV47.7の「おすすめ」role-only表示を維持し、具体Task名はDetail Paletteで確認する。スマホでは実寸に基づく端切れ・重なり補正と44pxの詳細操作領域を用いる。階層Navigation・Breadcrumb・semantic zoom・canonical graphは変更しない。仕様と並行実装境界は [MAP_NODE_MOBILE_PRESENTATION.md](MAP_NODE_MOBILE_PRESENTATION.md) を参照。


## V48.0 Roadmap Spatial Map

RoadmapのPrimary Map表示を、従来の一本道型orbitからDependency Spatial Mapへ移行する。canonical dataは既存Plan / Task / task_dependencies / RoadmapServiceを維持し、新しいPhase/Cluster DB entityは作らない。

V48.0の表示上のPhaseはDependency深度、Task Clusterは同一Phase内で同じ直接前提集合を共有するTask群とする。横方向にDependency depth、縦方向にCluster/parallel Taskを配置し、Dependency edgeとlineage edgeを分けて表示する。複数Taskを含むClusterは「並行 N件」として見せるが、同時実行を強制・推奨する意味は持たない。

Dependency readinessはExecution Coordinationと同じ完了判定（status=done または progress_percent>=100）のみを使う。未完了Dependencyが残るActive Taskはblockedとして表示し、Roadmapからの直接Work Session開始は出さず、Execution Orchestrationへ判断を委ねる。Roadmap自身はTask status/progressを変更しない。

`/roadmap` ではSpatial MapをPrimary、既存ListをSecondaryとする。shared Roadmap partialは `roadmapSpatial` 未提供時に従来Mapへfallbackし、Plan Review preview等の既存利用を維持する。

RoadmapからPlan-centered Map L3へ戻る導線、Map L3のPlan詳細から「ロードマップMapを見る」導線を持ち、RoadmapをCanovia Map Navigation Layerの1 Surfaceとして扱う。

詳細は `docs/V48.0_ROADMAP_SPATIAL_MAP.md` を正とする。


## V48.3 Space Station Intake Hub

Space StationをCanoviaの共通入力Surfaceとして、`Input → Interpret → Connect → Action` の4段階Flowへ完成させる。

Space Stationからのtext / URL / screenshot / PDFは既存Inboxへchat intakeとして保存し、Native AIが利用可能な場合はInbox IntelligenceによるInterpretationまで自動で進める。ただしAIはdestination / reason / confidence / Plan・Task名hintのみを返し、Plan ID / Task IDを選ばない。

L1-L3のMap ContextにPlanが存在する場合は現在Planを接続候補として初期表示できるが、capture時点でInboxItem.plan_idを変更せず、Human Confirmまではcanonical connectionを作らない。

接続確定は既存InboxRoutingServiceを使う。execution_request選択時はinstruction / actor type / available minutesをSpace Station内で確認し、ExecutionRequestHandoffServiceへ渡す。

確定後にSpace Stationへ戻るFlowでは、flash-only resultとして接続先と次Actionを表示する。Task Evidenceなら関連Task Map、Plan ResourceならResource、RecallならRecall等へ進める。

Space Stationはroutingを強制せず、整理せずCompanionへ相談する導線も維持する。

Human agency境界:
- 自動可: capture / interpretation / candidate / current-context candidate
- Human Confirm必須: destination / Plan / Task / canonical mutation / Execution Request
- Task completion / progress変更は行わない

詳細は `docs/V48.3_SPACE_STATION_INTAKE_HUB.md` を正とする。


## V48.4 Map Pages / Presets

Canovia Mapに複数の用途別Pageを導入する。Pageはcanonical Plan / Task / Evidenceを複製せず、Map structural route・Page名・Data Layer state・並び順だけを保持するpresentation/navigation presetとする。

標準Pageは「全体 / 計画 / 実行 / 振り返り / 共同」。さらにユーザーは現在のMapを最大8件までカスタムPageとして端末内へ保存でき、開く・並び替え・削除が可能。

Custom Page保存時は現在のData Layer stateもsnapshotする。同一Map Contextでも「作業用」「全体確認用」のように異なるOverlay構成を持てる。Page削除はcanonical dataへ影響しない。

V48.4ではlocalStorageへ保存し、account sync / shared Page / drag & drop / AI automatic page generationは行わない。

またV48.1 Global Home resetを強化する。遷移元でのclearだけでなく、短命reset requestをsessionStorageへ残し、L0 mount時に再度Focus / Dock / hash / view transformをclearしてinitial focus restoreをskipする。これによりInstant Navigationやcached DOM replacement後も「全体へ」で選択中Nodeが残らない。

詳細は `docs/V48.4_MAP_PAGES_PRESETS.md` を正とする。

## V48.5 Explainable Spatial Personalization

Personalized Shortcutを単なるrank順Satelliteから、既存Behavior / Priority / Recency / Continuity signalに基づく説明可能なSpatial Attentionへ進める。L0固定Intentは変更せず、V45.6のthreshold 0.55 / max 2 / same anchor max 1を維持する。

Shortcutのposition roleはpromotion rankではなくsemantic anchorへ固定し、Plan=上、Execution=右、Reflection=下、Collaboration=左とする。同じContextはrankが変わっても意味方向を維持する。promotion scoreはabsolute座標保存には使わず、anchor slot内でのcenter distanceを小さく変えるだけとし、strong signalほど少しcenter側へ寄せる。Mobileはこのradiusをpixel-balanced layoutへ変換し、既存mobile box fittingを最後に適用する。

各Shortcutにはpresentation-onlyのpersonalization metadataを付加し、既存4軸signalのweighted contribution上位最大2件から「優先度が高い / 最近よく使っている / 最近開いている / 継続して進めている」等の理由を決定的に表示する。AI reasoningは生成せず、Canvas本文へsignalを詰め込まずDetail Paletteの「ここにある理由」で確認する。

ユーザーはShortcutを「この端末では非表示」にできる。設定はversioned localStorage `canovia.map.personalization.v1` へstructural Node IDだけを保存し、Plan / Task / priority / promotion scoreを変更しない。L0では非表示件数とresetだけをcompact表示する。manual pinはserver promotionとの契約が未定義なためV48.5では実装しない。

Personalization state、promotion score、signal値、理由labelはBehavior telemetryへ追加しない。Instant Navigation cacheではruntime mount guardを除去し、cached Map復帰後にもdevice-local preferenceを再適用する。DB migrationは行わない。

詳細は `docs/V48.5_SPATIAL_PERSONALIZATION.md` を正とする。

## V48.6 Pinned Spatial Shortcuts

V48.5のExplainable Spatial Personalizationへ、ユーザー自身がPersonal Plan Shortcutを明示的に固定できるaccount-level preferenceを追加する。PinはPlan / Task / priority等のcanonical dataを変更せず、eligible candidateに対するpromotion threshold overrideとしてのみ働く。

Preferenceは `users.map_personalization_preferences` のversioned JSONへstructural Node IDだけを保存する。現在のPlan Shortcutは全て `intent:plan` anchorなので、別PlanをPinした場合は既存Plan Pinを置き換える。V45.6のmax 2 / same anchor max 1 / automatic threshold 0.55は維持する。

Projectionではeligible + pinnedをautomatic promotionより先に評価する。Pin後にPlanが未完了Taskを失った場合はpreferenceが残っていてもShortcutを復活させない。つまりPinはcandidate existenceを捏造しない。

Pinned Shortcutは `personalization.mode=manual_pin` / `strength=pinned` とし、「固定しているため表示」と説明する。behavioral signal理由やraw scoreはPin理由として表示しない。

Pinはserver-side account preference、V48.5のHideはdevice-local localStorage preferenceとする。同じNodeへ両方が適用された場合はdevice-local Hideを最終表示overrideとして優先し、「アカウントでは固定しているが、この端末では隠す」を許可する。HideはPinを解除しない。

Detail Paletteから「このShortcutを固定 / 固定を解除 / この端末では非表示」を操作できる。absolute position保存、drag & drop、Shared Plan重複表示、Task-bound ToolのL0昇格、AIによるPin判断は行わない。

詳細は `docs/V48.6_PINNED_SPATIAL_SHORTCUTS.md` を正とする。

## V48.7 Contextual Shortcut Pinning

V48.6のserver-side Pin contractをcanonical Plan Contextから操作可能にする。これまでPin操作は既にL0へpromotionされたPersonalized Shortcutからしか到達できず、signalが弱くShortcutが存在しないPlanをUIから固定できなかった。

`MapShortcutPinProjectionService` はlevel-specific Map Projection後のtype=plan Nodeへpresentation-onlyの `shortcut_pin` metadataを付加する。対象はuser自身のPersonal Planだけで、Shared Planには付与しない。

状態は `available / pinned / inactive` の3つとする。未完了Taskを持つPersonal PlanはL2/L3のDetail Paletteから「このPlanを全体へ固定」できる。Pin済みなら同じContextから解除できる。Pin後に全Taskが完了・cancelledとなった場合はV48.6どおりL0 Shortcutを復活させず、Plan Contextではinactiveとして「固定設定は残っているが現在は全体Mapの表示対象外」と示し、人が解除できる。

Pin / Unpin後はL0へ戻してProjection結果を即確認する。Plan Context上のshortcut pin stateはProjection keyにも含め、Living Map / Instant NavigationでstaleなPin表示を残さない。

新しいcandidate種、Task-bound ToolのL0昇格、Shared Plan Shortcut、AIによるPin判断、absolute position保存、canonical Plan / Task mutationは行わない。

詳細は `docs/V48.7_CONTEXTUAL_SHORTCUT_PINNING.md` を正とする。

## V48.8 Reflection Candidate Expansion

Personalized Shortcutのcandidate sourceをPersonal Planだけから最小拡張し、既存Reflection Lens「最近の実績」をautomatic candidateとして追加する。L0を埋める目的ではなく、canonical WorkLog / TaskEvidenceが直近14日で最低2件存在し、既存4軸signalを0.55 thresholdで評価した結果が十分強い場合だけpromotionする。

Recent Reflection Shortcutは `satellite:reflection:recent` / `satellite_reflection` とし、`intent:reflection` へanchorする。destinationは `/map?level=l2&intent=reflection&reflection_context=recent`。V48.5のsemantic placementにより `satellite-3` / 下方向へ安定配置される。

4軸weightは importance 0.35 / usage_frequency 0.25 / recency 0.20 / continuity 0.20 のまま変更しない。Reflection candidateではrecent recordに含まれるPlan priority、record数、最新record時刻、distinct active day数を各軸へ決定的に正規化する。Candidate固有のsignal意味に合わせ、「重要なPlanの実績がある / 実績がまとまっている / 最近実績が増えた / 継続して積み上がっている」という説明labelを使用する。Plan Shortcutの既存説明は変更しない。

V45.6のmax 2 / same semantic anchor max 1を維持するため、強いPersonal Plan ShortcutとRecent Reflection Shortcutは最大2件で共存できる。同じReflection anchorへ将来候補が増えてもL0には最上位1件だけを出す。

Telemetryにはstructural node_type `satellite_reflection` を追加するが、promotion score / record count / Plan title / Reflection label / Evidence summary等は保存しない。candidate row、score history、absolute positionの永続化は行わず、request時にcanonical recordから再計算する。

Task-bound Tool、generic Tool usage inference、Shared Plan Shortcut、Collaboration Purpose Shortcut、AI ranking/layout、automatic PinはV48.8では追加しない。

詳細は `docs/V48.8_REFLECTION_CANDIDATE_EXPANSION.md` を正とする。

## V48.9 Collaboration Review Candidate

Personalized Shortcutのcandidate sourceをCollaboration方向へ最小拡張し、既存Purpose「レビュー待ち」をautomatic candidateとして追加する。存在条件には `PlanArtifact.metadata.collaboration_state = review` という人が明示したstateだけを使い、GitHub PR URL・assigned user・activity・provider等からreview状態を推測しない。

candidateは `satellite:collaboration:review` / `satellite_collaboration` とし、`intent:collaboration` へanchorする。destinationは `/map?level=l2&intent=collaboration&collab_context=review`。V48.5 semantic placementにより `satellite-4` / 左方向へ配置する。

4軸weightと0.55 thresholdは変更しない。importanceはreview artifactを持つShared Planのeffective priority、usage_frequencyはreview item数、recencyは最新artifact updated_at、continuityは人が明示的に解除するまで継続するreview queueをbase 0.55として複数item / Planで補強する。説明labelは「重要な共同Planにレビューがある / レビュー項目がまとまっている / 最近レビュー待ちになった / レビュー待ちが継続中」を用いる。

V45.6のmax 2 / same semantic anchor max 1を維持するため、review itemが存在してもsignal不足ならL0へ出さない。Collaboration Shortcutのmanual PinはV48.9では追加せず、Personal Plan Pin契約を維持する。

Telemetryへstructural node type `satellite_collaboration` を追加するが、review count / artifact title / external URL / collaboration state / Plan title / promotion score / user contentは保存しない。新規migration・candidate永続化・absolute position保存は行わない。

Waiting / External / My Action Shortcut、GitHub remote status inference、connector polling、automatic collaboration_state mutation、AI ranking/layoutはNon-goal。

詳細は `docs/V48.9_COLLABORATION_REVIEW_CANDIDATE.md` を正とする。
## V49.0 Continuous Semantic Zoom / Collaboration Project Workspace

Map hierarchyをclickによる画面切替ではなく、camera scaleで情報粒度が変わる地図型interactionへ拡張する。URL / L0〜L3 Projectionは維持し、巨大な単一DOMにはしない。desktop trackpadのtwo-finger scrollでpan、Ctrl+wheelとして届くpinchでpointer中心zoom、mobileではPointer Events pinchを使用する。camera scaleは0.68〜2.20、semantic thresholdは1.62以上でzoom-in、0.72以下でzoom-outとする。

Container click / tapも同じSemantic Zoomとして扱う。V47.5 geometry continuityを維持しつつ、cached projectionでもselected Nodeがcenterへ移動する動きを知覚できるよう、Reduced Motion以外ではdestinationをprefetchして最大120msだけdepartureを見せてProjectionを交換する。camera pan / scaleはruntime-onlyで永続化しない。

CollaborationはPurpose-firstからProject-firstへ変更する。L0「共同」からL1で閲覧可能なShared Planを直接表示し、Shared Planが1件でもselection levelを維持する。L1には常に「＋ 共同計画を作る」を置き、既存Plan createへ`collaborative=1` presetで遷移する。

L2はselected Shared Planをsemantic centerとして残すProject Workspaceとする。Task / Artifact等を無理にMap Node化せず、Classic Collaborationの「制作ファイル / 成果物」「参加メンバー」「最新情報」の3カードをshared Blade partialへ切り出してMap上のPaletteとして再利用する。Desktopでは成果物を大きく右へメンバー/最新情報、Mobileでは1列scrollへ落とし、Map Sceneは低opacityで背後に残す。

V48.9 Review Waiting Shortcutはexplicit`collaboration_state=review` truthを維持し、L2 Purposeではなくreview itemを持つProjectだけに絞ったL1 Shared Project selectionへ入る。GitHub URL等からreview状態を推測しない。

新規DB / canonical model mutation / camera persistence / AI layout / Palette drag & dropは追加しない。詳細は`docs/V49.0_CONTINUOUS_SEMANTIC_ZOOM_COLLABORATION_WORKSPACE.md`を正とする。

## V49.1 Semantic Zoom Recovery / Plan-first Workspace

V49.0実機確認で、物理縮小できても親Projectionへ戻れないケースを修正した。各Map pageへ `graph.hierarchy.parent_url` を `data-map-parent-url` として出し、zoom-out destinationはDOM上の戻るlinkよりserver-authoritative parent URLを優先する。既定semantic thresholdはzoom-in 1.42 / zoom-out 0.82へ調整し、少ないgesture量で粒度を切り替えられるようにした。

DesktopではPlan / Collaboration Workspace Palette上のprecision pinchもMap Shellで受ける。一方ordinary wheelはPalette上ではcard scroll、Map Scene上ではcamera panとして分離する。

Plan Intentは `L0 計画 -> L1 Plan -> L2 Plan Dashboard` とする。Plan.categoryはmetadataとして残すが、通常のPlan閲覧で独立semantic levelにはしない。V49.7以降Execution Intentも `L0 実行 -> L1 Plan -> Execution` とし、Domain groupingを通常Navigationから外す。

L2 Plan Dashboardはselected Planだけをspatial centerとして残し、Classic Planの進捗summary metricsと既存Roadmap partialをDocument Canvas上で再利用する。進捗summaryはshared Blade partialを利用し、Classic / Mapで同じ表示contractを維持する。Map専用の類似data modelは作らない。

V49.1時点の経緯は `docs/V49.1_SEMANTIC_ZOOM_RECOVERY_PLAN_WORKSPACE.md`、現在の詳細契約は `docs/V49.7_DASHBOARD_MAP_DOCUMENT_CANVAS.md` を正とする。

## V49.2 In-place Semantic Expansion

Single-Surface Architectureへ移行する前のUX bridgeとして、Semantic Zoomの見え方を「中央へ移動してProjectionを交換」から「選択した箱をその場で開く」へ変更する。

zoom-in時にselected Nodeをviewport centerへtranslateしない。selected Nodeの現在world positionを保持し、次Projectionのcenterをその位置へ合わせた上で、childの相対配置半径をdesktop 0.62 / mobile 0.72へ圧縮する。これにより次levelの要素はselected boxの内部／周辺から展開する。

zoom-in前の兄弟Node / Edgeはcurrent window memory上だけでDOM cloneし、次Projectionのambient spatial contextとして残す。ambient layerはpointer-eventsなし、current graph / focus / telemetryの対象外とする。raw title / contentをsessionStorageへserializationしない。

Semantic TransitionのsessionStorage payloadへ追加するのはsource x/yとcamera x/y/scale等の数値・structural stateのみ。camera transformを次Projectionへ引き継ぎ、不要なrecenterを行わない。fallback full reloadではambient cloneは失われるが、source positionとcameraによるin-place配置は復元できる。

child Nodeはselected box中心からfinal positionへ展開するanimationを使用する。zoom-outはV49.1のauthoritative parent URL契約を維持し、親Projectionへ戻るとcanonical sibling配置へ復帰する。

URL hierarchy removal、全level同時DOM、ambient siblingの直接branch switching、absolute position persistence、AI layoutはV49.2のNon-goal。詳細は `docs/V49.2_IN_PLACE_SEMANTIC_EXPANSION.md` を正とする。

## V49.3 Desktop Pinch Isolation / Expansion Spacing

Desktop precision touchpad pinchとbrowser page zoomの入力競合を解消する。Map page mount中はdocument capture phaseで `Ctrl+wheel` を先に判定し、current Map Shell内・cancelable・browser zoom recovery中ではない場合だけbrowser default zoomを抑止してCanovia Map zoomへ渡す。Keyboard / browser menuによるzoomは無効化しない。

Map mount時の `window.devicePixelRatio` をruntime baselineとして保持し、current DPRとの差が8%を超えた場合はbrowser page zoomが変化したものとしてMapが `Ctrl+wheel` を消費しないrecovery modeへ入る。同じpinchでbrowser倍率をbaselineへ戻せることを優先し、baseline付近へ戻るとMap pinch captureを再開する。DPR / browser倍率はTelemetry・DBへ保存しない。

V49.2 In-place Semantic Expansionのlocal radiusはmobile/PWA 0.72を維持し、desktopを0.80へ拡大する。viewport heightが720px以下のdesktopでは0.84とし、低い画面でParent / Project / Create等のNodeが縦方向に重なる問題を緩和する。

詳細は `docs/V49.3_DESKTOP_PINCH_ISOLATION_EXPANSION_SPACING.md` を正とする。

## V49.4 Semantic Zoom LOD / Camera-Selection Separation

Continuous Semantic Zoomの操作予測性を改善するため、camera zoomとsemantic selectionを分離する。

Desktop precision pinchでは、camera scale自体は常に連続更新するが、zoom-inのsemantic targetはpinch中心がzoomable Nodeのbounding box + 28px以内にある場合だけ候補とする。空白位置へのpinchはcamera zoomだけを行う。Click / tapは明示selectionとして従来どおりNodeを開く。Mobile / PWAのpinch candidate契約はV49.3時点で問題がないため変更しない。

+/- controlsとdouble-clickはcamera zoom専用とし、それだけではsemantic levelを切り替えない。

Camera scaleからdesktop LODを算出する。

- overview: < 0.90
- context: 0.90〜1.16
- detail: 1.16〜1.34
- ready: >= 1.34

overviewではlabel中心、contextでeyebrow、detailでsubtitleとdirect-open preview、readyでfull actionを表示する。既存semantic zoom-in threshold 1.42の前にready区間を設け、Nodeを開く予兆を表示する。

Desktop ready LODでpinch focusがNode近傍に入ると `is-semantic-armed` として強調し、どの箱が開くかをthreshold到達前に明示する。

Node cardはscene camera scaleへそのまま追従させず、desktopでは `1 / scale^0.72` のcounter-scale（0.62〜1.32）を適用する。これにより空間距離は大きく変わる一方、Node UIのscreen-space sizeはcamera 2xで約1.24x、camera 0.68xで約0.90xに抑える。Mobile / PWAではcounter-scaleを適用しない。

LOD / counter-scale / armed Nodeはruntime onlyで、DB・Telemetry・sessionStorageへ新規保存しない。

詳細は `docs/V49.4_SEMANTIC_ZOOM_LOD.md` を正とする。

## V49.5 Semantic Zoom Stabilization

V49.4のcamera-selection separationを安定化し、Desktop semantic zoomへhysteresis / dwellを追加する。ready LODは1.34のまま、同じzoomable Node近傍を120ms狙った場合のみarmedにする。armed stateはscale 1.28未満まで解除せず、Desktop semantic open thresholdは1.46へ変更する。Mobile / PWAのsemantic open threshold 1.42は維持する。

Pinch gestureは開始時に指中点の下にあるMap world coordinateを固定anchorとして保存し、各frameでcurrent midpoint / target scale / fixed world anchorからcamera x/yを直接逆算する。Desktop precision pinchも同じworld-anchor helperを利用し、zoom時の注視点driftを抑える。Map boundary clampが必要な場合のみanchor preservationよりboundary safetyを優先する。

In-place Semantic Expansion後だけlocal collision resolutionを適用する。selected semantic parentはlocked nodeとして位置を維持し、overlapしたchild同士だけを必要最小限押し広げる。Desktopは18px padding / 6 iterations / 8px boundary、Mobileは10px / 6 / 4px。通常のL0 constellationやcanonical layoutへglobal force layoutは適用しない。

追加stateはsemantic arm candidate id / candidate sinceのみでcurrent Map runtime限定。DB / sessionStorage / Telemetryへ新規永続化しない。

詳細は `docs/V49.5_SEMANTIC_ZOOM_STABILIZATION.md` を正とする。

## V49.6 Semantic Expansion Clarity

Semantic ZoomはContainer Nodeを一段具体化する操作に限定する。 `presentation.kind=leaf` の最具体NodeはSemantic Zoom候補から除外し、tap/clickでContext Surface / Palette detailを開く。Scene Nodeには `data-map-semantic-capability=expand|detail|none` を付与し、runtimeのzoom-in candidate探索は `expand` のみを対象とする。

zoom-in arrival後、selected parentはworld positionを維持したまま `is-semantic-expanded-parent` として半透明・縮退し、展開元を示すambient contextへ退く。childは `is-semantic-expanded-child` としてdesktop 0.88 / mobile 0.84程度へ一段小さく表示し、V49.4 counter-scale / V49.5 collision resolutionを維持する。

Semantic zoom-out thresholdは0.82から0.70へ下げる。0.82〜0.71の縮小は同じsemantic level内のcamera zoomに留め、階層collapseには明確な縮小gestureを要求する。Mobile semantic open threshold 1.42 / Desktop 1.46は維持する。

詳細は `docs/V49.6_SEMANTIC_EXPANSION_CLARITY.md` を正とする。

## V49.7 Dashboard Map / Document Canvas

Plan / Task等の具体Contextは、Map Nodeを巨大化するのではなくDashboard DocumentとしてMap上へ開く。Mobileでは情報をviewport幅へ強制reflowせず、Plan Dashboardは約54rem、Node Detailは約52remの固定幅Document Canvasとして保持し、Document Viewportから上下左右へpanして読む。Mapは背面のspatial contextとして残す。

Document Surfaceは `data-map-document-viewport` / `data-map-document-canvas` を共通contractとし、Node DetailはSummary / Context / Meta / Actionsのdashboard regionへ整理する。Plan Dashboardは既存Classicのsummary-metrics / Roadmap partialを再利用し、Map専用data modelを追加しない。

Execution IntentではPlan.categoryによるDomain階層を通常Navigationから削除し、L1へPlanを直接配置する。Plan選択から `/map?level=l3&intent=execution&plan=<id>` へ進み、Execution Contextのauthoritative parentは `/map?level=l1&intent=execution` とする。表示上のsemantic depthは Intent -> Plan -> Execution の0/1/2。

戻る操作は明示Back controlを常設し、standalone PWAのみleft-edge swipeを補完する。Safari等ブラウザのnative edge gestureはCanovia側で奪わない。

詳細は `docs/V49.7_DASHBOARD_MAP_DOCUMENT_CANVAS.md` を正とする。

## V49.8 Dashboard Surface Refinement

Dashboard Documentではnavigation chromeとdocument canvasを同じscroll containerへ入れない。`data-map-document-viewport` 配下を fixed `data-map-document-chrome` + pan対象 `data-map-document-scroll` + `data-map-document-canvas` に分離し、Back / canonical title / CloseまたはPrimary Actionはviewportへ固定する。

MobileのPlan Dashboardはfirst paintからDocument Mode、leaf Detailはopen中だけDocument Modeとする。Document Mode中はMap Global Navigation / Page・Data Layer controls / Map gesture controls / Spatial Dockを退かせ、Fullscreen Topbarはcompact化する。Utility SurfaceやSpace Stationはleaf Dashboard Modeへ含めない。

Plan Dashboard Documentは約52rem、leaf Detail Documentは約46remを基準とする。leaf DetailはSummaryを全幅、Context / MetaとActionsを下段2columnへ再配置し、固定Chromeへtemplate canonical titleを同期する。

Document Viewportにはhorizontal position HUDを表示する。standalone PWAのleft-edge backはactive Documentの `scrollLeft <= 1px` の場合だけarmし、Documentを右側へpan中のhorizontal gestureをnavigationとして扱わない。

Global depth labelはplan-first hierarchyへ合わせ、Executionは `全体 -> Plan -> 実行`、Plan閲覧は `全体 -> Plan -> Dashboard` とする。

詳細は `docs/V49.8_DASHBOARD_SURFACE_REFINEMENT.md` を正とする。


### V49.8.1 Pre-device Hardening

V49.8実機確認前の静的監査として、Plan Dashboardのabsolute positioningをDocument edge affordanceが上書きしないようcascadeを固定する。horizontal pan可能なDocumentだけ左右edge fadeを表示し、`is-document-scrollable / is-document-at-start / is-document-at-end` をruntime stateとして利用する。Planのユーザー向け表現はWorkspace / PaletteからDashboard / Documentへ統一する。Plan / Detail Dashboardはtitleでlabelされたregion、Document Scrollはkeyboard focus可能なregionとし、固定Detail BackはSemantic Zoom-outへ接続する。旧Bottom Sheet由来のFeature Test aria contractも現在のDashboard構造へ更新する。

## V49.9 Spatial Roadmap / Fit Document

Plan DashboardのRoadmapは既存 `RoadmapSpatialProjectionService` をPrimaryとして使い、`RoadmapService -> Spatial Projection -> Dashboard` のread-only projectionとする。Dashboardでは `dashboard-overview` modeを使い、List切替、Current自動センタリング、Task detail展開を持ち込まず、Phase / Cluster / Task / Dependencyの構造把握を優先する。Spatial StageはDashboard frameへ自動fitし、最初にRoadmap全体構造を一望できる状態をPrimaryとする。`/roadmap` 本体のstandard interactionは維持する。

V49.9は段階導入とする。Phase 1ではSpatial Roadmap PrimaryとRoadmap全体fit、Phase 2ではPlan / leaf Detail Documentのfit-first Camera、Phase 3ではtouch / trackpad pinch・pointer-anchor zoom・Roadmap Container focus・Task leaf detailを導入済み。Phase 4ではSpatial Roadmapへsemantic 2.5D depthを追加する。Taskは `Current=foreground / Ready=near / Future=neutral / Blocked=recessed / Done=deep` とし、Cluster / Phaseは内部Task roleを集約、Dependency / Lineage Edgeにもdepth roleを付与する。BlockedはFutureよりaggregate上の優先度を高くし、進行阻害を視覚上埋もれさせない。2.5Dはscale / offset / opacity / shadow / saturation / z-indexで表現し、WebGL等のreal 3D engineは導入しない。Dashboardには状態Legendを固定表示する。すべてread-only projection / presentation stateで、DB永続化は追加しない。

詳細は `docs/V49.9_SPATIAL_ROADMAP_FIT_DOCUMENT.md` を正とする。


## V50.0 Contextual Map Foundation

Map UIをCanovia全体の標準Navigationへ置き換えることは前提にしない。Classicは入力・編集・一覧・日常操作、Dashboardは1つのContextの理解と判断、Mapは複雑な構造・関係・Dependency・Parallelism・Blockerを理解・探索するSpatial Surfaceとして責務を分離する。Mapを使わなくても主要機能を利用できる状態を維持し、Mapは明確な構造理解価値があるContextで使う。

現在のMap requestへdescriptive `MapSurfaceRole` を付与する。roleは `global_navigation / plan_context / collaboration_context / reflection_context / execution_context / hierarchy_context`。Global Navigationだけscope=`global`、その他はscope=`contextual`。RoleはView選択を決定しない。

`MapComplexitySnapshotService` はGraph / Spatial Roadmapからnode / edge / plan / task / dependency / cluster / parallel cluster / phase / blockedの件数と構造booleanだけをrequest時に生成する。score / threshold / preferred_view / recommendationは持たせず、DBへ保存しない。

既存Map Telemetryへwhitelist済みstructural metadata `surface_role` を追加し、Complexity countやuser contentは送信しない。既存 `map_viewed` はFlow開始、`map_surface_viewed` は各Surface入場として分離する。MapTelemetryServiceはSurface Role別にviews / flows / focus_rate / classic_action_rate / back_per_flowを集計する。Adminは用途別検証へ変更するが、Map / Classicの優先SurfaceをTelemetryから自動決定しない。

既存Semantic Zoom / Spatial Memory / Plan Dashboard / Spatial Roadmap / Document Camera / Collaboration Projection等はGlobal Map専用技術として破棄せず、Contextual Spatial Surfaceの共通資産として再利用する。

Inbox Command Center、Automatic View score / threshold、Automatic / Classic / Map preference、AI view selection、Global Map削除はV50.0では実装しない。

詳細は `docs/V50.0_CONTEXTUAL_MAP_FOUNDATION.md` を正とする。


## V50.1 Dashboard Document Foundation

Canoviaの主要Surface責務を、Classic=入力・編集・一覧・日常操作、Dashboard Document=複数情報の俯瞰・比較・判断、Map=構造・関係・Dependency・Parallelismの探索、として分離する。

V49.9でMap最下層に実装したDocument Cameraのcamera mathを `resources/js/dashboard-document.mjs` へ抽出し、Map以外のDashboardでも利用できる共通基盤とする。Dashboard DocumentはFixed Chrome + Document Scroll + Stage + Canvasの構造を持ち、初期全体fit、explicit zoom、touch/trackpad pinch、panを提供する。Camera stateは永続化しない。

共通Blade Surfaceとして `<x-dashboard-document>` と `<x-dashboard-document-region>` を提供する。Canvasは12-column information boardを標準とし、複数情報を縦一列へ強制reflowせず、一枚の配置関係を保ったまま全体fit / zoomできる。

既存Plan Dashboard / leaf Detailは移行期間中 `data-dashboard-document-owner="map"` とし、Generic Dashboard runtimeはmountしない。Map runtimeとの二重gesture処理を避けつつ、generic DOM contractを先に共有する。旧Map camera controls partialはshared Dashboard controlsへのcompatibility wrapperとする。

Dashboard DocumentはMapではなく、必要な場合だけSpatial Roadmap等のContextual Mapを一Regionとして内包する。大量Form / Table / Settings / Chat / Task listなど順次操作が適する画面はClassic / Scrollを維持する。

詳細は `docs/V50.1_DASHBOARD_DOCUMENT_FOUNDATION.md` を正とする。


## V50.2 Plan Dashboard Information Board

V50.1 Dashboard Document FoundationをPlan Dashboardへ適用し、Plan詳細を縦スクロール中心ではなく一枚の12-column Information Boardとして構成する。配置はOverview 4 + Progress 8、Next Action 4 + Attention 4 + Activity 4、Spatial Roadmap 12 / 2 rowsをPrimaryとする。MobileでもRegionを一列へreflowせず、70remの安定したDocument geometryを初期fitして必要箇所へzoomする。

`PlanDashboardBoardService` はPlan / Progress / canonical Roadmap / Spatial Roadmapからread-only Board projectionを生成する。Current/doing/readyをNext Actionへ、ready Task最大3件、blocked Task最大3件、最新WorkLog最大4件、ready/blocked/parallel等のsignalsを提供する。Attentionはblocked、Plan status、current、readyの順で決定論的に作り、V50.2ではAI生成やAI Insight表記を行わない。

Next / Ready / Blockedは既存Roadmap Task Detailへ接続し、Spatial RoadmapもBoard内のStructure Regionとして既存V49.9 interactionを維持する。追加DB / layout persistence / user customizationは行わない。

詳細は `docs/V50.2_PLAN_DASHBOARD_INFORMATION_BOARD.md` を正とする。



## V50.3 Standalone Plan Dashboard

Plan DashboardをMap-owned Documentから独立したDashboard Document Surfaceへ昇格する。正規routeは `/plans/{plan}/dashboard`（`plans.dashboard`）。Plan intentのMap L1 Plan nodeはこのDashboardへ直接遷移し、MapはDashboardの所有者ではなくlauncherとなる。旧Map L2 Plan Dashboard URLはmigration compatibilityとして残す。

`PlanDashboardWorkspaceService` がPlan / Progress / canonical Roadmap / Spatial Roadmap / Information Board / ownership / execution URLをrequest-timeに共通生成し、Standalone ControllerとMap compatibility surfaceの双方が利用する。private Planはrelations load前に `canView` を判定する。V50.2の6 Region markupはshared partialへ抽出し、Standalone / compatibilityで二重実装しない。

Standalone Dashboardはgeneric `Dashboard Document` runtimeでinitial fit / zoom / pinch / panを行い、Phase / Clusterの `data-roadmap-region-focus` もgeneric runtimeで扱う。Spatial RoadmapはDashboard全体ではなくStructure Regionであり続ける。

Task Detailは既存Roadmap Task templateを再利用し、`plan-dashboard.mjs` が `#roadmap-task=...` のhash/back state、Close / Backdrop / Escape、focus restorationを担当する。Detailを開閉してもouter Document Cameraのscale / scrollは維持する。

Classic Planは編集・詳細管理Surfaceとして維持し、Dashboard actionを追加してClassicとDashboardを相互移動可能にする。追加DB、layout persistence、Automatic Surface selection、Legacy Map L2削除は行わない。

詳細は `docs/V50.3_STANDALONE_PLAN_DASHBOARD.md` を正とする。

## V51.0 Primary Navigation & Companion Shell

Primary Navigationを `Home / Constellation / Execution / Timeline` の4 Surfaceへ整理する。Homeは「今何をすればいいか」、Constellationは「全体と現在地」、Executionは「実際にどう進めるか」、Timelineは「何が起きたか」に答える。Inboxは機能を維持したままPrimary Navigationから外し、役割再検討中とする。

AI Companionは5番目のNavigationではなく横断Control Surfaceとする。Bottom Navigationより上の右下Floating Orbから大型Paletteを開き、現在のPlan / Task / Global Contextを確認して既存Companion Threadへ接続する。V51.0ではPalette shellを実装し、chat本体の埋め込みは後続Phaseとする。

Primary Nav keyは `desktop/mobile-home / constellation / execution / timeline` を正とする。HomeボタンはLegacy Home Surface Preferenceに関係なくClassic Action Home `/` へ入る。Legacy Mapは削除しないがPrimary Home扱いしない。Roadmap `/roadmap` はConstellationの暫定入口、Navigation `/navigate` はExecutionの暫定入口とする。

後続順は Constellation Roadmap → Execution Workspace → Action Home → Timeline/Achievement → Companion Deep Integration。詳細は `docs/V51.0_PRIMARY_NAVIGATION_COMPANION_SHELL.md` を正とする。


## V51.1 Constellation Roadmap

Roadmap `/roadmap` はUniverse-first Constellation Surfaceとする。初期状態ではPlanを自動選択せず、中央Space Stationと周囲のPlan Constellationを表示する。Plan選択時のみ星座を軽く拡大し、完成度・状態・Main Starごとの `完了/総数` を表示する。

TaskはMain Starへ1:1対応させない。`ConstellationProjectionService` がcanonical Roadmap順序を維持しながらTaskをcost-balancedなTask Groupへ圧縮する。Main Star数とRichnessはTask countだけでなくestimated minutesとcross-group structureも使うが、scoreはユーザーへ公開しない。Main Star位置はSpatial Roadmapのdependency depthを基準とし、dependencyをStar間edgeへ集約する。両端Star完成時にedgeを発光させる。

Main Star選択時のみTask Listを表示し、Task title/status/progress/remainingを確認できる。「今何を優先するか」はRoadmapへ持たせずExecution Surfaceへhandoffする。Space StationはPlan作成・一覧管理・選択中Plan更新/詳細の操作拠点とする。

MobileではUniverseを画面幅へ無理に縮小せず、minimum sizeを持つpannable Stageとして扱う。既存`RoadmapSpatialProjectionService` はPlan Dashboardやdependency inputとして維持する。

詳細は `docs/V51.1_CONSTELLATION_ROADMAP.md` を正とする。


## V51.2 Execution Workspace Modes

Execution `/navigate` はMode-aware orchestration layerとする。Execution ModeはPlanへ保存せず、`ExecutionModeService` が既存categoryとspecialized capability boundaryから `study / development / career / general` をrequest時に導出する。

複数Modeがある場合のみMode Pickerを表示し、選択後はSwitcherで切替可能とする。1 Modeのみなら自動選択する。Constellation等から `plan_id` 付きで入った場合は、そのPlanのModeを自動選択しPlan scopeを維持する。

RecommendationService自体は変更せず、入力候補をselected Mode内Planへ限定する。同一Modeに複数Planがある場合のみPlan switcherを表示し、preferred PlanのUI/server validationもMode内に限定する。

Task決定後はStudy -> Study Activity、Development -> Execution Orchestration、Career -> Career Workspace、General -> WorkSession Timerへhandoffする。specialized handoffがあるPrimary RecommendationにもTimer fallbackを残す。

詳細は `docs/V51.2_EXECUTION_WORKSPACE_MODES.md` を正とする。


## V51.3 Action Home

Primary HomeはPlan browserではなく「今、何をすればいい？」へ答えるAction / Alert Surfaceとする。表示優先順はActive Work Session、Next Action、Important Changes、Continuity、utility、small Timeline preview、collapsed daily context。

既存Dashboard Guidanceのobjective priorityとspecialized execution actionは維持するが、Plan tabs、全Planカード一覧、per-Plan Dashboard panel、Plan-specific Surface Modules、Roadmap詳細をPrimary Homeから外す。Plan全体はConstellation、実行方法の選択はExecution、履歴はTimelineへhandoffする。

ActionHomeProjectionServiceはDBを変更せず、pending Plan update、PlanProgressServiceのattention status（遅れ気味 / 期限切れ / 作業時間不足）、共同計画の他メンバーによるPlanActivityLogをread-only signalへ投影する。Home sessionには直近Plan statusだけをaction_home.plan_statusesとして保持し、次回表示時にattention statusへ変化した場合はSTATUS CHANGEDとして状態遷移を明示する。Instant Navigation prefetchではこのsnapshotを更新しない。

共同計画のsignalは過去14日の他ユーザー操作から最大4件を取り、Homeには変化だけを表示する。参加等のmanagement actionはcollapsed disclosureへ残し、共同計画一覧そのものはHomeのPrimary contentにしない。Recent Activityも最大3件のpreviewに限定し、全履歴はTimelineへ渡す。

詳細は docs/V51.3_ACTION_HOME.md を正とする。


## V51.4 Timeline / Achievement Constellation

Primary TimelineはWorkLog一覧から、Work / Collaboration / Plan Completedを同じ時系列へ投影するReflection Surfaceへ拡張する。

Completion判定は`AchievementProjectionService`へ集約し、既存AchievementsとTimelineで同じsource of truthを使う。Completed PlanはTimeline上部のcompact Achievement Constellationへ1 Plan = 1 Starで表示し、starから既存Achievement detailへ遷移する。

Timeline eventとAchievement Constellationは既存canonical dataからrequest時にread-only生成する。V51.4ではDB migration、read/unread、achievement ranking、immutable historical constellation snapshotは追加しない。

詳細は `docs/V51.4_TIMELINE_ACHIEVEMENT_CONSTELLATION.md` を正とする。


## V51.5 Companion Deep Integration

Floating Companion Paletteを単なる入口からCross-Surface Control Surfaceへ拡張する。Primary Navigationは引き続きHome / Constellation / Execution / Timelineの4 Surfaceで、Companionを5番目のページにはしない。

Paletteは既存CompanionEntryService / CompanionConversationService / CompanionMutationApplyServiceを再利用し、既存endpointへPalette request headerを付けて会話・Candidate review・apply / dismissを非同期実行する。Full Companion pageとPaletteはCompanionThreadSurfaceServiceの同じContext / Continuity / Candidate Preview projectionを使う。

通常の会話やCandidate確認では現在Surfaceから強制遷移しない。Execution Request Candidateを確認した場合も自動遷移せず、Execution Orchestrationへのhandoff linkだけを返す。JavaScript無効時は既存Full Companionへのredirect fallbackを維持する。

DB migrationなし。既存Companion Thread / Message / Candidateだけを利用し、Human Confirm、mutation whitelist、idempotency、ownership / entitlementを変更しない。

詳細は `docs/V51.5_COMPANION_DEEP_INTEGRATION.md` を正とする。


## V51.6 Surface Refinement Phase 1

実機で確認したConstellation / Executionの情報密度と操作距離を改善する。

Constellation overviewはraw orbitの安定位置を維持しつつ、表示時だけPlan数に応じて中心からの距離を圧縮する。1〜6 Planでは巨大な52rem固定Stageを使わずviewport内へ収め、7 Plan以上のみwide stageを許可する。

Plan選択後はUniverse内で小さくzoomする方式を廃止し、Selected Constellation Workspaceへ切り替える。上段Focus Paletteで選択Planの星座を大きく表示し、Main Star tapでTask Groupを同一Palette内へ表示する。下段Detail PaletteはPlan completion / Task / Main Star / statusとExecution / Plan detail / Plan update handoffを担当する。旧左下InspectorとStar Task dialogはSelected flowから外す。

ExecutionはPrimary recommendationを維持しつつ、「別候補を見る」buttonを廃止する。同じExecution Modeの別Planから各1件のおすすめTaskを優先して横スライドRailへ常時表示し、不足時のみ同Mode内から補完する。

Study / Development / Careerのspecialized executionではTimer buttonを表示しない。TimerはGeneral executionのprimary execution patternとしてのみ表示する。既存active/offline work session resumeは継続する。

詳細は `docs/V51.6_SURFACE_REFINEMENT_PHASE1.md` を正とする。Constellation shape grammarの多様化はPhase 2で扱う。


## V51.7 Constellation Shape Grammar

ConstellationをPlan識別のvisual signatureとして扱う。既存dependency `pattern` は意味情報として維持し、表示geometry専用の `shape_key` を分離する。projection schemaは2とする。

Shape familyは `singular / binary / arc / ladder / orbit / zigzag / branch / fan / cluster`。Main Star数、dependency pattern、Plan ID seedから決定論的に選び、同じPlanを開き直しただけでは形・座標を変えない。Task構造変更でMain Star数が変わった場合の再投影は許容する。shapeはscoreやPlan評価には使わない。

OverviewとSelected Workspaceは同じshape_key / Star座標を共有し、Selected時だけ表示scaleを大きくする。

Selected WorkspaceにはTask Group railを追加し、Main Star tapと同じselected stateを共有する。星が小さい・密集している場合も、星域番号と完了数からTask Groupを直接切り替えられる。

Selected WorkspaceにはOverviewと同じPlan順序のPrevious / Next navigationを追加する。Focus graph上ではinteractive element外から始まった水平swipeもPlan切替として扱い、72px未満の横移動または48pxを超える縦移動は無視する。

DB migration、draggable geometry、persisted custom position、physics/WebGLは導入しない。詳細は `docs/V51.7_CONSTELLATION_SHAPE_GRAMMAR.md` を正とする。


## V51.8 Stability & Interaction Performance

iOSアプリ化へ進む前に、Web/PWAの体感遅延をserver / client両面から分解できるようにする。

既存 `canovia.performance` server logに加え、Core Surfaceでは `POST /performance/client` へ安全な数値metricsだけを送信し、`canovia.client_performance` structured logとして記録する。DB保存はしない。対象は `/ /map /inbox /roadmap /timeline /calendar /navigate` のみで、query string、Plan/Task title、user入力は送らない。

Instant Navigationは cache / prefetch / network source、wait、fetch、HTML parse、DOM replace、Surface mount、2 animation frames後までを計測する。対応ブラウザではlong task / layout shiftも同じinteraction windowへ集計する。iOS / Android / other、web / PWA、mobile / desktopを区別し、V52 Native shell導入後の比較基準にする。

常時1秒ごとに全pageで走っていたWork Timer DOM scanを廃止し、`[data-work-timer]` が存在しdocumentがvisibleな場合だけTickerを動かす。Instant NavigationでtimerのないSurfaceへ移動した場合とbackground移行時は停止する。

Instant Navigation後のoffline IndexedDB snapshot保存はidleへ移し、次の入力と競合させない。cached / prefetched fragment表示後に必要なserver-side navigate revalidateもrender直後ではなくidleへ送る。実訪問semanticsとcache refreshは維持する。

Dashboard Roadmap overviewが存在しないSurfaceでは2回目のrequestAnimationFrame fitを予約しない。

詳細は `docs/V51.8_STABILITY_INTERACTION_PERFORMANCE.md` を正とする。


## V51.9 Navigation Bottleneck Removal

V51.8のiOS PWA実機計測でExecution `/navigate` のserver DB N+1を特定した。

実測では最大:

- 13.98秒
- 158 query
- Task query 143回
- 同型Task SELECT 142回
- 同型Task SELECT合計 約11.99秒

原因は `RecommendationService` がcandidate Taskごとに `Task::dependencyIds()` を呼ぶ一方、NavigationControllerがlegacy singular `prerequisite` のみをeager loadし、canonical many-to-many `prerequisites` をloadしていなかったこと。

NavigationControllerはExecution用Plan取得時に `tasks.prerequisite` と `tasks.prerequisites` を同時eager loadする。Recommendation score / dependency semanticsは変更しない。

多数Taskとcanonical task_dependenciesを持つPlanでもTask SELECT数がTask数に比例して増えないことをFeature regression testで固定する。

詳細は `docs/V51.9_NAVIGATION_BOTTLENECK_REMOVAL.md` を正とする。


## V51.9.1 Server Cost Reduction

V51.8/V51.9 production telemetryで確認したserver固定費を削減する。

production sessionはRender SingaporeのKey Valueへ移し、`SESSION_DRIVER=redis` / `SESSION_CONNECTION=default` / private internal `REDIS_URL` / persistent phpredis connectionを使用する。free Key Valueはdisk persistenceなしのため、restart時にlogin/session stateが失効する可能性は許容する。Plan/Task/Evidence等のdomain dataはDBをsource of truthとして維持する。rollbackは `SESSION_DRIVER=database`。

Home common pathでは career / memberships / activity_logs を無条件loadしない。Career profileのPlanがある場合だけcareer relationを、共同Planがある場合だけmembership/activity relationをhydrateする。該当SurfaceのUI semanticsは変えない。

Instant Navigationのautomatic idle prefetchからHomeを除外する。通常のHome起点ではcurrent page cacheが既に存在し、deep link起点ではユーザー意図なしに高コストHome projectionをbackground実行しない。pointerover / focusin / touchstartによるintent prefetchと通常navigationは維持する。

Core Bundle自体の分割はV51.9.1では行わず、Redis session化後の再計測で判断する。

詳細は `docs/V51.9.1_SERVER_COST_REDUCTION.md` を正とする。


## V51.9.2 Home / Core Bundle Cost Reduction

V51.9.1でdatabase session固定費をRedisへ移した後、Home / Timeline / Core Bundle内のSQL roundtripを削減する。

TimelineのPlan adjustment取得はPlanごとの `loadMissing('adjustments')` を禁止し、全PlanをEloquent Collectionへまとめて1 queryでbatch loadする。共同Planが存在しない場合は `activity_logs` featureをloadしない。

`BehaviorEventLogger::recordOnce()` / `recordOnceSafely()` は同じsession内の直近記録timestampをRedis sessionへ保持する。同一event / Plan / Task / dedupe window内ではbehavior_eventsへのexists queryを省略する。Session markerがない場合は従来どおりDB dedupeを行う。

Homeの `UserStateSnapshot` 永続化は表示state計算とは分離し、同一session・同日では10分に1回までに抑える。日付変更時は即captureし、prefetchでは従来どおりcaptureしない。

Homeのactive/pending WorkSessionとContinuity latest WorkSessionは検索scopeを維持したまま同一contextへまとめ、取得したSession群のPlan / Task relationをbatch hydrateする。DashboardPresentationServiceはpreloaded contextを受け取り重複WorkSession relation queryを行わない。

Behavior sampleがanalysis threshold未満の場合、利用されないUserStateSnapshot trend SELECTは行わない。

Core Bundle endpoint / JSON contract / direct fragment fallbackは変更しない。内部query削減後のproduction telemetryを見て、bundle head-of-line blockingやprefetch分割は次段階で判断する。

詳細は `docs/V51.9.2_HOME_CORE_BUNDLE_COST_REDUCTION.md` を正とする。


## V51.9.3 DB Connection & Query Roundtrip Reduction

V51.9.2 production telemetryで、iOS PWAのcache hit UIはHome 92ms / Constellation 73msまで下がっている一方、server側では各requestの最初のusers SELECTが約275〜295ms、その後の単純SELECTも約78〜85msであることを確認した。

MySQL/MariaDB connectionは `DB_PERSISTENT` 環境変数で `PDO::ATTR_PERSISTENT` をopt-inできる。defaultはfalseとし、productionのみtrueへ切替可能にする。rollbackは `DB_PERSISTENT=false`。DB schema / credentials / data sourceは変更しない。

ExecutionではPlanごとの `PlanProgressService::calculate()` により `availabilityRules / availabilityOverrides` がN+1化していたため、NavigationControllerで両relationを全Plan分eager loadする。Plan数に関係なくavailability SELECTは各relation 1回に固定する。

TimelineでもAchievement Projection経由で同じN+1が発生するため、CoreContextのTimeline featureへ `availability` を追加してbatch loadする。Core BundleではCalendarが既にavailabilityをloadしておりShared CoreContextで再利用される。

詳細は `docs/V51.9.3_DB_ROUNDTRIP_REDUCTION.md` を正とする。


## V51.9.4 Home Query Collapse

Primary Homeから未使用のlegacy `collaborationPlans` membership projectionを削除する。Action Homeの共同作業Signalに必要な `activity_logs` は維持するが、Homeでは `memberships` をloadせず、共同Planがあるaccountでも `plan_members` SELECTを発生させない。

CoreContextで `tasks` が既にload済みの状態から `work_logs` をloadする場合、WorkLog.taskのためにTaskを再SELECTしない。Plan.tasksをtask_idでindexし、WorkLogへrelationとしてreuseする。tasks未loadのcallerでは従来どおり `with('task')` を維持する。

V51.9.2 production Home 23 query shapeでは、plan_members 1 query + WorkLog.task再取得用tasks 1 queryの削減を狙う。

詳細は `docs/V51.9.4_HOME_QUERY_COLLAPSE.md` を正とする。


## V51.9.5 Model Reuse & Dependency Hydration

Home ContinuityはCoreContextで既にload済みのPlan / Task modelをWorkSession relationへ再利用する。matching modelが存在する場合は `setRelation()` し、current context外のSessionのみ従来どおりDB hydrationへfallbackする。これにより典型HomeではContinuity用plans/tasks SELECTを発生させない。

CoreContextの `task_dependencies` featureは、既にload済みのTask modelを依存先Taskとして再利用する。canonical source of truthである `task_dependencies` pivotを1 queryで読み、legacy `prerequisite` とcanonical `prerequisites` relationを既存Task modelから構成する。Task resourcesは従来どおりloadする。

V51.9.4後の典型Homeからさらに約3 DB roundtrip削減を狙う。V51.9.2 production baseline 23 queryに対し、同account shapeで約18 queryが目安。

詳細は `docs/V51.9.5_MODEL_REUSE.md` を正とする。


## V51.9.6 Real-device UI Refinement

iPhone実機確認を反映し、Execution Candidate RailはPrimary Planの別Taskを含めず、Other Planごとに最大1 Taskを横比較する。固定枚数を埋めるsame-Plan fallbackは廃止する。

候補互換性はExecution Modeだけでなく具体的handoffで判定する。`ExecutionModeService::actionFor()` は `compatibility_key` を返し、Candidate RailはPrimaryと同じkeyのTaskだけを候補にする。StudyではQuestion Practice / Recall / Resource Studyを分離する。資格学習Plan内でも、Task自身に学習実行Signalがない申込・予約等の管理TaskはStudy Workspaceへ送らずTimerへfallbackする。

Primary Execution Surfaceから旧「条件変更」link、Intent選択、Time選択UIを退役する。既存sessionに旧stepが残る場合はrecommendationへ正規化する。旧POST endpointは互換性のため残す。

Mobile Selected ConstellationはTask Group確認を優先し、focus paletteを `clamp(34rem, 70dvh, 41rem)`、graph/task比率を45%/55%へ変更する。Task panelは独立scrollを維持する。

詳細は `docs/V51.9.6_REAL_DEVICE_UI_REFINEMENT.md` を正とする。


## V51.9.7 Action Home Density & Plan Swipe

Action Homeの「確認したい変化」はPrimary Actionより弱いsecondary attention railとして扱う。Mobileでは縦積みをやめhorizontal scroll + snapへ変更し、card widthを `min(84%, 21rem)` とする。色・border・typographyもquiet化する。

Plan update signalにはquick dismiss `×` を追加する。dismissはWorkSession / WorkLog / 実績履歴を削除せず、`needs_plan_update=false` と `metadata.plan_update_dismissed_at` だけを記録する。`plan_updated_at` は変更しない。UIはfetchでcardを即removeし、失敗時だけ通常form submitへfallbackする。

Plan status signalのCTAは `実行を見直す` を廃止し、遅れ気味/期限切れは `次のTaskを見る`、作業時間不足は `優先Taskを見る` とする。

Active WorkSession中のIn Focusはsingle cardではなくhorizontal Plan deckに戻す。先頭にactive session、続けてDashboard Guidanceからactive Planを除外したOther Plan候補を最大4件表示する。他Plan cardはPlan scoped Executionへ遷移する。Active WorkSessionを自動終了・自動切替はしない。

Action Home signal schemaはv2。Plan update signalのみoptional `dismiss_url / dismiss_label` を持つ。

詳細は `docs/V51.9.7_ACTION_HOME_DENSITY.md` を正とする。


## V51.9.8 Web Real-device Finish

Web版のiPhone実機仕上げとして、mobile shell / safe area / horizontal rail / virtual keyboard / Constellation swipe interruptionを統一する。

Mobile shellは `--canovia-mobile-dock-clearance: calc(7rem + env(safe-area-inset-bottom))` を基準にmain bottom paddingと `html.scroll-padding-bottom` を同期する。header/tabbar左右には `safe-area-inset-left/right` を反映し、landscape notchでもphysical safe areaへUIを入れない。

VisualViewportが使える環境ではeditable elementへfocus中かつviewportがbaselineより120px超縮小した場合をvirtual keyboard openとし、固定mobile tabbarを非表示・非interactive化する。VisualViewport非対応時は既存挙動へfallbackする。

Home Guidance / Active Focus / Attention、Execution Mode / Plan / Recommendation、Constellation Task Groupのhorizontal railはiOS touch scroll契約を統一し、`-webkit-overflow-scrolling: touch`、inline overscroll containment、pan-x/pan-y、card系scroll-snap-stopを適用する。

Selected ConstellationのPlan swipeはsingle-touchのみ扱い、multi-touch / touchcancelでgesture stateを必ずresetする。

長いTask / Next Action textは主要mobile cardで `overflow-wrap:anywhere` + `text-wrap:pretty` により横overflowを防ぐ。

詳細は `docs/V51.9.8_WEB_REAL_DEVICE_FINISH.md` を正とする。


## V51.10 Interaction Smoothness

V51.8のiOS PWA実測ではcache hitがHome 92〜108ms、Constellation 73〜92ms、Surface mount 0〜5ms、Long Task 0まで下がっている一方、tap直前のprefetch待ちが約1.6〜2.3秒になるcaseを確認した。V51.10ではDOM mountの再設計ではなくnavigation orchestrationを優先する。

Instant Navigationはhistory entryごとに `canoviaScroll:{x,y}` を保持し、push前に現在scrollをreplaceStateへ保存する。Back/Forwardでは `popstate.state.canoviaScroll` を復元し、core runtime中は `history.scrollRestoration='manual'` とする。

touch intent prefetchは即時 `touchstart` fetchを廃止し、single touchのcore linkだけを対象に90ms静止後prefetchする。12pxを超えるtouchmoveではcancelし、horizontal rail / vertical page scrollの開始で不要network workを発生させにくくする。短いtapはtouchend時にprefetchを開始し、直後のclick navigationが同じinflight requestをreuseする。touch pointeroverはignoreする。

cache / prefetch表示後のnavigate revalidate semanticsは維持するが、`revalidateByKey` により同一URLのpending idle revalidateを1件へcoalesceする。

詳細は `docs/V51.10_INTERACTION_SMOOTHNESS.md` を正とする。


## V52.0 iOS Readiness Foundation

Canovia iOSは `SwiftUI shell + WKWebView + existing Laravel/Blade/JS` を採用し、Web appをsource of truthのまま維持する。Native shellはOS境界だけを担当し、認証・Plan/Task state・mutation business logicをNativeへ二重実装しない。

`resources/js/client-runtime.mjs` をruntime source of truthとし、surfaceを `web / pwa / native` に分類する。Nativeはdocument-start `window.__CANOVIA_NATIVE__` injectionをprimary、User-Agent suffix `CanoviaNative/iOS/<version>` をfallbackとして検出する。

WKWebView bridgeは `window.webkit.messageHandlers.canovia`、message schema version 1。Web→Nativeは `ready / navigationState / openExternal / fileInputRequested / requestClose`、Native→Webは `CanoviaNativeBridge.receive()` の `back / openPath / appBecameActive` を正規契約とする。

Nativeのsessionは独自tokenへ移さず、persistent WKWebsiteDataStore上のLaravel session cookie + existing CSRFを維持する。Bridgeへcookie/session/CSRF本文は送らない。

Native runtimeではPWA Service Workerを登録せず、WKWebsiteDataStoreにstale registrationがあればunregisterする。PWAとNativeのcache/navigation ownershipを二重化しない。

cross-origin http/https linkは `openExternal` でNativeへ渡す。same-origin `target=_blank` はcurrent WebView内で開き、新規WKWebViewを要求しない。

Deep LinkはCanovia same-origin pathだけをNativeから `openPath` へ渡す。Core SurfaceはInstant Navigation、non-coreはnormal navigationへfallbackする。

file/photo inputはV52.0ではWKWebView標準pickerを維持し、Nativeへ `fileInputRequested` metadataだけを通知する。

Interaction Performance / AI Funnel / Map telemetryのsurface whitelistへ `native` を追加し、Web/PWA/nativeの実測比較を可能にする。

詳細は `docs/V52.0_IOS_READINESS_FOUNDATION.md` を正とする。


## V53.0 Canovia Intelligence Contract

Canoviaの中心を、固定されたTask進行から「現実のStateをEvidenceで更新し、その時点の最善Actionを判断する」実行ループへ拡張する。

正規ループ:

```text
Goal
→ Current State
→ Gap / Readiness
→ Decision
→ Next Action
→ Evidence
→ Outcome
→ State update / Replan
```

Taskは廃止しない。Taskは長期計画・透明性・実行管理に有効だが、Intelligence Coreのsource of truthにはしない。

```text
Task != Intelligence State
Task != Decision source of truth
Task = Actionを表現する一つのProjection
```

V53.0の共通語彙は以下。

- State: 現在の現実を正規化した状態
- Evidence: 現実に起きたことの観測
- Readiness: 目標成果へ到達できる準備状態
- Decision: State / Readinessから選んだ次判断
- Action: Decisionから生成された実行候補
- Outcome: Action実行後にEvidenceから観測された結果
- Confidence: 観測・推定・判断の確信度 0.0〜1.0

共通処理境界:

```text
StateBuilder
→ ReadinessEvaluator
→ DecisionEngine
→ ActionGenerator
→ OutcomeInterpreter
→ next State
```

Study / Development固有ルールはCoreへ直接埋め込まず、後続V53フェーズで同一契約のdomain implementationとして追加する。

既存の `TaskEvidence`、`GuidedExecution`、`GitHubEvidenceDecisionService`、Study Practice / Recall、`NativeAiRun` は破棄せず、新しいIntelligence境界へ段階的に接続する。

将来自前推論へ活用できる主要データ関係は単なる会話ログではなく、

```text
State before
→ Decision
→ Action
→ Outcome
→ State after
```

とする。V53.0では契約のみ固定し、永続化schema・OpenAI routing・Study/Developer readiness・自動Task mutation・新UIは導入しない。

詳細は `docs/V53.0_INTELLIGENCE_CONTRACT.md` を正とする。


## V53.1 State / Evidence Foundation

V53.0で定義したIntelligence contractを、既存Evidenceと永続Stateへ接続する。

既存 `TaskEvidence` を新しい生Evidence tableへ複製せず、`TaskEvidenceAdapter` で `EvidenceObservation` へ正規化する。任意metadataはそのままIntelligenceへコピーせず、score / weakness / CI stateなど、判断意味が明確な既知fieldだけを境界越しに渡す。

Studyを最初のvalidation domainとし、`StudyStateBuilder` はPractice / Recall Evidenceから以下をStateとして構成する。

- Evidence count
- Practice attempt count
- Recall review count
- latest / average / best score
- observed strengths / weaknesses

Task progressはStudy Stateのsource of truthとして取り込まない。

fingerprintは二層に分ける。

```text
state_fingerprint
= semantic State
= capturedAtを含まない

state_reference
= one observed snapshot
= semantic State + capturedAt
```

これにより同一snapshot retryはidempotentにしつつ、同じStateを後で再観測した履歴は別snapshotとして保存できる。

`intelligence_state_snapshots` はnormalized metrics / facts / Evidence traceだけを保持し、questions / answers / provider payload / raw webhook / arbitrary free textを既定では保存しない。

Evidence traceはexact normalized Evidence hashと既存source record originの両方を保持し、判断の再現性とauditabilityを両立する。

V53.1ではReadiness / Decision / Next Actionはまだ計算しない。詳細は `docs/V53.1_STATE_EVIDENCE_FOUNDATION.md` を正とする。


## V53.2 Decision & Readiness Engine

V53.1で正規化・永続化した `StateSnapshot` を入力に、説明可能なReadinessとDecisionを deterministic に生成する。

Study Readiness V1は最終的な合格確率ではなく、現在Canoviaが持つEvidenceから判断できる範囲の準備状態を表す。

Evidence不足時は数値を捏造しない。

```text
Practice Evidenceなし
→ readiness score = null
→ level = unknown
→ confidence = 0.20
→ baseline Evidence収集をDecision
```

Practice Evidenceがある場合はlatest / average scoreを中心に、観測弱点を小さく補正し、practice回数とrecall Evidence量からconfidenceを決める。

Study V1のGap code:

- practice_evidence_missing
- practice_evidence_thin
- mastery_below_target
- retention_unverified
- observed_weaknesses

Readyは以下をすべて満たす場合のみ。

- readiness score >= 80
- practice attempt >= 3
- recall Evidence >= 1
- observed weaknessなし

Decisionは候補を先に生成し、priority → confidence → stable type順で決定する。

主なcandidate:

- collect_baseline_evidence
- reinforce_observed_gap
- expand_practice_sample
- verify_retention
- advance_scope
- continue_observation

Decision inputはsemantic Stateだけでなくexact State snapshot referenceをfingerprintへ含める。同じStateを後で再観測した場合は新しいDecision traceとして履歴を残し、同一snapshot retryはidempotentにする。

`intelligence_decision_traces` へState / Readiness / Decision関係を保存し、将来の

```text
State before
→ Decision
→ Action
→ Outcome
→ State after
```

の評価・自前モデル用データ基盤につなげる。

V53.2ではOpenAIを使用しない。V53.3 Reasoning Routerでdeterministic / domain rule / OpenAI / future Canovia modelを同じDecision境界の後ろで選択可能にする。

詳細は `docs/V53.2_DECISION_READINESS_ENGINE.md` を正とする。


## V53.3 Reasoning Router

V53.2のdeterministic Decisionをbaseline / fallbackとして維持したまま、provider-neutralなReasoning Routerを追加する。

```text
State
→ Readiness
→ deterministic candidates
→ baseline Decision
→ Reasoning Router
  ├ deterministic
  ├ OpenAI
  └ future Canovia model
→ selected Decision
```

default modeは `deterministic` とし、mergeだけでOpenAI traffic/costを増やさない。

`openai` modeでは既存 `FeatureAccessService -> AutomaticAiExecution` を権限境界として再利用し、独自Premium判定を作らない。

`auto` modeはcandidateが複数存在し、baseline confidenceが設定閾値以下の場合だけmodel escalation候補とする。

OpenAIは自由にTask/Actionを発明せず、Canoviaがdeterministicに生成したcandidate listから1件だけ選択する。Structured Outputの `selected_type` 自体をcandidate enumへ制約する。

最終Decision confidenceは、

```text
min(candidate confidence, provider confidence)
```

とし、modelがEvidence由来confidenceを上回らない。

provider障害、権限不足、未設定、contract mismatch時はdeterministic baselineへfallbackする。fallbackは観測するがsuccessful reasoning cacheとしては扱わず、provider復旧後の再試行を妨げない。

successfulなexact reasoning requestはrequest fingerprintで再利用し、同じState/Readiness/candidateに対するOpenAI二重実行を防ぐ。

`intelligence_reasoning_runs` へ以下を保存する。

- mode / route
- provider / model
- baseline / selected Decision type
- baseline / selected confidence
- baseline agreement
- fallback reason
- latency
- NativeAiRun link
- token usage
- optional estimated USD cost
- linked Decision trace

provider pricingはhard-codeせず、環境設定されたinput/output token単価がある場合だけestimated costを計算する。

baseline agreementは品質そのものではなくevaluation signalであり、最終品質は将来 `Decision -> Action -> Outcome -> State delta` で評価する。

自然会話AIとDecision reasoningは別責務のまま維持する。

詳細は `docs/V53.3_REASONING_ROUTER.md` を正とする。


## V53.4 Study Scope Capture

Studyの最初の獲得導線として、テスト範囲の画像・スクリーンショット・PDFからreviewableなStudy Scopeを作る。

```text
画像 / スクショ / PDF
→ Structured Extraction
→ Human Review
→ Confirmed Study Scope
```

対象は資格試験だけに限定しない。Plan Category Profileが `study` のPlanを対象とし、定期テスト・学校の試験・大学試験等も扱う。

Study Scope CaptureはFree core capabilityとして `study_scope_capture` を追加する。テスト範囲を読み取る入力境界はCanovia本体の価値であり、Premium専用にはしない。高頻度Reasoningや長期分析は別Capabilityで扱う。

元ファイルは既存private `InboxItem` storageを再利用し、Study専用の別ファイル保存基盤を作らない。

`StudyScopeCapture` はAI draft / review lifecycleを保持し、`StudyScopeItem` はHuman Confirmation済みの範囲だけを保持する。

AI抽出対象:

- exam title
- source date text
- normalized exam date
- subject
- unit
- range text
- page start / end
- short source excerpt
- confidence
- ambiguities

AIは資料にない科目・単元・ページ・日付を補完しない。年が不明な「10月15日」等は `exam_date_text` へ残し、`exam_date` はnullのままHuman Reviewへ渡す。

Human Confirmation前は、

- StudyScopeItemを作らない
- Taskを作らない
- Task progressを変えない
- Intelligence Stateを変えない

Human Confirmation後だけconfirmed scope factsとして保存し、V53.5はこのconfirmed dataだけを入力に使う。

Native AIが失敗・未設定でも元ファイルは保持し、手動で科目・単元・範囲・ページ・日付を入力して確定できる。Captureがprovider availabilityに依存して消失しないことを保証する。

全文OCR transcriptは永続化せず、構造化範囲・短い根拠抜粋・曖昧点だけを保存する。

Planの学習カテゴリには「試験範囲」導線を追加し、元ファイル確認、抽出信頼度、曖昧点、範囲行追加/削除、再解析、確認・修正・確定を1画面で行える。

詳細は `docs/V53.4_STUDY_SCOPE_CAPTURE.md` を正とする。


## V53.5 Study Intelligence

Human-confirmed Study Scopeと既存Practice / Recall Evidenceを接続し、試験範囲に対する現在状態をdeterministicに計算する。

```text
Confirmed Scope
+ Practice Evidence
+ Recall Evidence
+ deadline context
→ scope-level observation
→ Coverage / Mastery / Retention
→ Remaining Effort / Deadline Pressure
→ Exam Readiness
```

Task progressはStudy Stateのsource of truthとして使用しない。

Coverageは、confirmed scopeのうちPractice / Recall Evidenceを安全に紐付けられた範囲の割合とする。unitまたは特徴的range tokenの一致を強いSignalとし、subject-only一致は同subjectに1範囲しかなくunitもない場合だけ許可する。例えば「数学を勉強する」というgeneric Taskを、二次関数・図形の両方へ自動展開しない。

Masteryはscopeへ紐付いたPractice scoreから算出し、latest 65% + average 35%を基準とする。scopeに一致するweaknessだけをbounded penaltyとして扱う。

RetentionはRecall rating / mastered stateから算出する。again=20 / hard=50 / good=80 / easy=95 / mastered=100をV1 mappingとし、複数観測はlatest 65% + average 35%でまとめる。

SpeedはV53.5では未計測とする。現在のStudyPracticeSession started_at/completed_atは解答時間だけでなく採点・provider latency・結果確認等を含み得るため、解答速度として利用しない。

残り学習量は偽の分数精度を出さず、confirmed scope 1件を1 Study Unitとする相対負荷で表す。Evidence / Mastery / Retentionが揃うほどremaining unitを減らし、remaining units / remaining percent / units per day / deadline pressureを出す。将来、authoritative duration dataが十分に溜まった時点でUnits→時間へ校正可能にする。

試験日は、1つのconfirmed exam dateがあればそれを使い、なければPlan deadlineをfallbackとする。複数confirmed Captureのexam dateが食い違う場合は `exam_date_conflict=true` とし、Canoviaが勝手にどちらかを選ばない。

Exam Readiness V1:

```text
Coverage × 0.35
+ Mastery × 0.45
+ Retention × 0.20
```

Readyの目安はCoverage>=85 / Mastery>=80 / Retention>=70 / total>=80 / remaining effort<=25% / deadline pressureがhigh・overdueでないこと。Speedはscoreへ含めず、未計測中はconfidenceを上限0.85へ抑える。

Study Intelligence StateSnapshotは、Scope Human Confirmation / Practice assessment Evidence / Recall review Evidenceが増えた時に更新する。GET表示ではephemeralに再評価し、閲覧のたびにsnapshotを量産しない。

V53.5以降、V53.4のHuman Confirmation後はconfirmed scopeだけをIntelligence StateへProjectionする。AI Draftは引き続きStateへ入れない。Task生成やTask progress mutationも行わない。

Study Activity / AI Practice / Recall / Recall candidateはexact `category === 資格学習` 判定を廃止し、Plan Category Profileの `study` 全体へ拡張する。AP専用profileは維持し、generic profileは `generic_study / 学習・試験` とする。

Study Scope画面にはV53.5検証用の最小diagnosticとしてReadiness / Coverage / Mastery / Retention / Speed未計測 / 残りStudy Units / deadline pressureを表示する。最終Intelligence UXはV53.9、Next ActionはV53.6で扱う。

詳細は `docs/V53.5_STUDY_INTELLIGENCE.md` を正とする。


## V53.6 Adaptive Study Action

V53.5のStudy State / Exam Readinessを、現在の一つのActionへ変換する。

```text
Study State
→ Exam Readiness / Gap
→ Decision
→ Current Action
→ Execute
→ Evidence
→ State / Action refresh
```

TaskはIntelligenceのsource of truthではなく、必要時の実行Projectionとして扱う。

Study Decision V1はconfirmed scope、scope別remaining unit、Coverage、Mastery、Retention、deadline pressure、exam-date conflictからcandidateを生成・順位付けする。主なcandidateは `capture_scope / reduce_deadline_risk / establish_scope_baseline / reinforce_scope_mastery / verify_scope_retention / resolve_exam_date / maintain_readiness / continue_study`。

`StudyAdaptiveActionGenerator` は選択Decisionから一つのCurrent Actionを生成し、title / why-now intent / confidence / success signals / target scope / target Task / execution routeを保持する。Actionに時間を捏造せず、estimatedMinutesはnullとする。

`intelligence_action_projections` はCurrent Actionと変化履歴を永続化する。same semantic State + same semantic Actionは再表示しても同一Actionとして再利用し、判断が変わった時だけ旧Actionを `superseded`、新Actionを `active` とする。

Task作成はAction生成時には行わない。

```text
Action
├─ 合う未完了Taskあり → 既存Taskを再利用
├─ Scope / 日付確認 → Study Scopeへ
└─ 合うTaskなし → ユーザーが実行を選んだ時だけTaskへProjection
```

新規Projection Taskはprogress=0、estimated/remaining minutes=0から開始する。これは時間が0分という推定ではなく、V53.6が根拠のない時間を生成しないためのlegacy Task shape上の値である。Task作成直後はState/Decision/Actionを再評価し、「Task化が必要」という古いActionを残さない。

Scope Human Confirmation、Practice assessment、Recall reviewはStateだけでなくCurrent Actionまでrefreshする。Primary mutationが成功した後のIntelligence projection失敗は元操作をrollbackしない。

Homeは段階的にAction-firstへ移行する。現在最優先のPlanがStudyなら同Planの旧Task guidanceよりStudy Intelligence Actionを先頭に置き、Readiness / confidence / なぜこのActionかを表示する。他Plan候補は残す。Taskをまだ持たないStudy PlanでもPlan priorityが本当に上位ならActionを表示できる。完了済みStudy Planには新Actionを表示しない。他domainはV53.7以降のdomain intelligence実装まで既存task-based guidanceを維持する。

Study ScopeではCurrent Action、why-now、Readiness、confidence、最近のAction変化を確認できる。

V53.6のStudy Action policyはdeterministicで、mergeにより新しいOpenAI traffic/costを発生させない。

詳細は `docs/V53.6_ADAPTIVE_ACTION.md` を正とする。


## V53.7 Developer Evidence Sync

DevelopmentではGitHub activityをTask進捗へ直接変換せず、まずauthoritative Evidenceとして正規化する。

```text
signed GitHub webhook
→ minimal routing
→ GitHub App REST authoritative re-fetch
→ explicitly linked Artifact / Task
→ TaskEvidence
→ EvidenceObservation
→ V53.8 Development State
```

Webhook payloadは「変化があった」というtriggerに限定し、Issue / Branch / Commit / PR / Review / CI / Merge / Deploymentの実状態はGitHub App経由で再取得する。raw webhook bodyをIntelligence truthへ入れない。

V53.7で扱うDevelopment Evidence:

- `pull_request_observed`
- `pull_request_review_submitted`
- `pull_request_ci_observed`
- `pull_request_merged`
- `github_issue_observed`
- `github_branch_observed`
- `github_commit_observed`
- `github_deployment_observed`

非PR eventは、同じRepository / installationへ接続済みで、対象GitHub ArtifactがTaskへ明示linkされている場合だけ同期する。Repository全体のactivityをPlan内の全Taskへ展開しない。

Issueはnumber/state/state reason/locked/assignee count、Branchはname/head SHA/protection、CommitはSHA/親数/署名確認、DeploymentはID/SHA/ref/environment/status/production flag等のbounded factだけを扱う。Issue本文・title、commit message、diff/files、source code、Deployment payload/description等はIntelligenceへ取り込まない。

GitHub Appの新しい自動観測には対応するpermission/event subscriptionが必要で、IssueはIssues read、Branch/CommitはContents read、DeploymentはDeployments readを利用する。既存PR/Review/CI権限境界は維持する。

`FeatureAccessService -> DeveloperGithubEvidence` をそのまま利用し、V53.7独自のPremium判定を作らない。新しい非PR同期ではEntitlement確認前にremote APIを読まない。

GitHub eventはEvidenceであり進捗ではない。

```text
Commit observed ≠ 50%
PR merged       ≠ 100%
Deploy success  ≠ requirement complete
```

Task status / progress / remaining_minutes / Canovia workflow laneはV53.7では変更しない。

`DevelopmentEvidenceCollector` をV53.8の入力境界とし、Webhook / GitHub REST / provider payloadの内部事情をDevelopment State Builderへ漏らさない。

詳細は `docs/V53.7_DEVELOPER_EVIDENCE_SYNC.md` を正とする。


## V53.8 Developer Readiness

V53.7で正規化したDevelopment Evidenceを、Task単位のRelease State / Readiness / Decision / Current Actionへ変換する。

```text
Development Evidence
→ same-Task Quality Gates
→ Release Readiness
→ largest Gap
→ Decision
→ Current Action
```

V1 Quality Gateは以下の7つとする。

- implementation
- CI / automated test
- review
- merge
- production deploy
- verification
- specification synchronization

Readiness scoreのweightは20 / 20 / 15 / 15 / 15 / 10 / 5。ただしscoreだけではReadyにしない。7 Gateすべてがpassedの場合だけReadyとし、failed Gateが1つでもあればBlocked、それ以外はDevelopingとする。Development Evidenceがなければscore=null / Unknown。

Release Gateは必ずTaskごとに相関させる。別TaskのCI / Deploy / Verificationを寄せ集めて1つのReady判定を作らない。

Task progress / status / remaining_minutesはDevelopment Stateのsource of truthにしない。

Reviewはreviewerごとの最新APPROVED / CHANGES_REQUESTEDを匿名化したkeyで追跡し、未解決のCHANGES_REQUESTEDが1件でもあればReview Gateをfailedとする。コメントだけで既存Approvalを消さない。

Production Deployだけをrelease deploy passedとして扱い、staging成功はpendingとする。Deployment SHAが現在の実装/release SHAと一致しなくなった場合、古いDeployはstaleとしてpendingへ戻す。

実機・本番確認と仕様同期はGitHubから推測せず、`development_quality_gate_confirmed` のHuman Confirmationだけを使う。

Verificationは現在のProduction Deployment ID + deployed SHAへbindする。新しいDeployが出たら以前のVerificationはstaleとなり再確認が必要。

Spec Syncは現在の実装/release SHAへbindする。新しいCommit/SHAへ変わったら以前のSpec Syncはstaleとなる。`not_required`も明示的なHuman Decisionとしてのみ扱う。

新しいimplementation SHAを観測した場合、旧CI / Review / Merge / Deploy等のdownstream release stateを新しい変更へ自動継承しない。PR headからmerge commitへの正当なmerge transitionだけは、直前headで成立したCI / Reviewを保持する。

Development Decision / Actionはdeterministic baselineとし、V53.8による新しいOpenAI trafficは発生させない。

Current ActionはGitHub webhook、manual GitHub Return、Verification / Spec Sync confirmation後に再評価する。同じsemantic State + Actionは既存Action projectionを再利用し、State変化で判断が変わった場合だけsupersedeする。

GitHub WorkflowにはV53.8診断SurfaceとしてRelease Readiness / confidence / Release candidate Task / Current Action / 7 Gateを表示する。最終的なStudy / Development共通Intelligence UXはV53.9で整理する。

詳細は `docs/V53.8_DEVELOPER_READINESS.md` を正とする。


## V53.9 Intelligence UX

Study / Developmentのdomain-specific Intelligenceを、共通Presentation contractでHomeと詳細Surfaceへ出す。

正規の表示順序:

```text
Current State
→ Readiness
→ Biggest Gap
→ Current Action
→ Why now?
```

Task / Evidence / Decision historyは消さず、判断の根拠を確認する詳細として残す。Task treeをユーザーが維持し続けないとCanoviaが現在状態を理解できない設計には戻さない。

`PlanIntelligencePresentation` をStudy / Development共通のUI境界とし、domain固有ruleはAdapter側へ閉じ込める。shared Blade surfaceはStudyのMastery ruleやDevelopmentのRelease Gate ruleを解釈しない。

Homeは `IntelligenceHomeActionService` で最大1 PlanだけをIntelligence評価する。既存Dashboard Guidanceの最優先PlanがStudy/DevelopmentならそのPlanを使い、別のIntelligence Planがtask guidanceを置き換えるのは既存Plan priority上で本当に上位の場合だけとする。

V53.6の暫定ルール「全current Task完了ならStudy Home Intelligenceを出さない」はV53.9で撤回する。

```text
all current Tasks done
≠ Goal achieved
≠ Exam Ready
≠ Release Ready
```

Plan自体の明示的completion conceptが将来必要なら、Task全完了とは独立してmodelingする。

Home Intelligence cardはPlan / Current Action / Readiness / Current State / Biggest Gap / CTA / Whyを表示し、そのPlanの重複Task guidanceは同時に先頭へ出さない。他Plan候補は既存horizontal guidanceとして残す。

Study detailは共通Summaryの下にconfirmed scope / remaining Study Units / deadline pressure / Speed未計測 / priority scope等を残す。Development detailは共通Summaryの下に7 Release Quality GatesとVerification / Spec Sync確認を残す。

判断履歴には既存 `intelligence_state_snapshots` / `intelligence_decision_traces` / `intelligence_action_projections` を再利用し、新しいhistory tableは作らない。表示はbounded summary / Evidence countに限定し、raw provider payloadやprivate Evidence本文を出さない。

Study ActionのTask projectionは引き続きexplicit/user-triggered。閲覧だけではTaskを作らない。Development V53.9も自動Task生成を追加しない。

Home offline snapshotはdomain / action kind / title / intent / readiness score / gap labelだけを保持し、State全体・Decision trace・Evidence referencesはserialiseしない。

V53.9はpresentation/orchestration layerであり、新しいOpenAI trafficやReasoning Router escalation policyを追加しない。

V53シリーズ完了後の共通プロダクトモデル:

```text
Goal
→ State
→ Readiness / Gap
→ Decision
→ Action
→ Evidence
→ State update
```

Taskは、このloopを実行・可視化・相関するためのoptional durable projectionであり、Intelligence source of truthではない。

詳細は `docs/V53.9_INTELLIGENCE_UX.md` を正とする。


## V54.0 Workspace Mode Contract

V53で共通化したIntelligence Coreの上に、目的別のWorkspace Modeを置く。

```text
State / Evidence / Readiness / Decision / Action
                    ↓
          Intelligence Presentation
                    ↓
              Workspace Mode
        ↙             ↓             ↘
    Overview         Study      Development
```

Workspace ModeはPlan categoryそのものではない。

既存Plan category profileは `study / development / career / creative / general` を維持する。V54.0で公開するWorkspace Modeは `overview / study / development` の3つだけとし、career / creative / generalは専用Modeを実装するまでOverviewへフォールバックする。

固定Mode UIは今後増えるMode数に依存して横幅が増えないよう、横並びタブではなくdropdownを前提とする。Mode optionはBladeへ直書きせず `WorkspaceModeRegistry` を正とする。

各Mode definitionは以下を持つ。

- stable Mode key
- label
- semantic icon key
- description
- accent tone
- home strategy
- supported Plan profile keys
- semantic navigation keys
- empty-state title / description / action key

Study navigation contractは Current Action / Readiness / Study Scope / Practice / Recall / History、Developmentは Current Action / Release Readiness / GitHub / Evidence / Historyを基本とする。

`WorkspaceModeResolver` のV54.0 precedence:

```text
explicit caller Mode
→ strong domain route hint
→ current Plan profile
→ Overview
```

Plan routeだけでなくTask / WorkSessionからも関連Plan profileを解決できる。

V54.0ではmanual Mode choiceを永続化しない。V54.2でユーザー明示選択を保存し、最終的には「ユーザー明示選択 > 文脈推定 > Overview」を安定したUXとして実装する。

V54.0はV53 Intelligence計算、Task progress、Plan category、課金、AI trafficを変更しない。

詳細は `docs/V54.0_WORKSPACE_MODE_CONTRACT.md` を正とする。


## V54.1 Fixed Workspace Mode Bar

Canoviaの全体App Shellへ、現在のWorkspaceを常時確認・切替できる固定Mode Barを追加する。

横並びタブではなくdropdownを採用する。今後Workspace Modeが増えてもヘッダー横幅が増えないことを優先する。

Mode optionはBladeへ直書きせず、`WorkspaceModeRegistry` をsingle source of truthとする。

現在の公開Mode:

- Overview
- Study
- Development

固定Barは既存sticky headerの2段目として配置する。Desktop / Mobileとも同じsemantic partialを利用し、Focus Modeでは通常App Shellごと非表示にする。

Mode切替Entry:

`GET /workspace/mode/{workspaceMode}`

V54.1では選択を永続化しない。

- Overview → Home
- Study → accessibleなStudy PlanがあればStudy Scope / Intelligence surface
- Development → accessibleなDevelopment PlanがあればGitHub / Development surface
- 対象Planなし → `workspace_mode` query付きHomeへ一時的に戻す

対象Plan選択はPlan priority → deadline → Plan IDの順でdeterministicに決める。

`?workspace_mode=study` / `development` はそのrequestだけのephemeral explicit contextであり、User/DB/session/localStorageへ保存しない。永続化はV54.2の責務とする。

V54.1 resolver precedence:

```text
explicit caller Mode
→ valid ephemeral workspace_mode query
→ strong domain route hint
→ current Plan profile
→ Overview
```

Instant Navigationではglobal headerがDOMに残るため、fragment metadataにWorkspace Modeを含め、page replacement後にlabel / icon / active option / data attributeを同期する。

Mode BarはPlan filterではなくWorkspace navigationである。Task progress、Plan category、V53 Intelligence calculation、課金、AI trafficは変更しない。

詳細は `docs/V54.1_FIXED_WORKSPACE_MODE_BAR.md` を正とする。


## V54.2 Mode Context & Persistence

Workspace Modeの手動選択を永続化する。ただし、保存されたModeによって明確なPlan/deep-linkの意味を上書きしない。

Preference state:

```text
null → automatic
overview | study | development → manual preference
```

Authenticated userは `users.workspace_mode_preference`、GuestはLaravel session `canovia_workspace_mode_preference` を使用する。public Mode keyの正当性は `WorkspaceModeRegistry` を基準に検証し、localStorage/cookieへ同じ状態を複製しない。

V54.2 resolver precedence:

```text
explicit caller Mode
→ valid ephemeral workspace_mode query
→ strong domain route hint
→ current Plan / Task / WorkSession profile
→ persisted manual preference
→ Overview
```

このprecedenceは「ユーザーの固定を無視する」ためではなく、具体的なdeep linkのsemantic truthを守るためのもの。たとえばDevelopmentを固定した状態でStudy Planを開いた場合、その画面ではStudyを表示するが、保存済みDevelopment preferenceは維持し、Homeへ戻ればDevelopmentへ復帰する。

V56.12ではこの「Homeへ戻れば固定Modeへ復帰」をapp再開にも適用する。root `/` が `manual_preference` を解決した場合は、generic Action Homeを表示したままMode Barだけ固定表示にせず、対応するcanonical Workspace Homeへredirectする。

```text
manual overview     → /workspace/overview
manual study        → /workspace/study
manual development  → /workspace/development
manual career       → /workspace/career
automatic / null    → /  (Action Home)
```

`?workspace_mode=...` のexplicit context、strong domain route hint、Plan / Task / WorkSession profileは従来どおりmanual preferenceより強い。したがって明示contextを開いたことを理由に保存済みpreferenceは消さない。

Mode Barの通常選択は `POST /workspace/{workspaceMode}/select` で保存し、`DELETE /workspace/preference` でautomaticへ戻す。V54.1の `GET /workspace/mode/{workspaceMode}` はephemeral navigationとして残す。

Mode Barはcurrent Modeとは別にstored preferenceを保持し、表示上は `固定中` / `画面に追従` / `Planに追従` / `自動` を区別する。Instant NavigationでもMode sourceとvisible context labelを同期し、retained headerの表示が古くならないようにする。

V54.2はV53 Intelligence、Task progress、Plan category、課金、AI trafficを変更しない。

詳細は `docs/V54.2_MODE_CONTEXT_AND_PERSISTENCE.md` を正とする。


## V54.3 Study Workspace

Study Modeに専用Homeを追加し、Modeを単なる表示切替ではなく目的別の作業面として成立させる。

Canonical route:

```text
GET /workspace/study
→ workspace.study.index
```

Study Mode選択後はStudy Scopeへ直行せず、Study Homeへ入る。

Study Homeの主表示順:

```text
Exam Readiness
→ Biggest Gap
→ Current Action
→ Coverage / Mastery / Retention / Remaining Load
→ Scope / Practice / Recall / History
```

数値・Gap・Actionは新しく計算しない。既存V53 Study Intelligence / Adaptive Action / Presentation Adapterをそのままauthorityとして使用する。

Study Planが複数ある場合は既存Plan priority semanticsを使い、priority → deadline → Plan IDでdefault Planを決定する。ユーザーは `?plan_id=...` で別のaccessible Study Planを明示選択できる。明示PlanがinaccessibleまたはStudy profileでない場合は404とする。

Study Planが存在しない場合もgeneric Homeへ戻さず、Study Workspace内で「学習Planを作る」empty stateを表示する。

Study Planは存在するがconfirmed Scopeがない場合はcapture-firstとし、numeric Readinessを意味のある値のように表示しない。

```text
Study Scope Capture
→ Human Confirmation
→ Practice / Recall Evidence
→ Study State
→ Exam Readiness
→ Gap
→ Current Action
```

confirmed Scope後は既存Stateから以下を表示する。

- Exam Readiness / state / confidence
- exam date / days until exam
- deadline pressure
- Coverage
- Mastery
- Retention
- Remaining Load
- remaining Study Units
- priority remaining Scope

Study-specific navigation:

- Current Action
- Readiness
- Study Scope
- Practice
- Recall
- History

Practice / Recallはcurrent Intelligence target Taskを優先し、なければactive Task、最後に既存Taskを使う。Taskがなければ導線をdisabled表示し、GET表示だけでTaskを自動生成しない。

`workspace.study.*` はstrong Study route hintとして扱い、V54.2のmanual preferenceが別ModeでもStudy Homeのsemantic contextをStudyとして表示する。保存済みpreference自体は変更しない。

V54.3は新規AI traffic、Study scoring変更、Task progress変更、Plan category変更、billing変更を行わない。

詳細は `docs/V54.3_STUDY_WORKSPACE.md` を正とする。


## V54.4 Development Workspace

Development Modeに専用Homeを追加し、Release判断に特化したWorkspaceとして成立させる。

Canonical route:

```text
GET /workspace/development
→ workspace.development.index
```

Development Mode選択後はGitHub Workflowへ直行せず、Development Homeへ入る。

主表示順:

```text
Release Readiness
→ Biggest Release Gap
→ Current Action
→ Quality Gates
→ GitHub / Evidence / History
```

Release scoringやGap判定を新設せず、既存V53.7 / V53.8 Development Intelligence / Adaptive Action / Presentation Adapterをauthorityとして使用する。

Development Planが複数ある場合は既存Plan priority semanticsで priority → deadline → Plan ID の順にdefault Planを決定する。ユーザーは `?plan_id=...` で別のaccessible Development Planを明示選択できる。inaccessible / non-Development / invalid Plan IDは404とする。

Development Planがない場合もOverviewへ戻さず、Development Workspace内で「開発Planを作る」empty stateを表示する。

Release Evidenceがまだない場合はGitHub-firstとし、numeric Release Readinessを意味のある値のように表示しない。

```text
GitHub / Task link
→ authoritative GitHub Evidence
→ Development State
→ Release Readiness
→ Gap
→ Current Action
```

Release Candidateが観測された後は既存Readinessから以下を表示する。

- Release Readiness / state / confidence
- passed / failed / pending / unknown Gate counts
- Biggest Release Gap
- Current Action
- Release Candidate Task
- PR number
- branch
- bounded short SHA
- deployment environment
- stale Verification / Spec Sync warnings

Quality Gate summary:

- implementation
- CI / Test
- Review
- Merge
- Production Deploy
- Verification
- Spec Sync

Development Home上ではGateをread-onlyで表示する。GitHub接続、Evidence管理、Verification / Spec Sync確認などのmutationは既存GitHub Workflow / Execution surfaceへ送る。

Development-specific navigation:

- Current Action
- Release Readiness
- Quality Gates
- GitHub
- Evidence
- History

`workspace.development.*` はstrong Development route hintとして扱い、別Modeのmanual preferenceが保存されていてもDevelopment HomeではDevelopment contextを表示する。保存済みpreference自体は変更しない。

Workspace GETではGitHub API request、Task / Artifact / Evidence生成、Intelligence history保存を行わない。

V54.4は新規AI traffic、Release scoring変更、Plan category変更、billing変更を行わない。

詳細は `docs/V54.4_DEVELOPMENT_WORKSPACE.md` を正とする。


## V54.5 Overview Workspace

Overview ModeはStudy / Developmentの専門Workspaceとは異なり、Canovia全体の薄い司令塔として扱う。

Canonical route:

```text
GET /workspace/overview
→ workspace.overview.index
```

Overviewで答える問いは4つだけとする。

```text
今いちばん優先するActionは何か
Study / Developmentは今どんな状態か
Inboxに何が待っているか
重要な状態変化はあるか
```

### Global Current Action

Overview独自のpriority modelは作らない。

既存 `HomePageDataService` / `IntelligenceHomeActionService` の選定結果をauthorityとして再利用する。

- Intelligence-supported Planが既存Homeルールで最優先ならIntelligence Action
- それ以外は既存Dashboard Guidance
- Plan priority semanticsは既存ルールを維持
- Overview GETではHome projectionをprefetch/non-recording modeで利用

そのためOverview表示だけでは以下を増やさない。

- DashboardViewed telemetry
- RecommendationShown telemetry
- periodic behavior state snapshot
- Intelligence State / Decision / Action history

### Mode summaries

OverviewではStudy / Developmentそれぞれ代表Planを最大1件だけ表示する。

代表Plan:

```text
priority
→ deadline
→ Plan ID
```

Study:

- confirmed Scopeあり → Exam Readiness / State / Biggest Gap / Current Action
- Scopeなし → セットアップ中
- fake numeric Readinessは表示しない

Development:

- focus Release Candidateあり → Release Readiness / State / Biggest Gap / Current Action
- Development Evidenceなし → セットアップ中
- fake numeric Readinessは表示しない

詳細は各専用Workspaceへ送る。

### Inbox

Overviewはcurrent identityに属する `new / review` Inboxだけを扱う。

- pending count
- latest 4 items
- source type
- associated Plan when available
- Inbox CTA

Identity semanticsは既存Inbox / Space Stationと一致させる。

AI routingはOverview表示では実行しない。

### Important changes

Overviewは既存 `ActionHomeProjectionService` signalsを再利用し、最大4件だけ表示する。

対象例:

- pending Plan update
- Plan attention / status change
- collaboration activity

新しいnotification systemは作らない。

V54.6で扱うReadiness before/after feedbackとは分離する。

### Workspace semantics

`workspace.overview.*` はstrong Overview contextとする。

保存済みStudy / Development preferenceが存在してもOverview deep linkではOverviewを表示し、保存済みpreference自体は変更しない。

root `/` HomeはV54.5では置き換えない。

### Performance boundary

Overviewは:

- existing Home projection × 1
- Study Intelligence representative × 最大1
- Development Intelligence representative × 最大1
- Inbox latest × 最大4
- important signals × 最大4

に限定する。

全Study/Development PlanをIntelligence評価しない。

V54.5は新規AI traffic、GitHub API traffic、scoring変更、Task進捗変更、billing変更を行わない。

詳細は `docs/V54.5_OVERVIEW_WORKSPACE.md` を正とする。


## V54.6 State Change Feedback

V54.6は、Evidence追加によってCanoviaの判断がどう変化したかをユーザーへ返すread-only feedback layerである。

新しいevent storeやAI説明生成は作らず、既存の永続Intelligence履歴を比較する。

Canonical comparison:

```text
previous persisted Decision Trace
+ previous State / Action
→ compare
current persisted Decision Trace
+ current State / Action
→ State Change Feedback
```

Feedbackを表示するのは次のいずれかが成立するときだけ。

- Readiness levelが変わった
- Decision reason / Biggest Gapが変わった
- Current Action fingerprintが変わった
- Readiness scoreが5pt以上変化した

5pt未満のscore-only変化は表示しない。履歴をフィード化せず、ユーザーの理解や次の行動に意味がある差分だけを返す。

表示可能な内容:

- Readiness before → after
- score delta
- State level before → after
- Decision / Gap before → after
- Current Action before → after
- 新規Evidence件数
- Evidence種別ラベル

Evidence種別ラベル例:

- Practice結果
- Recall確認
- 作業実績
- Commit
- Branch
- Issue
- Pull Request
- CI / Test
- Review
- Production Deploy
- Quality Gate確認

以下はState Change Feedbackへ出さない。

- raw provider payload
- commit message
- issue / PR本文
- code diff
- 学習回答本文
- raw Evidence metadata

Study WorkspaceではExam Readiness直後、Development WorkspaceではRelease Readiness直後に最新1件を表示する。

Overviewでは代表Study Plan / Development Planから最新の意味ある変化を最大2件だけ表示する。Overviewを履歴feedにはしない。

Feedbackは既存mutation flowが `StudyAdaptiveActionService::refresh / tryRefresh` または `DevelopmentAdaptiveActionService::refresh / tryRefresh` を実行して永続化した履歴だけを対象とする。

Workspace GETは履歴を作らず、live evaluationを過去Stateとして扱わない。

V54.6は新規AI traffic、GitHub API traffic、Readiness formula変更、Task進捗変更、billing変更を行わない。

詳細は `docs/V54.6_STATE_CHANGE_FEEDBACK.md` を正とする。


## V54.7 Mode-specific Onboarding

V54.7は、Study / Developmentの専門Workspaceへ初めて入ったユーザーに「このWorkspaceを有効にするために次に何が必要か」を示すsetup layerである。

Canovia全体のFirst-run Gateとは責務を分ける。

```text
Global First-run
→ Canoviaとは何か / 最初の入口

Mode-specific Onboarding
→ このWorkspaceで判断を開始するために何が必要か
```

Mode onboardingに専用の完了フラグは持たない。

以下は追加しない。

- onboarding completed DB column
- localStorage completion state
- dismiss / skip state
- modal / forced tour

代わりに実際のPlan / Scope / Evidenceからstep完了を導出する。

### Registry contract

`WorkspaceModeDefinitionData` は以下を持つ。

- `suggestedPlanCategory`
- `onboardingSteps`

オンボーディングの順序・copy・Action semanticは `WorkspaceModeRegistry` をauthorityとする。

Workspace ModeとPlan categoryは同一化しない。

`suggestedPlanCategory` は、専門Workspaceからユーザーが明示的にPlan作成を開始した場合の初期値に限る。

### Study first route

Study onboarding:

```text
Study Plan
→ confirmed Study Scope
→ first Practice / Recall Evidence
→ normal Exam Readiness / Gap / Current Action
```

Step completion:

- Study Plan存在 → `create_plan` complete
- confirmed Scope存在 → `capture_study_scope` complete
- `study_practice_assessed` または `study_recall_reviewed` が存在 → `record_study_evidence` complete

confirmed Scopeだけではセットアップを完了扱いにしない。

最初のlearning Evidenceが入るまでは、Readinessの数字を主画面として押し出すより「現在地を測る」を優先する。

最初のPractice / Recall Evidenceが存在するとMode onboardingは自動で消え、通常のStudy Workspaceへ移る。

### Development first route

V57.3 supersedes the original Development onboarding sequence.

Current Development onboarding:

```text
Development Plan
→ Developer Home
```

Step completion:

- Development Plan存在 → `create_plan` complete

Plan作成後はGitHub Evidenceを必須setupにしない。Developer Homeが現在Stateから
Current Actionを決め、必要な場合だけGitHub / Execution / Review / Deploy /
Verification / Spec Syncへ導く。

GitHubはState Sensor / Execution Providerであり、Development Workspaceへ入るための
universal prerequisiteではない。

### Shared presentation

Study / Developmentは共通のMode onboarding presentationを使う。

表示:

- Mode identity
- current setup step
- current step explanation
- completed / total
- ordered step rail
- one primary setup CTA

案内自体に「完了」操作は置かない。

Reality / Evidence側が進むことで案内が消える。

### Mode-aware Plan creation

専門WorkspaceからPlan作成する場合は、既存のdirect Plan formを使う。

```text
Study
→ /plans/create/manual?workspace_mode=study

Development
→ /plans/create/manual?workspace_mode=development
```

既存routeの意味:

```text
/plans/create
→ conversational Goal Discovery

/plans/create/manual
→ direct Plan form
```

専門Workspaceではすでにユーザーがdomainを選択済みなので、Goal Discoveryをもう一度挟まずdirect formを使う。

初期category:

- Study → `資格学習`
- Development → `個人開発`

create formはorigin Modeをhidden contextとして保持する。

作成結果のPlan profileがorigin Modeと一致する場合:

```text
create
→ originating Workspace?plan_id=...
→ next setup step
```

ユーザーがcategoryを変更してprofileが一致しなくなった場合、Canoviaは不正確なWorkspaceへ強制redirectしない。

その場合は既存のgeneric post-create flowへ戻す。

### Overview first-use choice

代表Study PlanもDevelopment Planも存在しない場合だけ、Overviewに「最初は目的に近い入口を選ぶ」surfaceを表示する。

選択肢はRegistryから生成し、Overview自身は除外する。

現在は:

- Study
- Development

このsurfaceはgateではなく、専門Workspaceへのnavigation choiceである。

どちらかの専門Planが存在すれば消える。

### Performance / mutation boundary

V54.7:

- Study → selected Planに対するlearning Evidence `exists()` × 1
- Development → existing evaluated `focus_task_state` を再利用
- Registry → static definitions

全Plan Evidence scanは行わない。

Workspace GETでは:

- Taskを作らない
- Study Scopeを自動importしない
- GitHub接続を開始しない
- AIを呼ばない
- GitHub providerを呼ばない
- onboarding completionを保存しない

詳細は `docs/V54.7_MODE_SPECIFIC_ONBOARDING.md` を正とする。


## V54.8 Workspace Mode Polish / Telemetry / iOS

V54.8はWorkspace Modeシリーズのcloseoutである。

新しいModeやIntelligence authorityを追加せず、V54.0〜V54.7で成立したWorkspace architectureをWeb / PWA / Native iOS境界まで安定化し、最小限の観測可能性を追加する。

### Real-device Mode Bar

Workspace Mode Barは既存sticky app header内のsecond rowを維持する。

別のsticky layerは作らない。

mobile / iOSではMode Bar自身もphysical safe areaを尊重する。

- left: `safe-area-inset-left`
- right: `safe-area-inset-right`
- menu widthはphysical viewport内へ制約
- menu heightは`100dvh`基準で制約
- long menuは内部scroll
- `-webkit-overflow-scrolling: touch`
- `overscroll-behavior: contain`

desktop headerもtop / horizontal safe areaを受けるため、landscape notch / Native shellでも固定chromeがphysical safe areaへ侵入しない。

既存V51.9.8の:

```text
--canovia-mobile-dock-clearance
virtual keyboard handling
mobile tabbar safe area
```

はauthorityのまま維持する。

### Dropdown lifecycle

Mode dropdownのopen/closeはephemeral client UI stateであり、Workspace selection authorityではない。

`workspace-mode-runtime.mjs` は以下だけを担当する。

- desktop/mobileの重複switcherのうち一つだけをopen
- open時にcurrent optionをviewport内へ寄せる
- outside pointerでclose
- Escapeでclose
- Instant Navigation page replacementでclose
- Instant Navigation page-readyでclose / context再確認
- pagehideでclose
- orientation changeでclose
- VisualViewport resizeでclose

これによりiOS rotation、virtual keyboard、PWA navigation、WKWebView navigation後に古いdropdown overlayを残さない。

Workspaceの選択・永続化・resolver precedenceは引き続きserver authorityである。

### Mode telemetry

既存BehaviorEvent基盤へ2種類だけ追加する。

```text
workspace_mode_selected
workspace_mode_auto_context
```

#### workspace_mode_selected

ユーザーがModeを明示選択、または自動判定へresetした操作を観測する。

保存可能metadata:

- `selected_mode`: overview / study / development / auto
- `from_mode`: overview / study / development
- `from_source`: explicit / manual_preference / route_hint / plan_profile / default
- `surface`: web / pwa / native
- `device`: mobile / desktop
- `platform`: ios / android / other

#### workspace_mode_auto_context

manual preferenceではなく、Canovia側のsemantic contextがWorkspaceを決定した状態を観測する。

対象source:

- route_hint
- plan_profile
- default

保存可能metadata:

- `mode`
- `source`
- `surface`
- `device`
- `platform`

Clientはbrowser session中の最後の `mode:source` fingerprintだけをsessionStorageに保持し、同一automatic contextをページごとに重複送信しない。

これはproduct stateではなくtelemetry dedupe専用であり、Workspace preferenceやonboarding completionには使わない。

ServerはBehaviorEventControllerでmetadataを再whitelistする。

以下をMode telemetryへ保存しない。

- Plan title
- Task title
- user text
- URL / raw query
- AI content
- provider payload
- arbitrary client metadata

Telemetry失敗はnavigationをblockしない。

### Instant Navigation synchronization

Instant Navigationはretained shellのMode Barを引き続き同期する。

同期対象:

- body Mode
- body Mode source
- page Mode
- bar Mode / source
- label
- icon
- active option
- current mark
- open dropdown cleanup

このためfull reloadとInstant NavigationでMode表示・telemetry semanticsを分岐させない。

### Native / PWA boundary

V52.0 contractを維持する。

Native:

- Laravel session cookie authority
- persistent WKWebsiteDataStore
- existing CSRF
- PWA Service Workerを登録しない

PWA:

- existing Service Worker / Instant Start

Mode runtimeは既存 `canoviaClientSurface()` / `canoviaClientPlatform()` を利用し、Web / PWA / Nativeを同じBehaviorEvent schemaで比較可能にする。

Native bridgeへPlan / Task / user contentを追加送信しない。

### Performance / mutation boundary

V54.8はWorkspace GETへserver queryを追加しない。

追加client trafficは:

- explicit Mode selection / resetごとに1 event
- automatic Mode contextがbrowser session内で変化したときに1 event

のみ。

V54.8では:

- AI requestなし
- GitHub provider requestなし
- all-Plan queryなし
- Readiness再計算変更なし
- preference semantics変更なし
- billing変更なし

V54.0〜V54.8をもってWorkspace Mode foundation / specialized Workspace / feedback / onboarding / real-device closeoutを完了とする。

詳細は `docs/V54.8_WORKSPACE_MODE_POLISH_TELEMETRY_IOS.md` を正とする。


## V55.0 Career Intelligence Foundation

V55.0は、既存CareerのReality / Evidenceを共通Intelligence Coreへ接続する。

Careerにはすでに以下の構造化された現実データがある。

- Career Capture
- Career Application
- Selection Event
- Interview Review
- interview review / result TaskEvidence

V55.0はこれらを:

```text
Reality / Evidence
→ Career State
→ qualitative Process Readiness
→ Gap
→ Decision
→ Current Action
```

へ接続する。

### Careerは就職成功確率を評価しない

Career Readinessは内定確率・市場価値・候補者評価ではない。

V55.0では必ず:

```text
readiness.score = null
```

とする。

CanoviaがCareer Intelligenceで判断するのは、現在記録されている選考プロセスがどの程度観測可能で、次にどのプロセスActionを処理すべきかまで。

以下は判断しない。

- 内定確率
- 市場価値
- 候補者ランキング
- 企業の良し悪し
- 給与の妥当性
- オファー承諾 / 辞退
- 退職判断
- protected traitに基づく適性

### Intelligence domain

`IntelligenceDomain::Career` を追加する。

既存generic Intelligence tablesをそのまま利用する。

- `intelligence_state_snapshots`
- `intelligence_decision_traces`
- `intelligence_action_projections`

新規migrationは不要。

Career scope:

```text
domain = career
scope_type = career_plan
scope_id = Plan ID
```

### Privacy boundary

Career Intelligence Stateへコピーするのは構造化されたID / enum / date / countのみ。

CareerCapture:

- id
- status
- linked application id
- captured_at

CareerApplication:

- id
- stage
- status
- known result enum
- next_event_at

CareerSelectionEvent:

- id
- application id
- task id
- type / stage / status
- scheduled_at / completed_at
- known result enum
- review status

以下はIntelligence履歴へコピーしない。

- company name
- role title
- company URL
- screenshot
- raw Career Capture text
- selection event notes
- Interview Review answer text
- Review insights free text

`TaskEvidenceAdapter` も `interview_review_completed` / `interview_result_recorded` から安全なIDs・stage・resultだけを正規化する。

### Career State

主なmetrics:

- capture count
- pending capture count
- application count
- active / waiting / offer application count
- scheduled interview count
- review due count
- result waiting count
- completed review count
- Career TaskEvidence count
- hours until next interview

主なfacts:

- pipeline stage counts
- pending capture IDs
- active / preparing application IDs
- offer application IDs
- next interview
- review due
- result waiting event IDs

### Qualitative Process Readiness

Career signalがない場合:

```text
score = null
level = unknown
gap = career_signal_missing
```

Career signalがある場合:

```text
score = null
level = developing
```

Readiness metadata:

```text
policy = career_process_readiness_v1
meaning = career_process_observability_not_employability
```

可能なGap:

- interview_review_due
- pending_capture_unorganized
- application_pipeline_missing

### Career Decision priority

Deterministic policy:

1. 面接後Review
2. 72時間以内の直近面接準備
3. active Offerの条件整理
4. 未整理Career Capture
5. Application未構造化
6. preparing / candidate Applicationの次Action
7. result waitingを含むPipeline確認
8. Career signalなしなら現実情報を1件Capture

LLM reasoningは使用しない。

オファーについてCanoviaが出せるActionは:

```text
オファー条件を整理する
```

まで。

```text
この企業を選ぶ
承諾する
辞退する
```

は出さない。

### Persistence

`CareerAdaptiveActionService` が既存generic storesを利用する。

```text
CareerPlanIntelligenceService
→ CareerDecisionEngine
→ CareerActionGenerator
→ StateSnapshotStore
→ DecisionTraceStore
→ ActionProjectionStore
```

GET Career Workspaceでは履歴を作らない。

Career mutationが成功した後だけfail-safeに `tryRefresh()` する。

対象:

- Capture create / delete
- Application create / update
- Capture link
- Selection Event create / cancel
- Selection result
- completed Interview Review

共通 `CareerCaptureService` にrefreshを置くため、Career Workspace直入力だけでなくInboxからCareer Captureへroutingした場合も同じIntelligence loopへ入る。

### V55.0 boundary

V55.0ではCareerをまだPublic Workspace Modeへ追加しない。

既存Career UIはそのまま維持する。

次のV55.1で、V54のWorkspace Mode registry / persistence / onboarding / telemetry契約へCareerを載せる。

詳細は `docs/V55.0_CAREER_INTELLIGENCE_FOUNDATION.md` を正とする。


## V55.1 Career Workspace Mode

V55.1でCareerをV54 Workspace Mode systemへ正式追加する。

Public Workspace Modes:

```text
Overview
Study
Development
Career
```

Careerのreasoning authorityはV55.0 Career Intelligenceをそのまま使う。

```text
Career Reality / Evidence
→ Career State
→ qualitative Process Readiness
→ Gap
→ Decision
→ Current Action
```

### Canonical Career Workspace

```text
GET /workspace/career
route = workspace.career.index
```

責務:

- representative Career Plan選択
- Process Readiness
- Biggest Process Gap
- Current Action
- latest State Change
- Career Intelligence history
- existing Career operational surfaceへの導線

既存:

```text
/plans/{plan}/career
route = plans.career.index
```

は以下のoperation authorityとして残す。

- Career Capture
- Application pipeline
- interview scheduling
- result recording
- Interview Review

つまり:

```text
Career Workspace
→ 判断面

plans.career.index
→ 操作面
```

### Registry / Resolver

`WorkspaceMode::Career` を追加する。

Registry:

- label = Career
- icon = career
- accent = emerald
- supported profile = career
- suggested Plan category = 就活・キャリア

Navigation keys:

- current_action
- process_readiness
- career_inbox
- pipeline
- interviews
- history

Strong route hints:

- `workspace.career.*`
- `plans.career.*`

Career Plan deep linkはprofile `career` からCareer Modeへ解決する。

Career manual preferenceも保存可能。

Creative / Generalは引き続きOverview fallback。

### Self-completing Career onboarding

Career onboarding:

```text
Career Plan
→ first real Career signal
→ normal Career Intelligence
```

Steps:

1. `create_plan`
2. `capture_career_signal`

Career signalはV55.0 Stateの:

```text
has_career_signal = true
```

で判定する。

signal sourceは以下のいずれでもよい。

- Career Capture
- Career Application
- Selection Event
- supported Career TaskEvidence

1件でも現実Stateが入ればonboardingは自動で消える。

Application整理やInterview Reviewはonboardingに含めない。
それらは通常のCurrent Actionが判断する。

### Mode-aware Career Plan creation

Career Workspaceから:

```text
/plans/create/manual?workspace_mode=career
```

を開く。

初期category:

```text
就活・キャリア
```

profileがcareerのまま作成された場合:

```text
create
→ /workspace/career?plan_id=...
→ capture_career_signal
```

ユーザーがcategoryを変更した場合はCareerへ強制redirectしない。

### Qualitative presentation

Careerは共通 `PlanIntelligencePresentation` を使う。

ただし数値Readinessは表示しない。

```text
readiness.score = null
```

Career Workspace表示:

- signalなし → 未観測
- signalあり → 観測中

label:

```text
Process Readiness
```

metrics:

- Capture
- Application
- Interview
- Review Due

これは内定確率・市場価値・候補者スコアではない。

### Action routing

`career_interview_review`:

```text
→ plans.career.interview_reviews.show
```

その他:

```text
→ plans.career.index
```

自動応募・自動送信は行わない。

### State Change Feedback

CareerもV54.6 generic historyへ参加する。

safe Evidence labels:

- Interview Review
- Selection Result

Career level labels:

- unknown → 未観測
- developing → 観測中
- blocked → 要整理
- ready → 整理済み

会社名・役職・面接回答はState Changeへ出さない。

### Overview

OverviewはCareerについても:

- representative Plan
- Mode summary
- State Change candidate
- first-use choice

を持つ。

representative ordering:

```text
priority
→ deadline
→ ID
```

Intelligence ChangesはStudy / Development / Career全体から新しい順に最大2件のまま。

first-use chooserはStudy / Development / Careerのいずれかのspecialized Planが存在すれば消える。

### Telemetry / Mode Bar

V54.8 telemetry safe Mode allowlistへ `career` を追加する。

追加fieldはない。

Mode BarにはCareerを追加し、emerald accentと専用briefcase iconを使う。

### Safety boundary

Career WorkspaceでもV55.0 safety boundaryを維持する。

Canoviaは以下を行わない。

- hiring probability推定
- market value計算
- user ranking
- employer rating
- offer承諾 / 辞退判断
- protected trait推論
- auto application submission
- employerへの自動送信

詳細は `docs/V55.1_CAREER_WORKSPACE_MODE.md` を正とする。


## V55.2 Qualitative Readiness UX

V55.2はiOS前のUX Completionにおける最初のsemantic cleanupである。

V53.9の共通 `PlanIntelligencePresentation` はStudy / Developmentの数値Readinessを前提に作られていた。

V55.1でCareerが同じpresentationへ入った結果、Careerの正しいState:

```text
readiness.score = null
state = 観測中
```

に対して、共通UIが:

```text
Process Readiness
未判定

現在地
観測中
```

と二重・矛盾表示する可能性があった。

V55.2ではView側のCareer例外分岐を増やさず、presentation contract自体へ `qualitativeReadiness` を追加する。

### Numeric Readiness

Study / Development:

```text
qualitativeReadiness = false
readinessDisplay = score / 100
Current State = separate
```

scoreが本当に未観測の場合だけ `未判定` を表示する。

### Qualitative Readiness

Career:

```text
qualitativeReadiness = true
readinessDisplay = stateLabel
Current State = duplicateなのでcompact surfaceでは分離表示しない
```

Career表示:

- Career signalなし → `未観測`
- Career signalあり → `観測中`

数値scoreは生成しない。

### Shared surfaces

同じReadiness semanticsを:

- Action Home Intelligence card
- Overview Global Current Action
- Overview Mode summary
- shared Intelligence summary
- Career Workspace

で利用する。

Viewは `domain === career` を見てReadinessの意味を決めない。

adapterがdomain-specific labelを提供し、presentationがnumeric / qualitativeの表示契約を持つ。

### Density rule

qualitative ReadinessとCurrent Stateが同じ意味の場合、status tileを1つに統合する。

これにより重複statusよりCurrent Action / Biggest Gapを視覚的に優先する。

Study / Developmentの既存2要素表示は変更しない。

### Safety / cost boundary

V55.2では:

- Career scoreを追加しない
- hiring probabilityを推定しない
- State / Decision policyを変更しない
- persistenceを変更しない
- DB queryを追加しない
- migrationを追加しない
- AI trafficを追加しない
- provider trafficを追加しない

詳細は `docs/V55.2_QUALITATIVE_READINESS_UX.md` を正とする。


## V55.3 Current Action Home UX Refinement

V55.3はiOS前UX CompletionのHome責務整理である。

Action Homeの最優先質問は引き続き:

```text
今、何をすればいい？
```

専門Workspaceの成立後、Home Intelligence card内にReadiness / Current State / Biggest Gapを大きく再表示すると、Study / Development / Career Workspaceの縮小版になってしまう。

V55.3ではHomeをCurrent Action中心へ戻す。

### Home Intelligence hierarchy

```text
Plan context
→ Current Action
→ short Action intent
→ Primary CTA
→ specialized Workspace CTA
→ compact judgment context
→ なぜ今これ？
```

Homeでは独立した大型Readiness / Current State / Biggest Gap panelを表示しない。

代わりにcompact contextとして:

- Readiness
- distinctな場合だけCurrent State
- Biggest Gap label

を1行へ圧縮する。

Readiness / Gapの詳細、metrics、State Change、historyは専門Workspaceが担当する。

### Canonical Workspace handoff

`PlanIntelligencePresentation` に:

- `workspaceUrl`
- `workspaceLabel`
- `hasWorkspaceHandoff()`

を追加する。

Study:

```text
/workspace/study?plan_id=...
Study Workspace
```

Development:

```text
/workspace/development?plan_id=...
Development Workspace
```

Career:

```text
/workspace/career?plan_id=...
Career Workspace
```

`detailUrl` は既存のdomain detail / operation surfaceとして維持する。

これにより:

```text
Home
→ specialized Workspace
→ domain operation/detail
```

の責務を分離する。

### CTA deduplication

HomeのIntelligence card:

1. Primary Action CTAを最優先
2. `workspaceUrl !== actionUrl` の場合だけWorkspace CTA
3. IntelligenceがPrimaryならtitlebarのgeneric linkを `ほかの候補を見る` と表示
4. Intelligenceがない通常Task Homeでは `実行を開く` を維持

同じdestinationへのPrimary / secondary CTA重複を表示しない。

### Explainability

Action intentはcollapsed Home cardでも短く表示する。

`なぜ今これ？` を開くと:

- Decision summary
- confidence
- Task projection note

を確認できる。

詳細履歴は専門Workspace / Intelligence history側をauthorityとする。

### Compatibility / cost

以下は変更しない。

- IntelligenceHomeActionServiceのselection policy
- Plan priority semantics
- other Plan horizontal browsing
- active WorkSession behavior
- attention rail
- offline snapshot
- Study explicit POST execution
- Development action routing
- Career action routing
- telemetry semantics

追加:

- DB queryなし
- migrationなし
- AI trafficなし
- provider trafficなし

詳細は `docs/V55.3_CURRENT_ACTION_HOME_UX.md` を正とする。


## V55.4 Map Exploration Surface Reframe

V55.4はiOS前UX Completionとして、Canovia Mapを「Homeの別表示」から明示的なExplore Surfaceへ再定義する。

最新のSurface責務:

```text
Home
→ 今、何をすればいい？

Workspace
→ domainの現在地と判断理由

Explore
→ Canovia全体の構造・関係性・Contextを空間的に辿る
```

### Canonical entry

Home:

```text
/
route = home
role = Now / Current Action
```

Explore:

```text
/map
route = map.index
role = spatial exploration / whole-system navigation
```

ログイン後・authenticated guest redirectのcanonical entryは常にHome。

旧Map preferenceが残っていても `/map` へ自動redirectしない。

### Legacy Home Surface Preference

旧:

- localStorage `pacekeeper.ui.home_surface`
- cookie `canovia_home_surface`

は互換期間中のlegacy stateとして残せるが、入口決定には使わない。

`HomeSurfacePreference` はcompatibility shimとなり:

```text
value() → classic
url() → /
```

を返す。

DB migrationやlegacy storageの即時削除は行わない。

### Settings

以下をSettingsから撤去する。

```text
ホームの既定表示
Classic
Map
```

ユーザーはMapをHome preferenceとして理解する必要がない。

Theme / Accent / Densityは変更しない。

### Surface navigation

既存compact switcherのlayout/CSS資産は再利用し、意味だけをSurface navigatorへ変更する。

```text
SURFACES
Home
Explore
```

DOM:

- `data-canovia-surface-nav`
- `data-canovia-surface="home"`
- `data-canovia-surface="explore"`

通常app pageは `data-canovia-surface="app"`。

旧 `data-home-surface-*` UI contractは退役する。

### Explore presentation

Map root title:

```text
Canovia Explore
```

L0:

```text
L0 · CANOVIA EXPLORE
```

HomeがCurrent Actionを開始するSurfaceなのに対し、Exploreは:

- 全体像
- Plan / Execution Context
- 振り返り
- 共同
- Space Station

を空間的に探索するSurfaceとして説明する。

L0 CTAは:

```text
実行Contextを探索
```

とし、「今やることを開始する」primary authorityはHomeへ残す。

Mobile / Instant shell section labelは `Explore`。

### Runtime cleanup

product runtimeの `app.js` はlegacy Home preference moduleをmountしない。

削除対象:

- `resolveHomeSurface()`
- `persistHomeSurface()`
- Home link destination rewrite
- Home surface setting event handlers

legacy helper file自体はcompatibility window中は残してよい。

### Preserved Map architecture

V55.4では以下を変更しない。

- `/map` URL
- Map Projection
- L0/L1/L2/L3 hierarchy
- Space Station
- Semantic Zoom
- direct node navigation
- Map telemetry
- fullscreen Map shell
- safe area
- Instant Navigation
- Mobile pan / pinch
- Map personalization
- Roadmap spatial surfaces

### Cost boundary

V55.4は:

- DB query追加なし
- migrationなし
- AI trafficなし
- provider trafficなし
- Current Action selection変更なし

詳細は `docs/V55.4_MAP_EXPLORATION_SURFACE.md` を正とする。


## V55.5 Execution Mode Terminology & Surface Handoff Clarity

V55.5はiOS前UX Completionとして、global Workspace Modeと旧V51.2 Execution Workspaceの語彙衝突を解消する。

現在のauthority:

```text
Workspace Mode
→ domain state / Readiness / Gap / Decision / Current Action

Execution Mode
→ selected Taskをどう実行するか
```

Workspace Mode:

- Overview
- Study
- Development
- Career

Execution Mode:

- study
- development
- career
- general

`/navigate` の内部route/query/session contractは変更しない。

```text
mode=study|development|career|general
session: execution_mode
```

user-facing UIだけを:

```text
Execution Workspace
→ Execution Mode / 実行タイプ
```

へ更新する。

Execution header:

```text
学習モード
開発モード
キャリアモード
汎用モード
```

Picker:

```text
CHOOSE MODE
```

Plan scope:

```text
この実行タイプのPlan
この実行タイプの全Planから選ぶ
```

specialized handoff:

Study:
```text
学習Activityで進める
```

Development:
```text
開発フローで進める
```

Career:
```text
Career管理で進める
```

General:
```text
このまま開始
```

Task handoff copy:

```text
このTaskを専用フローへ引き継ぐ
Task Contextを保ったまま、<mode>の実行フローで進めます。
```

変更しない:

- ExecutionModeService class name
- mode keys / query
- recommendation scoping
- destination route
- timer fallback
- compatibility_key
- session draft
- CSS class names
- telemetry

追加:

- DB queryなし
- migrationなし
- AI trafficなし
- provider trafficなし

詳細は `docs/V55.5_EXECUTION_MODE_TERMINOLOGY.md` を正とする。


## V55.6 Execution Ecosystem Foundation

V55.6は、Canoviaを「すべての実行手段を内蔵するサービス」ではなく、目標・計画・実行手段・結果を接続するオーケストレーションレイヤーへ拡張するための内部Foundationである。

Canonical flow:

```text
Workspace / Intelligence
→ Current Action / Task
→ Execution Mode
→ Execution Capability
→ Execution Resolver
→ Execution Provider
→ Operation Surface
→ ExecutionActivity
→ TaskEvidence
→ Existing Intelligence / Replan
```

### Execution Capability

初期Capability:

- `study.practice`
- `study.recall`
- `study.resource`
- `study.language`
- `coding.repository`
- `general.task`

Studyは既存 `StudyActivityPolicyService` をauthorityとしてCapabilityへ変換する。

Development profileは `coding.repository`、その他は現時点では `general.task` へ解決する。

AIによるCapability判定は行わない。

### Execution Provider

`ExecutionProviderCatalog` interfaceの背後にcode-defined `ExecutionProviderRegistry` を置く。

初期Provider:

- `canovia.study.practice` / native
- `canovia.study.recall` / native
- `canovia.study.resource` / native
- `canovia.study.language` / native
- `canovia.development` / native
- `github` / external
- `canovia.general` / native

External ProviderがCatalogへ存在するだけでは自動選択しない。

### Provider resolution

`ExecutionResolver` のV55.6順序:

```text
1. valid Plan preference
2. enabled Native provider
3. current execution fallback
```

Recommendation、Sponsored、Monetization、AI scoreはResolverへ入れない。

### PlanExecutionPreference

`plan_execution_preferences` を追加する。

```text
plan_id
capability
provider_key
user_selected
```

`plan_id + capability` は一意。

V55.6ではPlan scopeのみ。Task override / User defaultは将来拡張とする。

### ExecutionActivity

外部ProviderがCanovia内部のPlan / Task構造を理解しなくても活動結果を返せるよう、Task未紐付けを許す `execution_activities` を追加する。

```text
Provider
→ ExecutionActivity
→ Canovia linking
→ TaskEvidence
```

`ExecutionActivity != TaskEvidence` を設計原則とする。

Activityは「Providerが報告した事実」、TaskEvidenceは「Canovia Taskへ紐付いた観測事実」を表す。

`provider_key + external_key` でidempotentに受信できる。

### Activity → Evidence

`ExecutionActivityProjector` interfaceを追加し、V55.6では `TaskEvidenceExecutionActivityProjector` が既存 `TaskEvidenceService` へ投影する。

generic Evidence type:

```text
execution_activity_observed
```

External Providerは `EvidenceSource::External`、Native Providerは `EvidenceSource::Native`。

Provider trace用のActivity metadataはTaskEvidenceへ自動コピーしない。

Activity受信・完了だけではTask progress / statusを変更しない。

### UX boundary

V55.6ではvisible UIを変更しない。

変更しない:

- Plan creation redirect
- Study / Development / Career Workspace onboarding
- `/navigate`
- Current Action CTA
- specialized route
- Timer fallback

将来のExecution Setupは、実際に複数Provider候補が存在する場合だけPlanning / Workspace側で提示し、通常実行は一つのStart actionを維持する。

### Runtime / cost

V55.6は:

- AI traffic追加なし
- external provider request追加なし
- GitHub request追加なし
- GET page query追加なし
- automatic progress mutationなし

詳細は `docs/V55.6_EXECUTION_ECOSYSTEM_FOUNDATION.md` を正とする。

Execution Ecosystem全体の将来構想・Marketplace / Developer Portal / Monetization等の原案は `docs/EXECUTION_ECOSYSTEM_DRAFT.md` に一時資料として保持する。


## V55.7 Execution Setup Validation

V55.7はV55.6 Execution Ecosystem Foundationの最初のvisible UX validationである。

原則:

```text
Planning / Workspace
→ provider choiceが複数ある時だけ設定を提示

Normal Start
→ 一つのCTA
→ saved preference / Native default
→ provider resolve
→ launch
```

### Validation provider

非本番Validation用に、feature flag配下でのみ:

```text
validation.study.practice.external
kind = external
capability = study.practice
```

をProvider Catalogへ追加する。

flag:

```text
CANOVIA_EXECUTION_SETUP_VALIDATION_ENABLED=false
```

default false。

OFF時は既存Native挙動から変化しない。

### Study onboarding precedence

Execution SetupはStudy Plan作成直後には出さない。

既存:

```text
create Plan
→ capture Study Scope
→ record first Study Evidence
```

を完了した後にのみ評価する。

### Setup visibility

`ExecutionSetupService` がPlan + TaskのCapabilityを解決し、enabled providerが2件以上ある場合だけchoiceを成立させる。

明示Preferenceがない場合:

- Study Workspaceで初回Setupを表示
- 選択しなくてもNative Start可能

明示Preferenceがある場合:

- 大型Setup promptを消す
- 現在のProviderをcompact表示
- `実行方法を変更` から再選択可能

編集権限のないユーザーには変更UIを表示しない。

### Preference

V55.6の:

```text
plan_execution_preferences
```

を再利用する。

scope:

```text
Plan + Capability
```

を維持する。

Task ID単位にしないため、Study IntelligenceがStart時にTaskを新規投影しても同CapabilityならPreferenceが継承される。

### One-tap Start

既存canonical Study Start:

```text
POST /plans/{plan}/study-action/execute
```

へProvider resolutionを接続する。

このためStudy Workspaceだけでなく、同じactionUrlを使うHome / OverviewからのCurrent Actionも同じProvider設定を利用する。

`ExecutionLaunchResolver` を追加し:

```text
ExecutionResolver decision
→ Native: existing Study route
→ Validation External: validation handoff route
```

とする。

### Validation external surface

Validation Providerは実際の外部サービスへ接続しない。

確認するのは:

```text
saved preference
→ resolver
→ launch handoff
```

のみ。

行わない:

- OAuth
- external API
- external app launch
- Activity callback
- Evidence creation
- Task progress mutation

### Native fallback

Validation flag OFF、Provider無効化、stale external preference等の場合も、V55.6 Resolver ruleによりNativeへfallbackする。

### Database / cost

- migration追加なし
- AI traffic追加なし
- external network request追加なし
- Intelligence scoring変更なし
- Task progress自動変更なし

詳細は `docs/V55.7_EXECUTION_SETUP_VALIDATION.md` を正とする。


## V55.8 External Activity Integration Validation

V55.8はExecution Ecosystemの最初のreturn loop validationである。

Canonical flow:

```text
Study Current Action
→ selected External Provider
→ launch
→ normalized provider result
→ ExecutionActivity
→ explicit Task link
→ TaskEvidence
→ TaskEvidenceAdapter
→ Study Intelligence
→ refreshed Current Action / Readiness
```

Validation Provider resultは、現在はCanovia内の非本番Surfaceから返す。

新規route:

```text
POST /execution-validation/study/{plan}/tasks/{task}/result
```

このrouteは `CANOVIA_EXECUTION_SETUP_VALIDATION_ENABLED=true` の時だけ利用でき、authenticated Canovia user + Task edit permissionを要求する。

これは将来のprovider authentication設計ではない。

### Domain Evidence mapping

V55.6のgeneric Activity Evidenceを維持しつつ、以下を満たす場合だけStudy domain Evidenceへ昇格する。

```text
capability = study.practice
type = study_practice_completed
status = completed
valid score_percent
```

その場合:

```text
ExecutionActivity
→ study_practice_assessed
→ EvidenceSource::External
```

へ投影する。

その他のActivityは引き続き:

```text
execution_activity_observed
```

とする。

### Intelligence boundary

TaskEvidenceへ渡すStudy factsはallowlistする。

- score_percent
- strengths
- weaknesses
- weakness_topics
- provider_key
- execution_activity_id

ExecutionActivity.metadataや任意provider payloadはIntelligenceへコピーしない。

Study Intelligenceは既存 `study_practice_assessed` contractをそのまま再利用し、並行scoring engineを追加しない。

### Progress boundary

```text
external score
!= Task progress

Activity completed
!= Task completed
```

Task progress / status / remaining_minutesは自動変更しない。

### Idempotency

```text
provider_key + external_key
→ one ExecutionActivity

execution-activity:{activity_id}
→ one TaskEvidence
```

同じprovider resultの再送は同一Activity / Evidenceを更新し、重複作成しない。

### Current scope

追加しない:

- public provider Activity API
- OAuth / Account Link
- provider webhook authentication
- real external provider
- Marketplace
- Developer Portal
- Developer Pro implementation
- automatic progress / completion

Developer Proは `docs/future/DEVELOPER_PRO_AI_DEVELOPMENT_ORCHESTRATION.md` のFuture Designとしてのみ保持する。

詳細は `docs/V55.8_EXTERNAL_ACTIVITY_INTEGRATION_VALIDATION.md` を正とする。


## V55.9 Provider Connection / Authenticated Activity Intake

V55.9はExecution Ecosystemの最初のprovider-neutral authenticated return pathを追加する。

```text
External Provider
→ ProviderConnection
→ signed stateless Activity API
→ opaque execution_context
→ server-side Task association
→ ExecutionActivity
→ TaskEvidence
→ existing Intelligence
```

### ProviderConnection

新規 `provider_connections` tableを追加する。

ConnectionはCanovia user所有で、enabled external providerだけ作成可能。

保存:

- user_id
- provider_key
- public_id
- encrypted secret ciphertext
- status
- last_used_at
- revoked_at

plaintext secretはConnection作成時に1回だけ返し、DBへ平文保存しない。

### Signed Activity API

Endpoint:

```text
POST /api/execution/activities
```

Headers:

```text
X-Canovia-Connection
X-Canovia-Timestamp
X-Canovia-Signature
```

Signature:

```text
HMAC-SHA256(
  secret,
  "<unix_timestamp>.<raw_request_body>"
)
```

timestamp toleranceは±300秒。

raw body / signature / plaintext secretは永続化しない。

### Opaque execution_context

CanoviaがTask実行前に短命tokenを発行する。

```text
POST /plans/{plan}/tasks/{task}/provider-connections/{connection}/execution-context
```

条件:

- authenticated user
- Task edit permission
- Connection owner一致
- selected Execution Provider一致
- active Connection
- provider supports capability

token TTLは30分。

Provider payloadから `user_id / provider_key / capability / plan_id / task_id` を信用しない。これらはConnection + encrypted contextからserver-sideで確定する。

### Activity schema v1

Envelope:

```text
schema_version = 1.0
execution_context = opaque token
activity = normalized provider result
```

現在Study Practice completionだけdomain metricsをallowlistする。

- score_percent
- strengths
- weaknesses
- weakness_topics

unknown extra metricsは破棄する。

### Idempotency / replay

```text
timestamp window
+
provider_key + external_key
```

でreplay impactを制限する。

同じexternal_keyの再送は既存ExecutionActivity / TaskEvidenceを更新し、重複作成しない。

### Security boundary

Connection revoke後はAPI認証不可。

execution_contextは発行Connectionへboundし、別Connectionで再利用不可。

Provider/Task/User/Capabilityはcaller payloadから決定しない。

### Existing integrations

GitHub App / GitHub webhookは既存provider-specific connectionとしてそのまま維持する。

V55.9 ProviderConnectionへ自動移行しない。

### Non-goals

V55.9では実装しない:

- OAuth platform
- real provider onboarding
- Marketplace
- Developer Portal
- provider SDK
- strict nonce ledger
- automatic Task matching AI
- automatic progress/completion
- Developer Pro runtime

詳細は `docs/V55.9_PROVIDER_CONNECTION_AUTHENTICATED_ACTIVITY_INTAKE.md` を正とする。


## V56.0 Study Exam Convergence Policy

V56.0では、Study Practiceが弱点を見つけ続けるだけで試験日へ収束しない問題を、deterministicなCanovia Policyで制御する。

責務:

```text
AI
→ question generation / difficulty / assessment / error classification / next-step suggestion

Canovia Policy
→ phase / weakness graduation / deep-dive cap /
   General Practice return / re-entry / exam-date convergence
```

Study Practice phase:

```text
DIAGNOSIS
→ WEAKNESS_REINFORCEMENT
→ GENERAL_PRACTICE
→ EXAM_MODE
```

Phase stateは新しいDB状態を追加せず、既存:

- StudyPracticeAttempt
- StudyPracticeSession.selection_context.strategy
- StudyScopeCapture.exam_date
- Plan.deadline

から導出する。

### Weakness Graduation

初期Policy:

```text
targeted reinforcement Sessions >= 2
AND estimated targeted question budget >= 8
AND latest 2 targeted scores >= 80%
AND no blocking Topic error in those latest 2
→ graduated
```

blocking error:

- knowledge_gap
- concept_gap
- reasoning_gap
- condition_reading
- unit_error
- unknown

calculation_slip / carelessだけではGraduationを妨げない。

Graduationは永久masteryではなく:

```text
stop deliberate drilling now
```

を意味する。

### Overtraining prevention

1 Topicの1 intervention cycleは:

```text
maximum 3 targeted Sessions
OR
maximum estimated targeted question budget 20
```

で打ち切る。

Graduationできなくてもcap到達後はfocused reinforcementを止め、General Practiceへ戻す。

### General Practice return

graduated / cappedの直後は、別弱点へ直接移らず最低1回のbroad Practiceを要求する。

General Practice:

```text
primary = 0
secondary = 0
diagnostic = 10
```

として既存Question Bankのdomain round-robin / recent question avoidanceを再利用する。

### Weakness re-entry

Graduated / capped TopicはGeneral Practice / Exam Modeでのみ再評価する。

```text
latest 3 broad Attemptsのうち
same Topic blocking failure >= 2
→ reopened
```

単発ミスでは即再弱点化しない。

### Exam date

試験日authorityはStudy Intelligenceと共通化した `StudyExamDateService` を使う。

優先:

1. exactly one unique confirmed StudyScopeCapture.exam_date
2. Plan.deadline
3. unknown

初期Policy:

```text
days > 30
→ bounded Diagnosis / Reinforcement allowed

15..30 days
→ General Practice preferred

0..14 days
→ Exam Mode
```

Exam Modeでは新しい細部探索よりAP科目A相当のbalanced 4-choice practiceを優先する。

現在のStudy Practice contractは1〜20問のため、V56.0では50/100問の巨大Sessionを作らず10問blockを積み上げる。

### AP Subject A case

42/50 = 84%程度の診断で:

- Network
- Database
- Performance / Availability calculations
- Quality characteristics

が一度観測されても、全領域をfocused weaknessとしてゼロから学び直さない。

single signalはmonitoringに留め、repeated evidenceがある狭いTopicだけ短く補完する。

### AI Prompt

Generation / evaluation promptもPhase-awareにするが、Promptだけに終了判断を委ねない。

- reinforcementではCanovia-approved active Topicから逸脱しない
- General Practiceでは直前弱点へ偏らない
- Exam Modeでは本番バランスを優先する
- graduated/capped Topicを勝手にfocused practiceへ戻さない
- AI next_stepはsuggestionでありPhase遷移authorityではない

### UI

既存PRACTICE STRATEGY cardへ最小表示を追加する。

- 現在Phase
- 試験までの日数
- active Topic
- targeted Session / estimated question budget
- graduationまでの残り
- graduated / capped理由によるGeneral Practice return
- Exam Mode表示

新しい管理dashboardは作らない。

### Compatibility

DB migrationなし。

既存Attempt / Session historyから状態を推定し、新SessionからStrategy v3 phase snapshotを保存する。

Study Intelligenceのexam-date semanticsも同じ `StudyExamDateService` へ統合する。

Telemetry eventは将来候補として仕様へ残すが、V56.0 MVPでは新しいTelemetry subsystemを追加しない。

詳細は `docs/V56.0_STUDY_EXAM_CONVERGENCE_POLICY.md` を正とする。


## V56.1 iOS Soft Launch Web Readiness

V56.1は、SwiftUI + WKWebView構成を維持したまま、iOS Soft LaunchでApp Store審査に必要になるWeb側の公開・Account境界を整える。

### Public review surfaces

新規public routes:

```text
GET /privacy
GET /support
```

- Privacyは未ログインでも閲覧可能
- Supportは未ログインでも閲覧可能
- Legal surfaceではGuest onboardingを自動表示しない
- desktop footer / Settings / AccountからPrivacy・Supportへ到達可能

Productionでは:

```text
CANOVIA_SUPPORT_EMAIL
CANOVIA_OPERATOR_NAME
```

を設定する。

Support email未設定はSoft Launch release blockerとして扱う。

### Account deletion

Account作成機能を持つCanoviaは、iOSアプリ内からAccount全体の削除を開始できるようにする。

```text
DELETE /account
```

条件:

- authenticated user
- current password確認
- current account email再入力
- destructive actionを明示

単なるdisable/deactivateにはしない。

### Deletion semantics

`users` rowだけを削除すると、`plans.user_id` 等の `nullOnDelete` により本人データがownerlessとして残り得る。

V56.1では:

```text
owned Plan ids
→ private file paths収集
→ file-backed user rows削除
→ owned Plans削除
→ Plan cascade
→ nullable user-owned rowsをchild-first削除
→ User削除
→ private Storage削除
→ session invalidation
```

とする。

本人所有Planに含まれるTask / Study / Career / Evidence / IntelligenceはPlan cascadeを利用する。

他ユーザー所有の共同Planそのものは削除しない。

### Private uploads

Account deletionでは少なくとも:

- Inbox storage_path
- Study Recall source storage_path
- Career Capture screenshot_path

を収集し、DB削除成功後にprivate Storageから削除する。

### iOS metadata relationship

App Store Connectで使用するURLはcanonical Canovia originを基準に:

```text
Privacy Policy URL = /privacy
Support URL        = /support
```

とする。

iOS Native側はAccount stateや削除logicを二重実装せず、Web Account surfaceをそのまま利用する。

### Non-goals

V56.1では追加しない:

- Sign in with Apple
- subscriptions
- native account model
- data export
- App Store submission automation
- push notification
- iOS native settings rewrite

詳細は `docs/V56.1_IOS_SOFT_LAUNCH_WEB_READINESS.md` を正とする。


## V56.2 IPA Official Question Pack

V56.2 adds the first authentic IPA AP past-question corpus to the existing Question Bank.

Initial bundled Pack:

```text
ap-a-ipa-2025-autumn-official-v1
source = 令和7年度 秋期 応用情報技術者試験 午前
subject equivalent = 科目A
questions = 35
```

Content composition:

- Technology 20
- Management 5
- Strategy 10

Diagram-heavy items are excluded until Question has first-class image/diagram support.

Every included item uses:

```text
source_type = official
source_reference = 年度 / 期 / 試験区分 / 時間区分 / 問番号
learning_metadata.provenance = publisher / source URLs / question number / transcription note
```

The existing Study Practice UI renders `source_reference` directly under the question, so official attribution is visible during practice.

### Official-first deterministic selection

Question Pack metadata may now include:

```json
{
  "selection_priority": 100
}
```

Coverage resolution still prioritizes:

1. qualification match
2. Pack availability for the requested question count / focus

Only among Packs that can satisfy the same practice demand does higher `selection_priority` win.

Therefore:

```text
General Practice / Exam Mode
→ IPA official Pack first when sufficient

narrow weakness without enough official matches
→ Canovia Core Pack

remaining uncovered seats
→ Hybrid Native AI fallback
```

This keeps question selection deterministic. AI does not decide which official past question to use.

V56.0 Phase / Weakness Policy remains the authority over whether practice is Diagnosis, Reinforcement, General Practice, or Exam Mode.

No database migration is required.

Detailed contract: `docs/V56.2_IPA_OFFICIAL_QUESTION_PACK.md`.

## V56.3 Question Exposure Rotation

V56.3 improves deterministic Question Bank repetition control before adding more official AP years.

Question selection remains inside the existing authority chain:

```text
V56.0 phase / weakness policy
→ Question Mix
→ V56.2 Pack coverage + official priority
→ V56.3 exposure rotation inside the selected Pack
```

Question Bank selector version:

```text
bank-v3-exposure
```

The selector derives bounded Plan-wide exposure state from
`StudyPracticeSession.selected_questions`.

Initial policy:

```text
history_session_limit = 24
recent_session_window = 3
```

Within each existing topic/domain group, selection prefers:

1. not recently seen
2. lower bounded exposure count
3. longer time since last exposure
4. existing focus score
5. existing difficulty / sort order

History is Plan-wide rather than Task-local. This prevents two Study Tasks under the same exam Plan
from independently replaying the same Question Bank sequence.

The rule is preference-based: narrow Weakness Reinforcement may reuse recent questions when the
focus pool is small. V56.0 graduation/cap behavior remains the mechanism that prevents endless drills.

New selections persist exposure diagnostics in the existing `selected_questions` JSON. No migration
and no AI selector are introduced.

Detailed contract: `docs/V56.3_QUESTION_EXPOSURE_ROTATION.md`.

## V56.4 Study Routing Mastery / Cooldown

V56.4 adds deterministic study-routing control above V56.0 phase convergence and V56.3 question-level exposure rotation.

Canonical routing:

```text
V56.0 phase / weakness convergence
→ V56.4 Task intent + mastery / cooldown
→ Primary / Secondary / Diagnostic allocation
→ subtopic cooldown suppression
→ parent-topic exposure cap
→ V56.3 question exposure rotation
→ Question Bank / Hybrid provider
```

### Assessment authority

For modern assessments that contain structured `question_feedback`, that structured feedback is authoritative for routing.

```text
correct + error_type=none
→ feedback / reasoning_feedback may remain visible
→ weakness_topics / misconceptions are cleared for routing
→ summary weaknesses do not create a routing weakness
```

`next_step.focus_topics` cannot create a new weakness by itself. It can only slightly influence a Topic already supported by actual error evidence.

### Task intent

Internal Task modes:

- `focused_remediation`
- `broad_assessment`
- `adaptive`

Broad mode is intentionally conservative and requires an explicit signal such as:

- 分野横断
- 横断問題
- 弱点探索
- 全範囲
- 模試 / 模擬 / 本番演習

Generic `総合演習` remains `adaptive` for backward compatibility.

The public Study Practice strategy key remains `general_practice`; the internal difference is stored in:

```text
selection_context.strategy.routing_policy.task_mode
```

### Broad allocation

For an explicit broad-assessment 10-question block:

```text
confirmed weakness recheck <= 2
retention check           <= 2
cross-domain exploration  >= 6
```

A confirmed weakness does not convert the whole broad block into one-domain remediation.

### Focused remediation

Focused remediation keeps deliberate depth:

```text
primary ≈ 60%
secondary / related ≈ 20%
diagnostic exploration = remaining
```

V56.0 graduation / cap still bounds the intervention.

### Mastery / cooldown

Initial configurable policy:

```text
correct streak for cooldown = 2
mastery correct count       = 3
cooldown sets               = 2
mastered cooldown sets      = 4
perfect latest score        = 100%
```

These are routing defaults, not permanent mastery claims.

### Parent-topic exposure

V56.4 separates:

- `mastery_confidence`
- `recent_exposure`

Initial parent control:

```text
recent question window      = 10
normal broad parent cap     = 2
high-exposure parent cap    = 1
high exposure threshold     = 0.40
high confidence threshold   = 0.75
```

Thus SQL / HAVING / JOIN / transaction questions are also evaluated through the shared parent `データベース`, preventing sibling-subtopic chaining from dominating broad practice.

### Question Bank

Current selector:

```text
bank-v4-routing
```

It preserves V56.3 question-level exposure ordering while adding:

1. cooldown suppression
2. parent-topic cap / exposure preference
3. parent-aware diagnostic round-robin
4. then V56.3 recent / exposure-count / last-seen ordering

New selection diagnostics remain inside existing JSON:

- `selection_parent_topic`
- `selection_parent_recent_exposure`
- `selection_parent_cap`
- `selection_cooldown_match`

No migration and no new required external-AI field are introduced.

Detailed contract: `docs/V56.4_STUDY_ROUTING_MASTERY_COOLDOWN.md`.

## V56.5 Study Practice Resume Fast Path

V56.5 separates new-practice setup from unfinished-practice continuation.

Canonical entry:

```text
Study Practice link
→ resumable READY / IN_PROGRESS Session exists?
  → yes: resume route → restore snapshot → answering
  → no:  show setup → Strategy / Provider preview → prepare
```

Resume authority is the existing `StudyPracticeSession`:

- `selection_context.strategy`
- `question_provider / question_provider_mode`
- `provider_payload`
- `questions_snapshot`
- `draft_answers`

During resume, Canovia does **not** run `StudyPracticeOrchestrator::previewHandoff()`. Therefore reopening a Session cannot recalculate V56.4 phase/routing, choose another provider, or replace the selected questions.

New route:

```text
GET /plans/{plan}/tasks/{task}/study-practice/resume
plans.tasks.study_practice.resume
```

Existing Home / Plan / Workspace links continue targeting the canonical Study Practice entry. The controller automatically branches into resume when durable unfinished answer state exists.

Resume UI intentionally skips:

- Practice Strategy setup card
- Practice Reliability setup card
- four-stage "問題準備 → 回答 → AI評価 → 結果" strip

and renders:

```text
CONTINUE PRACTICE
→ restored answer count / total
→ existing Session/provider
→ questions
```

The user can explicitly choose `新しい演習を作る`, which reuses the existing reset semantics and marks the unfinished Session `abandoned`.

`answered` / `assessed` Sessions remain on V40.7.3 evaluation/result recovery and do not use the answer-resume fast path.

No database migration.

Detailed contract: `docs/V56.5_STUDY_PRACTICE_RESUME_FAST_PATH.md`.

## V56.13 Study State-First Workspace Composition

V56.13 changes the Study Workspace entry model from a fixed setup sequence to State First composition.

Canonical decision flow:

```text
Plan / Tasks / Study Evidence / Practice Attempts / Deadline
→ StudyLearningTypeRouter
→ StudyWorkspaceStateResolver
→ StudyWorkspaceSurfacePolicy
→ StudyWorkspaceSurfaceRegistry
→ registered Study UI surfaces
```

### Learning Type Router

Initial internal types:

- `certification_exam`
- `score_exam`
- `school_test`
- `memorization`
- `skill_learning`
- `general_learning`

This classification is deterministic presentation/policy state, not a new persisted Plan schema.

### State First rule

Study Scope is no longer a universal prerequisite.

Canovia first determines whether a usable current position already exists from:

- Study Practice Attempts
- `study_practice_assessed` Evidence
- `study_recall_reviewed` Evidence
- observed confirmed Scope
- active Tasks
- deadline context

A learner with existing Practice history is considered to have a usable current position even when confirmed Study Scope is absent.

### Missing Context rule

Canovia asks only for context that materially changes the next action.

Examples:

```text
AP + Practice history
→ current position known
→ continue Practice / weakness / recent-result surfaces
→ Scope remains optional

TOEIC 600 + no baseline
→ score exam
→ current score / diagnostic surface
→ do not ask for exam scope

School test + no range
→ school_test
→ Study Scope is decision-changing
→ surface Scope Capture prominently

Memorization
→ retention / Recall baseline

Skill learning
→ practical Evidence / current Task baseline
```

Practice percentages are never interpreted as absolute TOEIC / IELTS scores.

### Surface Registry

Initial registered Study surfaces:

- Goal Summary
- Current State
- Missing Context
- Readiness
- Biggest Gap
- Current Action
- Weaknesses
- Recent Results
- Scope Coverage
- Study Methods

Views render selected registry surfaces instead of branching on specific named exams.

Future image / diagram / material-viewer integrations can be added as new registered surfaces without changing the State First architecture.

### Existing Study Intelligence

The existing V53 Scope-based Exam Readiness model remains authoritative when confirmed Scope exists.

V56.13 does not introduce a second readiness score.

It changes whether missing Scope is allowed to gate the entire Study Workspace.

### Study Mode onboarding

Study Mode registry onboarding is now Plan creation only.

After a Study Plan exists:

```text
Mode onboarding ends
→ State First composition begins
```

Development and Career onboarding semantics are unchanged.

### Mutation / cost boundary

V56.13:

- adds no migration
- adds no AI traffic
- adds no external provider traffic
- creates no Task on Workspace GET
- does not change Study Practice routing
- does not change Study Recall scheduling
- does not change Study Scope Capture
- does not infer unsupported score scales

Detailed contract: `docs/V56.13_STUDY_STATE_FIRST_WORKSPACE.md`.

## V56.14 Study Recommendation Surface

V56.14 makes the Study Workspace's recommended next practice visible before execution.

Canonical authority:

~~~text
Study Practice history
+ Task intent
+ V56.0 Exam Convergence
+ V56.4 Routing / Mastery / Cooldown
→ StudyPracticeStrategyService
→ StudyWorkspaceRecommendationService
→ Study Recommendation Surface
~~~

The Workspace does not maintain a second allocation policy.

When there is no unfinished Study Practice Session, the recommendation is built by the same StudyPracticeStrategyService used by the actual Practice flow.

The surface can show:

- Strategy / phase label
- target question count
- primary / secondary / diagnostic allocation
- bounded weakness recheck topics
- retention-due topics
- cooldown context
- suppressed / preferred parent topics
- deterministic Strategy reason
- CTA to the existing Study Practice route

Display labels translate the existing Strategy buckets without changing counts:

### General / broad practice

- primary = 弱点の再確認
- secondary = 定着確認
- diagnostic = 横断・未探索

### Weakness Reinforcement

- primary = 重点弱点
- secondary = 関連弱点
- diagnostic = 確認問題

### Diagnosis

- diagnostic = 現在地診断

### Exam Mode

- diagnostic = 本番横断

### Resume authority

When an actor-owned READY / IN_PROGRESS StudyPracticeSession with an existing question snapshot exists:

~~~text
stored Session selection_context.strategy
→ Study Recommendation
→ 続きから再開
→ V56.5 Resume Fast Path
~~~

The Workspace does not calculate a new next-set Strategy above an already-fixed Session.

### Surface selection

Study Recommendation replaces the generic Current Action card only when question Practice is actually the applicable next execution method.

It is initially available for:

- certification_exam
- score_exam
- general_learning
- school_test after required Scope exists

It does not override:

- school-test Scope gate
- memorization Recall
- skill-learning practical Evidence flows
- non-Practice Study Intelligence actions

### Mutation / cost boundary

Workspace GET may read Practice history and existing Practice Session state, but it does not:

- generate questions
- call a question provider
- create a StudyPracticeSession
- create a StudyPracticeAttempt
- change Task progress
- add AI traffic

Detailed contract: docs/V56.14_STUDY_RECOMMENDATION_SURFACE.md.

## V56.15 Study Method Recommendation

V56.15 adds the method-selection layer above V56.14 Practice Strategy.

Canonical flow:

~~~text
Learning Type
+ Current Learning State
+ Task semantic fit
+ Practice Strategy / Routing State
+ Recall / Resource availability
→ StudyMethodRecommendationService
→ primary learning method
→ method-specific execution
~~~

### Existing V41.10 authority

V41.10 `StudyActivityPolicyService` remains the Task-semantic classifier for:

- `question_practice`
- `recall`
- `resource_study`

V56.15 does not replace or duplicate it.

Responsibility split:

~~~text
StudyActivityPolicyService
= which method fits the Task text / intent?

StudyMethodRecommendationService
= which method is primary now, given current State?
~~~

V56.15 can additionally select:

- `scope_organization`
- `practical_evidence`

### Deterministic method priority

Initial precedence:

1. unfinished Practice Session → Question Practice / Resume
2. school test with missing Scope → Scope Organization
3. memorization learning → Recall
4. skill learning → Practical Evidence
5. Exam Mode → Question Practice / Exam Mode
6. explicit current Study Intelligence Recall action → Recall
7. repeated knowledge/concept gap in at least 2 distinct Attempts among latest 3 → Resource Study
8. retention-due topics → Recall
9. otherwise V41.10 Task-semantic primary activity

Exam Mode remains authoritative over ordinary retention detours.

The older Scope-based Study Intelligence route may only force Scope Organization when confirmed Scope already exists. It must not reintroduce a universal Scope gate for no-Scope certification Plans.

### Repeated knowledge gap

Resource Study is selected only from structured Question Feedback when:

- correctness is `incorrect` or `partial`
- error type is `knowledge_gap` or `concept_gap`
- the signal occurs in at least 2 distinct Attempts among the latest 3

A single knowledge gap does not force a method switch.

### Workspace composition

The Workspace renders `study_method_recommendation` before method-specific detail.

When Question Practice is primary:

~~~text
Study Method Recommendation
→ V56.14 exact Practice Recommendation
~~~

V56.14 remains authoritative for question count, weakness/retention/exploration allocation and Resume Strategy.

When another method is primary:

~~~text
Study Method Recommendation
→ primary method CTA
~~~

The V56.14 Practice card and generic Current Action are not shown as competing primary actions.

The existing Other Study Methods surface is populated from ranked method alternatives instead of a fixed unordered list.

### Method routes

- Question Practice → `plans.tasks.study_practice.show`
- Recall → `plans.tasks.study_recall.show`
- Resource Study → `plans.resources.index`
- Scope Organization → `plans.study_scope.index`
- Practical Evidence → `plans.tasks.guided_execution.show`

### Study Activity page

The dedicated Study Activity page uses the same State-aware method recommendation as Study Workspace.

This prevents:

~~~text
Workspace: Resource Study recommended
→ Study Activity page: Question Practice recommended
~~~

from occurring after repeated knowledge/concept gaps or other State-based overrides.

The generic V41.10 `forPlanTask()` contract remains available to other surfaces and execution capability resolution.

### Execution Setup

Study Workspace provider setup is only shown when Question Practice is the current primary method.

Recall, Resource Study, Scope Organization and Practical Evidence do not display a Practice-provider selector above their primary method.

### Mutation / cost boundary

V56.15 Workspace / Study Activity reads may inspect:

- Practice Attempts
- existing Practice Session strategy
- Recall items
- Plan Resources
- Study State
- V41.10 method fit

They do not create or mutate Attempts, Sessions, Recall items, Resources, Tasks or Evidence.

No AI/provider request and no migration are added.

Detailed contract: `docs/V56.15_STUDY_METHOD_RECOMMENDATION.md`.

## V56.16 Study Score / Baseline Evidence

V56.16 separates external learning scores from Canovia Practice accuracy.

Canonical rule:

~~~text
StudyPracticeAttempt.score_percent
= accuracy inside a Canovia Practice set

StudyScoreObservation.score_value
= observed value on an external / declared score scale
~~~

The product must never infer TOEIC / IELTS / school-test absolute score from Practice percentage.

Persistence:

- Plan-level `study_score_observations`
- actor identity
- request UUID
- metric key / label
- observed score value
- preserved scale min / max where known
- source kind / optional source detail
- observed date
- optional bounded component scores

Initial profiles:

- TOEIC total 0–990; optional Listening / Reading 0–495
- IELTS 0–9 in 0.5 increments; optional Listening / Reading / Writing / Speaking
- school test 0–100
- unknown score exams remain generic and are not normalized to 0–100

State First:

- `score_observation_count`
- `has_external_score_baseline`
- `latest_external_score`
- `external_score_history`

For `score_exam`, Practice history alone does not satisfy the external-score baseline requirement.

Score Exam Current State shows distinct values:

- Current Score
- Target
- Gap
- Practice Accuracy (explicitly marked as a separate scale)

Missing external baseline routes to:

~~~text
GET /plans/{plan}/study-scores
plans.study_scores.index
~~~

Capture writes through:

~~~text
POST /plans/{plan}/study-scores
plans.study_scores.store
~~~

Observations can be deleted by the owning actor through:

~~~text
DELETE /plans/{plan}/study-scores/{studyScoreObservation}
plans.study_scores.destroy
~~~

School-test titles such as `数学II 中間テストで80点` may expose `target_score=80`; the target remains Plan intent, not Evidence.

Detailed contract: `docs/V56.16_STUDY_SCORE_BASELINE_EVIDENCE.md`.

## V56.17 Study Learning Type Confirmation / Override

V56.17 completes the State First Study architecture by making Learning Type an editable Plan-level decision when deterministic classification is ambiguous.

Canonical precedence:

~~~text
explicit Plan Learning Type override
>
deterministic StudyLearningTypeRouter inference
~~~

Supported Learning Types:

- `score_exam` — スコアを上げる
- `school_test` — 学校のテスト
- `certification_exam` — 資格に合格
- `skill_learning` — スキル習得
- `memorization` — 暗記・定着
- `general_learning` — その他の学習

Persistence:

- `plans.study_learning_type_override`
- `plans.study_learning_type_confirmed_at`

Confirmation is shown only when no explicit override exists and deterministic confidence is below 0.80.

Strong signals such as TOEIC / IELTS, school-test keywords, qualification keywords, memorization keywords, and concrete programming-language / programming keywords do not prompt.

Generic wording such as 「学ぶ」「身につける」「習得」 and fallback general-learning classification may prompt because choosing a different Learning Type materially changes Study surfaces and methods.

An explicit choice:

- sets downstream Learning Type confidence to 1.0
- suppresses future confirmation prompts
- outranks contradictory heuristic text
- remains editable from Goal Summary
- can be reset to automatic inference
- does not change Workspace Mode

The low-confidence confirmation surface is non-blocking.

Downstream services do not implement their own override logic. They continue consuming `StudyLearningTypeRouter`, so one explicit choice consistently changes:

- Missing Context
- Study Method Recommendation
- Score / Baseline behavior
- Scope behavior
- Recall / Practice / Practical Evidence selection
- Study Activity presentation

Routes:

~~~text
PUT /plans/{plan}/study-learning-type
→ plans.study_learning_type.update

DELETE /plans/{plan}/study-learning-type
→ plans.study_learning_type.destroy
~~~

Detailed contract: `docs/V56.17_STUDY_LEARNING_TYPE_OVERRIDE.md`.

V56.17 completes the temporary four-phase State First follow-up program:

1. V56.14 Study Recommendation Surface
2. V56.15 Study Method Recommendation
3. V56.16 Score / Baseline Evidence
4. V56.17 Learning Type Confirmation / Override

The temporary `docs/STUDY_STATE_FIRST_FOLLOWUP_IMPLEMENTATION_PLAN.md` is therefore removed in V56.17. Permanent versioned specs and this Product Spec are the authorities after merge.

## V56.18 Study Scenario Lab

V56.18 adds a Super Admin-only QA environment for reproducing Study states that are difficult for the product owner to personally generate through real-world learning.

The Lab is not a user-facing simulator.

It creates ordinary Canovia Plan / Task / Study records and then opens the normal Study Workspace.

Access requires both:

~~~text
admin.access
+
CANOVIA_STUDY_SCENARIO_LAB_ENABLED=true
~~~

If the explicit flag is disabled, the Lab returns 404 even for Super Admin.

Canonical route:

~~~text
GET /admin/study-scenarios
~~~

Fixture mutations:

~~~text
POST   /admin/study-scenarios/{scenarioKey}
DELETE /admin/study-scenarios/fixtures/{fixture}
DELETE /admin/study-scenarios
~~~

### Fixture identity

Lab-created Plans are tracked through `study_scenario_fixtures`.

A fixture stores:

- user_id
- plan_id
- scenario_key
- scenario_version

`user_id + scenario_key` is unique.

Recreate / delete operations use this fixture relationship. They never find Plans by title/category, so a real Plan with a similar name is not eligible for Lab cleanup.

### Initial presets

1. AP / current state known
   - 3 Practice Attempts
   - latest 90%
   - DB / network history
   - no confirmed Scope
2. AP / repeated knowledge gap
   - repeated DNS knowledge / concept gap
   - expected Resource Study
3. TOEIC 600 / external baseline known
   - current 480
   - Listening 250 / Reading 230
   - Practice 76%
4. TOEIC 600 / external baseline missing
   - Practice 84%
   - no external score Evidence
5. School test / Scope missing
   - previous score 62
   - target 80
   - no Study Scope
6. Memorization / Recall due
   - 6 Recall items
   - 4 due
7. Skill learning / Practical Evidence
   - Python CLI Task
   - no practical Evidence
8. Ambiguous Learning Type
   - 「英語を学ぶ」
   - no Learning Type override

These presets intentionally cover both Learning Type differences and State changes inside the same type.

### Data authority

Scenario state is built from the normal models:

- Plan
- Task
- StudyPracticeAttempt
- StudyScoreObservation
- StudyRecallItem

The Lab does not inject fake State into Study Workspace views.

Therefore V56.13–V56.17 must derive exactly the same UI/policy result as they would for a real learner with equivalent durable data.

### Mutation boundary

Lab index GET is read-only.

Creating/recreating a preset:

1. removes only the current Admin user's existing fixture for that scenario
2. deletes its fixture Plan and cascaded Study rows
3. creates a fresh normal Study Plan / Task / Evidence state
4. records the new fixture
5. redirects to normal Study Workspace

Delete-all removes only Plans referenced by the current Admin user's fixture records.

No AI/provider calls are made.

### Admin integration

When the feature flag is enabled:

- Admin navigation shows `Study Lab`
- Admin Dashboard shows a Study QA card

When disabled, both the navigation entry and dashboard card are hidden.

Detailed contract: `docs/V56.18_STUDY_SCENARIO_LAB.md`.

## V57.0 GitHub Integration Stabilization

V57.0 stabilizes the existing GitHub integration before Development State First.

The central rule is:

~~~text
GitHub unavailable
≠
Development unavailable
~~~

Repository read, review-only write, and automatic Return Sync are separate capabilities and readiness levels.

### Readiness

`GitHubIntegrationReadinessService` projects:

- Developer GitHub Evidence capability
- Developer GitHub Write capability
- GitHub App credential readiness
- Install URL readiness
- Webhook readiness
- async Queue readiness
- Repository installation state
- actionable next owner

Possible next owners include:

- PLAN / ENTITLEMENT
- CANOVIA OPERATOR
- GITHUB APP
- GITHUB / REPOSITORY ADMIN
- RENDER

Super Admin Free/Premium Preview restrictions are surfaced explicitly so operators do not mistake Preview policy for a broken GitHub App.

### Web capability denial

User-originated GitHub web actions remain fail-closed, but capability denial no longer drops the user onto an opaque 403 page.

Instead, the current GitHub / Development surface receives an actionable status message.

True Plan ownership/editor authorization remains 403/404.

### Diagnostics

`/admin/github` is a diagnostic surface and therefore must remain available even when one integration dependency is broken.

It separately reports:

- App ID / Private Key
- Install URL
- Webhook Secret
- Queue driver
- Queue storage schema
- `github_webhook_deliveries`
- connected Repositories
- worker observation
- diagnostic query failures

The diagnostics service uses the model-declared canonical webhook table name and converts schema/query failures into diagnostic facts instead of a 500.

### Interactive GitHub App vs automatic Return Sync

Interactive review write requires:

~~~text
DeveloperGithubWrite
+
App ID / Private Key
+
Install URL
+
Repository installation
+
Contents / Pull Requests write
~~~

Automatic Return Sync additionally requires:

~~~text
Webhook Secret
+
async Queue
+
queue storage
+
running Queue Worker
~~~

Worker health is not inferred from configuration alone. It becomes observed when real webhook deliveries are processed.

### Source-text integrity

File content sent to GitHub is source text, not ordinary prose input.

Canovia preserves leading/trailing whitespace and final newlines on GitHub file-body routes so a file that is already identical to the default branch is not misclassified because request middleware trimmed its content.

### Development architecture implication

V57.0 intentionally does not make GitHub the Development workflow controller.

GitHub becomes an optional State Sensor / Execution Provider for later Development stages.

A future Development State First layer can therefore support conception, specification, bootstrap, implementation, feedback, validation and release while only showing GitHub / PR surfaces when the current state actually needs them.

Detailed contract: `docs/V57.0_GITHUB_INTEGRATION_STABILIZATION.md`.



## V57.1 Developer Activity Observation

Connected Development repositories project authoritative GitHub facts into
`development_activity_observations` before Task Evidence exists.

Canonical boundary:

```text
GitHub webhook / bounded connection bootstrap
→ GitHub App REST re-fetch
→ DevelopmentActivityObservation
```

Observation is not Task Evidence and never advances Task progress. Raw webhook
payloads, source, diff, PR/Issue bodies and commit messages are not persisted.

Detailed contract: `docs/V57.1_DEVELOPER_ACTIVITY_OBSERVATION.md`.

## V57.2 Developer Task Association

V57.2 turns unresolved GitHub observations into deterministic Task candidates.

```text
Observation
→ deterministic candidate
→ Human Confirmation
→ PlanArtifact ↔ Task
→ authoritative GitHub re-fetch
→ existing TaskEvidence
```

No LLM chooses the Task and no relation is created without human confirmation.
Association still does not change Task progress/status/remaining time.

Detailed contract: `docs/V57.2_DEVELOPER_TASK_ASSOCIATION.md`.

## V57.3 Developer Home V1

Development Workspace becomes a State First Developer Home.

Primary hierarchy:

```text
Next Action
→ Recent GitHub Reality
→ Active Development
→ Release Readiness / Quality Gates
→ History
```

The existing Development Adaptive Action remains the authority for Next Action.
V57.1 observations supply recent GitHub reality and V57.2 supplies human-confirmed
Task association. Unfinished Tasks form Active Development.

Release Readiness remains authoritative but is secondary to daily execution.
Quality Gates remain available as detail instead of dominating the first screen.

Development onboarding ends after Plan creation. Missing GitHub Evidence may
still produce a GitHub-oriented Next Action, but it no longer blocks the rest of
Developer Home.

Workspace GET does not call GitHub or AI and does not create associations,
Evidence or Task progress. It may refresh local deterministic Task suggestion
metadata.

Detailed contract: `docs/V57.3_DEVELOPER_HOME_V1.md`.


## V57.4 Developer Execution Context

V57.4 deepens Developer Home by attaching a bounded, Task-scoped execution
context to the existing Development Adaptive Action.

Canonical flow:

```text
Development Adaptive Action
+ target / focus Task
+ confirmed Task ↔ GitHub Artifact links
+ persisted authoritative GitHub TaskEvidence
→ DevelopmentExecutionContextService
→ Action Context / Handoff surface
```

V57.4 does not create a second decision engine. V53.8 remains authoritative for
the next Action, target Task/Gate and success signals.

The context projection may show:

- repository full name
- branch / latest commit SHA
- Pull Request number / state / draft / URL
- CI state
- latest Review state / reviewer
- Issue state
- Deployment environment / status
- linked GitHub Artifacts
- recent GitHub Evidence summaries
- deterministic Evidence guidance for the current Action

Context is bounded to one unfinished Task. Evidence from another Task must not
leak into the current execution context even when both Tasks share a repository.

Developer Home GET reads only persisted Canovia state. It does not call GitHub
or AI, persist a context snapshot, create Evidence/associations, or mutate Task
progress/status/remaining time.

Detailed contract: `docs/V57.4_DEVELOPER_EXECUTION_CONTEXT.md`.


## V57.5 GitHub Connection & Private Repository Unification

GitHub App installation becomes the canonical Repository connection path for
Canovia Developer.

Connection/read and write are separate:

```text
DeveloperGithubEvidence
→ connect / verify GitHub App
→ Repository read
→ Development Evidence

DeveloperGithubWrite
→ optional Branch / Commit / Pull Request creation
```

A Repository is connected when the exact GitHub App installation grants
Contents and Pull Requests read access. Write readiness is projected
separately and requires write access to both.

Connected Repository inspection uses an installation access token and supports
both Public and Private Repositories. The old service-owned
`GitHubRepositoryInspector` remains public-only and is now only a limited
fallback preview for unconnected Public Repositories.

Developer Home may start GitHub App connection directly when a Repository root
already exists. Users never need to make a Private Repository public merely to
connect it to Canovia.

Write surfaces require both write entitlement and write-ready installation.
A read-only connected Repository remains valid for Evidence but cannot become
an Execution GitHub write target.

Detailed contract:
`docs/V57.5_GITHUB_CONNECTION_PRIVATE_REPOSITORY_UNIFICATION.md`.


## V57.6 Context-aware Implementation Brief

Developer Home now converts the canonical Development Adaptive Action and the
V57.4 Task-scoped Execution Context into a deterministic, copy-ready
implementation brief.

```text
Next Action
+ Task-scoped GitHub reality
→ Implementation / CI / Review / Deploy / Verification brief
→ HOW TO PROCEED
→ DONE WHEN
→ optional copy handoff
```

The brief is a presentation and handoff layer, not a second decision engine.
Developer Home GET does not call GitHub or AI to generate it and does not
persist source code, diff, PR / Issue / Review body, or a new brief record.

Unknown provider facts remain unknown. Task progress, status and remaining time
are not mutated. GitHub writes still require the existing entitlement,
installation permission and human-confirmation boundaries.

Canonical contract:
`docs/V57.6_CONTEXT_AWARE_IMPLEMENTATION_BRIEF.md`.


## V57.7 Provider-linked CI / Review Triage

Developer Home may now cross from bounded Development State into provider text
only after an explicit user action.

```text
CI failure / Review changes requested
→ explicit provider-detail request
→ GitHub App read
→ transient CI annotations / Review comments
→ deterministic Task-scoped triage
```

Normal Developer Home GET remains provider-call-free.

CI triage may read bounded failed Actions jobs, failing steps, Check Runs,
Check annotations and Commit Status detail where GitHub App permissions allow.

Review triage may read bounded Review bodies and inline review comments for the
current Pull Request.

These provider details are response-only and are not persisted to TaskEvidence,
PlanArtifact metadata, Intelligence snapshots, Decision traces or a dedicated
triage table. The response is marked `no-store`.

Only Plan editors/owners with Developer GitHub Evidence access may request
provider triage.

The triage page may create a user-copyable handoff, but Canovia does not invoke
a coding agent or perform GitHub writes automatically.

Canonical contract:
`docs/V57.7_PROVIDER_LINKED_TRIAGE.md`.


## V57.8 Opt-in Coding Agent Handoff

A Development Implementation Brief can now enter the existing Execution
Orchestration flow after explicit user confirmation.

```text
Development Brief
→ user confirms scope
→ canonical Execution Request
→ external Execution Orchestration prompt
→ user-selected Coding Agent
```

V57.8 does not start a provider automatically and does not add a second
Execution / Provider architecture.

The confirmed request uses source type
`development_implementation_brief`, actor type `external`, and the existing
Execution Context fingerprint / Dependency / protected-scope rules.

V57.7 provider text remains transient and is not automatically copied into
session state. Moving from Provider Triage into Coding Agent Handoff rebuilds
the bounded V57.6 Implementation Brief server-side.

No Task progress, GitHub write, merge or deploy is performed by preparing the
handoff.

Existing GitHub Return Evidence and future ProviderConnection Activity intake
remain the return paths.

Canonical contract:
`docs/V57.8_OPT_IN_CODING_AGENT_HANDOFF.md`.


## V57.9 Compact Desktop Header

Desktop no longer dedicates a second sticky row to Workspace switching.

```text
Canovia + compact Workspace selector
+ primary navigation
+ utilities
→ one desktop header row
```

The Workspace selector remains registry-driven and globally available, but its
desktop trigger/menu are visually compact. Workspace routing, persistence,
telemetry and Instant Navigation synchronization are unchanged.

Primary navigation and utility spacing are also tightened on desktop only.

Mobile keeps the existing compact touch-first Workspace selector and is not
redesigned by this phase.

Canonical contract:
`docs/V57.9_COMPACT_DESKTOP_HEADER.md`.


## V58.0 Developer Workspace Focused Surfaces

Developer Workspace follows the daily-use rule:

```text
one screen = one information / operation lineage
```

The old vertically stacked Developer Home is replaced by five server-rendered
surfaces:

```text
今やること / リポジトリ / チーム / 改善 / プレビュー
```

Only the selected `?surface=` is rendered.

The default Work surface keeps Next Action first and moves detailed Execution
Context / Implementation Brief and additional active Tasks behind explicit
details controls.

Repository opens a bounded GitHub App tree projection only when explicitly
selected. It reads path/type/size metadata, never source file contents, and does
not persist the tree.

Team uses existing Plan collaboration facts, assigned artifacts, linked Tasks
and recent activity. It does not infer Task ownership.

Improvements are deterministic from existing connection / activity / readiness /
paused-Task facts; opening the surface does not call AI.

Preview stores one explicit external-link PlanArtifact and loads its sandboxed
iframe only after a user click. The server never fetches the Preview URL and an
external-open fallback remains for sites that reject framing.

Canonical contract:
`docs/V58.0_DEVELOPER_WORKSPACE_SURFACES.md`.


## V58.1 Developer Workspace Navigation IA

Developer Workspace no longer assumes every surface is a permanent peer-level
horizontal tab.

Current implemented surfaces are grouped by lifecycle:

```text
実行         → 今やること
設計         → 改善
プロジェクト → リポジトリ / チーム
観測         → プレビュー
```

The compact Developer shell exposes one Plan selector and one grouped View
selector. The selected category is derived from the selected surface and shown
as a small location badge.

The existing `?surface=` URL contract remains unchanged. There is no separate
category query state.

Future categories such as `自動化` are not rendered until a real surface
exists. This prevents the global navigation from becoming a row of disabled or
speculative destinations.

Canonical contract:
`docs/V58.1_DEVELOPER_NAVIGATION_IA.md`.


## V58.2 Study / Developer Mode Top Hubs

Study / Developer now separate mode-level navigation from Plan-scoped daily
work.

Canonical hierarchy:

```text
Study Top / Developer Top
→ choose Plan
→ inspect progress / preparation / integration state
→ open one Plan Workspace

Plan Workspace
→ work inside the selected Plan
→ one screen = one information / operation lineage
```

New routes:

```text
GET /workspace/study/top
GET /workspace/development/top
```

Each specialized Plan Workspace exposes a compact top-left escape link:

```text
← 学習トップへ
← 開発トップへ
```

Study Top owns:

- Study Plan list
- weighted Plan progress
- deadline / status
- active / total Task counts
- Study Scope entry
- Resources entry
- Study Scores entry
- Study Plan creation

Developer Top owns:

- Development Plan list
- weighted Plan progress
- deadline / status
- active / total Task counts
- registered Repository
- persisted GitHub App connection state
- GitHub / Evidence setup entry
- Development Plan creation

Mode Top GET does not run Study / Development Adaptive Action for every Plan,
does not call AI, and Developer Top does not perform remote GitHub API reads.

Plan selection moves out of the specialized Plan Workspace. Study Workspace is
therefore Plan-local, while Developer Workspace keeps the V58.1 grouped View
selector but no longer exposes a second Plan selector.

Canonical contract:
`docs/V58.2_STUDY_DEVELOPER_MODE_TOPS.md`.


## V58.3 Study Workspace Focused Surfaces

Study Plan Workspace now follows the same daily-use information architecture as
Developer Workspace:

```text
one screen = one information / operation lineage
```

The selected Study Plan is split into four real focused Views:

```text
実行 → 今やること
準備 → 学習準備
分析 → 学習分析
記録 → 履歴
```

Canonical URL contract:

```text
/workspace/study?plan_id=...&surface=work
/workspace/study?plan_id=...&surface=preparation
/workspace/study?plan_id=...&surface=analysis
/workspace/study?plan_id=...&surface=history
```

Missing / unknown `surface` falls back to `work`.

The existing Study State First engine remains authoritative. V58.3 does not add
a second readiness/recommendation model; it only projects the already composed
Study surfaces into the selected View.

The default Work View keeps only execution-relevant information:

- missing context that changes execution
- recommended Study method / Study recommendation
- current action
- alternative Study methods
- existing Execution Setup / state-change feedback

Preparation owns the selected Plan's Study Scope, Resources, external Scores and
Plan-detail inputs.

Analysis owns Current State, Readiness, Biggest Gap, weaknesses, recent results
and Scope Coverage.

History owns the existing Decision / Action / State history and is no longer a
permanent tail on every Study Workspace screen.

Study Top remains responsible for multi-Plan selection.

Canonical contract:
`docs/V58.3_STUDY_WORKSPACE_SURFACES.md`.

## V58.4 Specialized Mode Top Canonical Entry

Study / DeveloperのMode-level entryは、V58.2で導入したMode Topを正規入口とする。

```text
Workspace Mode select / ephemeral entry / manual preference resume
→ Study Top / Developer Top
→ Planを選択・準備
→ selected Plan Workspace
```

Study / DeveloperのPlan Workspace deep linkは引き続き直接開ける。Plan作成後、
Current Action、Study Practice / Recall、DeveloperのPlan-local surfaceなど、
対象Planが明示された導線をMode Topへ戻す変更は行わない。

generic ephemeral entryはconcrete Workspace routeとの衝突を避けるため:

```text
GET /workspace/mode/{workspaceMode}
→ workspace_modes.enter
```

へ移動した。Overview / Careerの入口契約は従来どおり。

V58.4はrouting-onlyであり、Workspace preference persistence、Study /
Development Intelligence、AI / GitHub provider traffic、Task / Plan state、
entitlement / billing behaviorを変更しない。

Canonical contract:
`docs/V58.4_SPECIALIZED_MODE_TOP_ENTRY.md`.

## V58.5 Specialized Mode Top Daily-use Density

V58.4でStudy / Developer Topが正規入口になったため、Mode Topを日常利用向けの
compact hubへ整理する。

```text
Mode Top
→ Plan名 / 進捗 / 状態をcompact rowで一覧
→ primary Open
→ setup / detailは必要時だけ展開
```

Studyは範囲・教材・成績・Plan詳細をnative `<details>` 配下へ移し、
DeveloperはRepository / persisted GitHub connection stateをrow内へinline表示する。
GitHub setupは利用可能な場合に直接到達可能なまま維持する。

Planごとのnested `page-card` は廃止し、Top headerとDeveloper Integration表示も
compact化する。

V58.5はpresentation-onlyで、Plan ordering / progress、Study / Development
Intelligence、AI / provider traffic、Task / Plan state、Workspace preference、
entitlement / billingを変更しない。

Canonical contract:
`docs/V58.5_SPECIALIZED_MODE_TOP_DENSITY.md`.

## V58.6 Recall Task Progression

Recall-primaryなStudy Taskでは、既存Recall Deckの実成績をTask progressionへ
接続する。

```text
Recall review
→ Deck全体が既存mastery条件を満たす
→ due 0
→ Task完了候補
→ user明示確認
→ Task done
→ 既存Study next-Task選択
```

Recall review単体ではTaskを自動完了しない。RecallがPrimary ActivityでないTaskでは
Deckは補助学習のままで、Task完了Signalには使わない。

Review済み判定は `last_reviewed_at` を使う。`Again` はrepetitionsを0へ戻すが、
実際にreviewした事実まで未学習扱いにはしない。

Task完了前にserver-sideでTask / active Recall cardをlockし、eligibilityを再評価する。
明示完了時は `study_recall_mastery_confirmed` Evidenceをidempotentに記録する。

Question Practice progression、Recall scheduler、AI/provider、entitlement / billingは変更しない。

Canonical contract:
`docs/V58.6_RECALL_TASK_PROGRESSION.md`.

## V58.7 Recall Source Retry

失敗したRecall Candidate抽出は、保存済みのprivate
`StudyRecallSource` から明示的に再実行できる。

```text
failed saved Source
→ user Retry
→ same Source
→ existing Candidate extraction
→ Human Review
```

Retryはfailed Sourceだけに限定し、Plan / Task ownership、
`AutomaticAiExecution`、Source所属、保存済み教材の存在をserver-sideで再確認する。

textは `source_text`、image / PDFは既存private `storage_path` を再利用する。
同じ教材の再アップロードは不要。

Candidate重複防止は既存の `task_id + fingerprint(prompt|answer)` を維持し、
retry専用のCandidate状態は追加しない。

Plan Resourceは共有URL参照のまま維持する。V58.11では任意URLをserver fetchせず、
Resourceを出典として選んだ上で、ユーザーが明示的に渡したfile/textだけを
StudyRecallSourceへ保存・抽出するtrusted material handoffを実装した。

Migration、Recall scheduler、Task progression、billingは変更しない。

Canonical contract:
`docs/V58.7_RECALL_SOURCE_RETRY.md`.

## V58.8 Recall Batch Ingest

Recall教材は2〜5個の画像 / PDFを一度に選択し、1回のNative AI実行で
Candidate抽出できる。

```text
multiple files
→ one Source per file
→ one Native AI run
→ Candidate.source_index
→ Human Review
```

既存の `1 StudyRecallSource = 1 file` は維持し、batch専用tableやmigrationは
追加しない。各Candidateは最も直接の根拠となるSourceへ紐づく。

制限は1ファイル10MB、合計20MB。Candidate重複防止は既存の
`task_id + fingerprint(prompt|answer)` を維持する。

provider失敗時は全Sourceをfailedとして保存し、同じfailed NativeAiRun IDを保持する。
その後はV58.7でSourceごとに個別再抽出できる。

単一file / pasted text、Human Review、Recall scheduler、V58.6 progression、
entitlement / billingは変更しない。

Canonical contract:
`docs/V58.8_RECALL_BATCH_INGEST.md`.

## V58.9 Recall Candidate Outcome Trace

AI生成Recall Candidateの出自と、その後の実Recall Reviewを直接追跡できる。

```text
Candidate
→ Human Review
→ Recall Item
→ Recall outcome
→ Evidence lineage / outcome projection
```

新しい `study_recall_reviewed` Evidenceは、Itemに紐づくpromoted Candidate群の
Candidate ID / Source ID / original confidenceをbounded ordered arrayとして保持する。
手動カードでは空配列になる。

複数Candidateが同じRecall Itemへ統合された場合も、Candidate lineageは全て残す一方、
Review件数などTask-level outcomeはunique Item単位で集計して二重計上しない。

`StudyRecallCandidateOutcomeService` はprovider-freeに
unobserved / developing / needs_reinforcement / retainedを投影し、
original AI confidenceと実Recall結果を別軸で可視化する。

Recallの難しさは内容・学習状態にも依存するため、V58.9はCandidate confidenceを
自動変更せず、「Recall成績が悪い = Candidate品質が悪い」とは判定しない。

Migration、Candidate extraction、Human Review、Recall scheduler、
V58.6 Task progression、billingは変更しない。

Canonical contract:
`docs/V58.9_RECALL_CANDIDATE_OUTCOME_TRACE.md`.

## V58.10 Study Language Activities

Study Activity PolicyへListening / Dictation / Shadowingをfirst-class Activity
として追加する。

```text
Task semantics
→ Listening / Dictation / Shadowing
→ dedicated language Activity surface
→ existing Resourceを開く
→ explicit self-report
→ study_language_activity_completed Evidence
```

明示的な「リスニング問題演習」はQuestion Practiceを維持し、純粋な音声理解・
書き取り・shadowing Taskだけをlanguage Activityへ送る。

V56.15 State-aware recommendationはTaskが明示するlanguage Activityを保持する。
Execution Ecosystemには `study.language` capabilityと
`canovia.study.language` native providerを追加する。

Activity実施結果はrounds / outcome_ratingをconfidence 0.6の自己評価Evidenceとして
記録するが、Task進捗・完了は自動変更しない。free-text reflectionはEvidenceへ保持しても
normalized Intelligence factsへは渡さない。

V58.10はマイク録音、音声認識、発音採点、Dictation自動採点、remote Resource fetchを
実装しない。Language Activity自体は非AIなのでFree pathで利用できる。

Canonical contract:
`docs/V58.10_STUDY_LANGUAGE_ACTIVITIES.md`.

## V58.11 Safe Plan Resource → Recall Handoff

Plan ResourceをRecallへ接続する際、外部URLをCanovia serverが自動取得しない。

```text
PlanResource
→ user selects Recall handoff
→ explicit local file / pasted text
→ StudyRecallSource.plan_resource_id
→ existing Candidate extraction
→ Human Review
```

`study_recall_sources.plan_resource_id` をnullable FKとして追加し、
Resource由来Sourceのprovenanceを保持する。Resource削除時はnullOnDeleteとし、
既存Recall historyは残す。

利用可能Resourceはsame Planのfileで、current Taskに紐づくもの、または
Task未割当のPlan-level Resourceだけ。別Task専用Resourceとcross-Plan Resourceは
server-sideで拒否する。

Resource URLはNative AI inputにもHTTP fetchにも使用しない。
抽出対象はユーザーが確認して渡したPDF/image/textだけ。

一般PlanResource device uploadは解禁せず、Resourceのlightweight reference contractは
維持する。

Canonical contract:
`docs/V58.11_SAFE_RESOURCE_RECALL_HANDOFF.md`.

## V58.12 Study Activity Outcome Observation

Study Activityの実利用結果を、同じTask内の連続Practice採点を使って
before / after観測できるようにする。

```text
Practice assessment
→ one tracked Activity type
→ next Practice assessment
→ descriptive score delta
```

対象はQuestion Practice / Recall / Resource Study / Listening / Dictation / Shadowing。
Resource StudyはV58.14の明示完了Evidenceだけを観測し、Resource URLを開いただけでは学習実施とみなさない。

Recall Reviewや同一Language Activityが複数回あっても、同じPractice間では
1つのscore observationとして扱う。複数Activityが混ざった区間、14日超の区間、
invalid score、別actor Evidenceは比較から除外する。

UIでは「実利用でのActivity観測」として平均・最新のbefore / afterを見せるが、
問題難度・外部学習等の交絡を含むため因果効果とは扱わない。

V58.12の観測値はV58.15で、同一Task・同一actorの明示Activityに十分な比較が
たまった場合だけStudy Method fitへ最大±5ptの補助Signalとして利用する。
因果効果とは扱わず、Primary Methodは自動切替しない。
Practice Reliability、Task進捗、masteryは変更しない。

Canonical contract:
`docs/V58.12_STUDY_ACTIVITY_OUTCOME_OBSERVATION.md`.

## V58.13 Question Candidate Reliability Signal

AI Practice Reliabilityの「出題内容」へ、Question Candidateの運営実績を
bounded signalとして反映する。

```text
Candidate Human Review
(promoted / rejected)
+ promoted Questionの実Practice再利用
→ evidence maturity
→ Native / Hybrid question_qualityへ最大±6pt
```

reviewed Candidate 5件未満では点数を変更しない。20件review / 20回assessed reuseを
それぞれmaturity上限とし、Human Reviewを75%、再利用量を25%でSignal強度へ反映する。

promotion rate 70%をneutral centerとするが、これは統計的な真のQuestion品質ではなく
運営上のcalibrationである。

学習者の正答率・弱点・Task masteryはQuestion品質へ使わない。
難しい良問の正答率が低い可能性を品質低下と誤解しないためである。

Question Bank / external AIのbaselineはCandidate補正しない。
Native AI / Hybrid AIのみ既存question_quality baselineへ小幅補正する。

No migration / no provider call。

Canonical contract:
`docs/V58.13_QUESTION_CANDIDATE_RELIABILITY.md`.

## V58.14 Resource Study Completion Evidence

Resource Studyをgeneric Resource libraryへのリンクだけで終わらせず、
専用Execution Surfaceと明示完了Evidenceへ接続する。

```text
Resource Study recommendation
→ dedicated surface
→ Resourceを開く
→ explicit outcome POST
→ study_resource_study_completed
→ V58.12 observation
```

Resource URLを開いただけではEvidenceを作らない。
Task-linked Resourceを優先し、Taskに紐づくResourceがない場合だけTask未割当の
Plan-level Resourceを候補にする。submitted resource_idはserver-sideで同じeligible
contextへ再照合し、cross-Plan / other-Task-only Resourceを拒否する。

Evidenceはconfidence 0.65の自己申告Activity factであり、Task進捗・完了・masteryは
自動変更しない。normalized Intelligence factsへはresource_id / outcome_ratingだけを通し、
Resource title / URL / reflectionは渡さない。

V58.12はこのEvidenceをResource Studyとして追跡し、waiting / observedの通常Activityへ
昇格する。Recommendation scoring / Practice Reliabilityは変更しない。

No migration / no provider call / Free path。

Canonical contract:
`docs/V58.14_RESOURCE_STUDY_EVIDENCE.md`.

## V58.15 Study Method Outcome Calibration

V58.12で観測した実利用Activityのbefore / afterを、Study Method fitへ
bounded secondary signalとして接続する。

対象は明示Activityのみ:

- Recall
- Resource Study
- Listening
- Dictation
- Shadowing

Question Practiceは「他Activity Evidenceが無かった区間」であり、clean interventionとは
みなせないためcalibration対象外。

同一Task / 同一actorで3比較未満は観測だけ。
3比較以上で直近最大5件を使い、score delta中央値と方向一致率を確認する。
±5pt未満の中央値はneutral、方向が2/3未満ならmixedとして補正しない。

fit補正は最大±5pt。
V56.15のPrimary Method選択後に適用するため、Primary keyは変更しない。
代替Methodの並びと表示fitだけが個人の実利用Signalで小幅に変化する。

DB-only / read-only / provider-free / no migration。

Canonical contract:
`docs/V58.15_STUDY_METHOD_OUTCOME_CALIBRATION.md`.

## V58.16 AP Subject A Coverage Dashboard

AP科目A Planの複数Taskを横断し、採点済みQuestion Bank実績からPlan-wide Coverage / 正答観測をStudy Analysisへ表示する。

公式CoverageとPerformanceは分離する。

- 公式Coverage: reference公式Pack内で採点済みのユニーク問題数 / Pack収録数
- 正答観測: Core Packを含むQuestion Bank全採点exposureのdomain別correct / partial / incorrect

同じ公式問題を繰り返してもCoverageは増えない。Core Pack問題は正答観測には入るが公式Coverageには入らない。

current AP公式Pack metadataではテクノロジ20 / マネジメント5 / ストラテジ10 / total35をreference denominatorとして利用する。これはinstalled Packの母数であり、普遍的な試験配点とは扱わない。

Study Analysisへregistered surface `ap_subject_a_coverage` を追加する。Plan-wide domain / parent-topic観測はread-onlyで、Task-local Weakness Priority / Routing / Task進捗 / Masteryを変更しない。

最新最大200 Session / same Plan / same actor限定。DB-only / provider-free / no migration。

Canonical contract:
`docs/V58.16_AP_SUBJECT_A_COVERAGE_DASHBOARD.md`.

## V58.17 Study Practice 50 / 100 Cumulative Checkpoints

Study Practiceの10問単位Sessionを維持したまま、current Taskの採点済みquestion_feedbackを累積して50問 / 100問Checkpointを表示する。

Providerを問わず `correct / partial / incorrect` の構造化Feedbackだけを問題数として数える。同一Attempt内の同じquestion_idは1回だけ。

50問・100問到達時は最初のN問を時系列で固定して正答観測Snapshotを作るため、51問目以降や101問目以降の結果で過去Checkpoint値は変わらない。

Question Bank問題はdurable question idがある場合だけunique / repeated exposureを分離する。AI生成問題は累積問数には入るがfake uniquenessは作らない。

current Plan / current Task / current actor限定、oldest最大500 Attempt。read-only / DB-only / provider-free / no migration。

V58.17はTask進捗、完了、Weakness Priority、Exam Convergence、Routing、Masteryを変更しない。50/100問到達を自動的な弱点補強開始条件にはしない。

Canonical contract:
`docs/V58.17_STUDY_PRACTICE_CUMULATIVE_CHECKPOINTS.md`.

## V58.18 Plan-wide Weakness Handoff after 100 Questions

V58.17のcurrent Task 100問Checkpointと、V58.16のsame-Plan AP科目A parent-topic観測を接続し、次回の分野横断Practiceへboundedな再確認Signalを渡す。

適用には以下をすべて要求する。

- AP科目A Plan
- current Taskで100 graded questions到達
- parent topicで3 exposure以上
- 2 unique Question Bank questions以上
- observed correctness <60%
- task_mode = broad_assessment
- deterministic policy phase = general_practice
- mastery verificationではない

既存Task-local `broad_recheck_topics`を最優先し、Plan-wide候補は未使用枠だけを埋める。デフォルト最大2問。General Practiceの`focus_topics`は空のまま、残り問題は横断探索を維持する。

current Taskでcooldown / masteredのTopicはPlan-wide履歴から復活させない。

Strategy snapshotへ `routing_policy.plan_wide_weakness_handoff` を保存し、実際に適用された時だけPractice UIへ表示する。

Task progress / completion / mastery / Study Method / V56.0 phaseは変更しない。No migration / no extra AI call。

Canonical contract:
`docs/V58.18_PLAN_WIDE_WEAKNESS_HANDOFF.md`.

## V58.19 Weakness Intervention Outcome Observation

Focused weakness reinforcementの実利用効果を、補強中の点数ではなく「補強前Broad Practice → 補強終了後Broad Practice」の同Topic結果で観測する。

same Plan / same Task / same actor、最新最大120 Attemptを対象とする。

baseline / afterはそれぞれ最大5問、最低2問かつ2 unique question refsを要求する。correct / partial / incorrectだけを使い、partialはcorrectに含めない。

補強中のTopic得点はintervention contextとして表示するだけで、after判定には使わない。

before / afterが揃った場合:

- +15pt以上: improved observation
- -15pt以下: regressed observation
- それ以外: stable observation

これは因果効果の証明ではなくread-only観測。Weakness Priority / Exam Convergence / Routing / V58.18 / Mastery / Task進捗を変更しない。

Study Analysisへregistered surface `weakness_intervention_outcomes` を追加する。

DB-only / provider-free / no migration。

Canonical contract:
`docs/V58.19_WEAKNESS_INTERVENTION_OUTCOMES.md`.


## V58.20 Release Level Foundation

Early Access staged releaseのruntime foundationを追加する。

Canonical contract:

- `docs/CANOVIA_RELEASE_LEVEL_SPEC.md`
- `docs/V58.20_RELEASE_LEVEL_FOUNDATION.md`

実装:

```text
Public Release Level
+ User Access Override
+ Super Admin Release Preview
→ ReleaseLevelService
→ Workspace availability / route enforcement
```

初期Workspace minimum:

- Overview: Level 0
- Study: Level 1
- Development: Level 1
- Career: Level 3

Foundation導入だけで現在の公開面を変えないため、default Public LevelはLevel 4とする。Early Access開始時に `CANOVIA_PUBLIC_RELEASE_LEVEL` を明示的に1または2へ下げる。

既存Free/Premium Admin PreviewはEntitlement体験のPreviewとして維持し、Release Previewとは別Session / 別軸で動作する。

User overrideはLevel 3まで。Level 4 Internal PreviewはSuper Admin専用。

Level不足時はCareer専用Workspace / Career mutationをserver-sideでも制限し、保存済みCareer preferenceは削除せずOverviewへfallbackする。

V58.20ではPublic LevelのDB管理、percentage rollout、Pricing / Premium / Pro runtime移行、Career UI完成は行わない。


## V58.21 Release Gate / Feature Inventory

Early Accessへ向けて、V58.20 Release Level Foundationをread-only Release Gateへ拡張する。

Canonical detail:

- `docs/V58.21_RELEASE_GATE_FEATURE_INVENTORY.md`
- `docs/CANOVIA_RELEASE_LEVEL_SPEC.md`
- `docs/CANOVIA_MONETIZATION_SPEC.md`

### Product decision

```text
L1 Early Access Core
= Study / Developmentの現在の安定Core

L2 Product Preview
= Premium / Pro / Dev ProのComing Soon presentation
= paid capability unlockではない

L3 Beta Expansion
= selected beta capability / Career Beta

L4 Internal Preview
= unfinished / mutation / internal-only capability
```

L2へFeature capabilityを割り当てない。
特にPremium / Pro / Dev ProはEarly Access時点で購入・利用を解放せず、体験差をPreviewとして見せる。

Developer GitHub WriteはRepository mutationを伴うため、V58.21ではL4 Internal Previewに固定する。

### Release Gate

Adminにread-only Release Gateを追加する。

```text
admin.release_gate.index
```

Release Gateは:

- Feature inventory整合
- Entitlement inventory整合
- Workspace minimum整合
- required route存在
- Levelごとのrequired Feature / Workspace maturity

を自動検証する。

一方、mobile実機、500/403、rollback、copy、telemetry、feedback等はmanual checkとして残し、自動昇格しない。

Initial Early Access targetはL1。

L2はcanonical Product Preview route `product.preview.index` が未実装のため、V58.21時点では意図的にBlockedとする。

この実装だけではPublic Release Levelを変更しない。


## V58.22 Product Preview

L2 Product Preview Surfaceを実装する。

Canonical detail:

- `docs/V58.22_PRODUCT_PREVIEW.md`
- `docs/CANOVIA_MONETIZATION_SPEC.md`
- `docs/CANOVIA_RELEASE_LEVEL_SPEC.md`

Product Preview:

```text
GET /product-preview
route = product.preview.index
release minimum = L2
```

役割はpaid capabilityの解放ではなく、Free / Premium / Pro / Dev Proの体験差をEarly Accessユーザーへ説明すること。

FreeのみAvailable。
Premium / Pro / Dev ProはComing Soonとし、価格・正式提供時期・checkoutを持たない。

Study / Developmentはfeature tableではなく処理フローで差を見せる。
将来Preview動画を追加する場合も同じExperience contractをpresentation sourceとして利用できる。

Account EntryはL2以上だけ表示。
L1以下はdirect routeもRelease Level boundaryで制限する。

閲覧は `product_preview_viewed` をserver-sideで安全に記録する。

V58.22後、Release GateのL2 structural blockerは解消し、Initial Early Access planning targetをL2へ更新する。
この変更だけではProduction Public Levelを変更しない。


## V58.23 Early Access Observability

L2 Early Access candidateへ最低限の運営・観測Layerを追加する。

Canonical detail:

- `docs/V58.23_EARLY_ACCESS_OBSERVABILITY.md`
- `docs/CANOVIA_RELEASE_LEVEL_SPEC.md`

User-facing:

- Early Access disclosure
- Feedback immediate CTA
- L2 Product Preview link
- Feedback contextへrelease levelを付与

Telemetry:

```text
early_access_registered
early_access_session_started
```

Activationはevent instrumentationだけに依存せず:

```text
User
→ first_run_completed_at
→ Plan
→ Task
→ WorkStarted
→ WorkCompleted
```

のDB fact / existing execution eventsから集計する。

Admin:

```text
GET /admin/early-access
```

でActivation、D1 / D7、Daily Active、Product Preview、Feedback mixを確認できる。

この実装だけではPublic Release Levelを変更しない。


## V58.24 Release Review

Early Access L2 candidateのManual Release Gateを永続化する。

Manual Checkはstable keyを持ち:

```text
Pending
Passed
Failed
```

としてAdmin Release Gate上で管理する。

保存:

- release level
- check key
- status
- note
- reviewer
- reviewed at

L2はL0 + L1 + L2の全Manual CheckがPassedかつAutomatic Gateが通過した場合のみ:

```text
READY FOR RELEASE
```

となる。

READYでもPublic Release Levelは自動変更しない。

またL2→L0 downgrade時にStudy / Development専用Surfaceを隠しても、既存Plan / Taskを保持しCore flowから利用できることをregression testで固定する。


## V58.25 Personalization Bootstrap

新規ユーザーの0→1 frictionを減らすPersonalization Bootstrapを追加する。

Canonical:

- `docs/CANOVIA_PERSONALIZATION_SPEC.md`
- `docs/V58.25_PERSONALIZATION_BOOTSTRAP.md`
- `docs/wip/PERSONALIZATION_BOOTSTRAP_IMPLEMENTATION.md`

Core principle:

```text
Initial Diagnosis is a starting hypothesis, not a permanent profile.
```

初回診断は固定プロフィールではなく、伴走開始の初期仮説。

Phase 1:

```text
Welcome
→ Study / Development / unsure diagnosis
→ source-aware Personalization Context
→ deterministic Plan Seed
→ existing Plan create
```

Personalization Contextは:

```text
self_reported
observed
inferred
```

を混同しない。

Phase 1ではself_reportedのみをBootstrapから更新し、
observed / inferred behavior engineは後続Phaseへ残す。

Plan思想との対応:

```text
Initial Diagnosis = Initial Plan
Observed Behavior = Actual Result
Context Update = Replanning
```

GitHubはDevelopment actorでreadiness signalがある場合だけValue Previewを出し、
Interestを保存する。接続は強制しない。

既存ユーザーへは強制せず、Accountから任意起動する。

PersonalizationはL1 Early Access Core以上で公開する。
L0では従来のPlan作成フローへfallbackする。


## V58.26 Capability Activation Foundation

PersonalizationのFeature Eligibilityを、実際のCapability利用まで段階的につなぐ。

```text
Need
→ Preview
→ Interest
→ Readiness
→ Setup
→ Completed
```

GitHub Integrationを最初の実例とする。

Canoviaは:

- capabilityを提案する
- readinessを説明する
- next ownerを示す
- setup lifecycleを記録する

既存GitHub Workflowは:

- Repository登録
- GitHub App install
- connection verification
- sync

を担当する。

接続完了はobserved Contextとして記録するが、
Initial Diagnosis / self-reported experience / Guidanceを自動変更しない。

Canonical:

- `docs/V58.26_CAPABILITY_ACTIVATION_FOUNDATION.md`
- `docs/CANOVIA_PERSONALIZATION_SPEC.md`
- `docs/wip/PERSONALIZATION_BOOTSTRAP_IMPLEMENTATION.md`


## V58.27 Living Profile Foundation

Personalizationを初回Onboardingで終わらせず、
実利用からContext Update Candidateを作るFoundationを追加する。

```text
Initial Diagnosis
→ Actual Behavior
→ Context Update Candidate
→ Replanning
```

低リスク:

- recent Workspace
- recommended Surface ordering

は自動適用可能。

高影響:

- advanced capability exposure
- experience interpretation
- Plan direction

はUser Confirmation境界を持つ。

V58.27ではGitHub接続を根拠に
「高度なDevelopment支援を表示する」候補まで実装する。

これはExperience再分類ではない。
Self-reported experience / Guidanceは維持する。

Canonical:

- `docs/V58.27_LIVING_PROFILE_FOUNDATION.md`
- `docs/CANOVIA_PERSONALIZATION_SPEC.md`
- `docs/wip/PERSONALIZATION_BOOTSTRAP_IMPLEMENTATION.md`


## V58.28 Study Behavior Personalization

Living ProfileへStudyの実演習行動を最初のDomain Adapterとして接続する。

```text
assessed Study Practice x3+
→ observed practice_focused
→ low-risk derived readiness
→ Growth Experience
```

重要:

- 能力レベル再分類ではない
- 初回Study stageを上書きしない
- Guidance Levelを変更しない
- Plan / Task progressを変更しない
- Study Strategy / weakness modelを変更しない

同一Study Planの評価済み演習が3回未満ではLiving Profileを変更しない。
3回目以降は観測値を更新するが、同じContext Update Candidate / Auto Apply telemetryを増殖させない。

Canonical:

- `docs/V58.28_STUDY_BEHAVIOR_PERSONALIZATION.md`
- `docs/CANOVIA_PERSONALIZATION_SPEC.md`

## Future Architecture boundary

Long-term Architecture direction is documented under `docs/future/`.

Start at:

- `docs/future/CANOVIA_FUTURE_ARCHITECTURE_OVERVIEW.md`

Future documents are architecture-preservation context only.

They do not:

- replace this Product Spec
- make Future components current requirements
- authorize OAuth / Shared Context / Opportunity Engine / Automatic Improvement / Product Factory implementation

Current behavior continues to be defined by latest main + Active Specs.


## V58.29 Plan Lifecycle Personalization

Living ProfileへCanovia CoreのPlan lifecycle節目を接続する。

```text
Plan created
→ trigger: new_plan

all active Tasks completed
→ trigger: plan_completed
```

Plan completionは新しいPlan statusではなく、既存Task stateから観測する。

V58.29は:

- Plan / Taskを自動変更しない
- self-reported Personalizationを変更しない
- Guidanceを変更しない
- user without Personalization Contextをsilently profileしない
- collaborator actionでowner Contextを変更しない

同じPlanのcompletionはbounded Plan ID memoryで重複抑止する。
reopen / re-complete revisionは後続evidence fingerprint contractへ分離する。

Canonical:

- `docs/V58.29_PLAN_LIFECYCLE_PERSONALIZATION.md`
- `docs/CANOVIA_PERSONALIZATION_SPEC.md`


## V58.30 Plan Completion Fingerprint

V58.29のPlan completion memoryをmaterial structural fingerprintへ拡張する。

```text
first completion
→ revision 1

reopen / re-complete with same structure
→ no new revision

material structure change
→ re-complete
→ revision + 1
```

Fingerprintはruntime progress/statusではなく、Plan条件・Task内容・見積・優先度・依存関係などのmaterial structureを対象にする。

V58.29の既存completion ID memoryはsilent baseline化してから新revision判定へ移行する。

また、最後の未完了Taskをcancelledにした結果、残るnon-cancelled Tasksが全て完了している場合もPlan completionを成立させる。

V58.30は:

- Plan / Taskを自動変更しない
- AI semantic diffをしない
- user-facing completion historyを追加しない
- automatic replanningをしない

Canonical:

- `docs/V58.30_PLAN_COMPLETION_FINGERPRINT.md`
- `docs/CANOVIA_PERSONALIZATION_SPEC.md`


## V58.31 Return-after-Absence Personalization

Living Profileへ長期離脱後の復帰Triggerを追加する。

```text
first tracked visit
→ presence baseline only

<14 days
→ normal presence update

>=14 days
→ return_after_absence refresh
```

Presence Source of Truth:

```text
observed_context.presence
```

これはEarly Access actor tokenではなくuser Personalization Contextへ保存する。

同日navigation / Instant Navigation prefetchは復帰として重複評価しない。

V58.31は:

- re-onboardingを強制しない
- self-reported Contextを書き換えない
- Guidanceを自動変更しない
- Plan / Taskを変更しない
- AI inferenceを行わない

Canonical:

- `docs/V58.31_RETURN_AFTER_ABSENCE_PERSONALIZATION.md`
- `docs/CANOVIA_PERSONALIZATION_SPEC.md`


## V58.32 Candidate Evidence Revision / Reopen

Living Profileのhigh-impact Candidateに、Dismiss後の再提示条件を追加する。

```text
same evidence
→ dismissを尊重

same-strength evidence fluctuation
→ dismissを尊重

materially stronger evidence
→ candidate reopen
→ user confirmation
```

最初の対象は `development_advanced_support`。

Current Development factsを安全な集計値として観測し、
Candidate側でsignal strength / evidence fingerprint / revisionを保持する。

V58.32は:

- user experienceを自動再分類しない
- GitHub activityをuser authorshipと断定しない
- Guidanceを変更しない
- Plan / Taskを変更しない
- confirmed Candidateを再openしない

Canonical:

- `docs/V58.32_CONTEXT_CANDIDATE_EVIDENCE_REOPEN.md`
- `docs/CANOVIA_PERSONALIZATION_SPEC.md`


## V58.32 Candidate Evidence Reopen

Living ProfileのDismissed high-impact candidateを、
同じ事実で永遠に出さない / 毎回しつこく出す、の二択にしない。

```text
Dismiss
↓
same-strength evidence
→ respect dismissal

real behavior/environment change
↓
stronger signal
→ candidate may reopen
```

V58.32 first scope:

`development_advanced_support`

Signalは:

- connected GitHub foundation
- multiple Development repositories
- recent Development activity
- PR / Commit activity

からdeterministicに算出する。

重要:

- experience levelを自動変更しない
- productivity scoreにしない
- code quality評価にしない
- confirmed candidateは再openしない
- raw GitHub contentをTelemetryへ送らない

Canonical:

- `docs/V58.32_CANDIDATE_EVIDENCE_REOPEN.md`
- `docs/CANOVIA_PERSONALIZATION_SPEC.md`

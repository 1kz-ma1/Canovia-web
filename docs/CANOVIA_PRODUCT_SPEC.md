# Canovia Product Specification

更新基準: 2026-09-27 / V41.16 Conversational Onboarding / Invisible Memory

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
- Study / Career / Developer Packの具体Capability実装とFeatureKey接続
- failed Recall Sourceの再抽出UI・複数ページbatch ingest
- Plan Resourceからの安全なRecall material ingest
- Recall成績をTask progressionへ使うPolicy
- Listening / Dictation / Shadowing等のStudy Activity拡張
- Native AI usage historyを使ったquota / cost policy

### Future

需要確認後に実装判断する領域。

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

Canovia Economyの基本構造:

```text
FREE
│
└─ PREMIUM CORE
      ├─ Study Pack
      ├─ Career Pack
      ├─ Developer Pack
      ├─ Creator Pack
      ├─ AI Capacity Boost
      └─ All Access
```

原則:

> Freeでも、自分で動けば目標へ到達できる。  
> 課金すると、整理・転記・解析・判断・自動化をCanoviaがより多く引き受ける。

`Billing`、`Entitlement`、`AI Capacity`は別責務とする。

```text
Billing != Entitlement
Entitlement != AI Capacity
Pack ownership != unlimited AI
```

Feature側が問い合わせる内容は一つに限定する。

> このactorは、この公開済みFeatureを利用できるか。

`FeatureAccessService` がEntitlementの最終境界であり、Feature codeへ以下のような条件を散らさない。

```php
$user->is_premium
$user->has_study_pack
$user->coin_balance
$user->has_gift
```

V41.5では `ProductKey` / `config/economy.php` / `user_product_grants` を追加し、Provider非依存のProduct GrantをPremium / Gift / Sponsorそれぞれのresolverから既存の `FeatureAccessService` へ流す。

```text
Billing / Manual / Gift / Sponsor
              ↓
        Product Grant
              ↓
        Economy Catalog
              ↓
Premium / Gift / Sponsor
Product Grant resolvers
              ↓
     FeatureAccessService
```

AI Practice本体、Question Bank、外部AI Handoff等の核となるFree経路は維持する。V41.8では `automatic_ai_execution` をPremium Coreの実CapabilityとしてFree=falseへ切り替え、Canovia自身がAI Providerを呼ぶ自動実行だけをPremium価値とする。Pack向けCapability-level FeatureKeyはFree=falseで予約し、実際のCapabilityを実装したときに既存アクセス境界へ接続する。

V41.16では例外ではなく独立Capabilityとして `conversational_onboarding` をFree=trueで追加する。これは最初の伴走価値を体験させる限定されたNative AI経路であり、`automatic_ai_execution` や継続 `canovia_companion` のPremium境界をFree化しない。

AI Capacityは `AiCapacityService` で独立判定する。All AccessはPurpose Packを包含するがAI Capacity Boostを包含しない。

料金構成の推薦はV41.5時点では生成AIではなく決定論的な `EconomyRecommendationService` が担当し、Freeを正式な推薦結果として扱う。売上最大化ではなく「現在の使い方に対する最小十分構成」を目的とする。

Coinは直接Feature解放するEntitlement sourceから外す。将来は応援・Gift・自己表現・Cosmetic等の別経済として扱い、Coinで注目やランキングを買えない方針とする。

未実装:

- StoreKit / Stripe等の購入処理
- 実料金
- 公開Paywall / Checkout
- Coin残高・取引
- Gift購入
- Sponsor課金
- Native AI使用量課金（V41.8では利用履歴のみ記録し、請求はしない）

## 5. Feature Flag

Feature Flagの問い:

> この機能を現在公開するか。

Entitlementの問い:

> 公開済みのこの機能を、このactorが利用できるか。

V40.7では`FeatureFlagService`を最小境界として追加する。現在の永続化元は設定であり、server-side Admin操作・percentage rolloutの氵続化はNextへ送る。

最小定義で考慮できる項目:

- feature key
- enabled
- environment
- platform
- minimum app version

将来追加候補:

- rollout percentage
- optional audience
- released_at
- emergency off

Feature FlagをEntitlement resolverの内部へ入れない。

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

Question Bank selectorは `bank-v2-balanced` へ更新し、Primary / Secondary / Diagnostic quotaとdomain round-robinを利用する。External AI selectorは `prompt-v41.4-calibrated` とし、Canoviaが決めたExam ProfileとQuestion MixをPromptへ渡す。

AIのnext_step.focus_topicsは候補Signalとして残すが、次回演習方針を直接決定しない。最終的な出題配分はCanovia Policyが決める。


## 17. V41.5 Economy Foundation

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

arrivalではanchorがsource geometryからcanonical positionへ移動し、その後周囲のNode / Edgeが展開する。最終位置は常に既存Map layoutでありanimation overrideは残さない。`prefers-reduced-motion: reduce` ではnode-to-node animationを無効化し、canonical geometryを維持する。

V47.3のgesture直後click、Focus切替、Context Inspector、V47.4 Reflection Map、Collaboration Projection、L3 Execution semantics、Living Reevaluation、Instant Navigationは維持する。Navigationをanimation完了待ちで遅延させず、AI layoutやcanonical position永続化、billing / entitlement変更は行わない。

詳細は `docs/V47.5_SEMANTIC_ZOOM_SPATIAL_CONTINUITY.md` を正とする。

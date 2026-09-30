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

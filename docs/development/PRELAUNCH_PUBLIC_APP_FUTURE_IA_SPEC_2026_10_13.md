# Canovia — Pre-Launch Public Site / App / Future IA Specification

> 作成日: 2026-10-13  
> 状態: **PRODUCT DESIGN / IMPLEMENTATION PLAN — 未公開・未実装部分を含む**  
> 対象: 事前公開の診断・計画提案、一般向け情報発信、公開ロードマップ、無料Support、継続的な段階公開  
> 現行の実装基準: `main` の実装と既存Canonical Specsを優先。本書は無条件の本番公開承認でもFuture構想の一括昇格でもない。  
> 既存の社内Release Level 0–4は変更しない。PRで`main`に統合されても、Render本番・iOS配布・公開レベルが変更されたことにはならない。

## 0. Product decision and why

Canoviaの複雑さを最初に説明しきるのではなく、ユーザーが最初の価値を得てから、利用可能な支援範囲が段階的に広がる体験とする。開発が進行する間も、診断・計画プレビューとプロダクトの現在地を示すことで、早期から実需を検証する。

ユーザーの2つの目的を混同しない。

1. **自分自身が前進する** — 目標、計画、タスク、学習・開発・実績。いつでも主目的。
2. **Canoviaの未来に参加する** — 機能を知り、期待・要望・任意の応援を伝える。利用の前提条件にはしない。

公開対象は「価値・期待できる体験」。内部アルゴリズム、未発表の詳細設計、非公開のGitHub資料、セキュリティ情報を宣伝のために漏らさない。ロードマップ上のSupportは投票・需要シグナルであり、費用負担・購入・納品の約束ではない。

### Goals

- 登録前の訪問者でも、Canoviaの役割、現在利用可能な範囲、将来の方向性が短時間で理解できる。
- 「診断を試す」または「自分で目標から始める」のどちらでも、本人が確認可能な**次の一歩の提案**に至る。
- 完了後に希望者が結果や計画を保存し、後日のEarly Accessで**理解済みの状態を引き継げる**。保存同意を伴わないゲスト体験から勝手に公開Planを作らない。
- AppのOverviewは常に本人の今日の行動を主役にし、Canoviaの開発ニュースに乗っ取られない。
- Featureへの無料の「期待する」と、将来検討する任意の金銭的な「開発を応援する」を明確に分離する。
- 事前公開から登録・アクティベーション・再訪までの実需を、過剰な追跡なしに検証できる。

### Non-goals of this document

- 画面・Controller・DB・トラッキングイベントの即時追加、公開レベル・Feature Flag・Entitlement変更。
- 新しい課金導線、Coin残高/購入、支援者ランキング、金銭と開発優先順位の連動。
- 全分野の完全自動診断、AIによる計画の無断確定、Careersの一般公開。
- 本番への自動Deploy、iOS App Store公開、MCPの本番解放、Queue Workerの自動構築。
- `docs/future/*` の広範な構想を自動的に実装対象へ昇格させること。

## 1. Current evidence / architecture boundary

次を「既存実装/仕様」として再利用する。**実装済みと本番稼働・実機受入は別**。

| 領域 | Current / available in source | この仕様から生じる新規作業 |
| --- | --- | --- |
| Public | `static-welcome/` にRenderの冷起動用Static Welcomeがある | Marketing/公開情報のIA、診断への入口。既存の待機・復帰挙動を壊さない |
| App | Overview / My Workspace、Study / Development、Plan/Task/Record、初回導線 | 事前公開用の最小体験制御、将来情報と本人ワークスペースの分離 |
| Personalization | V58.25 Bootstrap、Study/Development/unsureの本人回答とPlan Seed、既存Plan作成への接続 | ゲスト体験・保存への引継ぎ、説明文、招待導線の受入 |
| Action-grounded Plan Draft | V58.71–V58.76に根拠・本人承認の限定Slice | 自動フルロードマップ生成を標榜せず、事前公開では承認可能な提案だけ提示 |
| Canovia Future | `GET /feedback`、`roadmap_features`、`roadmap_votes`、Support/取消、自由記述Feedback | マーケティング公開向けの安全な読み取り投影と情報の見せ方 |
| Product Preview | `GET /product-preview`、L2でFree/Premium/Pro/Dev Pro Coming Soon表示 | 公開の約束や価格の誤認防止。Pre-Launchページとの文脈接続 |
| Release Gate | L0–L4、手動受入、個人のAccess override | 事前公開の**明示的な入口スコープ**と検証。L2を自動公開しない |
| Monetization | Free/ Premium/ Pro + future Dev Pro方向、V41.5 Entitlement基盤 | 有料実行・Coin・支援決済は今回の範囲外 |

根拠:
- [Canovia Product Spec](../CANOVIA_PRODUCT_SPEC.md)
- [Release Level Spec](../CANOVIA_RELEASE_LEVEL_SPEC.md)
- [Monetization Spec](../CANOVIA_MONETIZATION_SPEC.md)
- [Personalization Spec](../CANOVIA_PERSONALIZATION_SPEC.md)
- [V40.7 Canovia Future](../V40.7_CANOVIA_FUTURE_FOUNDATION.md)
- [V58.22 Product Preview](../V58.22_PRODUCT_PREVIEW.md)
- [State-first prerelease WIP](../wip/2026-10-08_STATE_FIRST_PRE_RELEASE_EXECUTION_SPEC.md)
- [Development roadmap](ROADMAP.md)

## 2. Three information spaces

### A. Public Site — Canoviaを知る場所

**Primary job:** 何ができるか理解する → 価値を試す → 必要なら登録/利用開始する。

Proposed main navigation (具体URLは実装PRで決定):

- **Canoviaとは** — 一言の価値提案、現実的な利用例、現在の利用可能範囲。
- **無料で試す** — 診断と計画提案。診断スキップ→目標起点も同等の入口。
- **Canoviaのこれから** — 一般向けの公開ロードマップ。Canovia Futureの編集済み投影へ。
- **開発ストーリー / Updates** — 何を改善したか、なぜ改善したか。機密や利用者固有データを出さない。
- **利用状況 / サポート** — 先行公開である旨、プライバシー、問い合わせ、FAQ。

Primary CTAは「無料で試す」1つ。Secondaryは「今できることを見る」。Coming Soon/未公開は誤って押せる主要CTAにしない。SNSからのリンクは該当する価値説明や更新記事に着地できる。

既存`static-welcome/`は現在、Render Freeの起動待ちとAppへの引渡しが責務。公開用LPそのものと同一視しない。Public Siteを作る際は、この待機・障害時の救済・既存アプリURLへの遷移を維持するか、別ホスト/経路に役割を分離する。ドメイン・SEO・静的配信方針は別の実装判断。

### B. Canovia App — 自分が前進する場所

**Primary job:** 現在地を確認 → 今日の行動を選ぶ → 実行・記録 → 次の行動へ。

- **Overview:** 自分に関連する現在地・次Action・重大な変化。宣伝・ロードマップ・人気投票を主領域に混ぜない。
- **My Workspace:** Study / Development、十分に受入済みのCareer Beta等、許可された専用体験。モード切替・閉じる/再開の連続性を維持。
- **My Plan / My Roadmap:** 自分の目標とTaskに紐づくロードマップ。公開製品のロードマップとタイトル・URL・導線・データ元を分離。
- **Inbox / History / Settings:** 現行責務を維持。Futureへの導線はヘルプ・フィードバックや情報メニューへ控えめに配置。
- Early Accessでは、利用できない機能を無理に表示しない。未公開機能のカードは必要な発見シーンのみ表示し、毎回の行動を邪魔しない。

### C. Canovia Future — 成長に参加する場所

**Primary job:** 今どこまで使えるか知る → 将来の体験に期待を伝える → 更新を確認する。

- **Product Roadmap:** 一般向けの機能価値カードと進展状態。
- **期待する / Support:** 既存V40.7の無料需要シグナルと取り消しの再利用。
- **意見・要望:** 既存自由記述Feedbackを再利用。報告、要望、良かった点は引き続き送れる。
- **Release Updates:** 確認済みの公開事実と主な改善を示す。GitHubへのmergeやCI成功だけでは「利用可能」としない。
- 将来の「開発を金銭的に応援」は別機能。未実装期間に課金CTA・支援特典・支援金額ランキングを表示しない。

**Naming contract:** App内の個人計画は「自分の計画 / My Roadmap」、Future内は「Canoviaの開発ロードマップ / Canovia Roadmap」。両者を単独で「ロードマップ」とだけ表示して誤認させない。

## 3. Navigation and visibility contract

| Actor | Public Site | Pre-Launch Preview | App | Canovia Future |
| --- | --- | --- | --- | --- |
| 未ログイン訪問者 | 主要説明/公開情報 | 安全に限定したゲスト体験のみ | 非公開Planへ入れない | 公開カード閲覧。Supportは既存guest policyと濫用対策を満たす場合のみ |
| ログイン済み一般ユーザー | 公開情報 | 自分の保存状態から再開 | **実際のPublic Release LevelとEntitlementで許可された範囲のみ** | 自分のSupport状態/取消。金銭的優遇なし |
| 選定Betaユーザー | 公開情報 | 既存情報を再利用 | actor-specific L3など、個別に承認された範囲 | 一般公開していない機能状態を勝手に発信しない |
| Admin | 公開状態を確認 | 操作の安全性を検証 | Preview LevelはPublicと別 | 内部候補と公開候補を編集/レビューする将来の管理面。公開Projectionに管理権限を渡さない |

- Product Release Level、Feature Flag、Entitlement、Resource Ownershipは別評価のまま。Public向けMarketing閲覧の可否とApp機能権限を混同しない。
- **Pre-Launch Previewはユーザー向けキャンペーン段階であり、内部Release Levelに新たなL0.5を設ける意味ではない。** 実装には限定公開用の明示的なRoute/API gateが必要。L4の既定値を「誰でも利用できる」権限として流用しない。
- Client側の非表示だけでは不足。直接URL、API、deep link、保存POST、preview終了時のアクセスをserver-sideで検証する。
- Appが未準備でもPublic Siteと公開ロードマップは説明責任を果たせる。Appが障害の場合は「起動中/現在利用しにくい」と正直に示し、無限遷移させない。

## 4. Pre-Launch core journeys

### Journey 1: 診断から計画提案へ

1. Public Siteから「無料で試す」を選ぶ。利用範囲・保存条件・所要入力の概要を説明。
2. **「簡易診断を受ける」または「診断せず目標から作る」** を選択。選ばない人に職業・レベル・カテゴリ入力を過度に強制しない。
3. 目的、現在地、必要なときだけ期間・行動可能な時間を取得。Study / Developmentの既存Bootstrapを優先再利用。未対応領域は無根拠な動的診断を作らず、共通の目標入力・最初の行動へフォールバック。
4. 出所を「本人申告」「公式/確認済み」「観測結果」「推定」「未確認」に分けて扱う。簿記基礎12問のような限定診断を、資格合格判定・能力保証と表現しない。
5. **編集できる計画案**を提示。最初の行動、理由、未確認事項、確認済み/未確定の区別を示す。複雑な全自動ロードマップ生成は約束しない。
6. 「この計画を保存する」は**明示的な本人操作**。未認証時はログイン/登録後に権限付きの本人Planへ引き継ぐ。ゲスト下書きの保持期間・削除・端末境界は実装前に決定。
7. 未保存で離脱する場合も正常終了。再訪時に本人の許可なく個人情報を復元・公開しない。

### Journey 2: 既存利用者・再訪ユーザー

- 登録/ログインしたユーザーに初回診断を強制しない。保存された自己申告、承認済みPlan、観測Evidenceを使い、欠けた条件だけ質問。
- 新機能開放時に旧データ/理解を無断変換しない。本人に利益と移行の内容を説明してから移行する。
- 正式なStudy / Developmentの利用が始まったら、Pre-LaunchのPlanは実行可能な既存Plan UIに引き継ぐ。個人Planの共有範囲はデフォルト非公開。
- 復帰先は本人が最後に使用した適切なWorkspaceを尊重し、既存のMy Workspace状態保持と整合させる。

### Journey 3: Canovia Futureを見て期待を伝える

1. Public Site、SNS投稿、Appの情報/Feedback導線から開く。
2. 何が「利用可能」「検証中」「構想」かを明示した概要を閲覧する。
3. 欲しい機能に**無料のSupport**。原則1 actor / 1 candidate、取消可能。既存`roadmap_votes`を使い、新しい投票モデルを重複作成しない。
4. 「Support数が一定数なら開発を約束する」としない。500 Support閾値は**現行の検討開始の目安**として継続。小規模Pre-Launchで表示するか・表記を変えるかは別途プロダクト判断。
5. 既存Feedbackで説明しきれない要望も送れる。要望から個人の属性・悩みを公開カードへ転載しない。

## 5. Public roadmap projection and idea protection

**編集済みの公開Projection**のみを表示する。内部`docs/development/ROADMAP.md`やPR/Issue/CIを直接パースし、変更を自動公開しない。

### Safe public card contract (proposal)

- 安定ID（public feature key、内部Secretsと無関係）
- 短いタイトルとユーザー価値（何ができるようになるか）
- 対象分野（Study / Development / Career / General）
- 公開ステータスの見出しと最終更新日
- 利用可能か、Beta対象者のみか、Coming Soonかの区別
- その機能への無料Support可否と、必要なら実績に基づくRelease Note
- 公式説明ページとフィードバック先

### Status and truthfulness

現行の`RoadmapFeatureStatus`（voting / considering / planned / in_development / ready / rolling_out / released）を保持。公開ラベルへのマッピングは将来View層で行い、**単純なstatus翻訳が利用可能性の証拠にはならない**。

- 検討中/アイデア: 需要の観測。実装確約なし。
- 計画中/開発中: 公開予定時期や品質保証を意味しない。
- テスト中/段階公開: 対象者、必要なアクセス、まだできないことを記載。
- 利用可能: 当該Release Level/Feature Flag/Entitlementを持つ実ユーザーに、デプロイ済みの実機確認された経路がある場合のみ。
- 日付は内部目標と対外的な確約を区別する。根拠のない進捗率やリリースETAを生成しない。

**Never publicize:** 内部設計・学習者弱点計算の詳細、Context Pipeline/Agent制御の独自手法、未公開のPartner/契約/価格、バックログの細かい工程、脆弱性、Secrets/URL/識別子、private Repository、個人Plan/Feedback本文、未確認の性能/成果保証。

**Editorial gate:** 内容を公開する担当者が、公開可否・分類・コピー・status根拠をレビューしてから`is_published`相当の公開状態にする。公開後の変更も監査可能にし、誤情報は非表示/修正できる。GitHubのmergeや内部実装状況を自動的に公開へ昇格させない。

### Initial story grouping (not commitments)

- Discover: 目的と現在地を整理し、最初の行動を見つける。
- Execute: 学習や開発を進め、記録して振り返る。
- Adapt: 履歴・Evidenceに合わせて提案を調整する。
- Connect: 必要な外部サービスやAIへの接続を検証する。
- Expand: Career、高度なAI、将来の専門サービスを検討する。

これは**広報上の体験ストーリー**。内部Release Levelの名前・閾値・実装済み機能を上書きするものではない。

## 6. Free expectation vs future financial support

| | 無料Support（current V40.7） | 将来の開発応援（future/undecided） |
| --- | --- | --- |
| 目的 | 機能に対する期待・需要を伝える | Canovia全体の持続的な活動を任意に支援する |
| 対価 | なし | 金銭の扱い、決済、法務、返金を別途設計 |
| 機能解放 | 付与しない | 原則直接付与しない。特典が必要なら個別契約・Grant経由 |
| 優先順位 | 他の定性Feedback・ユーザーの行動・安全性・コストと合わせて判断 | **支援額で順位や実装確約を決めない** |
| 現在の公開対象 | 条件を満たした候補で利用可 | **公開対象外・課金CTAなし** |

V41.5 CoinはEntitlementと独立した将来の応援・Gift・自己表現の構想。Coin残高・購入・贈与・還元・ランキングは現時点で未実装。将来検討する場合も、未成年者保護・StoreKit/Stripeのプラットフォーム規約・景表法等の適用可能性、会計、返金、目的を事前審査する。支援額による社会的序列・人格評価・ゲーム的煽りを作らない。

## 7. Phase and release boundaries

| Product-facing wave | 利用可能な価値 | 機能/権限の公開境界 | 依存するGate |
| --- | --- | --- | --- |
| **P0 Pre-Launch Discover** | Public Site、診断または目標起点の計画案プレビュー、公開ロードマップ閲覧 | 独立した明示的な限定プレビュー契約。**L0–L4の意味変更なし**。保存/登録と匿名閲覧を分離 | 安全なゲスト/認証、プライバシー・運用・端末受入 |
| **P1 Invitation Early Access** | 計画の実行、選択されたStudy/Development、Feedback | L1/L2の受入済み能力を招待/許可された利用者へ。Careerは別途L3 Beta | L0–L2の累積Release Gate、初回価値の実証 |
| **P2 General Early Access** | 無料Core＋Study/Development＋L2 Product Preview | 一般Public L2はProduct Ownerが手動決定。Premium/Pro/Dev ProはComing Soon | 10件を含む最新の累積Manual Check、移行/バックアップ/復帰/サポート |
| **P3 Expanded capabilities** | Evidenceに応じた高度化、選択的連携、Careerなど | 各機能の成熟度・Entitlement・同意を別々に判断 | 個別E2E/権限/費用/ストア条件 |
| **P4 Monetization** | 実需のあるPremium/Pro等 | BillingとEntitlementは別実装。既存のFree Coreを取り上げない | 実利用の価値、AI単位原価、支払意思、契約・StoreKit等 |

このWaveは**ユーザー向けの紹介順序**であり、既存`ReleaseLevel 0..4`と直接一対一対応させない。現行L2を「Pre-Launchのために自動ON」としない。

### Pre-Launchで実装してよい最小範囲

- 説明する → 試す → 計画案を見る → 任意で保存/先行登録、の1ループ。
- 対象Study / Developmentの既存Personalizationを再利用。Careerは対応状況の正直な説明と将来/限定Betaの案内を優先し、不完全な診断を無理に出さない。
- Freeの計画案は編集可能かつ本人確認付き。複数AI Agentによる高コスト計画生成や継続AI利用は約束しない。
- L2 Product Preview（機能宣伝）と今回のPreview（利用前の体験）を言語的に区別する。

## 8. Acquisition/feedback/observability

最小ファネル（Event命名やDB schemaは実装時に決め、**本書をもって計測実装済みとしない**）:

1. Public Site閲覧（流入元は安全で粒度の粗い集計）
2. 診断開始 / 目標直接入力
3. 計画提案表示 / 途中離脱
4. 計画の編集・保存意向
5. 登録後の同意を伴う保存完了
6. 招待Early Accessでの最初の実行・再訪
7. Futureの閲覧 / Support / Feedback

**Primary evidence:** 「計画がほしい」よりも、実際に計画の編集・保存・再訪・行動開始が起きるか。初期登録率と継続課金率を混同しない。Study / Development / Careerへの関心を、サンプル数や流入元の偏りとともに読む。成功率/目標KPI値は測定後にOwnerが設定。

Privacy:
- Guest analyticsは可能な限り集計値、短命・目的限定のActor ID。広告用途への自動利用や第三者への非公開Plan送信はしない。
- 診断回答・計画本文・学習結果を公開ロードマップ/検索エンジン向けページに含めない。
- 保存/登録前に収集目的、任意性、削除・サポート導線を提示。子どもや高リスク個人データの収集は別審査。
- 既存V58.23 Early Access/Feedback event contractと衝突しない。Guest→Account間の重複計測を正確なユーザー数と断定しない。

## 9. Implementation slices and ownership

実装は一括大型UI刷新ではなく、1つの機能価値につき小さなPRに分割する。

| Slice | 領域 | 内容 | Acceptance / release condition |
| --- | --- | --- | --- |
| **IA-0** | Orchestrator / Experience | 本書と既存仕様の整合、公開Copy/命名の固定、現行導線棚卸し | 既存機能を未実装と誤認しない、Product Ownerが公開の原則を確認 |
| **IA-1** | Experience / Public | Static Welcomeの起動救済維持とPublic Siteの情報構成/入口整理 | App起動待ち、SEO、低速時、スマホ、公開/非公開区別が成立 |
| **IA-2** | Experience / Intelligence | 診断or目標→本人承認可能なPlan Seed→任意保存、Pre-Launch gate | ゲスト/ログイン/戻る/再開、誤計画を確定しない、登録を強制しない |
| **IA-3** | Experience / Future | V40.7既存Roadmapを安全な公開Viewへ整理 | Hidden/internal候補や秘匿情報を直接URL/APIからも漏らさない |
| **IA-4** | Future / Feedback | 無料Support・取消・自由記述の再利用、表示/運用の整理 | 1 actor/候補、CSRF、rate limit、不正連打、閾値誤認防止 |
| **IA-5** | Platform / Experience | 招待型の段階公開とL0–L2 Release Review、iOS/Web E2E | 対象外機能への直接アクセス拒否、downgrade時データ保持 |
| **IA-6** | Product / Operations | SNS・更新情報の安全な公開運用、実績指標、Copy運用 | 編集承認済み内容だけ発信、掲載/公開/停止が運用可能 |
| **IA-7** | Future (post-launch) | 金銭応援・Coin/特典の別仕様検討 | 正式な決済・法務・安全性・費用ゲートが満たされるまで実装しない |

IA-1〜IA-4のうち、最小Pre-Launchに不要な装飾/アニメーション/管理画面高度化は後回しにする。IA-5以前に一般Public L2やiOS正式公開を勝手に実施しない。

既存並列開発モデルに従い、Public/Shell変更をExperience、認証/ReleaseをPlatform、既存Futureモデルの追加変更を適切なOwner laneへ分離する。GitHub source/CI/PRの実績を使い、AIが巨大なTask JSONを反復生成しない。

## 10. Acceptance matrix for implementation

- **内容の誠実性**: Current/Beta/Coming Soon/Conceptの表現が本番実態と一致する。機能提供日・価格・必要能力・合格確率などを創作しない。
- **独立した場所**: 既存の個人`My Roadmap`と製品`Canovia Roadmap`が混同されない。Overviewの主情報が広告・Supportで埋まらない。
- **匿名性/権限**: GuestのPreview/Supportが別ゲストや別UserのContext/Planへ到達できない。ログイン後の引継ぎは本人の明示操作のみ。
- **直接アクセス**: Hidden Roadmap Feature/内部Context/未公開Workspaceは深いURLやPOST/APIでも漏えい・変更不可。
- **データ境界**: Free SupportはEntitlement付与・Task実績・Coin・支援額と接続されない。重複/取消/idempotencyと削除経路を検証。
- **デバイス**: iPhone WKWebView、Safari/PWA、Macブラウザで入力中断・再開・キーボード・安全領域・スクロール・リンク・戻るが破綻しない。
- **アクセシビリティ**: 主CTAのラベル、フォーカス、スクリーンリーダー、reduced-motion、タップ領域、色のみで状態を伝えない。
- **運用**: Privacy/Support/アカウント削除/パスワードリセット/バックアップ・復旧可否とRollback。重大500/予期せぬ403なし。App起動不能時の公開サイトFallbackを確認。
- **リリース制御**: 各実装PRはテスト/CIで検証、実Deployは固定候補SHAとOwner許可に従う。AppのWeb更新はiOSにも作用する。`main`統合≠本番公開。

## 11. Open decisions (not silently decided)

1. Public Siteの運用形態: 現在の`static-welcome`を拡張するか、独立したMarketing Siteとして切り出すか（DNS/SEO/Free cold start配慮）。
2. Pre-LaunchのGuest Plan Seedを「画面上のみ」にするか「短命Session保存」を許容するか、正確な有効期限と削除契約。
3. Pre-Launch対象: StudyとDevelopmentの共通導線を先行するか、簿記等の小さな専門体験まで含めるか（教材レビューと別）。
4. 既存Canovia Futureの「500 Support」目安の公開表現。少人数段階で誤った進捗感を与えないか。
5. Feature status編集担当と公開文の承認責任、更新頻度、利用者向け通知は実装前に決定。
6. Pre-Launchで「登録して保存」を有効にする時点の本番バックアップ/復旧、メール送信、プライバシー・アカウント削除等の安全受入。
7. 一般向けロードマップの詳細度。独自実装手法や未公開の安全対策は必ず内部に留める。

## 12. Decision boundary / next step

この仕様は**新しい公開導線の設計・実装分割**を承認可能な形で整理したもの。直ちにすべてのSliceを実装する指示ではない。最初は**IA-0の整合確認 → IA-1/IA-2の最小体験 → IA-3公開Roadmap → 実ユーザー受入**を推奨する。

先行リリースは「使えること」を少なく正直に伝え、その代わり「最初の価値」を完全なループとして届ける。将来機能の期待は利用の必須条件にせず、本人の目標への支援が常に中心となる。

- Source of truth: latest `main`, canonical docs and validated deploy/device evidence.
- Implementation: new feature branch → focused tests → CI → reviewed PR to `main`; no direct main pushes.
- Production: protected `production` promotion, operator DB audit, manual Render deploy and iPhone/Web smoke are separate owner-controlled gates. Existing Queue Worker hold remains unchanged.

# Canovia — 先行リリース「事前準備」戦略と同一DB継続の受入仕様

> **2026-10-23 JST / Orchestrator + Platform + Experience**
>
> **PRODUCT DIRECTION: ACCEPTED / IMPLEMENTATION: NOT AUTHORIZED BY THIS DOCUMENT / PRODUCTION: NO-GO HOLD**
>
> 対応する製品判断: [D-2026-10-23-01](decisions/2026-10-23_PRELAUNCH_PREPARATION_SHARED_DB_DECISION.md)。本書は旧[統合停止・段階公開マスター](CANOVIA_INTEGRATION_FREEZE_RELEASE_MASTER_2026_10_16.md)の**先行リリースのユーザー体験・公開対象**を更新する。旧マスターと[Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488)の**安全・DB・認証・復旧・昇格ゲートを一切解除しない**。
>
> 調査基準: GitHub開発`main` `bcf385ea18fc97902d3381099094aecae9044ec1`（2026-10-23）。SHA・CI・Release Level・Liveは変動するため、着手時に再取得する。コード実装・実Aiven接続・運用変更・デプロイ・ユーザー公開の完了を意味しない。

## 1. 先行/正式リリースの役割（変更された製品方針）

**先行リリースの成功 = 現段階でCanoviaの中核価値を完走させることではない。** ユーザーが目標を整理し、本人の現在地を確認・修正し、正式リリースで実際に使い始める準備が安全に保存・継続されることを優先する。

| 段階 | ユーザーの約束・見せ方 | Webアプリで最低限必要な動き | 明示的な非目標 |
| --- | --- | --- | --- |
| **先行** | 「将来の利用に向けて診断・目標・計画を準備する」。正式リリースで使う情報が保存される | 説明→本人選択の診断/現在地→目標/Planの作成・閲覧・修正→保存確認→再ログイン/再訪復帰、プライバシー・削除/問い合わせの適切な案内 | Study演習・Development実行・実績分析・AI生成・GitHub連携・課金などの価値を無理に再現しない |
| **HP/ロードマップ** | 現在利用できるものと開発中・未定を明示。将来の中核価値を例示して期待を作る | 成熟度・予定/不確実性・参加/Feedback・現行アプリへの安全な案内を表示 | 「開発版mainにある」「CI成功」を一般公開済み・保証済みと表現しない |
| **正式** | 保存済みの診断/PlanからStudy・Development・実績・次Actionへ進める | 登録済み本人の同じ所有データを引き継ぎ、追加設定の強制・不要な再診断・Plan再作成を避ける | 自動連携/AI自動判断等の未承認機能を正式版に無条件追加しない |

先行版で「今は準備だけできる」ことを明言する。機能の不足を演習/進捗の模倣で隠さない。先行公開日は**2026-12-01を検討目標**として保持するが、安全・データ連続性ゲートが未達なら延期する。日付を守ることより安全な正式版への継続を優先する。

### 1.1 先行版の機能境界（提案する最小契約）

- **公開候補:** Homepage/Canovia Future、本人が選ぶ診断、目標と現在地、Planの新規作成・閲覧・修正、アカウント作成/ログイン/再訪復帰、保存成否表示、Feedback/Help、利用上の制約・プライバシー/データ削除の導線。Task生成/編集は正式版への連続性を阻害しない必要最低限に限り採否を別途判断。Guestは検証済み引継ぎなしにデータ永続性を約束しない。
- **機能公開を保留:** 一問演習、外部AIやAI採点/自動判断、GitHubデータの自動取得/実行、WorkSession/自動進捗、MCP/Auth0外部連携、共有/他者データ、課金、外部プロバイダ、Dev Pro、監視、Career、Map/World。これらはコードが`main`に存在していても**PUBLIC OFF**が初期値。
- **UI原則:** 元のCanovia Webの情報設計・ブランド・初回導線を保つ。`local-preview/`は独立した端末保存/E2Eの検証成果として残し、先行本番UIを置き換えない。ローカル版の6問はValidation-onlyで公開教材ではない。
- **公開制御の独立:** Static HP公開・Webのソース配備・一般ユーザーへの機能露出・個人Planへのアクセス・外部連携許可はすべて別のGateとする。

## 2. 永続化方式の第一候補と代替

### A — 第一候補：正式版と同じCanonical DB / Account / Plan契約

先行版で作成した`User / Account / Diagnosis Context / Goal / Plan`を**正式版でも権威のある同一データとして利用する**。最初から既存のLaravel認証・Plan ownership・正規のDB契約を使用し、公開するUI/Mutationを段階的に限定する。正式リリースでは機能・権限の承認された範囲を解放し、ユーザーに全データ再入力を要求しない。

- **同じDBとはデータの継続性を指す。** 将来の安全なバックアップ復元・物理DB更新は許容するが、移行前後で本人の所有権・識別・参照整合性・事実履歴を維持する。
- Laravelの現在のPlan/Task/診断スキーマ・共通認可・トランザクション・Guest→Account引継ぎを再監査してから採用する。形式的に「同じDBを使う」と決めても、旧schemaと新release candidateの互換性が未確認なら採用不可。
- **同一DB = スコープ限定によるDB無害化ではない。** 導入アプリの`migrate --force`、起動処理、Queue/Job、Listener、共有Session/Auth、Global Feature Flagは非公開機能にも関与し得る。Deploy時の副作用や脆弱な未公開Routeがないことを検証する。
- 先行公開後に作る新しいPlanは、正式版と同一の主体/Owner制約で関連付け、日付・状態・データVersionを保持する。後の追加カラム/Indexは既存行を保持する前方互換migrationを基本とする。過去ユーザーの全件上書き、`migrate:fresh`、無断再診断、予告なし変更を禁止する。
- **Aの採用ゲート:** `#488`の**先行公開に影響する共有基盤**（実DBの物理整合/必要migration、権限/Auth、復旧、effective flags、実機、Owner GO）を証明できた場合のみ。すべての将来専門機能を完成させる必要はないが、未完成の共有経路を安全に遮断できることは必須。

### B — 代替：独立したPreview DBから本人確認済み移行

Aの安全受入に時間がかかり、別の配信/データ保存基盤なら独立して適切に検証できる場合だけ再評価する。別DBは**安全の自動保証ではない**。Auth/owner認可、暗号化、復元、機密保持、運用/費用、監視と削除が必要で、さらに下記を実装・実証しなければならない。

- Previewと正式版の不変`source_installation_id / source_record_id` → Cloud ID mapping、元の主体と移行先の本人一致、明示同意。
- schema/version/参照/重複/衝突/部分失敗を全件検証するDry-runと冪等Import。予期せぬ所有者競合で全件BLOCK。既存Planを上書きしない。
- 隔離環境で実際にExport→Import→再ログイン→元データ照合→二度目Import拒否・失敗時保持・復旧を実証し、正式版に何を持っていけるかの制約を明記する。
- IndexedDB `local-preview/`はBの可用な検証資産であるが、端末消去・ブラウザ領域消失・端末間不可視性があるため、一般公開向けの永続アカウントDBと同等と説明しない。
- **Bへの切替はオーナーの別決定**。Aが止まっているという理由だけで、暗黙にPreview別DBを一般公開しない。

## 3. 先行版に必要なセキュリティとRelease Gates

技術的な正本は[統合停止マスター](CANOVIA_INTEGRATION_FREEZE_RELEASE_MASTER_2026_10_16.md)、[本番固定審査](PRELAUNCH_FIXED_SCOPE_RELEASE_EVALUATION_2026_10_15.md)、[Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488)。この表は**今回の新しい製品スコープへの影響分類基準**であり、既存の実環境受入判定を捏造/上書きしない。

| Gate | 先行公開で必要な証拠（UNKNOWNならNO-GO） | 分離が証明されたときのみ後回し可能 |
| --- | --- | --- |
| **Code / release SHA** | Live SHA・`production`・`main`の差分全量、固定`release/*`の必要な依存closure、migration全件、対応CI / browser E2E、レビューレベル。CI green≠Live | 非公開Future実装そのもの。ただし同梱コードの事故経路は依然審査 |
| **実DB・DDL** | Aiven対象の正しい本人承認/対象Identity/権限/TLS、実migration ledger/物理schema/index/triggerと候補差分、旧行保持、MySQL 8.0/8.4 disposable forward/retry/partial-DDL rehearsal、Startup migration副作用とwriter fence | 完全隔離・非配備の実験migration。Flagで非公開でも実行されるmigrationは後回し不可 |
| **Backup / recovery** | Fresh restorable backup、別環境での復元・整合・運用手順、失敗時read-only/stopとforward repair、許容RPO/RTOの決定、未観測/予想外DDLのBLOCK | 無関係な新Providerの復旧整備。ただし本番Aivenの復旧は必須 |
| **Auth / isolation** | 登録/ログイン/Logout/Session/CSRF、Plan owner毎のGET/POST/IDOR拒否、Guest→Registered引継ぎと二重所有防止、XSS、削除/Export/同意、既存アカウント復帰を負のテストまで | MCPの実アカウント連携/Auth0外部Plan grant等は完全OFFかつ独立Route/Queue/permissionを検証できるときのみ |
| **Release-level / Feature Flag** | Anonymous/Public/selected actorの実効Level、default-off、Web UIだけでなくdeep link/route/API/POST/queue/webhook/Console/workerのdeny、Admin bypass防止、emergency OFFが対象サービスに反映される | Studio、AI、GitHub、Career、課金、Dev Pro等の個別動作検証。ただし非公開経路の拒否テストは必要 |
| **Actual experience** | Mac Safari/Chrome・iPhone Safari/WKWebViewに応じた診断→Plan保存→再ログイン復帰・編集、別ユーザー隔離・障害/ネットワーク失敗・保存未完了表示、User Notice、HP/roadmapの可用性表示 | Study採点・Dev作業実績・AI/GitHubの正式版機能受入（先行では無効が前提） |
| **Operator approval** | exact review SHA/flags、実運用・セキュリティ/Privacy責任、対象者、緊急停止手段、初回手動deployを個別承認。`production`はmerge commit、Auto Deploy OFFを維持 | 完全な将来ロードマップの機能完成・Dev Pro等 |

**Track H (旧Live94監査/hotfix) と Track E (`e858`, 94→113候補等)は別の候補/ゲート。** いずれもMacオフライン観測やCIだけで実Aiven接続・restore・production GOとはしない。2026-10-23時点の`#488`には実Aiven監査や独立復元が未完了と記録される。最新状況を必ず再読する。旧文書にある94→113/19件等は固定当時の候補であり、現在の`main`のpending件数ではない。

**HOLD分類ルール:**

- **BLOCKING_SHARED:** 公開されるか否かに関係なく稼働するDB/Auth/Session/Queue/Startup/Configのリスク、Plan保護、バックアップ復元、配備・緊急停止。これが1件でも未検証ならA案はNO-GO。
- **DEFERRABLE_ISOLATED:** 実装中の専門機能をdeny-by-defaultで物理・論理的に隔離でき、直接URL/POST/worker/APIも遮断され、公開候補のschema/共通経路へ影響しないことを再現テストしたもの。実装/一般公開は後回しにできる。
- **UNKNOWN / MIXED:** 外部Provider、Native AI、MCP、Experiment flag、old/new Code等で共通経路への影響を断言できないもの。安全証明ができるまで**BLOCKINGとして扱う**。単なる「UIを非表示」は十分条件ではない。

## 4. ユーザーに対するデータ継続性の受入条件

正式リリース時に「準備がそのまま役立つ」ことを**約束可能な最小の意味**として具体化する。

1. 同じ本人アカウントで再ログインした時、先行時に作成/編集した診断・現在地・Goal・Planが同じ所有者のもとで読み込まれる。
2. 将来の新機能を使っても先行データが重複しない・黙って初期化されない・参照切れ/権限の混線を起こさない。既存データを失うmigrationはNO-GO。
3. 入力した情報は本人が閲覧/訂正でき、初回診断の推論/推薦は事実と混同しない。
4. Guest状態があるなら**引き継ぎが確定した後で**Account-ownedの正式データとなる。移行失敗では元データを破棄せず、成功と表示しない。
5. 障害時の保存結果が不明なら「保存成功」と表示しない。ユーザーが離脱前に保存状態を理解できる。
6. 公開後のデータ保持・削除方法・将来の提供範囲の注意を説明する。「正式リリースは特定日/全機能/完全移行を保証」と誤認させない。

**受入E2E案:** 新規Account Aで診断/Plan作成 → Logout/Login → 正本ID/フィールド一致、Account BはAのPlan閲覧・更新を拒否、権限エラー/CSRF/Guest衝突/保存失敗で旧データ保持、Feature OFF状態で演習/Dev/MCP/課金等を直URL/POSTで拒否、旧Account→更新版で復帰、migration/backupからPlan復元して一致。テストは合成ユーザーで、実Liveにはテストデータ投入しない。

## 5. Codex向け実装・監査の実行パケット（次チャットからの起点）

**担当: Orchestrator + Platform（Experienceは初回導線の最小化、Learning/Developmentは共有Plan契約の受入のみ）。** 実装はGitHub`main`に統合する審査用開発まで。正式版の機能追加競争は今は行わない。

### Slice 0 — 現状を再取得して「先行公開に必要な共有安全基盤」を固定

- 最新`main`/`production`/PR/Render Live **read-only evidence**、最新`AGENTS.md`/`docs/README.md`/`docs/CANOVIA_PRODUCT_SPEC.md`、本書、`#488`、Release Level/Feature Flag/Route/Auth/DB/Render startupを再調査。
- 最小のユーザージャーニーに必要なController/Model/Middleware/Migration/Session/Job/Frontend/CDの**影響範囲表**を作成。「公開候補 / 完全遮断 / shared/blocker / unknown」の理由と正確な実装根拠を記す。
- `main`の全差分と全migration（REST compareの300-file表示上限は全件数ではない）を固定candidateで算出。**先にRelease Branchを勝手にproductionへ昇格しない**。
- 各HOLDを`BLOCKING_SHARED / DEFERRABLE_ISOLATED / UNKNOWN`に分類し、**先行限定スコープなら安全に除外できるものだけ**根拠と否定テストを添える。未観測をPASSにしない。
- 認証・保存・移行の安全な最短ルートがAで成立しないと判明した場合のみBのコスト/保護/安全性を比較する。方式を自動変更しない。

### Slice 1 — 安全なコード上の収束（必要な場合のみ、責務別PR）

- **Default-deny feature exposure:** 既存`FeatureFlagService`、`ReleaseLevelService`、`ReleaseGateService`、Plan/Owner authorizationを重複実装せず、UI/Nav/Route/POST/API/Job/Queueの整合を追加。既存の先行公開用UXとエラー表示/未公開導線を維持し、後から新機能を解除可能に。
- **Preparation-first UX:** UIは既存Canoviaデザインのまま、Welcome→選択診断→目標/Plan作成・保存/再訪→HP/Canovia Futureへ。不要な分析/内部仕様/先走った「始める」CTAを一般向けに露出しない。
- **Persistence/auth regressions:** Owner-bound Plan作成/編集/削除、Guest handoff、既存Account、競合保存、CSRF/IDOR、暗黙の進捗変更、移行耐性をテストで証明。データやユーザー権限の互換性を壊さない。
- **Migration/deploy hardening:** `migrate --force`起動副作用の隔離・事前適用順序・write fencing/復旧を**使い捨てDBとCodeのみで**整備する。実Aiven/Render操作は別の本人GOまで停止。

### Slice 2 — QA / Release evidence / Ownerへの判断パケット

- 固定SHAの差分・CI・独立MySQL 8.0/8.4、実ブラウザE2E・認可否定系、Release OFF/ON boundaryを分離して証明。
- Mac/iPhone実機受入手順、Owner-only Aiven read-only監査の事前審査、独立Backup/Restore練習、実際のRPO/RTO/運用設定の確認表を作る。**実行に承認が必要な接続/DDL/Deployを勝手に実施しない**。
- `LOCAL_PREVIEW_RELEASE_NO_GO`（以前の独立IndexedDBルート）とCloud production `PRODUCTION_NO_GO_HOLD`を混同しない。Local-first成果は保持、公開方針をAへ切り替えたからといって以前のE2EがCloud本番テストになることはない。
- 最終報告は`IMPLEMENTED / CI_VERIFIED / BROWSER_VERIFIED / DEVICE_VERIFIED / DEPLOYED / RELEASE_APPROVED / HOLD`を独立して記し、Blockerの解除証拠と次のOwner決定を提示。Code PRの自律的main Squashは`AGENTS.md`の合格条件のみで許可される。**productionへはOwnerの別GO必須**。

### 初手で「しないこと」

- 別ローカル版を再実装して先行Web UIに置き換えない。
- 見た目のためだけにCloud権限・migrationリスクを隠さない。
- 実Aivenに接続しない、Credential/CA/SHOW GRANTS/生データをGithub/ChatGPTに貼らない。
- 旧`#513`の固定PR、Track HとE、過去SHAを最新候補として無断差し替えない。
- `production` merge、Render環境/auto deploy、Auth0、Aiven DDL/Backup resource、実データアクセス、外部有料資源、Store課金を承認なしに操作しない。
- Feature FlagをOFFにしただけで安全性・脆弱性・旧DB互換性が証明できたと判断しない。
- Study/Devの未承認Question Pack/AI/GitHub/MCPを公開しない。

## 6. 新規チャット / Codexへの最小引き継ぎプロンプト

~~~text
Canoviaの先行公開方針が2026-10-23に変更されました。
リポジトリ: https://github.com/1kz-ma1/Canovia-web
担当: Orchestrator + Platform（必要に応じExperience、Learning/Development）

最新mainとAGENTS.mdを必ず再取得し、docs/README.md と以下の正本を確認してください。
1. docs/development/PRELAUNCH_PREPARATION_SHARED_DB_RELEASE_STRATEGY_2026_10_23.md
2. docs/development/decisions/2026-10-23_PRELAUNCH_PREPARATION_SHARED_DB_DECISION.md
3. docs/development/CANOVIA_INTEGRATION_FREEZE_RELEASE_MASTER_2026_10_16.md
4. docs/development/PRELAUNCH_FIXED_SCOPE_RELEASE_EVALUATION_2026_10_15.md
5. Issue #488（最新コメント、各HOLDとPR/CIのexact SHA）
6. docs/CANOVIA_RELEASE_LEVEL_SPEC.md / docs/CANOVIA_DEVELOPMENT_WORKFLOW.md

製品決定: 先行リリースはCanoviaの中核価値を今すぐ完走することを
必須とせず、既存Canovia WebのUIで診断・目標・計画の準備と保存を提供。
中核体験はHP/ロードマップで正直に示し、正式リリースで準備データから開始する。
第一候補A=同じ本番Canonical DBとAccount/Planを使用し、Server-side
Release Level/Flag/Authorizationで公開機能だけを限定する。
AはDB物理監査・必要migration・認証/認可・復旧・本番effective flags・
実機受入・Owner GOを含むshared release gatesが通った場合のみ。
B=別DBから本人認可・検証済みの移行はAが安全に成立しない場合の代替、
無断採用しない。独立local-previewは技術検証資産で先行UIの本命ではない。

まず最新GitHubの実装/本番差分とRelease Issue #488を確認し、
必要なHOLDをBLOCKING_SHARED/DEFERRABLE_ISOLATED/UNKNOWNに分類、
根拠と必要なテスト・依存を一覧化して安全な最短のCodex実装PR順を確定。
その後、必要な隔離/保存/認可/リリース安全性の改修を小PRで自律的に進める。
mainへのSquashはAGENTS.mdのCI・差分・受入条件を満たす場合のみ。
実Aiven、Render、Auth0、production、実ユーザーデータへの操作、
実リリース/公開は別途Owner明示GOまで実行禁止。
履歴や誤認を避け、IMPLEMENTED/CI/BROWSER/DEVICE/DEPLOY/GOを区別。
単なる調査報告で止まらず、許可済みの安全なCode/Tests/Docs領域は進める。
~~~

## 7. 計測・再検討条件

先行公開の仮説（まだ**UNMEASURED**）：診断からPlan保存、後日再訪、正式版移行後の同じPlanからの初回実行により「登録だけして終わる」摩擦が減る可能性。成功指標候補は①診断開始→保存完了率、②Plan作成率、③7/30日再訪率、④正式版で旧Planから実行を開始できた割合、⑤保存/復元/権限事故件数。ただし計測のために余計な個人情報を外部へ送信しない。母数・収集への同意・正式公開Version・計測期間は未決定。値/改善は保証しない。

再検討条件: Aの共有本番ゲートが長期未解決で期限・費用に影響する、Guest/Account移行が安全に成立しない、実機/プライバシー受入で重大な問題、ユーザーが準備だけの体験を誤認する、正式版のPlan互換性が維持できない。これらはProduct ownerが別のDecision IDで再判断する。

## 8. Authority / 状態ラベル

- **ACCEPTED:** 「先行=準備、正式=中核体験」「A第一候補、Bは必要時再評価」という**製品/実装方針**。
- **NOT IMPLEMENTED BY THIS DOCUMENT:** 先行モード専用の本番機能遮断、真正な本番migration、移行/復元、本番受入は**本書の追記のみでは実施していない**。
- **NOT RELEASED:** `main`マージ・CI成功・`local-preview/`のChromium E2EはCloud本番昇格を証明しない。
- **AUTHORITATIVE GATES:** `AGENTS.md`、現行Release/DB/Auth関連実装と安全な固定検証、Issue #488、Ownerの個別GO。旧`#513`、10/15の19件用candidate、Track H/Eはそれぞれ独立管理する。

## 9. 2026-10-24 Slice 0 / 共有Plan保護の初回差分

ユーザーの本書を指定した実装依頼に基づく着手。調査基準main **`e026f49221c7e45b52fdf7314f14420ed39d921b`**。GitHubの`production=15abdc9de6f24b5250fb255c579d1aa55facaf1a`と固定e858 releaseは不変。Render Liveは[#488の直近の独立記録](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6360966071)を歴史的証拠として再利用し、今回はRender APIで再取得していない（現在値UNVERIFIED）。本作業のmainは新しい本番候補として未採用。

### 9.1 全量source差分と既存証拠の再利用

Git全履歴で`15abdc9..e026f492`は **368 commits / 1,177 changed paths**。REST compareの先頭300件は使わない。次で全量を再生成可能（LFを含む出力をSHA256）：

```sh
git diff --name-only 15abdc9de6f24b5250fb255c579d1aa55facaf1a e026f49221c7e45b52fdf7314f14420ed39d921b
git diff --name-only 15abdc9de6f24b5250fb255c579d1aa55facaf1a e026f49221c7e45b52fdf7314f14420ed39d921b -- database/migrations
```

全path一覧hash `29210f6575736831ef09ba3842f54b89d5a06dad3285883c5f738eee4de5bde7`、追加migration一覧hash `99432db700ee35a767ace77af22629eca318a6176a20d9421aaf34bd98900da3`。source上は **94→114 / 追加20件**（実DB台帳の現在値ではない）。migration全ファイルと`docker/render-start.sh`は固定e858から差分ゼロ。したがって既存[integration README](integration/2026-10-18-b/README.md)の20件監査・MySQL両版のforward/partial-DDL/retry/独立logical restore CIを再利用する。実DBの制約名、M08 UNIQUE名依存、M04→M05 trigger、衝突・可視性、実DDL権限/binlog、実バックアップ復元は未受入のまま。#856は旧Live94の**合成**single-client経路であり新mainの実監査ではない。

### 9.2 影響範囲とHOLDの分類（実装着手時）

| 区分 | 実装根拠・対象 | 判定と次の証拠 |
| --- | --- | --- |
| BLOCKING_SHARED | `PlanController::store/update`、`PlanOwnershipService`、`GuestPlanClaimService`、`GoalContextAccessService`、`AuthController`、session/CSRF | Account/Guestの本人帰属・保存・再訪が必須。再送時のowner確認不足を下記で修正。既存Account、所有者以外のGET/POST拒否、Guest claimを合成テスト。実機/実運用の受入は別。 |
| BLOCKING_SHARED | `Dockerfile` PHP 8.4.27 pin/build fence（#832）、Nginx/PHP/session runtime | sourceの修正済みruntimeは再利用するが、旧Liveの更新完了とは扱わない。正式候補imageの出自・version・起動/既存session互換と実配備の別受入は必須。専門機能OFFでも後回し不可。 |
| BLOCKING_SHARED | 全20 migration、`Release20*Guard`、`docker/startup-migration-policy.sh`、web/worker/scheduler | 非公開機能のmigrationも同じ起動経路。候補固定・実schema監査・writer停止/forward repairは省略不可。独立実データ復元、RPO/RTOも未達。 |
| BLOCKING_SHARED | `ReleaseLevelService`、`FeatureFlagService`、`FeatureAccessService`、`ReleaseGateService`、`routes/web.php`/`api.php` | L0でも`work_sessions.*`/`offline.work_sessions.sync`等が存在し、診断はL1。既存Levelだけでは「準備のみ」を分離できない。専用のdenyとnegative testsが必要。effective production設定はUNKNOWN。 |
| UNKNOWN/MIXED → BLOCKING | `ProcessGitHubWebhookDelivery`/`BootstrapGitHubDevelopmentActivity`、`routes/console.php`、Native AI、provider、MCP、Study/Development | route非表示だけでは既存delivery/job/CLIの副作用を否定できない。専用機能の完成は不要だが、HTTP/API/queue/console/workerと共有起動の隔離を証明するまで一括でDEFERRABLEにしない。 |
| DEFERRABLE_ISOLATED（限定） | `local-preview/`と専用static server、独立IndexedDB | Laravel public/routes/startupの外。Aの配布対象に採用しない限り、PWA/教材の人間受入やCloud importは後回し可能。既存資産は保全しCloud保存証拠へ転用しない。 |
| 条件付き後回し（未認定） | Study採点/教材公開、Development実行/AI/GitHub、MCP/Auth0連携、Career/World/課金/Dev Pro | 専門機能自体の完成・実機受入は先行目的に不要。ただし上記shared schema/認可/実行隔離がPASSになった範囲だけを後続でDEFERRABLE_ISOLATEDへ変更する。 |
| BLOCKING_SHARED | browser/device、privacy/削除/export、source/flag固定、GitHub production保護、緊急停止/owner GO | 合成browser成功は実機・本番・正式公開承認ではない。Aの診断→Plan保存→再ログイン/編集をMac/iPhoneで受入し、部署/対象者/停止責任と個別deploy/public GOを確定する必要。 |

### 9.3 最初の小さな修正：Plan作成再送の所有権

`create_request_id`は冪等キーであり所有権ではない。既存Planへ戻る分岐で既存`PlanOwnershipService::authorizePlan`を再利用し、cookie発行・Goal Context接続の**前**に本人を検証する。旧Account間の409は維持し、正しいGuest cookie/Accountの再送は同じPlanへ戻る。DB migration・既存行/スコア/進捗の書換えなし。

新5ケースは未修正mainで3件の拒否テストが失敗し、修正後は既存bootstrap/lifecycle/first-action/sessionと合わせPHP **34件 / 313 assertions PASS**（使い捨てSQLite）。実ブラウザでは合成Guest→保存→登録claim→編集→logout/login→同一Planの保持と、他者GET/PUT/replay・CSRF拒否を確認する専用CIを追加。これは既存PHPベースライン全体の失敗原因解消や実ユーザー移行の受入を意味しない。

次PRは準備専用のpublication境界。既存Release/Feature基盤を再利用し、公開有効化を行わずHTTP/非HTTP経路を責務別に閉じる。全量候補・実DB/復旧・実効設定・実機・オーナー各GOが揃うまで **A_SHARED_DB_PRELAUNCH_NO_GO / PRODUCTION_NO_GO_HOLD**。

### 9.4 Slice 1：既存Web上のrestrictive preparation profile（未選択・未配備）

`release_levels.preparation_only` / `CANOVIA_PREPARATION_ONLY=true`は、既存Release Level/認可の**上に重ねる縮小制御**。コードの初期値falseは既存開発カタログとの互換性であり、準備版の安全な公開設定ではない。本番へこの値を設定した事実はなく、A案の候補審査では**全サービスの実効値true**を別途確認する。falseへの変更は緊急OFFとして使わない（元のカタログへ戻るため）。不正なconfig値は制限側へ倒し、L4/Admin/契約の例外で拡張しない。L0→L1の診断権限を自動昇格しない。

- **原UI/DB継続**：既存Laravel routes/controllers、Canoviaのbrand/CSSとBlade shell、`User/Plan/UserPersonalizationContext`・Goal Contextと既存サービスを再利用。新DB/Account/Planコピー/同期/migrationなし。Home→任意の既存診断→編集可能な候補→本人Plan保存/編集→再ログインで元IDから再訪。Study/Dev/Career Workspaceへ自動転送しない。以前のPlan/Task/履歴・公開bitは削除/書換えず、共有URLを閉じる。保存成功時は既存FirstRunServiceの準備完了markerを再利用し、正式UIへ戻った際の初回導線/再入力を強制しない（Task完了/習熟やLevel昇格は記録しない）。
- **既存ユーザーへの影響**：trueは全actorへ適用される縮小profileで、既存ユーザー/オーナー/AdminのStudy・作業実行・共有URL・履歴UIも閉じる。既存Task/Attempt/実績は保持するが、継続利用可能とは約束しない。本人のAP学習を含むこの停止影響は、本番設定を選ぶ前の別受入事項。既存ユーザーを免除する抜け道は追加していない。
- **最小スコープ**：準備の保存はログイン本人のみ。新Guest durable save/Task操作は今回採用しない。旧Goal Discoveryの`goal_context_id`接続もgeneric Plan POSTから拒否し、閉じた専門workflowへ再入場させない。既存の正しいGuest cookieから登録/ログイン時のclaimは既存サービスを維持。アカウント・Privacy/Support/既存削除経路・本人feedbackは保持し、データexport提供済みとは説明しない。
- **HTTP deny-by-default**：`EnsurePreparationSurface`をweb/apiのresource binding前に適用。public/auth/本人診断/本人Planの正確なroute一覧以外（unnamed/new routeも含む）を404。Admin previewでも同じ。Plan共有の入力も拒否し、所有者認可は既存サービスが引き続き行う。準備shellは実行/AI/MCP/課金/共有の導線やSWを登録しない。Canovia FutureのGETは既存のpublic roadmap projectionだけを表示し、SupportのDB/投票/架空countへ混同しない（POSTは拒否）。
- **非HTTP**：既存2 GitHub jobの直接handleと、未知jobを含むqueue before-eventを拒否。Consoleは起動/maintenance/cache/readonly ledger等の固定allowlistだけ許可し、`schedule:run`、`queue:work`、`migrate`、各provider command等は実CLIプロセスで拒否する。機能を隠して旧deliveryを処理することはない。providerへの通信は負例でゼロを検査。
- **境界の限界**：これは起動済み旧workerのプロセスconfigを書き換える仕組みではない。旧worker/schedulerの停止・全runtimeへ同じ固定code/configを反映し再起動する証拠、job reservation/failureのDB bookkeeping、共有session/メール/削除/export/telemetry、既存SW/キャッシュやNginx静的経路の実受入は依然BLOCKING_SHARED/UNKNOWN。DB migrationは別承認で事前適用し、runtimeでは既存check-only起動フェンスを使う。Flagで未適用20件を飛ばして起動可能とは言わない。

新profileのPHP13ケースと既存Plan replay/bootstrap/Level/session等の関連回帰を使い捨てSQLiteで確認（50件 / 437 assertions）。編集時は既存Plan行ロック内で編集内容のfingerprintを比較し、古いタブ/比較値なしの上書きを拒否。入力内容は残して保存ボタンを止め、最新内容の確認を求める。fingerprintは新しい認証情報や永続revision/自動mergeではない。別の実Chromium journeyは登録→診断→元AccountのPlan保存→reload/edit/logout/login→元Plan再訪、GET/POST/API拒否、CSRF、390pxでの横overflowなしを検証。Console負例はLaravelがunit-test環境でConsoleイベントを省略する仕様を避け、**`APP_ENV=local`の独立した実Artisanプロセス**で確認する（実環境への接続なし）。既存Full94/114・strict SQL/Reference・MySQL復旧CIは対象source不変のため作り直していない。

**HOLDの更新**：専門機能の完成・教材/実行の実機受入は、上記固定profileの**合成アプリケーション層**では後回し可能。現在本番が隔離済みとは認定しない。実効設定/旧worker/静的経路等を含む実運用隔離と実DB/復旧が未検証なので、公開判断では依然UNKNOWN/MIXED→BLOCKING扱い。Track H/Eいずれの候補も自動更新/採用せず、`A_SHARED_DB_PRELAUNCH_NO_GO / PRODUCTION_NO_GO_HOLD`。

次の本人操作は実接続や配備ではなく、CIで固定された元Webの準備フローを管理Macで**無公開・合成データ**受入すること。実運用候補の依存closure/追加20件・実DB/backup restore・実効profile/旧worker停止・既存Account/実機/Privacy・初回manual deployment/publicationの各別GOが揃うまで公開しない。別DB B案への切替もしない。

## 10. 管理Mac Safariでの無公開・合成受入（2026-10-24）

§9/#857/#859/#860の既存証拠を再利用する。`scripts/ci/preparation_mac_lab.py`は**元Webを別のclean worktreeで前面起動**するための受入ツール。既存のbrowser runnerの合成環境と`preparation_surface_browser_smoke.mjs`を再利用する。公開UIの差分は今回追加しない。内部コード/判定を一般画面へ出す必要はなく、既存PC/390px画像と今回の実ブラウザで点検する。Safari実機は本人の操作後まで`DEVICE_NOT_RUN`。

### 10.1 起動と終了（本人操作、実データ入力禁止）

前提：管理MacのPHP 8.4（SQLite/mbstring等のComposer要件）、Composer、Node 22、Python 3.9以上。既存のCanovia/AP checkoutや.envは変更しない。別ターミナルで、Issue #488の**今回の最終検証HEAD完全SHA**を`ACCEPTANCE_SHA`へ貼る。moving mainのまま開始しない。以下の`/path/to/Canovia-web`だけ手元のGit checkoutへ置換する。新しいパスが既に存在したら上書きせずSTOP。

```bash
ACCEPTANCE_SHA='<Issue #488に記録した最終検証HEADの40桁SHA>'
git -C /path/to/Canovia-web fetch origin
git -C /path/to/Canovia-web worktree add --detach "$HOME/Canovia-preparation-mac-acceptance" "$ACCEPTANCE_SHA"
cd "$HOME/Canovia-preparation-mac-acceptance"
composer install --no-interaction --prefer-dist --no-scripts
npm ci --ignore-scripts
npm run build
python3 scripts/ci/preparation_mac_lab.py --source-sha "$ACCEPTANCE_SHA"
```

`.env`作成、`artisan key:generate`、手動migration、実資格情報設定は**不要・禁止**。vendorはこのworktree内に独立install（他checkoutへのsymlink不可）。`.env*`（配布`.env.example`を除く）/cached config/routes/Vite hot/public storage link/dirty source/占有portなら起動拒否。依存不足もSTOP。今のAPサーバーを止めてportを空けない。

`SYNTHETIC_LOCAL_LAB_READY`後、Safariの**新しいラボ専用プライベートウィンドウ**で **http://127.0.0.1:18803** を開く。既存APのタブ/プロファイルはそのまま残す。LAN IP、localhost、本番URL、tunnelを使わない。固定の**公開ダミー**ログインだけを使用する：

| 合成Account | 公開L0での扱い | password |
| --- | --- | --- |
| `tester@example.test` | 明示的な本人override L1、診断可 | `Synthetic-Preparation-Only-2026` |
| `normal@example.test` | L0、診断不可、手入力Plan可 | 同上 |
| `admin@example.test` | adminだがL0、診断バイパスなし | 同上 |

新規登録もL0。全員へL1を付与しない。`example.test`とダミーpasswordは本番で使用しない。合成AP/Development PlanとAP採点履歴が初期データとして存在する。

終了は**起動したターミナルでCtrl-Cを1回**。所有する単一PHP serverの終了をwaitし、必要時だけその同じprocessをkill/waitしてから、私有temp内のSQLite/セッション/compiled view/logを削除する。`LAB_STOPPED_DISPOSABLE_DATA_DELETED`を確認。Safariタブを閉じる。source/dependency/buildはworktreeに残るが、ラボ内のPlanは終了時に消える。次の起動は別DB/別session cookieで最初から。`killall php`、他サーバーの停止、SQLiteの流用は禁止。終了未確認・権限エラーなら`LAB_BLOCKED`、データ削除/停止成功とは記録しない。残るラボはOSのtemp領域に合成データのみ、該当processを私的に特定して終了確認するまで新たな操作をしない。

### 10.2 確認する画面（Mac Safari本人受入）

1. 未ログインのホーム/Canovia Future/Privacy/Support。元ロゴ/デザイン、不要な内部説明・架空応援数がないこと。未ログイン保存/他者Planは入れない。
2. testerでログイン→「現在地を整理する」→合成診断→候補編集→Plan保存。元のPlan IDのままreload/編集/ログアウト/再ログインし、保存した内容へ戻る。
3. 同じPlanの編集を2タブで開く。先のタブを保存後、古いタブを送信。上書きを拒否し、古い入力を残し、保存ボタンを止め、最新画面を開く案内がある。
4. Safariウィンドウを約390pxへ縮める。フォーム/エラー/ナビが横にはみ出さず、Futureを横スクロールし、キーボードでも操作できる。iPhone実機受入とは区別する。
5. normal/adminを別のSafariプライベートウィンドウで確認。L0診断入口なし、直URLも診断へ入れない。管理者でもStudy/Development/履歴/専門APIを開けない。他人のPlanを編集できない。L0自身の手入力Planは保存できる。
6. 準備profile trueでは既存合成AP/Dev Planは見えるが実行/履歴UIは404。**本番に同じ設定を採用すると普段のAP学習も閉じる**。Task/score/履歴削除とは別の影響として受入する。

比較用の元UIはCtrl-C完了後、同じsourceで次を起動して **http://127.0.0.1:18804**。新しい独立DBに同じ合成fixtureを作る（先の手入力を移す仕組みではない）。tester L1でStudy（`/workspace/study?plan_id=1`）/Development（`/workspace/development?plan_id=2`）と合成AP Taskの履歴（`/plans/1/tasks/1/study-practice/history`）へ入れることを比較する。これらのIDは空のラボに作る固定合成fixtureだけの値。通常L0の専門画面が閉じるのは元のRelease Level仕様。adminだけの例外は追加しない。

```bash
python3 scripts/ci/preparation_mac_lab.py --source-sha "$ACCEPTANCE_SHA" --profile original
```

本人が共有する結果は`source SHA / Safari・macOS version / 上記1〜6と元UI比較のPASS・FAIL / 保存・競合・再ログイン・390pxの問題`だけ。実Account/Plan、実URL、秘密値、実データの画像を求めない。1件でも不一致なら受入HOLD、公開を行わない。

### 10.3 合成証拠と限界

`PreparationCompatibilityMatrixTest`はfalse/true × public L0/L1 × 未認証/通常/管理者の12ケースと、指定L1本人のfalse→true→falseを比較。同じDBのPlan/Task/StudyPracticeAttempt/WorkSessionの**全行・全列（ID/owner/score/思考/assessment/進捗/時刻を含む）**を前後一致検証し、別ユーザーの履歴/編集拒否も確認する。SQL/新migration/production設定を変えない。元UI L0の履歴403と専門Workspaceのredirect、準備profileの404は区別する。全既存仕様や全プロバイダーの無故障保証ではない。

起動ツールはenvをallowlist化し、DB/queue/mail/AI/MCPを合成設定へ固定、storage/config/routes/cache/cookieも分離。Cookieはportを区別しないため、専用routerはランダムなラボsession名以外の受信Cookieをアプリへ渡さず、応答もそのsession Cookie以外を送信しない。既存loopbackのactor/first-run/owner/XSRF Cookieを合成値で事前配置し、保存・再ログイン後も上書きされないことを実ブラウザ検証する。このためラボではremember-me/Guest永続Cookieの受入を対象外にする（元アプリの認可実装は変更しない）。既知local .envや実秘密を読まない。lab専用routerはloopbackとHostを照合し、CSPで外部リソース/フォーム/workerを閉じ、Laravel HTTP facadeの実リクエストも拒否。**OS全体のegress firewallではない**。既存の任意SDK/ブラウザ拡張/同一Macの別プロセスまで隔離済みと扱わない。実資格情報の設定や連携操作は禁止。private tempログは共有せず停止時削除。loopbackはインターネット認証や同一端末の他アプリからの隔離証明ではない。

### 10.4 残るHOLD

Mac Safari本人受入、iPhone実機、実効全runtime profile/旧worker/cache/SW/静的経路、既存ユーザーのAP画面停止影響、候補固定/実DB・追加20件/独立実backup restore/Privacy/各Owner GOは未解除。今回のlabでDB互換や本番認証/session/運用復旧をPASSにしない。**次の推奨操作は上記のMac Safari無公開受入だけ**。本番flag変更や実Aiven監査を依頼する段階ではない。

`DEVICE_NOT_RUN / NOT_DEPLOYED / A_SHARED_DB_PRELAUNCH_NO_GO / PRODUCTION_NO_GO_HOLD`

## 11. 2026-10-24 横断的な関心診断・グラフ結果・Futureへの導線（製品決定）

> **2026-10-24 後続決定 D-2026-10-24-02:** 本§11.2/§11.5の「直接カテゴリを複数選ぶ/自分で各分野の興味強度を答える」は初期提案であり、**先行診断の入口としては採用しない**。最新の体験/30問/5択・Unknownの具体設計は§12および[30問オリジナルドラフト](PRELAUNCH_INTEREST_DIAGNOSTIC_ITEM_BANK_V0_2026_10_24.md)に従う。§11.1・§11.3・§11.4の広い関心・Future連携・Canonical保存/安全ゲートは継続。


> Product decision: [D-2026-10-24-01](decisions/2026-10-24_PRELAUNCH_BROAD_INTEREST_DISCOVERY_DECISION.md)
>
> **PRODUCT DIRECTION ACCEPTED / INTERACTION & DATA CONTRACT PROPOSED / NOT IMPLEMENTED / NOT DEPLOYED / PRODUCTION_NO_GO_HOLD**。本項は§1・§9の「先行診断＝Study/Development現在地入力」という**入口UIの具体的な対象を更新**する。従来の深い診断・本人Plan/Task/履歴/認可を削除・改変しない。§3・§10・Issue #488の全HOLD継続。

### 11.1 製品目的と用語（2つの独立した成果）

1. **本人向けの関心プロフィール:** 学習や開発に限定せず、あらゆる目標領域に対する現在の自己申告の興味の強さを可視化し、後から修正できる。グラフは**性格/才能/適性・実際に使う可能性**の測定ではない。
2. **Canovia側の需要検証:** どの分野に興味を持つ登録者がいるかと、**「Canoviaがその領域を支援したら使いたい」かという利用意向**を分離して理解する。選択したカテゴリーの%を市場シェア/課金意思/需要予測とみなさない。個人の興味を公開ランキングや勝手な他者比較に使わない。

先行リリースにおける成功は、興味分布の結果・本人の必要に応じた準備Planへの接続・承認済みFuture案内、本人アカウントでの保持/再訪。正式版の専門機能をこの診断で解放しない。

### 11.2 画面体験：横断→自己理解→期待→任意のPlan

| Step | 画面 | 必須/省略可 | 受入と境界 |
| --- | --- | --- | --- |
| 0 | 「Canoviaで何を目指したい？」／診断の趣旨・利用範囲 | 診断自体スキップ可 | 既存の手入力Plan・Future・Helpへ自由に進める。自動でPlan作成しない |
| 1 | 興味のあるカテゴリを複数選択 | 任意、未定/その他あり | 学習・開発に限定しない。専門サービスの提供状況と関心カテゴリを混同しない |
| 2 | 選んだ領域ごとに興味の強さを軽く入力（例：1〜5の5段階） | 質問数を選択数に合わせる | 100点の手動割振りは要求しない。未回答・スキップも有効な状態 |
| 3 | **「あなたの現在の関心」結果画面**。カテゴリ別横棒グラフ/数値表、本人が修正できる | これが初回診断のユーザー価値 | 興味の合計を100%に正規化し各カテゴリの割合を表示。本人回答由来・基準日・計算方法を記載。能力/目標の完成度とは言わない |
| 4 | 目立つ**「興味のある機能の今後を見る（Canovia Future）」** と、関心の高いカテゴリに対する将来体験の公開カード | Futureは任意 | 「開発中」「検証中」「検討中/今後の予定」の**承認済み**状態・公開説明を表示。現行公開ロードマップに対応しないカテゴリには虚偽の約束を出さず、全体のFutureへ |
| 5 | **「この関心から目標を準備する」**（任意）と利用意向の任意質問 | Plan作成/調査利用は任意 | 選んだカテゴリをPlanの入力補助に使う前に本人確認。Career等の未実装専門Plan生成を自動で開かず汎用Planを利用できる |
| 6 | 保存/再訪/編集 | 本人認証を伴う永続化 | 同じCanonical Accountで保持。既存Plan、Self-Reported Study/Development情報と両立し、正式リリース時に再入力強制しない |

**候補カテゴリ（表示名/コードはオーナー承認後固定・バージョン管理）**：`study`（学習）、`development`（開発・ものづくり）、`career`（就職・キャリア）、`work`（仕事・生産性）、`life`（暮らし・習慣）、`creative`（趣味・創作）、`finance`（お金・家計）、`wellbeing`（健康・ウェルネス）、`other`（その他）と「まだ決めていない」。将来のカテゴリ分割・統合に対応できるよう識別子/カテゴリ定義版を残す。「健康」「お金」は*興味カテゴリのみ*で病歴・収入額・借入・家庭環境など詳細を聞かない。カテゴリーが多すぎるなら初回選択は表示グループ化で軽くする。

**割合の具体例（採用前テストで決定）**：本人が選択したカテゴリに1〜5の整数点を設定し、`share_i=100*score_i/sum(score)` を表示用に算出。丸め後の合計は100%となる決定論的な余り配分を用いる。元の点数・カテゴリ版も保持し、図表に「自己申告の興味の配分。将来の利用予測や適性評価ではありません」を表示する。全0/未選択なら「まだ決めていない」、グラフの強制表示なし。**横棒グラフ＋ラベル/割合**を第一候補とし、色だけで意味を表現せず、390px・Safari・キーボード/スクリーンリーダー用に同じ情報をテキストでも表示する。レーダーグラフはカテゴリ数が多く比較/理解が難しいため初回標準にしない。

### 11.3 結果からの「将来こんな体験ができます」連携

- User interest categoryとCanovia Futureの**公開カテゴリ/カード識別子**は別。手動審査された `category_key → approved_public_roadmap_key(s)` の**許可リスト**を作り、`PublicRoadmapProjectionService` と `config/canovia_public_roadmap.php` の**公開済みprojectionだけ**を紹介に使用。
- 学習：学習計画→演習/理解度/次Actionなどの**将来体験の方向性**。開発：作業記録→進捗/次Action/連携などの方向性。就活：応募/準備/選考の整理などの**将来候補**。Careerは正式版でカバー**予定/検討**でも現時点では実提供/時期を約束しない。公表文はロードマップのowner審査を要する。
- Futureに適合する公開カードがない場合は「この分野の機能は現在検討中／紹介可能な具体的な内容はありません」等の安全な汎用案内に止め、私的なFuture仕様や架空の進捗・Support票数を公開しない。
- 結果画面のFuture CTAを明瞭に（主CTAに近い位置）。Futureから結果へ戻れるようにするが、結果のprivate detailsをURL/queryや第三者参照へ漏らさない。
- **結果画面の中心は本人のグラフ**。市場データ・他ユーザーランキング・内部検証数値を混ぜない。

### 11.4 保存・既存機能・安全の互換性

現行は `PersonalizationController::store` が `study/development/unsure` だけを検証し、`PersonalizationBootstrapService::buildContext` はその2分野用に組み立てる。`PersonalizationContextService::normalize()` と `selfReportedPayload()` は固定キーを再構成するため、**未知キー追加だけでは自己申告が継続保存されない可能性がある**。したがって、全カテゴリを既存 `domains` に単純に混ぜ込んだり、Study/DevのFeature readiness・本人Progressの意味を変えたりしない。

- **データ契約の実装候補**：`interest_profile`（schema_version, category_version, score_map, recorded_at, optional_intent_map）を本人の自己申告として**既存Canonical UserPersonalizationContextの安全な名前空間**または審査された別構造へ保存。既存JSONカラム再利用の安全性は`current/normalize/selfReportedPayload/buildContext`等の実コードを検証し、更新時に双方の回答が保持されることをテストで証明した場合に限る。新migrationが不要と推定しない。既存Plan/Task/Study/Development/Goal IDとowner・historyに干渉しない。
- **Authorization**：準備診断の一般向け入口をL0に開放する設計が必要なら、現在のL1 `/personalization` を黙って緩めず、別の最小scope route/controller/deny test・本人認証/Guest責任を定義する。L1既存詳細診断は互換維持。Admin bypassや専門機能の解放なし。
- **研究データ**：本人が結果を見るための保存と、改善目的の集計用利用/計測を分け、明瞭に説明して選択可能にする。集計はカテゴリコード/利用希望など必要最小限、同意/撤回・目的/保存期間・削除・同一ユーザー重複の扱いを設計する。ユーザーの自由記述Plan全文、金融/健康の詳細を解析用イベントに複写しない。集計結果を外部/全ユーザー向けに公開しない。
- **無配備の受入**：新規L0／既存L1／Admin／非ログイン、開始/skip/途中離脱/訂正/再診断、割合合計=100・整数丸め・未選択・旧Context共存・正式UI復帰、Future対象あり/なし/状態変更時の表示、本人Plan関連付け/他人アクセス拒否、XSS/CSRF/IDOR、390px・Mac Safari実機と合成ブラウザ。個人データの保存失敗で成功を表示しない。
- **公開ゲート**：20 migrations・実Aiven/schema・独立restore・配置候補・全runtime/旧worker・Privacy・owner GOなどは完全維持。興味診断の実装でこれらのHOLDを解除したとみなさない。

### 11.5 Codexの次の実装スライス（方針承認後の推奨順）

1. **契約を先に固定**：カテゴリコード・interest intensityと利用意向の別質問・個人データ保存/分析同意・現在地詳細の既存セマンティクスの影響図。合成fixtureと少数の画面設計を合わせて合意する。
2. **純粋関数とテスト**：versioned分類、重み正規化、未選択/丸め、既存診断の自己申告と共存する読み書き、無断書換え防止。DB新DDLが必要なら本番HOLDに統合し、最小実装を選ぶ。
3. **元CanoviaのPreparation UI**：横断カテゴリ選択→関心配分チャート→「Canovia Future」「任意のPlan準備」。既存詳細診断/Planを破壊しない。全く新しい独立Local-first UIに置換しない。
4. **ロードマップ接続**：ownerが承認した公開コピー・成熟度にしか接続しない。Careerを完成扱いしない。書込み投票/Supportは先行profileの閉鎖境界を維持。
5. **合成Browser/E2E・実機UX**：L0/L1、本人帰属、保存/編集、別ユーザー、外部通信ゼロ、Futureからの復帰、近似アクセシビリティ。緊急公開切替/既存AP停止HOLDは別。
6. **Analyticsは最後に最小範囲で**：明示された利用意向と結果閲覧を無関係な既存Plan実績から推論せず、Privacy・集計仕様が確認された範囲だけ実装。分布の採用判断はサンプル数・分母・選択バイアスを明記。

**新チャット用追記**：「先行診断はStudy/Developmentの計画作成フォームから、広いカテゴリの相対的な関心プロフィールと独立した利用意向の検証へ製品方針が変わった。現行L1詳細診断・自己申告/Planのcanonical semanticsを守り、結果を横棒%グラフ、承認済みFutureへCTA、任意Plan保存へ接続する。§11とD-2026-10-24-01を読み、PRを小分けで作る。productionはNO-GO。」

## 12. 2026-10-24 後続決定 — MBTI風「何気ない30問」からの関心推定

> [D-2026-10-24-02 製品決定](decisions/2026-10-24_INDIRECT_INTEREST_30_ITEM_DIAGNOSTIC_DECISION.md) / [30問アイテムバンク・内部タグ・採点例 v0](PRELAUNCH_INTEREST_DIAGNOSTIC_ITEM_BANK_V0_2026_10_24.md)
>
> **PRODUCT DIRECTION ACCEPTED / QUESTION BANK & SCORING PROPOSED (NOT VALIDATED) / IMPLEMENTATION NOT STARTED / PRODUCTION_NO_GO_HOLD**。本節は`11.2の直接カテゴリ選択方式より新しい決定。`1の正式版へのデータ継続と`3・`10の安全HOLDに影響しない。

### 12.1 UXを修正する理由

- Safari実機の本人レビューで元Webの準備診断は表示/動作は正常だったが「目標作成フォームに見え、診断らしくなかった」。`11の「学習・開発・就活などを複数選んで興味強度を付ける」方式も**直接聞くアンケート**に留まる。
- 最初の30問前後は**日常の行動や考え方の軽いシチュエーション**で問い、質問に紐づくカテゴリは内部で複数の仮重みを用いて採点する。**心理尺度の検証はされておらず、MBTI商標・公式設問・タイプ分類・妥当性の主張を流用しない**。
- 導入で「日常への回答をもとに興味を推定し、製品の改善にも任意同意に基づいて活用することがある」と説明する。質問の裏にあるカテゴリ採点を画面内に表示しないことと、調査目的を隠すことは別。

### 12.2 決定した体験の骨格

1. 任意の30問診断に入るか、スキップしてPlan作成/Canovia Futureへ進むか本人が選ぶ。**初手でカテゴリを直接選択しない**。
2. 回答は**わからないを含む計5択**を初期案とする：とても当てはまる / やや当てはまる / あまり当てはまらない / まったく当てはまらない / わからない。Unknownを0や中立に換算しない。「どちらでもない」がないため回答者に無理をさせる可能性がある点はUX検証課題。厳密な5段階賛否尺度にUnknownを追加する6択は後続判断でのみ採用。
3. 5問程度ずつのカード、進捗、戻る/修正、途中中断/再開を支援。質問IDと版を固定して、ユーザーが未回答やUnknownを残しても誤って「興味なし」と診断しない。
4. 全体の回答から8つの分野（学習・開発・就職・仕事・健康習慣・家計・趣味創作・暮らし）の相対的関心スコアの**暫定推定**を計算。「その他/未定」は答え合わせ/本人補正の入口で、隠れた内部第9スコアにしない。
5. 結果は**%の横棒グラフ+数値/テキスト**。「あなたの回答に基づく現在の関心の傾向」と明記し、未判定・偏りの弱さ・結果を修正できることを示す。百分率は8領域の相対的な重みの表示用であり、才能、適性、関心の絶対水準や製品需要を表さない。
6. 結果画面に**Canovia Futureを見る**を目立つCTAとして、本人に関心の高いカテゴリを基に**承認済み公開ロードマップ**だけを紹介。「学習/開発/就活で正式版にできること」を構想・開発中・検証中・提供済みの正しい成熟度ラベルで区別し、未承認事項や約束を創作しない。
7. 別の任意ステップとして「Canoviaの支援を将来使いたい領域があるか」を確認する。**間接推定の興味と本人が明示した利用意向を別のデータに記録**する。希望者のみ、詳しいStudy/Development既存診断・汎用Plan入力へ進む。

### 12.3 採点/分析・既存保存契約・受入の境界

- 30問の暫定内部対応と計算/欠測/閾値/丸め、8カテゴリのID一覧は[質問バンク v0](PRELAUNCH_INTEREST_DIAGNOSTIC_ITEM_BANK_V0_2026_10_24.md)を正本とする。各領域の主設問3、横断6で重なりを表現。ただし**この設問群で関心領域を正しく計測できるかはUNVALIDATED**。初見試用とユーザー本人の結果フィードバックで校正し、本番の「精度」を宣伝しない。
- **分離保存**：間接診断の回答/Unknown/推定値/版/本人訂正を、既存の`study/development/unsure`という`PersonalizationController::store`の旧`domains`や`PersonalizationBootstrapService`の既存意味へ直接書き換えない。`PersonalizationContextService::normalize()/selfReportedPayload()`で新namespaceが脱落しないよう版付きの読書きを定義し、既存Canonical Account/Plan/Task/採点/第一回診断結果を保存・復帰・更新しても非破壊なことを証明。DDLが必要なら別HOLDのもとで検討。
- **権限**：現行L1`/personalization`を一般L0へ無差別開放しない。先行新規ユーザーが回答できるL0スコープが必要なら、別の最小権限ルートと本人帰属・途中保存/再ログイン・CSRF/IDORを設計し、`EnsurePreparationSurface`のdeny-by-defaultとAdmin/Worker禁止を破らない。
- **プライバシー**：本人に興味を可視化する個人目的と、Canovia側の改善目的での集計は別。目的/任意性/同意/撤回/削除/保存期間を設計。病気、収入/債務、宗教、政治信条、家族事情、面接で不利になる属性を質問/推定しない。個別回答をサイト内の他者ランキングや外部AI/計測へ出さない。
- **実装分割**：①オリジナル30問のレビューと採点版固定→②純粋な採点/Unknown/丸めテスト→③Canonical読書き/権限/旧Context保護→④元Webの質問/進捗/途中保存/結果→⑤公開承認済みFuture導線・任意利用希望・Plan→⑥合成ブラウザ/本人Safari受入。分析集計は本人の同意・Privacy評価後。
- **既存HOLDは維持**：AP学習画面を閉じてしまう`preparation_only`全員適用、20件の新migration/実Aiven照合、復元、worker/runtime config、Mac/iPhone本人受入、公開候補の固定SHA/Owner GO。`main`統合、合成E2E、文書変更のいずれも本番配備の証明ではない。

**新チャット/ Codex追加指示:** 「先行関心診断の最初にカテゴリを直接選ばせない。`12/D-2026-10-24-02`と30問アイテムバンクv0を最新の入口にする。回答5択のUnknownは欠測。本人結果はグラフとCanovia Future承認済み情報/任意Planにつなぎ、需要/利用意向は別質問。旧Study/Dev ContextとPlanを壊さず小PRで実装。実Aiven・Render・production/HOLDに触れない。」

## 13. 2026-10-25 Shared-runtime convergence / activation review delta

The current source audit compares development `e8fad276dab68dccdd35e445c3dcf78ee6c97a1b`
with historical production source `15abdc9de6f24b5250fb255c579d1aa55facaf1a`.
Git source is not Render Live or real Aiven acceptance. The Render read-only
connector is unavailable in this session; current Live/branch/auto-deploy are
**UNKNOWN**, not inferred from historical OFF/old-Live records. No settings are
changed. Local full-history comparison confirms94→114 migration files,20 additions
and no removed old migration; actual production ledger/schema remain unknown.

| Shared surface | Classification / current evidence and remaining gate |
| --- | --- |
| Schema/20 migrations/data/old writer | BLOCKING_SHARED: reuse existing isolated8.0/8.4 forward/partial-DDL/retry/constraint/recovery CI; real ledger/schema/DDL principal/binlog and mixed old/new writers NOT_RUN |
| Startup migrations | BLOCKING_SHARED: current startup defaults to check-only ledger readiness, explicit exact-SHA apply runs once and never automatic rollback. Runtime effective settings/writer fence need owner acceptance |
| Docker/PHP/Nginx/dependencies | BLOCKING_SHARED: source and CI exist; actual Render image/flags/caches/health/worker version UNKNOWN |
| Auth/session/CSRF/owner | BLOCKING_SHARED: existing #857/#859/#861 account/guest/replay/IDOR/conflict tests reused; real session/domain/cookie/device acceptance separate |
| Guest→Account/Plan revisit | BLOCKING_SHARED: existing canonical ownership/claim and same-ID browser journeys; no new Preview DB or forced guest persistence |
| Feature/level/route/API | BLOCKING_SHARED: existing preparation whitelist/default L0/admin denial preserved; no actor is implicitly assigned L1. Missing runtime flag evidence is not a PASS |
| Queue/worker/scheduler | BLOCKING_SHARED: Queue::before and Console denial existed, but dispatchNow/non-queued dispatchSync bypassed queue events. Reproduced with a handler/closure; add the same restrictive profile to the Bus handler pipeline. false-profile behavior preserved. Old processes/cached false config still require an explicit stop/restart/fence; no hot-config guarantee |
| Browser SW/static assets | BLOCKING_SHARED operational check: existing SW caches shell assets, not private page responses or mutations; preparation HTML is no-store/private. Historical installed SW/CDN/native browser state still requires real acceptance |
| Recovery/restore/forward repair | BLOCKING_SHARED: synthetic recovery and #800 logical restore reused, not actual Aiven Full backup restore or real restore proof |
| Render/production rules | UNKNOWN→BLOCKING: Git production ref rechecked independently; actual Render and operator branch protection/Auto Deploy readiness require read-only owner evidence |
| Deferred specialist UI/API/jobs | DEFERRABLE_ISOLATED only where preparation route/feature/queue/console refusal is proven. Internal Study/Dev/AI/MCP/GitHub/charging capabilities are not made public by this work |
| Account/Plan runtime acceptance | BLOCKING_SHARED: original account/Plan contract remains authoritative; old AP/Study/Development data retained, but enabling preparation hides their execution/history UI, including owner's AP use. No production adoption inferred |

### 13.1 Interest experience and current authority

The30-item config and pure scoring Service from #863 exist; the preparation
screen still uses the older Study/Development form. Thirty-question namespace,
progress persistence/result/revisit and opt-in Plan bridge are implementation
work, not completed evidence. Use the existing bank and scorer; answers remain
four agreement levels plus unknown (five choices total), per §12. Do not add a
sixth choice or call Unknown zero without a later product decision. Existing
legacy context and all Plan/Task/history must survive any new namespaced save.

### 13.2 Track E — review packet, not activation

Reuse #887's exact checked HEAD `a7f7b0d5b366a2e0797141d21f2d3ff569111a8b`,
25 CI jobs, both MySQL59 cases, #806 TLS/remote and prior Mac reports. No e858
code or accepted fixture is recreated. `PrivateReview.check` remains pending-only;
`LauncherCandidate.run`, `OpenerCandidate.open`, `Adapter.prepare` and
`PrivateWire.connect` remain unconditional BLOCK. Actual private B admission and
live executor wiring are **NOT_IMPLEMENTED / A: TECHNICAL_WORK_REMAINING**.

Before changing those functions, independent review must cover (1) the human
owner/operator and managed Mac operating assumptions, (2) exact public source,
wheel/runtime and tooling/bundle adoption, (3) private target/CA/scope custody and
independent expected hashes, (4) fresh B2 and one local trial, (5) cancellation,
revocation/expiry/interrupt STOP, (6) no target/role/Runtime substitution or retry,
(7) same-target2TLS/Control→Data/SQL guards, (8) remote-unverified HOLD/incident
responsibility, (9) cleanup and private storage/deletion/OS/loader residual risks,
and (10) new differential tests and managed-Mac acceptance. Human supervision is
not software identity authentication; no new external signature authority or
rollback-resistant nonce service is made mandatory. Self-review/CI are not an
independent reviewer or owner signature. No approval-shaped object is added.

The minimum later order is private operational admission contract review →
bootstrap/Entry binding → separately reviewed single-target executor linkage →
owned-only negative/regression and exact artifact review → managed-Mac new-delta
acceptance/adoption/P1/R1–R6 → separately scoped fresh B2. Phase1 bootstrap and
full17 metadata need distinct scopes. Real TLS/grants/remote state, schema/index/
FK/TRIGGER/principal/binlog/restore and production publication remain separate.
This packet does not authorize any execution or release, and P2 must not delay
safe Track R implementation. All results/immutable SHAs go to existing #488.

### 13.3 30問の本人保存境界（2026-10-25、開発用）

既存 `user_personalization_contexts.self_reported_context.interest_diagnostic` に版付きの回答・採点snapshot・明示的な関心修正・支援希望を分離保存する。migrationなし、本人sessionのみ、L0のPreparation-only限定route。個人保存への同意を必須とし、市場分析への同意や外部AI送信を兼ねない。既存の純粋採点Serviceから結果を再計算し、未知版は上書きしない。

User→Contextの同一順序lock、namespace単位revision、同一送信の冪等性により旧タブ・応答消失・legacy自己申告更新を扱う。旧自己申告・推定・観測・Plan/Task/履歴は維持し、legacy AI contextへ新namespaceを渡さない。準備profile OFFでは新routeを404にする。UI・ブラウザ受入は別sliceであり、この保存実装だけで利用体験完成や本番受入とはしない。

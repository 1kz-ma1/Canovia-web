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

At the start of this audit, the30-item config and pure scoring Service from #863
existed while the preparation screen still used the older Study/Development form. That original gap is now addressed by §13.3–13.4 below; deployment remains
unverified. Use the existing bank and scorer; answers remain
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

### 13.4 30問→Future→本人Planの開発受入（2026-10-25）

§13.1の入口未実装を解消。既存バンク・採点を再利用し、任意の30問を5問×6ページ、4段階+Unknown、明示的な本人保存同意、途中保存/復帰、横棒+%/未判定、本人の関心修正/別枠の支援希望、開発成熟度を保った公開Future紹介、任意のCanonical Plan入力へ接続する。既存Study/Devフォームは明示L1に維持し、全員L1にしない。診断なしのPlan作成も残す。

新しいJSはPreparation-only専用で、通信失敗時は入力を残し成功を表示しない。旧タブ競合は上書きせず、最新記録への導線を出す。同じ回答の応答消失再送は§13.3の冪等性を利用する。削除後の同一時刻・同一回答での再作成は別の記録寿命IDとし、削除前の旧タブrevisionを復活させない（固定時刻で修正前の失敗/修正後の拒否を確認）。本人が明示的に関心を空にした場合も未確認の推定へ戻さず、後の回答編集でも維持する。JSなしでも標準フォームで保存可能。本人の確認済み関心（未指定なら採点上位）から、既存公開projectionの全般/対応分野だけを紹介し、非掲載分野の機能やSupport数を創作しない。分析・外部送信・自動Plan作成なし。

差分受入: PHP75件/770 assertionsと、既存runnerの新 `preparation_interest` journey。127.0.0.1:18805/使い捨てSQLite/合成Accountのみ、L0で登録→回答→保存/再読み込み→修正→Future→任意Plan→編集→logout/login→同一ID再訪を確認する。旧タブ、通信失敗、同一送信、CSRF、別Accountの閲覧/編集拒否、認証失効、専門URL拒否、390px/desktopを含む。既存Preparation false/true×L0/L1×匿名/本人/adminの12ケース、旧AP/Development/履歴保持、Guest/Planの旧ブラウザ検証を同じCIで回帰する。CI番号・最終HEAD・統合SHAはIssue #488に固定する。既存Futureのpinned Playwrightコンテナ/Chromium・WebKit matrixで新journeyも検証する。Linux WebKit/Chromiumの合成検証をSafari/iPhone実機PASSとはしない。

本人の新しい実機差分はこの30問UIと再訪導線のみ。旧94件・CA/grants・e858 Macテストを再要求しない。既存Mac labを、採用した固定mainの別clean checkout（.env/privateデータなし、専用vendor、build済み）で再利用する:

```sh
python3 scripts/ci/preparation_mac_lab.py --source-sha "$(git rev-parse HEAD)"
```

表示されたloopbackだけをSafariで開き、合成 `normal@example.test` (L0) または新規合成登録、公開FAKE password `Synthetic-Preparation-Only-2026` を使う。30問/Unknown/中断保存/結果/訂正/Future/任意Plan/再ログイン/狭幅を確認し、Ctrl-C後 `LAB_STOPPED_DISPOSABLE_DATA_DELETED` を確認する。以前のAP学習・元DB・本番Accountには触れない。単独labがそのportを占有していればSTOPし、別serviceを止めない。

現在地は `BLOCKING_SHARED_REMAINING`。コード上の体験完成と、実DB物理監査・復元・writer停止/起動順・runtime/Release Level実設定・実認証/session・既存installed SW・Render保護・実機/本番公開の受入は別。Preparation-onlyの採用は既存AP実行/履歴UIも閉じるため、ownerの影響受入なしに本番有効化しない。12月1日は安全ゲートを省略できる期限ではない。

## 14. 2026-10-25 P0 runtime / preflight / CI画像（Mac操作不要）

### 14.1 Cached false / STOP後再開の修正

#888の既存Bus/Queue/Console/直接Job境界を再利用。修正前の合成3テストで、(1) runtimeの `CANOVIA_PREPARATION_ONLY=true` がcached falseに隠れること、(2) 同じapplicationが一度拒否した後にfalseへ変更するとhandlerを再開することを再現した。現在はraw runtime指定を制限の追加にだけ用い（Laravelと同じfalse/(false)の大文字小文字を許容し、falseでrestrictive configを解除しない）、一度STOPを観測したapplicationの実行guardを終端状態にする。Bus/Queue/Console/既存2 Jobが同一guardを使い、新しいapplication/processの正常な起動とは区別する。

独立した合成PHPプロセスのメモリ内handlerだけで false→true→false の実行/拒否/拒否と回収を確認する。実queue daemon・scheduler・workerへの停止/再起動操作なし。関連PHP50件/404 assertions成功。missing Vite manifestはlocal build準備で解消し、テスト条件は緩和していない。

**限界:** 新コードを持たず、false環境で既に稼働している旧Workerへ、この修正から通知/強制停止する機能はない。Webだけtrue・旧Workerがfalseで残存する構成は依然 **BLOCK**。guardは任意のコードを停止する機能や全writer列挙の証明ではない。cutoverは旧Web/worker/scheduler/queue consumer/自動再起動の停止とin-flight完了/remote不明HOLD→旧writer不在の独立確認→固定新source/config/cacheの全runtime照合→新Webの限定起動、の順序が必要。Preparation profileでは専門worker/schedulerを再開しない。残存・観測不明・新旧混在・古い設定/配布物・復旧後の無承認再開はSTOP。旧Job/予約/failed-jobを削除・再送しない。実プロセス不在はLIVE_VERIFICATION_REQUIREDであり、ローカル証跡やJSON自己申告ではPASSにしない。

### 14.2 既存Preflightの準備版レポート

`platform_release_preflight.py --preparation` は既存のGit topology処理と固定sourceを再利用し、旧94のblob保持・新114/追加20のinventory・変更件数・worker回帰のCI登録を検査する。実migrationや旧writer動作をstatic PASSへ変換しない。既存release-refの厳密照合modeは変更しない。

追加の `--github-readonly` は既存bounded GET helper/gh認証を使い、単一repoのexact HEAD check/runsとmain/production rule inventoryだけを読む。ログ・artifact・資格情報・本番サービスには触れない。必須scopeのjob名だけでなくrunのrepo/HEAD/workflow pathを照合し、失敗はFAIL、欠損/未完了/skip/改変/取得不能はUNKNOWN。Squash後のmainと検証PR HEADを `--ci-head` で対応させる場合、Git tree全体の完全一致を必須にする。別treeの成功CIを流用しない。

出力はPASS（明記したGit/CI観測範囲のみ）/FAIL/UNKNOWN/OWNER_ACCEPTANCE_REQUIRED/LIVE_VERIFICATION_REQUIRED。branch ruleの取得成功は保護内容や公開承認の受入ではない。旧worker不在・全runtime設定一致・現Aiven schema/ledger/TRIGGER/DDL・実backup/restore・本番auth/cookie/SW/Render・AP利用影響と実機は未受入のまま。外部JSONの自己申告でこれらをPASSにする入力は設けない。結果が全CI成功でもexit2/BLOCKING_SHARED_REMAINING、`release_authorized=false / source_adopted=false` を維持する。実操作の許可スイッチなし。

```sh
python3 scripts/ops/platform_release_preflight.py --repo . --preparation \
  --release-sha <固定候補40桁> --ci-head <同じtreeの検証HEAD40桁> \
  --live-sha 15abdc9de6f24b5250fb255c579d1aa55facaf1a --github-readonly
```

ここでlive-shaは歴史的Git比較対象で、現在Render Liveの証明ではない。proposal sourceをレポートするだけで新候補へ自動採用しない。Mac操作は不要。CLIは任意の補助module importによるPython bytecode生成も禁止し、GitHub無接続stubによる子プロセス回帰で作業treeへ__pycache__を書かないことを検証する。

### 14.3 Macなしの390px画像確認

#890の同じ `preparation_interest` journeyを再利用し、新しい機能/別labを作らず、390×844で (1)30問の入力 (2)関心グラフ (3)Future (4)Plan保存 (5)再ログイン後の同一Plan再訪を撮影する。既存のChromium/WebKit jobの `future-chromium / future-webkit` Artifact内 `interest/interest-mobile/` に5 PNGとmanifest、既存desktop結果も保存する。生成件数5・viewport幅390・横はみ出しを検査し、既存uploadを再利用（7日保持）。localhostの使い捨てSQLiteとソース固定の合成Accountだけを使用し、実利用者/認証情報の取得なし。manifestは合成由来を示すだけで、本人受入や実環境の証明にはならない。

オーナーは現在Macを利用できないため、**今回のMac Safari/iPhone本人操作は不要・後日**。画像とLinux WebKit/Chromium CIで操作/表示を確認しても実機受入はOWNER_ACCEPTANCE_REQUIREDのまま。旧e858/CA/grants/94件/Mac検証を再要求しない。実環境へのGOやPreparation-only有効化も含まない。

### 14.4 予約前のmanaged Queue loop終了

JobProcessing/Queue::beforeの例外だけでは、既に予約されたdeliveryのattempt/release/failed記録が動き得る。既存guardをLoopingにも適用し、STOP時はmanaged `queue.worker` のshouldQuitを立ててfalseを返し、Laravelの通常daemon終了処理へ入る。正常時はnullで他のLooping listenerを妨げない。既存Queue::before/Bus/Console/直接Jobはbackstopとして維持。

ブローカー接続を一度でも呼ぶと失敗するmock managerと、OS signal/sleepだけを除いた合成Workerで、修正前の接続要求を再現、修正後の予約前終了・接続0・設定をfalseへ戻しても再開しないことを検証。関連PHP51件/414 assertions成功。実Worker/Job/queueには触れない。これは既に走っているhandlerの中断や、別binary/false環境/外部supervisorの停止ではない。境界確認後〜pop間の変化や予約済みdeliveryのbookkeepingまでゼロと認定しない。標準managed daemon以外も含む全writer停止と再起動禁止は依然実環境ゲート。

## 15. 2026-10-25 Cutover / Recovery / Candidate preparation

### 15.1 更新された製品判断とstartup cache

オーナーはCanovia外でAP学習中で、他の既存利用者へも利用停止を案内済み。先行公開時のPreparation-only適用によりStudy/Development実行・履歴画面を一時的に閉じることを許容した。**OWNER_ACCEPTED_PRODUCT_RESTRICTION_ONLY_2026_10_25**。Account/Plan/Task/履歴は保持し正式版で再利用する。この判断は全利用者の停止・Session失効・旧writer/worker停止・DB/IdP安全性・本番GOを証明しない。§13–14の「AP利用影響受入待ち」はこの製品制限の範囲だけ後続決定で更新され、実運用の切替受入は残る。

起動調査で、build contextがhost生成のbootstrap cacheを含め得ることと、古いconfig cacheを残したままschema確認が先行することを確認。合成fixtureで、schema client起動時にも旧cacheが残る失敗、未許可cache override/symlinkを拒否しない失敗を再現した。`.dockerignore`で生成PHP cacheを除外し、起動policyはPHPを呼ぶ前に固定のowned `bootstrap/cache/config.php`だけを無効化する。未知のAPP_CONFIG_CACHE、symlink/非通常ファイル、cleanup失敗はPHP/DB処理前にSTOP。内容は読まず、削除失敗の生エラーも出さない。通常のcheck-only/承認SHA付き一回apply/部分DDL STOPは維持する。

この処理は再生成可能なcacheのhygieneで、旧process停止やDB identity監査の代わりではない。startup directoryは信頼済みsource/単独writer/所有権の一致が前提で、敵対的な同時filesystem変更への一般的な防御とはしない。実画像・設定・APP_KEY/cookie/session/target一致は未受入。未知cache pathを黙って掃除/参照しない。実Render/Aiven/Worker操作なし。関連PHP35件/385 assertions成功、固定e858/SQL/Reference/4公開BLOCKとmigrationは不変。
### 15.2 Cutover Rehearsalの範囲・停止条件

既存 `preparation_mac_lab` のport/所有プロセス/専用SQLite/合成Account/秘密なし環境/HTTP routerを再利用する。新しい本番connectorや承認frameworkはない。現行SourceのOriginal profileからPreparation-onlyへ**同じDB/APP_KEY/cookie/session storage**で限定再起動し、旧合成Account/Plan/Task/採点履歴/未処理Jobが維持されることを比較する。旧合成Sessionは継続して本人Planを読めるが、以前の専門URL/POSTは404、CSRF不一致は419。既存30問→Future→任意Plan→保存/再ログイン再訪のjourneyも再利用する。

これは歴史的production **15abの全バイナリを実行する試験ではない**。現行114のSQLite fixtureを使ったcoordination/HTTP/data continuityの検証であり、旧94→114のMySQL移行・制約・部分DDL・独立論理復元は既存CI証跡を参照する。実MySQLや実installed Service Workerの受入にはしない。旧画面のsaved URLを再利用してserver-side拒否を確認し、offline旧HTML表示まで消えたとは認定しない。

順序: Source/config固定→データ保全/復旧前提→旧Web停止→consumer/再起動停止→in-flight drain→writer不在→schema前提→一回migration→全runtime整合→限定Web起動→受入→異常時安全停止。既存Preflightの純粋な `cutover_order` はこの**計画の順序だけ**を検査し、実停止・個人認証・承認を証明しない。STOP後の継続/段階skip/二重MIGRATIONはBLOCKING。実行機能やGOを返す入口ではない。

拒否ケースは、旧Web残存、新Webだけ更新/旧consumer残存、consumer再生成、in-flight SQLite transaction、観測不能、未処理Job未整理、migration境界でのwriter出現、runtime/APP_KEY/DB/URL/level差替え、Preparation flag false、古いcache、部分DDL後の無承認retry順序。実プロセス/lockで確認するケースをVERIFIED_ISOLATED、注入されたunknown/config/order条件をVERIFIED_SOURCEとして区別する。各負例は独立した合成trialで、同じ本番GOの再利用ではない。15ケースのBLOCKを観測し、その後の別正常caseでsafe shutdown/旧行保持/子process回収を確認する。

pending Jobは合成queueへ固定payloadで1件を残し、consumer不在とdigest不変/attempt=0を検査する。purge/replayをしない。実Redis/SQS/外部webhookや別machineの不在をこの試験で証明しない。新Worker guardが旧コードを遠隔停止できるという主張もしない。in-flight rollbackはowned SQLiteでの観測であり、クライアント終了だけをMySQL remote終了確認にしない。

lab再起動ではTIME_WAITと現存listenerを区別する。SO_REUSEADDRのprobeでもlive listenerへの重複bind/借用を拒否し、停止済みowned PHPのみを再起動する。全owned web/actorを登録して回収し、未知cleanupではprivate evidenceを残してSTOP。失敗時のraw log/browser storageは0700のprivate fixture内だけに保持し、Artifactへ入れない。正常時はprivate DB/session/stateを削除した後にsanitized reportを発行する。

CI: Shared DB Preparation Safetyの既存jobに `Isolated cutover rehearsal (synthetic only)` を追加し、`preparation-cutover-rehearsal` Artifactへreport/合成画面/既存toolで作成した全未出荷source manifestだけを7日保持する。製品UI/独立lab/暗号署名authorityを増やさない。既存Mac本人受入を再要求しない。

### 15.3 Recovery / Candidate報告と判定

Recoveryの正本は [既存DDL/復旧gate](PRELAUNCH_AIVEN_RUNTIME_DDL_AND_RECOVERY_GATE_2026_10_15.md) の2026-10-25追記を参照。MySQL8.0/8.4の旧94→114/部分DDL/trigger/FK/既存行/独立logical restoreと、#897のstartup変更に必要な既存upgrade CIを再利用する。Migration blob・SQL・Reference・strict比較を緩和しない。Aiven Fullの記録とmysqldump独立復元を区別する。

候補案は本実装の最終mainの**不変40桁SHA/tree**とするが、本番採用・正式tooling採用・新release ref固定はしていない。現在production ref/歴史的Source15abとの差分を `integration_freeze_manifest.py` で全件列挙し、各pathのchange/両blob/risk queueをArtifactへ保存する。機械分類はowner割当/安全審査ではなくSOURCE_ONLY/FUTURE_NOT_IMPLEMENTATION/REVIEW_REQUIREDを保持する。Source inventoryの完全性を全機能の実装/公開/本番互換性へ昇格しない。fixed SHA/tree/CI Run ID/manifest要約は#488へ固定する。

既存Preflightの `checks` は互換性を維持し、`readiness` で VERIFIED_SOURCE / VERIFIED_ISOLATED / LIVE_VERIFICATION_REQUIRED / OWNER_ACCEPTANCE_REQUIRED / UNKNOWN / BLOCKING に分類する。製品の一時UI制限のowner記録はSourceに存在することだけを検査し、Session失効・writer不在・本番GOへ変換しない。`preparation_effect_on_existing_ap_use` の残受入は製品判断の再要求ではなく実運用cutoverの受入を指す。

緑のjobだけではskipされたrehearsalを認定しない。GitHub GETで同じHEAD/repo/workflowの所有job/実step成功まで照合する。missing/skip/別HEADはUNKNOWN、失敗はBLOCKING。CLIにoperator receiptや自己申告承認を投入して実gateをPASSにする機能はない。e858真正B起点/Live executorの未実装はBLOCKINGの別Trackで、4公開BLOCKは不変。新Sourceを自動で監査候補e858に置換しない。

実schema/ledger/TRIGGER/DDL/binlog、実backup/復元、post-snapshot削除/同意取消/Session失効の保持、全writer/再起動制御、実runtime/IdP/session/cookie/cache、prod rules、実機、fresh owner GOが未成立のため候補は**案・未採用**。現時点はBLOCKING_SHARED_REMAINING / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO_HOLD。

### 15.4 後日のOwner Action Packet（実行許可ではない）

**共通:** `RC` は#488最終記録の固定40桁SHA/tree/検証HEAD/CI、`E858` は `e858b9c855970fbe73f37a2d60c9db6525b132d6`。全工程はowner/operator本人が別途対象・scope・時間窓・副作用を明示承認した後だけ実行する。実値・署名・Password/CA/host/DB/private記録は本人のprivate custodyに置き、GitHub/ChatGPTへ提出しない。共有証拠は固定SHA/判定/件数/時刻/cleanup状態のみ#488、raw/evidence原本は本人private保管。未知/不整合/中断はSTOP/HOLD、同じ許可で再試行せず再審査。過去のCA/grants/Mac受入は歴史的証拠として再利用し、現在のLive受入へ昇格しない。

| 工程・担当・対象 | 前提/必要権限 | 副作用 | 成功条件 | STOP/異常時 | 証拠/残ゲート |
| --- | --- | --- | --- | --- | --- |
| 1 Mac Safari UI / owner / RC | Mac利用再開、別clean checkout/既存lab、local権限 | 合成DB/session/Planのみ作成・終了時破棄 | 30問/Unknown/保存/訂正/Future/任意Plan/再訪/390px | UI/保存/所有権不良は中止、実Accountを使わない | 合成画像/差分判定。今は操作不要 |
| 2 iPhone Safari/WKWebView / owner / RC+iOS build SHA | 別途承認した隔離HTTPS環境またはdevice transport、iOS実機/Xcode条件 | 合成アカウント操作のみ | 表示/keyboard/safe-area/back/保存/復帰 | endpoint/source不一致はSTOP | device/build/固定判定。127.0.0.1 labを勝手にLAN/tunnel公開しない |
| 3 現schema/ledger / 承認済operator / E858+RC migration一致 | e858 admission/Live executor未実装を先に審査・解消、tooling採用/fresh scope GO、既存metadata-only権限 | 実接続・metadata読取、remote処理 | source/SQL/ref/TLS/session/ledgerの固定照合 | 権限不足/過剰・unknown remoteはSTOP、新GOなしretry禁止 | 固定code/countのみ共有。現時点実行不能 |
| 4 Aiven物理構造/DDL principal / 既存認可DBA / E858+RC | 実新設名衝突/M08/INDEX-FK/完全TRIGGER/DDL-binlog条件をprivate独立審査 | 許可されたread-only観測のみ | 厳密BLOCKと意味差診断を別評価、全前提一致 | 観測不能を不存在にしない。監査user拡権で通さない | private原本と固定判定。DDL/GRANTは含まない |
| 5 old Web/consumer/in-flight / owner運用担当 / old15ab+RC | 実service/cron/webhook/queue/auto-restart全一覧と停止窓を別承認 | 実停止・新規write拒否 | 独立観測で不在、in-flight/remote既知、pending隔離/非再送 | 生存/再生成/unknownでDDLへ進まない | private roster/時刻・固定件数。案内済みだけで満たさない |
| 6 backup/独立restore / owner+DBA / RC前のquiesced DB | 別承認したexport権限・暗号化/鍵/保管/削除・独立先容量・全writer停止 | 実dump/転送/隔離先DDL/DML（別承認） | schema/row/owner/Plan/FK/trigger/復元所要時間/失効保持 | real Full復元とlogical復元を混同しない。未確認なら公開拒否 | private RPO/RTO/比較。現時点NOT_RUN |
| 7 一回migration→runtime統一 / owner+DBA / RC | 3–6完了、fixed source/20順序、承認済one-off CLI、Web/worker停止 | 実DDL/DML/cache無効化・再生成は別承認 | 114台帳+物理構造/保持確認、全image/config/cache一致、prep check-only | 部分DDL/未知構造/旧writerでSTOP。retry/down禁止、forward審査 | private差分/段階記録。CLI移行中にWebや専門workerを開始しない |
| 8 Auth/session/cookie/SW / owner / RC | IdP/APP_KEY/private file/Session方針のprivate確認、合成環境から先に受入 | 実Session変更は別承認、通常login/write smokeは明示対象のみ | owner/他人拒否/CSRF/再訪/旧URL拒否/実cache挙動 | key/IdP/session不整合で公開停止。既存Session失効は未証明 | 秘密なし固定判定。旧cookieをChatGPTへ渡さない |
| 9 RC/prod rules照合 / owner GitHub管理者 / RC | 全code/CI/real/device gate完了、rules/required CI/merge ancestry、独立審査 | この照合はGETのみ。promotionは別GO | candidate SHA/treeが受入対象と一致、policy有効 | moving main/別tree/未受入ならSTOP | GitHub不変URL/CI IDs。新release固定/production mergeは未実施 |
| 10 First manual deploy/limited smoke / owner Render担当 / RC | 別の明示deploy GO、Auto Deploy OFF確認、6–9/復旧・緊急停止担当確定 | 本番配備/限定本人操作は別承認 | 限定範囲でauth/Plan/隔離・実機smoke、監視/停止可能 | 旧writer/不整合/unknownでwrite閉鎖・HOLD、独断rollback不可 | fixed Live SHAと受入記録。一般公開/Auto Deployはさらに別判断 |

実環境未受入の技術的不一致は、本人署名だけで許容したことにしない。Macが使えない今は本人操作を要求しない。正式P1/R1〜R6/B2/独立審査はこのpacketで代行しない。

## 16. 2026-10-25 Release Candidate security review（Source/隔離のみ）

### 16.1 公開auth URLのNginxログ境界

共通経路の内容審査で、既存Nginx access logの `$request` / `$http_referer` が、Preparationでも許可する `/reset-password/{token}?email=...` を記録し得ることを確認した。通常のupstream failureのNginx error logもrequest URIを含むため、access formatだけの変更では不十分。これは過去から存在する欠陥で、本番で漏えいが起きたという実測ではない。

合成tokenを使ったnetwork-none/所有Nginxで修正前の漏えいを再現。新sourceではaccess logをmethod/status/bytes/latencyだけに限定し、request由来のraw Nginx error streamを `/dev/null` へ送り、代わりに502/403等のstatus監視を維持する。URL/query/Referer/header/body/IP/UAはaccess logへ出さない。raw upstream errorの診断能力は失われるので、事故時も未審査のdebug loggingを本番で有効化せず、固定statusとprivate/隔離再現で調査する。ルーティング・認証・DB・nginxのPHP front-controller制限は変更しない。

テストは専用Nginx Debian fixtureの実HTTPでreset GET/POST、Referer/Authorization/Cookie、dotfile拒否、存在しないFPMへの502を含む。秘密を含み得るログ内容は成功/失敗どちらでもテスト出力へ出さず、固定判定のみ。コンテナはnetwork-none/read-onlyで作成・回収し、実PHP/DB/credentialsは起動しない。Dockerfileの実production構成・staging設定との回帰は既存CIを使う。Render edge/Laravel/FPM/IdP/外部mailerの実効ログや過去ログ保持をこの修正で安全と認定せず、実運用gateとして残す。新しい本番設定変更・実ログ参照/削除は実施しない。
### 16.2 全差分分類と高リスク内容審査

既存 `integration_freeze_manifest.py --preparation-review` を使い、historical production→固定候補の**全path**をA PRELAUNCH_REQUIRED / B DEFERRABLE_ISOLATED / C SECURITY_REVIEW_REQUIRED / D FUTURE_ONLY / E UNKNOWNへ漏れなく割り当てる。各pathのchange/両blob/依存/risk queueは従来通り保持。分類は審査対象の割当で、内容審査・実運用隔離・独立レビューのPASSではない。Aにも追加審査は必要、Dは非runtimeの文書/開発資産を含み、現行正本の権威をFuture Designへ降格する意味ではない。未知pathはEとし、既知runtimeは安易にBにせずCへ置く。件数・固定Source/tree/JSON hashは#488最終記録に固定する。default inventory modeと監査SQL/Referenceは不変。

Bは審査した4 controller（StudyPracticeReview/Retry、AdaptiveLearningHistory/NextAction）のhandler実行だけに限定。専用routeがPreparation whitelist外で、constructorにI/Oがなく、shared codeからの別呼出しがないことを内容確認した。内部のReview↔Retry呼出しも同じ閉じた経路。DB/migration/共用serviceはBへ含めない。全app/bootstrap/config/routes/resources/public、Docker/Nginx/PHP/supervisor/startupとComposer両fileのGit objectを、manifestの固定 `review_basis`（初回#898、§16.5後は同Auth拒否差分を審査したSource）と照合し、一つでも変更/欠損ならBをCへ戻す。runtimeのPreparation false・旧worker・実設定不明は、このSource限定Bから実運用PASSへ昇格しない。既存admin拒否テストに当該GET/POSTを加え、binding/controller前404を確認する。

| 具体的な到達経路・内容審査 | Source判断・再利用証拠 | 本番へ残す条件 |
| --- | --- | --- |
| Nginx→public/index.php→bootstrap/AppServiceProvider→web/API | NginxはPHP実行をfront controllerに限定。Bootstrapはauth/session/CSRFとPreparationを共有。provider登録はbinding/guard/event設定で、登録時に外部通信しない。Queue/Bus/Consoleは同じSTOP latch。§16.1のURLログ欠陥を修正。 | edge/Laravel/FPM/IdPの実ログ、全runtime/config/cache/source一致。Sourceは本番観測ではない |
| Preparation whitelist→Home/Auth/Plan/Personalization/PrelaunchInterest/Future/Help/privacy | default deny、admin例外なし、未知API拒否。専用layoutはpreparation.mjsを読み、app.js/専門autosave/instant SWを起動しない。Futureは承認済projectionのみ、Support DB/AI投票を使わない。既存#888–898 browser/所有権/CSRF/競合テストを再利用。 | 実認証/cookie/CSRF/旧installed SW、Safari/iPhone。旧SWのローカルHTMLはserver拒否と別 |
| login/register→GuestPlanClaim→Plan lock/owner→LearningContextSourceEpoch/FirstRun/FutureMemo | 再生成Sessionと所有cookie照合、Plan-first transactionを確認。専門画面が閉じてもこの経路は到達する。epochはopt-in/private Studyでだけ書くが、共有契約から削れない。旧ID/ownerは維持、別Account拒否は既存テスト。 | 現Session/remember-cookie/APP_KEYとguest cookieの実効方針、旧writer不在。ユーザー案内は失効の証明ではない |
| Plan save/update→row lock/revision→activity/GoalContext/epoch | 保存前にlive ownerを再検査。revisionで古いタブを拒否。Task/採点/履歴を書き換えず、canonical Planと必要な関連記録は更新する。非公開/共有の旧状態を勝手に消さない。 | 実114構造/trigger、既存Plan/owner/履歴の保持、mixed-writer不可。表示OFFをDB安全性と混同しない |
| Account削除→locked User/Plans→cascade/private-file outbox→afterCommit | 明示password確認/本人Sessionに束縛。Account削除は本人のprivacy機能で、Cutoverによるデータ保持とは別。削除は専門機能OFFでも共有DB/実storageへ作用するため関連private-file serviceはCで追加審査対象。outboxは暗号化・失敗pending/quarantineを保持し、生pathをログへ出さない。 | 実disk/provider generationとAPP_KEY、削除失敗の担当/保管期限。Prep下の専門worker停止をprivacy retry成功と認定しない。snapshot復元による削除/取消復活はBLOCK |
| 全20 migration/Release20PendingSchemaGuard/CheckReleaseSchemaLedger | 旧94blob保持、20件の内容/順序は既存transition-safety-reviewのM01–M20表を正本に再照合。M01全体precondition、M03→M04→M05/FOLLOWS、M08旧UNIQUE名/意味、M11→M12/13、M14/15→M16/17、M18/19 partial拒否、M20 epochを区別。全20がA、未公開用途でも除去しない。8.0/8.4/旧writer/部分DDL/restore証拠は再利用。 | 名前差strict BLOCK、完全TRIGGER/新名衝突、DDL principal/binlog/ledger/schema実測、全writer停止、fresh restore。down/自動修復なし |
| Docker/PHP/Composer/startup | PHP8.4.27 pin+version check、Composer lockのphp-jwt7.2.1追加、no-dev install、public root、check-only startup、固定cache除去を確認。startup applyは承認SHA照合し一回だけ、失敗retryなし。自動migrationを復活させない。staging bootstrapは別guard。 | actual image/digest/PHP/extensions/vendor provenance、secret-file/APP_KEY、FPM OPcacheは新process/imageで揃える。Preparation=trueのWeb起動からmigrateを自動実行しない。one-off DDLの別承認済CLI profileは専門workerを一切起動しない |
| 専門controller/service/MCP/GitHub/AI/provider/公開share | 4 handler以外は、共有DI/モデル/SQL/storage経路を否定できないため保守的C。Web/API denyとBus/Queue denyだけで全serviceを非到達と断定しない。実認証provider/JWT等の追加はComposerを通じ共有runtimeに存在する。 | 実worker/cron/webhook/consumer停止・再起動不可、全runtime Preparation。外部I/O/所有権/DBの独立レビュー要否は担当者が判断 |
| Actions/production policy/CI | release同repo/main ancestry/merge parent、production reusable MySQL/upgrade/startup、5 required status contextsのSourceを確認。source-only checks/合成envは実DB/owner認可を検証しない。CIから本人GOを生成する箇所はない。 | rulesetの有効性/actor bypass/required checks/対象SHAのowner受入。CI成功とOwner GOを別に保持 |
| scripts/opsの旧collector、Track H/E、配布物 | 本番entrypointからは呼ばれないが、既存collectorには実接続能力があるためDへ隠さずC。候補を同梱しても実用採用/認可にはならない。e8584公開BLOCKは不変。 | §16.3の独立審査/方式選択。Codex self-reviewを独立第三者の完了にしない |

この表は高リスクの**内容と到達経路**に基づくsource reviewであり、Cの全変更をセキュリティ承認した票ではない。今回新たに見つけて再現した公開必須欠陥は§16.1と§16.5。追加の根拠なしに認証/DBの契約を書換えたり、全ファイルの機械ラベルをレビュー済みと表示しない。

候補比較: **A（開始時mainそのまま）**は既知ログ欠陥が残るため推奨しない。**B（最小安全修正後main）**を審査用候補案とする。完全SHA/tree/依存lock/SQL/Reference/20 migration/対象機能/必要CIを#488へ固定し、本番採用や新release refは作らない。**C（15abから限定cherry-pick）**はCanonical Account/Plan/guest/privacy/authorization epoch/private-file/20 migrationの推移的依存を再構築する別候補で、差分数だけで安全とは言えず、未検証のschema分岐と旧writer互換性を増やすため今回は構築しない。Bも実環境・独立審査未達のため未採用。

### 16.3 実DB監査の方式比較・e858 Critical Path

共通DBの現ledger/physical/grants/TRIGGER/DDL-binlog監査は先行公開に必須だが、「e858というツールの完成」自体を唯一の方法と定義しない。一方、**現在採用済みで同じ安全基準を満たす代替の実行経路はない**。

| 方式 | 再利用できるもの | 現状不足・採否 |
| --- | --- | --- |
| e858 B方式 | 固定source/SQL/ref、Full94同一TLS、Q0/Q1/control、caps、DNS/TCP/TLS/TTY/Runtime/予約、owned remote終了観測 | genuine private admission/Live executor未実装。Phase1はQ0/Q1と補助観測のみで、full17の構造監査ではない。将来実装を独立レビューして正式採用する第一候補。現時点BLOCK |
| Track H old Live94 shell collector | 旧94/15abの7 Git blob・login-path/VERIFY_IDENTITY/grantsの歴史的証拠 | 3 session非束縛、期限/remote終了/role/出自/保持の既存8 blocker。現在の114 source/20 pending/全構造の代替不可。実行しない |
| Track H one-client CI executor | 固定7 SQL、単一client、合成TLS/role/remote観測・hardcoded Docker guard | CI/旧94限定、不完全physical証拠でHOLD、実endpoint/実資格情報readerなし。guard解除やSQL交換で使わない |
| 既存認可DBAの独立監査基盤 | 既存の正式承認済み・source-bound・単一対象/TLS/session・固定SQL/caps・最小権限・独立remote観測を満たすなら方式候補になり得る | 現在その基盤/担当/受入証拠は提示されておらずUNKNOWN。ownerが方法/権限/停止責任を選び、独立レビューが同等性を証明する前は採用しない。Console画面/手動SQLコピーだけで代用しない |

最短の推奨は、既存e858部品を保持し、**Bの人的監督を信頼する運用契約と閉じたactivation差分を、接続機能を開く前に独立レビューする**こと。新しい署名Authority/耐rollback ledgerをBへ必須追加しない。別DBA経路が既存資産として実在するなら同等性審査で比較するが、ownerの方式選定をCodexが代行しない。いずれもfull scope・正常/異常remote終了・現在の対象binding/roles・TRIGGER別証拠が必要。実MySQL権限を増やして通さない。

具体的な未実装: `practical.PrivateReview.check` のpending属性照合は真正認可を受理する起点ではない。`LauncherCandidate.run`、`OpenerCandidate.open`、`Adapter.prepare`、`PrivateWire.connect` はいずれも無条件BLOCK。Runtime→Entry→BProceduralAttempt→予約→PreparedChild/ProducerBinding→Phase1Driver→SQL Gate/Bridge→Control/Dataの内部結合はowned fixtureでのみ成立する。認可された単一対象からこのexecutorを起動する**本番用結線はない**。これを「承認待ちだけ」とは呼ばずA TECHNICAL_WORK_REMAININGとする。

| 残項目 | コード/審査/本人/実環境の境界 |
| --- | --- |
| PrivateReviewの真正B起点→固定Runtime/Entry/target/CA/scope/UTC/試行へ束縛 | 新しいlive admissionの設計/コード差分と独立レビュー。bool/JSON/TTY文字列/予約marker/自己署名を本人認証にしない。管理Mac/本人監督の信頼は運用前提として本人判断 |
| Launcher→Opener→Adapter/Wire→既存executor | 4入口の実行有効化に触れるため別の明示承認・別PR・独立レビューが必要。今回コードを開かない。callback/factory等の迂回も不可 |
| closed archive/runtime/secret custody | #816–887の受入を再利用。新入口の差分だけ新たな配布物hash/import closure・管理Mac受入が必要。Python/driver/OSの完全秘密消去・rollback耐性を保証しない |
| Phase1からfull17への範囲 | Q0/Q1でcurrent identity/grants/controlを受入しても、physical/ledger/INDEX-FK/TRIGGER・full17は別scope/GO。固定SQLの置換や接続追加を無断で行わない |
| remote異常/権限/実target | 実Aivenの同principal観測可能性・role/failover/CID再利用・remote消滅/incident経路はLIVE_UNVERIFIED。local close/QUITやCI管理observerを実保証にしない。確認不能ならREMOTE_TERMINATION_UNVERIFIED/HOLD、新GOなしretry禁止 |

今回の源泉審査では、4 BLOCKを維持したまま追加すべき具体的な純粋検査欠陥は見つかっていない。新しいapproval fixtureや候補クラスを増やさない。次の安全な工程は**閉じたままのactivation変更案とB運用前提に対する独立技術審査・方式選定**であり、実Aiven接続の依頼ではない。

### 16.4 Gate matrix / 本人操作の最短単位

以下の「Source/隔離」は確認した範囲だけ。PASSを空欄へ伝播しない。候補案は§16.2/#488の固定Source、e858監査Sourceは旧e858のまま。各工程の担当/権限/副作用/秘密custody/STOP/事故責任は§15.4の10行packetを再利用し、同じMac/CA/旧94検査を再依頼しない。

| Gate | Source | Isolated | Live | Owner | Release Blocking / 不足証拠 |
| --- | --- | --- | --- | --- | --- |
| 全差分/依存/候補完全SHA・tree | 分類/内容審査、C追加審査は残る | exact HEAD必要CI | 実image/Live SHA未確認 | 正式候補未採用 | YES / C高リスクの独立審査要否・正式固定 |
| PHP/Composer/Docker/startup/logging | 既知ログ欠陥修正、check-only/cache制限 | Nginx実HTTP/Docker/既存PHP CI | 配備image/digest/log/secret/cache未受入 | 配備・ログ運用未受入 | YES / edge/FPM等とsource一致 |
| Account/Plan/Context/Guest/CSRF | owner/lock/revision/namespace確認 | 既存本人/他者拒否・再訪・30問 | Session/cookie/key/IdP未受入 | 実機・Session方針未受入 | YES / 旧Session失効と保存復帰の実効条件 |
| 専門UI/API/Job隔離 | default deny/STOP確認、B4 handler限定 | 既存matrix+当該route拒否 | 全runtime/旧writer不明 | UI一時閉鎖は受入済、運用は別 | YES / 実効flag/worker/scheduler/cron/再起動制御 |
| 20 migration/名前/制約/台帳 | 旧94blob+20依存不変 | 8.0/8.4/partial/旧行/restore再利用 | 現ledger/完全physical未観測 | DDL GOなし | YES / INDEX-FK strict名前差、TRIGGER全可視、DDL/binlog |
| e858 admission/Live executor | NOT_IMPLEMENTED / 4BLOCK | 内部のみ検証済み | NOT_RUN | P1/R1–R6/tooling/B2未成立 | YES / 技術差分+独立審査。別監査経路も未採用 |
| Backup/restore/削除・取消保持 | 既存Recovery正本、raw data取得なし | 合成logical restoreのみ | fresh backup/独立restore/容量/鍵/RPO/RTO未測定 | リスク/復旧責任未受入 | YES / 実Full復元とlogical復元を区別、post-snapshot writeは別整合 |
| Writer/consumer/in-flight/cutover | 順序とterminal STOP | #898 owned local15拒否 | 全writer不在/remote未知 | 停止窓/復旧担当未承認 | YES / 旧Web/worker/cron/queue/自動復帰の独立観測 |
| Runtime/OPcache/Session/installed SW | 共通Source、別entrypoint | 合成SQLite/cookie・Chromium/WebKit | 実cache/remember/edge/IdP/SW未受入 | Mac/iPhone差分未受入 | YES / CI画像は実機代替ではない |
| Production rules/release/deploy | policy/5 required contexts | existing fail-closed CI | Render Live/branch/AutoDeploy UNKNOWN | 候補採用/promotion/deploy/公開GOなし | YES / 固定release→production merge→manual deployは別承認 |

今はMac操作なし。後日ownerが行う最小単位は (1) GitHub上で候補案/監査方式/独立レビュー担当と停止・復旧責任を決める（秘密の提出なし） (2) Mac復帰後に今回UI差分だけを合成labで短時間受入、並行して正式tooling/private target/runtime/P1/R1–R6をprivateに受入 (3) fresh Phase1 GOの限定観測（実行入口の別実装/審査完了後だけ） (4) 別full metadata/DBA証拠受入 (5) 別write fence/backup/restore/一回migration/限定runtime切替の各承認 (6) 別本番昇格/manual deploy/限定smoke/公開判断。Phase1/full17/DDL/deployを一つのGOにまとめない。各単位はscope/固定SHA/時間窓を限定し、失敗/unknownはSTOP、同じGOで再実行しない。rawログ/個人データ/実target/CA/password/署名は本人private保管、GitHubはSHA/固定判定/時刻/件数/cleanupと不足だけ。

Critical Pathは「独立審査とlive admission実装の別承認→現在のDB/権限/物理観測→fresh restoreとwriter fence→実runtime/session/device→正式候補採用・配備・公開判断」。Macの利用再開日と実環境条件に依存し、12/1を無条件には保証しない。現在 **BLOCKING_SHARED_REMAINING / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO_HOLD**。

### 16.5 Reset mailの非ログ配送境界（追加の具体的欠陥）

§16.1後の最終内容確認で、Laravelの既定 `mail.default=log` と `failover→smtp/log` も確認した。許可済みforgot-passwordは、Nginxを秘匿してもResetPassword通知のbearer linkをLaravel LogTransportが記録し得る。合成User/NullHandlerのMessageLogged観測で修正前のreset link混入を再現した。実メールや実ログの観測ではない。

local/testing以外ではbroker呼出し前に既存メール設定を検査し、log/array/不明/legacy-global driver、空/循環/過大なcomposite、URL/queryでlogへ差替わる設定を固定の一般向け送信不能メッセージで拒否する。tokenを生成せず通知せず、設定値/parse errorは出力しない。SMTP/sendmail/既存既知providerの構成形だけを許し、これは実配送・送信者/宛先・TLS/Providerログ・実秘密保管の受入ではない。Mailer overrideの解析はLaravelと同じConfigurationUrlParserを再利用し、新しいcredential loaderは作らない。既存login/register/Plan/Sessionとlocal/testingの開発通知は変更しない。本番メール設定の変更や実送信は実施しない。

同じrequest/brokerの修正前後試験、nested failover、URL override、cycle/unknown/empty、既知SMTPのNotification fake、開発環境の互換性を検証する。アカウント存在の有無によらず配送設定不明なら同じ拒否とし、public responseへ設定詳細を出さない。実メール配送の最終受入は別のowner/live gateで、log fallbackを本番で有効にして通さない。

この修正は共有AuthのSource変更なので、A–E分類のB根拠も新Sourceで再審査する。4専門controller・routes/bootstrap/config/resources/依存lockは不変で、共有Authの差分はこの通知前拒否だけ。追加した既存Preparation境界/本人Session回帰が成功した同じapp treeを新しい `review_basis` と公開Git object sealへ束縛し直す。比較はcandidate自身のobjectだけで行い、Squash後に到達不能になり得るPR中間commitの取得へ依存しない。sealは承認tokenや実環境証拠ではない。旧Mac/旧94/CA監査や既存UI本人受入を再要求せず、以前の#898全app tree一致を無条件に継続主張しない。

Source上で解消した既知の公開必須ログ欠陥は§16.1と本節の2件。Render edge/FPM/Laravelの他の実効ログ、過去ログ保持、実メールProvider/TLS、current Session/remember-token policyは引き続き独立受入が必要。実運用を変更しない限りPRODUCTION_NO_GO_HOLD。

## 17. 2026-10-26 Preparation-only UI素材差分

Owner提供 `Canovia_UI_Assets_Codex_20261026.zip` の `CODEX_REQUEST.md` を、本人の「進めて」で今回のUI実装指示として採用。参考画像の数値・架空の機能・ロゴ置換は採用しない。§13の4段階+Unknown、5問×6ページ、同意・本人Context・revision/CSRF・Canonical Planの契約を維持する。

- 質問はnative radioのカードとチェック表示・focus輪郭・390px一列。Unknownも同じ操作面積。進捗は**選択数（Unknownを含む）**であり得点ではない。自動進行・回答の必須化はしない。
- 結果は既存Serviceの割合/未判定のみ。8分野のラベル・割合・棒・装飾画像を組み合わせ、能力/性格/精度の断定なし。
- 関心の訂正と準備希望を別fieldsetに維持。前者は画像カード、後者は任意details内の文字カード。保存済み希望があれば展開する。両方の解除・未指定・旧入力を維持し、Support投票や推定点数へ変換しない。
- 新規Planだけ、静的な3例（調べる/作品/働き方）を任意表示。本人の明示clickで空のtitle/description/categoryへ入力するだけ。入力済み/編集Planを上書きせず、通信・自動保存・AI接続・診断からの自動選択なし。通常の確認・保存が別途必要。JSなしでも既存フォームを使用可能。
- Futureの公開対象・文言・成熟度は既存projectionの3件を維持し、機能カード/票数を追加しない。`first-use-choices` のロゴplaceholderを構想イラストへ置換。`progress-and-records`/`external-activity-sync` は既存素材を再利用。実提供・一般公開へ昇格しない。

素材はZIPの02〜09を各320×320 WebP（計227,816 bytes、透明度維持）として `public/images/preparation/{st,dv,cr,wk,wb,fn,ct,lf}.webp` へ配置。02をFuture向け640×640 WebP（88,852 bytes、contain表示）として再利用。画像は固定寸法/遅延読込/async decode、文字ラベルとnative入力が操作の正本。01宇宙背景・10生成emblemは性能/公式ブランド維持のため未採用。背景はCSS。コンセプトと現行参考PNG・大容量原本はpublicへ含めない。新規生成・構図加工なし、縮小/形式変換のみ。追加アニメーションなし。

差分検証は既存 `PrelaunchInterestSurfaceTest` と `preparation_interest_browser_smoke.mjs` を拡張し、keyboard/native radio、44px以上のtouch面、選択数、任意2群の全解除・点数不変、例文の明示入力/自動保存なし/上書き拒否、JSなしのUnknown保存を確認する。既存の途中保存・復帰・通信失敗・revision競合・再送・CSRF・他者Plan拒否・専門機能404・再ログイン同一IDを継続。既存Chromium/WebKit Artifactに390pxの5画面とdesktop質問/結果/Future/Planフォームを保存する。CIは実Safari/iPhone受入や本番GOではない。

本人Mac差分は新しい固定Sourceの別clean checkout/既存§10ラボで、回答カードのtap/矢印focus、Unknown/進捗、結果と任意2群の解除、例文を使わない/選択/編集、Futureの絵と状態、390px相当を確認する。過去のCA/grants/旧94件/e858 Mac検査は再要求しない。正式なSafari受入報告は未取得として維持（ZIPの所感を完全な受入やcleanup証拠へ昇格しない）。実DB・Render・production/release・e8584公開BLOCK・固定SQL/Reference・PHP最低8.4.27・全安全HOLDは不変。

## 18. 2026-10-26 公開P0セキュリティ差分（UI凍結）

### 18.1 npm監査と到達性

base `2c590c133007a3dd245a8e508183ba16c96995a6` のlockfileを `npm audit --package-lock-only --json` で再現し、Critical2/High2（脆弱パッケージ4、独立原因は3系統）を確認。既存の共有node_modulesの表示版をlockfile実測と混同しない。以下は公開npm/GitHub Advisoryの2026-10-26観測で、将来の未知脆弱性を保証しない。

| Package / 導入元 | Advisory / 影響範囲・修正版 | 到達性 / 対応 |
|---|---|---|
| `shell-quote` 1.9.0（間接）、`concurrently` 9.2.4（直接dev） | [GHSA-pqg4-j6r4-53mv](https://github.com/advisories/GHSA-pqg4-j6r4-53mv) / CVE-2026-102422、shell-quote >=1.8.4 <1.11.0。コメントtoken後の攻撃者制御改行をquoteしshellへ渡すと注入可能。concurrentlyのCriticalは同じ原因の伝播で別RCE2件ではない | `composer dev` のローカル複数command起動。現在の固定commandに攻撃入力の接続は未検出だが、開発端末/CI用途と未知入力境界を理由に放置しない。upstreamのpatch `concurrently` **9.2.5**（shell-quote **1.12.0**）を採用。独自overrideなし |
| `source-map-js` 1.2.1（間接：PostCSS/Tailwind/Vite CSS build） | [GHSA-68fv-2mgg-jv7q](https://github.com/advisories/GHSA-68fv-2mgg-jv7q) / CVE-2026-93749、>=1.0.0 <1.2.2、indexed source-mapのsection offsetでevent-loop DoS。修正1.2.2 | 細工したsource-mapを処理するbuild/開発経路。静的成果物へ脆弱consumer自体を配信するimportは未検出。PRやplugin/依存入力が信頼できないbuildを免責しない。lockを **1.2.2** へpatch更新 |
| Vite 8.2.2（直接dev/build） | 8.2.xは8.2.3以下が対象、**8.2.4** が修正版。[GHSA-587h-7wmj-h5pm](https://github.com/advisories/GHSA-587h-7wmj-h5pm) / CVE-2026-112942（Windows launch-editor存在検査迂回/OS command注入の連鎖）、GHSA-698v-4r3x-3vcv / CVE-2026-112943、GHSA-r825-w8mm-5pxw / CVE-2026-112944（editor引数）、GHSA-9jrq-w75r-8gcw / CVE-2026-112945（custom middleware XSS）、[GHSA-rq7h-c2jc-7f22](https://github.com/advisories/GHSA-rq7h-c2jc-7f22) / CVE-2026-112946（非Windows・外部公開dev serverのfs allow迂回）、GHSA-vfpm-58rq-9qcg / CVE-2026-112947（fs先頭byte露出） | productionはVite dev serverを起動せず、Docker frontend stageでbuild→Nginx/PHPへ `public/build` を配信、`.dockerignore` がnode_modulesを除外。公開JSへeditor/dev server自体を埋め込むimportなし。Mac/LinuxはWindows固有chainの対象外でも、他のdev server問題と共有buildのため修正。**8.2.4** を直接固定し、8.3/9系への変更なし |

`npm audit fix`、無条件major upgrade、audit除外/閾値緩和は行わない。直接2依存を正確版で指定し、間接source-mapのみ既存許容range内の更新。npmが既存optional WASIの不足6依存metadataを補完した差分もレビューし、実行用途/直接依存を追加しない。shared/future既存CIへlockfile変更のpath filterと `npm audit --package-lock-only --audit-level=high` を追加し、network/監査失敗やHigh/Criticalを失敗扱いにする。browser検証用Playwrightを追加する**前**に、採用対象のapplication lockfileを監査する。

### 18.2 Live・警告・採用ゲート

現Renderへのread-only connectorがないため現在Live SHA/image/digest/PHP/worker/設定はUNKNOWN。GitHub production ref15abは現在のRender稼働証明ではない。既存PHP Hotfix仕様・#899/#901の修正根拠を再利用し、旧Sourceのfloating PHPタグ、raw URL/Referer logging、無guardのreset mailをSource上の懸念とする。実PHPパッチ版・実Mailer・侵害有無は未確認。**実HTTP GETでもFree serviceをwakeし旧startup migrationを誘発し得る**ため、既存[Wake gate](PRELAUNCH_WEB_WAKE_PASSIVE_EVIDENCE_2026_10_15.md)を維持し、今回health/login/feedbackを実呼出ししない。DB/Session/運用変更を伴わないproviderのpassive確認と、必要ならowner判断による一時外部アクセス制限/安全なメンテナンス化を優先。未承認の停止やhotfix deployは行わない。

Future PHP12 warningsはdiagnostic Run40252971155で全文を取得。全件 `vendor/vlucas/phpdotenv/src/Store/File/Reader.php:73` の `file_get_contents(.../.env): No such file or directory`。分類は**テスト環境準備不足**で、12種類のFuture動作欠陥や本番の秘密漏えいを示すものではない。`@file_get_contents` の抑制された警告もLaravel test formatterのsummaryへ出ており、初回artisan wrapperはwarning表示付きでもexit成功だった。そこでCI workspaceに秘密・設定なしの空 `.env` をO_EXCL/0600で作成し（既存file/symlinkを上書きしない）、直PHPUnitの既存12テスト/75 assertionsへ `--display-warnings --fail-on-warning` を適用する。アプリ/依存vendor/実環境/エラー表示水準を変更して隠さない。これにより不足するfixture inputを補い、同じテストの警告消滅を最終HEADで確認する。

同じdiagnostic RunのChromiumで、追加済みJS無効journeyの登録ボタンが読込直後のgeometry未安定で12秒のclick待ちに失敗（WebKit/Shared Chromiumは成功）。新しい環境を作らず既存register helperにnetwork idle/フォントready/横幅検査を入れ、POST前の読込完了を待つ。timeout緩和・force click・要求retry・テストskipなし。製品UIコードは変更しない。

新規の実行lock分類ではroot `package.json` / `package-lock.json` に既存manifestの明示ルールがないことを確認したため、共有build契約としてPRELAUNCH_REQUIREDに追加し、既存分類テストで検証する。これはC全件の審査完了/実環境PASSを与えない。2件のNode負例はshellを実行せず、quoteのコメント後改行拒否、indexed mapの巨大offsetをmapping展開前に拒否、正常入力の受付を確認する。

RCはこの依存/警告差分の検証後の完全SHA/treeを**審査用案**として示し、候補正式採用/production/releaseを更新しない。§16の高リスク審査/94+20 migration/Recovery/Cutover/本人データ互換証拠は差分に影響しない部分を再利用。Source treeが動いたため古いB隔離sealを無条件に引き継がず、既存manifestの保守的なC降格を受け入れる。C全件の独立承認を意味しない。

Phase1 private B認可起点、Launcher/Opener/Adapter/PrivateWireのlive結線は未実装で4公開BLOCK維持。§16.3とe858正本のcall graph/審査責任を再利用。Bの管理Mac人的監督はソフトウェア本人認証・耐rollback one-shotではない。外部独立reviewer（作者/本人/Codexと区別）の固定差分/配布物/runtime/対象scope/取消/期限/STOP/remote状態/事故責任の審査が必要で、未選任。DBA代替も同等のSource固定/最小権限/TLS-session/完全metadata/remote終了の独立証拠/安全停止・復旧とowner方式選定が必要、未採用。Phase1・Full17・TRIGGER完全可視性・DDL principal/binlog・backup/restore・Migration・本番配備は別ゲート。署名/P1/R1〜R6/fresh B2/正式採用を生成・代行しない。


## 19. 2026-10-26 Local-first Preparation-only開発方針

オーナーの新委任により、B: Local-firstを開発上の第一候補、C: 保存を保証しないStatic Previewをfallback候補とする。これは実装着手の判断で、外部公開・配備・Cへの正式切替のGOではない。Canonical A案/e858/独立監査/Backup/Writer Cutoverの未達は別トラックとして保持し、旧Web Suspended/Resume・HTTP禁止、実Aiven禁止、production/release不変更を維持する。

配布対象は`preparation-local/`の必要な診断・グラフ・静的Future・任意Plan・端末内保存/再訪・明示Export/Importのみ。旧Study主体の`local-preview`全体を出荷せず、保存/backup primitiveを再利用する。Account/Login/Session/Canonical同期/AI/専門実行/投票/analytics/iOS native新機能は対象外。未来の本人認証・同意付きCanonical Importは未実装の別設計。

Phase1は独立schemaと133 pure PHP対照ケース、静的entrypoint・診断/結果修正/Futureを、保存なしのmemory profileで先に検証する。BのIndexedDB/Plan/Exportは次Phase。現時点のC候補構築はC公開採用ではない。static builderは固定asset allowlistから再現可能zipを作り、Origin未採用/公開未承認を維持。配布物にPHP/.env/管理コード/旧JS/SWを含めず、loopback検証だけを許可する。

Schema1は独立installation UUID/版/revision、診断回答・本人選択/希望、Plan、Import receipt。得点は固定bankから常に再計算し二重保存しない。UUID/checksumを本人認証へ昇格しない。未知schemaを推測変換/削除しない。保存/Import失敗・競合で既存stateを保持し、B保存不能を自動Cへ切り替えない。

Chromium/WebKit CIは合成受入であり、Mac Safari/iPhone Web実機は別ゲート。WKWebViewは初回Web公開の必要条件へ追加しない。独立origin/headers/配布hash、保存消失/Export保管説明と実機受入、別公開GOが揃うまでPRODUCTION_NO_GO_HOLD。段階ごとのPR/SHA/CI/最終artifactはIssue #488へ記録する。

### Phase2 — 端末内Plan・明示Export/Import

Bは`canovia-preparation-v1`の専用DB/storeで保存し、旧local-previewのtransaction/CAS engineをnamespaceだけ変えてbuilderから再利用する。旧DBは読み書きしない。Cは同じschemaのmemory repositoryで、Bのopen失敗から自動切替しない。Plan最大100件/state1MiB、JSON Import2MiB、深さ12/node100,000、unknown schema/重複key/危険key/不正型/衝突は拒否。得点は復元した回答から固定bankで再計算する。

backupは既存canonical/checksum primitiveを再利用しnode capだけ厳しくする。format `canovia.preparation.local`・schema1・bank版・source installation/UUIDを保持するがAccount本人性ではない。checksum不一致/異なる診断/同UUID異内容は上書きせず停止。preview→明示確認→同じraw/hashとrevisionでatomic merge、失敗時は旧state保持。Import receiptと既存record一致でも二重取込を拒否。checksumは改変不能性/暗号化を保証しない。

Planは本人の手動入力/編集だけ。応答前に保存成功を表示せず、失敗時は入力を残す。Exportは非暗号化JSONの明示downloadで、ファイルの実保管・別保管からのImportは本人受入。将来のCanonical Importには別本人認証/所有権/同意/重複排除/削除取消保持が必要で、今回実装しない。

### Phase3/4 — 固定配布物・通信境界・差分受入

公開Sourceのclean checkout/full SHA/treeとGit blob目録からB/Cの固定allowlistを構築する。配布はHTML/CSS/ES modules/画像・`_headers`・manifestだけで、Laravel/PHP/.env/認証/管理コード/旧Service Worker/fixture/秘密は入れない。旧repositoryのCAS primitiveとbackup canonical/checksumだけを再利用し、診断bank/採点/FutureのPHP由来データはCIで固定Sourceと照合する。manifestの自己申告hashではなく、検証checkoutのGit bytes/allowlist/全ファイル集合と照合する。source/tree/flags、余剰・不足・改変、symlink/FIFO/hardlink/巨大ファイルを拒否し、既知の公開assetだけをメモリに読み込む。SHA/hashは真正なSource採用や本人認可ではない。

ZIPはdeterministic/stored-only、regular file・相対パス・サイズ・重複なし・完全なallowlist/bytes一致を検証し、**検証器は展開しない**。`--verify-zip`はclean checkoutのHEADを期待Sourceとする。未知ZIPを解凍して配布ディレクトリに重ねない。Mac labは検証Sourceから新しい私有一時ディレクトリへ再構築するので展開先逸脱の入口を設けない。同一ユーザーの侵害やGit/OS全体の不正まで保証するものではない。

loopback labは127.0.0.1限定、既知Host/一致Origin・GET/HEADだけ、未知route/query/encoding traversalを拒否。Cookie/Authorization付き要求、cross-site/opaque Originも拒否し、CORS/Set-Cookie/要求URLログを出さない。応答は全て固定errorと同じsecurity headers。CSPはscript/style/img selfのみ、connect/worker/object/form/base/frameは拒否。Fetch/XHR/WebSocket/Beaconのブラウザ実効負例を検証する。ページは既存SW controllerを検出すると保存開始前にSTOPし、他SWを解除しない。公開Originはnull・未採用、実HostingのHTTPS/CSP等の適用は未検証であり、このlabの証拠を本番へ昇格しない。

Cookieはportで分離されない。labでも専用Safari profile（必要なら別OS user）を使い、既存Canovia/開発用Cookieがあるprofileで起動しない。旧DB namespaceを触らないことを合成で検証するが、最終公開は旧Canonicalと別の独立originを明示採用する必要がある。同じ端末内保存はsame scheme/host/port/profileに束縛され、別port/localhostと127.0.0.1の切替では復帰しない。SWを配布・登録せず、ネット不通での再起動や恒久保存を宣伝しない。配信事業者のIP等通常アクセス情報は残り得る。

追加差分受入は、B/C×Chromium/WebKitの既存診断→結果→Future→Plan→明示Export/Importを拡張し、CSP/HTTP、固定配布/ZIP/source、旧namespace保持、未知保存版の非破壊STOP、SW拒否、保存不能BのSTOP/CのIDB非依存、Plan保存Quota・別タブ競合で入力/旧記録保持を確認する。133 PHP参照ケースを各engineでも照合する。Import後のメモ再編集試験で、textareaの保存内容がlabelの名前に混入する不備を再現し、for/idの明示ラベルへ最小修正した（見た目や保存形式は変更しない）。390pxの診断/グラフ/Future/Plan/データ画面は合成Screenshot Artifact（7日）として保存する。これはMac Safari受入・Hosting採用・外部公開GOではない。

#### 管理Mac Safariの最小無接続受入票

検証SourceはIssue #488に記録する**最終PR HEAD全40桁**（moving mainやSquash後の別SHAではない）、期待ZIP SHA256とCI Run IDを使用する。既存AP checkout/DB/Herd/Homebrew/global依存は変更しない。この手順はPython3.9以上・Gitだけを使い、Composer/PHP/Nodeの再installや実target/CA/パスワードは不要。

```bash
# Issue #488の検証HEADを控え、独立した一時checkoutだけで実施
PREP_LAB_ROOT="$(mktemp -d -t canovia-preparation)"
git clone --no-checkout https://github.com/1kz-ma1/Canovia-web.git "$PREP_LAB_ROOT/source"
cd "$PREP_LAB_ROOT/source"
git checkout --detach <検証HEAD全40桁>
python3 --version
git status --porcelain
python3 -B scripts/ci/preparation_static_build.py --profile B --out "$PREP_LAB_ROOT/B"
python3 -B scripts/ci/preparation_static_build.py --verify-zip "$PREP_LAB_ROOT/B.zip"
shasum -a 256 "$PREP_LAB_ROOT/B.zip"
# 期待hash一致、working tree cleanを確認。port使用中なら停止し、他プロセスをkillしない
lsof -nP -iTCP:18798 -sTCP:LISTEN
python3 -B scripts/ci/preparation_static_serve.py --directory "$PREP_LAB_ROOT/B" --port 18798
```

1. 専用の通常Safari profileで `http://127.0.0.1:18798/` を開く。合成内容のみ。30問の一部/Unknown保存→reload、8領域グラフと見直し、Future、任意Plan作成/編集を確認する。
2. Safari終了/再起動後、**同じorigin/profile/port**で同じPlanへ復帰。JSON ExportがDownloads等に実在することを確認し、安全な別保管先へコピーする。初回ファイルは暗号化されない。
3. 別の専用profileで同じlabへアクセス→Import previewでは未保存→確認後に同じPlan。二重/破損Importは拒否し既存記録を保持。通常profileへ戻ると元記録も保持する。
4. 合成用のprofile/当該lab originだけのデータを意図的に消し、消失を確認。Exportなしでは復旧できない。保存したExportの明示Importで戻ることを確認する。既存AP/Canonical profileや他originのデータは消さない。
5. 390px相当の幅/keyboardで操作し、失敗・未保存の表示を確認。local serverはCtrl-Cで終了し、Exportは本人の保存方針に従って保管する。使わないlab削除もこの一時ディレクトリだけとし、既存環境やExportを一括削除しない。

STOP: SHA/hash/clean tree不一致、既存port使用、旧Cookie/SW拒否、保存/Import/復帰/表示不良、未知版・Quota・通信要求があれば中止。実secret/接続先/生Export/本人入力のScreenshotはGitHub/ChatGPTへ提出せず、固定Source/判定/件数/engine版と不具合分類だけ記録する。Macはこれまでのe858受入を再要求せず、今回の独立static配布/保存/UI差分だけ。iOS Native/WKWebViewは別ゲート。

#### B/Cの公開審査条件（未達を成功扱いにしない）

Bはコード/隔離CIの成功に加え、独立したHTTPS origin・実Hostingのheader/path/asset配置・Source/ZIP hashの採用、Mac Safariの保存/再訪/Export保管/消去受入、保存限界/privacy説明と運用責任、別の本人公開GOが必要。Hosting固有の`_headers`解釈は未採用で、ファイルを置くだけで適用済みとは扱わない。新たな費用や公開配備は別承認。

Bの保存耐久性/対応Safari条件を満たせない場合は原因を記録し、別C成果物へ**本人判断で**縮小できる。CはIDBなし、同一画面内の診断/Plan/明示Export・Importのみ、reload/終了で消えることを明示し、保持を約束しない。CもHosting/Origin/Safari/公開GOは別に必要で、B失敗を暗黙のCへ変換しない。

Canonical Account/Plan/DB・Session・旧Webは利用せず、既存e858監査/復元/Cutover/4BLOCKを維持する。静的成果物が完成してもCanonical統合や将来の本人同意付きImportは未完了。**PRODUCTION_NO_GO_HOLD**。

### Hosting採用前審査・運用（2026-10-26、§19追補）

**Cloudflare Pagesのstatic Direct Uploadを第一推奨、Netlifyを比較候補**とする。推奨は正式採用/プロジェクト作成/Origin確定/公開GOではない。登録・設定・配備・請求変更は実行しない。B/Cとも`ADOPTED_ORIGIN=null`/`release_approved=false`、Canonical/e858と独立した公開HOLDを維持する。

#### 公式根拠と取得限界

確認日: **2026-10-26 JST**。両公式サイトの直接取得は環境proxy403で不可。Cloudflare公式文書の公開SourceとNetlify公式運営の公開技術referenceを固定commitで取得した。後者は元のdocs.netlify.com料金/契約の全文ではないので、取得できない細則/価格を推測せずUNKNOWNとする。公開資料中のdeployコマンドは資料として読んだだけで実行しない。これは外部skillのdeploy指示を採用する工程ではない。

| ID | 公式資料URL（参照箇所） | 取得できた固定根拠 |
|---|---|---|
| CF-H | https://developers.cloudflare.com/pages/configuration/headers/ | [公式Source](https://github.com/cloudflare/cloudflare-docs/blob/1342d903794faa50353ef9a53978aa375798fc34/src/content/docs/pages/configuration/headers.mdx): `_headers`はstatic用、Functionsには不適用、100rules/1行2000文字、複数一致は合成、`! `で削除可能 |
| CF-S | https://developers.cloudflare.com/pages/configuration/serving-pages/ | [公式Source](https://github.com/cloudflare/cloudflare-docs/blob/1342d903794faa50353ef9a53978aa375798fc34/src/content/docs/pages/configuration/serving-pages.mdx): root404なしはSPA fallback、HTML拡張子redirect、既定CORS `*`、ETag/304/圧縮/cache残留 |
| CF-U | https://developers.cloudflare.com/pages/get-started/direct-upload/ | [公式Source](https://github.com/cloudflare/cloudflare-docs/blob/1342d903794faa50353ef9a53978aa375798fc34/src/content/docs/pages/get-started/direct-upload.mdx): 既成assetをfolder/ZIPで手動upload可、Direct Upload→Git方式は同projectで切替不可、drag/drop1000files・25MiB/file、`_worker.js`も配布可能なので除外必須 |
| CF-P | https://developers.cloudflare.com/pages/configuration/preview-deployments/ | [公式Source](https://github.com/cloudflare/cloudflare-docs/blob/1342d903794faa50353ef9a53978aa375798fc34/src/content/docs/pages/configuration/preview-deployments.mdx): Previewは既定公開、Accessは別設定、noindexは認可ではない |
| CF-G | https://developers.cloudflare.com/pages/configuration/git-integration/ | [公式Source](https://github.com/cloudflare/cloudflare-docs/blob/1342d903794faa50353ef9a53978aa375798fc34/src/content/docs/pages/configuration/git-integration/index.mdx): Git連携はpush→build/deploy。main統合権限を公開権限に転用しない |
| CF-R | https://developers.cloudflare.com/pages/configuration/rollbacks/ | [公式Source](https://github.com/cloudflare/cloudflare-docs/blob/1342d903794faa50353ef9a53978aa375798fc34/src/content/docs/pages/configuration/rollbacks.mdx): 成功したproduction deploymentへ戻せる。previewはrollback対象外 |
| CF-D | https://developers.cloudflare.com/pages/configuration/custom-domains/ | [公式Source](https://github.com/cloudflare/cloudflare-docs/blob/1342d903794faa50353ef9a53978aa375798fc34/src/content/docs/pages/configuration/custom-domains.mdx): custom domainのCAAが証明書発行を妨げ得る。provider host/custom domainの採用・DNS/証明書は別工程 |
| CF-L | https://developers.cloudflare.com/pages/platform/limits/ | [公式Source](https://github.com/cloudflare/cloudflare-docs/blob/1342d903794faa50353ef9a53978aa375798fc34/src/content/docs/pages/platform/limits.mdx): Free500build/月・20000files・25MiB/file・100custom domains/project・account100project上限（この案で追加契約しない） |
| CF-$ | https://developers.cloudflare.com/pages/functions/pricing/ | [公式Source](https://github.com/cloudflare/cloudflare-docs/blob/1342d903794faa50353ef9a53978aa375798fc34/src/content/docs/pages/functions/pricing.mdx): static requestsはFree/Paidともfree/unlimited。Functions/Workersは別quota/課金 |
| NL-C | https://docs.netlify.com/manage/routing/headers/ / https://docs.netlify.com/manage/routing/redirects/overview/ | [Netlify公式技術reference](https://github.com/netlify/context-and-tools/blob/b9f53856d45aa2e8cf24721fd8a82e222deb323d/skills/netlify-config/SKILL.md): publish dirの`_headers`、storeのstaticだけ、headersはcontext別ではなくglobal、function/proxyは別。SPA200 rewriteは明示規則で、今回禁止 |
| NL-D | https://docs.netlify.com/deploy/create-deploys/ / https://docs.netlify.com/deploy/manage-deploys/ | [Netlify公式技術reference](https://github.com/netlify/context-and-tools/blob/b9f53856d45aa2e8cf24721fd8a82e222deb323d/skills/netlify-deploy/SKILL.md): manual artifact/draft/Git-CD、auto publish lockとbuild停止は異なる、retryは古い固定Sourceでなく移動したHEADになり得る。失敗deployは元公開を置換しない |
| NL-P | https://docs.netlify.com/manage/security/secure-access-to-sites/project-visibility/ | [Netlify公式技術reference](https://github.com/netlify/context-and-tools/blob/b9f53856d45aa2e8cf24721fd8a82e222deb323d/skills/netlify-access-control/SKILL.md): Public/Private/PasswordとPreview保護範囲はplan依存。Free/Personalで共通passwordは不可、privateはowner限定と記載。自動previewをprivateと推定しない |
| NL-$ | https://www.netlify.com/pricing/ / https://docs.netlify.com/manage/accounts-and-billing/billing/billing-for-credit-based-plans/ | 直接本文取得不可。公式referenceでcredit-based Free/Personal/Proとauto-recharge/credit packsの存在は確認。**現在の月額・含まれるcredit数・静的帯域/requests/deploy単価・free枠到達時の停止/超過課金細則はUNKNOWN**。旧料金を現行として転記しない |

資料中の数値は公開プラン仕様の観測で、契約・SLAや将来の料金保証ではない。USD表記/地域/税/為替が必要な有料機能・独自domain登録更新・既存サービス保管費は未見積で0円扱いしない。個人予算は記録しない。

#### 採用前比較（未配備・全Live項目は未受入）

| 評価項目 | Cloudflare Pages | Netlify | 残る公開前受入 |
|---|---|---|---|
| HTML/CSS/modules/画像 | CF-U/S、Laravel/build serverなしの既成static可 | NL-C/D、publish dirの既成static可 | MIME・圧縮・module import・実browser/header |
| `_headers`/CSP/Referrer/Permissions | CF-H、既定headerをoverride/remove、Functions対象外 | NL-C、static対象、global/context非分離・server管理headerは制限 | 今回は両対応の単一`/*`・固定header subsetだけ。provider全構文parserの実装ではない |
| HTTPS/TLS/独立Origin | provider host/custom domain（CF-D）、実証明書/redirect未確認 | provider host/custom domain、現行TLS設定細則UNKNOWN | 独立HTTPS origin採用・cert/redirect/旧Domain Cookie/SW不在を別確認。sibling subdomainだけでは親Domain Cookieを分離できない |
| 未知path/404 | CF-S、root404がないとindexへfallback | NL-C、SPA rewriteは明示。native404/Pretty URLs等の現環境挙動は要実測 | script/formなしroot404を追加。`/api`/不存在module/encoded pathがindex200にならず、404+安全headerになること |
| query/不正method/Host | Python labの拒否契約は移植されない。query付きindex200等を想定 | 同左。CDNの正規化/response codeはUNKNOWN | query付き200でもappが保存前STOPする合成試験。実method/Origin/Hostの正規化を確認。厳密なPython同等拒否が必須なら別設計/承認、動的機能を黙って追加しない |
| CORS/Cookie/Authorization | CF-S、既定ACAO`*`はlabと異なる。Authorizationでcache挙動も変わる | NL-C、server管理header/親domain cookieに注意。既定CORS細則UNKNOWN | 配布物は全てpublic staticで個人dataなし。CORS`*`は本人認証ではなくCSP connect-none/無backendとは別。Set-Cookie/credentials許可なしを実測 |
| Preview公開範囲 | CF-P、URLは既定公開/noindex。Access採用は別 | NL-P、planとvisibility/preview scope依存 | preview作成も公開操作。今回作らない。本番hostだけ受入してalias/古いdeploymentを放置しない |
| GitHub自動公開 | CF-G、pushやPRで自動配布 | NL-D、Git-CDでpush/PRに連動。manual配布も次pushで置換され得る | 初回はGit未連携のmanual artifactを推奨。main squash≠deploy。build hooks/token/権限を勝手に作らない |
| 固定artifact手動配備 | CF-U、ZIP/folderで可能。出力rootだけ、repo root不可 | NL-D、manual/draft可、publish dir/context/設定を限定 | fixed full SHA/tree/profile/ZIP、Functions/worker/redirect/plugin混入なし、実配布file照合。実uploadは別GO |
| rollback/緊急停止 | CF-R、過去productionへ戻せるが古いpreview/cache/開いたtabは別 | NL-D、失敗buildは旧公開維持。bad publishedの停止/rollback/権限は現行UI/APIで別確認 | auto build/publish停止だけでは配信は止まらない。inert停止版/アクセス遮断等の具体策を採用後に承認。schema非互換なら古いappへ単純rollbackしない |
| 無料枠/想定外費用 | CF-$ static free/unlimited、CF-L/Uのquota。Workers/Functions/R2等は除外 | NL-$ credit枠/料金/停止条件UNKNOWN、auto-rechargeや有料保護を0円扱いしない | accountの実plan/limits/請求通知を本人が採用前確認。新契約/課金変更なし |
| 静的だけで成立するか | このB/C scopeはstaticだけ。DB/認証/Worker不要 | 同scopeはstaticだけ。Functions/Identity/Forms等不要 | 厳密HTTP拒否やprivate previewを追加要件にする場合、別安全/費用判断。今回の候補へ追加しない |

第一推奨の理由は、既存static asset/`_headers`と直接uploadを使い、DB/Functionsなしで公開scopeを満たし、static料金の公式根拠を取得できたこと。自動Git-CD/関数/analyticsは採用しない。CFのSPA既定・CORS/cache・preview公開は明確な残留リスクで、**推奨をPASS/正式採用に変換しない**。Netlifyは代替候補だが、現行料金・保護plan条件とrouting/TLS/緊急停止の細則確認が先行条件。

#### オフライン検査の差分と保証限界

#908のbuilder/Source/tree/blob/ZIP検証を拡張し、新Frameworkは作らない。(1) root `404.html`を固定allowlist/manifestへ追加し、Cloudflare既定SPA fallbackへの具体的不足を修正 (2) ZIPの期待B/C profileを外から指定して一致確認（旧CLIの`--profile`がverifyでは無視されていた不備を修正）(3) 単一wildcard/一意header/行長/固定CSP/no-store/無script404のcommon subset検査を同じbuilderへ追加する。

```bash
# clean検証Sourceで実行。deployなし。B/Cを明示、戻り値のrelease_approvedはfalseのまま
python3 -B scripts/ci/preparation_static_build.py --verify-zip <B候補.zip> --profile B
python3 -B scripts/ci/preparation_static_build.py --verify-zip <C候補.zip> --profile C
```

extra PHP/.env/auth/functions/worker/redirect/test/秘密/不要assetは完全allowlist/bytes差異として拒否。これは承認Source自体に秘密がないことの汎用DLP証明ではなく、Source/依存/公開asset内容のレビューを置換しない。未知provider設定を追加して正当化せず、Functions/`_worker.js`/`_redirects`/`netlify.toml`/デプロイスクリプトを配布しない。`ADOPTED_ORIGIN=null`/`release_approved=false`維持、Preflight出力のPASSはoffline source/共通subsetだけで、HostingはLIVE_VERIFICATION_REQUIRED。

browser差分試験はCDNを実装するものではない。route interceptionで**localhostのqueryにindex200が返る合成応答**を作り、appがIDB開始前にSTOPすることを確認する。`.invalid`のtest-only仮想HTTPS/preview originではproduct appが未採用としてSTOPし、別の合成probe DBでoriginごとに保存が分離されることを確認する。外部DNS/TLS/HTTPは発生させず、このtest originは配布物に含めない。実HTTPSの証明書/Hosting/CORS/cache/404/Headers受入ではない。既存CSP transport拒否/秘密非送信/Service Worker非包含/旧namespace保持/unknown schema非破壊を再利用。

今回のproduction module/UI/storage schemaは#908から不変更で、本人が進めている#908のSafari診断・保存・Import受入を最初からやり直す必要はない。Source/ZIPは配布404/検査差分により新hashとなり、正式採用時に今回の固定Source/配布物とUI/storage bytesの一致・新404だけを照合する。Hosting未受入分は別に残す。

#### 公開後の最小Operations（採用前の設計、今回実行なし）

| 事象/担当 | 実施前条件・手順 | STOP/証拠/責任 |
|---|---|---|
| 新版準備・owner/operator | clean fixed Source/tree・明示B/C・再現ZIP・配布manifest・関連CI・旧schema/Export互換を確認。既存origin/profileを継続。別Origin移転を気軽なrenameにしない | SHA/tree/hash/差分/CI/承認時刻だけ公開記録。実origin採用/配備/公開は別owner GO |
| 手動更新・owner/operator | 採用したproviderのupload先・独立origin・全alias/preview保護・auto build/publishなし・HTTPS/headersを固定確認。固定artifactだけ、repo全体をuploadしない | 失敗uploadは実配布状態を照合し旧版維持を確認。推測で公開成功/rollback成功にしない |
| 更新失敗/rollback | 同一originで既知の安全なSourceに戻す候補を保管。DB schema/backup版が同じか確認し、unknown schemaは非破壊STOP。必要なら先にinert停止版へ | Web配布rollbackはIndexedDBを戻さない。古いappを開く/データ消去/`migrate:fresh`/強制downgradeは禁止。provider操作は別GO |
| セキュリティ事故 | 自動公開経路を止めるだけでなく、全公開host/aliasesを対象にinert停止版またはproviderアクセス遮断を別承認で実施する案。侵害Source/hashを保存しfixed repairを審査 | cache/preview/open tab/取得済みassetは即回収できない。緊急停止で端末内dataを削除・回復できると約束しない。ownerが判断/連絡責任を持つ。今回停止機能やdeploy tokenを作らない |
| cache/asset残留 | `Cache-Control:no-store`を期待し実Header確認、custom CDN cache ruleを追加しない。old moduleとnew indexの混在・304・stale tabを受入。必要なpurgeは別承認 | CF-Sでは旧assetが最大1週程度残る場合あり。no-storeは既に開いたtab/ダウンロード物の失効ではない |
| schema/Export更新 | schema1/bank/scoring版を明示。unknown版/衝突/古いtabで旧data保持。変更が必要な時だけ別設計/互換fixture/明示preview確認 | 新版fileを古いappへImport強行せず、本人保管Exportを保持。自動Canonical import/クラウド同期を約束しない |
| Safari保存消失・support | same origin/profile/private-mode/消去等の条件を聞き、本人保管Exportからの明示Importだけを案内 | 管理者は端末内dataを読めず、Exportなしの消去/端末変更を復旧できない。raw Export/回答/Plan/Cookie/storage dump/署名/端末個人情報は提出させない |
| 不具合報告 | version/source短縮SHA・browser版・再現手順・固定error分類・合成再現だけ。必要Screenshotは本人情報を除去 | IssueへURL query/実Plan/診断内容を貼らない。ownerが公開ticketを確認し、漏えい時は削除依頼と本人対応を判断 |
| analyticsなし監視 | 別承認後の公開static endpoint/response/hash/headerの軽量外形確認、provider稼働/失敗build/limits/請求通知、匿名化した手動ticketを最低限とする | ユーザー行動/保存成功/データの有無は収集しない。analytics/Beacon/tracker/外部monitor契約は今回追加なし。現在WebへのHTTP監視は行わない |
| 費用/quota・owner | provider公式planを採用時と運用時に再確認。static以外の製品・独自domain・有料保護・auto rechargeは別費用として扱う | 無料quota到達/plan変更通知/費用不明はSTOPし追加公開や課金を自動継続しない。具体的個人予算額は公開記録しない |

#### UX非実装レビュー（#908合成画像/UI sourceの再利用）

#908の20枚Artifactの保存記録と、同じUI/storage blobに対応する既存390px合成画像・UIコードを確認する。環境proxyでArtifactの再取得は不可のため、実Artifactを再downloadして全画像を検査したとは記録しない。実Safariは本人受入中で未判定。

- **新たな確定P0/P1はなし**（合成/Sourceで確認した範囲）。保存不能/Quota/unknown版は明示STOP、未保存入力は残す。診断は任意、Unknown/未回答を区別、Planは手動、Cのreload消失とExportなしの復旧不能を説明、Importはpreview/確認あり。これを実Safariの操作性保証に昇格しない。
- **P2**: 既存合成画像ではradioへの自動scroll時にsticky進捗帯が最初の設問の一部と重なる。実Safariの再現性/影響は未確認で、表示位置とfocus/読み上げ案内を後続確認する。長い結果画面での次の導線、保存/Exportの初回理解を将来改善候補として記録。今回はUI変更や自動保存/新機能を追加しない。
- 配布上の404不足とB/C取り違えは今回の安全修正として扱い、見た目の改善や新UX開発へ拡大しない。Safariで保存/Import不能等が判明すればP0/P1へ再分類し、Bの公開をHOLDする。

#### 残る最小判断・公開前のLive受入

本人の次の判断は**Cloudflare Pagesのstatic/manual方式を採用候補として選ぶか**。同時にdomain/全alias/previewの公開範囲・Git未連携・費用/通知/停止責任を決める（秘密の提出不要）。選定とプロジェクト作成/実Origin採用/配備/公開の各GOは分離する。未承認の現在は実際のOriginをSourceに設定しない。

選定後の別承認工程: (1) originとprovider構成/費用採用 (2) fixed Source/artifact/CI＋継続中Safari差分受入の照合 (3) 最小配備・実HTTPS/全header/CORS/Cookie/Host/query/method/404/古いmodule/preview/停止方法確認 (4) 本人による公開可否。初回配備そのものが公開を伴い得るため、previewだから無承認で行えるとは解釈しない。機能が隠れたままかを含め、app未採用OriginのSTOPを解除する変更は別承認/レビューへ残す。

12月1日のCritical Pathは、残る配布整合性→Hosting/Origin/公開範囲/費用の選定→本人Safari受入→別承認の配備と実効security/運用受入→公開GO。日付を理由に未検証をPASSにしない。Cへ縮小してもHosting/Origin/公開GOは残る。Canonical/e858/復元/旧WriterのHOLDは継続し、今回Local-first経路の成功で解除しない。**PRODUCTION_NO_GO_HOLD**。

### Render継続方針の技術審査（2026-10-27 JST、採用・配備なし）

本追補は上記2026-10-26の「Cloudflare第一推奨」を**Render専用Static Site第一推奨へ更新**する。正式採用ではない。最新mainは `c72523469f2f866d8a2c0d9d2cd9d5935b544e54`、PR #906–#909はいずれもMERGEDとGitHubで再確認。Issue #488の直近証拠・未完了ゲートも照合した。Cloudflare用コード/検証は保持。今回は仕様のみを変更し、実Origin/null、app/UI/schema、配布コードは変更しない。

#### 現状証拠と3構成案

Render MCP `list_workspaces` / `list_services(includePreviews=true)` / 対象2件の `get_service` を読み取り。`My Workspace` = `tea-d4vb3f6mcj7s73djkrqg` とownerId一致。

- Canovia (`srv-dagfn2id0e5s73cac7ng`): Docker `./Dockerfile`、Web/free、Suspended(user)、Auto Deploy no/off、main、Preview off。
- Canovia-Welcome (`srv-dahn5k142hec739l0fmg`): Static Site、root `static-welcome`、Build Command空、Publish `.`、Auto Deploy no/off、main、Preview off。Render subdomain enabled。
- `canovia-mcp-staging` は対象外。workspaceの実plan・残帯域/分数・支払方法・spend limit・既存Header/Rewrite/custom domain一覧は今回の応答に含まれず **UNKNOWN**。`buildPlan=starter` はworkspace料金planの証明ではない。

ソースの `static-welcome/config.js` は旧WebをappUrlに指定。`app.js` は初期表示で `wake()`→`/health?from=welcome&t=...` をGET/CORS/credentials omit/no-storeで要求し、7秒timeout、約2.2秒/遅延後6.5秒間隔で再試行する。ready headerでボタン有効化、再訪者は450ms後に旧Webへ遷移。強制open、future/supportの `/feedback` 導線も旧Web向け。**Suspendedを前提にHTMLを実ブラウザで開かず、旧Web/healthへのHTTPは実施しない**。これは最新mainソースの証拠で、既存配信bytes一致や実利用者数を測定したものではない。

| 観点 | A: Welcome再利用 | B: 専用Static Site（第一推奨） | C: Laravel Web再利用 |
|---|---|---|---|
| 既存影響 | 既存入口/再訪者の体験を置換。旧health・遷移の除去と別受入が必要 | Welcome・旧Webを変更せず独立運用。入口リンク変更も将来別承認 | Suspended解除が必要で今回禁止。旧サービス全体の安全審査へ戻る |
| DB/Laravel/Session | 完全な静的配布へ置換すれば分離可能 | PHP/DB/認証/env groupを持たず最も明確に分離 | 現Docker/Session/DB設定からの分離証拠が必要。画面非表示だけでは不十分 |
| 手動/固定SHA | root/build/publish変更後は可能、既存設定を上書き | Auto Deploy OFF、Preview OFF、特定full SHAを手動指定する設計 | fixed SHA自体は可能だがWeb再起動と旧安全ゲートが付随 |
| Origin/IndexedDB | Welcome Originを継承できるが既存Cookie/SW/storage履歴は未確認 | 新しい独立HTTPS Originを一度採用し更新時維持。aliasは別storage | 旧Canonicalと同Originを期待しない。既存Cookie/Sessionとの衝突リスク |
| headers/404/cache | Static向け別設定が必要。現在設定はUNKNOWN | 同左。未知pathをindexへrewriteしない | Docker/server/middleware/CDNの全経路審査が必要 |
| 公開範囲 | Publish `.` のままrepo rootへ変えるのは禁止 | 固定allowlist出力だけ、repo/public全体は公開しない | PHP/env/管理asset/旧routeへの露出面が広い |
| 停止/rollback | Welcomeも停止対象になる | 先行版だけ停止可能、workspace障害/枠共有は残る | 正式版/旧サービスとの停止責任が混在 |
| 月額/上限 | Staticのcompute追加費なし、workspace帯域/分数消費 | 同左、Site分離で無料枠が増えるわけではない | 現plan free。継続稼働/必要有料planの実見積はUNKNOWN |
| 将来Laravel | 同Providerでも自動移行はない | Laravelを独立して安全化し、後日明示接続。AWS移転も可能 | 見かけの連続性のためにCanonicalの未解消リスクを持ち込む |

**Aは技術的に再利用可能だが現状のまま不可、今回は推奨しない。Bを条件付き第一推奨、Cを先行公開経路から除外**。BでもWelcomeの既存利用者問題は解消しない。旧入口の利用数はUNKNOWNであり、Welcomeのhealth廃止/静的案内/リンク変更を行うなら独立した限定承認とレビューが必要。今回Welcomeのsource/配信/設定は変更しない。

#### 公式仕様・取得限界

2026-10-27 JSTに公式本文をWeb取得。Renderの `.md` はツールがtext/markdown非対応のためHTMLを読んだ。従来のproxy取得不能とは別の取得経路であり、実サービスへアクセスした証拠ではない。

| 公式本文 | 今回確認した要点 |
|---|---|
| [Render Static Sites](https://render.com/docs/static-sites) | Gitからbuild、独立URL/TLS、static配信、cache invalidation、依存自動install。作成時に初回deployが走るため作成も今回禁止 |
| [Deploys](https://render.com/docs/deploys) | 特定SHA指定可。Dashboard指定はAuto Deploy無効化、CLI/APIは無効化しないため明示OFF照合が必要 |
| [Static headers](https://render.com/docs/static-site-headers) / [Routes](https://render.com/docs/redirects-rewrites) | Dashboardでpath/name/value、`/*`。既存assetはrewriteより優先。`_headers`のCloudflare形式を適用済みとは扱わない |
| [Rollback](https://render.com/docs/rollbacks) | 旧build artifact利用、保持数はplan依存。**static headers/routes/custom domainsは現設定のまま** |
| [Bandwidth](https://render.com/docs/outbound-bandwidth) / [Build pipeline](https://render.com/docs/build-pipeline) | workspace共有枠・超過課金とpipeline spend limit。下記費用表参照 |
| [Native runtime](https://render.com/docs/native-runtimes) | 言語別build toolchainの説明はあるが、このStatic SiteでのPython/Git版・checkout形態は実測していない |
| [Cloudflare料金](https://developers.cloudflare.com/pages/functions/pricing/) / [制限](https://developers.cloudflare.com/pages/platform/limits/) | Functionsを呼ばないstatic requestはfree/unlimited、Free 500build/月、20分/build、20000files、25MiB/file |
| [Amplify manual](https://docs.aws.amazon.com/amplify/latest/userguide/manual-deploys.html) / [headers](https://docs.aws.amazon.com/amplify/latest/userguide/custom-headers.html) / [料金](https://aws.amazon.com/amplify/pricing/) | GitなしZIP配備、headersはconsole/customHttp.yml。無料適用はaccount条件に依存 |
| [CloudFront料金](https://aws.amazon.com/cloudfront/pricing/) / [S3料金](https://aws.amazon.com/s3/pricing/) / [OAC](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/private-content-restricting-access-to-s3.html) | flat-rateと従量を区別。private S3 REST origin+OAC案。website endpointはOAC非対応 |
| [AWS Budgets](https://docs.aws.amazon.com/cost-management/latest/userguide/budgets-managing-costs.html) | 通知・制御補助、更新遅延あり。通知を即時の請求hard capとみなさない |

#### Render向け配布契約（オフライン設計、実設定なし）

| 項目 | 固定する設計・確認方法 | 未完了/STOP |
|---|---|---|
| Root/Build/Publish | rootはrepo全体のcheckout。builderは複数dirのGit blobを読むため `static-welcome`/`preparation-local` rootでは不可。BuildはPython3.9+とGit、下記順序。Publishは専用 `.render-static-B` のみ | `.`/`public`/repo全体のPublishは拒否。新Site構築は別GO |
| Source SHA | 手動指定のfull40 SHA、checkout HEAD、manifest source_sha/tree/inputs、CI対象HEADを照合。moving main/latest deployを使用しない | squash後は同treeでもmanifest SHA/ZIP hashが変わる。旧PR ZIPのhashを流用しない |
| Build tools | Python/Git版・regular blob・clean checkoutを確認。`SKIP_INSTALL_DEPS=true` を候補設定としComposer/npm/PHPやネット依存installを不要にする。env group/DB secretsを接続しない | 実RenderのGit metadata/clean状態/Python版はUNKNOWN。欠落/汚れなら失敗終了、fallback build禁止 |
| allowlist/manifest/ZIP | 既存builderの25files、B/C明示、source-bound完全byte/集合照合。ZIP展開せず検証後に同builder出力をPublishへコピー | 余剰 `.env`/PHP/旧JS/SW/worker/Functions/fixture、未知profile、hash差異でSTOP |
| Security Headers | 各 `HEADERS` 要素を `{path:'/*',name,value}` の一意な設定へ転記。CSP・Referrer・nosniff・Permissions・no-storeを既存値と完全一致照合。`_headers`は残すがRender設定の代替にしない | 現設定とエラー/redirect/aliasを含む実効適用はUNKNOWN。meta CSPだけで代用しない |
| 404/Rewrite | routesは空を期待。hash routingなので `/* → /index.html` 不要。root404は配布維持 | 未知pathの実status/body、custom404採用有無は未検証。SPA fallback/外部redirectはSTOP |
| Query/Cookie/CORS | queryはappが保存前STOP。Cookie/Authorization/非GET拒否はloopback独自の機能で、Static CDNに同じ拒否を期待しない。appはAPI/認証/Cookieを使わない | CDNはquery/Cookieを受け取り得る。独立Originでも親domain Cookieは届き得るため既存Domain Cookieを棚卸し。CORS既定/削除可否/OPTIONS/HostはUNKNOWN。資格情報付きCORSを許可しない |
| Cache | 全assetのno-storeを期待、index/module混在/304/旧tab/圧縮応答を別承認後確認 | provider内部cacheとbrowser cacheを混同しない。旧tabは即失効不可 |
| HTTPS/alias | 採用Originは現在null。主HTTPS hostを一つ採用する別工程。独自domain維持は将来移転に有効 | Render alias/preview/custom domainは別Origin。aliasで別DBを作らせずSTOP/主host誘導を別審査。DNS/TLS/redirect/旧SWは未受入 |
| Auto/Preview | Auto Deploy OFF、PR Preview generation off、手動full SHAだけ。deploy hook/自動CI deployなし | OFFは配信停止ではない。初回作成/Previewも公開し得るため今回実行不可 |
| Rollback | codeとheader/routes/domain設定のrevisionを一組で記録。旧build保持期限とsource再build手段を保管 | Headerは戻らず、IndexedDBも戻らない。schema/Export互換不明なら非破壊STOP/inert停止候補、Clear-Site-Data禁止 |
| Source変更 | 新SHA/tree/ZIP/CI、公開asset差分・設定差分・schema互換を再審査 | Origin有効化、CSP緩和、API/telemetry追加、bank/schema変更、未知toolchain、採用hash不一致は新公開を停止。既配信停止は別操作/承認 |

候補Build Commandの**処理順序**（Renderへ登録・実行していない）: (1) `git rev-parse HEAD` が承認full SHAと一致しcleanであること (2) 私有一時dirを作成 (3) `python3 -B scripts/ci/preparation_static_build.py --profile B --out <一時dir>/B` (4) `--verify-zip <一時dir>/B.zip --profile B` と期待hash照合 (5) 出力dirを既存でない `.render-static-B` へコピー。出力を先にcheckout内へ作るとclean_source検査を汚すため、ZIP検証を済ませてからコピーする。ZIPやtool用設定をPublishへ追加しない。実際に登録可能なbuild wrapperは採用後の限定差分で固定し、実deploy前に独立checkoutで検証する。

Render用header候補は既存 `HEADERS` からオフラインで生成できる。Blueprint自動同期事故を避け、今回はrepo rootの `render.yaml` やdeploy可能な設定ファイルを新設しない。候補設定の審査はheader集合/一意性/固定CSP/no-store・routes空・Auto/Preview off・root/publish・固定Source/profileを照合する。現在は**候補変換の整合だけ検証可能**で、Dashboardとの差分/実効HTTP/TLSは `LIVE_VERIFICATION_REQUIRED` のまま。Providerの完全parserを模倣する新実装は不要と判断。

オフライン実績（基準main `c7252346`、各25files）: 既存負例12件成功、B/C生成・profile指定ZIP検証成功。B ZIP SHA256 `29d4d32524e1d9729a88855c72fb63dcb0d3c2b343be7817b61bab7566885a4e`、C `f0dbcbfef930c9c1ba8c4bce00687e247a3ad65a6bf2862f67313445de886cc6`。どちらもrelease_approved=false。前PR HEADのhashとはSourceが異なる。新たな配布Source採用ではない。今回UI/storage blobを変えないため、本人Safariの既存差分受入を再開始させない。

#### Provider最終比較と費用判断

金額はUSD、税/為替/domain登録・更新/既存DB保管費等は別。契約実額・残枠・既存サービス負担はUNKNOWN。全案とも公開前の実効CDN検証は未実施で、試験deployにも別承認が必要。

| 案 | 費用・上限の公式確認 | 小規模安全性/固定配備/長期運用 |
|---|---|---|
| Render Static | Staticは無料開始。workspace帯域Hobby5GB/Pro25GB/Scale1TB、超過internet $0.15/GB。Starter pipeline月500/1000/5000分、build120分上限。分数超過購入はspend limit/支払方法に依存、追加分単価はUNKNOWN | 既存運用とLaravel監視を集約。固定SHA手動可。Header/routesが配布と別revision、他Siteが枠を消費する点が負担。pipeline上限は帯域課金capではない |
| Cloudflare Pages Direct Upload | static request無料/unlimited。Free500build/月/20分、20000files/25MiB。手元既成asset uploadならprovider build不要。domain/追加製品費は別 | Git未連携で公開権限を分離しやすく、費用予測では優位。別provider運用/Preview・既定CORS等の受入が必要。固定ZIP＋Source記録で再現 |
| Amplify Hosting static/manual | 標準build $0.01/分、CDN保存$0.023/GB月、転送$0.15/GB（無料適用外）。無料枠の本人account適格性はUNKNOWN。SSR/WAF/backendは範囲外 | GitなしZIP対応。IAM/課金/headersと停止責任の追加設計が必要。LaravelをAmplifyへそのまま移す方式ではない。今導入する運用増加に見合う利点は弱い |
| S3＋CloudFront | flat-rateに$0/月、$15/月等・overageなしの記載。Free表示1M requests/100GB・S3 credit5GB。従量案は別。実適格性・allowance超過時処理・private origin/header機能適合・S3全費用はUNKNOWN。地域別S3/CloudFront従量単価は今回未確定 | private S3 REST+OAC、headers/cache/error/IAMの細かい設計が可能。固定prefix/manifestを切替する設計が必要で、素朴な上書きsyncをatomic deployとみなさない。初期/継続運用は最大 |

Renderは支払方法があれば帯域超過を請求、なければworkspaceサービス停止となり得る。無課金化のため支払方法を外すなど既存workspace全体を変更してはならない。採用時は残枠・通知・pipeline spend limit・帯域費用許容・停止責任を読み取り確認し、不明なら採用保留。AWS Budgetsも請求停止の保証にならない。CloudFrontの新flat-rateを無視して「AWSは必ず従量」と断定しないが、関連費用と適合が不明な現在の小規模先行版に直ちに採用しない。

Canovia全体では **Render B → Cloudflare Direct Upload → Amplify → S3/CloudFront（運用体制確立後再評価）** の優先順位を推奨。根拠は既存Render管理の再利用とLaravelを別安全トラックで継続できること。費用上限が満たせない場合はCloudflareを再優先する。静的JS/標準HTTPS/明示JSON Exportとprovider外のSource/ZIP/設定記録を維持し、Functions/独自Auth SDK/edge DBに依存しない。将来AWS移転時もHeader/404/cacheをadapter設定として再検証し、同domain維持だけでstorage移行成功としない。

#### 段階的移行・残る判断

以下のPhase A–Dは移行段階であり、上記Hosting構成A/B/Cや配布profile B/Cとは別。

| 段階 | Hosting・データ所有権 | Origin/IndexedDB・Export/Import | 正式版との接続条件 |
|---|---|---|---|
| Phase A: Local-first B独立公開 | 専用Static候補。本人の端末内記録、クラウド/管理者の復元なし | 独立HTTPSの明示採用後に固定、専用DB、JSONの本人保管/復元。実Safari未受入 | Canonicalへの通信・Auth・AIなし。別Hosting/Origin/security/公開GOが必要 |
| Phase B: 利用者検証/UI/安定化 | 同static/同Originを継続、最小匿名support。analytics追加は別同意/設計 | schema/Export互換、Quota/消去説明、旧tab/rollback受入。Cへ暗黙縮小しない | 正式版の安全ゲートをLocal-first成功で解除しない |
| Phase C: Canonical安全性解消 | Laravel/DBは別安全トラック。Render継続/AWS等は別判断 | staticの端末記録を保全。旧DB所有権/backup/restore/Writer/Session/Cutover/独立審査 | e858のBLOCK、実DB/復元、認証境界の受入を完了して別GO。今回実行なし |
| Phase D: 正式認証/クラウド保存/AI | Laravel正式版の本人認証・権限/同意/費用制御が必要 | 正式版とstaticが同Originとは限らない。異OriginはIDBを読めず、本人Export→別工程のImport。同Originでも自動吸上げ禁止 | Canonical Importは本人認証、所有権/同意、厳格schema/容量/版検証、重複/衝突排除、原子的保存/失敗保持、監査・削除取消を別設計/受入 |

独自domainを維持してproviderだけ変える場合はscheme/host/portが同じなら通常IDB Originを維持できるが、同browser/profile・storage消去/SW/互換の確認が必要。host/port変更、Safari profile変更では別storageとなる。DNS redirectはデータ移送ではない。旧staticのExport導線/保管期間/終了通知を別計画とし、先行版を消して正式版へ強制移転しない。

本人が次に承認すべき最小事項は **「Welcomeを変更せず、専用Render Static Siteを採用候補として設計を確定する方針」**。この判断にサービス作成・実Origin採用・設定登録・deploy・課金・外部公開は含めない。続く各操作は具体的Source/設定/予算/停止票と実Safari結果を揃えた別判断。

2026年12月1日は目標のまま。今回の仕様比較によるapp/schema改修は不要だが、残るcritical pathは費用/Origin候補の判断→継続中Safari差分受入→別承認された限定配備→実効headers/404/query/Cookie/CORS/cache/alias/停止受入→本人公開GO。Renderの初回作成自体がdeployを開始するので、公開前工程へ紛れ込ませない。日付達成を保証せず、CanonicalのPhase C/Dを先行版必須に混ぜない。**HOSTING_RECOMMENDED_NOT_ADOPTED / LIVE_HOSTING_NOT_RUN / SAFARI_ACCEPTANCE_PENDING / PRODUCTION_NO_GO_HOLD**。e858解除・実Aiven/Migration/Auth0/production/release/旧Web Resume/HTTP/Welcome更新は一切行わない。

### Render Static Site配布準備 Phase 1（2026-10-27 JST、オフライン実装）

PR #910の審査を受けた限定実装。基準main `9537f3af60bc7f714cf0fd2d946664adbd488a83`、#906–#910 MERGED、Issue #488の最新証拠を再確認。専用Static Siteは第一候補のまま、**正式採用・作成・設定登録・配備・Origin採用は未承認**。Welcome/旧Web/Canonical/Session/app/UI/storage schema/既存Cloudflare検証は変更しない。public roadmapの提供状態にも変化がないので更新しない。

#### 実装した設定票・Preflight・Build境界

`preparation_static_render.py` は既存builder/allowlist/manifest/ZIP検証を呼ぶオフラインadapter。新しい配布形式やRender API clientは作らない。full Source SHA・明示B/C・期待ZIP SHA256を必須入力とし、Git clean HEAD/Source・全25files・profile・全bytesを照合する。浅いcloneでも必要なHEAD tree/blobが揃えばよく、全履歴を必須としない。Git metadata/必要blobがない、dirty checkout、Python3.9未満、Git未導入、Source/hash不一致はBLOCK。実Renderでこれらを満たせるかはUNKNOWNのまま。

- 生成するJSONは `PREPARATION_RENDER_OFFLINE_PLAN_V1` という**独自の審査票**。Render API/Blueprint入力ではなく、auto syncされない。Source/profile/ZIP hash、root/build/publish、Header path/name/value、routes空、Auto Deploy off、Preview generation off、依存install skip、env groupなし、domain空、Origin null/公開falseを固定する。
- `--plan` は固定期待値との完全照合。重複JSON key、未知field、不正型、Header欠落/重複/変更/限定path、Rewrite/redirect、異Source/profile/hash、Auto Deploy/Preview有効化、Origin設定/公開承認trueを拒否。大小文字違い等もこの審査票では正規形不一致として拒否する。Render全設定parser/実設定の証明ではない。
- `--stage` はcheckout**外**の既存でない `.render-static-B`（CならC）へ、ZIPを展開せずGitから再build→hash/全bytes検証→コピー→集合/bytes再検証。private親dirで使用する。Source checkout内出力・上書き・symlink出力・設定票との出力衝突を拒否。設定票/ZIP/toolは公開dirへ入れない。エラー時は固定 `PREPARATION_RENDER_BLOCK` / exit2、PASSを出さない。失敗時のローカル一時出力が残っても公開認可されない。
- 成功出力は `offline_source_integrity=PASS` / `offline_render_plan=PASS` だけ。`render_build_environment=UNKNOWN`、`hosting=LIVE_VERIFICATION_REQUIRED`、`deployment=BLOCKED_ORIGIN_NOT_ADOPTED`、`release_approved=false`、`PRODUCTION_NO_GO_HOLD` を維持する。

| Render候補項目 | Phase 1で固定した仕様 |
|---|---|
| Root Directory | repo root（空文字）。`static-welcome`/`preparation-local`を指定しない |
| Publish Directory | `.render-static-B`（C候補は`.render-static-C`）。`.`/`public`/repo全体は禁止 |
| Build Command | 設定票の `buildCommand`。`python3 -B scripts/ci/preparation_static_render.py --render-build --profile B --source-sha <full40> --artifact-sha256 <hex64>` |
| 現在のBuild Commandの動作 | **必ずBLOCK/exit2、公開dirを作らない**。これは未採用Originの配備ゲートで、現状のままRenderへ登録/実行しない。公開可能なbuild wrapperへの変更はOrigin/費用/公開範囲の別採用審査後に限定PRで行う。環境変数やJSONで解除するスイッチはない |
| 将来wrapperが再利用する処理 | 下記オフライン検証・Sourceからの一時build・ZIP hash確認・公開dirコピー。Render checkoutの汚れを無視したりGitなしで自己申告manifestを信用するfallbackは設けない |
| Build tools/environment | Python3.9+/Git・必要HEAD blobs・cleanを要求。`SKIP_INSTALL_DEPS=true` 候補。Composer/npm/PHP/DB接続不要。実環境tool版/clone状態/自動生成fileは未確認 |
| Security Headers | 既存builderの5headersを各 `path=/*` の別設定として出力。CSP/Referrer/nosniff/Permissions/no-store一致を検査。`_headers`も配布維持するがRender自動適用とみなさない |
| 404/Rewrite | `routes=[]`、`404_NO_INDEX_REWRITE`。root `404.html`を固定bytesで検証。Renderでの未知pathの実404/status/body/headersは未検証 |
| Auto Deploy/Preview | off/offを厳格照合。特定full SHAだけを後日の手動配備候補にする。GitHub CIからRenderへ接続するstep/token/hookはない |
| Origin/alias | null/domain空、実HTTPSは未採用。別OriginのIDB共有なし、旧Session/Cookie/SW流用なし、Welcome導線追加も別工程 |

独立clean checkoutでの再現例（**ローカルのみ**、SHA/hashはIssue #488の同一検証HEAD・同profileの証拠で置換。出力親は新しい私有一時dir）:

```bash
PREP_RENDER_TMP="$(mktemp -d -t canovia-render-review)"
python3 -B scripts/ci/preparation_static_build.py --profile B --out "$PREP_RENDER_TMP/B"
python3 -B scripts/ci/preparation_static_render.py --profile B \
  --source-sha <検証HEAD全40桁> --artifact "$PREP_RENDER_TMP/B.zip" \
  --artifact-sha256 <同HEADの期待B-ZIP-SHA256> \
  --plan-out "$PREP_RENDER_TMP/B-plan.json" --stage "$PREP_RENDER_TMP/.render-static-B"
python3 -B scripts/ci/preparation_static_render.py --profile B \
  --source-sha <検証HEAD全40桁> --artifact "$PREP_RENDER_TMP/B.zip" \
  --artifact-sha256 <同HEADの期待B-ZIP-SHA256> --plan "$PREP_RENDER_TMP/B-plan.json"
```

自分で算出したhashの一致だけではSource/配布採用・本人承認を証明しない。生成したbuildCommandを手元で実行してもexit2になることを負例CIで確認する。`--stage` の成功をRenderへのアップロード許可に読み替えない。

#### CI・負例と証拠

既存 `Preparation Static Local-first` workflowにadapter負例とB/C設定票生成・再検査・stageを追加。新規Render用10testsは、両profileのexact staging、危険設定22種、Header欠落/重複/改変7種、重複/不正JSON・FIFO/symlink、Source/profile/hash不一致、再hashした不正ZIP/余剰秘密・SW・設定file・404改変、実際のdirty checkout、Gitなし/Python不足、出力衝突/上書き、未採用Build Commandの非ゼロ終了/非生成、引数非漏えいを含む。既存配布負例12件とChromium/WebKit×B/Cを保持する。

`preparation-render-offline-plans` ArtifactにB/C設定票とreportを7日保存（公開dirそのものはこのArtifactに含めない）。従来のB/C候補ZIP・合成画像Artifactも保持。最終PR HEAD・CI Run・ZIP hash・統合mainはIssue #488へ記録する。CI PASSは実Render/実Safari PASSではない。

#### 作成前の費用・通知確認票（2026-10-27 JST、変更なし）

[Static Sites](https://render.com/docs/static-sites)、[Headers](https://render.com/docs/static-site-headers)、[Build Pipeline](https://render.com/docs/build-pipeline)、[Outbound Bandwidth](https://render.com/docs/outbound-bandwidth)、[通知](https://render.com/docs/notifications)、[Pricing](https://render.com/pricing)の公式本文を再取得。Markdownが取得ツール非対応のためHTMLで確認した。Pricing本文では数値表を取得できず、そこで読めない現行plan月額/追加pipeline単価はUNKNOWN。推測で埋めない。

| 項目 | 公式仕様 | 実My Workspaceの観測/未確認 |
|---|---|---|
| Static/plan | Static無料開始、帯域/分数はworkspace共通。Hobby/Pro/Scaleの帯域5GB/25GB/1TB、Starter分数500/1000/5000分 | 実workspace planはUNKNOWN。Webの`plan=free`/`buildPlan=starter`で推定しない |
| 帯域単価/超過 | internet超過$0.15/GB。支払方法ありなら請求、なければworkspaceサービス停止となり得る | 使用量・残枠・支払方法の登録有無はUNKNOWN。カード番号等の秘密を取得しない |
| Pipeline | 超過分自動購入はspend limit/支払方法に依存。上限到達/支払なしでは当月build停止。build120分/16GB disk上限 | 実残分/費用上限/追加分単価はUNKNOWN。pipeline capは帯域capを意味しない |
| 既存サービスへの影響 | Site分離しても共通枠/障害の影響は残る。作成は初回deployを開始する | MCP読み取りでCanovia Suspended(user)、Welcome Auto Deploy/Preview off・root/build/publish不変。専用Siteは作成していない |
| 通知 | email/Slackのworkspace default、service override。deploy等の通知が可能 | 既存対象serviceは `notifyOnFail=default`。実default/通知先/受信確認・料金通知条件はUNKNOWN。個人連絡先/秘密を記録しない |
| 運用案 | owner/operatorがBilling使用量と通知受信・provider status/失敗deployを確認し、予算/残枠不明なら配備HOLD | 通知先・確認頻度・許容費用・停止責任者を本人が決定。通知/外形監視/課金設定を今回変更しない |

MCPにはworkspace plan/請求/残枠/payment登録/spend limit/default通知先を読むtoolが見当たらず、list_workspaces/list_servicesの応答にも含まれないためUNKNOWN。`notifyOnFail=default`は通知到達の証明ではない。支払い削除やplan変更で既存serviceを止める操作は禁止。採用前の次工程で、秘密なしの管理画面読み取りにより確認する。

#### 残る判断・Safari・12月1日

本人がサービス作成前に決めることは、専用Render案の正式採用、独立Origin/全aliasとCookie境界、固定Source/profile/配布hash、実plan/残枠・費用許容・通知/停止責任、初回配備の公開範囲と別GO。現時点の安全なオフライン準備はCI/開発main統合まで進めるが、未解決UNKNOWNを解除しない。

app/UI/schema/保存・Export/Importのbytesは不変なので、進行中の本人Safari差分受入を再開始させない。Safari保存/再訪/実ファイルExport・復元の受入と、新SHAのSource/ZIP照合は必要。採用後の実HTTPS/Headers/404/Rewrite/CORS/query/Cookie/cache/alias/停止・rollbackは別受入。WebKit CIを本人Safariへ昇格しない。

12/1目標への影響: 設定票と誤配布防止をCI化し、後日の配布判断材料を具体化した。残るcritical pathは費用・Origin・本人Safari→限定wrapper/Origin変更の審査→別承認の作成/配備→実効security/運用受入→公開GO。Origin未採用BLOCKを弱めて日付に合わせない。Welcome更新、旧Web Resume/HTTP、Aiven/Canonical、Session/SW、Auth0、production/release、e858、有料契約、外部公開は一切操作せず **PRODUCTION_NO_GO_HOLD**。

### 公開Origin候補・最終設定パケット（2026-10-27 JST、承認前）

基準main `e2984ea88d976d3296b9b6413833ff6211b8fef9` とPR #911/Issue #488を再確認。本追補は本人の次回判断に用いる審査パケットであり、Hosting/Originの正式採用・BLOCK解除・サービス作成・配備・DNS変更を許可しない。コード/設定の変更は不要と判断し、既存adapter・負例・browser CIを再利用する。過去の「Safari全体未受入」は、以下の**本人申告された成功経路だけ**を更新する。

#### 本人Safari受入の更新（成功範囲限定）

2026-10-27、本人申告: 通常の専用Safariプロファイルで静的アプリを表示し、診断→Plan作成を操作。Command+Qで完全終了し、同じOrigin・プロファイルで再起動後もPlanを保持。JSON Export/Importによる記録引継ぎも成功した。状態を **OWNER_REPORTED_SAFARI_POSITIVE_PATH_ACCEPTED** とする。個人Plan/Export/画面データは取得しない。

申告からは検証Source SHA・Origin文字列・Safari/OS版・Export別媒体保管条件を確定できずUNKNOWN。今回固定するSourceで操作したと逆算しない。Quota/abort/競合/破損・重複Import/未知版/データ消去からの復元等の未申告負例、実Render HTTPS上での保存、全端末/恒久保存は未受入。CI合成負例と本人実機成功を別証拠として保持する。既存の成功経路を最初から再要求せず、採用Sourceとのasset差分照合と未実施差分だけを後日受け入れる。

#### Origin・DNS・Cookie・SWの判断

**`https://start.canovia.com` を条件付き設計候補として推奨するが、正式採用はHOLD**。所有権/DNS制御/競合なしとCookie分離を確認できなければ、同じcanovia.com配下の別subdomainへ逃がさず、別の所有確認済み登録可能ドメインを比較・選択する。

| 調査対象 | ソース/公式仕様から確認できたこと | 未確認・採用条件 |
|---|---|---|
| 既存公開構成 | `.env.example` のcanonical候補は `https://app.canovia.com`。Welcome configは旧Render Webを宛先にし、既存DNS/TLS仕様には2026-10-15のapp DNS_UNVERIFIEDという日付付き証拠がある | 現在のapex/www/app/startの宛先・DNS所有/制御・wildcard/CAA/別provider routingはUNKNOWN。古いDNS観測を現在状態とみなさない |
| GitHubのDNS仕様 | `PRELAUNCH_STATIC_PUBLIC_DNS_TLS_ACCEPTANCE_2026_10_15.md`、同readonly probe、`.env.example`/`config/canovia.php`/Welcome sourceを確認。startの既存割当・権威DNS zoneの正本は発見できなかった | repoで見つからないことは空き/所有権証明ではない。既存DNS/TLS probeも今回は起動しない |
| 今回の名前解決 | DNS-over-HTTPS資料取得はツールで不可。OS resolverのDNS照会だけをcanovia.com/www/app/startへ実行したが全てgaierrorで取得不能。各hostへのHTTP/TLS接続なし | **DNS_LOOKUP_UNAVAILABLE**。NXDOMAIN/空き/ドメイン失効/全世界の障害と判定しない。所有/利用可否はUNKNOWN |
| Laravel Session | `config/session.php`: cookie名 `pace-keeper-session`、path既定 `/`、domainは `env('SESSION_DOMAIN')`、secureはproduction既定true、HttpOnly既定true、SameSite既定lax。`.env.example`はSESSION_DOMAIN=null | 実env/config cache・過去ブラウザ保存CookieのDomain/Path/期限はUNKNOWN。templateのnullを実host-onlyの証拠にしない。Session以外のXSRF/他app/親domain Cookieも対象 |
| 親Domain Cookie | Domain=canovia.com（先頭dot有無は同じ）なら条件に合うstartにも送られる。CNAME先がRenderでもbrowserの要求hostはstart | 独立Origin/HttpOnly/Secure/SameSite/CSP/no-storeでは最初のCookie送信を防げない。JSのOrigin STOPもHTTP後である。秘密CookieがCDNへ届かない境界を確認するまで採用HOLD |
| Service Worker | Laravel sourceは `resources/js/instant-start.mjs` で `/sw.js` 登録（scope指定なし、root scope）。SW scopeはsame-origin内。別hostのapp/apex/旧Render SWはstartを制御しない | start自体の過去利用/SW残留はUNKNOWN。先行配布はSWなし、既存controllerがあればIDB前STOP。他SWを解除しない。旧Laravel native用解除コードも先行配布には含めない |
| IndexedDB | scheme/host/portとbrowser/profileに束縛。startとapp/apex/onrenderは別DB。現在のBは専用namespace | Cookieのsite/domain境界と区別する。同domainでもhost違いを共有扱いしない。将来Laravelの自動Import/同Originを仮定しない |

境界の根拠は [Cookie Domain/属性](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Set-Cookie)、[SW same-origin制約](https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerContainer/register)、[IndexedDB same-origin](https://developer.mozilla.org/en-US/docs/Web/API/IndexedDB_API) の本文を確認。Cookieのsame-siteとstorageのsame-originを混同しない。

Cookie判定の非秘密操作票: 旧Webを開かず、既に保管されたbrowser Cookieメタデータや許可済み設定記録からDomain/Path/Secure/HttpOnly/期限とhost-onlyの有無を本人がローカルで確認する。値/token/全Cookie dump/Planを提出しない。ローカル確認ができなければUNKNOWNで停止。既存envをhost-onlyへ変更しても過去Domain Cookieが消えるとは限らず、旧Cookieの失効/Canonical Session変更は別の安全工程。今回は変更・削除しない。新しい専用profileでCookieがないことだけを一般利用者の安全証明にしない。

| Origin方式 | 利点 | 採用条件/負担 |
|---|---|---|
| start.canovia.com | ブランドと将来provider移転時のURL継続。appとはIDB/SW分離 | 親Domain Cookieの全体方針・DNS所有/競合を要確認。条件未達はHOLD |
| 別の所有確認済み登録可能ドメイン上の専用host | canovia.comのDomain Cookieが一致せず、既存appのSession/SWから分離しやすい | 未所有domainの登録/購入は今回禁止。所有・TLS・更新費・名称は未確定。JSON Export/Importと将来Canonical Importは別工程 |
| 新専用onrender URLを主Originにする | canovia.comのDomain Cookieが一致しない | 将来custom domain/AWSへ変更すると別OriginになりIDB継続が切れる。主Originとしては今回は推奨しない。作成直後のasset公開面としては別途考慮 |

AWS等へ移転しても同じHTTPS/host/port・browser/profileを保ち、TLS/SW/asset/schema互換を受け入れれば通常IDB Originを維持できる。DNS移転はstorage移送ではなく、消去/別profileまで復元するものではない。別Originへ移す場合は本人Export/Importが必要。Canonical Importは本人認証・同意・所有権/厳格検証の別工程を保持する。

#### B固定Source・登録候補パケット

このパケットの**検証Sourceは下記e2984で固定**し、文書PRのHEAD/統合後mainと混同しない。#911の検証HEAD799708adと同treeでもmanifestのSource SHAが変わるので、ZIPは新しく再算出した。将来BLOCK解除wrapper/Origin設定を変更すればSource/ZIP/設定票/CIを全て再発行する必要があり、このhashのまま公開可能にはならない。

| 項目 | 現在の検証値/本人が後日照合する候補 |
|---|---|
| Source SHA | `e2984ea88d976d3296b9b6413833ff6211b8fef9` |
| Source tree | `c34e0a74539f647f04ed049606ba05d4e0e167e6` |
| B ZIP SHA256 | `796b941d377f36e37f0210b9529bd68ddf10402b4cf786df967f0d418f5efc92` |
| 閉じた公開集合 | 25files、manifest込み1,540,504 bytes、ZIP1,543,132 bytes。PHP/.env/旧JS/SW/fixture/adapter/設定票/ZIP自体をPublishに追加しない |
| Root / Publish | repo root（空文字） / `.render-static-B`。`.`/`public`/Welcome rootを登録しない |
| Git / Python | Python3.9+、Git metadata/HEAD tree/blob、clean checkout、full SHA一致。全履歴は不要だが実Render条件はUNKNOWN。欠落をfallbackで緩和しない |
| Build Environment | 候補 `SKIP_INSTALL_DEPS=true`、env groupなし。Canonical/Session/秘密を渡さない |
| Auto Deploy / Preview | off / generation off（After CI checks passやmanual previewも不可） |
| 現在の実装上のOrigin | `adopted_origin=null`、`customDomains=[]`、`release_approved=false`。候補startをadapter設定票へ書き込むと拒否される |
| 将来Custom Domain候補 | 正式採用された場合だけ `start.canovia.com` 1host。apex/www/wildcard/旧appを追加しない。現在の登録先/サービスID/CNAME targetは未存在・UNKNOWN |
| 将来標準subdomain方針 | 専用Siteにcustom domain追加・検証後、Render Subdomain Disabledを候補とする。現adapterはこのfieldを持たず未知fieldとして拒否するため、登録可能な契約への反映は別wrapper PR。旧Welcome/Webの標準URLは触らない |
| Redirect / Rewrite / 404 | routes空。hash routingなのでindexへのcatch-allなし。script/formなしroot404の固定bytes。Render既定HTTP→HTTPS以外のredirect/aliasを追加しない。未知pathの実404/status/headersは未検証 |
| Cache / CORS / Cookie | 全path no-storeを別Header設定。既定CDN cache/304/圧縮/非GET/query/CORS/Set-Cookieは実受入待ち。loopbackのCookie/Auth拒否をRenderにも期待しない |

現在の固定Build Command（**必ずexit2、実Renderへ登録・実行しない**）:

```text
python3 -B scripts/ci/preparation_static_render.py --render-build --profile B --source-sha e2984ea88d976d3296b9b6413833ff6211b8fef9 --artifact-sha256 796b941d377f36e37f0210b9529bd68ddf10402b4cf786df967f0d418f5efc92
```

全Security Headersの登録候補（各path `/*`、重複/部分path/余剰設定なし。`_headers`をRenderが自動処理するとは仮定しない）:

| Name | Value |
|---|---|
| Content-Security-Policy | `default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'none'; worker-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'` |
| Referrer-Policy | `no-referrer` |
| X-Content-Type-Options | `nosniff` |
| Permissions-Policy | `camera=(), microphone=(), geolocation=(), payment=()` |
| Cache-Control | `no-store` |

オフライン照合は独立clean checkoutを上記Sourceへdetachし、新しい私有一時dirで既存builder→adapter `--profile B --source-sha <上記SHA> --artifact <生成B.zip> --artifact-sha256 <上記hash> --plan-out <一時dir>/B-plan.json --stage <一時dir>/.render-static-B` を実行する。後日 `--plan <同設定票>` で再検査できる。設定票はRender API requestではなく、Dashboard設定の現物と照合する入力票。出力はoffline PASSだけ、実Render/Origin/配備はUNKNOWN/BLOCKを維持する。今回は2回の同Source生成ZIPのcmp一致、配布12tests・adapter10tests、設定票生成/stage/再検査を確認。既存browser試験のOrigin不一致IDB呼出0、profile/Source/hash/余剰asset/Header/Rewrite/Auto/Preview/未採用build負例を再利用し、製品コードの追加は不要。固定SourceのStatic CI Run/文書PR/統合SHAはIssue #488へ記録する。

#### onrender・DNS・初回公開範囲の操作条件

公式 [Custom Domains](https://render.com/docs/custom-domains) / [DNS Providers](https://render.com/docs/configure-other-dns) を2026-10-27 JSTに再確認（Markdown取得非対応のためHTML）。custom domainが少なくとも1件あれば標準subdomainを無効化でき、無効化後の標準URLは404・serviceへ到達しない。実際の新Siteでは未検証。custom domainを追加しただけでは標準URLは残る。実host停止とURLの非掲載を混同しない。

- 本人操作時は、既存zoneのstartのA/AAAA/CNAME/TXT・wildcard、apexのNS/CAA、domain forwarding/proxy、既存Render紐付けを読み取り、競合・所有/権限を確認。取得不能/用途不明ならSTOP。新Siteから返る**実際の専用Render hostname**をCNAME候補targetにする。Welcome/旧Webのhostnameを流用しない。
- DNS変更は別承認後、startの必要recordだけ。apex/www/app/全zoneのAAAAやCAAを一括変更しない。継承CAAがTLSを妨げる場合は全体影響を別審査。wildcard/apex方式は今回採用しない。DNS→TLS verified→標準subdomain Disabledを現物で読み戻す。CNAME先のhostnameとアクセス時のHTTP Host/SNIは区別する。
- **新規作成は初回build/deployを開始し、標準URLは原則有効**。Auto Deploy OFFは初回deployを抑止せず、Origin STOP/noindex/未告知はassetの認可ではない。既知URLのHTML/JS/画像が第三者に取得され得る前提で判断する。
- 推奨する後日手順は、最初は承認済みの失敗終了build/停止用最小成果物に限定し、B本体の初回成功配信より先にHeaders/Domain/標準URL停止を確認する方式。現在のBLOCK buildを用いた作成も今回は未承認。providerの初期error画面・URL露出・失敗build分数もゼロ/非公開と保証しない。停止用成果物を選ぶ場合は、別固定Source/hash・実効Header・公開範囲の審査を先に行う。
- 作成画面でoff/Headers等を事前登録できる範囲、custom domain未検証状態での標準URL停止可否・TLS更新の順序は実操作未検証。UIがこの順序を満たさなければクリックを進めずSTOPし、本人が初回の公開範囲を再判断する。未知の新service URLを今回作ったり、試験配備で確かめたりしない。

#### 本人操作票・承認ゲート（未実行）

各票は「期待値 / 実際の読み戻し値 / 判定 / 確認時刻・非秘密証拠」を記録する。UNKNOWN/差異をPASSにせず、秘密/請求個人情報/生Cookie/Plan/ExportはGitHubへ置かない。

| ゲート | 本人判断/操作票 | STOP条件・許可に含まれないこと |
|---|---|---|
| 1. Hosting・Origin正式採用 | 専用Render、候補host所有/DNS制御・競合なし、親Cookie/旧SW境界、全alias、費用/停止責任を確認し、採用hostを一つ選択 | UNKNOWNのまま採用しない。別登録ドメインなら新しい候補票が必要。作成・DNS変更・wrapper解除は含まない |
| 2. 公開可能Wrapper限定PR | ゲート1後、選択Origin/標準URL拒否、Source/profile/hash/clean/allowlist、schema互換・停止候補をレビュー。承認された場合だけ限定実装→負例/CI→main | 現BLOCKをJSON/envで解除しない。変更後のSource/tree/ZIP/設定票/CIを再発行。本票のe2984/hashを流用しない |
| 3. 作成・初回配備 | 新専用service名/ID・workspace確認、Root/Publish/Build/SHA/hash、Auto/Preview off、全5Header/routes、Domain/標準URL方針、初回に公衆が取得し得る範囲、失敗時停止票を本人が明示承認 | **Createボタンはdeploy開始**。作成だけの無公開操作とは扱わない。DNS/Domain/設定変更範囲も別途明示。既存Welcome/Web/DBへ触れない |
| 4. 実配信の受入 | 新Siteだけを対象にHTTPS/TLS・主host/alias・Header全一致・Cookie非送信/Set-Cookie不在・未知404・query/非GET/CORS/cache/古いmodule・停止/rollbackを確認。合成入力で同Origin Safari保存/再訪/Export差分も確認 | 実Cookieを確認前に旧Cookieのあるprofileで候補hostを開かない。旧Web HTTPは含まない。raw headerのCookie値を提出しない。結果は実条件の範囲だけ |
| 5. 一般公開GO | ゲート1–4と未実施実機項目、費用・通知、privacy/Export保管説明を本人が総合判断 | 未告知の配備も既に外部到達し得る。一般案内/Welcome導線変更は別差分・別GO。Canonical/e858は別HOLD |

公開後の停止/rollback票（今は手順設計のみ）: owner/operatorを停止責任者として本人が指名し、連絡先は私有管理。事故時は新Siteへの更新・自動経路を止め、事前承認済みinert停止版の手動配信またはproviderが実際に提供する遮断を選ぶ。初回公開前に対応可否・必要権限・Source/hashを確定し、まだ存在しないSuspend機能や保持artifactを前提にしない。Auto Deploy OFFだけ/標準URL停止だけ/DNS削除だけでcustom domain配信停止と認定しない。全custom host・標準URL・previewを対象に状態を確認し、取得済みtab/cacheは即回収できないと説明する。

[Render Rollback仕様](https://render.com/docs/rollbacks)ではbuild artifactは戻せてもstatic Headers/routes/custom domainsは現設定のまま。成功artifactの保持有無・互換schemaを先に確認し、コードと設定票を別々に照合/復旧する。IndexedDB/Exportは戻らない。初回には安全な旧deployがないため停止候補なしで一般公開しない。Clear-Site-Data/強制DB消去・downgradeは使わない。DNS/Origin切替や秘密Cookie露出が疑われれば新配備をHOLDし、旧Canonical Sessionへ手を出す工程は別判断とする。

#### 費用・通知・使用量（本人画面申告、2026-10-27時点）

| メーター | 本人が確認した表示 | 解釈の限界 |
|---|---|---|
| 通信量 | 738 MB / 5 GB | 残量は表示単位の単純計算で約4.26GB。月末予測・帯域hard capではない |
| Build Pipeline | 295 / 500分 | 単純差205分。新Site/既存serviceのbuild・失敗buildが共有消費する |
| Free Instance | 238.55 / 750時間 | 差511.45時間。Staticに常駐Laravelを作らないが、実課金メーターへの増分は未測定 |
| サービス | 7 / 25 | 差18。新Staticの実カウント/他resource消費は作成前再確認 |
| Custom Domain | 1 / 2 | 差1。start 1hostだけなら表示上は枠内の見込み。登録後の算定・別domain追加は未確認 |
| Build Pipeline追加支出上限 | $0/月 | **本人画面申告のpipeline capだけ**。帯域の課金上限、実plan、支払い登録有無、通知受信を証明しない |

公式 [Static Sites](https://render.com/docs/static-sites) はHobby/Pro/Scale custom domain枠2/15/25、枠超過1件$0.25/月と記載。今回の1/2表示を実plan契約の証明にしない。domain登録/更新費・支払い/請求情報は未取得・UNKNOWNで、記録しない。[Bandwidth](https://render.com/docs/outbound-bandwidth)のinternet超過$0.15/GBと支払い有無による超過請求/停止の分岐、[Pipeline](https://render.com/docs/build-pipeline)のcap到達時build停止は一般仕様。実際の請求適用・通知到達は別確認。

新Siteの利用増分は、build回数×実Render所要分（現在UNKNOWN）と配信bytes×request数。全公開集合1.54MBを1,000回全取得する仮定なら圧縮/headers等を除き約1.54GBであり、実利用者数や転送量の保証ではない。no-store・bot・再読込・既存serviceの消費を見込む。StaticのためWeb常駐時間を増やす設計ではないが、実測前に全費用0とは認定しない。枠内でも失敗deployを無限に繰り返さず、cap$0では停止版の再buildも枠不足で困り得るため、既存artifact保持/遮断経路を作成前に決める。

運用案: owner/operatorが月初に枠/plan/通知先・capを再確認し、公開中は週次と各更新前後に使用量/失敗build/既存service影響を確認。表示上限の80%で残月予測を見直し、90%・予算/通知不明・急増時は新配備/一般案内をHOLDして停止責任者が判断する案とする（本人の運用採用は未決、通知設定/自動監視は作成しない）。停止しても既発生請求や旧tabの通信は回収できない。pipeline capを帯域制限として説明せず、料金通知と障害通知の受信確認は秘密なしの判定だけを残す。

#### 現在地と12月1日

本人申告Safari成功経路とusage画面情報を新証拠として追加したが、**ORIGIN_CANDIDATE_NOT_ADOPTED / COOKIE_DNS_ACCEPTANCE_PENDING / LIVE_RENDER_NOT_RUN / PRODUCTION_NO_GO_HOLD**。e858のBLOCKを維持。次の最小判断はゲート1のためのDNS所有/競合とCookie境界の確認方針・候補Originの選択であり、ゲート2–5を一括承認しない。

2026-12-01目標は維持。本人実機の基本成功経路は前進した一方、Cookie/DNS→ゲート1→wrapperの限定審査/新Source→初回公開範囲の別GO→実Render受入→一般公開GOが残るcritical path。未解決を日付でPASSへ変えず、既存Welcome・旧Web Suspended・Aiven/Canonical/Session・Auth0・production/release・課金に変更なし。今回、実hostへのHTTP/health、DNS変更、domain登録、サービス作成/設定/deploy、Origin BLOCK解除・一般公開は一切行わない。

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

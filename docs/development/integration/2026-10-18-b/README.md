# B案：main統合リリース / Draft・NO-GO

2026-10-18 JST。正本: [#787](https://github.com/1kz-ma1/Canovia-web/pull/787)、[#488](https://github.com/1kz-ma1/Canovia-web/issues/488)、[統合マスター](../../CANOVIA_INTEGRATION_FREEZE_RELEASE_MASTER_2026_10_16.md)、最新AGENTS/[workflow](../../../CANOVIA_DEVELOPMENT_WORKFLOW.md)。旧A案 #785/#786 は比較資料として保持し、merge/cherry-pick/closeしない。

## 2026-10-20：e858収束 / 12月1日C-Light審査（現行の追記）

この節を今回の索引とし、下の10月18日固定在庫を上書きしない。Issue #488が承認の正本。
**PRODUCTION_NO_GO_HOLD**。オフライン合格、PR統合、実環境操作の承認は別々。

### ソース・PR・Liveの確認境界

2026-10-20 11:07 JSTにGitHubを再取得：mainと`release/2026-10-19-e858b9c8`は
`e858b9c855970fbe73f37a2d60c9db6525b132d6`（tree `f6990e8bcf7904be2fdc2706d6a525a29ccef9ee`）、
productionは`15abdc9de6f24b5250fb255c579d1aa55facaf1a`。旧release/c09と#513は不変。
旧Liveソース→e858の313 commits / 1,020 paths / 旧94不変＋追加20＝114は
[#798の固定依存監査](https://github.com/1kz-ma1/Canovia-web/pull/798)を再利用する。
今回のテスト/文書HEADやmainへの後続統合を、新しいrelease候補として自動採用しない。

| Draft/Open（全件未統合） | HEAD / base | 証拠と後続依存 |
| --- | --- | --- |
| #794 | `02143e26c70288a1f2b3551513015ac6be72e236` / main | 適格性監査資料。4 check成功。履歴保存。 |
| #795 | `be89af9a2cb4b13ef16579d2570f4a5605a711b8` / main | source-bound基盤。13 PR check成功、専用run39211597992は8 jobs成功。#796の固定親。 |
| #796 | `5e63b7db068121d000c316fa8700f7c5f1c0c3b4` / #795 | transport/Mac cleanup修正。run39271348988の8 jobs成功。#797の固定親。 |
| #797 | `9f7d248e87bd54211b76d9b85effd72ee385daed` / #796 | 合成承認/TLS/one-shot。run39278554514 **attempt2**の3 jobs成功。古いqueued記述を現行障害にしない。 |
| #798 | `71b5143716cfd04687d91930de5b82c313d92bd9` / main | AP本人Read依存監査。PR自動3 check＋手動MCP含む4 workflow成功。新機能配備ではない。 |
| #513 | `bb8248f25545ae0d600fbb68f66c41c360004e2c` / production | 旧独立Draft、5 check成功でもNO-GO。今回更新/採用しない。 |

#795〜#797のMacオフライン受入は、[owner #796記録](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6270282307)、
[CI解消記録](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6277300988)、
[owner #797記録](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6277403445)、
[全3件受入の独立レビュー](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6278742704)を再利用。
owner提出の技術受入でありホストattestationではない。再実行依頼、固定packetのPENDING文字列変更、Ready/rebase/mergeはしない。

**Render直接再取得はUNVERIFIED**：この実行環境にRender読み取りconnectorがない。
[直近の別セッションread証拠](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6278742704)
（2026-10-20 07:26:58 JST）ではWeb `srv-dagfn2id0e5s73cac7ng`、Live15abdc9d、branch main、Auto Deploy no/off。
Static `srv-dahn5k142hec739l0fmg`、Live `ee533028c866e201d61c1430c15eaedb9c843524`、main/OFFは
今回のowner提示値＋[10月15日証跡](../../PRELAUNCH_FIXED_SCOPE_RELEASE_EVALUATION_2026_10_15.md)であり現在の独立観測ではない。
実効envの値・token・DB情報は取得していない。現在のAuto Deploy OFFを直接確認できるまでは、新規PRのmain統合も保留する。
production ruleset24954459はactive・bypass0・merge-only・5 required statuses・approving count0を再取得。
mainの適用rulesetは空、legacy branch protection APIは403で確認不能。これを保護なし/merge許可と推測しない。

### 追加し直さずに再利用する合成証拠（対象e858）

| 状態 | 検証 / exact-SHA証拠 | 限界 |
| --- | --- | --- |
| PASS | [Integration Migration Safety 39201955575](https://github.com/1kz-ma1/Canovia-web/actions/runs/39201955575)、3 jobs成功。MySQL **8.0.46 / 8.4.11**、旧94→114全20順序、全旧列/行digest、同意/認可/Attempt/session保持、再実行、M20部分DDL停止/拒否・明示合成forward recovery、TRIGGER権限なし拒否。 | 任意SQL停止点の網羅でも実本番互換性でもない。 |
| PASS | 同runの各engineで合成114を別schemaへlogical restore。全データ、全列/index/FK、trigger定義、ledger114比較。 | 実Aiven backup/isolated restore、暗号化ファイル、RPO/RTO、旧writer再開の証明ではない。 |
| PASS | [Release20 Transition Safety 39201963912](https://github.com/1kz-ma1/Canovia-web/actions/runs/39201963912)、8 jobs成功。両engineで別名、同件数意味差、新設名衝突、M08正負、M03/M04/M08/M18/M19/M20中断/再実行を既存case単位で検証。 | M08旧名だけの相違もBLOCK。合成別名を本番名一覧と同一視しない。 |
| PASS | 同run startup-policyおよび`ReleaseSchemaLedgerFenceTest` / `RenderStartupMigrationRetryV569Test`。通常checkはDDLなし、pending/unknown/unavailableはHTTP前STOP。applyは40桁runtime/approval SHA一致・一度のみ。 | ledger一致は物理一致/実承認の証明ではない。旧Live起動は自動migrateなので再起動を安全なread作業と扱わない。 |
| PASS | [Schema Diagnostics 39201947065](https://github.com/1kz-ma1/Canovia-web/actions/runs/39201947065)、2 jobs成功。元strict/semanticの負例。 | 旧c09 referenceの負例も含む。e858本番監査合格への読み替え禁止。 |

SQL/reference/guard/seal/全114 migration/startupは今回変更しない。
e858 sealは`a3494c1d1cf60546a86312f249f7d44b2ac52e44669752a65e1c70011ec9f91b`。
本番で以前観測したindex/FK名前差は**c09対象のowner提出証拠**。e858実測は未実施で、元strict BLOCKを維持する。

今回の追加は既存IA-2e E2Eの診断入口と拒否検証だけ：
診断→フェーズ型Seed→本人編集保存→Guest Plan claim→**自動Taskゼロ**→本人JSONで1 Task→
Action/Evidence/明示Next Action→ログアウト/ログイン復帰。
別アカウントのPlan/Dashboard/Workspace拒否404、Task実行拒否403、およびtokenなしcross-site POST419を実HTTPで確認する。
既存Browseとtouch WebKit経路を残し、外部fontを含む非loopback browser通信を拒否。
PHPは既存Bootstrap/一般L0と明示L1/session/進捗非自動更新を対象にする。
結果と最終HEAD/CIは本PRとIssue #488へ記録し、実機合格には昇格しない。

既存PHP baselineは別範囲：onboarding120成功/2失敗/7エラーと、
[全体比較64失敗/253エラー](../2026-10-16/review-packet/baseline-comparison.json)を混ぜない。
[既存分類](../2026-10-16/review-packet/initial-production-eligibility.md#existing-php-failures-release-relevance)の
公開影響53件中15失敗、SQLite down/schema・G3 journal・fixture等は今回解消したと主張しない。
L1で到達する未分類失敗は公開ゲート。新しい26件/283 assertions成功は対象範囲のみ。
#798のActions PHP warning（missing .env等）、Node20/DEP0040も過去証拠として維持し、今回の失敗と混同しない。

### C-Light：12月1日までの必須 / 任意 / 公開後

| 体験 | production15abdc9dとe858の既存実装 | 公開に必要な判断 |
| --- | --- | --- |
| 目標/期限/現在地の診断 | `PersonalizationBootstrapService`、`FirstRunService`、`RecommendationService`は同blob。診断はself_reported、observed/inferredと分離。e858はSkip先をPlan作成からゼロPlan Overviewへ変更。 | **必須**：一般L0では診断/専用Workspaceは非公開。C-Light全体は明示L1テスターに限定し、一般L0に同体験を約束しない。公開レベル変更は未実行。 |
| 初期Planの提案と編集保存 | deterministicな定型フェーズをdescriptionへprefill。PlanControllerはTaskを自動作成せず、本人保存/重複送信/所有者確認。 | **必須**：フェーズ型提案と明示し、日程/具体Taskの自動生成と宣伝しない。 |
| 最初のAction | Studyは範囲確認等のGETの場合がある。Developmentは空Planから既存builderへ進み、本人のTask JSON入力後に既存Recommendationが候補Taskを選ぶ。 | **必須**：手入力/既存importによる具体Task作成を含む導線で受入。自動Task不要を最小公開条件としてownerが選べる。自動生成を必須とするなら未実装の別スコープなのでHOLD。 |
| 後日再開 | Guest→Account Plan claim、既存login/session、Plan/Task/Guided Execution/Evidence継続。e858のWorkspace/Account UI変更は合成検証と実機を分ける。 | **必須**：旧account・選択L1・他人拒否・保存/再開を実Web/iPhoneで確認。ゲスト診断context自体の永続claimや全Study導線の成功まで本E2Eで証明しない。 |
| 改良 | よりよいseed説明、手動Task入力の摩擦低減、利用者の理解度測定。 | **任意**。新規生成/大規模UIは今回実装しない。 |
| 拡張 | 自動Task/日程、AP本人ChatGPT Read、B/C/D、Watch、G3/外部provider、課金、未監修AP問題。 | **公開後/別審査**。C-Lightに不要なAuth0/MCP実連携を12月1日の必須ゲートにしない。 |

### 残る本番ゲートと最小操作票（未承認・未実行）

PASSは上表のsource/synthetic/Mac限定。以下はe858を監査対象のまま保持する。
**実Aiven再接続、env保存、再起動、writer停止、DDL、昇格、deploy、公開ONはこの票で承認されない。**

| 順番 / 状態 | 目的・対象 / 前提 | オーナー操作（次の1段階だけ）・期待結果 / STOP / 共有可 |
| --- | --- | --- |
| 0 / UNVERIFIED | Web `srv-dagfn2id0e5s73cac7ng` とStatic `srv-dahn5k142hec739l0fmg`、My Workspaceの現状。 | Dashboardで**閲覧のみ**：service ID、Live SHA、branch、Auto Deploy、確認時刻を確認。envを保存/再起動しない。相違/不明ならSTOP。非機密5項目だけ共有。これで初めてmain PR統合の副作用を再評価できる。 |
| 1 / BLOCK | e858物理監査への実接続経路、名前差例外、trigger可視性/DDL権限/binlog/新設名衝突。 | #795〜#797は`--collect`常時拒否、承認真正性・実TLS・耐久one-shot未実装。Mac再実行で解決しない。**別承認前に**、DBAが既存最小権限・TLS検証を保つ実測手段と固定sourceのsecurity reviewを選定。実測承認後もstrict BLOCKを自動PASSにせず、人間の例外審査は別記録。出力は固定分類/件数/対象SHAのみ。不可視/未知/部分DDLならSTOP。 |
| 2 / UNVERIFIED | 本番backupと隔離restore、旧writer/APP_KEY/session/private storage、RPO/RTO/費用。 | まずAiven Dashboardで復元手段・復旧先・費用を**閲覧だけ**確認。課金/fork/backup取得を押さない。別承認で隔離復元と合成受入を計画。復旧できない/費用未許可ならSTOP。共有は可能/不可/未検証、日時、RPO/RTO、費用有無のみ。 |
| 3 / NOT_RUN | 監査・復旧合格後に全writer停止→固定20 DDL→114物理/ledger確認。 | Web/worker/scheduler全停止とconsistent recovery pointを別承認。承認したsource/runtime SHAに限り一度適用。通常webはcheckのまま。失敗はtrafficを閉じてSTOP、無条件retry/down/ledger改変なし。CIの合成forward修復を本番SQLへ転用しない。 |
| 4 / NOT_RUN | production昇格、Render branch/env保存/manual deploy、Static独立公開。 | 別GO後に固定release→production **merge commit**、5必須CI。runtimeはmerge SHAを別照合し、applyが必要ならapprovalもruntimeと一致。Auto Deploy OFF、Web/Staticを別受入。候補をmoving mainへ差替えない。 |
| 5 / NOT_RUN | 実機・旧account・L0/L1・Session/CSRF・Plan/Task/履歴・取消/他人拒否。 | 別承認済みの段階受入でdesktop/Safari/iPhone WKWebView/PWAを確認。一般L0＋明示L1を保ち、既存ユーザーの専用画面がL0で閉じる影響を承認。異常なら公開拡大せずSTOP、承認済み復旧手順へ。実ユーザーデータをIssueへ貼らない。 |

10/22中間確認、**10/23までに本番安全性の見通しが立たなければ本番機能拡張を止め学習優先**。
11/10科目A・11/25科目B・12/1先行公開は目標であり、未達ゲートの免除や日付だけによる自動操作はない。
本番GOを求める前に0〜2の可否を判断する。Mac offline受入の再提出は不要。

## 固定範囲・完全在庫

- 開始時main: **`4e3a6e97949602409ea337290c4d4f2052fda998`**。production: **`15abdc9de6f24b5250fb255c579d1aa55facaf1a`**。Render Liveはオーナー/既存証跡の同SHAであり、今回独立再観測していない。
- [完全979行のmanifest](main-source-manifest.tsv) / [要約・20件のmigration/blob](main-source-manifest.json)。追加752・変更227・削除0。完全local Git、API300件制限を不使用。重複可能な9リスク領域＋shared queueは機械分類で、セキュリティ承認ではない。
- manifestは**開始時mainの固定在庫**。このDraftの追加修正はPR差分と各最新headのCI artifact `integration-source-inventory`で別に追跡。main/production・`release/2026-10-16-641e2105`を動かさず、新release候補を採用しない。
- migrationは94→114、追加20。旧94ソースは全件不変。このDraftは未適用の20件目だけを安全修正し、migration追加数は20のまま。別の候補を選ぶ際はこの修正も含む**新しいsource seal / 全差分**を審査し、641e2105の古い監査へ読み替えない。

## 危険変更のレビューと受入境界

| 領域 | 確認した差分・安全条件 / まだ閉じないゲート |
| --- | --- |
| DB/Migration/Trigger | 20件全体・旧94 blobを固定。3つのauthorization/member trigger、active identity uniqueness差替、nullable expiry、Private File、watch/source epochを全schemaで照合。実AivenのDDL principal/trigger visibility/binlog条件は未検証。 |
| Auth/OAuth/Session/CSRF | `config/auth.php`・`config/session.php`・`config/queue.php`は旧Liveから同blob。AuthControllerのStaging専用分岐、Account二重確認削除、User fillableからrelease override除外、bootstrap middlewareは変更あり。APP_KEY/暗号化/session store/cookieを維持。既存[合成session証跡](../2026-10-16/review-packet/README.md)は再利用するが、本番cookie/実機の証明ではない。 |
| MCP/個人Plan | Auth0 JWT経路・独立2client・fresh PKCE同意・subject/owner/Plan/scope再検査。L0/L1はMCP grantを失効しない。共通Account/Developmentの本人限定入場はAuth0側も別検証。APの段階受入は後続の独立PR/packetで追跡。 |
| Learning/Development/Intelligence | 保存Attempt/Task進捗/権限の保持を優先。レビュー/独立再回答、observations、context/watchは別の意味と公開ゲート。外部AI採点を検証済み実績へ格上げしない。実AP教材の人間/権利承認ゼロはそのまま。 |
| Release/Feature/Entitlement | 一般L0＋明示L1本人を提案のまま保持。既存 `ReleaseLevelService` / feature flags / Entitlement / owner認可を再利用。core legacy URLの全廃や全endpoint網羅を主張しない。 |
| Private File/Provider | 新しい5管理table・storage revision・private fenceはOFF状態でもschema移行対象。実ファイル/外部providerに触れず、旧resource metadataは合成行で保持検証。実暗号化ファイル復元・provider連携はowner gate。 |
| UI/Web/WKWebView | 学習レビュー・再回答・Future UIの既存CIをそのSHAの証拠として保持。今回の対象PHP/Actionsと後続合成browserを別記。Mac Safari/iPhone/PWA/WKWebView・読み上げはNOT_RUN。 |
| Composer/Docker/Render/Worker | `firebase/php-jwt ^7.2`追加、lock更新を伴う。PHP8.4/locked Composer installで検証。起動はcheck既定、applyはruntime 40桁SHA一致・一度のみ。全旧writer停止と実効APP_KEY/session/worker/branch設定はowner確認。 |
| Actions/Production protection | main祖先のrelease/*→production、merge commit、5 required statusesを維持。実行SHAのupgrade/startup CIを追加強化し、検査をskip/偽装しない。Rulesetの変更・bootstrap・production PR/mergeは行わない。 |

## 20件のmigration検証

`scripts/ci/integration_mysql_upgrade.py`をB専用の全体検証へ拡張した。旧19件のgate・verifier・fixtureを呼ばず、94件の旧Git定義、独立した新seed、20件の順序適用を使う。

| 対象（順序はmanifestの20行） | 検証・復旧上の要点 |
| --- | --- |
| observation source revision / source epoch（2件） | 旧observationの全既存列・行digest保持。新列の型/null/indexをschema referenceと照合。 |
| Plan/member epoch列＋DB epoch trigger＋member binding trigger（3件） | backfill、raw旧writerによるepoch増加、membership再割当拒否。TRIGGERなしの合成principalはDDLを拒否される。Aiven実権限は独立審査。 |
| recall review provenance（1件） | 旧列・行保持、追加列/型/索引/FKは全体metadata照合。 |
| MCP nullable expiry＋active identity history（2件） | 旧finite/revoked grant、identity、読取監査を保持。active fingerprint backfill・新旧indexを照合。旧writer停止前に旧global uniqueを外さない。down拒否条件は既存MCPテストを併用。 |
| Private File cleanup/fences/intents/generation/write-attempts（5件） | 全table/列/default/index/FK/checkのhashを照合。旧private resource metadataを保持。実blob復元の証明ではない。 |
| operational profiles/targets/manual-service/storage-revision（4件） | 新tableと後続ALTERの全構造を照合。旧データは保持。実バックアップ/RPO/RTOとは別。 |
| study result proposals（1件） | schema作成だけでwriteを有効化しない。write scope/提案flagはOFF。 |
| learning watch persistence（1件、2table） | 空のwatch状態を確認。schema移行で通知/外部呼出し/自動進捗を始めない。 |
| learning source epochs（20件目） | 既存コードは `constrained()->…->unique()` がFK定義へ適用され、Plan一意indexを生成しなかった。列だけの再実行判定も部分DDLを完了扱いできた。column上のunique宣言へ修正し、既存tableはPK・Plan unique・cascade FKが揃わなければ拒否。旧94は未変更。既に旧114が適用された別環境がある場合、このコード差替だけでは直らず、別途重複/物理構造監査と前進修復が必要。 |

使い捨てMySQL **8.0/8.4**で、次の両経路を実行する（結果はexact commitのActions artifact / PRへ記録）。

1. 旧94適用→合成user/Plan/Task/PracticeAttempt/権限/同意/監査/session/private-resourceをseed→**migrations以外の全旧table・全既存列**をPK順digestで封印。
2. 新20件を順序適用。20件目のCREATE TABLEがcommitした直後、FK/uniqueの前に実際の例外を注入。ledger113＋物理残骸を専用gateがBLOCKし、起動checkもpendingで停止。再実行は拒否し、ledger/schemaを変更しない。
3. **その合成状態に限定した明示forward-repair**で欠けたunique/FKを追加→20件目を完了。部分tableのepoch行と全旧tableのdigest保持、114整合・再実行を確認。down/データ削除/自動retryを使わない。
4. DBを別シナリオ用に初期化し、旧94＋同じseed→通常の20件一括適用→旧行保持・再実行を確認。通常経路と修復経路の全schema hashが完全一致することを確認してreference化。
5. その114件の合成DBを別schemaへlogical restoreし、全データ・全列/index/FK・trigger定義・114 ledgerを比較する。新しいPlan unique/FKも復元で保持されることを検証するが、Aiven実バックアップ/復元・RPO/RTOは未達のまま。

特定の例外注入は、全20件の**任意のSQL停止点**を網羅した証明ではない。unknown/途中ledger/物理差分は常にBLOCKし、本番の部分DDLはその状態を確認した個別forward fixまたは整合したbackup＋code復元を選ぶ。CIの修復コードを本番用DDL手順として使わない。

## 専用監査器・最短オーナー受入

実Aivenの固定候補 `c09a8fc718c338f98a4045a4b5f2260c7571fdaa` に対する2区分不一致はBLOCKを維持する。新しい独立Draftの[診断専用操作票](schema-diagnostic-owner-operations.md)は、元のhash/判定を変更せず区分・件数・定義/Collation差を分類する準備であり、再接続承認ではない。

#791の後続HEADは、オーナー実測のindex/FK識別子差を引き継ぎ、[名前と定義の意味比較・20 migration名前依存の操作票](constraint-semantics-owner-operations.md)を追加する。旧toolingの承認・Mac受入を新版へ流用しない。名前だけでもstrict BLOCK、移行互換性は別ゲート。

- `scripts/ci/integrated_release20_audit.py`: networkなし。旧94/候補114のGit migration seal、ledger、metadataを、両版で生成・Gitレビューしたreferenceと照合。未知履歴・途中適用・型/default/index/FK/check/trigger差分はBLOCK。全一致でも **REVIEW_REQUIRED / release_authorized=false**。
- `scripts/ops/integrated_release20_collect.py`: **既定OFFLINE**。HEAD/clean tree/full history、監査SQL/実行コード/referenceのGit blobを実ファイルと検証。ignored/assume-unchangedによるSQL差替もblob検査で拒否。credential環境overrideを拒否。`--collect`は別のowner承認、対象確認、採用済みrelease refのSHA一致/main祖先、既存login-path、VERIFY_IDENTITY＋CA、narrow SHOW GRANTSを必須にする。CIから実接続は禁止。
- SQLはinformation_schemaとmigration ledgerのみ。生metadata・grants・client errorは0700一時領域で扱い、成功/失敗とも削除し、画面へ固定code/countだけ返す。実データ・秘密値をログ/GitHubへ渡さない。
- REFERENCESだけではTRIGGER metadataが不可視になり得る。欠落と不可視を区別できなければBLOCK。94一致時もtrigger不在の独立証明・実DDL principalの権限/binlog policyは未達として残す。監査ユーザーへTRIGGER/adminを足して通さない。

**次にオーナーが実行できるのは、採用ではなくGitHubとローカルのソース確認だけ。** PR最新headの全CI・レビュー後に、別途main統合とrelease SHA採用を判断する。その前の実Aiven再監査は開始しない。

| 段階 | 1ステップずつ行う操作 / 次へ進める条件 |
| --- | --- |
| 0 ソースだけ | レビューするDraft PRのexact headを別のclean checkoutへ取得し、`python3 scripts/ops/integrated_release20_collect.py --source-sha <その40桁SHA>`。`OFFLINE_SOURCE20_READY_NO_CONNECTION`はツール準備だけで、DB接続/候補採用の許可ではない。`BLOCK_SOURCE_SHA`になった旧94フォルダを削除/force-resetしない。 |
| 1 候補採用 | CI全件・全差分・AP安全修正をレビュー後、ownerがmain統合と新固定候補を判断。採用したmain祖先のrelease refを固定し、ローカルへ取得。641e2105やmoving mainへ監査を黙って差替えない。 |
| 2 実Aiven SELECT（別承認） | 私的に `CANOVIA_P0_LOGIN_PATH` / `CANOVIA_P0_DATABASE` / `CANOVIA_P0_CA_FILE`を既存設定から確認。`CANOVIA_RELEASE20_RELEASE_REF`を採用ref、`CANOVIA_RELEASE20_OPERATOR_APPROVED=YES`と`CANOVIA_P0_TARGET_VERIFIED=YES`をその承認後だけ指定し、同じsource SHAで`--collect`を追加。**現時点では実行しない**。 |
| 3 判定 | 旧94と新114のreferenceに対する`EXACT94_PENDING20_METADATA_REVIEW_ONLY`を期待するが、旧94の既存受入を代用しない。BLOCKなら停止。trigger可視性・migration principal・managed binlog policyは別の承認済みowner/DBA確認。 |
| 4 backup / 復元 | Full記録だけではPASSにしない。別承認の隔離復元でDB/APP_KEY/ファイル/認証/同意・監査保持とRPO/RTOを確認。Freeで実復元できない/有料forkが必要なら、実行可能な復旧先・方法・費用と未実証リスクをowner審査しHOLD。 |
| 5 writer fence / DDL | Web/worker/queue/旧instanceの全writer停止を確認し、状態別回復案・承認SHAを確定。DDLは別GO。失敗時停止、無条件retry/down・履歴削除なし。物理構造と114 ledger両方の合格前にtrafficを開かない。 |
| 6 昇格 / Render | 別GOでrelease/*→production、5必須CI、merge commit。Renderのbranch設定変更・env保存（deploy誘発）・specific-commit手動deployは各々別承認、Auto Deploy OFF。runtime merge SHAとDDL承認SHAを照合。通常起動check。 |
| 7 端末 / AP OAuth | Web/iPhoneの既存ログイン/Plan/Task/学習履歴/CSRFを受入後、独立2client/Auth0本人一致→本人overviewのみ→別同意でtasks→approved summary→learning_context。各段階で別人/別Plan/取消後拒否。write/一般公開/課金はOFF。 |

本票をコード配備・本番リリース・実データreadの承認にしない。実復元、実Auth0 token/subject/client、実機、本番20件監査、費用、production/Render/IdP操作の個別GOは **NOT_RUN / HOLD**。旧94台帳・P0の実測はowner提出の限定的REAL_ACCEPTED、今回のMySQL/SQLiteはSYNTHETIC_TESTEDとして分離する。

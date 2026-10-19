# B案：main統合リリース / Draft・NO-GO

## 統合後mainの独立readiness審査（2026-10-19）

[#791〜#793統合済み固定main e858の監査・承認ゲート・実Aiven診断計画・復旧票](readiness-e858/release-candidate-readiness-audit.md)。指定post-merge CIは3本13 jobs成功。候補正式採用、新source監査セット、実環境操作は未承認でproduction **NO-GO/HOLD**。以下の開始時SHA・旧候補・Draft記述は当時の履歴として保持し、新mainの採用証拠へ読み替えない。

2026-10-18 JST。正本: [#787](https://github.com/1kz-ma1/Canovia-web/pull/787)、[#488](https://github.com/1kz-ma1/Canovia-web/issues/488)、[統合マスター](../../CANOVIA_INTEGRATION_FREEZE_RELEASE_MASTER_2026_10_16.md)、最新AGENTS/[workflow](../../../CANOVIA_DEVELOPMENT_WORKFLOW.md)。旧A案 #785/#786 は比較資料として保持し、merge/cherry-pick/closeしない。

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

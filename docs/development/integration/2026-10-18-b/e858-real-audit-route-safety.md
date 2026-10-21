# e858 実metadata監査経路：差分と停止点（2026-10-20）

**TECHNICAL_WORK_REMAINING / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO_HOLD**。
正本は[Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488)。これは実接続の承認票・実行CLIではない。

## 2026-10-20 オーナー採用決定：B案・人間監督付き単発metadata監査

**決定：B方式をこの1回のe858監査に向けた設計ベースラインとして採用（2026-10-20、ownerの対話による明示決定）。A方式の自動・独立署名authority／durable atomic nonce消費は将来設計として保留。今回の決定は実接続・監査実行・既存コードガードの解除・本番移行の承認ではない。**

本節は下記の「A/Bいずれも未採用」「次はdurable trust store選定」などの**過去時点の比較・保留記述に優先する**。履歴は変更せず残す。下記のsource/SQL/reference厳密性、最小権限、実MySQL物理・trigger検証、metadata-only、production NO-GO等の停止条件は維持する。

### Bの適用範囲と保証の差

| 区分 | Bでの要求 | 判定 |
| --- | --- | --- |
| 適用対象 | 固定e858 source SHA、固定release、個別に確定されたtooling/SQL/reference、Aivenの既存1サービス・1DB・既存限定監査principal、1回のmetadata-only読取 | **設計方針採用**。実target/tooling/operator bindingは未受入 |
| 本人と明示承認 | owner兼operatorが私的に対象・read scope・影響・開始時刻・終了期限を確認し、その実行1回に対して**接続直前に改めて明示承認**。過去の「進めて」や本節採用・GitHubコメント・CIは実監査承認ではない | 手順未受入／実行未承認 |
| 再試行・実行済管理 | 自動発行・再利用tokenは設けない。private operator receiptに試行ID・実行開始・終了/中断・再実行不可を記録。異常・接続失敗・タイムアウト・端末再起動後はその許可を終了扱いとし、再実行には**別の新規承認**が必須 | **手続上の再実行防止**。ホスト複製・rollback・意図的なリプレイを技術的に不可能にする保証はない。旧atomic one-shotと同等と表現しない |
| ターゲット・TLS | ownerが既存Aiven Consoleと接続先・CA・監査principalを私的に独立照合。VERIFY_IDENTITY/ホスト名・証明書・期待DB/current user/server識別子とセッション整合を検証。fallbackやTLS弱化なし | 実toolingへの接続・session整合実装が未検証。STOP |
| 取得データ | 既存allowlist Q0/Q1、Laravel migration ledger、information_schemaの物理metadataのみ。Plan/Task/Account行、実dump、自由SQL、DDL/GRANT、権限追加なし | SQL/referenceと上限制約は旧監査契約から緩和しない |
| 検証と証跡 | 実収集先はMacのGit・クラウド同期対象外のprivate領域にのみ、一時生データは限時削除。公開IssueにはSHA・対象匿名分類・固定判定／カウンタ・実行日時・中断コードだけを出す | 生のDB名/host/query/error/grants/PII/token/keyを公開しない |

**残留リスクの明示**：BはAの独立発行署名・失効照会・復元後にも破れないone-shot ledgerを**置き換えず、短期の人間監督により運用リスクを限定する再設計**。悪意あるローカル管理者や端末の巻戻し等への強い技術保証がない点は、本番接続前にownerへ別途具体的に提示して受入判断を求める。Bを選択しただけでは旧ゲートのBLOCKをPASSにしない。

### 1回のオーナー実監査の審査フロー（この文書は実行票ではない）

1. **B0 方針採用（今回）**：Bの運用方針のみ採用。元の固定監査sourceは `e858b9c855970fbe73f37a2d60c9db6525b132d6`、releaseは `release/2026-10-19-e858b9c8`。toolingや実接続先をここでは採用しない。
2. **B1 オフライン準備と別レビュー**：最新mainと固定candidateの差を確認。固定監査tooling #795→#797の現HEAD/ソース固定/SQL hash/reference hash/クリーンcheckoutを再照合。B用の一操作票・実行後receipt、ログ非漏えい、strict mismatch分類、停止条件、Mac実session/TLS・query kill/timeout設計をレビュー。旧synthetic Mac受入の再実行を無目的に要求しない。実接続可能なCLI・credential loaderは安全性検証なしに実装しない。
3. **B2 接続前個別GO**：operator/ownerを特定し、対象Aiven service・DB・endpoint/port/CA・login-path・現在の最小権限（強化なし）を**private**に照合。固定source/tooling/SQL/reference hashと実行対象・1回の時刻window・許されるSQLの範囲、再試行不可条件を記したオーナー操作票に対して別途明示GOを得る。未確定・古い承認・時間切れ・別対象ならSTOP。
4. **B3 実行（まだNOT_RUN）**：認可operatorが指定Macから一度だけ、実toolingのfail-closedなTLS・Q0/Q1・metadata read・postflight・出力上限を監督。TLS/identity/grants/ledger/strict判定/remote query停止が不明なら中止し、接続し直すときも再GOを要求。取得した生metadataは共有しない。旧実監査collectorにBを示す環境変数を足しただけで許可を通さない。
5. **B4 実行後**：rawのprivate削除確認、source/tooling/SQL hashと限定判定の公開記録、INDEX/FK名前差・新設衝突・M08・TRIGGER完全可視性の独立DBA証拠・migration principal/binlogの未達を分類する。read-only診断のPASSはDDL/backup復元/production merge/deploy/一般公開を許可しない。

### Codexへ任せる次のオフライン作業（優先度順）

- 既存監査仕様とBの差分を短いoperator操作票／receipt templateに落とし込み、**実接続を起動せず**対象とscope・GO・STOPをチェックできる形式へ整理する。作る場合は識別子・秘密を記録しない合成fixtureのみで検証する。
- 実認証なし・旧固定source guard/SQL/referenceを改造しない条件下で、事故防止の負例（別source、古い承認、対象不明、再実行要求、TLS弱化、過剰grants、通信中断、raw漏えい等）を合成テストで確認する。再実行の人的抑止は暗号的proofと誤称しない。
- 実Aivenにつなぐ運用経路がBに適合するか**レビューして差分だけ**明示する。必要ならレビュー済み固定toolingの新候補を別PRで準備し、接続動作は明示GOまで無効のままとする。

**現状態**：`B_MANUAL_SINGLE_RUN_DESIGN_ADOPTED` / `B_OPERATOR_PACKET_NOT_ACCEPTED` / `REAL_OPERATOR_GO_NOT_GRANTED` / `REAL_AIVEN_METADATA_NOT_RUN` / `PRODUCTION_NO_GO_HOLD`。AはFuture Design。Auth0/MCP・Render session Key Value・MCP staging Postgresに監査承認権限を追加しない。将来Aへ移る際は別の承認・設計変更が必要。

### B1 オフライン操作票・合成結果票の実装（2026-10-20）

`scripts/ci/e858_b_offline.py` は**ローカルGitの読取と匿名チェック票の検査のみ**。実client/credential loader/GO発行/実行スイッチはない。`execution_allowed=false`、`owner_approval_verified=false`、`fresh_owner_go_required=true` を常に出す。チェック完了も実行許可・本人/対象の実証ではない。

| 操作票 | 確認内容・停止条件 |
| --- | --- |
| B0 固定対象 | source `e858b9c855970fbe73f37a2d60c9db6525b132d6`、release `release/2026-10-19-e858b9c8`。checker候補SHAはPR/Issueに固定し、`--tooling-sha` と実HEADが一致すること。#795 be89af9a → #796 5e63b7db → #797 9f7d248e は別の固定依存。最新mainを監査sourceへ置換しない |
| B0 SQL/Reference | #797の固定blobからliteral manifestを読み、#795の14 SQL/17順序と両referenceのhashを再照合。source tree/seal、旧94/全114定義も既存source関数で照合。SQL/reference/strict/上限の変更不可 |
| B1 実行環境 | 管理Mac、Python3.9以上、full-historyのclean checkout、正常なtracked bytes/実行mode、固定remote-tracking refs。これらのprivate照合の宣言を検査する。OS本人確認・端末管理の真偽は検証しない |
| B1 本人/対象 | owner兼operator、既存1 service/1 DB/期待DB・CURRENT_USER・version/UUID・CA/hostnameをConsole/既存認可DBA証拠と私的に照合。checkerには実値を入れず匿名宣言のみ。未確定/未知operatorならSTOP |
| B1 scope/権限 | 既存metadata allowlist（identity/own grants/ledger/information_schema）だけ。REFERENCES schema＋SELECT ledger＋任意USAGEを維持。実ユーザー行、自由SQL、DDL/GRANT、dump不可。不足・過剰/未知の実効権限はSTOP。TRIGGER完全可視性は既存DBAの別証拠で、inspectorの拡権なし |
| B1 時間/保管 | 開始UTC/終了期限と120秒以内の予定窓、private試行IDを記入。Git/クラウド同期外0700領域、raw0600/限時削除・crash残骸確認・私的な責任者を確認。識別子やrawをcheckerへ入力しない。時計の真正性・完全消去は検証しない |
| B2 個別承認 | B0/B1と実transportの安全レビュー後に、固定対象/実行者/metadata-only scope/時刻窓/1回/再試行不可をオーナーが私的に確認し、接続直前の別GO。設計採用、合成review宣言、CI/JSON/環境変数/commentはGOではない。今は実GOを求めない |
| B3 STOP | pin/対象/operator/権限/TLS差、別session/再接続、query10s/stream120s、8MiB/16MiB、row256KiB/cell128KiB/行数上限超過、raw漏えい、SQL error/未知構造、query/接続終了不明で停止。TLS弱化/fallback/自動retryなし。remote終了保証は未実装・未検証でSTOPを残す |
| B4 結果/終了 | 固定source/tooling/SQL/ref整合、匿名target分類、開始/終了UTC、固定成功/BLOCK/UNVERIFIED分類・件数、cleanup、再承認要否のみを共有。実測のない時刻/件数/cleanupはNOT_RUN/未記録。DB名/host/credential/token/CA内容/生SQL出力/詳細error/個人行を共有しない。成功でもrelease GOにしない |

**匿名操作票の使用（無接続）**：clean checkoutで `python3 -I -B scripts/ci/e858_b_offline.py --tooling-sha <PRに固定したchecker HEAD> --template`。stdoutの `template` 部分だけをprivate領域へ保存し、`NOT_REVIEWED` は実際の私的確認後にのみ所定宣言へ変える。`attempt_id` は非秘密の32桁hex試行ID、`starts_at`/`ends_at` はUTC epoch整数。raw target/本人情報や秘密を追記してはならない。templateの自動生成は確認完了を意味しない。

`--packet <private JSON>` で形式と不足を検査し、匿名の **SYNTHETIC_RECEIPT** を出す。入力は#801の16KiB・regular file・nonblocking・最終symlink拒否を再利用し、repo内入力/duplicate key/未知field/float/巨大/期限切れも拒否。出力は固定分類のみ。独自HMACや署名authorityを作らない。tooling/SQL/ref照合はオフラインだけの証拠で、remote refの最新性や実TLSを証明しない。

**再実行の境界**：`prior_attempt` がNOT_RUN以外、retry要求、`--prior-receipt` の再利用はBLOCK。合成COMPLETED/BLOCK/INTERRUPTEDのreceiptも実時刻・実件数・cleanupを捏造せず、実状態はNOT_RUNのまま。receiptが消失/省略/複製/rollbackされた場合を技術的に防止しない。オフライン再検査を繰り返しても実許可は発生しない。実操作ではownerがprivate試行台帳へ開始と終了/失敗/中断を記録し、失敗・再起動・再接続を含め**同じGOで再開せず新しい個別承認**を必要とする。

**既存証拠の再利用**：#801入力20件と、新しいBのsource/SQL/ref改変、未確認/期限切れ、対象/operator不明、再試行/中断、TLS弱化/過剰権限、file/CLI秘匿負例をLinux3.9/3.12・macOS3.12で実施。#795〜#797の受入とMySQL8.0/8.4各38ケース、#800の独立論理復元は再構築せず参照する。今回の変更はDB・SQL・runtimeに達しない。

**Bの次の技術課題は1件**：固定allowlist/limitsを維持する、同一VERIFY_IDENTITY TLS session内のQ0/Q1→metadata→postflightと異常時remote query/接続終了の検証設計。#796の別client方式やlocal kill成功だけを実session/remote停止の証拠にしない。実接続機能を有効化せず、local fake/隔離環境で先にレビュー可能な設計と負例を用意する。現段階のowner操作はB0〜B4と手続上の再実行保証差の**無接続レビュー1件のみ**。A案authority/store選定をBの必須条件として再要求しない。

### B 隔離TLS transportとremote終了の実証（2026-10-20、実Aivenは対象外）

本節はB1末尾の「次の技術課題」の実装差分。`scripts/ci/e858_tls_host.py` はMySQL系列とclean tooling HEADだけを受け付け、**自分で作成したinternal Docker networkの固定合成hostだけ**へ通信する。endpoint/port/credential/CA入力、実行許可スイッチ、実Aiven connectorはない。#804のオフラインcheckerと旧監査guardは変更しない。

- hostは毎回テスト専用CA・SAN付きserver証明書・期限切れ証明書を作り、秘密鍵はserver側だけへmount。workerにはpublic CA・固定Git blobのSQL・匿名manifestだけを渡す。コンテナは公開portなし、単一internal network、workerはread-only filesystemでDocker socket/host credentialを持たない。依存wheelはhost側TLSで取得して固定SHAを照合し、image内ではnetworkなしでinstallする。認証情報は合成固定値のみ。
- PyMySQL 1.1.2のSSLContextはCERT_REQUIRED＋hostname検証＋TLS1.2以上。serverのSSL capabilityが無ければpassword送信前に拒否。暗黙/明示の2回目connectを拒否し、同一connection object/socket/CONNECTION_IDでQ0→Q1→固定17処理→postflightを行う。server UUIDやconnection IDだけをTLS証明にしない。
- metadata SQLは#795の14 blobを無変更で再利用。前後の固定 `SELECT CONNECTION_ID()` と `SET SESSION max_execution_time=10000` は**隔離fixture envelope内の補助SQL**であり、旧bundleへ混ぜない。将来の実運用で制御/観測SQLを使うには、対象・追加接続・操作scopeの別審査が必要。
- query10秒/収集120秒、cell128KiB/row256KiB/section8MiB/total16MiBと旧行capを維持。パケットを読む前にもrow/total上限を適用する。タイマーは所有するTCP descriptorの複製をshutdownし、SSL読取中のSSLオブジェクトを別threadから変更せず新規収集を止め、結果を破棄して再実行を拒否する。テストでは0.25秒/0.12秒へ短縮し、上限増加を拒否する。緊急終了確認は別のbounded制御処理で、収集期限の延長やmetadata再取得ではない。

**終了の証拠**：クライアント例外/close/SIGKILLだけではremote終了にしない。独立したspawnプロセス内の合成root oracleがserver UUIDとperformance_schemaの対象session有無・実行状態を観測する。終了要求(KILL QUERY/KILL CONNECTION)の失敗フラグと、query終了・connection消滅の観測を分ける。観測失敗・observer切断・権限不足・connection残存は `REMOTE_TERMINATION_UNVERIFIED`。後続のテスト管理cleanupに成功しても、元のUNVERIFIEDをPASSへ戻さない。失敗したSessionは再利用不可で、別Sessionの作成を本物の新承認と扱わない。

**権限境界**：管理rootは隔離環境の試験oracle/合成DDL/最後の掃除専用であり、本番inspectorへPROCESS/CONNECTION_ADMIN等を要求・GRANTしない。REFERENCES schema＋SELECT ledgerだけの同一principal別接続による自身sessionの観測/停止も別fixtureで評価する。別principalに対するKILL権限拒否と、見えないsessionを「不在」と誤認しない負例を含める。実Aivenでの可視性・KILL可否・proxy/運用制約は未検証で、合成MySQLの標準動作から推定しない。

**試験範囲の限界**：3テーブルと94 migration名のledgerはTLS metadata取込用の最小構造fixtureで、旧94→新114の再リハーサルではない。既存referenceとのstrict比較は意図的にBLOCKを要求する。TRIGGER不可視性は解消しない。移行/名前差/独立logical restoreの証拠は既存CIを再利用する。

**MySQL timeoutの限界**：`max_execution_time` は主にread-only SELECTのstatement制限であり、SHOW/SET・全SQL・connection寿命を一括保証しない。長時間SELECTのerror3024と、その後のconnection残存/別途終了を検証する。通信断やclient死亡時は、サーバー側の実状態を独立観測しない限り終了扱いしない。接続timeout fixtureはTCP確立後のMySQL greeting無応答を対象とし、実ネットワークのSYN timeoutやAiven障害の再現とはしない。

CIで既存 `StudyAdaptiveActionV536Test` の1件がJSTの日付境界後に失敗した。固定base mainと作業HEADの両方で同一assertionを再現した。Carbonの固定時刻がnative `DateTimeImmutable()` に伝わらないため、当該テストの直接evaluateだけに既存のcapturedAt引数 `now()` を明示する。アプリの期限判定・HTTP処理・assertionは変更せず、skip/期待値緩和も行わない。

検証状態・各engineの件数・CI runと独立tooling SHAはPRとIssue #488へ記録する。合成実通信に成功した場合のレベルは `ISOLATED_MYSQL_TLS_TESTED` / `ISOLATED_REMOTE_TERMINATION_TESTED`。**常に `REAL_AIVEN_UNVERIFIED` / `REAL_OPERATOR_GO_NOT_GRANTED` / `PRODUCTION_NO_GO_HOLD`**。

**実監査までの残作業（接続依頼ではない）**：実principalのまま利用できる独立した同一対象の終了観測/制御経路、control channel故障時の既存認可operatorへの引継ぎ、private対象/CA/本人binding、管理Macでの実行・中断挙動を別審査する。追加control接続やKILLを旧metadata-only承認へ暗黙に含めない。既存権限で成立しない場合はSTOPし、拡権を自動解決策にしない。本番経路は未実装のまま閉じる。B0〜B4の無接続レビューと、実SQL一回の個別GOは分離する。

## 2026-10-21 B実Aiven監査前・無接続停止経路レビュー

**範囲と証拠**：2026-10-21、オーナー提供Mac結果はMySQL 8.0.46・8.4.11の合成隔離31/31ケースずつ `ISOLATED_SYNTHETIC_VERIFIED`、後片付け・残存session0、fixed tooling `68768e08cc198898a27e1e497d7c021ddd6367de`。正本は[Issue #488のMac受入記録](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6305069582)。これは提供JSONの検査結果であって、owner端末への独立アクセス・実Aiven上の受入ではない。本節ではGitHub資料と公開の[Aiven Current queries手順](https://aiven.io/docs/products/mysql/howto/mysql-long-running-queries)、[MySQL 8.4 KILL仕様](https://dev.mysql.com/doc/refman/8.4/en/kill.html)を無接続で照合した。Aiven Consoleログイン、Aiven API/SQL、production/Render/Auth0変更なし。

### 結論：最小権限を維持したまま、観測経路を2系統として扱う

| 経路 | 既知の仕様と役割 | 実Aivenの判定・制約 |
| --- | --- | --- |
| **第1候補：既存inspectorと同じprincipalの独立control session** | MySQLの標準仕様ではPROCESSなしで自己sessionが見え、CONNECTION_ADMINなしで自己sessionのKILL QUERY/CONNECTIONを要求可能。#806隔離8.0/8.4で同一minimal principalによる観測・停止を合成実証済み。 | **UNVERIFIED**：実Aivenのuser/host同一視、接続上限、proxy/経路、performance_schema/PROCESSLIST visibility、自分のsessionの帰属、KILL拒否・failover・終了観測を証明しない。単独のtest結果で実privilegeをPASSにしない。 |
| **補助手段：Aiven ConsoleのObserve → Current queries** | Aiven公式は長時間queryの表示とTerminate UIを案内する。owner/既存認可DBAの介入経路候補。 | **UNVERIFIED**：現service/plan/roleで表示・操作可能か、停止対象を同一sessionと識別できるか、実connectionが消滅したかは未確認。`Terminate`を押せることやUIの空表示だけで`REMOTE_TERMINATION_VERIFIED`に昇格しない。 |
| **双方不能・障害・異なる観測** | 認可operatorがoperationを隔離し、既存Aiven管理責任者へprivateに引き継ぐ。 | **BLOCK / REMOTE_TERMINATION_UNVERIFIED**：同じGOで再接続・再監査しない。primary/control両方失われた場合に継続query終了を保証したとしない。 |

### B0/B1 実行前の必要条件（ここでは実操作・確認を実施しない）

1. **private target binding**：固定e858 source/releaseとtooling・SQL/reference/clean checkoutを照合した後、既存Aiven Consoleの特定の1 service/1 DB、endpoint/port、検証するCA chainとhostname/SAN、現在のinspectorの最小grants、`CURRENT_USER()`、engine/version/server identity、時刻窓、operatorをオーナーが非公開で照合。実値・権限出力をGitHubへ貼らない。未知のserver UUIDを採取する接続は別途許可が必要。`main`や合成toolingを実Aiven用connectorへ自動転用しない。
2. **独立観測・停止の最小scope審査**：observerはcollectorとは別セッションかつ同じ認証済みtarget、同じ既存minimal principalを第一候補とする。ただし新たな`PROCESS`、`CONNECTION_ADMIN`、`TRIGGER`、全DB `SELECT`、admin/user生成はしない。観測のための追加接続と、異常時の`KILL QUERY/CONNECTION`は**metadata-only readとは別のcontrol操作**なので、既存Q0/Q1/17固定SQLのallowlistへ混ぜず、別途scope/owner GOに明記する。control用SQLの内容・上限・失効・接続障害時の安全性は個別受入までBLOCK。
3. **観測の健全性**：対象sessionのCID、identity、server UUID、観測権限・対象帰属が開始前に成立することを確かめる。`KILL`の受理だけで成功とせず、同一server上で対象query終了とconnection消滅を区別して確認。見えないCID、古いCID、CID再利用・別ユーザー対象、observer途中切断・誤service観測は不明としてBLOCK。Console UIやprocesslistが空であることだけで『終了した』とはしない。
4. **端末/停止経路の限界**：Mac Docker隔離でSIGKILL・通信断・timeoutを合成受入したが、Mac全停止・controlとdata双方断・Aiven failover/プロキシ切替・管理画面停止時に遠隔処理を確実に終了させる保証はない。MySQL `KILL`は終了flagで非同期に進む。server `max_execution_time`も全SQL・接続寿命を保証しない。残余リスクとfail-closed中断の責任者を明記し、オーナーが別途受入するまで実Aiven GOは禁止。
5. **個別GOの境界**：今回の『進もう』は無接続レビューの承認のみ。監査対象、二次control接続、限定KILL、実行者、time window、1回限定、失敗時停止、private結果処理を確定した別の操作票への**実行直前の新しいGO**が必要。新規接続・KILLは技術準備なしに実行しない。A案のdurable nonce/独立authorityを追加しない代わりに、手続による再実行防止の弱さを隠さない。

### 次の最小オーナー確認（無接続）

オーナーが**既存Aiven Consoleで対象MySQLサービスの管理権限を持ち、`Observe → Current queries`で緊急操作を担当できるか**を、まず権限・責任分担として「担当可能／別の既存管理者が担当／不明」の1つで回答する。実サービスの現在のquery一覧を開く、Terminateする、実SQLを試す、資格情報やhostを共有する必要はない。これは停止手段の候補選定にすぎず、実Aivenで使用可能な証拠とはしない。詳細未確認の場合は`UNVERIFIED`を維持。

**判定**：`OWNER_MAC_ISOLATED_TRANSPORT_ACCEPTED_FROM_USER_OUTPUT` / `REAL_AIVEN_OBSERVER_AND_KILL_UNVERIFIED` / `CONSOLE_TERMINATION_CAPABILITY_UNVERIFIED` / `B_OWNER_OPERATOR_PACKET_UNACCEPTED` / `REAL_OPERATOR_GO_NOT_GRANTED` / `REAL_AIVEN_METADATA_NOT_RUN` / `PRODUCTION_NO_GO_HOLD`。追加tooling・migration/DDL・GRANT・backup/restore・実接続・有料resource操作なし。

## 2026-10-21 B1 単発監査・無接続最終操作票（オーナー用）

> **この表は安全条件の確認票であり、実接続・KILL・公開・本番移行の許可ではない。** 運用責任者はオーナー本人（本人申告）。本人が提供したAiven Console画面で `Current queries` の一覧と `Terminate` ボタンの**表示のみ**確認。実権限・停止結果・同一対象のserver-side証明は未検証。画像・接続元IP・service/DB名・接続IDをpublic GitHub/ChatGPTへ転記しない。対象の実識別子や秘密はオーナーの非同期・非共有の私的記録のみ。

| B1確認欄 | オーナーが接続前に私的に照合する内容 | この時点の判定 |
| --- | --- | --- |
| **B0 固定監査資産** | 監査対象は固定source/release `e858b9c855970fbe73f37a2d60c9db6525b132d6`。#795 `be89af9a`→#796 `5e63b7db`→#797 `9f7d248e`の14固定SQL・17実行・2 reference、caps・strict BLOCKを維持。#804 checker・#806隔離transportは実collectorの認可ではなく、実接続に使う**独立toolingの確定SHA/レビュー**は別。最新mainの変更を黙って監査sourceへ混入しない | **固定source/SQL/refはofflineで検証済み／実tooling未採用** |
| **B1 operator・対象** | owner=operator（申告確認）。既存Aiven Consoleで、私的に限定した**1 service／1 DB**のregion/endpoint/port/expected host/SAN/CA fingerprintとログインidentity・engineを照合。実値はpublicに出さない。事前に未知の`CURRENT_USER()`やUUIDを得るためにも**無承認の接続はしない** | **管理担当申告済み／private binding・実identity未確認** |
| **B1 限定権限** | 既存metadata inspectorの実効権限が既存基準（REFERENCES schema／SELECT migration ledger／任意USAGE）を超えず不足もないか。role/mandatory role・default schema・TRIGGER不可視性は独立確認。不明ならSTOP。ユーザー行・dump・DDL・GRANT・全DB SELECTは対象外 | **実権限UNVERIFIED** |
| **B1 data session** | VERIFY_IDENTITYの単一TLS接続でQ0/Q1→allowlist固定metadata→postflight、暗黙再接続禁止。query10秒／全体120秒、cell128KiB・row256KiB・section8MiB・total16MiB等の既存上限を維持。#806のMac両系列31/31隔離実測は本番TLS/Proxyの保証ではない | **Mac合成受入済み／実Aiven未検証** |
| **B1 control session** | metadata readとは別の**監視・停止操作scope**として、同一対象の既存inspector自身のsessionを別TLS接続で観測できるか・自身のCIDだけに限定しKILL QUERY/CONNECTIONできるか、scope/SQL/timeout/監督者を事前レビュー。追加`PROCESS`/`CONNECTION_ADMIN`/`TRIGGER`はしない。同一Macの別接続だけではhost・network全障害への耐性はない | **合成確認済み／実Aiven観測・停止経路はBLOCK** |
| **B1 emergency fallback** | ownerのConsoleにCurrent queriesとTerminateの表示あり。ただし実操作なし。使う場合は対象CID・DB/host/ownerの照合、UIの権限・停止結果・接続消滅の別観測が必要。画面が空になることやKILL受理を終了証明にしない。対象不明・observer断・Console障害なら`REMOTE_TERMINATION_UNVERIFIED`、同じ承認で再実行しない | **UI表示PASS／実能力UNVERIFIED** |
| **B1 Mac・保管・失敗時** | operator Macのclean/full-history checkout、固定tooling hash・時刻窓、private非同期0700領域とraw0600/限時削除、試行開始/終了/中断を記録。Mac全停止・両control/data断やAiven failoverではremote終了を保証できない残余リスクを別途確認。停止・不明時は既存管理責任者へ私的引継ぎ、復旧としてDDL/GRANTしない | **合成Mac検証済み／実行前受入未完了** |
| **B2 個別GO（現時点では禁止）** | 将来、固定source+採用tooling/SQL/ref、private target identity、実行者、read/controlの分離されたscope、限定KILL、UTC開始/満了、1回限り・自動retry禁止・失敗後再GO・生結果の保管/廃棄・残余リスクを明示して**接続直前に別のオーナー承認**。チェック済みJSON、GitHub comment、B方式採用、CI、現在の「進もう」はGOではない | **NOT_GRANTED** |
| **B3/B4 実行・結果** | 実AivenのSELECT/SHOW、独立観測、停止、sanitize receipt、INDEX/FK名・M08・TRIGGER別証拠、migration principal/binlog・restoreは全て実測前。正確な実証なしにPASSとしない。実監査PASSもproduction/release GOではない | **NOT_RUN** |

**機械的な状態区別**：`B1_REVIEW_SHEET_PREPARED` ≠ `B1_PRIVATE_TARGET_ACCEPTED` ≠ `B2_FRESH_OWNER_GO` ≠ `REAL_AIVEN_AUDIT_COMPLETED`。現状は**最初の状態のみ**。#804のsynthetic receiptは一回限り承認のauthorityでも、実施済みの独立証跡でもない。人的再実行防止はA案のdurable atomic anti-replayと同等ではない。

**次の1件（接続なし）**：オーナーはAiven Consoleの既存MySQLサービスに**監査専用read-only principalが今も管理対象として存在するか**を、設定/UIまたは手元の既存私的運用資料だけで確認し、**「存在を確認／不明／見つからない」**のいずれかのみ返信する。実ユーザー名、接続先、権限一覧、認証情報、query結果を送らない。read-only principalの実効grantsの検証はまだUNVERIFIEDとする。新規user/GRANT、実SQL、Aiven接続を依頼しない。

**維持**：`B1_REVIEW_SHEET_PREPARED` / `REAL_AIVEN_CONTROL_UNVERIFIED` / `B2_OWNER_GO_NOT_GRANTED` / `REAL_AIVEN_METADATA_NOT_RUN` / `PRODUCTION_NO_GO_HOLD`。

## 2026-10-21 B1 既存証拠再利用と再確認禁止の引継ぎ（#809後／接続なし）

> **証拠の分類を維持する。** 本節は既存[Issue #488 10/18証跡再発見](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6305738797)、[B1オーナー確認](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6305648880)、[Full94 TLS #809](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6306272111)と、10/21の後続オーナー自己申告を整理するもの。実Aivenに再接続した証拠ではない。先のB1表の作成時点の未確認事項は消さず、差分だけ追跡する。

| 証拠 | 再利用できる範囲 | なお未証明 |
| --- | --- | --- |
| 10/18 owner報告の `P0_METADATA_GRANTS: ACCEPT_METADATA_ONLY_LEDGER_SELECT` | 当時の `SHOW GRANTS` を固定validatorが受理した履歴。原本の独立再検証ではない | 10/21現在の実効grant、role、principalと対象の同一性 |
| 10/18 owner報告の旧94件 `EXACT_94_APPLIED_REVIEW_ONLY` / pending 0 | 旧本番のmigration ledgerに関する歴史的受入 | e858追加20件の実台帳、物理INDEX/FK/TRIGGER・M08・DDL適合 |
| Aiven Console UI と端末の既存設定（owner申告） | 管理者本人が担当、緊急画面のCurrent queries/Terminateが表示、既存監査principalが存在、Macのlogin-path/CAファイルが存在 | 接続能力、実Terminate権限、独立したremote停止と終了証明 |
| 10/21後続オーナーの**無接続**照合 | 意図する単一DBを選択し、ConsoleとMacのHost/Port/監査Userが一致、既存CAと現在CAのSHA256 fingerprintが一致したとの報告。**秘密や実識別子は記録しない** | hostname/SANと実TLSセッション、server UUID/`CURRENT_USER()`/engine、採用toolingの実接続資格 |
| #809隔離MySQL 8.0/8.4 のFull94 TLS bridge | 固定source/SQL/ref、旧94 strict一致、名前差BLOCK、114 TRIGGER不可視BLOCK、同一TLS・自己session controlと負例の合成受入 | 実Aivenでの観測/停止/対象権限、real connector/real owner GO |

**再確認禁止の原則**：引継ぎでは最初にこの表と#488の証拠を読んで、ユーザーに既存ユーザーの有無、Mac login-path/CAの存在、Host/Port/User、CA fingerprint、旧94監査、旧grants validatorを機械的に再実行させない。証拠の失効・設定変更・新しい具体的不一致を示せる場合のみ、対象と理由を明示して**新しい差分**を確認する。historical PASSをlive PASSへ昇格させず、無期限の免除ともしない。

**次工程（実接続を伴わないB1 control/scope審査）**：既存のB1表を使い、固定e858 source/releaseと実接続用に将来採用するtoolingを区別（現状 **実用tooling未採用**）。operator管理Mac、同一target・同一最小principalの独立control session、自己session CIDだけを監視/停止する範囲、最大時間/取得量、独立した緊急fallback、残余remote終了不確実性、private保管、UTC開始/満了、**1回限り・再接続/自動retry禁止・中断後新承認**を事前審査する。補助Console UIの表示を実停止能力とみなさず、`PROCESS`/`CONNECTION_ADMIN`/`TRIGGER`等を追加しない。主dataとcontrolの操作・承認scopeを混在させない。曖昧ならHOLD。

**承認の切れ目**：B1レビューを整えても `B1_PRIVATE_TARGET_ACCEPTED`、実装済みのlive connector、`B2_FRESH_OWNER_GO` のいずれにもならない。将来の **Q0/Q1/自己session controlだけの限定bootstrap** は別の実行仕様・実行直前の明示GOを要する。**full17実metadata監査はさらに別GO**。TRIGGER独立証拠、migration主体/binlog、実バックアップ復元、migration・デプロイ・実機受入も独立の未達ゲートとする。接続結果やremote終了が不明ならSTOPし、同じGOでは再実行しない。

**維持**：`B1_HISTORICAL_EVIDENCE_REUSED` / `B1_OFFLINE_TARGET_CA_MATCH_OWNER_REPORTED` / `REAL_AIVEN_CONTROL_UNVERIFIED` / `LIVE_TOOLING_NOT_ADOPTED` / `B2_OWNER_GO_NOT_GRANTED` / `REAL_AIVEN_E858_NOT_RUN` / `PRODUCTION_NO_GO_HOLD`。

## 2026-10-21 B1 control CID拘束／STOP契約（無接続・実用未採用）

この節は#810で再利用可能とした証拠に対して**未解決のcontrol境界だけを追加**する。以前のB1表の「監査ユーザーが存在するか」等はオーナーにより既に確認済みで、引継ぎで再依頼しない。#809の`scripts/ci/e858_tls/transport.py` の `Observer.terminate(cid)` は**隔離合成専用**であり、引数のCIDが承認済み監査sessionだけであることを実環境の信頼境界で検証するlive APIではない。実運用toolingには採用しない。

### 分離する操作scope（仕様のみ／実接続GOではない）

| 操作 | 許可候補の最小範囲 | 常時禁止 |
| --- | --- | --- |
| B1 private/offline | 固定e858 source/release・SQL/refと将来採用する独立tooling、単一service/DB・expected CA/SAN・既存principal、operator Mac、UTC開始/満了、試行ID、停止責任者、private保管を結合。既存証跡を再利用し不明はHOLD | 新接続probe、秘密や生metadataの共有、synthetic receiptを実GOへ昇格 |
| 限定bootstrap DATA（将来の別GO） | 検証済みの**単一**VERIFY_IDENTITY TLS sessionで固定Q0 identity／Q1 own grantsだけ。対象DB・engine・server UUID・`CURRENT_USER()`を事前期待値と照合し不一致はSTOP | full17、migration/schema追加read、自由SQL、ユーザーデータ |
| 限定bootstrap CONTROL（DATAとは別GO内scope） | DATAと独立したTLS接続、同一target／**既存の同一minimal principal**。DATAの**当該試行**に属するCID、server UUID、identityの対応と継続可視性を事前確認 | 他人や不明なCID、特権login・追加`PROCESS`/`CONNECTION_ADMIN`/`TRIGGER`、管理者権限へのfallback |
| 緊急停止（さらに明示的なcontrol scopeが必要） | 実証済みの当該試行CIDのみを停止候補とする。KILLを承認scopeに明記した場合だけ `KILL QUERY`／`KILL CONNECTION` を検討。直前の同一性・観測の再照合と、query終了／connection消滅の**個別**server-side証拠を要求 | 任意CIDのKILL、Consoleのボタン表示やKILL受理・local closeをremote終了証明にすること |
| full17 DATA（将来のさらに別GO） | bootstrapとcontrol/終了可能性の受入後にのみ、固定14SQLの17順・caps・strict comparatorを別承認で実行 | bootstrap承認の使い回し、合成PASSだけで実環境PASS認定 |

**重要な限界**：CID照合からKILLまでの競合、CID再利用、proxy/failover、Macとcontrolの同時障害を、単なる「同じユーザー名」やUIの表示だけで解消できない。**当該試行のセッションであることを連続して証明できなければKILLしない**。終了確認不能なら結果を `REMOTE_TERMINATION_UNVERIFIED` とし、既存の認可された管理者へprivate引継ぎする。後のcleanupを元の失敗に対するPASSへ読み替えない。

### STOP条件と証拠判定

| 発生事象 | 必須判定 |
| --- | --- |
| source/tooling/SQL/ref、対象service・DB、CA/SAN、expected identity、既存grantsが不明／不一致 | **BLOCK_BEFORE_CONNECT**。無承認接続を始めず別審査 |
| controlが同一principal／server UUID／対象CIDを証明できない、CID再利用の疑義、初見の空表示 | **CONTROL_SCOPE_UNVERIFIED / BLOCK**。CIDを変えて探索しない |
| KILL要求失敗、observer断、server切替、query/connection消滅が確認できない | **REMOTE_TERMINATION_UNVERIFIED / STOP**。同じGOで再試行しない |
| query10秒・全体120秒、cell128KiB・row256KiB・section8MiB・total16MiB等を超過、期限切れ、通信断 | **STOP**。部分データを監査合格としない |
| 成功・失敗・中断・結果不明のいずれかで1試行が終端 | **TERMINAL**。同じ承認で再接続・retry禁止。改めて別scope・別GO |

### B1実務上の決定

**ここで合意候補として明文化したもの**：既存minimal principal維持、read/control別scope、CID束縛、独立のserver-side終了観測、異常時STOPとfresh GO。これは**設計レビュー結果**であり、実環境の動作証明でも承認でもない。

**次の技術的差分**：実接続できない状態を維持して、future live-tooling候補の事前拒否契約（許可外CID／任意SQL／対象・CA切替・過剰grants、observer喪失、失敗後retry、秘密漏えいを拒否）をコード／試験計画に落とすかをレビューする。**現時点ではlive connector、credentials入口、real bootstrapコマンドを作らない**。必要なときに限定bootstrapとfull17をそれぞれ**別の明示承認**へ進める。

**状態**：`B1_CONTROL_SCOPE_SPECIFIED_OFFLINE` / `REAL_TOOLING_NOT_ADOPTED` / `REAL_AIVEN_CONTROL_UNVERIFIED` / `B2_FRESH_OWNER_GO_NOT_GRANTED` / `PRODUCTION_NO_GO_HOLD`。

## 2026-10-21 B1 安全境界のオフライン実装（#811の差分）

`scripts/ci/e858_tls/control_contract.py` は**非実行型の合成状態機械**。接続・SQL/KILL実行・credentials・endpoint入力CLI・承認発行はない。#804 `validate` / `outcome` を再利用し、期待するsource/tooling/SQL/reference pinsと14固定SQL digestを照合。既存inputのbounded/symlink/FIFO検査は再実装しない。

- 試行のDataとControlを別Bindingに分離。同一target/server UUID/principal/CA/SAN、VERIFY_IDENTITY・検証済みTLS・EXACT_MINIMALを要求。別CID・別generationと、事前期待targetとの一致を検査する。管理者fallbackなし。Controlは当該試行のBindingだけを保持し、任意CID/自由SQLを受け取る操作口や `Observer.terminate` callbackを持たない。
- `NEW → ACTIVE → COMPLETED/STOPPED`。最初のbindで試行を消費し、不一致・期限切れ・中断・終了後は同一objectを再利用不可。毎回のControl判定に新しい連番・現在時刻・同じData/Control・継続可視性を要求。観測不能・observer断・server/generation切替・消滅後のCID再出現はSTOP。
- idleはquery終了だけでconnection消滅ではない。両方を独立観測でき、停止要求失敗もない場合だけ **SYNTHETIC_REMOTE_TERMINATION_OBSERVED**。KILL受理・local exit・Console表示では昇格しない。不明なら **REMOTE_TERMINATION_UNVERIFIED**、後のfixture cleanupでもSTOPを解除しない。
- 結果は固定状態/分類/boolのみ。CID・principal・target hash・SQL・raw/例外本文を出力しない。`execution_allowed=false` / `owner_approval_verified=false` / `fresh_owner_go_required=true` を常に維持。metadataは固定digestに加えて当該試行のData scopeへ限定する（既定はidentity/grantsのみ）。allowlist内でもscope外のfull metadata・export・scope外Controlを拒否する。

**証明の限界**：Binding/Observationは合成fixtureの申告であり、実対象の認証・実GO・実session証拠ではない。generationはfixtureが所有する接続object由来の識別子で、MySQLのCID世代証明ではない。実環境の観測→KILL間の競合、CID再利用、failover、Mac全停止を解消したとは扱わない。新しいobject/プロセスの再生成は防止せず、Bの人的再実行禁止をdurable anti-replayへ昇格しない。実行可能permitを返さないため、このモデルを通しても実操作は開始できない。旧合成Observerは変更せず本番インターフェースに採用しない。

**差分検証**：`test_e858_b_control.py` の22単体テスト（複数負例はsubtest）をLinux Python3.9/3.12・Mac Python3.12の既存B CIへ追加。旧input/権限/B契約は既存試験を再利用。#809 Full94の既存MySQL8.0/8.4に、実同一principalのidle→消滅をモデルへ渡す正常系と、CID置換・generation変更・observer実切断・server置換を拒否して元sessionが保持される4負例を追加する。これらは **ISOLATED_MYSQL_TLS_TESTED** の補助証拠であり、実環境のTLS/観測/停止をPASSにしない。Full94/114の旧15ケースはfixture差分の回帰、固定SQL/ref/strict/capsは不変。#806の旧31・既存Mac受入を重複実装しない。

**次工程・歴史証拠**：#810の10/18権限/旧94・10/21 CA/Host/Port/Userの既存報告を再利用し、再確認を依頼しない。実接続用toolingは未採用。実CID帰属・観測継続性・停止競合/失効・既存最小principalでのControl実能力・Mac障害時責任者の審査を残す。限定Q0/Q1/control bootstrapとfull17は、それぞれ実行仕様・STOP・新しい明示GOが必要。現在は新probe/秘密共有を求めず、実connectorを有効化しない。

**維持**：`REAL_AIVEN_NOT_RUN / B2_OWNER_GO_NOT_GRANTED / PRODUCTION_NO_GO_HOLD`。

## 2026-10-21 Phase 1：Q0/Q1・観測専用Control（隔離合成のみ）

[オーナー方針6312638759](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6312638759)の実装。`e858_phase1_host.py` は既存のCA生成・固定Git資産照合・所有internal Docker networkを再利用し、`e858_phase1/runtime.py` が単一Data TLSと独立Control TLSを使用する。両方とも固定合成inspectorのみ。endpoint/credential/login-path/実行許可switchは受け付けず、workerは外部network・Docker socket・Git/実秘密を持たない。固定14SQLのhashをoffline照合するが、workerに渡すSQLファイルはQ0/Q1の2本だけで、full17/台帳/構造/app rowを取得しない。

### Phase 1 SQL／protocolの完全な操作一覧

SQLはASCII byte列。Q0/Q1は#795固定Git blobの改行までそのまま、他は下表のliteral（末尾改行なし）。`<CID>` は当該Data接続のhandshakeで得て同じ接続のSQL結果と照合した正の10進整数のみ。任意CIDを受け取るAPIはない。GateがdriverのCOM_QUERY直前に**全SQLのbytes・順序・回数**を検査し、未記載SQLやCOM_INIT_DB/PING/reset等は送信前拒否する。

| ID | exact SQL bytes（`\n` は末尾LF） | role／順序・最大回数 | 用途・権限／対象・STOP |
|---|---|---|---|
| I | `SET NAMES ascii` | Data/Controlの接続直後、各1 | PyMySQL1.1.2初期化。自身session設定のみ、失敗/追加初期化SQLはSTOP |
| Q0 | `SELECT DATABASE(), CURRENT_USER(), VERSION(), @@server_uuid;\n` | Dataの最初とclose前、最大2 | 固定identity、期待DB/user/engine/UUIDとControlを照合。不一致STOP |
| Q1 | `SHOW GRANTS FOR CURRENT_USER();\n` | Data1、Controlは初回1＋観測毎1、最大12 | own grantsのみ。既存REFERENCES schema＋SELECT migrations＋任意USAGEのvalidatorを再利用。不足/過剰/不明STOP |
| D | `SELECT CONNECTION_ID();` | DataのQ0後とQ1後、最大2 | handshake CID・同じobject/socket/thread IDとの照合。不一致STOP |
| C | `SELECT DATABASE(), CURRENT_USER(), VERSION(), @@server_uuid, CONNECTION_ID();` | Control初回1＋観測毎1、最大12 | 同じserver/DB/principal/engine＋Control自身CIDを再照合。切替/再接続STOP |
| O | `SELECT ID, USER, DB, COMMAND FROM information_schema.PROCESSLIST WHERE ID = <CID>;` | Control、close前1＋close後最大10＝11 | 同一minimal principalの当該Dataだけ。追加PROCESSなし。初見の空/別user/DB/CID/観測断はSTOP。正常close後にだけ継続可視性を前提とした消滅確認 |
| P | SQLではない `COM_QUIT`（MySQL payload `01`、packet bytes `0100000001`） | Data正常終了1、Control後処理1 | 送信成功とlocal closeを記録。remote終了の証明とは別。書込失敗はSTOP |

**順序**：Control `I,C,Q1` → Data `I,Q0,D,Q1` → Control `C,Q1,O`（Data idleだがconnection存在を独立観測）→ Data `D,Q0,P` → Control `(C,Q1,O)` を最大10回 → 消滅確認後Control `P`。通常post-close1回ならData6 SQL＋Control9 SQL＝15 SQL、最大Data6＋Control36＝42 SQL。handshake/auth/TLSのprotocol packetはSQLではなく、driver認証の後にI以外の暗黙SQLを許さない。autocommit=None、sql_mode/init_commandなしで、`SET AUTOCOMMIT`、`SET sql_mode`、`max_execution_time`等は送らない。

全SQL query10秒/試行120秒、cell128KiB/row256KiB/section8MiB/合計16MiBを維持。#806のbounded readerとStrictConnectionを変更せず再利用し、合計byte予算は両channelで共有。接続2秒、read/writeも残時間以内。Controlのpost-close観測は10回上限、終了を確認できなければSTOP。再接続・自動retry・KILLなし。

### 合成fixtureの管理SQL（Phase 1 scope外・本番へ持ち込まない）

新規owned MySQL内の準備／独立wire検証だけで使用する。rootはbootstrapのData/Controlに渡さず、失敗時fallbackにも使わない。`e858_phase1_host.SETUP` の正確な7 literalは以下：

```sql
CREATE TABLE migrations (id BIGINT PRIMARY KEY);
CREATE USER 'e858_tls_inspector'@'%' IDENTIFIED BY 'ci-only-tls-inspector' REQUIRE SSL;
GRANT REFERENCES ON canovia_p0_ci.* TO 'e858_tls_inspector'@'%';
GRANT SELECT ON canovia_p0_ci.migrations TO 'e858_tls_inspector'@'%';
SET GLOBAL log_output='TABLE';
SET GLOBAL general_log=ON;
SELECT @@server_uuid;
```

全て合成名/合成passwordで各1回。root socketでの準備のみで本番ユーザー/DBを変更しない。ready確認は `SELECT @@skip_networking;` をserverごと90秒内・最大360回（2 server）、固定private traceの検査は `SELECT DISTINCT thread_id FROM mysql.general_log WHERE command_type='Query' AND argument='SET NAMES ascii';` 1回、各所有CIDについて `SELECT HEX(argument) FROM mysql.general_log WHERE thread_id=<CID> AND command_type='Query' ORDER BY event_time;` 最大36回。これ以外の管理SQLは使わない。private logの全inspector接続数と全query bytesを照合し、未知SQL/未知接続を拒否。MySQL general_logが末尾delimiterを省く表現差だけ観測比較で正規化し、実行Gateのbytesを緩和しない。raw log/CID/SQL結果は公開せず、所有container/network/image/cert/rawは終了時破棄する。

### 終了・証拠・回帰

PyMySQL通常closeはQUIT write errorを隠すため、Phase1は送信成功を明示確認してからlocal socketを閉じる。その後、独立Controlのidentity/own grants/対象可視性を確認してDataのconnection消滅を観測する。query idleはconnection消滅と別。エラー時はlocal dropのみでKILL/QUIT retryをせず、**REMOTE_TERMINATION_UNVERIFIED**。fixtureの破棄を元の失敗の成功証拠にしない。Control自身の正常QUIT送信は確認するが、そのremote消滅の独立証明とは主張しない。

`control_contract.py` は `scope=('OBSERVE',)` のみで再利用し、binding/generationは合成申告である限界を維持。CID再利用競合、server failover、実権限の不可視変化、Mac全停止・両channel断、異常時遠隔停止能力・実owner本人認証は未証明で、実用tooling未採用。**正常closeの成功からKILL能力を推論しない。**

新15単体テストと既存B22/TLS17のoffline回帰、合成MySQL8.0/8.4の18ケースを別CIで検証する。実CA/hostname/期限切れ負例はTLS通信で拒否。CID/server/不可視/remote残存の負例は固定fixtureで観測値を改変する合成負例であり、実failover/CID再利用発生の証拠ではない。query期限は実接続の応答読取をfixture内で遅らせ、stream期限は短縮して検証し、長時間SQL/KILLを混入させない。旧Full94/114/31wireは共有実装を変更していないため証拠を再利用し、CIは旧runtime/input/testsに差分がある場合だけ旧wireを再実行（不明/dispatchなら実行）する。旧固定SQL/Reference/strict/capsや判定条件は変更しない。

10/18権限/旧94・10/21 Host/Port/User/CAの歴史的証拠を再利用し再確認を依頼しない。後続の**Phase1実用tooling採用・操作票・接続直前GO**、KILL能力の別審査、full17の別GO、TRIGGER/移行主体/binlog/実restore/実機/本番公開は独立ゲート。今回のオフライン方針受入は実SQLの許可ではない。

**維持**：`REAL_AIVEN_NOT_RUN / B2_OWNER_GO_NOT_GRANTED / PRODUCTION_NO_GO_HOLD`。

## 2026-10-21 Phase 1実用tooling採用審査・実行票（無接続）

[独立受入6313307628](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6313307628)を反映。#813の検証HEAD `69515dc413e4c5dc6f93882b0feb71621e51c337`／main `2bd95c9cf1ff83b756ea618dbd512c256ea60272` と既存CIを再利用する。本節は**実行方式の条件付き推奨と将来の操作票**であり、実接続tooling・credential loader・GO発行を実装しない。操作票の空欄は未達であり、チェックの記入だけで現在の合成コードが実接続可能になることもない。

### 資格情報方式の比較と推奨候補

**条件付き推奨R1：#813と同じPyMySQL 1.1.2を基礎に、将来オーナー本人が管理Macのprivate TTYへ既存inspector passwordを一度だけ非表示入力し、既存CAと過去に照合済みの対象設定を使う方式。** 既存のlogin-pathは残し、10/21の対象照合の出典として再利用するが、**その保存passwordをPyMySQLへ転送する方式ではない**。login-pathを直接再利用できると説明してはならない。オーナーが既存passwordを私的に用意できない／TTY再入力を認めない場合はR1をBLOCKし、復号・表示・別方式への自動fallbackをしない。今回passwordの用意・取得・入力は求めない。

| 候補 | 既存login-path／CAの利用 | 採用審査 |
|---|---|---|
| **R1 PyMySQL＋将来のprivate TTY入力** | login-pathのcredentialは読まない。既存CA・対象の歴史的照合を再利用。秘密は将来のowner入力後、同一プロセスの2接続にだけ保持 | **条件付き推奨・未採用**。#813の同一TLS/Gate/caps/COM_QUIT送信検査を再利用でき、最小の差分。credential方式へのowner判断と後述の実装・審査が必要 |
| native MySQL CLIを2本持続起動 | MySQL自身が既存 `.mylogin.cnf` の選択login-pathを読み、既存CAを明示する。PyMySQLへの秘密移送は不要 | 今回は非推奨・未採用。永続stdin/result framing、初期SQL/2接続上限、timeout/caps、再接続禁止とQUIT送信確認を新たに証明する必要。CLIの終了codeだけでは#813のQUIT write成功に代えられない。既定SIGINT処理には**別接続からKILL QUERYを送る経路**があり、Phase1へそのまま使えない |
| SQLごとにMySQL CLI起動 | login-path／CAは使える | **不採用**。Dataの同一TLS/CIDと2接続・回数上限を維持できない |
| PyMySQLにlogin-path名／`.mylogin.cnf` を直接指定、設定を平文へexportして読込 | 非対応／秘密の新たな複製が必要 | **不採用**。PyMySQLの `read_default_file` は通常のoption-file parserで、MySQLのobfuscated login-path readerではない。password引数・環境変数・平文一時INI・print/defaults出力・復号bridgeも採用しない |

根拠：[MySQL公式login-path仕様](https://github.com/mysql/mysql-server/blob/mysql-8.4.0/man/mysql_config_editor.1)（obfuscationであり強固な秘密保管の保証ではない）、[MySQL CLIのSIGINT/KILL経路](https://github.com/mysql/mysql-server/blob/mysql-8.4.0/client/mysql.cc)、[PyMySQL 1.1.2 option-file/接続処理](https://github.com/PyMySQL/PyMySQL/blob/v1.1.2/pymysql/connections.py)。当該実装をsourceで確認した。operator Macの実際のclient binary/version・秘密は今回取得していない。

### 実用採用前に必要な実装差分（今回は実装・有効化しない）

1. **credential境界**：R1をownerが選定した後にだけ、private TTY専用の非echo入力・非TTY/echo復帰失敗時の中止・`getpass`の表示fallback拒否を設計する。秘密をargv/env/file/clipboard/ログ/traceback/receiptへ渡さず、core dumpやdebug/local変数収集を抑止。2接続で同じ既存principalを使い、失敗時に再入力・再接続しない。Python/driverのimmutable文字列やOSメモリの完全消去は保証できないことを受け入れ、終了時の参照破棄を完全消去と表現しない。新しい認証authority/Keychain連携やpassword変更は作らない。
2. **合成と実用の分離**：現runtimeの固定合成host/DB/user/password/CA、`/synthetic`、fixture oracleを置換するswitchを既存CLIへ足さない。別審査の単一用途adapter候補として、private対象設定の読取境界・許可対象1件・既存CA・期待identityの出典を固定する。環境や通常option-fileからの黙示override／任意endpoint／SQL／admin fallbackを拒否。今回はそのadapter/config/credential loaderを作らない。
3. **実toolingの固定と差分受入**：選定adapterの完全SHA、PyMySQL/Python/SSL依存・配布物hashを固定し、#813から変わる境界だけを合成・Mac無接続環境で検証する。既存15単体/18合成wire結果は同一部分に再利用し、全面再実装・旧94/CAの再調査をしない。実用SHAは現時点で**未定・未採用**。#813や本docs PRのSHAを実用tooling採用済みと記入しない。
4. **未知の実値を扱うbootstrap**：実UUIDや現在のgrantsを事前の実接続で採取しておくことを必須にしない。後述の明示GO内の最初のControl identityでUUID/`CURRENT_USER()`/engineを**観測値**として受け、既知のprivate対象/論理principal/DB/許可engineと照合する。Data Q0および以後のControlで同じ完全identityを要求。未知のhost grant部分等を勝手に `%` と仮定せず、対応を安全に判断できなければSTOP。UUIDやQ0自己申告だけを独立したtarget承認にしない。現在の実効権限/roleがQ1だけでは確定できない場合もSTOPし、追加SQLやGRANTで補わない。

### Phase 1専用実行票 P1（私的記入用／現在NOT_EXECUTABLE）

**操作一覧の審査結果**：直前の「Phase 1 SQL／protocolの完全な操作一覧」を変更せず採用条件とする。Q0/Q1以外も同じ票のscopeに含める。Data6＋Control9＝通常15 SQL／最大42、同一最小principalの**2 TLS接続のみ**、各1回の正常COM_QUIT（SQLではない）。Controlは対象Data CIDだけの観測、query idleとconnection消滅を区分。query10秒／全体120秒／既存cell・row・section・total caps。fixture管理SQL・KILL・full17・ledger/app rowsは**実行scopeに含めない**。adapterが1つでも別SQL/再接続/回数超過を必要とするなら採用を止め、票・コード・差分証拠を別レビューする。上限を黙って増やさない。

以下のprivate欄に実値を記入する場所はGitHub/ChatGPTではない。共有用は末尾の固定分類receiptだけとする。`未記入/未承認` がある間は接続しない。

| P1欄 | 私的に束縛する内容／合格条件 | 現在 |
|---|---|---|
| 票ID・版・試行ID | 本票の確定版と非秘密の一意試行ID、接続前に試行開始を記録。新プロセス/別票コピーで許可を再利用しない | 未記入 |
| source／release | `e858b9c855970fbe73f37a2d60c9db6525b132d6`／`release/2026-10-19-e858b9c8`。main移動で更新しない | 固定・不変 |
| 実用tooling | 将来採用する完全SHA／配布物・依存hash／#813との差分レビュー・受入証拠／clean full-history checkout | **未実装・未採用** |
| SQL seal | #795 `be89af9a…` の原本Q0 SHA256 `092b3b57f2cc33abe4857da34d50dbefc316e61c9b3073bb575233b7569e7a89`、Q1 `0923d34edf5c4d241e7ba853045e7443943ebeee81153a6c49ed8c5b080ebc24`（末尾LFを含む）。14 bundle `8383bc16fc8b35b8413a2a6a0b0c0784c9fa646ebab119017b97c2bd8d4333d1` とreference hashはB0の既存manifest照合を再利用。Phase1は物理Reference比較を実行しない | 原本確認済み／実行前bytes照合は将来 |
| operator・端末 | owner=operatorの本人・管理Mac・ローカル実行責任者。debug/画面共有/端末録画・秘密の収集を避け、private保管責任者を指定 | 私的記入待ち |
| 対象1件 | 10/21の既存service/DB/Host/Port/User照合記録を参照し、今回の対象1件を束縛。実値を公開しない。変更/失効根拠なしにConsole照合をやり直させない | 歴史的照合を再利用 |
| CA／SAN／TLS | 10/21の既存CA fingerprint・hostname記録を期待値に使う。将来の実行前にtoolingが現在のCA file bytesと期待値を比較、TLSはCERT_REQUIRED＋hostname/SAN検証＋TLS1.2以上。既存ファイルの存在/Console照合を改めて依頼する作業ではない | 歴史的照合あり／実TLSは未検証 |
| 資格情報方式 | ownerが将来private TTYで同じ既存inspectorのpasswordを入力するR1を選定。login-pathのpasswordは転送しない。用意できない/入力不可ならBLOCK | **R1選定済み（6313769942）／実用採用・実入力は未承認** |
| 最小権限 | 10/18 validatorの歴史的受入を根拠として再利用。現在値はGO内Q1で検査。不足/過剰/未知roleならSTOP、拡権・別loginなし | 現在値は未知・取得は将来GO内のみ |
| identity／Control CID | 既知のprivate対象・論理principalと、最初のControl identity→Data Q0→継続観測を照合。Data CIDはhandshake＋同じDataのCID SQL由来。未知のUUIDを事前probeしない、他CID探索不可 | 実観測NOT_RUN |
| UTC窓 | 新しいGOの記録時刻、接続開始/満了epoch、0<窓≤120秒。待ち時間超過/時計不明/終了期限超過は中止。秘密入力や接続の失敗・中断も試行消費として記録 | 未記入 |
| 実行scope | 直前のexact SQL/protocol表の確定版、15/42 SQL、2 TLS、normal QUIT・観測のみを対象にする。Q0/Q1だけと略記して補助SQLを隠さない | 表を審査済み／実用adapter同等性は未検証 |
| 保管・削除 | Git/同期/CI外のprivate0700領域、必要最小のraw0600、保存期限/削除担当/中断残骸確認。passwordは保存しない。rawを原則メモリ内に留め、OS crash/swap/完全消去の限界も認識 | 私的記入待ち |
| 障害時の引継ぎ | 当該対象の既存認可責任者と連絡方法、対象/UTC窓/既知CID等のprivate引継ぎ先。追加のSQL/Console操作は本票の自動fallbackではない | 私的記入待ち |
| 残余リスク受入 | 下記R欄をownerが私的に個別受入。受入は技術証明ではない | **未承認** |
| 実行直前GO | 実用tooling採用・全欄成立後にownerが本人監督で、固定対象・SHA・全SQL scope・窓・1回・再試行不可を確認し、新しい明示GOを記録。JSON/env/comment/CIだけをauthorityにしない | **NOT_GRANTED** |

### 残余リスクのowner承認欄（R欄、未承認が既定）

| 欄 | 受け入れる限界（PASSへ昇格しない） | 私的署名／UTC／受入・拒否 |
|---|---|---|
| R1 | private TTYでの秘密再入力とプロセス内平文保持。端末の侵害/録画/メモリ・swap・core等を完全排除/完全消去できない | 未承認 |
| R2 | 同一Macの2 TLSは独立した災害ドメインではない。Mac全停止/通信断ではremote終了を観測できず、再起動して同じGOで再実行しない | 未承認 |
| R3 | failover/proxy/server切替/CID再利用疑義はSTOP。CIDやUUIDだけでは真正性・世代/連続性を証明できない | 未承認 |
| R4 | 正常QUIT/local closeは異常時強制停止能力ではない。Phase1ではKILLなし、Control自身のremote消滅も独立証明しない | 未承認 |
| R5 | 最小権限の観測不能/未知role/同意撤回/期限切れはHOLD。人的1回運用は耐再起動・rollbackの暗号学的anti-replayではない | 未承認 |
| R6 | 限定bootstrapの合成/実結果はfull17・物理schema/TRIGGER・移行主体/binlog・restore・実機・production公開の承認ではない | 未承認 |

### STOP・再承認・private引継ぎ（本票の運用順序）

1. **接続前**：上記固定資産・既存対象記録・credential方式・実用SHA・残余リスク・責任者・UTC窓を無接続でレビュー。未採用tooling／未承認欄／不一致／秘密を安全に入力できない状態なら**NO_CONNECT**。歴史的な94/権限/CA検査を追加接続で埋めない。実行票の採用と実GOを分離する。
2. **将来のGO後だけ**：開始をprivate記録し1試行を消費、資格情報を私的に入力、Control→Dataの既定順序で実行。2接続以外を作らず、current identity/grants等の未知値は許可されたQ0/Q1/Control内でだけ得る。正常時はQUIT write/local closeとDataのserver-side query/connection終了を別判定し、最後にControlをcloseする。
3. **一度でも不明/異常なら**：未送信SQLを止め、現在のlocal handleを追加SQLなしでdrop。許可されたControlが健全な範囲の既定観測以外を足さず、再接続/別host/TLS弱化/admin login/GRANT/KILL/Console Terminate/別CID探索をしない。観測断・server差・CID疑義・timeout・QUIT失敗・remote終了不明は **REMOTE_TERMINATION_UNVERIFIED / HOLD**。要求受理/CLI終了/空画面をremote終了証明にしない。
4. **Mac停止・復帰を含む引継ぎ**：既存責任者へ、対象のprivate参照、試行ID、source/tooling、UTC開始/期限、最後の正常段階、既知CID/UUID（不明なら不明）、local close結果・remote終了未確認を私的に渡す。password/token/raw grantsを送らない。今回の作業では連絡やConsole操作を実行しない。本票は責任者へのSQL/KILL等の権限を新たに与えない。
5. **終了後**：成功/失敗/中断/期限切れの全てで同じGOは終端。private残骸と保存期限を確認し、将来の再試行は原因と対象/時刻窓/状態を再審査した**新票・新GO**にする。後のcleanup/管理者確認を元の失敗のPASSへ書き換えない。

共有可能なreceiptは**固定source/tooling SHA、SQL整合可否、開始/終了UTC、段階の固定分類、接続/SQL回数、QUIT write/local close/query終了/connection消滅の各boolまたはUNVERIFIED、cleanup区分、fresh GO必要**のみ。接続先/DB/CA path・fingerprint/内部principal/CID/UUID/秘密/raw/詳細エラーはGitHub・CI・ChatGPTへ出さない。ownerの実GOが存在しない現在の合成receiptは常に `execution_allowed=false` / `owner_approval_verified=false`。

**#814時点の次作業＝オーナー判断（R1資格情報方式の選定）**。その後の選定と無接続実装は次節を参照。以後は**必要最小の無接続実装・差分受入** → 実用tooling/票/残余リスクの最終owner審査 → 新しいPhase1限定GO → そのGO内だけの**実環境検証**。現時点から実環境検証へ直接進まない。R1が不採用ならHOLDして方式審査へ戻り、native CLI等へ無断切替しない。

**維持**：`LIVE_TOOLING_NOT_ADOPTED / REAL_AIVEN_NOT_RUN / B2_OWNER_GO_NOT_GRANTED / PRODUCTION_NO_GO_HOLD`。

## 2026-10-21 R1 private TTY入力境界（無接続実装）

[owner方針6313769942](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6313769942)により**R1の設計とオフライン実装だけを選定**。上の#814の方式選定待ちを解消するが、R欄のリスク受入、実用tooling採用、実password入力、接続GOは解消しない。

- `scripts/ci/e858_phase1/r1_tty.py` の `PrivateTTYAttempt.capture(terminal_fd)` は、明示されたprivate TTY descriptorだけを検査する独立library。SIGINTを処理するmain thread以外は拒否する。標準descriptor 0/1/2・非TTYは拒否し、stdin fallback・prompt CLI・`/dev/tty`自動open・login-path/option-file読込・secret export/handoff API・接続機能はない。将来adapterが信頼したprivate TTYを開く境界は**未実装・別審査**。単なるTTY判定は本人・端末の真正性証明ではない。
- 所有するdup上で元のtermiosを保存し、`ECHO/ECHONL`を無効化してreadback一致後だけ入力。noncanonical byte単位・最大1,024 bytes・120秒の入力上限で端末行バッファの切詰めを避ける。改行を除く空入力、制御文字、EOF、上限超過、timeout、入出力/設定失敗、SIGINT/KeyboardInterruptは拒否。最小の境界として編集キーも拒否し、再入力しない。この入力期限はSQL/接続の新しい実行許可・時間窓ではない。
- 成否・中断に関係なく、未読suffixを`TCSAFLUSH`で破棄してから元の全termiosを復元・readback検査し、dupをclose。復元/cleanup不明は `R1_TERMINAL_RESTORE_UNVERIFIED` / `R1_TERMINAL_CLEANUP_UNVERIFIED` とし秘密を破棄、成功にしない。復元がOSに拒否された場合の復元成功やSIGKILL・OS/端末全停止後の復元を保証しない。端末状態不明ならその端末で秘密を再入力せずSTOP、後の復旧は当該試行をPASSにしない。
- 同じattemptは入力前に消費し、成功/失敗後・同時要求を `R1_RETRY_REFUSED` にする。これはプロセス内だけの入力制約で、オブジェクト再生成・再起動をまたぐanti-replay/owner承認ではない。B案の新票・新GO規則は別に維持。
- 戻り値は固定分類のみ、repr/strも固定。秘密はprivateなmutable bytearrayだけに保持し、`discard()`/失敗時にbest-effortでゼロ化・参照を破棄。argv/env/file/clipboard/ログ/例外/receiptへ書く経路はない。Pythonのprivate属性はアクセス制御ではなく、trusted caller/debuggerはメモリを読める。`os.read`の一時bytes、Python/将来driverのimmutable文字列、OSメモリ/swap/core/端末監視の完全消去・機密性は保証しない。live統合時のdebug/traceback-local収集・core抑止、secret寿命・handoff/driver複製の審査は未完了。

**差分受入**：合成passwordだけを使う21テスト（実PTYで非echo/復元・正常/空/最大/過大/EOF、実SIGINT、read/setup/restore/close失敗、秘密非露出、同時/再入力拒否、network/CLI/secret exportのないimport境界）。既存Phase1 15・B1 control 22・TLS offline 17を回帰し、既存CIのLinux Python3.9/3.12・Mac Python3.12へ追加。Phase1の合成MySQL8.0/8.4は既存18-case CIを再利用する。CI結果・固定検証HEADはPR/Issue #488に記録し、管理Mac本人の受入成功へ昇格しない。

**変更しないもの**：`e858_phase1_host.py` / `runtime.py` / 固定合成TLS/資格情報、Q0/Q1 bytes・15/42 SQL・2 TLS・caps/STOP・source/SQL/reference seal、#795〜#797の親子関係。旧94/114/full17/remote KILLのwireは無関係な共有差分がないため再実行しない。10/18・10/21の歴史的証拠も再確認不要。

**次工程＝実装**：別審査の最小adapter候補でprivate対象/CA固定・TTY取得と秘密handoff/lifetime・2接続同一principal・unknown UUID/grantsのGO内bootstrapを無接続で具体化する。新しい実endpoint入力/credential loader/実接続switchは今回追加しない。その差分・Mac無接続受入・固定実用SHAが揃ってから、ownerのP1/R欄受入と新しい限定GOへ進む。現時点で実接続もpassword入力も依頼しない。

**維持**：`R1_DESIGN_SELECTED / LIVE_TOOLING_NOT_ADOPTED / REAL_AIVEN_NOT_RUN / B2_OWNER_GO_NOT_GRANTED / PRODUCTION_NO_GO_HOLD`。

## 2026-10-21 R1 adapter境界（合成専用・無接続）

[#815独立レビュー6314307552](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6314307552)に対する限定差分。`scripts/ci/e858_phase1/r1_adapter.py` は**内部生成の合成PTYと固定mock driverだけ**を束縛し、#815の `PrivateTTYAttempt`、#813のruntime/host/protocolを変更しない。実用tooling、実 `/dev/tty` opener、実endpoint/CA/password入力、login-path読込、credential loader/接続switchは作らない。

| 境界 | 今回の実装・検証 | 実用採用まで残ること |
|---|---|---|
| TTY取得・操作 | 明示的な `SYNTHETIC_OPERATOR_GESTURE` とsealed contractの成立後だけ、内部でPTYを生成。main thread・非継承FD・character device/TTY・作成時FD identityを検査し、標準/未知/置換FD、stdin fallbackを拒否。復元は#815を再利用し、所有FDを終了時にclose、不明はcleanup未確認 | 合成gestureは本人認証・オーナー承認ではない。管理Macのtrusted controlling TTY取得・端末/録画/本人確認は未実装・別審査。実password入力は求めない |
| 受渡し・寿命 | 入力は固定合成passwordだけ。context内でcapture→control→dataの順に各1回だけ同じbytearrayのreadonly memoryviewを固定 `SyntheticDriver` に貸与。任意driver/factoryの注入なし。SQL拒否・driver作成失敗・割込でも両mockを停止し、全leaseをrelease→driver参照破棄→bufferゼロ化/破棄→TTY close。close不明は `R1_CLEANUP_UNVERIFIED` | Python private属性/readonly viewはtrusted callerを隔離しない。将来PyMySQLのimmutable内部コピー・寿命、debug/core/swap、例外context/local変数のprivate閲覧・完全消去は保証しない。mockのcopyなし検証をdriver実用検証にしない |
| 対象・CA・seal | service/DB/Host/Port/principal・SAN・VERIFY_IDENTITY/certificate/hostname/TLS1.2・source/release・独立reviewed tooling SHA・Q0/Q1/bundle hash・既存上限をfrozen contractと型も含め完全一致で照合。変更は入力前/driver作成時/SQL claim時に拒否。環境/option-file override/admin fallbackなし | CA bytes/hashは明記した**合成marker**でありX509証明書ではない。実CA file/既存private対象記録のbindingは未実装。tooling引数同士の一致は承認/checkout真正性ではなく、既存source-bound integrity/clean checkoutを別途再利用する。seal変更を許可しない |
| Phase1同等性 | 新mockは固定Q0/Q1 bytesをhash照合し既存 `Gate` を使う。既存 `Bootstrap.run()` をテスト側のmock Channelでそのまま実行し、15 SQL/2 channel・Control観測・normal QUIT marker・query終了とconnection不在の分離を比較。free SQL/追加接続/順序違反/期限延長/中断/観測不能はSTOPし同じattemptで再実行しない | mockのQUIT/不在はserver/TLSの観測証明ではない。実TLS/COM_QUIT/remote消滅の合成通信証拠は既存Phase1 MySQL8.0/8.4各18-case CIを再利用し、新TTY秘密が実wireへ渡ったとは主張しない。real session/UUID/grants/CIDはGO内でのみ検証 |

**新23テスト**：全contract項目変更、tooling/Q0/Q1改変、非TTY/未知FD・取得失敗・非継承/復元/close、actionなし、同一readonly bufferの2貸与・releaseと参照破棄、driver作成/close失敗、割込/例外の固定出力、再入力・再開拒否、環境override無効、runtime変更なしの両engine mock受入、観測不能/割込の `REMOTE_TERMINATION_UNVERIFIED`、Gate改変/free SQL拒否後の再試行拒否、実接続/CLI/loader経路なし。既存#815 21＋Phase1 15＋B1 22＋TLS offline17を同じLinux3.9/3.12・Mac3.12 CIで回帰する。既存Full94/114/full17/KILL wireは共有差分がないため再実行しない。historical 10/18・10/21の照合を再依頼しない。

**管理Mac無接続受入へ進める条件**：このPRの固定HEADで上記CI・差分レビューが成功した後、管理Macの新しいclean checkoutから合成adapter/TTYテストだけを実行できる（具体的な固定HEAD/CI Run/コマンドはPR・Issue #488に記録）。実passwordも実CA file/接続先も入力しない。実fixture外への通信が必要、依存不一致、テスト失敗、cleanup不明ならSTOPし、実接続で解決しない。この受入はP1実行票やR欄の署名・実接続GOではない。

**次工程**：管理Macのこの合成無接続受入→別レビューの実用adapter候補（trusted TTY取得・private対象/既存CAのseal・PyMySQL受渡し/内部copy・単一principal/2接続・既存Gate/capsの保持）→完全実用SHA/配布物/依存固定とowner採用→P1票/R1〜R6の個別受入→新しいPhase1限定GO。現時点ではどの実用connectorも有効化しておらず、実接続GOまでの技術差分は残る。プロセス再起動をまたぐanti-replay/remote強制停止/実用principalの権限/実バックアップ復元も証明しない。

**維持**：`R1_DESIGN_SELECTED / LIVE_TOOLING_NOT_ADOPTED / REAL_AIVEN_NOT_RUN / B2_OWNER_GO_NOT_GRANTED / PRODUCTION_NO_GO_HOLD`。

## 2026-10-21 全94件の固定e858 single-session bridge（合成専用）

[Issue #488・クリティカルパス決定](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6305867624)の実装レーン。`scripts/ci/e858_full94_tls.py` は#806の所有Docker/TLS/cleanup基盤を再利用し、固定e858のPHPソース・94 migrationをGitから展開してLaravelの既存 `migrate --realpath --path` 方式で構築する。手動のledger生成や最小3テーブルfixtureで代替しない。同じ使い捨てDBに固定114件まで適用してtargetも検証する。dump/restoreは行わない。セットアップ用DDL/GRANTと試験KILLは、この新規作成した合成network内だけであり、実Aivenへの操作経路は存在しない。

**監査経路**：#804のsource/tooling/SQL/reference integrityとB1 validateを利用。各stageの合成B票は `SYNTHETIC_B1_ONLY` / `execution_allowed=false` / `owner_approval_verified=false` であり、receiptは実承認ではない。workerもB票の期限・pins・receiptと、固定source manifest/期待migration集合/contract/ref hashを照合してからSessionを作る。#795の14 SQL/17順序とpure `audit/model/compare` を固定Git blobからそのまま使用する。旧#795の限定scope boundaryや `--collect` BLOCKは緩和せず、新しいB integrityがmoving-mainと固定監査資産を分離する。

**data/control分離**：dataは従来と同一のVERIFY_IDENTITY TLS connection/socket/CIDで全17処理を実行。別spawnプロセスの**同一最小principal**が自身sessionを観測/停止する。REFERENCES schema＋SELECT ledgerの権限は広げない。管理rootは合成schema構築・独立fixture証拠・緊急cleanupに限定し、inspectorのTRIGGER権限などに置換しない。失敗後の同一Collector/Session再実行を拒否し、終了未確認は `REMOTE_TERMINATION_UNVERIFIED`、後続cleanupで昇格しない。

| 合成受入 | 必須の判定 |
| --- | --- |
| 全94件・最小権限のon-wire stream | 91table/1154column/604index行/181FK行等の固定baseline94へ厳密一致、`EXACT94_PENDING20_METADATA_REVIEW_ONLY` / `REVIEW_REQUIRED`。総合判定は依然 `BLOCK_REAL_GATES_UNVERIFIED` |
| 意図的なINDEX名差 | `NAME_ONLY_DIFFERENCE` でもstrict `BLOCK`。aliasを元に戻し固定114件へ進む |
| 全114件・最小権限stream | TRIGGER 3件が不可視となる `TRIGGER_ABSENT_OR_NOT_VISIBLE` / `BLOCK`。他区分一致を確認し、不可視性を0件の完全証明にしない |
| 全114件・管理fixtureの構造証拠 | #795 candidate114 referenceに厳密一致すること。これは独立した試験用証拠で、inspector収集結果へ混入・本番GOへの昇格をしない |
| SQL/権限/session/control負例 | 自由SQL・export・別target/session・過剰/不足grants・observer不可視・停止要求失敗を拒否。成功captureの実server query logをprivateにallowlist/envelopeと照合し、application-row SELECTが0件であることを検査 |

旧#806のMac31ケース、#795〜#797、既存移行/復元監査を目的なく再実行しない。共有runtime/Oracleに変更があるため、その31ケースを今回の回帰CIとしてのみ実行し、新規full94/114受入は別workflowの8.0/8.4で実施。CI run/tooling固定HEAD・main統合状態はPRとIssue #488へ集約する。新しい正本や本番connector、host/CA/login-path/credential入力、live実行スイッチは増やさない。

**実環境の次ゲート**：オーナーが既存1 service/DB、期待CA/SAN/identity、管理Mac、独立control scope、期限/1回を私的に照合する。秘密や生grants/metadataは共有しない。その後に初めてQ0/Q1/control可視性だけの狭いlive bootstrapを別提案・別GOで審査する。今回の実装/合成成功はそのGOでもfull17実読取許可でもない。現時点で新しいowner probeを依頼しない。実physical INDEX/FK/TRIGGER、migration principal/binlog、実backup復元・device・本番昇格は別ゲートで **REAL_AIVEN_E858_NOT_RUN / PRODUCTION_NO_GO_HOLD**。

## 固定対象と再利用

- 作業base main `7258a02ca3599f4efd3afacbd91d5017caeb9842`。本PRのHEADは独立tooling候補であり、固定監査候補の代わりにしない。
- 監査source `e858b9c855970fbe73f37a2d60c9db6525b132d6`、release `release/2026-10-19-e858b9c8`、production `15abdc9de6f24b5250fb255c579d1aa55facaf1a` を変更しない。
- 未mergeの #795 `be89af9a2cb4b13ef16579d2570f4a5605a711b8` → #796 `5e63b7db068121d000c316fa8700f7c5f1c0c3b4` → #797 `9f7d248e87bd54211b76d9b85effd72ee385daed` は独立した固定chain。このPRへ取り込まない。親子関係・旧main pin・SQL/reference・14 SQL/17実行・caps/limits・strict BLOCKは不変。
- #795 CI39211597992、#796 CI39271348988、#797 CI39278554514 attempt2の成功を再利用。Mac受入は #488 comments [6270282307](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6270282307) / [6277403445](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6277403445)。再実行を要求しない。
- MySQL8.0/8.4の名前差・意味差・相殺・衝突・部分DDLは既存の両版38ケース。#800 の94/114独立logical restoreも[6282556286](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6282556286)を参照。合成PASSを実DBの証拠に転用しない。

## 今回再現・修正した不足

既存 `p0_mysql_metadata_grants_guard.py` はunknown引数をargparseのstderrへそのまま含め、入力ファイルを16KiB判定前に全読込していた。合成canaryで漏えいを確認した。FIFOではopenが停止する可能性もあった。

独立した `p0_mysql_metadata_grants_input.py` が元の `verify_grants` に委譲し、引数不備・重複・省略形・割込は固定BLOCKのみ。最終pathのsymlinkを拒否し、nonblocking open後にregular fileを確認、statのサイズと最大16KiB+1の読込で成長後も制限する。必要なOS機能が無ければBLOCK。親directoryの信頼性・所有者・秘密保管・承認はこのloaderで証明しない。private directoryと保管条件の審査は別に必要。

REFERENCES schema + SELECT ledger + 任意USAGEという既存判定は変更しない。入力の受理は本人認証・対象確認・実接続許可を意味しない。connector、credential loader、authority、durable nonceは追加しない。初回CIで旧guard変更が既存source pinに拒否されたため、guard本体を元のblobに戻し、入力保護だけ分離した。固定guard/旧CLIは変更せず、旧CLIを秘密情報の入力に使わない。将来の実tooling採用時に別のsource/SQL/reference審査が必要。

追加のCLI/file負例と既存grants testsをLinux3.9/3.12・macOS3.12で実行。#797の既存30件を固定checkoutで再利用し、対象差・期限切れ・取消・replay・同時thread・不足/過剰権限・偽の承認・TLS差を再実装しない。#797の旧source guardはmoving mainに合わせて緩和せず、過去の受入証跡を保持する。既存Release20 Transition Safetyを本PRのHEADで実行し、Schema Diagnostics・全20移行・名前差/衝突/部分DDL・独立logical restoreのMySQL8.0/8.4、関連PHPを再利用検証する。

## 現ゲート（実環境に対する判定）

| 項目 | 判定 | 境界 |
| --- | --- | --- |
| Source integrity | PASS（固定source、offline） | 実行機のclean/full history・採用tooling再審査は別 |
| SQL / Reference integrity | PASS（固定chain、offline） | 既存固定hashの証拠。実接続時の実物照合はNOT_RUN |
| Operator authentication | BLOCK | 実本人確認・信頼anchor未実装 |
| Approval authenticity | BLOCK | 自己発行HMAC/JSON/CI/GitHub commentは認可にならない |
| Target / TLS session binding | BLOCK | 実connectorなし。fixture内session一致はhandshake証拠ではない |
| One-shot durability | BLOCK | **UNIMPLEMENTED**。プロセス再起動を越える実storeなし |
| Minimal grants | UNVERIFIED | c09時のowner結果をe858実測へ転用しない |
| INDEX / FK names and definitions | UNVERIFIED | 合成比較のみ。実名差・M08依存・新設名衝突は個別評価 |
| TRIGGER visibility | UNVERIFIED | 観測0や一致だけで完全としない |
| Migration principal / binlog | UNVERIFIED | metadata inspectorを流用・拡権しない |
| Actual metadata collection | NOT_RUN | 実SELECT/SHOWを一切実行していない |

## 承認状態遷移の設計（未実装）

`DRAFT → IDENTITY_VERIFIED → SCOPE_REVIEWED → ISSUED → CONSUMED → RUNNING → COMPLETED`

現在は実 `IDENTITY_VERIFIED` より先へ進めない。#797のSYNTHETIC_VALIDATEDはこの実state機械への入力ではない。各段階の不明・不一致はBLOCK。ISSUEDから取消/期限切れ、CONSUMED以降の失敗・中断・timeoutは終端とし、同じ承認で再開しない。COMPLETEDもrelease GOではない。

1. **本人・authority**：ownerとoperatorの実本人、権限、独立した信頼anchorと取消手段を決める。公開commentやCI、ローカル自己署名、承認JSONの真偽値を代用しない。秘密鍵を承認ファイルへ同梱しない。
2. **固定scope**：source/release、独立tooling、SQL/reference hash、METADATA_ONLY_READ、対象service/DB/endpoint/port、CA/SAN、期待CURRENT_USER/engine/server UUID、operator、開始/満了時刻、nonce、1回、caps/limitsを私的に結合。対象実値をGitHub/ChatGPTへ出さない。未知のUUIDを取得するための接続も未承認で行わない。既存認可DBAの私的証拠が無ければ、別途bootstrap identity-only操作の設計・個別承認が先。
3. **永続消費**：信頼された環境で検証・失効確認・nonce消費をdurableな単一原子操作にし、最初のsocketより前に確定する。プロセス/host再起動、同時実行、途中crash、timeoutでも消費を戻さない。承認後の取消・期限を実行中も検査し停止する。ローカルSQLite/file lockだけで、store削除/複製/rollback/失効・時計・耐障害性まで保証したとしない。実行環境、管理者、storage/復旧/取消の信頼条件が未確定なので**実storeは未実装**、暫定実装や実接続CLIは作らない。
4. **TLS/session**：VERIFY_IDENTITYで承認済CA chainとhostname/SANを検証した同じ生きた接続に、Q0 identity・Q1 grants・全metadata・postflightを結合する。#796の別clientによる17 queryではこの保証をしない。別接続/再接続は新しい承認が必要。TLS弱化、host/CA fallback、接続先変更は禁止。自己申告session IDや同一UUIDだけではTLS検証と同一sessionを証明しない。
5. **実行**：権限・identity確認前に構造を読まない。許可はidentity/own SHOW GRANTS/ledger/information_schema構造だけで、app row SELECT、自由SQL、DDL/GRANT、dumpを含まない。既存最小権限を維持し、role/mandatory role等の実効権限も不明ならSTOP。SQL allowlist・行cap・cell128KiB/row256KiB・section8MiB/total16MiB・query10s/stream120s・raw秘匿を維持。
6. **終了**：失効、期限、Q0/Q1/postflight差、ledger差、件数/bytes/時間超過、client errorで中止・再試行しない。local process終了だけでremote query終了としない。実connectorにはserver側実行時間/停止とsession終了の検証が必要で、#796のDISPOSABLE_REMOTE_TERMINATION_UNVERIFIEDを承認票で解除しない。cleanup/終了が不確実ならBLOCKし、既存認可operatorへ私的に引き継ぐ。復旧としてGRANT・台帳編集・DDL・rollbackを行わない。

## オーナーの次の最小操作（接続承認を求める段階ではない）

**無接続の実行環境・信頼基盤の選定を1件**行う。既存の管理可能なoperator実行環境、独立した本人認証/approval authority、durable storeの管理/取消/再起動/複製・rollback耐性をどこで保証するか決める。有料resourceを作成・変更せず、採用可能な既存基盤の有無だけを確認する。共有は「候補あり/なし、認証方式の種類、永続化の種類、責任者/取消経路の確定可否」のみ。識別子・鍵・接続値は不要。

その選定後にCodexが実契約とクラッシュ/並行/取消・TLS統合テストを準備し、コードの安全性レビュー完了後、初めて**固定e858＋確定tooling＋単一対象＋本人operator＋期限＋1回**のmetadata-only個別承認を提示する。現時点で実行できるコピペ接続コマンドはない。ownerが実接続を承認したと推定しない。

事前STOP：authority/operator/対象値/TLS/nonce永続性/失効/remote停止のいずれか不明、source/SQL/ref差、広範権限、不可視objectを0件扱いする設計。TRIGGER完全可視性は既存認可DBAの別証拠が必要で、inspectorへTRIGGERをGRANTしない。名前のみ一致もstrict BLOCKを解除せず、名前衝突・M08依存・実データ由来の制約適用可能性を自動承認しない。

## 別ゲートの依存（今回の実装対象外）

#800の合成mysqldump復元はAiven Full自体のrestoreではない。FreeおよびDeveloper USD5/月のFork不可は確認済みで、upgradeを解決策にしない。実dump/restoreはmetadata監査と別承認。

暗号化/manifest/鍵管理/保持・削除、実MySQL8.4隔離先/容量、DEFINER・APP_KEY・private files・IdP、snapshot以降の同意取消/Account削除/session失効を巻き戻さない設計、全writer/DDL整合・RPO/RTOは既存[復旧条件](README.md)に残す。今回の最優先の次作業は**選定した実行・信頼基盤上での実本人認証とdurable one-shot契約の確定**。これらが未達なので「承認するだけで実監査可能」とは結論しない。

## 既存信頼基盤の実環境調査（2026-10-20）

**証拠と限界：** 既存GitHub main 067ea9b35a23 のAuth0/MCP資料、およびRenderのowner workspace My Workspaceで list_services、list_postgres_instances、list_key_value を読み取り。Auth0管理画面/APIや実Aivenへは接続せず、Render設定・課金・env・秘密・DB行の取得・操作もない。以下は認可を成立させる証拠でなく、既存リソースの使い道の限定である。

| 既存候補 | 観測した事実 | 判定 |
| --- | --- | --- |
| Auth0 MCP用テスト構成 | Canovia first-party account linkとChatGPT clientの分離、JWT認証・合成Development Planへの同意/取消の過去受入はGitHub資料で確認 | **未採用**。DB operatorの独立承認issuer/role/MFA/step-up、実tokenの即時取消、発行authorityと権限分離は未検証。MCP同意/トークンを実DBの許可へ流用不可 |
| Render Key Value | Session用Free Key Value、region Singapore、persistenceMode=off、noeviction、available（直接APIメタデータ観測） | **不適格**。cache上のnonceはprocess/host restart・復元・消去・rollbackへの耐久消費を保証しない。設定変更なし |
| Render PostgreSQL 17 | MCP staging専用、Free、available、expiresAt=2026-11-08T01:04:45Z、HAなし（直接APIメタデータ観測） | **不適格**。短い有効期限・専用staging境界・backup/耐rollback未証明。監査承認台帳へ混在不可。SQL接続・DB行閲覧なし |
| Render Web/MCP staging | WebはFree、Canovia本番Web/StaticとMCP stagingはそれぞれAuto Deploy OFF。承認専用管理アプリなし（取得したサービス一覧の範囲） | **不適格**。ログイン、稼働、GitHub CIは独立認可・一回限り台帳を証明しない |
| オーナーMac | #488にオフライン合成監査受入実績 | **operator端末の第一候補のみ**。OS login/端末管理/MFA、署名・取消と耐rollback永続台帳は別審査。ローカルSQLite/ファイルロックのみで永続消費の保証を主張しない |
| GitHub Issues/Actions | source・CI・監査の公開証跡に利用中 | **認可authorityには不適格**。comment/CI/JSON approved=trueを実操作権限として扱わない |

### 現時点の結論と次の人間による選択

**オーナーMacを実行場所の候補に絞る。ただし既存の独立した承認authorityとdurable atomic one-shot storeは検証できず、選定・本番採用は行わない。** 一回の実監査を行うためにMCP Staging・session Redisや本番Aivenへの台帳機能追加を勝手に行わない。

1. 本人認証：owner/operatorの本人確認、認証担当、実署名authorityと独立取消経路を選定する。Auth0利用時も専用client/audience/issuer/role/MFAの実契約を閲覧のみで確認し、MCP用client/Plan consentとは分離する。
2. 一回限り消費：専用の信頼ストアの実所在/責任者、ISSUED→CONSUMEDのatomic durability、並行・再起動・削除・復元・rollback・複製・時刻改変への対処と取消判定/監査ログを確認する。候補なしの場合はBLOCKのまま。
3. 実接続：発行主体の真正性とtarget/source/tooling/SQL/ref/TLS/期限binding、同じverified TLS session内のQ0/Q1・metadata/postflight、remote query終了証明が条件。個人行読取/DDL/拡権/credential loader/real connectorをまだ作らない。
4. 費用と隔離：新規有料resource・既存Auth0/Render/Aiven設定変更は別承認。Staging Postgres Freeの11月8日期限を長期監査ストアとして利用しない。

**次の設計分岐（いずれも未承認）**：A=現在の厳密な自動one-shotモデルを保ち、独立署名authority+復元後も消費が復活しないtrust storeの実証後にオフライン実装する。B=短期の人間監督による単発metadata監査へ要件を変更するが、現行の承認・durability/TLS保証からの設計変更が必要であり、ownerの明示的判断と新たな安全受入なしでは実接続してはならない。Bはフラグを立てて既存BLOCKを解除する方法ではない。

判定：OWNER_MAC_EXECUTION_CANDIDATE_ONLY / NO_EXISTING_DURABLE_TRUST_STORE_VALIDATED / AUTH0_OPERATOR_AUTHORITY_UNVERIFIED / REAL_AIVEN_NOT_RUN / TECHNICAL_WORK_REMAINING / PRODUCTION_NO_GO_HOLD。

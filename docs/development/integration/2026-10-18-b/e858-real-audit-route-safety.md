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
| 受渡し・寿命 | 入力は固定合成passwordだけ。context内でcapture→control→dataの順に各1回だけ同じbytearrayのreadonly memoryviewを固定 `SyntheticDriver` に貸与。任意driver/factoryの注入なし。入力開始から同じ120秒窓を消費し、入力後に期限をリセットせず期限切れhandoffを拒否。SQL拒否・driver作成失敗・割込でも両mockを停止し、全leaseをrelease→driver参照破棄→bufferゼロ化/破棄→TTY close。close不明は `R1_CLEANUP_UNVERIFIED` | Python private属性/readonly viewはtrusted callerを隔離しない。将来PyMySQLのimmutable内部コピー・寿命、debug/core/swap、例外context/local変数のprivate閲覧・完全消去は保証しない。mockのcopyなし検証をdriver実用検証にしない |
| 対象・CA・seal | service/DB/Host/Port/principal・SAN・VERIFY_IDENTITY/certificate/hostname/TLS1.2・source/release・独立reviewed tooling SHA・Q0/Q1/bundle hash・既存上限をfrozen contractと型も含め完全一致で照合。変更は入力前/driver作成時/SQL claim時に拒否。環境/option-file override/admin fallbackなし | CA bytes/hashは明記した**合成marker**でありX509証明書ではない。実CA file/既存private対象記録のbindingは未実装。tooling引数同士の一致は承認/checkout真正性ではなく、既存source-bound integrity/clean checkoutを別途再利用する。seal変更を許可しない |
| Phase1同等性 | 新mockは固定Q0/Q1 bytesをhash照合し既存 `Gate` を使う。既存 `Bootstrap.run()` をテスト側のmock Channelでそのまま実行し、15 SQL/2 channel・Control観測・normal QUIT marker・query終了とconnection不在の分離を比較。free SQL/追加接続/順序違反/期限延長/中断/観測不能はSTOPし同じattemptで再実行しない | mockのQUIT/不在はserver/TLSの観測証明ではない。実TLS/COM_QUIT/remote消滅の合成通信証拠は既存Phase1 MySQL8.0/8.4各18-case CIを再利用し、新TTY秘密が実wireへ渡ったとは主張しない。real session/UUID/grants/CIDはGO内でのみ検証 |

**新26テスト**：全contract項目変更、tooling/Q0/Q1改変、非TTY/未知FD・取得失敗・非継承/復元/close、actionなし、同一readonly bufferの2貸与・releaseと参照破棄、writer/driver作成/TTY・driver close失敗、割込/例外の固定出力、再入力・再開/期限切れ受渡し拒否、環境override無効、runtime変更なしの両engine mock受入、観測不能/割込の `REMOTE_TERMINATION_UNVERIFIED`、Gate改変/free SQL拒否後の再試行拒否、実接続/CLI/loader経路なし。既存#815 21＋Phase1 15＋B1 22＋TLS offline17を同じLinux3.9/3.12・Mac3.12 CIで回帰する。既存Full94/114/full17/KILL wireは共有差分がないため再実行しない。historical 10/18・10/21の照合を再依頼しない。

**管理Mac無接続受入へ進める条件**：このPRの固定HEADで上記CI・差分レビューが成功した後、管理Macの新しいclean checkoutから合成adapter/TTYテストだけを実行できる（具体的な固定HEAD/CI Run/コマンドはPR・Issue #488に記録）。実passwordも実CA file/接続先も入力しない。実fixture外への通信が必要、依存不一致、テスト失敗、cleanup不明ならSTOPし、実接続で解決しない。この受入はP1実行票やR欄の署名・実接続GOではない。

**次工程**：管理Macのこの合成無接続受入→別レビューの実用adapter候補（trusted TTY取得・private対象/既存CAのseal・PyMySQL受渡し/内部copy・単一principal/2接続・既存Gate/capsの保持）→完全実用SHA/配布物/依存固定とowner採用→P1票/R1〜R6の個別受入→新しいPhase1限定GO。現時点ではどの実用connectorも有効化しておらず、実接続GOまでの技術差分は残る。プロセス再起動をまたぐanti-replay/remote強制停止/実用principalの権限/実バックアップ復元も証明しない。

**維持**：`R1_DESIGN_SELECTED / LIVE_TOOLING_NOT_ADOPTED / REAL_AIVEN_NOT_RUN / B2_OWNER_GO_NOT_GRANTED / PRODUCTION_NO_GO_HOLD`。

## 2026-10-21 R1実PyMySQL境界（owned Dockerのみ）

[#816管理Mac26件受入6315407327](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6315407327)を**オーナー報告として再利用**。旧26件・10/18・10/21照合の再実行を依頼しない。以下は実用connector採用ではなく、mock後の新しいdriver境界だけを観測する。

- `e858_r1_driver_host.py` は既存Phase1 host/parserを固定 `r1-driver` profileで呼ぶ。series/SHA以外のtarget/CA/password/driver入力はない。既存fixture・CA生成・source-bound integrity・内部Docker network・最小principal・private trace/oracle・cleanupを再利用し、新しいGRANT/DDL/管理権限は追加しない。同じserver/workerで新8ケース→既存18ケースを1回ずつ実行し、Full94/114/full17/KILLを実行しない。
- `r1_driver_fixtures.py` は `pty.fork()` で**新しい合成controlling TTY**を作り、子session leader/parent/foreground pgrp・所有slaveと非継承FDを確認して固定 `/dev/tty` をopen。generic `/dev/tty` のinode/uidをslaveと同一と誤認せず、同じforeground pgrp/termiosと親が作った新sessionを照合する。既存#815非echo入力/復元を再利用し、parentはecho-off後に固定合成passwordだけを一度入力。通常のstdin読取fallbackや実Macのpassword promptはない。入力後はowned FDをcloseし、PTY stdout/stderrの1 byteでもあればBLOCK。parent timeout/子終了未確認はBLOCK/remote未確認、ローカル子cleanupをMySQL KILLと混同しない。
- `r1_driver.py` は固定source/release/tooling/SQL/Q0/Q1、fixture target/CA bytes hash/SAN・TLS1.2/CERT_REQUIRED/hostname、DNSと**認証前の実TCP peer**を照合。CAは読み取り専用fixtureの固定pathのみ、symlink/改変・debug logging・PyMySQL version不一致を拒否。owned内部IP/固定hostname以外、任意SQL/再接続/追加接続/権限fallbackは許可しない。source/Reference/strict判定は無変更。
- PyMySQL **1.1.2** の実constructorへ同じ合成bytearrayを渡し、`Connection.password is captured_buffer` を2接続で検査（constructorで新しいbytes/strへ変換しない）。`caching_sha2_password`認証完了/失敗直後に `password=b''` としてdriverの保持参照を外し、全wire（部分構築を含む）のclose・bufferゼロ化/参照破棄をfinallyで確認。新input・自動retryなし、秘密や原例外をreceiptへ入れない。
- **完全消去の限界**：1.1.2のbytearray受入はこの固定版での実測であり、将来版の公開API保証にしない。full TLS authの `password + NUL`、`write_packet` のimmutable bytes、認証hash/SSL/OS buffer等の一時コピーは生じる。fresh serverの最初のControlでfull TLS auth packetの存在を**内容を複製・出力せずboolだけ**観測する。driver保持参照とowned bufferの破棄から、Python/driver/SSL/OS/swap/coreの完全消去を主張しない。合成fixture定数自体の残留も実secret消去の証明ではない。
- 継承する既存 `Wire` のSQL Gate/上限/COM_QUITと `Bootstrap` のQ0/Q1・Control・通常15/最大42 SQL・2 TLS・query10秒/全体120秒・query/connection分離は変更しない。normal実通信は2TLS/15SQL、同じprincipal、TLS chain/SAN、driver alias、full auth、Dataのserver-side消滅、独立general_logによる全SQL/接続照合を検査。異常時にremote消滅を推測しない。

**差分検証**：新13 offlineテストをLinux3.9/3.12・Mac3.12へ追加（fresh controlling PTY、実constructor alias/破棄、seal/CA/symlink/debug/依存/peer/DNS/constructor失敗/親中断の子所有確認・回収/実CLI引数拒否）。MySQL8.0/8.4は新8ケース：normal、Data認証失敗、割込、QUIT write失敗、CA/target/tooling seal違反、TLS弱化。既存18-case baselineは同じfixtureで1回、旧101単体も回帰し、結果は固定HEAD/CIとともにPR/Issue #488へ記録。Macの既存26件に加え、**新13件もowner受入済み**（[6316794699](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6316794699)）。実TLS通信はLinux Docker証拠であり、管理Macの実TLS/Aiven接続成功ではない。旧26/13の再実行は依頼しない。

**最終採用審査へ提出する材料**：この新driver境界の両MySQL版CIとMac差分受入、既存source-bound証拠、完全SHA/依存/配布物と#813からの差分、R1コピー限界、P1実行票/R1〜R6をまとめる。ただし今回のcontrolling TTY/target/CA/credentialsは合成に固定されており、実用private設定を受け取るconnectorは有効化しない。管理Mac/実用target/CA/同一principalの本番binding、owner本人/P1リスク受入、採用する実用SHAと**新しい限定Phase1 GO**は別審査のまま。未知の実UUID/grants/CIDの事前probeを要求せずGO内のみ。異常時remote停止/実TRIGGER/移行権限/binlog/backup復元/full17/本番昇格は別ゲート。

**維持**：`LIVE_TOOLING_NOT_ADOPTED / REAL_AIVEN_NOT_RUN / B2_OWNER_GO_NOT_GRANTED / PRODUCTION_NO_GO_HOLD`。

## 2026-10-21 Phase 1実用tooling候補・採用審査パケット（無接続）

### 再利用する確定証拠と候補の種類

[#817管理Mac受入6316794699](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6316794699)は**owner報告として13件成功**、#816の26件は[6315407327](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6315407327)を再利用。#817検証HEAD `79798ed0591f80b9dbfa74109dde376c14899e18` と統合main `a10fd1c815f9c30a47ab81b59d1a5e7a7ec148cd` は同一tree `ce7bb8a3b1a18200f03ca506ca81455837dce4a1`。Phase1/R1 CI [39599644167](https://github.com/1kz-ma1/Canovia-web/actions/runs/39599644167)のMySQL8.0/8.4各新8＋既存18ケース、offline新13＋既存101、全10 workflow/21 check成功を再利用する。10/18・10/21のprivate照合も歴史的証拠のまま保持し、現在の権限/CA/sessionの証明へ昇格しない。

今回の成果は**合成実装を基礎にした非実行の審査候補**。現実用adapterは未実装・tooling未採用であり、#817や今回PRの完全SHAをそのまま実用SHAとして採用できない。合成host・password・CA・PTY限定を解除する変更は含めない。審査用inventory CLIはprivate値を受けず、TTY/CA/passwordを開かず、接続・package install・SQLを実行しない。

### 実用差分の審査結果（必要最小、まだ有効化しない）

| 境界 | 既に証明した範囲 | 将来の最小差分／追加受入・STOP |
|---|---|---|
| Trusted operator TTY | #815非echo・復元・1回、#816所有PTY、#817新sessionのcontrolling TTY | 実ownerの明示操作後だけ管理Macのcontrolling TTYを取得する単一用途entry。合成gesture/FD/PTY成功は本人認証ではない。本人/foreground/所有/復元不明なら入力前STOP。現在の自動合成入力を実password入力へ切り替えるflagは禁止 |
| private対象・CA binding | fixtureの単一host/IP/CA/SAN seal、認証前TCP peer、TLS CERT_REQUIRED＋hostname | 10/21の既存private記録を単一対象の期待値へ束縛するreader/format/権限/override拒否の審査が未完了。既存CA bytesと期待fingerprint/SANを将来だけ照合。DNS固定IPをAivenの動的endpointへ単純転用しない。host/DB/principalの実値・CA hash/pathは公開しない。古い記録の出典再調査は不要だが、その読取境界の実装は必要 |
| 資格情報・寿命 | PyMySQL1.1.2で同じbytearray aliasを2接続へ、auth直後driver参照解除、finally破棄、部分接続/割込・再試行拒否 | 合成password限定assertを解除する変更は未実装・別レビュー。live adapterの例外/local debug/core/driverコピーの扱いを差分検査。NUL packet/immutable bytes/hash/SSL/OS/swapの完全消去は不可（R1）。login-path抽出/環境/password引数/永続保存なし |
| 2 TLS・同一inspector | Control→Data各1、同一principal、no reconnect、Q0/Q1/Control継続identity、Data正常消滅 | private論理principalと未知host grant部分/最初のUUIDのsafe bootstrapが合成固定値との比較のまま。初回Control→Data Q0で同じidentityを要求し、不明role/権限/対応不能はSTOP。実UUID/grantsを事前probeして埋めない |
| SQL・caps | 上の完全操作一覧を維持、通常15/最大42 SQL、2 TLS、COM_QUIT、query10秒/入力から同じ120秒窓、128KiB/256KiB/8MiB/16MiB | 全実用entry/driver初期化も同じGateを通す必要。接続/SQL/権限が増える場合BLOCK、無断上限変更なし。Q0/Q1のbyte列は#795原本を継続利用 |
| 異常時・停止 | 認証失敗/割込/QUIT失敗等でremote未確認、追加SQL/接続/retryなし | 正常QUIT/local close・query idle・Data connection消滅を分離。Mac障害・Control断・timeout・failover・CID疑義は `REMOTE_TERMINATION_UNVERIFIED / HOLD`。Control自身の消滅も独立証明しない。KILL能力や異常時停止保証は採用対象外（R2〜R5）、private引継ぎ先と新票/新GOが必要 |

**結論**：新たな合成transportの全面実装は不要。残る技術差分は、上のtrusted実行入口と**単一private対象/CA/identityのbinding**を持つ別審査のadapter。それが存在しないままowner署名だけで現合成版をlive版へ昇格しない。今回は実用入口/設定readerを作らず、将来差分の採用条件を確定する。

### public source inventory・依存・配布物hash

`scripts/ci/e858_phase1_review.py --tooling-sha <完全HEAD>` は既存B `integrity()` のclean/full-history checkout、tracked bytes、固定refs/source/SQL/reference照合を再利用。その後、明示列挙した18 public基盤ファイルが#817受入HEADとbyte一致する場合だけ、各SHA256＋canonical source-index SHA256を固定JSONで出す。変更時は歴史的証拠の自動再利用を拒否する。今回のreview helper/test/workflow/本節も別にhash化する。生blob・private設定・実CAを出力しない。Git診断/引数/例外は固定BLOCKで秘匿する。

このinventoryは**列挙した審査対象の内容目録**であり、全依存の閉包・実行可能archive・実用tooling・承認証明ではない。自己参照を避け、完全HEAD/目録hashはcommit後のPR/Issue証跡に記録し、コードへ自分のSHAを書き戻さない。mainのSquash後はtree同一性を記録し、候補HEADを勝手に新mainへ置換しない。

| 配布・依存 | 現時点の固定／未達 |
|---|---|
| PyMySQL 1.1.2 | `pymysql-1.1.2-py3-none-any.whl` SHA256 **`e6b1d89711dd51f8f74b1631fe08f039e7d76cf67a42a323d3178f0f25762ed9`**。既存isolated hostの実hash検査を再利用。目録はwheelを取得/installしない |
| Python/SSL/OS | 管理Macの正確なPython executable・version/architecture・SSL runtime・配布物hashは実用配布として**未固定**。CI Python3.9/3.12やMac13件成功は実用runtime lockではない。秘密を含まない実行環境manifestと配布物検証が最終採用前に必要 |
| cryptography 46.0.3 | 現hostの**合成証明書生成用test依存**。Phase1 PyMySQLのstrict TLS認証はstdlib SSLを利用。これを無条件に実用runtime必須へ移植しない。test環境のplatform別wheel/transitive hashも実用配布lockと称さない |
| Docker/MySQL/python image | 既存合成fixture専用。mutable tagはimmutable digestではない。実用Mac配布物にroot oracle/fixture setup/合成password/管理SQLを含めて有効化しない |
| 実用配布物 | **NOT_BUILT**。将来の別adapter完全SHA・レビュー済みファイル集合・正確なartifact SHA256・runtime依存lockが必要。GitHub自動archiveのzip hashを再現可能な実用package hashとみなさない |

**今回の差分検証**：新6 offlineテスト（目録決定性/不認可、基盤各file改変で歴史的証拠再利用拒否、既存integrity失敗時の拒否、秘密引数/例外/割込の固定出力、目録内容非露出）。同じLinux3.9/3.12・Mac3.12 CIで目録を実行し、既存114単体は回帰。runtime/host/fixed資産の差分がないためMySQL8.0/8.4 wireは **NOT_RUN / #817証拠再利用**。新wire-scope guardは該当基盤/test変更、base不明、手動dispatchでは従来実行を維持する。Full94/114/full17/KILLも再実行しない。

### 最後の管理Mac無接続受入と採用順序

1. **今回の審査候補の差分受入**：固定HEADのclean full-history checkoutで新6テスト＋public inventory生成をCI Linux/Macで確認する。入力/TTY/driverの変更がないため、**今回の目録だけを理由とする管理Macの追加受入は不要**。#81626/#81713・実CA/対象・password・Docker/SQLの再実行は依頼しない。固定HEAD/CI/目録hashはPR/Issue #488。成功しても実用SHA採用にならない。
2. **技術作業**：上表の実用adapter最小差分とpublic runtime/artifact lockを別PRで作り、実接続起動は閉じたまま単一対象/TTY/identity/秘匿/期限の差分をレビュー・Mac無接続受入。今回6件はその将来差分の受入を代替しない。ここまで未実装があるため現時点で実GOを依頼しない。
3. **オーナー採用審査（未承認）**：レビュー/CI/Mac差分受入済みの**実用adapter完全SHA**とartifact/依存hashを私的P1欄へ採用。既存private対象/CA記録のbinding・本人/端末・保管期限・障害private引継ぎを確定し、R1〜R6を**個別**に受入/拒否。owner署名は技術的未達をPASSへ変換しない。今回PRや目録への受入はこの採用ではない。
4. **別のPhase1接続直前GO（NOT_GRANTED）**：実用SHA採用・P1全欄・R欄の後、対象1件/owner監督/15–42全SQL/2TLS/≤120秒/1回・再試行不可の新GO。そのGO内だけで現在Q0/Q1/CA/TLS/sessionを検証。full17/KILL/移行/公開のGOとは別。

**現在の次工程＝技術作業**（private bindingを持つ別adapterと配布lockの差分準備）。今回の目録受入だけのために実パスワード・実CA/接続先を共有する必要はない。ownerに今すぐ追加テストや接続判断は求めない。**最後の管理Mac無接続受入は、将来の実用adapterで変わるtrusted入力/private binding/runtime配布境界だけ**を対象とし、既存26/13は再利用する。その固定候補ができた段階で実用採用・P1/R欄を判断し、実GOを別に扱う。

**維持**：`LIVE_TOOLING_NOT_ADOPTED / REAL_AIVEN_NOT_RUN / B2_OWNER_GO_NOT_GRANTED / PRODUCTION_NO_GO_HOLD`。

## 2026-10-21 Phase 1限定private adapter候補（INERT、接続不能）

[#818独立レビュー6318010899](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6318010899)に対応する限定実装。上の#818で未実装だった**private record reader・未知identityの受入契約・実constructorへの受渡し・候補archive**を具体化する。既存#813〜#817のruntime/SQL/transport/TTYは変更しない。10/18・10/21の照合、管理Mac26/13、#817 MySQL8.0/8.4 CI39599644167を再利用。これらを現在の実対象/権限/実TLSの受入へ昇格しない。

### 単一private対象・CA形式とreader

`private_binding.read_binding(directory_fd, expected_record_hash, tooling_sha, now)` は将来私的に取得・確認したdirectory FDのdupを所有し、**固定basename `target.json` と `ca.pem` だけ**を読むlibrary。今回読むのはテストが新規作成する合成ファイルのみ。実private path入力CLI・credentials reader・実CA取得はない。

- owner/euid一致、directory mode0700、file mode0600・regular・hardlinkなしを要求。`dir_fd`＋O_NOFOLLOW/O_NONBLOCK/O_CLOEXEC、file/dirの前後stat・bounded readを使い、symlink/FIFO/不正FD/緩いmode/oversize/途中変更/close失敗を固定BLOCKにする。record≤16KiB、CA≤64KiBで、既存metadata capsは変更しない。parent pathの安全なFD取得、native ACL/管理端末の信頼性はmode検査だけでは証明せず、実用entryの別審査に残す。
- record SHA256の**独立した私的期待値**と原bytesを照合してから既存B JSON boundary（duplicate/depth/type/size）でdecode。record自身が宣言するhashやapproved欄を信頼anchorにしない。期待hashの私的審査は本人承認の実装ではない。hash/private値をreceiptへ出さない。
- exact schemaは `kind/provenance/source_sha/release_ref/tooling_sha/sql_sha256/q0_sha256/q1_sha256/starts_at/expires_at/target/tls`。kind=`E858_PHASE1_PRIVATE_BINDING_V1`、provenance=`OWNER_PRIVATE_2026_10_21` は歴史的出典の申告で、署名・現在値証明ではない。targetは単一objectの `service/database/host/port/principal` のみ、tlsは `mode/minimum/san/ca_sha256` のみ。未知field/複数対象・期限切れ・seal改変を拒否。実UUID/grants/CIDの記入欄は設けずGO前probeを要求しない。
- source/release/Q0/Q1/bundleの既存固定値と**別途reviewした完全tooling SHA**を照合。DBは既存validatorが扱うASCII identifier、principalは保守的なASCII32字内、hostは小文字DNS名（raw IP/wildcard/曖昧表記を拒否）、portは1–65535整数、SAN期待名は同じhost。許容外の実値があっても正規化/推測せず別レビューでBLOCKする。
- CAは独立record seal内の期待hashと一致し、private keyを含まずSSLContextでparseできること。CERT_REQUIRED＋check_hostname＋TLS1.2以上、CA default store・環境overrideなし。**DNSを解決せずIPも固定しない**。Aivenの動的IPをDocker固定IPへ置換しない。実handshakeのCA chain/SAN/peer/session連続性は未実行であり、このfile検査を実TLS証明にしない。

### Trusted TTY・資格情報・未知identity

`private_candidate.Candidate` はsource固定Q0/Q1 bytesとPyMySQL1.1.2/debug無効を要求し、既存 `SharedBudget` と `Gate` を使用する。入力開始前から同じ120秒窓を消費し、expiry/timeout後に受渡ししない。`WindowedTTY` は#815のcapture/echo/復元を継承し、read hookだけを元のattempt残時間でclampする（新しい120秒へリセットしない）。hookの上限/EOF/制御文字/期限を新差分テストで確認する。enumやprivate recordは本人認証・実GOではない。

- `capture_synthetic_ctty()` は**fresh pty.fork child/session leaderに限定**。明示合成action・parent・foreground pgrp・所有slave/euid・character TTY・非継承FDを確認してfixed `/dev/tty` をopen、#815非echo/復元を再利用。固定合成passwordだけを受け付け、通常の管理Macから実passwordを入力する入口は作らない。標準入力fallback/再入力/ログ/保存/環境/argv/login-path抽出なし。実owner管理Macのtrusted TTY取得・明示操作・native ACL/親directory bindingを有効化するentryは**未実装・別審査**で、合成のparent/gesture検査をその代用にしない。
- `prepare_pair()` は同じprincipal/CA/targetと同じmutable bufferを、Control→Dataの**実PyMySQL constructor**へ各1回だけ渡す。`DisabledWire` は既存Wire/StrictConnectionの契約を継承しつつ、connect/auth/SQL/normal QUIT要求を無条件拒否してattemptをcloseする。**実接続数0／送信SQL0／TLS0**。2つのdeferred objectを2 TLS成功と呼ばない。任意factory/endpoint switch/接続許可flagはない。
- constructor途中失敗/割込/入力・SQL要求失敗/期限切れ/再entryで停止。context/finallyで両driverのpassword参照解除、元bufferのbest-effortゼロ化、target/identity/CID参照を解除し、不確実cleanupはBLOCK。driver一時packet/immutable bytes、Python/SSL/OS/swap/core/debuggerの完全消去は保証しない。実認証後の参照解除/full authは#817証拠を再利用し、今回のdeferred constructor試験から新しい実認証成功を推論しない。
- `observe_identity()` は**オフライン入力seam**。初回ControlでDB・logical principal・許可engine（8.0/8.4）・UUID形式・handshake CIDを検査し、未知のCURRENT_USER host部分/UUIDをprivateにlatchする。Q1は既存strict grants validatorを再利用し、recipientも当該CURRENT_USERに一致させる。Dataは同じidentity＋別CID、以後Control/Dataも完全一致を要求。UUID/CID/account切替・未知role/不足/過剰権限・期限切れはSTOP、@%やUUIDを事前推測しない。このseamの自己申告は本物のTLS/grants証明ではなく、将来の既定SQLからだけ供給する実用bridgeの別レビューが必要。
- 実用runnerを本PRで起動しない。固定Q0/Q1・全SQL表・15/42・2TLS・10/120秒・caps・Control観測/COM_QUITは既存正本どおり。新SQL/接続/権限を追加しない。異常時は追加SQL/再接続/KILL/admin fallbackなし。正常close/remote消滅は今回観測しておらず、reportは常に `REMOTE_TERMINATION_UNVERIFIED`。既存正常close証拠を異常時の遠隔停止能力へ昇格しない。

### 再現可能な候補archiveとruntime lockの境界

`private_distribution` は固定Git HEADの13 library source、#795の原本Q0/Q1、hash-pinned PyMySQL wheel、生成manifestの**17 members**をdeterministic ZIP_STORED（固定mtime/owner mode/順序）へまとめる。host CLI/Docker fixture/実private record/実CA/passwordは配布しない。shared libraryの固定合成機能は存在するが、本候補からlive connectorに切替できない。全runtime依存の承認済み実行packageではなく **INERT library候補archive** である。

- `e858_private_archive.py` はpublic wheel/sourceからarchiveを作るオフラインCLIだけ。`--tooling-sha/--wheel/--output`以外（target/CA/credential/connect mode等）は拒否。既存B integrityでclean/full-history/fixed refs/source/SQL/referenceを照合し、Git blobからだけ構築。出力はrepo外の新規file0600、既存fileを上書きしない。秘密引数/例外/詳細pathを表示せず固定BLOCKにする。
- 検証は**別にreviewした完全HEAD/pins/archive SHA256**を期待値に使い、全member hash/manifest/source/ref/Q0/Q1/wheelを照合。未知member/path traversal/重複/symlink/compression/size超過/改変は拒否。verifierはextract/実行しない。CIだけが検証済み固定public memberを使い捨てdirへ展開してsocket audit hookで通信拒否したimport閉包を確認する。
- PyMySQL wheelの既存SHA256 `e6b1d89711dd51f8f74b1631fe08f039e7d76cf67a42a323d3178f0f25762ed9` を再利用。manifestに自分のarchive hashは埋め込まず、commit後に固定HEAD/生成SHAをPR/Issueへ記録する（自己参照なし）。同じHEAD/wheelならLinux/Macで同じarchive bytesを要求する。#818 public source目録のhashとは別物。
- `runtime_components()` は**実行した機械だけ**のPython version/architecture/OS、実executable SHA256、SSL extension SHA256（builtinならspecで確認してexecutableに含まれると区別）、OpenSSL version、installed PyMySQL source-set hashを取得する。絶対pathは出さない。`verify_runtime(expected)` は別にreviewした期待値との完全一致だけを確認し、不明/変更はBLOCK。今回のCI/Linux観測値を管理Macのhashにしない。
- native dyld/OpenSSL/system library/OS全体の依存閉包・管理Macのruntime provenance/署名/実package installは**未検証**。`runtime_complete=false` / `linkage_closure=UNVERIFIED` を常に残す。自己採取snapshotの比較だけでowner採用や完全なruntime lockを成立させない。cryptography46.0.3は合成CA生成test依存であり、live TLSはstdlib SSLの別審査。管理Mac binary hashを推測で記載しない。

### 差分受入・採用審査への判定

新25 offlineテスト＋CI archive/import閉包1件。private形式/seal/CA/TLS弱化/期限/型、mode/FD/symlink/hardlink/FIFO/巨大file/途中変更、環境無効、秘密非出力、fresh合成ctty→実constructor2個のalias/破棄、部分失敗/割込/reentry/close不明、identity/grants/CID切替、接続要求拒否、archive改変/余剰member/重複/link/圧縮、runtime component不一致を検証する。既存#81521・Phase1 15・B1 22・TLS17・review6を必要範囲の回帰とする。

旧#81626/#81713は固定基盤・そのtestが無変更なら再実行せずowner受入/CIを再利用。MySQL8.0/8.4も旧wire profileの既存基盤に差分がないため**NOT_RUN**、#817を再利用。新private libraryを既存wire profileへ黙って注入しない。該当基盤/test変更、base不明、dispatchでは従来の回帰を実行。旧94/114/full17/KILLのwireも今回対象外。

**結論：限定候補のソース・archive差分レビューへは進めるが、最終の実用tooling採用条件は未達。追加の技術差分が残る。** (1) 管理Macのtrusted owner entry/私的directory FD取得とnative ACLを含む信頼境界、(2) 今回latchを将来の実TLS/既定Q0/Q1/Controlへ束縛するbridge（現在のrunnerは合成固定で、新candidateは接続不能）、(3) 管理Macの実runtime/dependency linkageと配布lock。この3点は実Aivenへの事前probeで埋めず、別の無接続差分・採用審査で扱う。connect拒否を単に削除したりflagで解除したりしない。将来のactivation変更も新しい完全SHA/受入が必要。

オーナーの次操作は**まだ実接続GOではない**。最終SHA/CI/archive hashを示す本限定候補の新しい差分だけ、管理Mac無接続で受け入れるかを審査できる（旧26/13は再利用、実CA/対象/password不要）。今後の実用候補が技術ゲートを満たしても、**完全SHA＋artifact/依存lock採用 → P1票 → R1〜R6個別受入 → 新しいPhase1限定GO** はそれぞれ別判断。現在の候補archive採用と本番用実行toolingの採用を混同しない。

**維持**：`LIVE_TOOLING_NOT_ADOPTED / REAL_AIVEN_NOT_RUN / B2_OWNER_GO_NOT_GRANTED / PRODUCTION_NO_GO_HOLD`。

## 2026-10-22 Phase 1 private-root FD事前検査（INERT・owner trust未成立）

#819のprivate bindingが要求する「既に安全に取得されたdirectory FD」に対し、**合成ディレクトリ限定**の次段階を設ける。新library `scripts/ci/e858_phase1/private_root.py` は、caller所有の既存anchor FDを受け取り、固定子名 `phase1-private` だけを `dir_fd` + `O_DIRECTORY/O_NOFOLLOW/O_CLOEXEC/O_NONBLOCK` で開く。親・子のUID/euid、mode0700、非継承、同一device、前後のstat identityを検査し、取得した子FDの所有権をcontext managerが保持する。差分テストは使い捨て合成ディレクトリでのみ行う。失敗時は固定BLOCKとし、未知または再利用されたFDを閉じるfallbackをしない。引数にパスワード・CA・endpoint・任意パスは取らない。

**安全性の限界**：anchorの祖先path安全性、native macOS ACL、本人認証、署名・取消・durable one-shot、実root trustは**すべてUNVERIFIED**。POSIX mode0700 / FD identityだけでは、rootを実用上「trusted」と判定しない。assessmentは常に `execution_allowed=false` と明示し、実用operator entry・credential prompt・connector・実Aiven接続は存在しない。将来のtrusted root取得・ACL/端末の独立検証と、実TLS/identity bridge、runtime/配布物固定は別差分。今回のFDから既存`read_binding`へ実private値を渡す操作は禁止し、合成のみで検証する。

実装差分：root library・新rootテスト・CI対象・public INERT archive member・import閉包・本正本のみ。新archiveは以前の#819の17 membersから**18 members**へ変わる独立新候補であり、#819のarchive SHA256 `a3d3f640...` を流用しない。新HEADでのSHA/CIはPRに記録し、review/採用前に固定する。旧#816 Mac26／#817 Mac13／#817隔離MySQLは保護済み基盤が不変なら証跡再利用し、無用な再実行をしない。過去の10/18・10/21照合も保持。

状態：`INERT_ROOT_PREFLIGHT_ONLY / OWNER_TRUST_UNVERIFIED / LIVE_TOOLING_NOT_ADOPTED / REAL_AIVEN_NOT_RUN / B2_OWNER_GO_NOT_GRANTED / PRODUCTION_NO_GO_HOLD`。

## 2026-10-22 Darwin native ACL観測の限定候補（INERT、合成FDのみ）

#820のprivate root FD事前検査に続く、**macOS native extended ACLが付いていないか**をFDから読み取り拒否するための最小境界。新`scripts/ci/e858_phase1/private_acl.py`は、Darwin上の既存caller-owned directory FDに対して`acl_get_fd_np(fd, ACL_TYPE_EXTENDED)`と`acl_valid`/`acl_get_entry(ACL_FIRST_ENTRY)`、`acl_free`で観測する。無効なACL、entriesあり、予期しない取得エラー、関数/cleanup失敗、対象FDのmode/所有者/identity変更などは固定BLOCK。macOS-15の合成temp directoryで実測した `acl_get_fd_np` の `NULL/ENOENT` は `ACL_RECORD_ENOENT_UNVERIFIED` として**ACL空の証明から分離**し、認可・実用acceptには昇格しない。正常取得かつvalid後のzero-entry観測だけを別分類とする。OSがDarwinでなければBLOCK。読み取り/参照のみでset/modifyはしない。ACLの生内容・FD番号・詳細エラーをreceiptへ出さない。APIはApple公式BSD acl man pageに従うが、合成macOS上の挙動のCI受入は別途要確認。

これは**ACLがないと観測したFD1つの補助証拠**であり、native ACLなしがpath祖先・実private record・OS管理状態の信頼性やowner本人認証を意味することはない。rootの祖先経路、本人/画面収録/管理端末、実ファイル内容、署名と失効・durable one-shot・native runtimeの完全閉包はすべて未検証。返値には`execution_allowed=false / ancestor_trust=UNVERIFIED / operator_identity=UNVERIFIED`を保持。実password/CA/target、実Aiven、TTY取得、2 TLS、SQL、productionに新しい入口は設けない。

新規`test_e858_private_acl.py`はsynthetic temp directoryのみで、macOS時はreal native FD読み取り、他OSではBLOCK、他にACL present/error/FD無効/変更時STOPとreport非認可を検証。#820の旧7、#819旧25、#816 Mac26/#817 Mac13、旧MySQL通信証拠は変更された境界だけ必要に応じて再利用し、新しいarchive member・shaを旧18件に流用しない。最終SHA/CIと新archive SHAはPR・Issue #488へ記録し、実用tooling採用には転用しない。

残ゲート：実owner trusted entry、親祖先path/OS管理状態の検証、native runtime lock、実TLS/identity bridge、P1/R1〜R6と独立新GO。状態：`INERT_NATIVE_ACL_OBSERVER_ONLY / OPERATOR_TRUST_UNVERIFIED / LIVE_TOOLING_NOT_ADOPTED / REAL_AIVEN_NOT_RUN / B2_OWNER_GO_NOT_GRANTED / PRODUCTION_NO_GO_HOLD`。

## 2026-10-22 private root親子FD逆方向照合（INERT、限定差分）

#820で追加した `open_private_root(anchor_fd)` が子 `phase1-private` を開く際に、子FDから `dir_fd` 付き `os.open("..", O_DIRECTORY|O_NOFOLLOW|O_CLOEXEC|O_NONBLOCK)` で逆方向の親FDを別途取得し、**最初に受け取ったanchor FDと同じdevice/inodeの直接親であること**を検証する。owner/euid、mode0700、非継承、前後stat identityを既存検査とともに確認する。親参照FDのclose失敗は固定BLOCKとする。この結果は「同一時点に観測した1階層の親子関係」の証拠にすぎず、祖先全体のpath trustや別processによる競合更新のないことを保証しない。

新規3テストでは、合成ディレクトリの正常関係、別のmode0700/euid所有parent FDを返す差し替え、`..` 取得失敗時の子FD cleanupと例外秘匿を確認する。既存root7/ACL6/private candidate25＋archiveはCIの差分回帰で確認する。既存の固定SQL、B/R1 wire runner、実TTY、実credential、live connector、production、固定releaseは変更しない。旧#816 Mac26・#817 Mac13と隔離MySQL通信は基盤無変更を前提に従来証拠を再利用する。

追加の直近親照合がPASSでも、callerが最初に渡したanchorの由来、native ACLの `ENOENT`、管理Macのoperator本人認証、実runtimeの署名・依存閉包、実Aiven TLS/identityは**UNVERIFIED**。INERT archiveのhashは新PR検証HEADで再生成し、#821のSHAをそのまま採用しない。採用権限や実AivenのGOを新設しない。

状態：`INERT_PARENT_LINK_OBSERVED_ONLY / ANCESTOR_TRUST_UNVERIFIED / LIVE_TOOLING_NOT_ADOPTED / REAL_AIVEN_NOT_RUN / B2_OWNER_GO_NOT_GRANTED / PRODUCTION_NO_GO_HOLD`。

## 2026-10-22 管理端末のforeground TTY観測候補（INERT・実本人認証ではない）

#822までのprivate-root FD/ACL/直近親子関係の観測と、既存#815〜#817の合成TTY資格情報テストは**実owner本人の操作証明と別**である。本差分は欠けていた「秘密を読む前に前面の端末・process group・現在のセッション状態を観測する」read-onlyの局所境界だけを追加する。

`scripts/ci/e858_phase1/private_operator.py` は、**callerが既に取得済み**のnon-inheritable FDについてDarwin/main thread/FD>2、character TTY、euidとFD owner、前面process group、session ID、termios状態、前後FD identityを参照・照合する。パス指定や `/dev/tty` open、端末read/write、エコー/termios変更、password取得、stdout/stderr、認可スイッチ、接続CLIは一切持たない。異常/非foreground/非Darwin/worker thread/identity変更は固定BLOCK、詳細例外・FD/端末値は返さない。観測FDはcaller所有であり閉じたり貸与したりしない。

返すのは `FOREGROUND_PROCESS_GROUP_OBSERVED_NOT_AUTHENTICATED` に相当する**局所的な状態のみ**で、`operator_identity=UNVERIFIED`、`explicit_owner_gesture=UNVERIFIED`、`ancestor_trust=UNVERIFIED`、`execution_allowed=false`、`real_credential_read=false` を常に維持する。端末のforeground/euid条件だけでは実ownerの意思・認証・管理Macの信頼性を証明できない。通常端末から実passwordを読める機能に自動移行したり、#817の合成gestureを実署名へ昇格しない。

専用`test_e858_private_operator.py`はOSが生成した**使い捨てPTYのみ**、foreground判定はmockし、現実のman-in-the-loop認証や管理Mac受入とは主張しない。Linux Python3.9/3.12・macOS Python3.12で追加テスト＋既存root/ACL/candidate・INERT archive/import closureをCIで検証する。旧#816のMac26件、#817のMac13件、#817隔離MySQL wire、歴史的10/18・10/21 private照合は既存の未変更証拠を再利用。差分には固定Q0/Q1・既存run・TLS/SQL/host/production/releaseは含めない。

**次に必要な実用ゲート**：ユーザー管理Macでの別の信頼済みoperator entry/明示操作、private anchorの祖先とnative ACL（ENOENTの未検証分類）、実用runtime配布lock、実TLS/identity bridge。この技術差分を満たして初めて新tooling完全SHA・P1/R1〜R6個別受入と新Phase1限定GOを別判断する。本PRはそのGOの代理ではない。

状態：`FOREGROUND_TTY_OBSERVATION_ONLY / OWNER_GESTURE_UNVERIFIED / LIVE_TOOLING_NOT_ADOPTED / REAL_AIVEN_NOT_RUN / B2_OWNER_GO_NOT_GRANTED / PRODUCTION_NO_GO_HOLD`。

## 2026-10-22 PINNED PyMySQL wheelと実インストールソースの一致検査（無接続・INERT）

#823までに `runtime_components` は現Python binary / SSL extension / PyMySQL 1.1.2のバージョンとインストール済みsource tree digestを観測済み。ただし版番号のみでは、事前固定した実wheelの内容と今回のrunnerに実際にインストールされているソースとの一致を証明しない。この限定差分では、新しい実行入口やユーザー管理Macへの操作要求を作らず、従来の`private_distribution.py`へ`verify_pymysql_wheel_installation(wheel_bytes)`を追加する。

**照合する範囲**：旧#819の固定`pymysql-1.1.2-py3-none-any.whl`をSHA256 `e6b1d89711dd51f8f74b1631fe08f039e7d76cf67a42a323d3178f0f25762ed9`で照合。wheel内の`pymysql/**.py`（固定上限付き）を全てhash化し、現runnerの`importlib.metadata.distribution('PyMySQL')`のRECORD列挙ファイル・実ファイルのバイトハッシュと完全一致するかを観測する。現在importされる`pymysql.__file__`と配布`__init__.py`が同一ファイルであることも確認。wheel不正、版違い、wheel内の重複/異常path、source改変/不足、import path差し替え、読み取り例外を固定BLOCKとする。固定wheel以外を信頼済み依存として受け付けるfallbackを置かない。

**証拠の限界**：これはRECORD列挙済みPython sourceのbyte一致だけである。`runtime_components()`が返すPython binary / SSL extension hashと別に、`_ssl`からの動的リンク先・OpenSSL dylib/共有キャッシュ・macOS codesign/notarization、追加の未列挙ソース/import hook、trusted origin/親directory FD、path alias、owner本人のMacが正しい端末かの評価は**UNVERIFIED**。新判定の`runtime_complete=false / live_tooling_adopted=false / execution_allowed=false`を固定する。実用runtime配布lock・実private対象/CA/TTY/password・実接続・TLS/SQL・production昇格は今回の範囲外。

**検証**：新`test_e858_private_runtime_lock.py`は各CIで既存のハッシュ固定INERT archiveからwheelを取り出し、正常一致・wheel改変・インストール済みsource改変（mock）・配布metadata不可・別import実体を確認。Linux3.9/3.12＋macOS3.12の既存archive build後にのみ実行し、root10/ACL6/operator7/candidate25、旧Mac26/13・#817合成MySQL通信証拠は無変更部分を再利用。新候補archiveのHEADに依存するSHAは新PRのCIで確定し、旧#823のhashを転用しない。実user管理Macのruntime lockは別のowner審査に残し、CI観測を本人署名やP1/R欄/実GOに格上げしない。

状態：`PINNED_WHEEL_INSTALLED_SOURCES_OBSERVED_ONLY / NATIVE_LINKAGE_UNVERIFIED / OWNER_RUNTIME_LOCK_UNADOPTED / REAL_AIVEN_NOT_RUN / B2_OWNER_GO_NOT_GRANTED / PRODUCTION_NO_GO_HOLD`。

## 2026-10-22 Native Runtime直接リンク・署名の観測候補（Darwin限定・無接続）

#824では固定wheelとrunner上のPyMySQLインストール済みPython sourceの一致を検証したが、Macで実際にPython実行体や`_ssl`拡張がどのネイティブライブラリへリンクし、ローカル署名検証がどう返るかは未観測。本差分は、これらを**値非露出・読み取り専用・管理Macで同じ操作を再実行可能なオフライン**の観測材料へ限定して追加する。

`private_native_runtime.observe_native_runtime()` は `sys.executable` と使用中の `_ssl.__file__`（built-inの場合は未検証と明記）だけを対象に、ファイルのbytes SHA256と前後statを確認する。固定の `/usr/bin/otool -L` と `/usr/bin/codesign --verify --strict` をシェルなし・8秒制限・サニタイズ環境・標準入力無効で呼び出す。ライブラリの実path、署名やエラーの生詳細は公開しない。直接依存の件数・ハッシュと、`LOCAL_CODESIGN_EXIT_ZERO_NOT_OWNER_TRUST`（または `UNVERIFIED`）の限定状態だけを返す。形式不正/バイナリ途中変更/観測例外は固定BLOCK。対象のパス/コマンドはユーザー指定できない。TLS通信・パスワード/CA/秘密、TTY read/write、アプリ起動・署名変更・installはしない。

`scripts/ci/e858_native_runtime_review.py` は追加引数を受け付けない無接続CLIで、macOS以外や観測失敗なら固定 `INERT_NATIVE_RUNTIME_OBSERVATION_BLOCKED` と終了2。観測が成立しても `runtime_complete=false / live_tooling_adopted=false / execution_allowed=false / real_aiven_connected=false`。将来管理Macの**独立レビュー済みclean checkout**上で `python3 -I -B scripts/ci/e858_native_runtime_review.py` のように実行し、秘密を含まない結果だけownerがレビューできる。今回のGitHub CIのMacは本人の管理Macではなく、結果を実用tooling採用値へ流用しない。

**未成立の信頼境界**：`otool -L`は直接依存の観測であり、再帰的dyld解決・実際にloadされた共有cache/ライブラリ・nested plugin/import hook・環境の起源・親パスACL・developer Team ID/Notarization/Gatekeeper審査を証明しない。`codesign --verify`終了0はローカル署名形式の確認であり、コードの発行元がownerにより信頼された証明ではない。動的リンク閉包・OS管理状態・operator本人意思は常に `UNVERIFIED`。完全package/runtime lockと採用完全SHA、P1/R1〜R6個別受入、新Phase1限定GOは別ゲート。合成で全テストPASSでも実Aiven・本番へ接続可能にはならない。

**検証**：新 `test_e858_private_native_runtime.py` でLinux BLOCK、synthetic Darwin/Apple tools mock、入力path変更・tamper・Codesign失敗時の誤昇格阻止、実macOS runnerのread-only実行、実CLIの拒否契約を確認。既存root/ACL/foreground/wheel/Private candidateとarchive/import閉包を再実行。INERT archiveは新sourceを追加し完全HEADごとに再hash。既存Mac26/13/隔離MySQL8.0/8.4は変更外なら証拠再利用。実管理Macの診断実行を今回要求しない。

状態：`DARWIN_NATIVE_DIRECT_LINKS_OBSERVABLE / CODESIGN_LOCAL_ONLY / OWNER_MACHINE_TRUST_UNVERIFIED / LIVE_TOOLING_NOT_ADOPTED / REAL_AIVEN_NOT_RUN / B2_OWNER_GO_NOT_GRANTED / PRODUCTION_NO_GO_HOLD`。

## 2026-10-22 INERT証拠の統合評価と実用採用HOLD（新しいGO経路なし）

#820〜#825で実装したprivate root FD・macOS ACL・foreground TTY・PyMySQL固定wheel・native runtime・deterministic archiveの**個別観測**は、技術的に異なるものを証明する。観測成功をowner本人認証、発行者権限、実private target/CA、動的依存閉包、失効可能なone-shot storeまたは実Aiven接続GOに連鎖させることは禁止する。

`scripts/ci/e858_phase1/private_acceptance.py` の `review_inert_receipts(receipts)` は、これらの既存receiptについて固定の**型と非認可フィールド**を照合して、どの形式の合成証拠が提出されたかだけを表示する。外部で生成した辞書は自由に偽造でき、origin署名ではないため、成功でも`UNTRUSTED_RECEIPT_SHAPE_ONLY`の分類以外を与えない。渡された生値、ホスト/証明書/FD/署名値/実path、未知フィールド、原例外は出力しない。承認フィールド、実passwordや秘密を読む方法、ネットワーク/SQL/TTY/永続化、実権限token・nonce消費・CLI/live switchを新設しない。予期しない形式・偽の実行許可フラグ・verified identity claimは固定BLOCK。

**必ず残すHOLDゲート**：親anchor祖先の信頼性、ACL（特に`ENOENT`未検証）、オーナー本人の明示操作、信頼された署名発行元とruntimeの推移的動的リンク、実private target/CAとTLS identity bridge、独立承認authority、再起動・取消・rollbackに耐えるdurable one-shot、審査済み実用SHA+完全配布物lock、私的P1とR1〜R6の各受入、単発Phase1専用の新owner GO。固定出力は `OWNER_ADOPTION_HOLD / execution_allowed=false / runtime_complete=false / real_aiven_connected=false / production_go=false`。入力が空でも合成証拠が全種類揃っても絶対に`GO`を返さない。

**確定済みの設計選択（#803、2026-10-20）**：今回の実Aiven e858 metadata-only監査には **B=人間監督下の単発監査** を既に採用している。**A=独立署名authorityと復旧・rollbackにも耐えるdurable one-shotは将来構想**であり、現在のB1/B2審査の必須条件に戻さない。Bではowner/operator本人による独立したprivate対象・実行1回の接続直前GO、期限、手続による再試行禁止・実行中断時の新規GOを求める。ただし人間監督とprivate receiptをAの暗号学的・永続的replay保護と同等とは主張しない。**B設計採用（B0）と新しい実接続許可（B2）は別**であり、後者は未付与。実用Trusted Operator Entry・実TLS/Identity Bridge・固定tooling SHA/配布lock・P1/R1〜R6受入が未達のため、実監査は引き続きBLOCKする。

CIでは無入力/合成全種類/ACL ENOENT・zero-entry/偽造GO・operatorなりすまし/原値非露出/輸入閉包の8テストをLinux3.9/3.12、macOS3.12で回帰し、基盤無変更の既存Mac26/13とMySQL wireは証拠再利用する。INERT Archiveの新ソース追加に伴うHashは当該review HEADで再生成し、過去値を再利用しない。これらの合成テストやGitHub mergeは、本人管理Macでの実用採用・実Aiven監査・本番昇格の承認と異なる。

状態：`B_DESIGN_ADOPTED_2026_10_20 / B1_OFFLINE_PACKET_IMPLEMENTED_PRIVATE_ACCEPTANCE_PENDING / B2_FRESH_OWNER_GO_NOT_GRANTED / FUTURE_A_DEFERRED / LIVE_TOOLING_NOT_ADOPTED / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO_HOLD`。

## 2026-10-22 B案採用決定の機械判定への反映（#803/#804正本整合）

2026-10-20の [PR #803](https://github.com/1kz-ma1/Canovia-web/pull/803) によるowner確定判断を最優先する。**Bは今回の単発metadata監査の採用済みベースライン、Aは将来の自動監査のFuture Design**。PR #826の「A/B未決定・独立issuer／durable nonce ledgerが今回Bの必須」という記述・出力は誤りであり、今回修正する。

`private_acceptance.review_inert_receipts` は `current_design=B_OWNER_SUPERVISED_SINGLE_METADATA_AUDIT_ADOPTED` と `future_design=A_INDEPENDENT_SIGNING_AND_DURABLE_NONCE_DEFERRED` を分離する。B0〜B4はそれぞれ「2026-10-20設計採用済み」「既存#804の無接続B1 packetを実装済み・private実受入は未了」「新しいB2接続前owner GOは未付与」「B3実監査未実施」「B4実データの証拠未取得」と分類する。#804の実`e858_b_offline.receipt`を合成したテストデータとして同一HOLD reviewerへ渡せるが、receiptは自由に偽造可能・非署名であり真のowner監督・本人確認・現在のtarget/CA・TLS結果とはみなさない。異常な実行許可・本人確認詐称・未知分類は拒否する。

**今回のB実用ゲート**はprivate anchor祖先・ACL・明示本人操作、限定runtime信頼、実target/CAとTLS+server/current-user identity、B1 private operator/target/frozen packetの照合、**人間による同一許可での再実行禁止と再GO手続**、審査済み実用tooling完全SHA+配布lock、私的P1/R1〜R6の個別受入、そして接続直前のB2限定GO。A方式の独立承認authority・rollback-proof nonce storeはこのゲートには含まない（将来別計画で実装する）。これらが不足する限り `OWNER_ADOPTION_HOLD / execution_allowed=false / real_aiven_connected=false / production_go=false` は不変。プランB選択済みの事実は実接続開始命令とは扱わない。

新テストは旧#826の8件を更新し、実#804 B1 synthetic receiptとの互換、偽B2承認、A-onlyゲートの混入防止、旧ACL ENOENT厳格分類、未入力HOLDをLinux3.9/3.12とmacOS3.12で回帰。固定e858 source/release、実SQL/transport、Mac既存26/13、MySQL隔離wire、実Aiven/productionを変更しない。

## 2026-10-22 B1 Trusted Operator Entry 結合リハーサル（無接続・オーナー認証は未成立）

既存PR #820〜#823はcaller由来が未確定のprivate anchor FD、Darwin native ACL、foreground TTY FDを独立観測する。B案（owner採用済み#803）の **B1の次の技術差分**として、これらを**同一呼出しで組み合わせ、中途不整合時に全体をBLOCK**するオフラインlibrary `private_entry.review_b_operator_entry(anchor_fd, terminal_fd)` を追加する。固定`phase1-private`子directory以外は開かず、callerがすでに持っている非継承anchor/TTY FDのみ受け付ける。実Homeからのroot祖先の証明、real TTY取得、ownerの明示操作は**まだ未実装**であり、このリハーサルを完全なTrusted Operator Entryとは称さない。

検査順はDarwin/FD型と別FD/非継承 → anchor owner/mode0700 → anchor native ACL → 固定子のdir_fd O_DIRECTORY|O_NOFOLLOW|O_CLOEXEC|O_NONBLOCK取得と逆親照合 → 子native ACL → 既存foreground TTY/read-only termios検査 → 親/端末の前後stat・FD再検査 → 子FDを確実にclose、という構成。途中で権限/ACL/FD/TTY/関係/後始末に不確実性があれば固定BLOCK。実行中に外部processによるrename等のTOCTOUを完全排除するものではなく、anchor祖先由来は`UNVERIFIED`を維持する。Darwin合成環境で`acl_get_fd_np`が返す`ENOENT`は`ACL_RECORD_ENOENT_UNVERIFIED`であり、ゼロACL証明やGOへ昇格させない。

**禁止のまま**：`target.json`/`ca.pem`のread、現実password/TTY read/write・画面操作・private path/host/FD/raw ACLの出力・Aiven/SQL/TLS接続・B2 GO・実用driver生成・再実行switch。返値は固定分類だけで`B1_PRIVATE_ACCEPTANCE_PENDING / B2_NOT_GRANTED / execution_allowed=false / target_ca_read=false / credential_read=false / real_aiven_connected=false`を維持。callerのanchor/TTY FDは所有せずcloseしない。固定子FDのみclose。成功してもowner本人認証済みとはみなさない。

新`test_e858_private_entry.py` では、OSが作成する使い捨て0700ディレクトリ・PTYだけを対象に、Mac CIは**real native ACLのread-only観測**とmock TTY、Linuxはmock Darwin合成検査/非Darwin拒否、既存root/ACL/TTY helper組み合わせの不正入力・ACL誤格上げ・TTY identity偽装・子FD cleanup・秘密非出力を検証する。既存base script/driver/固定e858監査資産に変更を加えない。INERT Archive source memberを1追加し、検証HEADごとに新hashをCIで算出する。旧#816 Mac26/#817 Mac13・隔離MySQL wire、#827 B0採用の意思決定とB1 packetは再利用。GitHubのCI成功は管理Mac実機を受け入れた証拠ではない。

**B1の引き続く技術課題**：管理Macの信頼できるanchor祖先と非同期変更監視、ACL ENOENTの扱いと私的保存場所のowner受入、実TTY上での本人の明示操作、real runtime/tooling完全SHA・配布lock、固定private target/CAのbindingとVERIFY_IDENTITY TLS/current user/server identity bridge。B2接続直前の新GOは、これらの技術とP1/R1〜R6の個別受入とはさらに別。A案の独立署名・耐rollback storeは将来設計のまま。

状態：`B_DESIGN_ADOPTED / B1_ENTRY_INTEGRATION_REHEARSAL_ONLY / ANCHOR_ORIGIN_UNVERIFIED / ACL_ENOENT_UNVERIFIED / OWNER_GESTURE_UNVERIFIED / LIVE_TOOLING_NOT_ADOPTED / B2_FRESH_OWNER_GO_NOT_GRANTED / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO_HOLD`。

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

### B1 integrated candidate — supervised entry, same-session bridge, review bundle (2026-10-22)

This delta implements the adopted **B** design (#803/#827); independent signed authority and durable rollback-resistant one-shot remain future A work. It neither adopts live tooling nor grants B2. Historical 10/18 and 10/21 evidence is reused, not refreshed into a current live claim. The single integration PR replaces another sequence of tiny receipt-only PRs.

- `e858_phase1/b1_operator.py` owns the no-follow directory FD chain from `/`, checks every parent/name relation, owners/modes, native ACL and FD stability, and owns foreground controlling `/dev/tty`. The managed OS/root is B's external trust base. Linux namespace-root ownership is **synthetic only**, never Mac acceptance. An explicit supervised invocation is required; a Python boolean or TTY observation is **not owner authentication or GO**. Private binding uses the existing bounded reader and independently reviewed record hash; every operation rechecks the record/window. The existing R1 non-echo reader/restoration and 120-second budget are reused. Replaced FDs are not closed as though still owned. Failure consumes the in-process attempt; new process/reboot is governed by B's human no-retry rule, not A-grade anti-replay.
- Darwin ACL capability uses XNU `bsd/sys/unistd.h` `_PC_EXTENDED_SECURITY_NP=13` through `fpathconf` (CPython does not expose that name). Successful `fgetattrlist(ATTR_CMN_EXTENDED_SECURITY)` is parsed using XNU `attr.h`/`kauth.h` layouts with strict lengths and deny-only ACEs. An empty buffer requires independently reported supported filesystem capability; unsupported/error/ALLOW/unknown formats BLOCK. This is a **new native observation**, not promotion of `ACL_RECORD_ENOENT_UNVERIFIED`; the legacy classification remains unchanged. Management-Mac filesystem/native semantics remain owner-machine acceptance even after synthetic macOS CI.
- `b1_bridge.py` joins that entry and binding to the #817 real PyMySQL driver. The only working backend remains the owned, internal Docker fixture, with fixed synthetic host/CA/principal/password and private IP guard. No live endpoint/credential CLI, loader, login-path extraction, network override, approval token or live backend is added. Private-target values accepted by the inert #819 library cannot make the integrated bridge connect outside the fixture. A future enabled live adapter needs separately reviewed B1 private/machine acceptance and B2; do not remove the guard via a flag.
- Control/Data use the same inspector, two separate TLS connections. Current UUID/account host/CIDs are learned only inside that attempt, not via a pre-GO probe. The existing identity/grants latch is reused. Connection object, TLS socket, CID and connect-count are checked throughout; Control observes only this Data CID and revalidates identity/grants before every observation. Normal Data COM_QUIT and independent server-side absence are distinct facts. Interrupt, unknown visibility, stale/mismatched identity, timeout, connection loss or uncertain cleanup HOLD; no KILL, reconnect, SQL fallback or admin fallback is used. CID reuse/failover races are not proved impossible by synthetic tests.

Exact SQL inventory: Phase1 preserves #813 **normal 15 / maximum 42**, two TLS connections, 120 seconds, query 10 seconds, and the existing row/cell/section/total/wire caps. The driver initialization remains exactly `SET NAMES ascii`; connection IDs, Control identity, PROCESSLIST and COM_QUIT follow the existing inventory. An explicitly separate **metadata scope** inserts the remaining 15 entries of frozen #797 `ORDER` after the initial Q0/Q1 (CID remains an auxiliary statement). It therefore has **normal 30 / maximum 57 SQL**, not a silent increase to Phase1. The fixed 17 metadata statements and their relative order/bytes, source/reference seals and pure #795 strict comparator are unchanged. Metadata scope is not authorized by a future Phase1-only GO. No hidden `SET`, KILL, ping, autocommit, fallback or reconnect is introduced. Query/connection disappearance is only claimed after normal close and independent observation; client deadlines do not prove abnormal remote cancellation. Real unexpected termination remains `REMOTE_TERMINATION_UNVERIFIED` and needs private DBA handoff plus a new approval, never a retry with the original GO.

`e858_b1_host.py` reuses the unchanged Full94 prepare, fixed e858 migration source/vendor contract, internal network, test CA and ownership cleanup. It installs the entire old94 **synthetic** schema and dispatches only the new integration cases (not the old privileged KILL exercise). The fixture root account is solely an independent synthetic wire/connection oracle; it is not a proposed real inspector privilege. General-log SQL/CIDs stay in disposable private fixture files and are matched against every actual inspector statement; shared results contain only fixed classifications and counts. The new strict-tamper negative mutates **synthetic collected metadata in memory**, not a reproduction of actual Aiven naming. Existing #809/#817 naming, TLS chain/SAN/expired certificates, Full94/114 and abnormal server-termination evidence is reused where the driver did not change.

`b1_package.py` reuses and embeds the old deterministic INERT archive, adds the actual new entry/bridge/integrity sources, and bundles original #795 comparator/SQL/8.0+8.4 references from their fixed Git object. Exact members, permissions, caps, manifest hashes, full tooling SHA and outer hash are verified. It is an **offline review distribution with no live launcher**. `b1_integrity.py` compares actual checkout bytes to the archive AND Git, rejects unknown/ignored files in the execution modules, and compares the entire installed PyMySQL package tree to the pinned wheel, including rejection of extra `.py`/native modules/symlinks/`.pyc`. Use a fresh environment installed with `--no-compile`, `PYTHONDONTWRITEBYTECODE=1`, and Python `-I -B`; stale caches do not receive a waiver. Loaded PyMySQL module origins must correspond to that installation.

The Darwin runtime check traverses actually loaded dyld images and declared non-system dependencies, hashes observed binaries, resolves loader/executable-relative references and requires an a unique resolution through explicit Mach-O LC_RPATH entries or loaded-image LC_ID_DYLIB for `@rpath`. Unsupported/ambiguous dependencies BLOCK. Native signature verification remains observation, **not trust by exit code**. OS shared-cache/system components and software origin are explicit owner OS/provenance dependencies; they are not declared byte-closed, notarized or authenticated by these probes. The existing Python/_ssl/version/OpenSSL component lock must match an independently reviewed management-Mac observation. CI's Python/framework/hash is never substituted for that Mac's runtime. Python/driver/SSL/OS secret copies cannot be fully erased; owned mutable buffers and driver references are cleared, full memory erasure is not promised.

B0: fixed e858/release and B design unchanged. B1: integrated implementation and synthetic evidence must be assessed at the exact verified HEAD recorded in [PR #829](https://github.com/1kz-ma1/Canovia-web/pull/829) and Issue #488; machine/private acceptance remains separate. B2: **NOT_GRANTED**. B3: **REAL_AIVEN_NOT_RUN**. B4: no real receipt exists. Strict schema BLOCK, minimal grants, trigger visibility, migration-principal/binlog, real backup/restore and production gates remain intact. Public-roadmap change is unnecessary for this internal preparation.

Owner's next operation is **offline acceptance only**, after green exact-HEAD CI and review: obtain the reviewed clean checkout and public bundle by complete SHA/hash; on the managed Mac use a clean pinned environment and run only the new synthetic PTY/root/integrity tests plus public bundle/runtime comparison. No actual `target.json`, CA or password is required. Privately assess trusted OS/Python origin/linkage (including system cache), private-root ancestor/ACL policy and the already recorded 10/21 target/CA evidence's applicability to that candidate; do not repeat historical SQL/connection checks. Do not publish paths, target names, binary error messages, CA/private hashes or credentials. Share only fixed acceptance statuses and public tooling/bundle hashes. If an ACL/runtime dependency is unknown, a file/FD changes, or cleanup is uncertain, STOP/HOLD and privately hand off the fixed stage; do not weaken the check. Formal tooling adoption, P1/R1–R6 residual-risk acceptance and a fresh narrowly scoped B2 GO remain distinct owner decisions. No live command is supplied by this PR.

B1 candidate operation count and verification boundary: Phase1 stays 15/42; metadata scope is 30/57. The new offline suite comprises 16 entry + 15 integrity/negative + 1 bundle/import case per CI runtime; the isolated Full94 profile has 11 cases per MySQL version. Grant and metadata corruption negatives are in-memory synthetic output mutations, not changes to real privileges/schema. A zero-duration synthetic query budget exercises the existing timer/STOP path; it is not proof that every MySQL SQL form obeys a server-side timeout. Independent fixture root observation proves only that synthetic sessions are eventually absent; abnormal production receipts remain UNVERIFIED even when that fixture oracle observes cleanup. Owner-machine/private acceptance and the actual-enabled backend remain BLOCKED pending separate review/approval; no automatic GO is derived from any report.

### Issue #830 — private-target practical adapter candidate (activation remains closed)

Latest implementation is based on #829 main `01787a11b69f266fbf1e024b1c4bf7ffa3806e48`. Reuse the owner-reported Mac 32/32 and public bundle hash match; runtime Darwin25.6.0/arm64/Python3.12.15/OpenSSL3.5.9, Homebrew consistency, native graph 36 non-system/347 OS trust/0 unresolved and local signature exit0 remain **OWNER_REPORTED_OBSERVATION**, not independent OS/package provenance. The service/DB/principal-intent/CA 4/4 comparison remains **OWNER_REPORTED_MATCH**, not actual CA-byte/current-TLS/current-grants verification. No historical SQL/CA/host check is requested again. The B design adopted #803 is unchanged; A authority/durable nonce is not a prerequisite.

**Implemented delta (not merely another receipt):** `practical.Program` verifies the actual source files and loaded frozen comparator bytes against a verified public bundle; exact installed PyMySQL wheel contents/origins, critical loaded stdlib origins/file hashes and runtime component hashes are required. Frozen/built-in stdlib belongs to the pinned Python binary; unknown origin/content drift BLOCK, while distribution/OS provenance remains owner acceptance. The archive now includes deterministic fixed-source migration facts and the absent-object contract for the original pure comparator. On Darwin, the existing native graph is captured once and loaded image identities/file hashes are rechecked without publishing paths; OS/shared-cache/provenance stay an external managed-Mac trust boundary. CI's runtime hash is never the owner's expected runtime. `Program`'s comparison is not independent authentication: the reviewed expected public bundle and private runtime lock must come from the operator's separately accepted P1.

`PrivateReview` reads only a fixed `review.json` in a separately protected `Root`, with an independently supplied private expected digest. `ReviewedEntry` reuses #829's ancestor/ACL/foreground TTY and R1 reader, but binds the target using that separate review origin; identical/ancestor/descendant private roots are refused. Both roots and all relevant files are rechecked, including native file ACL, mode0600/owner/nlink1, bounded reads and FD/name consistency. The bounded `private_binding` parser is reused, with a file-ACL callback and post-read pathname identity check. Source/tooling/bundle/wheel/SQL/reference/runtime/scope/engine/attempt/time must match exactly; unknown fields, unknown origin, weak ACL/permissions, races or expiry BLOCK. The record's only accepted state is `OWNER_REVIEW_PENDING_NOT_GO`; `approved=true` and actual-approval states are rejected. It is an offline review contract, not owner authentication, B2 issuance or an authority. Digests/private values are library-only inputs, never CLI/environment/receipt fields; no implementation reads an actual owner file in this task.

`PrivateWire` implements the real deferred PyMySQL constructor using the one reviewed service's DNS hostname/port/database/principal and verified CA **bytes**, without a Docker/static-IP assumption. It requires CERT_REQUIRED, hostname/SAN verification, TLS1.2+, disables common-name fallback and shares the original bounded SQL/protocol transport. The same mutable R1 secret is handed to Control then Data; password references are removed even on auth/partial-construction failure, and the owner buffer is cleared at attempt end. Immutable packets/hash temporaries, SSL/OS/core/swap and arbitrary trusted-caller introspection cannot be completely erased. No login-path extraction, password file/argv/environment/clipboard, input fallback or re-input is added.

**Hard activation boundary:** shipped `Adapter.prepare()` and `PrivateWire.connect()` unconditionally BLOCK. There is no live backend/launcher, no network/GO flag, no environment override, no injectable production factory. Even a syntactically valid actual private target cannot start DNS/TCP or password capture through the default adapter. `prepare_reviewed()` contains the reusable engine setup for a later reviewed activation; it does not open a connection. Test-only `practical_fixture` (excluded from the review bundle) supplies a concrete backend only for the host-attested internal Docker namespace, fixed synthetic secrets and permitted synthetic hostnames/CAs. It resolves freshly for each connection, admits only owned fixture addresses, binds the actual peer and SNI/CA/SAN on that TLS connection, and does not turn its fixture IP membership into an Aiven policy. Unit DNS rotation is simulated; real dynamic DNS/CDN/proxy/failover equivalence is not proved by that simulation. A future real activation is a separate code review and explicit owner adoption/P1/R1–R6/fresh B2 stage, not an unlocked option on this candidate.

The #829 **same engine** is reused (small preparation/secret-validation hooks and target-derived PROCESSLIST checks). It preserves Phase1 normal15/max42, metadata normal30/max57 **as a separate scope**, two TLS, same inspector, fixed Q0/Q1/14-query bytes/17 order/reference/strict BLOCK, query10s/entry120s and existing wire/row/cell/section caps. UUID and the unknown account-host portion of CURRENT_USER/grants are latched only on the first authenticated Control session and must match Data and subsequent observations; no pre-GO probe or guessed grants are introduced. The normal-COM_QUIT/local-close/query-idle/Data-absent facts remain distinct. Unknown visibility, server identity, CID continuity, Control loss, timeout, interruption, partial failure, close uncertainty or retry terminates in HOLD/REMOTE_TERMINATION_UNVERIFIED; no KILL/reconnect/admin fallback or extra SQL exists. Control's own remote disappearance is not independently proved. B's human single-use policy is not durable anti-replay.

**Delta evidence:** new 18 offline cases cover the public caller/program/private-origin boundary, separate owner record hashes, seals/scope/runtime/time, bad ACL/mode/link/FIFO/size, replacement/revocation, secret alias and default zero-network path. The new actual PyMySQL/Full94 profile has 15 cases per MySQL8.0/8.4 (normal Phase1/metadata, CA/hostname/auth rejection before SQL, grants excess/missing, server-identity mutation, Control loss, reconnect, SQL/export rejection, timeout, interruption and QUIT failure), followed by the affected #829 eleven-case engine regression on the **same owned fixture**. Failure cases assert the specific execution stage/certificate-or-auth classification, not just any exception. Server-identity and grants mutations are explicitly synthetic response mutations, not actual Aiven/failover experiments. The independent synthetic root oracle reconciles every actual inspector SQL and observes final session absence; no real inspector privilege is inferred. Source/SQL/reference/release/production are unchanged. Existing unaffected #813/#817 wire/owner 26/13 evidence is reused. Internal preparation does not affect the public roadmap.

**Management-Mac differential acceptance, not a repeat of accepted 32:** on the final exact reviewed HEAD, in a clean pinned `--no-compile` environment and Python `-I -B`, rebuild/verify the public bundle using `test_e858_b1_bundle.py` (its content/import closure changed), then run only `test_e858_practical.py` (18 new cases). `B1_BUILD_DIR` contains only the fixed public wheel and generated public archive, outside Git; no actual target.json, CA, password or Aiven connection is used. Compare only the public bundle hash from the final Issue #488 packet. The old 32/26/13 are regression-CI/history, not a new owner assignment. A failure/unknown native dependency or altered source/file/FD stops without weakening checks. Do not post private paths, runtime fingerprints, CA bytes, grant text or identifying errors.

#### P1 final private acceptance sheet — no field is accepted automatically

This extends the existing P1 and **R1–R6** rather than replacing them. All following owner fields are **UNFILLED / NOT_ACCEPTED**, including where earlier owner observations exist. Keep the completed sheet and signatures private, not in GitHub/CI/Codex. The code's `review.json` remains a pending review draft; it is not this signature or GO.

| Owner-only entry | Required private content / STOP | Current status |
|---|---|---|
| Candidate adoption | Full verified tooling SHA, reproducible bundle hash, fixed e858 source/release, SQL/Q0/Q1/reference and wheel hashes, source/import/checkout check | UNFILLED; final public evidence in Issue #488 |
| Runtime/managed Mac | Independently reviewed Python/_ssl/OpenSSL/OS/Homebrew/bottle origin, native graph and expected private runtime lock, trusted ancestors/native ACL/foreground terminal; the reported 347 OS trust dependencies and codesign exit0 are not independent acceptance | OWNER_REPORTED observations only; NOT_ACCEPTED |
| One private target | Existing 10/21 evidence binding to one service/DB/host/port/principal/CA/SAN; independent expected record/review digests and separate protected roots; no actual current UUID/grants pre-probe | 4/4 OWNER_REPORTED_MATCH only; NOT_ACCEPTED |
| Person / supervised operation | Owner identity and signature, named operator and managed Mac/terminal, explicit supervised action, private contact; TTY facts do not authenticate the person | UNFILLED / UNSIGNED |
| Attempt / scope / time | Unique attempt ID, scope **phase1 only** unless separately reviewed metadata scope, UTC start/end (local JST+9 may be recorded separately), max120s including input, two connections, no retry on failure/restart | UNFILLED; B2 NOT_GRANTED |
| Storage / deletion | Local non-synced0700 area/raw0600, who may inspect, explicit retention deadline and verified deletion procedure; no export/log/credential persistence | UNFILLED |
| Stop / private handoff | Named existing authorized DBA/contact and emergency private channel if Mac/Control/network fails or remote status unknown; record fixed stage/time privately, no new SQL/KILL/GRANT/DDL/backup to diagnose, no same-GO retry | UNFILLED |
| R1–R6 | Separate ACCEPT / REJECT / date / owner signature for **each** existing risk below; reject/blank/unknown means HOLD | ALL SIX NOT_ACCEPTED |
| Future activation / B2 | Independently reviewed activation code (currently absent), full adopted SHA and P1/R1–R6; then a new single-attempt owner GO immediately before connection, with cancellation and no reuse | NOT_IMPLEMENTED / NOT_GRANTED |

R1 (secret copies/terminal/OS limits), R2 (shared Mac/network failure), R3 (DNS/proxy/failover/CID uncertainty), R4 (normal QUIT vs forced stop/Control visibility), R5 (least grants/revocation/observability and procedural one-shot) and R6 (scope/privacy/no release implication) retain their existing substantive definitions and individual acceptance requirement. A signed sheet does not repair a technical failure or waive strict BLOCK. Missing/unknown fields stop before any real activation; do not ask for the values publicly. The next owner operation, once CI/review are complete, is only the **new 18-case no-connection delta + changed public bundle**; formal P1, live adoption/activation and B2 remain subsequent distinct decisions.

Status: `LIVE_TOOLING_NOT_ADOPTED / B2_FRESH_OWNER_GO_NOT_GRANTED / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO_HOLD`. Synthetic CI and main integration cannot elevate any of them.

### B1 offline completion delta — terminal STOP and post-start integrity (2026-10-23)

Starting main `29471a25bd2b40319cc49447ea8bf07f8b327194` contains #831 unchanged. Reuse #488's subsequent owner-reported 18+1 management-Mac acceptance, R1–R6 desk reviews and P1-readiness triage (comment `6345247849`); do not reclassify any of them as formal risk acceptance/signature, independent runtime provenance or a B2 GO. Trusted Root/native ACL/foreground TTY, private target/CA and independent expected review digest, R1 capture/two-driver handoff and fixed engine are implemented by #829/#831. This delta completes two demonstrated gaps in their reusable offline checks, without introducing another entrypoint or approval system.

- **Post-start dependencies:** `Program.check()` now revalidates the entire pinned installed PyMySQL tree and loaded wheel origins on every existing integrity check, and verifies loaded bundled-module `__file__` **and** import-spec origin against the corresponding reviewed source file. The previous check verified wheel contents/origins only at construction and did not bind loaded public-library origins. Synthetic reproduction on #831 allowed a newly added unreviewed wheel file and changed driver/public module origins; they now BLOCK. Existing source/stdlib/native drift and loader/keylog/debug rejection remain. These are file/origin drift checks within a trusted process, not independent software provenance or protection against arbitrary malicious Python/debugger/OS code.
- **Terminal STOP:** a failing `PrivateReview.check()` closes its owned root, clears its value and latches the object closed; a failing `ReviewedEntry.check()` closes both private roots and the owned TTY, discards the R1 mutable secret and clears target/review references. Restoring file permissions/content, the clock or terminal conditions cannot resume the same objects after a failed/interrupted check. Cleanup errors remain fixed BLOCK; local reference cleanup is not remote query cancellation or full memory erasure. New processes/host restart still require B's human no-retry discipline; durable anti-replay and live consent revocation are not implemented.

New `test_e858_practical_stop.py` has **9** differential cases: an actual extra installed-wheel file, loaded-driver origin, bundled-module file/spec origins, ACL failure followed by repair, expiry followed by clock restoration, cleanup-error privacy, and actual synthetic private-PTY capture followed by target failure or TTY interruption. The latter assert owned FD closure, buffer erasure and refusal of recapture/re-entry. No actual owner target/CA/password is used. The owned MySQL8.0/8.4 profile adds only **REVIEW_REVOKED** during the existing attempt: no extra SQL after STOP, no retry, terminated local entry and REMOTE_TERMINATION_UNVERIFIED/HOLD; the synthetic root oracle separately reconciles SQL and final connection absence. The affected profile is now 16 candidate + 11 existing common-engine cases per version. Exact-HEAD test/CI, artifact hash and integration tree evidence belong in Issue #488; test inventory alone is not a PASS claim.

`Adapter.prepare()` and `PrivateWire.connect()` remain unconditionally BLOCK; generic constructor/setup cannot start a live session. There is no new target/password/CA CLI, backend/factory toggle, credential loader, owner authentication or signature mechanism. Source/release/SQL/reference/strict comparison, Phase1 15/42 and separate metadata30/57, two TLS, 10-second query/120-second attempt and all byte/row caps are unchanged. No app, migration, production, Render, backup or live account operation belongs to this change. Internal safety preparation does not require public-roadmap changes.

**Next owner-only acceptance:** after exact-HEAD CI and review, run only the new **9** no-connection cases and changed public bundle/import test, using the reviewed complete tooling SHA and published bundle hash in #488. Reuse accepted 18/32/26/13 and historical target/CA reports; do not repeat actual SQL or populate the still-empty real private roots. Stop on integrity/ACL/cleanup uncertainty and share only fixed classifications/public hashes. P1 readiness preparation and R1–R6 design review are complete as preparation; all six formal ACCEPT/REJECT/date/signatures, P1 signature, independent runtime/target/operator trust, final tooling adoption and separate reviewed activation/fresh Phase1 B2 remain owner-private decisions, not results inferred from CI. Normal QUIT/Data absence does not prove abnormal remote cancellation or Control's own remote disappearance. Full metadata/schema/TRIGGER/migration/recovery/release gates remain separate.

`R1_TO_R6_REVIEWED_NOT_ACCEPTED / P1_NOT_SIGNED / LIVE_TOOLING_NOT_ADOPTED / B2_FRESH_OWNER_GO_NOT_GRANTED / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO_HOLD`

### Phase1 application-boundary candidate — offline review only (2026-10-23)

Instruction: #488 comment `6352979929`, based on main `c7504fe942025cc8e1d4af3dfc0eeaafe740bbcc` / #835. Reuse owner-reported bundle1 + stop9 PASS/hash MATCH (`6352897055`), older 18/32/26/13 and isolated MySQL evidence. These are neither a P1 signature nor acceptance of R1–R6, runtime provenance or real connection authority. No further management-Mac action is requested for **this offline candidate-review task**.

The remaining application gap was composition: the existing library accepted both separately scoped Phase1/metadata setup, but no dedicated review boundary joined an already-bound trusted entry with separately supplied expected **public tooling SHA/artifact hash** for **Phase1 only**, then released the private context without reading a password or creating a driver. New `phase1_application.Phase1ApplicationReview` implements only that offline operation; it is not a launcher, connector, approval authority or replacement for #835.

| Boundary / order | Reused implementation and new composition | Offline result / STOP |
|---|---|---|
| Trusted private origin / operator | Caller must transfer an already acquired **exact** `ReviewedEntry`; `entry.check()` rechecks Root/name/FD/native ACL and foreground TTY. `PrivateReview.check()` rechecks its separate protected origin and independent private expected digest | Unknown entry/record, mutation, expiry or TTY/ACL failure BLOCK before setup; person authenticity is not inferred |
| Public candidate / runtime | Existing `Program.check()` validates source/release/SQL/reference/wheel, loaded source/stdlib/native drift and loader/keylog/debug settings; new application requires independently supplied expected public tooling/artifact hashes to match | Hash drift or a metadata-scope review BLOCK; caller-supplied pins themselves are not independent provenance or GO |
| One target / CA / identity | Existing `ReviewedEntry.bind_review`/reader binds one service/DB/host/port/inspector, exact CA bytes/SAN intent and time to the independently reviewed record. Reuse `Adapter.prepare_reviewed()` only for the fixed engine/identity validator's **nonconnecting setup** | CA parse/hash is not handshake proof; current effective principal/grants/UUID/CID stay REAL_SESSION_UNVERIFIED until a future separately authorized verified session |
| Credentials / sockets | Application refuses an already captured secret, never calls capture, driver factory, identity observation, DNS or socket. It has no run/connect/activate/capture/factory API, CLI or config switch | Review always closes the transferred entry/review/TTY and discards references, on success/failure/interrupt/cleanup error; no credential output or execution permit |
| SQL / scope | Reuse sealed Q0/Q1 plus common setup; verify Q0/Q1 hashes and reject a nonempty metadata/export bundle. Existing actual Phase1 engine remains 15 normal/42 max, two TLS, query10s/entry120s and original caps | Application sends **zero SQL and opens zero TLS connections**. It does not increase limits or authorize the separate metadata scope |
| STOP / reuse | Existing terminal entry/review STOP is preserved; each wrapper is consumed and releases its entry. Reusing the wrapper or wrapping that closed entry refuses | A fresh process may review another pending record **only offline**; no durable anti-replay, live consent cancellation, remote query cancellation or Control post-close absence is claimed |

This is executable validation of existing bound components, not another self-declared approval receipt. Its public result is limited to fixed classifications, source/tooling/artifact digests and zero execution/input counts. No private service/DB/host/user/CA, runtime fingerprints, raw SQL/metadata or error text is returned. Every result keeps `execution_allowed=false`, `owner_approval_verified=false`, `live_tooling_adopted=false`, `b2_owner_go=false`, `real_aiven_connected=false`, `production_go=false`, with REMOTE_TERMINATION_UNVERIFIED. No other component accepts this result as authority.

New eight synthetic/offline application cases exercise actual private Root/Review/TTY composition under socket/DNS/capture/factory/identity-observation denial, false approval objects, independent public-pin mismatch, metadata-scope rejection, private CA/target/ACL/TTY/runtime/time mutation, interrupt/cleanup error privacy, changed SQL/export refusal and fresh-process/repeated review without GO. Exact-HEAD tests, CI, artifact hash and integration evidence are recorded in #488. Public bundle/import closure includes the application module; legacy boundaries and frozen assets are unchanged. The existing required isolated matrix is regression of that changed package dependency, not new real DNS/TLS evidence. Do not repeat accepted owner tests merely to restate them.

**Future activation is separate and absent:** an independently reviewed real DNS/TLS opener (dynamic endpoint, CA/SAN/same-session binding), approved source/package/runtime/TTY/one-target private origins and incident/retention ownership, exact full tooling SHA adoption, signed P1 and each R1–R6, then a new immediately scoped one-attempt Phase1 B2 GO. The application result cannot start that process or mint a GO. `Adapter.prepare()` and `PrivateWire.connect()` are unchanged unconditional BLOCK; there is no test-subclass alias, injectable network backend, credential loader, real endpoint input or magic flag. Full metadata/physical/TRIGGER/restore/migration/release authorization remains a separate gate. No production branch or live environment operation belongs here. Public roadmap is unaffected by internal review preparation.

`B1_835_OWNER_OFFLINE_DELTA_PASS / R1_TO_R6_NOT_ACCEPTED / P1_NOT_SIGNED / LIVE_TOOLING_NOT_ADOPTED / B2_FRESH_OWNER_GO_NOT_GRANTED / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO_HOLD`

### DNS/TLS opener candidate and isolated activation — not live activation (2026-10-23)

Based on #836 main `ce1ec5917982d2a9d4294e1ba3a08181476b6444`. The owner authorized only owned synthetic MySQL communication. Reuse #835's owner-reported Mac1+9 and earlier evidence; no real target/CA/password, Aiven or production operation is permitted. Public candidate code and **test-only isolated activation** are separate; a future enabled real backend/launcher still requires independent code/security review and formal owner decisions.

**Identified gaps:** the old fixture called blocking `getaddrinfo` before its socket deadline and made its additional peer/TLS checks after authentication. `opener_candidate.py` supplies nonconnecting reusable policy for the previously unimplemented opener stage. Its `open()` is **unconditional BLOCK**, and it contains no resolver, dialer, factory injection, credential loader or CLI. `Adapter.prepare()` / `PrivateWire.connect()` are unchanged unconditional BLOCK. A candidate object, approved-looking JSON or environment flag cannot invoke a live opener.

- The generic policy validates **every** bounded DNS answer (max32), TCP family/type/port and canonical IPv4/IPv6 sockaddr; rejects unspecified/multicast/loopback/link-local/scoped or inconsistent answers; chooses exactly the first answer without address fallback or retry. It does not impose the Docker IP list on a future Aiven dynamic endpoint. Resolution selection is single-use and private/redacted. Actual DNS/CDN/proxy/failover equivalence remains unverified.
- A single existing **2-second connect budget** is shared across DNS/TCP/TLS/authentication preamble and clamped to entry/gate deadlines; it is not reset after resolution. The unchanged Phase1 query10s/entry120s, 15 normal/42 max SQL, two TLS, Q0/Q1/all fixed operations and byte caps remain. Generic policy rechecks the private entry, scope, role/target, CA trust-store contents, CERT_REQUIRED/check_hostname/SAN-only/TLS1.2+, no key logging and socket/context/peer continuity. TLS must be verified on the exact context/socket before the driver can send credentials. The actual principal/grants/UUID/CIDs are checked only through the existing same-inspector Control/Data engine, not inferred from TLS or DNS.
- Only archive-excluded `opener_fixture.py` can perform network operations: the existing fixed synthetic hostname/CA/password/DB/principal guards and host-attested internal Docker namespace are checked first. Exactly two fixed non-spawning DNS children start **before** R1 input, wait on private pipes and receive one permitted synthetic hostname/mode per connection; no process is forked/execed with the captured password. They inherit no credentials/loader environment, use no endpoint argument, and are killed/reaped within the remaining budget on timeout (including unused workers). Fixed statuses distinguish DNS_TIMEOUT, DNS_RESPONSE_INVALID, DNS_CHILD_ERROR and DNS_CHILD_CLEANUP_UNVERIFIED; unknown reaping is BLOCK, never reported as success. POSIX/macOS process startup/scheduling and the trusted interpreter/NSS/OS are not a hard-real-time/provenance proof for a future real backend.
- The isolated TLS wrapper applies the generic context/peer/SNI checks **before returning the verified TLS socket to PyMySQL authentication**. CA/hostname rejection, changed CA store/peer/SNI, malformed/unowned DNS or timeout cannot send inspector SQL; the driver cannot silently reconnect or choose another address. The synthetic address-membership guard is additional containment, not real Aiven authorization. The same live context is never copied into an enabled real connector.

**Reuse #836 rather than treat its result as GO:** `_phase1_program` factors its existing pre-input checks without changing the public review method's close-on-success/failure behavior. The isolated adapter calls this same check before synthetic R1 capture and reuses the same entry/deadline; it does not consume a review receipt/JSON as admission, reset the budget or reopen a closed reviewed context. A real activation is absent. Secret handoff/lifetime, SQL gate, exact grants/identity latch, Control observations, normal QUIT and STOP/HOLD use the existing implementation. Abnormal remote termination, including Control's own post-close state, remains UNVERIFIED even if the fixture oracle sees final absence. No KILL or extra inspector SQL is introduced.

**Delta evidence inventory:** new `test_e858_opener_candidate.py` has 11 offline cases for generic ordered IPv4/IPv6 answers and poisoned secondary answers; false approval/default BLOCK; selection/deadline/role/TLS/CA/SNI; false TLS proof; two-deferred-wire/third-connection denial; real-target refusal before child/DNS; actual fixed child timeout/malformed/oversize output with reaping; and mocked kill PermissionError with **no** false cleanup claim. Synthetic PTYs/records and fixed child modes only, no real DNS from unit tests. The owned MySQL8.0/8.4 profile adds 18 opener cases on the same old94 fixtures: normal15/2TLS, bounded DNS/invalid output/unowned answer/port, peer/SNI/CA-store tamper, real bad CA/hostname/auth, grants/UUID mismatch, Control loss, query timeout and QUIT ambiguity. It also retains the affected previous16 + common11 cases once per engine. The root-only fixture oracle reconciles actual inspector SQL and independently checks all opener handshake CIDs, including failed TLS sessions that never sent SQL. Private CIDs stay in disposable0600 files, not CI output. Existing expired-certificate evidence is reused where OpenSSL verification is unchanged.

The host runs the existing regression and new opener suite in two owned clients on the **same** old94 schema. Between them, only the synthetic root CLI issues `FLUSH PRIVILEGES` to reset the authentication cache, so each first Control must still demonstrate full password authentication over verified TLS; the existing full-auth assertion is not weakened to accept a warmed-cache path. This is fixture setup, not inspector SQL, an extra production privilege requirement or a release procedure. The public bundle includes only the generic candidate, not the owned resolver/wrapper/activation. Critical stdlib `ipaddress`/`dataclasses` now join the existing origin/content/runtime lock. No public source seal, SQL/reference, source e858 or release pin changes; app/migration/lockfile/public roadmap are unaffected. Exact tested SHA, CI, bundle hash, actual case counts and reviewed merge tree belong in #488; this inventory is not a synthetic or real PASS by itself.

The review additionally rejects IPv4-mapped ambiguity, noninteger DNS fields, cipher-suite drift and nonfinite/expired deadlines. Unit child fault modes cannot perform DNS even if termination permission is denied; the artificial timeout branch exits after its sleep. This makes a **new11 + changed public bundle** management-Mac offline differential meaningful, in a disposable pinned no-compile test environment, without actual target/CA/password, DNS, Docker or DB. Do not repeat accepted old1+9/18/32/26/13; share only fixed results/public hashes and STOP on any failed cleanup/runtime check. Exact candidate commands/hashes belong in #488 after CI/review, not a connection command.

**Remaining activation gates:** real operator/Mac/private-origin and supplier provenance; a separately reviewed Mac-capable bounded resolver/dialer and verified lifecycle/termination behavior; exact tooling/artifact adoption and private signed P1/each R1–R6; then a fresh narrowly scoped one-attempt Phase1 B2 GO. No record, CI result, in-process latch or human B policy is durable cryptographic anti-replay or real consent-revocation authority. Real target/TLS/session/grants/failover, physical/TRIGGER/20 migrations and backup/recovery/release gates remain separate and NOT_RUN/UNVERIFIED. This task requests no real password, CA or Aiven probe and does not ask the owner to repeat accepted historic tests.

`ISOLATED_OPENER_CANDIDATE_ONLY / LIVE_TOOLING_NOT_ADOPTED / R1_TO_R6_NOT_ACCEPTED / P1_NOT_SIGNED / B2_FRESH_OWNER_GO_NOT_GRANTED / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO_HOLD`

### Dormant backend / launcher candidate — owned activation only (2026-10-23)

Reuses #837's exact-head Linux/Mac tests, owned MySQL8.0/8.4 each45 and the owner's Mac11+bundle1 PASS (#488 comment6355562586). Those remain historical evidence, not current Aiven facts or formal acceptance. Track H source-only triage (#488 comment6355713623) remains a separate workstream; this change is Track E Phase1 only.

**Changed engineering boundary:** `backend_candidate.exchange` supervises already-prestarted non-spawning children without launching any process or accepting endpoint/command/credentials. Bounded nonblocking anonymous pipes on Darwin/Linux replace fixture `communicate()` accumulation: request256B, stdout8192B, any stderr rejected without rendering, signal/nonzero/empty output rejected, timeout/interruption/cap error followed by direct-child kill/wait/pipe-close proof. The existing cleanup reserve0.25s stays **inside** the same2s DNS/TCP/TLS/auth connect budget; it never resets the entry120s deadline. A kill request/PermissionError is not reaping proof; unknown cleanup is `DNS_CHILD_CLEANUP_UNVERIFIED` / HOLD even after otherwise valid output. Kernel scheduling/NSS/descendants remain trusted-platform limitations, not hard real-time claims. Raw bytes/errors never enter receipts/logs.

`LauncherCandidate` reuses `_phase1_program`/`ReviewedEntry` and borrows only the existing entry (no password copy). It checks private/program/source/tooling/artifact/runtime/TTY before input, consumes input-start exactly once, rechecks the original120s deadline after capture, enforces Control then Data/max2/fixedQ0Q1 and terminal STOP. Only the archive-excluded owned fixture can perform process startup, R1 synthetic capture and networking. Public `run()`, `OpenerCandidate.open()`, `Adapter.prepare()` and `PrivateWire.connect()` remain unconditional BLOCK, with no CLI, real path/endpoint reader, factory callback, environment activation or credential loader. Internal lifecycle transitions are **not an owner authorization**; they neither call an opener nor mint a permit. Closing the lifecycle drops borrowed references; Entry/Boundary retain sole responsibility for password overwrite, driver-reference release, TTY restoration and wire cleanup. Python/driver/SSL/kernel copies cannot be guaranteed erased; no durable restart/rollback anti-replay is claimed.

The owned fixture still prestarts exactly two DNS workers **before** synthetic R1 input, uses the existing fixed internal DNS names/network/CA/password/principal and selects a single peer with no fallback. It reuses #837's authentication-before-TLS denial and Phase1 same-inspector/grants/UUID/CID engine; no new inspector SQL or TLS connection is added. Added owned cases exercise signal/stderr/output flood in DNS plus interrupt/reconnect/review revocation/Data disconnect; the root-only oracle checks private handshake CIDs/SQL inventory/absence, not real remote termination. Any abnormal receipt remains `REMOTE_TERMINATION_UNVERIFIED`. Existing normal15/max42 SQL,2TLS,10s/query,120s including input, byte/row caps, fixed e858/#795 SQL/reference/strict comparator and all live blocks are unchanged. No metadata scope, KILL, grants expansion, actual DB, backup or production operation is enabled.

New offline delta covers real synthetic direct-child stdout flooding, stderr, signal/nonzero/empty result, idle timeout, invalid/expired request, interrupt, actual mocked kill-denial on a live synthetic child, unknown cleanup even after valid output; real synthetic R1/PTY state ordering/deadline/private/tooling/artifact/SQL/third-role/retry/default-activation rejection. OS Python3.9/3.12 and Mac3.12 CI plus affected owned MySQL matrix are required before merge. Critical loaded stdlib selectors/subprocess/math join existing origin/content locks, and both dormant modules join the reproducible review bundle. Exact tested HEAD/hash/counts/CI and a **changed-delta-only** Mac acceptance command belong in #488 after verification; old accepted tests need no owner rerun.

**Remaining before live adoption:** independently trusted Mac OS/Python/OpenSSL/NSS/process provenance and private operator/target/reviewer custody, platform-specific bounded process lifecycle and incident escalation; separately reviewed **real activation/backend/launcher entry**, currently absent, is the only place a future single-target live connection could be authorized. This change does not supply a real credential reader or a live permit consumer. The owner must separately adopt exact tooling/package hashes, decide and sign P1/eachR1–R6 privately, then issue fresh Phase1-only one-attempt B2 GO. Signatures cannot waive target/TLS/grants/source mismatch or unknown remote termination. Current real grants/UUID/TLS/termination/failover, broader metadata/TRIGGER/migrations/backup/release remain NOT_RUN/UNVERIFIED. Do not ask for an Aiven probe to close an offline test gap.

`DORMANT_BACKEND_LAUNCHER_CANDIDATE_ONLY / LIVE_TOOLING_NOT_ADOPTED / R1_TO_R6_NOT_ACCEPTED / P1_NOT_SIGNED / B2_FRESH_OWNER_GO_NOT_GRANTED / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO_HOLD`

### 2026-10-24 current-source reconciliation and post-cleanup deadline rejection

Review baseline: development main `412eb606bb4d57d4f5b11b3d667dd0ef5618568b`, fixed audit source/release still e858. #795/#796/#797 retain their original reviewed HEADs; #803 adopted B's human-supervised one-off protocol. #809 Full94/same-TLS, #812 control/STOP, #813 Phase1 and #815–#838 TTY/private binding/driver/opener/backend evidence supersede the older “only #797 exists” inventory. The affected libraries, tests and CI on this main are byte-identical to the #838 review boundary. Reuse the owner's #838 Mac15+bundle1 PASS and previous isolated MySQL8.0/8.4 evidence; do not assign those owner checks again. Track H Live94 and the later preparation/interest-diagnostic product work remain separate.

**Reproduced technical gap:** `backend_candidate.exchange()` checked its deadline while exchanging pipe data, but could return a successful DNS payload after child reaping/pipe closure without rechecking time. A synthetic direct child and a deterministic post-reap clock change reproduced three failures on the unmodified source: expiry, NaN and clock exception each returned a result instead of BLOCK. This is a local dormant-backend defect, not evidence of an actual Aiven query continuing or an actual DNS incident.

**Minimal correction:** after confirmed local reaping, require the current monotonic clock to remain strictly before the *original* shared deadline before returning any payload. Expiry, NaN or unreadable clock yields fixed `DNS_TIMEOUT`; `dns_reaped=true` means only the owned local child/pipes are finished. Unknown cleanup still takes precedence as `DNS_CHILD_CLEANUP_UNVERIFIED`. No extra time, retry, worker, network endpoint, authority, credential reader or activation is added. The existing cleanup reserve stays inside the same budget. This checkpoint detects late completion; it cannot make OS scheduling a hard real-time guarantee or prove remote MySQL termination.

The new single differential test has three subcases using only an owned synthetic Python child, anonymous pipes and synthetic clock/error data. It checks fixed status, actual child exit, closed pipes and zero stdout/stderr/private-canary disclosure. Existing normal/timeout/flood/signal/PermissionError/unknown-cleanup and public unconditional-BLOCK tests remain. CI reuses the existing B1 Linux3.9/3.12 + macOS3.12 and owned Full94 MySQL8.0/8.4 fixture, because the packaged backend changed. No unrelated database rehearsal or historical owner test is reimplemented. Exact final HEAD, CI counts and changed public bundle hash belong in #488; the previous #838 bundle hash does not attest the new package.

#### Actual-audit gates after this delta

| Gate | Current classification | Evidence / remaining condition |
| --- | --- | --- |
| Source integrity | PASS (offline only) | Existing exact-source/tree/seal/Git-byte checks; e858 is not moving main. Not adoption of a new release source. |
| SQL / Reference integrity | PASS (offline only) | #795 frozen blobs/bundle, reference and strict comparator reused without changes. Phase1 Q0/Q1 and full metadata are different scopes. |
| Operator authentication | UNVERIFIED | TTY/FD/native observations and historical owner reports do not authenticate the actual person/operator now. |
| Approval authenticity | BLOCK | No current adopted tooling, signed P1/individual R1–R6 or fresh B2. Self-HMAC, CI, GitHub comment and approved JSON are not a real authority. |
| Target / TLS session binding | UNVERIFIED (live) | Isolated same-session TLS/identity/grants/Control is tested; no actual target, CA, session or UUID inspected here. |
| One-shot durability | BLOCK / NOT_IMPLEMENTED | B is procedural no-retry, not atomic durable anti-replay across processes/restarts/rollback. A's independent authority/store remains unimplemented. If durable consumption is a hard acceptance requirement, B cannot satisfy it; do not elevate this gate with a signature or a synthetic nonce. |
| Minimal grants | UNVERIFIED (current live) | 10/18 minimal-grant acceptance is historical; no current SHOW or privilege expansion. |
| INDEX / FK names / definitions | UNVERIFIED (current target) | Existing name-only diagnostic and synthetic migration cases remain separate from strict BLOCK and current database-specific review/collision evidence. |
| TRIGGER visibility | UNVERIFIED | An audit principal lacking TRIGGER cannot prove complete visibility. Existing authorized DBA evidence is separate; no new grant. |
| Migration principal / binlog | UNVERIFIED | Runtime DDL/binlog and pending20 execution are not established by inspector metadata or offline success. |
| Actual e858 metadata collection | NOT_RUN | No real connector/credentials/SQL enabled or used. Old94 historical receipt is not this run. |

The source/SQL PASS classifications mean local integrity only; they cannot override the other gates or grant connection authority. The original strict physical-schema BLOCK remains unchanged.

#### Smallest next step and operator STOP

**Next technical step:** independently review the concrete, currently absent single-target live activation/backend/launcher change against the existing Phase1 packet, incident/remote-termination plan and private custody/runtime evidence. Do not create another approval framework, re-run completed synthetic suites without a change, or ask for an Aiven probe to fill this offline gap. Phase1 bootstrap is not permission for full17 physical metadata. If this request makes persistent atomic consumption mandatory, its trusted authority/store and deployment conditions must first be separately selected and reviewed; no generic CLI can safely assume them.

**Owner next operation:** no connection command is issued here. Once the new HEAD/CI/bundle is fixed, only the changed deadline test (three synthetic subcases) plus changed public-bundle verification may need a no-connection managed-Mac delta; reuse accepted old15/11/26/13. Do not read actual CA/target/password. Publicly share only fixed test outcomes, exact public SHA/hash and no-connection status. STOP on failed source/package/runtime/cleanup checks; do not repair by weakening deadlines, adding grants, switching target or retrying a real GO.

Live operation remains a future, separately reviewed and explicitly authorized one-target/scope/UTC-window attempt. Private P1/each R1–R6/tooling adoption and fresh B2 are independent unfulfilled decisions, not approvals supplied by this document. Unknown remote query/connection status requires STOP, private handoff to the already authorized DBA/operator and new approval before any retry; local reaping or Console controls do not prove remote termination. Real grants/TLS/metadata, full schema/TRIGGER, migration execution, independent actual restore and production promotion/publication remain distinct gates. #800 logical restore success is not Aiven Full-backup restoration; Free and Developer Fork unavailability is unchanged.

`TECHNICAL_WORK_REMAINING / LIVE_TOOLING_NOT_ADOPTED / R1_TO_R6_NOT_ACCEPTED / P1_NOT_SIGNED / B2_FRESH_OWNER_GO_NOT_GRANTED / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO_HOLD`

## 2026-10-24 e858 B2 live-activation boundary — independent source review before implementation

> **REVIEW FINDING: LIVE_ACTIVATION_NOT_PRESENT / TECHNICAL_WORK_REMAINING. NOT an activation approval, implementation, private P1/R1–R6 acceptance or permission to connect.**  
> Reviewed source baseline: development main `37aa8ab9f0a481b69ffd5c278a43b427dc92a5df` (#865 integrated); audit source/release stays fixed `e858b9c855970fbe73f37a2d60c9db6525b132d6`. Review is read-only/source-bound, **not** a Mac runtime, actual operator, current Aiven target or client/remote termination observation. Existing offline CI and previous owner acceptance are reused; none are rerun for this document.

### Review result: which exact paths still cannot run

| Source seam | Verified code boundary on main | Missing independent live requirement | Review disposition |
| --- | --- | --- | --- |
| `launcher_candidate.LauncherCandidate.run()` | Calls `close()` and **raises `Block()`**; private _before_input/_connection are fixture lifecycle methods, not an invocation API | An independently adopted **single-attempt** foreground launch/cancel/cleanup implementation with no callable/flag-based bypass and one fixed Phase1 target | **NO live entry / do not replace with a flag** |
| `opener_candidate.OpenerCandidate.open()` | Always raises `Block()`; `select_peer/resolution/before_tls/after_tls` only enforce policy on caller-provided objects | Trusted bounded resolver and dialer; **one reviewed DNS peer/no fallback**, verified CA, target hostname, TLS 1.2+, peer/SNI identity and shared 2s deadline **in the actual connection** | **NO live DNS/TLS opener** |
| `practical.Adapter.prepare()` and `practical.PrivateWire.connect()` | Both unconditionally `Block()`; private wire initialization remains a candidate, functional network occurs only in owned fixture | A reviewed live driver/adapter whose **actual** network path cannot reconnect, mutate SQL, use privileged fallback or exceed frozen Phase1 query inventory | **NO live adapter/wire** |
| `b1_package.build()/verify()` | Review bundle manifest says `execution_allowed=false`, `live_backend=DISABLED_NO_LAUNCHER` and `b2_owner_go=NOT_GRANTED` | A separately adopted exact artifact with independently verified **operator Mac provenance**; no mutation of current offline bundle into an approved executable | **Public offline package remains inert** |
| `practical.PrivateReview.check()` | Accepts only `OWNER_REVIEW_PENDING_NOT_GO` (not an approval token); `ReviewedEntry` checks owner root/binding/provenance *attributes* | Authenticated individual operator + authentic fresh B2/P1 and **each** R1–R6 owner decision; explicit target/source/SQL/reference/tooling/UTC-window/one-attempt binding, revocation path | **No cryptographic/administrative GO in candidate** |
| `backend_candidate.exchange/reap` | Bounded already-owned child pipes, local reaping; #865 rejects results that finish after deadline | Trusted OS/NSS/child process chain; actual deadline and **unknown descendants / remote MySQL status** behavior; local reaping must never attest remote closure | **Local negative boundary PASS, live remote unknown** |

**Important distinction:** #830 closed on completion of the deliberately **inert B1** adapter. #865 was a fix to that same dormant backend, **not** an implementation of this B2 activation. Actual live code and trustworthy Mac/approval evidence do not exist in the current source; an independent reviewer cannot approve a non-existent activation diff. This finding does not downgrade previously verified CI or the B-plan product decision, but stops any **live activation GO**.

### Minimum independently reviewable implementation diff (not yet approved to enable)

Do **not** reimplement the Root/TTY/R1 secret handling, fixed SQL/reference comparison, `LauncherCandidate` internal life-cycle, `OpenerCandidate` policy, existing owned fixtures, or the previous 16 tests just to obtain new receipts. Before attempting activation code, the implementer must submit a reviewable source patch containing at least:

1. **Single explicit target and closed distribution:** one fixed private-target Phase1-only code path on a clean pinned managed-Mac installation. **No environment variable, argv, debug hook, import side effect, arbitrary callback/factory, archive JSON, CI receipt, GitHub Issue comment, admin privilege or alternate CLI** may activate the currently inert library. Offline B1 must remain permanently incapable of opening a live socket. A new candidate must be separate and **disabled until distinct adoption**. Any compile/install, supply-chain or remote side effect gets its own security review.
2. **Trust preconditions:** explicit trusted operator identity/privileged runtime provenance, independent authentic P1 and each R1–R6 decision, fresh precisely scoped B2 owner GO, expiry/target/release/tooling/SQL/ref binding and current revocation semantics. Existing `OWNER_REVIEW_PENDING_NOT_GO` cannot be reinterpreted as `GO`; an in-process latch or a human-operated checkbox is not cryptographic authorization. B's no-retry is **procedural**: do not claim atomic durable/rollback-resistant anti-replay, and do not add an invented in-memory nonce store to satisfy that gate. If atomic durable consumption remains mandatory, **BLOCK** and return for a separate A-style authority/store choice, not a quiet policy downgrade.
3. **One bounded foreground lifecycle:** validate exact source/artifact/runtime, private root ownership/ACL/FD identity, trusted terminal and complete fixed target/CA/engine **before** private password capture or any network step; input begins the single 120s budget. Resolution begins only with the prestarted owned worker and is supervised with #865's post-reap check. DNS+TCP+TLS+auth shares the existing 2s budget; no retry, second address, port fallback, repin or new clock window. The same identity/grants latch and Control/Data roles are used, at most 2 TLS connections, only Phase1 Q0/Q1 + fixed existing SQL (normal15/max42, query10s and frozen caps). **Full17 metadata requires separate scope/GO** and cannot be folded into Phase1.
4. **Failure and remote-state protocol:** if any source/private identity/permission/TLS/grant/UUID/CID mismatch, clock error/expiry, Ctrl-C, cleanup ambiguity, TLS connection loss or Control observation failure occurs, stop all further inspector operations. Differentiate **client cleanup, COM_QUIT attempted, verified normal Control-observed Data disappearance, and abnormal remote termination unverified**. Unknown remote execution or failover is terminal `REMOTE_TERMINATION_UNVERIFIED`/private DBA handoff; no automatic retry/KILL, GRANT, broadened SELECT, DDL or reauthorization from the old packet.
5. **Exact-negative tests for the *actual* new activation seam** (not only helper mocks): synthetic owned MySQL8.0/8.4, controlled DNS and TLS, forbidden owner/private/approval state, missed deadline and unknown cleanup, 3rd connection/reconnect, stale target, CA/hostname/identity/grants/caps/SQL-inventory mismatch, abort between capture and connection, postflight/remote-unknown. Assert **zero external Aiven attempts** in CI and that B1's public methods still unconditionally BLOCK. Run Linux 3.9/3.12 and macOS 3.12 where feasible and check the changed artifact hash/loaded code provenance; fresh managed-Mac *delta* acceptance only for changed components. Existing Full94/SQL/Reference/synthetic results are **reused**, not recopied as live proof.

### Decision and operator handoff (explicitly separate from code merge)

**First reviewer gate:** source diff, import/module boundary, test path, secret lifetime, launcher/process teardown, trust roots and B1 artifact closure are independently reviewed. Missing a source-diff-ready live candidate means review status `NO_LIVE_PATCH_TO_ACCEPT`. Do not mark B2 available or ask the owner to run Aiven at this stage.

**Owner's only immediately relevant action is still the #865 no-connection Mac differential:** new public bundle hash/verification and the single post-reap deadline method. Previously accepted Mac15/11/26/13, CA/principal-intent comparison and fixture SQL are not requested again. Owner/OS/private custody and B2/P1/R1–R6 are **not implicitly approved** by this differential.

**Only after** a future live source/artifact and independently verified runtime/authorization conditions are adopted may a *new*, limited, one-target, UTC-window, one-attempt B2 be considered. B2 then governs at most Phase1 metadata-only and does **not** satisfy physical schema/TRIGGER/INDEX/FK definitions, migration principal/binlog, 20 migration application, fresh actual independent restore, full metadata scope or production release. Real Aiven principal/grants/TLS/UUID and remote termination remain `UNVERIFIED/NOT_RUN`.

**Review status:** `INDEPENDENT_SOURCE_REVIEW_COMPLETED_FOR_EXISTING_INERT_SEAMS / LIVE_ACTIVATION_DIFF_ABSENT / ONE_SHOT_DURABILITY_BLOCK / AUTHENTIC_APPROVAL_BLOCK / TECHNICAL_WORK_REMAINING / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO_HOLD`.

## 2026-10-24 B2 signed-evidence verifier prototype — unadopted synthetic root only

> **TECHNICAL PROTOTYPE / SYNTHETIC_SIGNATURE_BYTES_VALIDATED / OWNER_AUTHENTICITY_UNVERIFIED / AUTHENTIC_APPROVAL_BLOCK / DURABLE_ONE_SHOT_BLOCK / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO_HOLD**
>
> The earlier #866 source review, #867 owned activation and #868 TLS handoff remain authoritative for their respective slices. This section does **not** approve or install a live backend, define a trusted owner key, select a remote service, or change the frozen e858 source.

A narrow, detached Ed25519 verification candidate now exists in the **archive-excluded** `scripts/ci/e858_phase1/b2_signature_fixture.py`, with offline tests at `tests/ci/test_e858_b2_signature_fixture.py`. The algorithm checks separately signed canonical P1, R1–R6 and B2 **synthetic-only** records, exact event ordering and completeness, exact prior source/release/tooling/bundle/SQL/reference/private-binding/runtime/CA hash, Phase1-only engine and attempt ID, bounded UTC window, and rejects missing/tampered/re-signed-with-wrong-key/expired/extra approval flags.

- **What is proven:** under a **caller-supplied unadopted test public key**, the eight synthetic bytes and their signatures are internally consistent and correspond to the **supplied** expected binding; the absence of a step, altered binding, invalid signature or expired clock is rejected. The same records remain cryptographically re-verifiable: **not a one-shot proof**.
- **What is NOT proven:** the public key's independent origin/custody; real owner identity, current operator, P1 and R1–R6 approval by the owner, fresh B2 permission, current revocation status, trustworthy OS/runtime provenance, target's live TLS identity/grants/remote termination, and durable consumed-state across concurrent processes, restarts or restored storage. A matching synthetic signature cannot become those facts. All records must carry **synthetic-only issuer/operator markers**, and no true approval or B2 GO state is accepted.
- `verify_synthetic_claims(...)` returns `execution_allowed=False`, `independent_owner_authority_verified=False`, `operator_authentication_verified=False`, `durable_one_shot_verified=False`, `production_go=False` even for valid signed bytes. `authorize(...)` always raises `Block()`. No DNS/TCP/TLS/SQL/TTY/credential/CLI or network code exists in this prototype; live B1 `run/open/prepare/connect` remain unconditional BLOCK, and this file is excluded from the immutable B1 archive.
- **Boundary for any future real adoption:** select and independently validate an owner-controlled **non-repository** trust root (identity, loss/revocation/rotation policy and signed scope), independent private operator identity, exact artifact+runtime+single target+UTC attempt binding, and decision on whether **durable atomic use-once consumption** is an independent hard gate. User-supplied keys, GitHub Issues/CI, a synthetically signed record, a local HMAC or a dictionary field `approved=true` cannot independently establish those. A separate B2 source/distribution, tests, Mac custody evidence and explicit owner GO remain prerequisite to connection. If hard atomic one-shot is required, this remains BLOCK rather than inventing an in-process nonce.
- **Safety / owner operations:** No actual owner signatures or keys have been requested or produced. No Mac reruns are needed for this source-only research beyond the changed CI evidence; existing #865 Mac public-bundle/date differential is a separate unmet action only if not yet completed. No real Aiven, migration, worker, backup or production operation, and no changes to fixed e858 release or production branch.

**Review status:** `SYNTHETIC_ONLY_SIGNED_EVIDENCE_CONTRACT_TESTED / LIVE_AUTHENTICATED_APPROVAL_NOT_PRESENT / TECHNICAL_WORK_REMAINING / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO_HOLD`.

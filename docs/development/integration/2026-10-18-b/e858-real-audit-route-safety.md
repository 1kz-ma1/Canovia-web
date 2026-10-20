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

検証状態・各engineの件数・CI runと独立tooling SHAはPRとIssue #488へ記録する。合成実通信に成功した場合のレベルは `ISOLATED_MYSQL_TLS_TESTED` / `ISOLATED_REMOTE_TERMINATION_TESTED`。**常に `REAL_AIVEN_UNVERIFIED` / `REAL_OPERATOR_GO_NOT_GRANTED` / `PRODUCTION_NO_GO_HOLD`**。

**実監査までの残作業（接続依頼ではない）**：実principalのまま利用できる独立した同一対象の終了観測/制御経路、control channel故障時の既存認可operatorへの引継ぎ、private対象/CA/本人binding、管理Macでの実行・中断挙動を別審査する。追加control接続やKILLを旧metadata-only承認へ暗黙に含めない。既存権限で成立しない場合はSTOPし、拡権を自動解決策にしない。本番経路は未実装のまま閉じる。B0〜B4の無接続レビューと、実SQL一回の個別GOは分離する。

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

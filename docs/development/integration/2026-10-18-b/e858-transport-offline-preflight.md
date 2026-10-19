# e858 transport：非本番オフライン準備

正本 [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488)。本稿は実接続コマンド・承認ではない。
**REAL_AIVEN_UNVERIFIED / PRODUCTION_NO_GO_HOLD**。新HEADのMac受入は **PENDING_OWNER_RUN**。

## 固定依存と承認の境界

- source `e858b9c855970fbe73f37a2d60c9db6525b132d6`、tree `f6990e8bcf7904be2fdc2706d6a525a29ccef9ee`。
- #795 base branch `audit/e858-source-bound-readiness`、固定HEAD `be89af9a2cb4b13ef16579d2570f4a5605a711b8`。未マージの積み上げ依存。将来のbase変更・main統合は別承認と差分/CI再検証が必要。
- ownerの既存Mac受入は [別の承認記録](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6261973250)。元packetの `PENDING_OWNER_CONFIRMATION` は改変せず、新transportのMac受入に流用しない。
- 新旧release/productionの固定refと、candidateのmain祖先関係を確認する。mainが将来進んでもcandidate/sourceを追従させない。#794/#795のHEAD・本文は変更しない。
- migration旧94/全114/pending20、変更pendingはM01/M08/M18/M19/M20。seal `a3494c1d1cf60546a86312f249f7d44b2ac52e44669752a65e1c70011ec9f91b`。
- SQL bundle `8383bc16fc8b35b8413a2a6a0b0c0784c9fa646ebab119017b97c2bd8d4333d1`。
- reference 8.0 `ab7a27ff4f95be54fa802d1d801082ac508f1611d32215003a5f1d6c0d9270b6`、8.4 `c23bb40ad94a4c33392e7a237d81cad1d576816065f5bb48f4ee86ea323e86d7`。

背景・対象データ・実測履歴は重複転記せず、固定 [#795設計](https://github.com/1kz-ma1/Canovia-web/blob/be89af9a2cb4b13ef16579d2570f4a5605a711b8/docs/development/integration/2026-10-18-b/e858-source-bound-audit.md) と #794固定HEAD `02143e26c70288a1f2b3551513015ac6be72e236` の [実測計画](https://github.com/1kz-ma1/Canovia-web/blob/02143e26c70288a1f2b3551513015ac6be72e236/docs/development/integration/2026-10-18-b/readiness-e858/real-aiven-readonly-diagnostic-plan.md)、同ディレクトリのproduction-gates/adoption-proposalを参照する。そこに残る「未採用」「Mac未実施」は当時の記録。

## コードと非実行設計

コードは新規7ファイルだけ。旧strict監査・参照・collector・SQL・migration・Laravel・.envは変更しない。

| 実装済み | 実行能力と限界 |
| --- | --- |
| `core.py` | full-history/clean/index flags・全tracked実ファイルblob・固定base/ref/source/reference・SQL集合/順序を照合。合成bindingのshape検査のみ。認証関数は常にBLOCK |
| `adapter.py` | 定数Python fake client、または所有ラベル/engine/image/loopback portが一致する既存Docker2種だけ。host/SQL/argv/credentialのCLI入力なし。SQL順序全体をprocess前に検査 |
| `packet.py` | offline専用。別々のbase/new HEAD CI receiptを照合し、新fixtureをローカル実行。DBアダプタを呼ばず、target/binding入力も持たない。新旧 `--collect` は無条件拒否 |
| `disposable.py` | CI用の所有Dockerに限り合成inspectorを作成・削除し、同じ最小権限で17 queryを検証。root DDLはこの合成テスト準備だけ。実用operator CLIではない |

本番TLSコネクタ・credential loader・承認発行・署名検証・waiverは未実装。実接続を解除するflag/envもない。
`synthetic_binding` は固定の架空値だけを受け付け、shape一致でもauthenticated=false。
署名風文字列やhash自己申告は承認にしない。実private bindingを入力させる経路を提供しない。

## Threat model / 認証境界

想定：SQL/receipt/ref/source差替、隠れたindex flags、symlink、ambient client設定、任意command/target、過大/途中stream、誤権限、同件数の意味差、target/actor変更、自己承認、ログ漏出。
GitHubからの既存権限によるsource/CI取得だけが外部通信。packet本体にはnetwork/DB呼出しがない。
loopbackアダプタはOSの固定絶対path `/usr/bin/docker` または `/usr/local/bin/docker` から固定container内の `127.0.0.1:3306` だけへ接続する。任意DNS/IP/socket/`DOCKER_HOST`/任意引数は受け付けず、子環境は再構築する。
Docker daemonの管理権限、Git/OS/Python自体、既存GitHub認証は信頼基盤であり、本稿の暗号学的証明の対象ではない。

`MYSQL_*` / `DB_*` / `DATABASE_URL` / transport解除風envは値を表示せず拒否。
親HOMEは参照・読取・転送しない。`.my.cnf` / login file / client option file / CA / 鍵を開かない。
子HOMEは存在しない定数path。Docker mysqlは `--no-defaults` を最初の引数に固定し、login-fileを不存在の合成pathへ固定する。8.4は加えて `--no-login-paths` を固定する。8.0ではこのoptionが未対応であることを実際の負例で確認したため、起動前にengineごとの定数argvを選ぶ。失敗後のfallback/retryはしない。
Docker内のMYSQL_PWDは既知の使い捨てfixture定数だけで、親envから取得しない。実用credential設計とは別物。
shellは呼ばず、command/stdout/stderr/例外/target/UUID/grantsを出力しない。rawはprivateメモリのみ、CI artifact無し。

GitHub receiptのhead/workflow/全job successは整合性証拠であり、署名attestationでもoperator認証でもない。
改ざんされたローカルPythonやreceipt作成者を、この整合性検査だけで認証できない。承認の自己発行は禁止。

## SQL14本と実行順序

正本は#795の `scripts/ci/e858_audit/queries/*.sql`。bytes/hashは変更しない。

| 固定ID | 最大行数 |
| --- | --- |
| identity / engine / semantic_version | 各1 |
| grants | 8 |
| ledger | 115 |
| table / check | 各512 |
| column / index / semantic_index / column_extra | 各4096 |
| foreign_key / semantic_foreign_key | 各2048 |
| trigger | 128 |

順序は `identity, grants, ledger, engine, table, column, index, foreign_key, check, trigger, semantic_version, semantic_index, semantic_foreign_key, column_extra, ledger, identity, grants`。
14固有statement、17実行。bytes完全一致の定数だけを受け付け、任意コメント/hint/変数/関数/複文/任意SHOW/DDL/DML/GRANTの追加を実行前拒否する。既存MAX_EXECUTION_TIME hintも原本の一部として固定。
show例外は `SHOW GRANTS FOR CURRENT_USER()` だけ。REFERENCES＋ledger SELECT＋任意USAGEの元guardをそのまま使用する。

10秒/query、120秒/総stream、8MiB/section・16MiB/総出力、row256KiB/cell128KiB。identity/grantsはさらに16KiB。
category cap+1、部分行、非ASCII/制御文字、stderr1byte以上、異常終了/signal/interruptでBLOCKする。終了確認不能時もBLOCK。切捨て・SQL再試行・対象fallbackなし。
Docker ownership inspectは各query前に別の5秒timeoutで行う。全stream budgetは先に作り、次query前にも期限照合する。OS scheduling/process cleanupの遅延までhard real-time保証するものではない。

## 将来の実接続契約（設計だけ、実行不可）

別実装PR・別承認・別Mac受入が必須。旧CLIをflagで解除しない。
`mysql` の最初の引数 `--no-defaults`、login/defaults混入禁止、TLS `VERIFY_IDENTITY`、事前承認CA/hostname/port/schema/engine/server UUID/principalを私的bindingへ固定する。
認可operatorの本人確認・失効可能な限時1回承認・署名または独立した認可記録照合を実装/審査しなければ接続不可。
接続ごとのQ0 identity/Q1 grantsのpreambleを私的検証し、その接続内のqueryと結び付ける必要がある。今回のDockerはquery別clientで前後検査する合成試作であり、この本番session契約を実装済みとはしない。
SQL全体hash/順序/cap、wall timeout/固定max_exec_time、private削除を別検証。binding/actor/CA変更・期限切れ・TLS失敗・権限差・timeout時は資格情報/SQL/対象のfallback/retry禁止。

ledger前後一致はmetadata全体の原子snapshotやDDL actor停止の証明ではない。targetが途中で変化して戻る、権限が不可視、同時DDLが起きる可能性はUNVERIFIED。
TRIGGER観測0件は `VISIBILITY_UNVERIFIED` に相当し、不存在や完全可視性を断定しない。
strict name-only BLOCK、94/114/未知・部分ledger、M08依存名/新設名衝突、完全TRIGGER、DDL principal/binlogは個別判定する。semantic一致からstrict PASSへの昇格はない。

## STOP matrix

| 固定code / 条件 | 操作 |
| --- | --- |
| FIXED_REF_DRIFT / TRANSPORT_HEAD_REJECTED / FULL_HISTORY_REQUIRED | 実行前停止、別SHAへ自動追従しない |
| CLEAN_CHECKOUT_REQUIRED / INDEX_FLAGS_REJECTED / SOURCE_FILE_REJECTED / TRANSPORT_SCOPE_REJECTED | 差替・範囲逸脱を修正して新HEAD CIから再審査 |
| SQL_BUNDLE_REJECTED / SQL_PLAN_REJECTED / ADAPTER_NOT_ALLOWLISTED | process前停止、SQLの自動修復なし |
| ENVIRONMENT_OVERRIDE_REJECTED / DISPOSABLE_TARGET_REJECTED | 外部対象や資格情報を読まず停止、値を公開しない |
| APPROVAL_AUTHENTICATION_UNIMPLEMENTED / SYNTHETIC_BINDING_ONLY / BINDING_CHANGED | 実binding/自己承認を受け付けない |
| QUERY_TIMEOUT / QUERY_*_EXCEEDED / PRIVATE_OUTPUT_REJECTED / PRIVATE_CLIENT_FAILURE | process group停止、raw非出力、再試行なし |
| LEDGER_CHANGED / PRIVATE_BINDING_CHANGED / METADATA_GRANTS_REJECTED | snapshot破棄、対象・権限変更を別審査 |
| CI_RECEIPT_REJECTED / OFFLINE_FIXTURE_TESTS_FAILED | 新HEAD受入停止。skip/neutralを成功にしない |
| REAL_CONNECTION_DISABLED | 新旧collectは常に停止。接続許可を意味するenv/flagなし |

## 未記入の承認票案（非実行）

以下はすべて未記入。実値は将来、Git/同期/CI/チャット外の私的票だけに記載。未記入では承認不成立。

| 必須欄 | 値 |
| --- | --- |
| candidate SHA / source tree / 採用新release ref | 未記入 |
| #795 tooling SHA / 新transport SHA / それぞれMac受入記録 | 未記入 |
| source seal / SQL bundle SHA / engine別reference SHA | 未記入 |
| owner / 認可operator / 本人確認証跡 / 独立認可検証方法 / 責任者 | 未記入 |
| private target binding参照 / DB / TLS CA・hostname / port / engine / UUID / principal | 未記入 |
| 既存最小権限証拠 / Q0・Q1 session結合 / 不可視性の扱い | 未記入 |
| 限時window開始・終了 / 1回限りscope / 失効方法 | 未記入 |
| SQL IDと順序hash / 行・bytes・時間cap | 未記入 |
| raw0700/0600 private保存・即時廃棄 / 異常終了残骸確認責任 | 未記入 |
| 変更・期限・TLS・権限・timeout・cap・ledger差のSTOPと連絡先 | 未記入 |
| metadata-only対象外：業務行/DDL/GRANT/backup/restore/writer/Render/Auth0/公開 | 未記入 |

この票の記入やhash一致だけで認可されない。実接続コマンドは掲載しない。

## CIとMacオフライン受入

新workflowは最終transport HEADで実行する。別checkoutの固定#795で既存Python33件を前後実行し、同じ検証の再実行を独立coverageへ加算しない。
既存guardの範囲制限を新ファイルに合わせて緩和しない。両MySQLで固定依存の94/114二重再構築・22ケースを再実行し、新adapter3ケース/17queriesは新HEADのコードで実行する。
All20各38ケース（M01/M08/M18/M19/M20・M04→M05含む）と既存startup/旧c09診断回帰、関連PHPとMCP実MySQL6件も保持。旧c09の成功をe858実Aiven証拠にしない。
最終run/head/各job/件数/skip/警告はDraft PRと#488に固定し、ソース自己HEADを埋める循環を避ける。

MacはGit/GitHub CLI/Python3.9以上だけ必要。最終Draft PRにHEAD/CI IDを置換済みの単一コピペ手順を掲載する。操作は、新しい一時full-history clone→新HEAD detach→固定#795 run `39211597992` と新workflow成功runのJSONを0700/0600へ取得→`python3 -I -B scripts/ci/e858_transport_preflight/packet.py --transport-sha <最終HEAD> --base-receipt <私的file> --transport-receipt <私的file>`→一時clone/receiptの廃棄。
GitHub source/CI取得以外の通信なし。MySQL/Docker/credential/target/binding入力は不要。packetは新fixturesのみローカル実行し、MySQLはCI evidenceだけと表示する。
期待 `TRANSPORT_OFFLINE_PREFLIGHT_VERIFIED`、全本番変更/接続/waiver/release許可false。Mac自身のMySQL実行やowner受入は宣言しない。
BLOCK時は固定codeのみ共有し、host/schema/account/UUID/grants/raw/error本文を貼らない。

残るP0：strict名前差BLOCK、実Aiven新設名衝突/完全TRIGGER、実DDL principal/binlog/identity重複、実backup/隔離restore/RPO・RTO、全writer/DDL actor停止、実Web/iOS/Auth0/Render。次は新HEAD CIとowner Mac offline受入。その先の実Aiven readは別の明示GO。


## #796 Mac受入失敗の修正（Darwin / Python3.9.6、未再受入）

owner実測：元34件のうち3件がerrno=1。#795 `transport.py` のfinally内の `killpg(SIGKILL)` がPermissionErrorを発生させ、元の上限Blockを上書きした。Linux34件成功をMac成功とは扱わない。
#795のコードを変更せず、#796の `adapter.py` 内へ独立readerを置く。SQL bytes/allowlist/順序とCAPS、8MiB/16MiB、row256KiB/cell128KiB、10秒/120秒、private output契約を保持し、制限増加をprocess前に拒否する。baseのreaderは固定base checkoutで従来どおり回帰する。

| 終了分類 | 証明・動作 |
| --- | --- |
| 固定childless fake正常終了 | 正確なisolated Python argv・定数program・再構築envが一致し、直接childをwait済み。既に終了していればkillpg不要 |
| 固定fake上限/timeout/中断 | 生存中はgroup kill、EPERMなら同じPopen childを直接killして0.5秒以内にwait。終了が証明できた場合だけ元の固定Blockを保持する。全体を再実行するfallbackではない |
| kill/wait権限エラー・wait timeout | PROCESS_TERMINATION_UNVERIFIED。PermissionErrorを無条件に無視しない |
| 不明process tree / Docker local group | 親の終了だけを木全体の証明にしない。生存中に限りgroup killを行い、親のwait後はgroup不存在を最大0.25秒確認。reap済み親のPGIDには破壊signalを送らず、PID再利用による別processへの誤killを防ぐ。権限不足/残存はPROCESS_GROUP_TERMINATION_UNVERIFIED。残存を除去できてもUNEXPECTED_DESCENDANT_DETECTEDでSTOP |
| Docker正常完了 | 固定foreground docker execのexit0＋完全な出力＋local group不存在を要する。これだけが当該remote mysql正常完了の証拠 |
| Docker異常終了・上限・timeout | local clientをkillしてもコンテナ内mysql終了は証明できない。DISPOSABLE_REMOTE_TERMINATION_UNVERIFIEDでSTOP。local groupも未確認ならそのBLOCKを優先する。次query/retryなし。試験runnerが所有する使い捨てcontainerの廃棄・停止が必要 |
| signal終了 | 正常完了にはしない。終了確認できればPRIVATE_CLIENT_FAILURE、確認不能ならcleanupの固定BLOCK |

cleanup追加graceは直接wait最大0.5秒＋group確認最大0.25秒で、元stream deadlineを延長しない。raw例外は表示しない。cleanup未確認時は元の原因をprivateな固定primary_codeへ保持し、成功にはしない。
childless証明を任意Python・shell・Dockerへ転用しない。固定fault fixture（sleep/stderr/self-signal）も子を作らない定数のみ。未知のprogram/envは証明不成立。

新テストは元34件を保持し、3種類の上限BlockへEPERMを注入、実blocked writerの直接kill/wait、正常終了・signal・timeout・kill/wait拒否・group残存/不可視・Docker local/remoteの証明差を追加する。skipや上限の緩和はしない。offline CIはLinux/Python3.9を明示し、手元Linux/Python3.12とも比較する。Darwin/Python3.9.6自体はownerの新HEAD実行まで **MAC_ACCEPTANCE_PENDING**。

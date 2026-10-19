# e858：実Aiven metadata-only診断計画（未実装・未承認・未実行）

正本 [#488](https://github.com/1kz-ma1/Canovia-web/issues/488)。対象sourceは `e858b9c855970fbe73f37a2d60c9db6525b132d6`、sealは[監査票](release-candidate-readiness-audit.md)に固定。**この文書は接続手順の実行許可ではない。** 新監査toolingはまだなく、旧collectorへ新sealを渡して動かさない。旧c09用の承認も流用しない。production NO-GO/HOLD。

## 承認前提・取得scope

目的は現在の94 ledgerと**構造metadataだけ**を、新sourceから導いた参照・absence契約に対して再診断すること。既存の限定監査user（REFERENCES on verified schema + SELECT on migrationsだけ、任意USAGE）を維持する。TRIGGER/PROCESS/DDL/広いSELECT/role/GRANT OPTIONを足さない。SHOW GRANTSで余分な権限やroleがあれば既存guardでBLOCK。実migration principalの権限審査は別の既存認可DBAが行う。

対象はownerが私的に承認した**既存Aiven service/database 1件だけ**。本番名・host・account・CAパス・証明書・token・内部object名の実測値は公開票へ書かない。過去の入力名から推定しない。source SHA、採用済release ref、独立tooling SHA、engine別reference hash、SQL bundle blob/hash、scopeと期限・operator本人・対象bindingを実行前に一枚のprivate承認票へ固定する。未記入ならSTOP。

接続はowner Macの既存login-pathとCAを使い、`--no-defaults`、`--ssl-mode=VERIFY_IDENTITY`、`--connect-timeout=10`、指定database、batch/raw/skip-column-names/skip-auto-rehash。既存管理画面/運用記録と接続先を私的照合する。TLSの無効化、別hostへの自動fallback、資格情報の環境override、CLI引数へのpassword、shell trace、CI上の実接続は禁止。

## SQL allowlistと取得列

以下は**次の準備PRで固定・審査するSQL仕様**。このPRではSQL原本もcollectorも変更せず、実行しない。入力文字列から任意SQLを組み立てず、hash一致した定数bundle以外を拒否する。SELECTと、権限確認の**1種類のread-only例外 `SHOW GRANTS FOR CURRENT_USER()`**だけをallowlist化する。SHOW例外をDDL/GRANT実行許可と解釈しない。SQLは複文を任意入力する入口を持たせない。

| ID | SQL/取得列（rawはprivateのみ） | 想定量 / 提案hard cap |
| --- | --- | --- |
| Q0 対象識別 | `SELECT DATABASE(), CURRENT_USER(), VERSION(), @@server_uuid;`。各接続のpreambleで私的対象binding/engineを照合。server UUID等の実値は出力しない | 各1行、16KiB。期待bindingを私的確定できなければSTOP |
| Q1 権限 | `SHOW GRANTS FOR CURRENT_USER()` → 既存 `p0_mysql_metadata_grants_guard.py` | 少数行、16KiB。許可の2種＋任意USAGE以外はBLOCK |
| Q2 ledger（前後2回） | `SELECT 'migration_ledger', migration, batch FROM migrations ORDER BY migration LIMIT 115;` | 旧観測94行。上限115行、94以外・前後差・未知/重複名/batch不正はBLOCK。個人行を読まない |
| Q3 strict物理 | 下記元SQLの6区分＋engine。取得列は次表のとおり。scopeは全て `*_SCHEMA=DATABASE()` | 旧観測table91/column1154/index604/FK181、check/trigger観測0/0（trigger完全可視性は未証明）。8MiB合計上限 |
| Q4 semantic補助 | 下記元補助SQLのversion＋Index/FK。strictと同じ対象・同じ行をグループ化 | Index604/FK181構成行が旧観測の参考、8MiB合計上限。strictとの重複/欠損はBLOCK |

Q3の正本SQL：固定e858の [`integrated_release20_metadata.sql`](https://github.com/1kz-ma1/Canovia-web/blob/e858b9c855970fbe73f37a2d60c9db6525b132d6/scripts/sql/integrated_release20_metadata.sql)、blob `e0e3f9eb11b34e4524323e1b52ef069c79ea7713`。
Q4：固定e858の [`integrated_release20_semantic_metadata.sql`](https://github.com/1kz-ma1/Canovia-web/blob/e858b9c855970fbe73f37a2d60c9db6525b132d6/scripts/sql/integrated_release20_semantic_metadata.sql)。ledger元SQLも[source証拠](source-audit.json)のblobを確認する。新bundleは別path/hashで、原本を上書きしない。

| metadata区分 / 参照catalog | privateに取得する列 |
| --- | --- |
| table / TABLES | TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION |
| column / COLUMNS | TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, HEX(COLUMN_DEFAULT), EXTRA, COLLATION_NAME |
| index / STATISTICS | TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE, IS_VISIBLE, HEX(EXPRESSION)。補助でCOLLATION/NULLABLEも取得 |
| foreign_key / KEY_COLUMN_USAGE JOIN REFERENTIAL_CONSTRAINTS | TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION, COLUMN_NAME, REFERENCED_TABLE_NAME/COLUMN_NAME, UPDATE_RULE, DELETE_RULE, LOCAL/FOREIGN判定。補助でMATCH_OPTION, POSITION_IN_UNIQUE_CONSTRAINT |
| check / TABLE_CONSTRAINTS JOIN CHECK_CONSTRAINTS | TABLE_NAME, CONSTRAINT_NAME, HEX(CHECK_CLAUSE), ENFORCED |
| trigger / TRIGGERS | EVENT_OBJECT_TABLE, TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING, ACTION_ORDER, HEX(ACTION_STATEMENT) |

**提案する新bundleの上限**：table512、column4096、index4096、FK2048、check512、trigger128行、semantic_index4096/semantic_FK2048行。各SELECTはこのcap+1をLIMITし、sentinel到達なら切り捨てて合格にせずBLOCK。正常時の全行・順序・型変換は元SQLと同一にし、参照hashを変えない。これは実測件数ではなく、安全性レビュー待ちの制限値である。

Q3/Q4に `SELECT /*+ MAX_EXECUTION_TIME(5000) */ ...` と定数LIMITを追加する設計とし、対応MySQLのhint適用/非適用を使い捨て環境で確認する。各client wall timeout10秒、全体120秒、合計出力16MiB・private出力fileサイズの逐次監視、1フィールド/行サイズも制限し、超過で接続を打ち切り固定BLOCK。最大行に到達した結果を完全集合と解釈しない。client切断がserver queryの即時終了を保証するとは扱わず、残留確認は認可DBAへ委ね、自動retryしない。**既存collectorは60秒timeout/事後bytes判定であり、この強化案を実装済みとは言わない。**

`SET`/`START TRANSACTION`/LOCK/CREATE/DROP/ALTER/INSERT/UPDATE/DELETE/REPLACE/GRANT/REVOKE/CALL/INTO OUTFILE/LOAD/EXPLAIN ANALYZE、任意SHOW、アプリ起動・artisan実行を許可しない。SELECTで使う関数も上記固定式だけ。functions/UDFを任意呼出しする入口は禁止。業務tableからのPlan/user/link/grant/audit行のSELECT、情報から実ユーザーIDを推定する処理も含めない。

## 後日のoperator操作順序（今回は実行しない）

1. ownerが候補採用・release pinを承認し、新監査セットPR/固定tooling SHA/上限・SQL・参照を受入。Mac clean/full-historyでoffline正負テストを受入。新セットが未完成、旧collectorしかない、release未採用なら**ここでSTOP**。
2. ownerがこの1回のmetadata-only接続について、私的対象/既存最小権限主体/時刻window/期限/SQL hashを別承認。CI経由・認可operator以外は禁止。接続先を既存管理情報と照合し、TLSとQ0/Q1をprivate処理。追加権限の要求で通さない。
3. ledger前→Q3/Q4→ledger後を上限内で採取。Q0は接続ごとに検証。ledger前後一致も物理snapshotの原子性証明にはならない。DDL actor/engine変更やmetadata不整合が疑われたらBLOCKし、既存可視性不足もそのまま記録する。
4. 新candidate-bound strict判定、semantic補助、M08旧名/定義、新設名衝突を別々に出力。**名前差は許容してPASSにせずstrict BLOCKを保持**。definition/重複/余剰/欠落/列順/prefix/visibility/参照動作差は不適合。trigger0件は `OBSERVED_ONLY_VISIBILITY_UNVERIFIED`、完全可視性・principal/binlogは別ゲート。
5. 証拠を以下の固定項目にsanitizationし、ownerが確認してから#488へ限定記録。query/clientエラーを貼らず、固定失敗コードだけ共有して終了。再接続や追加SQLは新承認。

## 出力・保存・失効・STOP

公開可能：固定source/tooling SHA、seal/reference/bundle hash、UTC時刻・evidence kind、engine系列、94/114/applied/pending等の集計、区分別MATCH/NAME_ONLY_DIFFERENCE/SEMANTIC_DEFINITION_DIFFERENCE/COUNT_DIFFERENCE、M08固定dependency IDと分類、衝突分類/件数、可視性UNVERIFIED、strict BLOCK、全承認/本番変更フラグfalse。任意のraw文字列、個別object名/名前hash（辞書攻撃可能）、schema/account/server UUID/host/証明書/実data/grants/エラー全文は公開しない。

rawはowner MacのGit/同期対象外・暗号化された保存領域下の0700一時dir/0600 fileだけ。標準出力・stderr・GitHub・CI/artifact・チャットへ出さない。成功/失敗/通常interruptで即削除し、異常終了後は次回接続前に残骸を私的確認/削除する。証拠として長期保管するのはsanitized結果とprivateな承認/対象bindingの参照だけ。rawをuploadして後で削除する手順は禁止。

有効期間はownerが承認した1回のwindowだけ（期限が未指定なら実行不可）。source/ref/seal/SQL/reference、DB/schema/engine/grants/actor状態が変わる、DDL/復旧/追加migrationが行われる、期限切れ、採取中差分、target不明、TLS失敗、cap/timeout/不可視性、strict不一致で該当ゲートをSTOP。旧観測は履歴として残すが、新migration GOへ再利用しない。

TRIGGER完全可視性はこの最小userでは証明できないことがある。既存認可DBA/providerの私的完全性証拠を別承認で取得するまでUNVERIFIED。監査userへTRIGGERを追加してPASS化する提案はしない。metadata-only計画が完了しても、実データ重複、runtime DDL/binlog、backup/restore、writer停止、実機/実認証、strict名前差審査は残り、**本番GOにならない**。

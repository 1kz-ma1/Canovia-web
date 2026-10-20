# e858 実metadata監査経路：差分と停止点（2026-10-20）

**TECHNICAL_WORK_REMAINING / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO_HOLD**。
正本は[Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488)。これは実接続の承認票・実行CLIではない。

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

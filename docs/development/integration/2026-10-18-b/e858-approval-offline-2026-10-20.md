# e858 Approval / TLS / One-shot：offline契約（2026-10-20 JST）

正本 [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488)。**実Aivenへ接続しない**。
`AUTHORITY_REAL_UNVERIFIED / TLS_REAL_SESSION_UNVERIFIED / ONE_SHOT_REAL_CONSUMPTION_UNVERIFIED / REAL_AIVEN_UNVERIFIED / PRODUCTION_NO_GO_HOLD`。

## 現在の実装と固定依存

候補e858 `e858b9c855970fbe73f37a2d60c9db6525b132d6`（tree `f6990e8bcf7904be2fdc2706d6a525a29ccef9ee`）はAUDIT_CANDIDATE_ONLY。
chain：candidate → #795 `be89af9a2cb4b13ef16579d2570f4a5605a711b8` → #796 `5e63b7db068121d000c316fa8700f7c5f1c0c3b4` → 本Draft。
baseは#796 branch `audit/e858-transport-offline-preflight` の固定HEAD。#794/#795/#796は未マージのまま、HEAD/本文/コードを変更しない。本Draft自身も統合・ready化・release採用しない。

Source/旧94/全114/pending20/変更M01・M08・M18・M19・M20、seal `a3494c1d1cf60546a86312f249f7d44b2ac52e44669752a65e1c70011ec9f91b` を再照合。
SQL `8383bc16fc8b35b8413a2a6a0b0c0784c9fa646ebab119017b97c2bd8d4333d1`、refs8.0 `ab7a27ff4f95be54fa802d1d801082ac508f1611d32215003a5f1d6c0d9270b6` /8.4 `c23bb40ad94a4c33392e7a237d81cad1d576816065f5bb48f4ee86ea323e86d7` 不変。
新旧release/main/production/#794/795/796のorigin refsを固定照合し、移動ならSTOP。本Draft source guardは独立して全inherited blob・#796元7追加ファイル・本Draftの正確な追加7ファイル・actual bytes/clean/full-history/index flags/symlinkを検証する。**#796 boundaryへ新HEADを渡して通す変更はしない。**

過去資料は [#794準備票](https://github.com/1kz-ma1/Canovia-web/pull/794)、[#795設計](e858-source-bound-audit.md)、[#796設計](e858-transport-offline-preflight.md) を参照。#796 owner Mac報告は [別証跡](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6270282307)。人間の受入はpacketのPENDING文字列を事後改変せず別記する。

| 今回実装 | 実装しないもの |
| --- | --- |
| 厳密なsynthetic JSON/canonical schema、対象/TLS観測/署名風fixture/clock/nonceの負例 | 実owner本人認証、実署名authority、private key/CA/credential読み込み |
| 役割別の一時メモリHMAC fixture | 実オーナーの暗号署名・PKI・公鍵配布/鍵管理・失効サービス |
| thread間のメモリnonce消費、二段階review/JITの失効検査 | durable server-side exactly-once、process/crash/restartを跨ぐ消費保証 |
| NOT_EXECUTABLE descriptor、固定SQL14/順序17/capsのbinding | connector、socket、MySQL operator command、実接続用flag |

## Threat model・認証境界

入力の自己申告、承認JSON埋め、approved=true、hash、CI成功、GitHubコメント、Mac受入は本人認証でも接続許可でもない。
`real_endorsement` は常に `MISSING_REAL_AUTHORITY`。real_operator_authenticated/release_authorized/real_aiven_connected/production_modified/strict_block_waivedは常にfalse。
対象は固定架空 `synthetic.invalid` / schema・actor・UUID等の固定fixtureのみ。実host/CA/key/credential/approval grantをCLI入力するoptionは存在せず、未知option/collectはファイル/プロセス/ソケット操作前に拒否する。contract APIはpure memory。任意targetを解決・接続しない。

合成HMACは `SYNTHETIC_HMAC_SHA256_TEST_ONLY`：役割owner/operatorで異なるランダム鍵を各test生成しメモリ以外に保存しない。**HMACはdigital signatureではなく、実本人認証や独立した署名authorityの実装ではない**。別keyringの自己発行MAC/呼出側public key追加は拒否する。algorithm・正確なfields・役割・世代・payloadを固定し、改変/rotation/revocationを検証するだけ。
将来、ownerが独立した署名者本人確認、鍵の保管/配布/失効/rotation、信頼anchor、監査責任者、時刻源を別承認するまで実authorityは未実装。test signerをproduction trustへ昇格しない。

## Canonical・時間・一回限り

ASCII printable文字、厳密field集合/type（boolとintを区別）、JSON重複key拒否、最大16384bytes、UTF-8 ASCII部分集合、sort_keys/separators(',',':')/余白なし/NaN・float禁止。時刻は整数UTC Unix秒だけ。local時刻文字列は認可時刻として扱わない。
有効窓は最大120秒、not_before inclusive / expires_at exclusive、grace0。合成clockのUTC/monotonic後退・差分不一致はBLOCK。review時のmonotonic deadlineもJITで再検査する。実機時刻の真正性は証明しない。

状態：UNREVIEWED → SYNTHETIC_VALIDATED → READY_FOR_OWNER_REVIEW。失敗はUNAUTHENTICATED / EXPIRED / REPLAY_REJECTED / TARGET_CHANGED / TLS_UNVERIFIED等。APPROVED / READY_TO_CONNECT / GO状態は作らない。
reviewとJIT間にsource/tooling/transport/verifier/SQL/ref/actor/role/signer/CA/UUID/host/schema/session/ledger/writer/timeが変われば失効。署名風fixtureを再生成してもreview snapshotの差を許容しない。
一つのtest-owned memory storeだけthread lockでnonceを原子的に一度消費する。同時2件は1成功/1拒否。**新store/process/restartでは記憶を失う**ことを正例として明示検証し、ONE_SHOT_REAL_CONSUMPTION_UNVERIFIEDを必ず保持する。
将来の実一回制御は、独立認証済serverで署名・失効・scope・session・時刻・nonceのreserve/consumeを同じdurable transactionへ結合する必要がある。socket前に消費し、timeoutや異常終了でも再開・再利用せず再審査する。現メモリlockはこの保証の代替ではない。

## TLS・session・権限

TLSはメモリ観測fixtureだけ。CA値は無害な文字列のSHA256でcertificateではない。chain_valid/SAN/VERIFY_IDENTITY/CA fingerprint/session/Q0/Q1を厳密照合しても実handshakeを証明しない。**TLS_REAL_SESSION_UNVERIFIED**。
将来はcertificate chainを承認済CAで検証しSAN/hostnameを一致させるVERIFY_IDENTITYが必須。REQUIRED/VERIFY_CA/DISABLED/HTTP/plain TCP、任意host/port/socket、env/defaults/login-file注入、CA/hostの自動fallbackは禁止。更新/CA rotation/host移動は新binding・別owner審査。
接続**ごと**に検証済TLS sessionへQ0 identityとQ1 grantsを結合してmetadata前に照合し、postflightも再照合する。#796の17queryは別client connectionで、本番のper-session証明ではない。
権限fixtureはREFERENCES_SCHEMA＋SELECT_MIGRATION_LEDGER＋任意USAGEだけ。role/GRANT OPTION/TRIGGER/PROCESS/全SELECT/DDLは拒否。元実grants parserは不変で固定依存の33/48回帰を使用し、ここでGRANTしない。
ledger前後一致はatomic snapshot/全writer・DDL停止ではない。TRIGGER0件でも完全可視性はUNVERIFIED。strict名前差BLOCKはsemantic一致で解除しない。

SQL14・17順序、行cap+1、8MiB/16MiB、cell128KiB/row256KiB、query10s/stream120sをpayloadへ固定するだけ。実clientは無い。#796 cleanup/EPERMとDISPOSABLE_REMOTE_TERMINATION_UNVERIFIEDも後続のSTOP理由であり、承認票で解除しない。

## STOPと将来の個別承認

| 固定code例 | STOP |
| --- | --- |
| FIXED_REF_DRIFT / NEW_SCOPE_REJECTED / INHERITED_BLOB_CHANGED | 勝手にrebase/ref/allowlistを変えず別rebaseline審査 |
| MISSING_REAL_AUTHORITY / SYNTHETIC_SIGNER_* / STALE_SIGNER / SIGNER_REVOKED | 実本人・信頼anchor未実装。自己発行/CI/コメントで補わない |
| TARGET_CHANGED / TLS_UNVERIFIED / SESSION_IDENTITY_CHANGED | 実接続不可、chain/session/対象を再審査 |
| EXPIRED / NOT_YET_VALID / CLOCK_ROLLBACK / CLOCK_DRIFT | grace/retryなし、認可窓を再審査 |
| REPLAY_REJECTED / APPROVAL_REVOKED / TARGET_OR_BINDING_CHANGED | 再使用・途中再開なし |
| METADATA_PRIVILEGE_BLOCK / LEDGER_CHANGED / STRICT_SCHEMA_BLOCK | 権限追加・台帳修正・semantic waiverで通さない |
| CI_RECEIPT_REJECTED / CI_FILES_REJECTED / SYNTHETIC_FIXTURES_FAILED | new HEADの受入停止、skip/neutral/cancelは成功算入しない |

将来raw保存の条件だけ：ownerの暗号化・非同期0700/0600領域、Git/CI/chat持出し禁止、finally廃棄・crash残骸確認。今回rawを扱わない。
実metadata read、TLS integration/証明書変更、完全TRIGGER、migration principal/binlog、backup/隔離restore/RPO・RTO、writer/DDL停止、実Web/iOS/Auth0、production/deploy/公開は各別承認・未検証のまま。

## CI・owner Mac

新workflowを最終HEADでPython3.9/3.12実行し、新pure fixturesとsource tamper/private CI file負例を分離する。
#79533件/#79648件をそれぞれ**clean detached固定SHA**で実行、元strict Python32件も固定#796で確認。本DraftのHEADを旧boundaryに渡さない。
MySQL/PHP/All20の新規再構築は今回実行しない：変更がpure契約だけで旧adapter/SQL/DB/PHP/source全blobが不変のため。固定既存run39211597992・39271348988の各8/8・両MySQL22/38/PHP/MCPは独立receiptの**依存証拠**で、新HEADでDBを実行した実績には加算しない。
Mac packetは新pure testsだけをin-process実行。source/full-historyを証明する読み取り専用local Git subprocessだけが必要で、child client/killpg/Docker/MySQL/networkは呼ばない。source tamper cloneを作るCI-only testsや旧依存suiteはowner packetで実行しない。
CI receiptsはownerが先にGitHubから取得した3固定名JSONだけ。private0700/0600、symlink/FIFO/重複key/sizeを拒否。整合性を検証しても署名attestation/実認可にはしない。
最終HEAD・全job成功run IDを得てから、PR本文へplaceholder無しのMacコピペコードを掲載する。新規full-history temp clone、物理path正規化（OSのtemp aliasへ依存しない）、3CI JSON取得の後、新packetだけを実行する。owner Macは**PENDING_OWNER_RUN**、既存Mac成功を転用しない。

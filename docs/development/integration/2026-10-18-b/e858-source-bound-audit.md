# e858 Source-bound監査セット：設計・検証・Mac受入

正本 [#488](https://github.com/1kz-ma1/Canovia-web/issues/488)。候補採用とrelease pinは[owner記録](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6260662593)で完了。順序は **候補pin済み → 本toolingの実装/CI/Mac受入 → 実Aivenの別承認**。未マージの[#794](https://github.com/1kz-ma1/Canovia-web/pull/794)は指定HEADの履歴資料として参照し、変更しない。

## 実装前に固定する契約

- candidateはe858の40桁SHA/tree、新release ref、114 manifest/sealに固定。tooling SHAは別引数で、候補の子孫・clean/full-history・実ファイルblobと照合する。旧94・元監査/参照/SQL/grants guardを変更しない。
- **本実装はoffline専用。`--collect`は常に接続前BLOCK**。operator承認flag、資格情報、mysql client、実接続用transportは提供しない。stream制限は使い捨てDockerと偽clientで検証するライブラリであり、本番接続の入口ではない。
- baseline94と完成114は固定sourceを両MySQLへ二度独立適用し、全参照を再生成比較する。旧referenceとの一致は生成後の回帰検査で、旧JSONをコピーして生成とは扱わない。
- 原strict物理hash、追加の意味/方向/生成式等の厳密hash、名前を除くIndex/FK多重集合を分離する。名前差はstrict BLOCK。metadata-onlyで二重identity/実権限/完全TRIGGER/backup/writerをPASSにしない。
- metadataは固定SQL ID・単一statementのみ。現在databaseのcatalogとmigration/batchだけ。bounded readerはwall/全体時間、stream bytes、行/セル、cap+1、stderr/不正encoding/部分行/中断を固定BLOCKにする。再試行なし、raw出力なし。
- referenceの自身のcommitをJSONに埋める循環は作らない。**生成時tooling commit**と実行module/SQL/旧library/PHP生成入力のblob集合をreferenceに封印し、最終tooling HEADがその子孫かつ同blobであることを検査。最終HEAD/refファイル自体のblobとCI runはMac packetで別照合する。実装変更時は生成元commit・二重生成・参照・CI/Mac受入が失効する。

## 正負検証の設計

| 境界 | 正例 | 負例・停止 |
| --- | --- | --- |
| source/tooling | 採用済pin、旧94/114、同seal、生成blob | candidate/tooling/ref/SQL差、dirty/shallow/symlink/ignored差替、release drift |
| reference | 両版の二重再構築94/114 | 元proposed JSON転用、候補/生成元/正規化/集合hash差 |
| SQL/stream | 固定ID、完全ASCII行、上限内 | 未知ID/任意SQL/複文、cap+1、slow/oversize/partial/error/interruption |
| structure | strict94/114一致と状態分離 | 名前差、定義/列順/UNIQUE/prefix/式/可視性/FK動作差、部分DDL/衝突 |
| visibility/permissions | 合成の定義観測 | narrow userのtrigger0、権限過多、不可視を完全とする誤判定 |
| migration guard | 固定sourceの正常移行/中断保持 | M08二重有効、別名旧UNIQUE、M18/M19/M20残骸、M04→M05依存欠損 |
| publication | 固定code/件数/source pinのみ | raw schema/account/host/secret/object/例外本文を出さない |

実行結果・新HEAD・CI/Macコマンドは本票の後半とDraft PR/#488へ追記する。本番のstrict名前差、完全可視性、実DDL/binlog、実データ、backup/復元、writer、Web/iOS/Auth0/Renderは引き続き未検証。**PRODUCTION_NO_GO_HOLD**。

## 実装した境界と参照の由来

- CLIは `scripts/ops/e858_audit_packet.py`。候補sourceとtoolingを別引数にし、`--collect`は資格情報・client生成より前に `REAL_CONNECTION_DISABLED`。環境変数で有効化する入口はない。
- 通常はoffline packet。将来別途取得したprivate JSONを検査する`--private-input`も**接続機能なし**。候補/参照/SQL/CI receiptを先に照合し、owner UID・0600 regular file・0700 parent・symlink/重複JSON key/sizeを検査する。入力ファイルはユーザー所有なので勝手に削除しない。operatorがGit/同期外で扱い、終了後に廃棄する。target binding hashは提供された私的情報の整合検査だけで、認可の独立証明ではない。
- 固定SQL14本は新ディレクトリだけに配置。Q0、限定SHOW GRANTS、ledger、6区分、semantic補助、生成式/charset補助。単一statementの固定IDのみで、任意SQL/複文はclient生成前に拒否する。production用client builderは提供しない。
- 制限：SQL hint5000ms、client10秒/全体120秒、streamカテゴリ8MiB/全体16MiB、row256KiB/cell128KiB、table512/column4096/index4096/FK2048/check512/trigger128/ledger115。cap+1検出、stderr、非ASCII/制御字、途中行、timeout、中断は固定BLOCK、retryなし。hintだけに依存せずclient側で逐次強制する。切断でserver即時終了する保証はしない。
- `scripts/ci/e858_audit_mysql.py --record`はlabelとloopbackを検証した既存使い捨てDBだけ。固定candidateのGitから94/114のmigrationを抽出し、二度DBを作り直して両境界を再構築・一致照合してから、新参照だけを生成する。CIは`--record`禁止で、全再構築してcommitted参照との一致を検証する。
- `scripts/ci/e858_audit_reference/{8.0,8.4}.json`のprotected_blobsには全114 migrationも含む。basename→blobに直して通常JSON辞書のSHA256を取るのが既存seal。source manifest/集合hash、candidate/tree/release、生成元tooling SHA、SQL/bundle hash、normalization、absence契約hash、strict/extended/semanticを独立保持する。元参照と全物理hashが一致してもsource sealやtooling承認を流用しない。
- 最終tooling HEADをreference自身へ埋める循環を避け、生成元commitの全protected blobが最終HEAD/実ファイルと同一か検査する。参照fileそのものは最終tooling HEADのGit blobと照合し、CIもその最終HEADで再実行する。source/code/SQL/ref/生成元/CIの変更は受入失効。

実DB入力の出力は `original_strict_result/code` と追加検査後の `strict_result/code` を区別する。semantic名前差はstrict BLOCK。94/114/unknownを区別し、完全TRIGGER・identity実データ重複・実DDL/binlog・backup・writerは常にUNVERIFIED。本セットにwaiver/手動PASS/権限昇格APIはない。

## Macの単一コピペ受入（オーナー未実施）

必要：Git、GitHub CLIの既存read権限、Python3.9以上。Aiven/MySQL client・資格情報は不要。**正確なtooling40桁HEADとCI run IDを最終Draft PR/#488から取得**し、下の2箇所へ入れる。このソース中に自己HEADを固定しない。

```bash
bash <<'CANOVIA_E858_OFFLINE'
set -euo pipefail
umask 077
CANOVIA_E858_HEAD='<toolingの40桁HEAD>'
CANOVIA_E858_RUN='<E858 Source-bound Audit成功run ID>'
CANOVIA_E858_TMP="$(mktemp -d 2>/dev/null)"
trap 'rm -rf -- "$CANOVIA_E858_TMP"' EXIT
trap 'printf "%s\n" "{\"result\":\"BLOCK\",\"code\":\"MAC_OFFLINE_PREPARATION_FAILED\",\"real_aiven_connected\":false,\"release_authorized\":false}"' ERR
command -v git >/dev/null
command -v gh >/dev/null
command -v python3 >/dev/null
git clone --quiet --no-single-branch https://github.com/1kz-ma1/Canovia-web.git "$CANOVIA_E858_TMP/repo" > "$CANOVIA_E858_TMP/git-private.log" 2>&1
git -C "$CANOVIA_E858_TMP/repo" checkout --quiet --detach "$CANOVIA_E858_HEAD" >> "$CANOVIA_E858_TMP/git-private.log" 2>&1
gh run view "$CANOVIA_E858_RUN" --repo 1kz-ma1/Canovia-web --json headSha,status,conclusion,url,workflowName,jobs > "$CANOVIA_E858_TMP/run.json" 2> "$CANOVIA_E858_TMP/gh-private.log"
python3 -I -B - "$CANOVIA_E858_TMP/run.json" "$CANOVIA_E858_TMP/receipts.json" 2> "$CANOVIA_E858_TMP/parse-private.log" <<'PY'
import json,sys
with open(sys.argv[1]) as f:
    value=json.load(f)
with open(sys.argv[2],'w') as f:
    json.dump([value],f)
PY
trap - ERR
python3 -I -B "$CANOVIA_E858_TMP/repo/scripts/ops/e858_audit_packet.py" --candidate-sha e858b9c855970fbe73f37a2d60c9db6525b132d6 --tooling-sha "$CANOVIA_E858_HEAD" --ci-receipts "$CANOVIA_E858_TMP/receipts.json"
CANOVIA_E858_OFFLINE
```

別の一時clean/full-history cloneだけを使用し、以前のcheckoutを変更しない。GitHubからのソース/CI receipt取得後、Python packet自体はnetwork/DB接続なしで検証する。CI JSONはownerが取得した証拠として照合し、署名されたattestationや権限とは扱わない。

期待JSONは `SOURCE_BOUND_TOOLING_OFFLINE_VERIFIED`、候補/sourceとtoolingの別SHA、candidate_adopted=true（AUDIT_CANDIDATE_ONLY）、release_pin_matches=true、94/114/変更5件、seal/SQL/参照/blob集合hash、CI run ID、local_fixtures=EXECUTED_WITHOUT_DATABASE、local_mysql=NOT_RUN_CI_EVIDENCE_ONLY、tooling_ready_for_owner_review=true。real_aiven_connected / production_modified / strict_block_waived / release_authorized はfalse。

このMac手順は小さな合成fixtureとsource/tamper/stream負例をローカル実行するが、Mac自身でMySQL再構築はしない。そのcoverageは固定tooling HEADのCIを別表示する。MacでMySQLを実行済みとしない。結果JSONを受領し、ownerがoffline範囲を確認するまでは **TOOLING_MAC_ACCEPTANCE_PENDING**。CodexのLinux成功やCIだけではMAC_ACCEPTEDにしない。

## 次の実環境フェーズへ進むための別承認

本実装・CI・Macの成功でも実Aiven接続は不可。現CLIの`--collect`を承認flagで有効にすることもできない。実測を行うには、取得transportの安全性と次の1回scopeを**別審査**する。

認可operator1名、既存service/schemaのprivate binding、既存REFERENCES＋ledger SELECTだけの権限、candidate/source/seal/release、tooling SHA、SQL bundle/参照hash、期限付きtime window・1回、raw非表示/非持出し/廃棄、失効とSTOPを承認票へ固定する。未記入・pin/対象/権限違い・timeout/cap/途中出力・ledger変化・不可視性はSTOP。業務行SELECT・DDL/GRANT・backup/restore・writer停止・Render/Auth0・公開は含めない。

旧c09実測は再利用可能な履歴だけ。strict名前差審査、完全TRIGGER、migration principal/binlog、実identity衝突、実復元/RPO/RTO、全writer、実Web/iOS/Auth0/Render runtimeは未実証のまま。**PRODUCTION_NO_GO_HOLD**。

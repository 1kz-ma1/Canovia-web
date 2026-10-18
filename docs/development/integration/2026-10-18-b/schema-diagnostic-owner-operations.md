# B案：物理スキーマBLOCKの診断専用操作票 / Draft・NO-GO

正本は [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488) と [統合 #790](https://github.com/1kz-ma1/Canovia-web/pull/790)。2026-10-19 JST。
固定候補 **`c09a8fc718c338f98a4045a4b5f2260c7571fdaa`** / **`release/2026-10-19-c09a8fc7`** は動かさない。
オーナー提出の `applied_count=94 / mismatched_section_count=2 / PARTIAL_DDL_OR_UNEXPECTED_PHYSICAL_SCHEMA` は **BLOCKのまま**。本票の準備・CIは実Aiven再接続の承認でも、別候補の採用でもない。

## 診断の意味・持ち出してよい項目

元の監査器・collector・SQL・両版の期待ハッシュ・114 migration定義を変更しない。独立したtooling SHAから、候補のmigration blob集合・旧参照物との一致を検査する。
診断用の補助参照は、同じ候補の旧94／新114を使い捨てMySQLで再生成し、**既存の全count/hashと一致した場合だけ**作成する。既存ハッシュの照合結果が判定の正本であり、補助投影は合否に代用しない。

GitHub/チャットへ共有できるのは、新collectorが出力する **JSONだけ**。固定code/result、boolean、集計count、下表の固定ID・分類に限定する。

| 安全な項目 | 意味・限界 |
| --- | --- |
| `section` | `table / column / index / foreign_key / check / trigger` の6固定IDのみ。オブジェクト名は返さない。 |
| `expected_count / observed_count` | 区分全体の集計。件数一致は構造一致の証明ではない。 |
| `MATCH` | 既存の厳密count/hash一致。不可視なオブジェクトの不存在を証明しない。 |
| `COUNT_DIFFERENCE` | 件数差。欠損・追加・不可視性のいずれかは未確定。 |
| `OBJECT_IDENTITY_DIFFERENCE` | 同件数だが内部の識別子集合が異なる。識別子自体は出力しない。 |
| `COLLATION_ONLY_DIFFERENCE` | 同じ識別子・件数で、採取した全フィールドからCollation欄だけを除いた場合に一致。**元のBLOCKは維持**。修正SQLや許容変更を意味しない。 |
| `DEFINITION_DIFFERENCE` | Collationを除いても定義が異なる。`collation_difference=true` ならCollation差も併存。対象外区分・件数/識別子差では同項目はnull。 |
| `metadata_visibility` | 既存GRANTS検査を通した場合だけ `NARROW_REFERENCES_AND_LEDGER_SELECT_CONFIRMED`。全メタデータの完全可視性ではない。 |
| `trigger_visibility` | 常に `ABSENCE_OR_INVISIBILITY_NOT_DISTINGUISHABLE`。TRIGGERの権限不足と実欠損は最小権限だけでは識別不能。 |
| `diagnosis_code` | 正常解析は `SECTION_CLASSIFICATION_ONLY`。途中/未知ledgerは `LEDGER_NOT_AT_REFERENCE_BOUNDARY`、補助参照不正は `DIAGNOSTIC_REFERENCE_REJECTED`。どちらもBLOCK。 |

生のmetadata・SQL結果・ledger・GRANTS・内部hash・オブジェクト名・schema名・host・アカウント・CAパス・秘密値は共有しない。`--verbose`、shell trace、画面収録、ログ添付、手動SQLの貼付は不要。
SQLは従来と同じで、既存GRANTS→migration ledger→information_schemaの3回だけ。一般テーブルのデータを取得しない。各client stdout/stderrは0700一時ディレクトリ内だけで扱い、通常終了・失敗で削除する。強制終了/OS障害まで消去を保証する機密保管方式ではないため、オーナー管理端末だけで実行する。

## オーナーの操作順とSTOP

**現在できるのは段階0まで。段階1以降は本Draftの安全性レビューと、新しい「診断SELECTのみ」の明示承認後だけ。エージェントは実Aivenへ接続しない。**

| 段階 | 操作 | STOP条件 |
| --- | --- | --- |
| 0 オフライン | Draftの全差分・最終40桁tooling SHA・そのSHAの両MySQL CIを確認。既存のrelease監査フォルダを変更せず、新規clean cloneへ下記コマンドで取得。`--collect`なしで起動。 | clone先が既存、SHA/clean/blob/参照の不一致、浅い履歴、候補違い、CI未完了/失敗。候補へcherry-pick・コピー・resetして通さない。 |
| 1 対象・承認 | オーナーが実サービス/DB/既存login-path/CAを非公開で再確認。既存のメタデータ最小権限（schema REFERENCES＋migration ledger SELECT、任意USAGE）を維持。tooling SHA・候補SHA・対象・3 query・持出範囲を明記して**再接続を別承認**。 | 対象不明、権限拡張の要求、既存の監査承認しかない、接続先/CA変更、追加費用/操作が必要。 |
| 2 一度だけ診断 | 同じtooling checkoutで、承認後のみ下記subshellを実行。TLS VERIFY_IDENTITY、固定release ref/main祖先、GRANTS検査を通した後だけmetadataを採取。 | preflight/TLS/認証/権限/SQL/形式の失敗、未知engine、別ledger、タイムアウト。失敗を通すためのGRANT、SSL無効化、期待値差替、無条件retryは禁止。 |
| 3 結果レビュー | 出力JSONだけを#488へ記録。区分・件数・分類を次のオフライン原因調査へ使用。 | **BLOCKなら終了**。CollationのみでもBLOCK。全一致のREVIEW_REQUIREDもリリースGOではない。解決のためのDDLや新候補採用は本作業に含めない。 |

段階0：以下の `<レビュー済みtoolingの40桁SHA>` をDraft最終報告の値へ置き換える。moving branchのHEADを承認済みSHAとみなさない。既存clone先を上書きしない。

```sh
git clone --no-checkout https://github.com/1kz-ma1/Canovia-web.git ../Canovia-schema-diagnosis-20261019
cd ../Canovia-schema-diagnosis-20261019
git fetch origin
git switch --detach <レビュー済みtoolingの40桁SHA>
python3 -B scripts/ops/integrated_release20_diagnostic_collect.py \
  --tooling-sha <レビュー済みtoolingの40桁SHA> \
  --candidate-sha c09a8fc718c338f98a4045a4b5f2260c7571fdaa
```

期待出力は `OFFLINE_DIAGNOSTIC_READY_NO_CONNECTION / REVIEW_REQUIRED`。これはソース準備だけ。`BLOCK_SOURCE_SHA`になった旧フォルダは触らない。

段階2（**未承認・今は実行しない**）：既存の `CANOVIA_P0_LOGIN_PATH / CANOVIA_P0_DATABASE / CANOVIA_P0_CA_FILE` を非公開で設定・確認する。値をチャットやコマンド履歴へ貼らない。DB/URL/password環境overrideがある場合は、それを使って接続せずSTOP。subshell外へ承認を保持しない。

```sh
(
  export CANOVIA_RELEASE20_RELEASE_REF=release/2026-10-19-c09a8fc7
  export CANOVIA_RELEASE20_DIAGNOSTIC_APPROVED=YES
  export CANOVIA_P0_TARGET_VERIFIED=YES
  python3 -B scripts/ops/integrated_release20_diagnostic_collect.py \
    --tooling-sha <別承認に記載した同じ40桁SHA> \
    --candidate-sha c09a8fc718c338f98a4045a4b5f2260c7571fdaa --collect
)
```

元の `CANOVIA_RELEASE20_OPERATOR_APPROVED=YES` だけでは新診断を実行できない。従来のrelease collectorは変更せず、新診断専用の承認が必要。

## 検証と残るNO-GO

MySQL8.0/8.4の使い捨てCIは、候補の旧94→新114、正常全hash一致、Collationだけの2区分不一致、column default定義差、ledgerを増やさない部分CREATE、FK欠損、CHECK追加を検証する。
同じ114構造について、**実際にREFERENCES＋ledger SELECTだけの合成アカウント**から3triggerが見えないこと、一般データSELECT/CREATE TRIGGERが拒否されること、privileged側で実際にtriggerを除いた構造も同一の採取結果となることを確認する。fixtureのDDL/GRANTはlabel・loopback portを検証した使い捨てcontainer内だけ。CIは補助参照を再作成せず、既存値との一致を検査する。生出力artifactは作らない。

この同一観測の検証により、最小権限を維持したまま不可視性と実欠損を常に識別できるという主張はできない。table/column/FK/checkの差でも権限・サーバー差の可能性を排除せず、人間レビューへ戻す。全種類の部分DDL停止点を網羅した試験でもない。
本番の2区分・原因・差分内容は **UNKNOWN** のまま。Collation負例の一致パターンは仮説の再現であり、本番原因の特定ではない。
独立したTRIGGER可視性/実DDL principal/binlog確認、実バックアップ・隔離復元/RPO/RTO、全writer停止計画、実機/認証/本人AP受入は既存#488の未達ゲートを維持する。**production NO-GO / HOLD**。
main/productionのmerge、固定release ref更新、Aiven接続/DDL/GRANT/設定変更、Render/Auth0変更、実ユーザーデータ取得、#513の更新は本PRの実行対象外。

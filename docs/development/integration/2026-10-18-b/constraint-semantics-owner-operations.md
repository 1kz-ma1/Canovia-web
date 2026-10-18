# Index / Foreign Key意味比較：診断のみ・BLOCK維持

2026-10-19 JST。[#488](https://github.com/1kz-ma1/Canovia-web/issues/488) のオーナー提出値：ledger94、index604/604・FK181/181が `OBJECT_IDENTITY_DIFFERENCE`、table/column/checkはMATCH、triggerは観測MATCHでも不可視性未排除。
固定候補 **`c09a8fc718c338f98a4045a4b5f2260c7571fdaa`** / `release/2026-10-19-c09a8fc7` は不変・HOLD。
[Draft #791](https://github.com/1kz-ma1/Canovia-web/pull/791) の旧tooling **`dbe3b11ca15cd6d82ef69e6db1cd2fa8fe8c77d0`** を継承して同じDraftのHEADを更新する。新しいtooling SHAはPR最終報告・#488で固定する。旧SHAのMac受入・CIは旧版の証拠として保持し、新版の受入に流用しない。

## 比較と安全な出力

既存の厳密hash、判定、metadata SQL、94/114 migrationは変更しない。新しいSELECT専用の補足SQLは、従来の各index/FK行にdirection/nullability、FK match option/親key内位置を追加する。補足行の元10フィールドが従来採取行と完全一致しなければBLOCK。生結果・名前・接続値を出力しない。

- Index：テーブル、PRIMARYか否か、列順序、UNIQUE、式のHEX、prefix長、可視性、種別、ASC/DESC、nullabilityを保持。名前だけ除外する。
- FK：子テーブル、子列順序、親テーブル/列順序、LOCAL/FOREIGN、UPDATE/DELETE、match option、親key内位置を保持。制約名だけ除外する。
- **複合制約ごとにグループ化し、順序付き構成を多重集合で比較**。同じ定義の重複も数える。単純なset化や列行の並べ替えだけで一致扱いしない。
- 式・動作コードの代数的/意味的な別表現は正規化しない。`NAME_ONLY_DIFFERENCE`は採取した全定義フィールドが厳密一致するという限定診断で、全SQL意味論・運用互換性の証明ではない。

共有できるのはcollectorのJSONのみ。既存の固定区分・判定・集計に、次の固定項目を追加する。内部名・個別hash・生metadata・SQL結果・GRANTS・host/DB/account/CA情報は共有しない。

| 項目 | 意味・STOP条件 |
| --- | --- |
| `semantic_code=CONSTRAINT_SEMANTICS_CLASSIFIED` | 補足参照と入力を検証して分類できた。GOではない。 |
| `semantic_sections[].classification=MATCH` | その区分の従来hashも定義の多重集合も一致。 |
| `NAME_ONLY_DIFFERENCE` | 定義の多重集合は一致するが従来hashは不一致。**従来のBLOCKを維持**。 |
| `SEMANTIC_DEFINITION_DIFFERENCE` | 定義/構成/重複数の不一致。行数が同じでも拒否。 |
| `expected/observed_object_count` | グループ化したIndex/FKの個数。 |
| `expected/observed_row_count` | 構成列の行数。**604/181は制約個数とは限らない**。 |
| `SEMANTIC_REFERENCE_OR_PRIVATE_INPUT_REJECTED` | 補助参照、形式、重複、途中行、別snapshot等を拒否。BLOCK。 |
| `migration_name_dependency` | 下記の名前依存1件について固定ID `RELEASE20_IDENTITY_UNIQUE_TRANSITION` と分類だけ返す。 |
| `EXPECTED_NAME_AND_DEFINITION` | 旧indexの名前と定義が1つだけ一致。移行承認ではない。 |
| `SEMANTIC_ALIAS_ONLY_BLOCK` | 同じ定義が別名にだけ存在する。移行結果に影響し得るのでSTOP。 |
| `MISSING_INCOMPATIBLE_OR_AMBIGUOUS_BLOCK` | 旧indexが欠損/不整合/曖昧。STOP。 |
| `HISTORICAL_TRANSITION_NOT_PROVEN` | ledger114で現在構造を見ても過去の移行過程は証明できない。 |
| `future_constraint_name_collisions_verified=false` | 新設名との衝突を全て証明したものではない。追加DDLの承認へ進まない。 |

補足だけでdirection等の不一致を検出した場合も判定をBLOCKへ厳格化する。名前差を理由に従来BLOCKをREVIEW_REQUIRED/PASSへ緩和しない。TRIGGER可視性・本番復元・DDL権限のfalseも維持。

## 過去のP0修復と追加20件の名前依存監査

固定ソースの `2026_10_09_235959` は整合する既存FK/Indexを別名のまま保持し、欠損時に命名して補う。`2026_10_05_000400` も既存列に対するFK/Indexを保持し得る。後者の既存FK判定は子列中心であり、親・動作まで等価だったとは推定できない。**名前差の発生仮説であり、本番原因の特定ではない。**
正確な20ファイルとblobは[既存manifest](main-source-manifest.json)と固定候補のGit treeを参照。下表のM番号はmigration名の昇順、先頭timestampで照合する。追加20件のGit sealは従来どおり全件検査する。新機能・DDL修正は含めない。

| 固定範囲 | UPの監査結果 / DOWN・残存リスク |
| --- | --- |
| M01 `2026_10_12_010000` | 列追加。既存index/FK名への参照なし。downは列削除。 |
| M02 `2026_10_13_021000` | 列と新index追加。旧名を検索/削除しない。downは列削除。 |
| M03 `2026_10_13_110000` | 2列追加。旧index/FK名への参照なし。 |
| M04 `2026_10_13_120000` | 新trigger/function名を使用。旧index/FK名には非依存。既存trigger不可視性・名前衝突は別ゲート。 |
| M05 `2026_10_13_130000` | MySQLはM04のtrigger名にFOLLOWSで依存。旧index/FK名には非依存。downはtrigger名で削除。 |
| M06 `2026_10_13_200000` | nullable列追加。旧名非依存。 |
| M07 `2026_10_14_000100` | nullable期限変更。旧index/FK名非依存。downのデータ条件は別審査。 |
| **M08 `2026_10_14_000200`** | **旧UNIQUE名をhasIndex/dropUniqueで参照**。新active UNIQUE→旧UNIQUE削除→history indexの順。旧名の別名化で旧UNIQUEが残り、履歴再利用を妨げ得る。新2名もhasIndexで分岐するため、別定義との名前衝突は危険。downも同3名に依存。 |
| M09–M11 `2026_10_14_100000/120000/130000` | 新table/indexの作成。旧名の検索/削除なし。既存部分DDL・新名衝突を許容する根拠にはしない。 |
| M12–M15 `2026_10_14_140000/150000/154500/164000` | 新tableのUNIQUE/Index/FK作成。旧index/FK名を検索/削除しない。新FK名のDB-wide衝突・参照先keyの適合性は未証明。 |
| M16–M17 `2026_10_14_185000/201000` | 新管理tableへの列追加。旧index/FK名非依存。 |
| M18 `2026_10_15_070000` | 新table/UNIQUE/Index/FK作成。既存時の列検査は名前互換性の証明ではない。downはtable削除。 |
| M19 `2026_10_15_110000` | 新2tableと制約作成。既存時は列検査。旧名非依存だが部分DDL・新名衝突を別審査。 |
| M20 `2026_10_15_120000` | 新table作成、既存時は列/PRIMARY/UNIQUE/FK定義を確認。旧index/FK名に非依存。downはtable削除。 |

**UPで旧index名を参照するmigrationは1件、旧FK名の検索/削除は0件。** 新設名やM04→M05のtrigger依存はこの数に含めない。全downの実行互換性を承認しない。
使い捨て両MySQLで、P0対象のIndex/FKを別名にした94→114を実適用する。さらにM08の旧UNIQUEも別名にした負例で、**ledger114になっても余分な旧UNIQUEが残り、候補の意味的定義に不一致となる**ことを確認する。これは移行コードを修正せずに危険を示す負例であり、本番へそのまま適用する手順ではない。

## Macオフライン受入と将来の再診断

既存のrelease監査フォルダ/旧tooling checkoutは変更しない。PRの最新40桁tooling SHA・全CI・差分をレビューし、下記 `<新toolingの40桁SHA>` を**その固定値**に置き換える。clone先が既存ならSTOP、上書き/削除/force resetしない。

```sh
git clone --no-checkout https://github.com/1kz-ma1/Canovia-web.git ../Canovia-constraint-semantics-20261019
cd ../Canovia-constraint-semantics-20261019
git fetch origin
git switch --detach <新toolingの40桁SHA>
python3 -B scripts/ops/integrated_release20_diagnostic_collect.py \
  --tooling-sha <同じ新toolingの40桁SHA> \
  --candidate-sha c09a8fc718c338f98a4045a4b5f2260c7571fdaa
```

期待値は `OFFLINE_DIAGNOSTIC_READY_NO_CONNECTION / REVIEW_REQUIRED`。ソース準備だけで、本番原因の分類は未実施。本番接続も候補更新も起こさない。

**今は実行しない段階**：安全性レビュー後、オーナーが新tooling SHA・候補・対象・補足SELECT範囲を別承認して初めて再診断できる。従来の承認変数だけでは接続させない。既存login-path/DB/CAは非公開で確認・設定する。

```sh
(
  export CANOVIA_RELEASE20_RELEASE_REF=release/2026-10-19-c09a8fc7
  export CANOVIA_RELEASE20_SEMANTIC_APPROVED=YES
  export CANOVIA_P0_TARGET_VERIFIED=YES
  python3 -B scripts/ops/integrated_release20_diagnostic_collect.py \
    --tooling-sha <別承認に記載した同じ新toolingの40桁SHA> \
    --candidate-sha c09a8fc718c338f98a4045a4b5f2260c7571fdaa --collect
)
```

MySQL client起動は3回（既存GRANTS→ledger→既存metadata＋補足2 SELECT）。一般テーブルの行は読まない。既存最小権限、VERIFY_IDENTITY、clean/blob/ref/main祖先確認を維持。新たなGRANT/DDL/設定変更はしない。
**STOP**：別承認なし、source/ref/対象/CA不一致、既存最小権限外、SQL/TLS/形式/補足照合失敗、未知/途中ledger、意味差、名前依存alias、BLOCK。SQLを手貼りしたり期待hash・SSL・権限を緩めて通さない。通常終了/失敗時にprivate一時ファイルを削除するが、強制終了時の完全消去は保証しないため管理端末だけで実行する。

実際の意味一致/不一致は **UNKNOWN**。名前のみと判明してもstrict BLOCKを解消せず、M08互換性・新設制約名衝突・TRIGGER可視性・DDL actor/binlog・実backup/隔離復元・writer fence・実機/認証受入が残る。**NO-GO/HOLD**。本DraftのCIは使い捨てDBだけで、本番修復や公開を承認しない。

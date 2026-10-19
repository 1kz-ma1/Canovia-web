# B案：全20 migrationの別名互換性・移行安全性 / Draft・NO-GO

> この票の変更範囲・件数は#792固定HEAD `c3ecddacebab499ea6f9c1c454542edea6c3810e` の証拠。後続の[統合前最終レビュー](preintegration-final-review.md)はM18コメントとM20の追加検査を別Draftで扱う。旧Mac受入・旧CIを新HEADへ流用しない。

2026-10-19 JST。正本[#488](https://github.com/1kz-ma1/Canovia-web/issues/488)、統合[#790](https://github.com/1kz-ma1/Canovia-web/pull/790)、診断[#791](https://github.com/1kz-ma1/Canovia-web/pull/791)。
この変更は **#791の固定HEAD `e6124170e4ab8f92709d7683544d999822a96581` をbaseとする独立した積み上げDraft**。#791のHEADを更新しない。main向け差分には#791の未統合部分も入るため、今回の修正差分はそのbaseから比較する。
固定release候補 **`c09a8fc718c338f98a4045a4b5f2260c7571fdaa`** / `release/2026-10-19-c09a8fc7` は一切変更しない。

## 証拠と今回の変更範囲

| 区分 | 状態・範囲 |
| --- | --- |
| REAL_ACCEPTED（オーナー提出、独立再照会なし） | 94 applied、91 table/1,154 column一致。Index450個/604行、FK181個/181行はNAME_ONLY_DIFFERENCE。M08はEXPECTED_NAME_AND_DEFINITION。check一致。triggerは観測一致だけ。 |
| 継続BLOCK | 元のstrict auditは2区分不一致。名前だけでもBLOCKを解除しない。一般的な移行承認ではない。 |
| ソース修正 | 未適用20件のうちM01/M08/M18/M19だけ変更。旧94 migrationは全blob不変。migration数114のまま。新しいhelper・source由来のabsence契約・合成CIを追加。 |
| 候補の扱い | 本DraftのSHAは修正提案で、固定候補とは別物。採用するならownerによるmain統合・新release pin・新source sealの審査が必要。**自動採用しない**。 |
| 検証の限界 | 実Aivenの名前一覧は取得していない。合成的な別名であり、本番命名の完全再現ではない。実backup/restore・権限/binlog・実機は未検証。 |

元のaudit/SQL/参照hash/意味診断/metadata grants guardを変更しない。旧reference自体のseal/hashは書き替えない。提案専用referenceは別ディレクトリに新source sealで固定し、全物理hashが元と同一であることをテストする。これはCI用の未採用ソース参照であり、実監査の既定参照先は変更しない。CIでは新ソースに対して元auditがBLOCKを返すことも確認する。構造の一致検査は**合成テストの独立assertion**であり、監査判定の置換ではない。

## A：固定20件の監査

M番号は固定候補の追加migration名昇順。ファイルとblobの一覧は[既存manifest](main-source-manifest.json)を参照。型/default/nullable/collation/列順序は固定sourceと、両MySQLで再構築して既存reference全hashと一致したcanonical metadataを正本とする。新設全11table・7列・13FK・3triggerとIndexの差分から[absence契約](../../../../resources/release20_pending_schema_contract.json)を再生成・照合する。これは本番採取結果ではない。

全Indexについて、テーブル・構成順序・PRIMARY/UNIQUE・prefix・式・可視性・種別・ASC/DESC・nullabilityを比較する。FKは子列順序・参照table/列順序・LOCAL/FOREIGN・UPDATE/DELETE・match/親key位置を比較する。新設定義は通常、全列BTREE/visible、prefix/式なし。動的cardinalityなどデータ依存統計は比較対象外。名前一致と定義一致を混同しない。

| ID / timestamp | 操作・依存・データ互換性 | 部分DDL/再実行・down制限 / 対策 |
| --- | --- | --- |
| **M01** `2026_10_12_010000` | observationにnullable source_updated_at(timestamp)、source_revision_id(unsigned bigint)。旧値を推測してbackfillしない。旧Index/FK名への直接依存なし。 | 2 ALTER途中ではretryが重複列で停止。**追加した初期guard**は新table/列/旧table上の新Index名・DB-wide FK/trigger名・M08を、最初のDDL前に検査。downは追加列を削除するため本番不許可。 |
| M02 `2026_10_13_021000` | 同observationにnullable uuid source_epochとIndex。M01より後の同table変更。旧名を検索/DROPしない。 | 列とIndexの間で停止し得る。重複列のretryは停止し自動完了扱いしない。downは列を消す。変更せず手動復旧ゲートを維持。 |
| M03 `2026_10_13_110000` | Planとmemberにunsigned authorization_epoch default1。履歴epochの復元ではない。旧writerは既定値で互換。 | 2 ALTERの途中停止・retry拒否を実注入。既存行保持、ledger不進行。downは列削除。単純な再実行で直す変更は加えない。 |
| M04 `2026_10_13_120000` | Plan/memberの変更を検知する2trigger。M03列に依存。旧raw writerもepoch更新を受ける。Index/FK名に非依存。 | 1つ目CREATE後停止→retry拒否を実注入。不可視trigger・実TRIGGER権限/binlogは別ゲート。downはtrigger/functionを削除し防御を失う。既存SQLを変えずSTOP。 |
| M05 `2026_10_13_130000` | member再割当拒否trigger。MySQLのFOLLOWSは**M04のmember trigger名**に直接依存。M04順序を変えない。 | 欠損/衝突/権限不足は停止。M01で可視な衝突だけ先行検出。不可視性を不存在と認定しない。downは拒否防御を消す。本番自動rollback不許可。 |
| M06 `2026_10_13_200000` | recall candidateにnullable JSON review_provenance追加。旧行はNULL、過去根拠を捏造しない。 | ALTER重複時は停止。旧名非依存。downは記録を削除するため別審査。変更不要。 |
| M07 `2026_10_14_000100` | MCP grant期限をnullable化。既存finite期限は保持し、機能自体はONにしない。 | 同じALTERは構造的に繰返し可能。downはNULL期限が残れば拒否。NULLを無断書換しない。旧Index/FK名非依存、変更不要。 |
| **M08** `2026_10_14_000200` | active fingerprint(char64 nullable)追加→backfill→新UNIQUE→旧UNIQUE削除→history Index。不変の旧link/grant/audit行を保持。旧UNIQUE名と新2名に依存。 | **新read-only guard**は名前だけでなく全MySQL Index定義、列型、alias/重複/衝突、active候補重複をDDL/backfill前に確認。旧名aliasはDROPせず拒否。本番の旧名EXPECTEDという実測を保持。列/新UNIQUEのcommit直後停止からの整合した再開を実検証。downは履歴再利用の重複があれば拒否し、同3名にも依存。本番down承認なし。 |
| M09 `2026_10_14_100000` | cleanup entry新table。暗号化path/status/retry時刻等とdue複合Index(status,next_attempt_at,id)。実path/個人データは合成せず、fixture値のみ。 | CREATE済みなら通常CREATEが拒否。table存在を採用しない。新table初期存在はM01で拒否。downは全行削除なので不許可。変更不要。 |
| M10 `2026_10_14_120000` | path fence新table、path_digest UNIQUE。旧resourceを書換しない。 | 同上。UNIQUE欠損を列存在で完了扱いする経路なし。downでfenceを失うため不許可。 |
| M11 `2026_10_14_130000` | upload intent新table、digest UNIQUE、状態/時刻。 | CREATE残骸は拒否。旧名非依存。downは復旧intentを失うため不許可。 |
| M12 `2026_10_14_140000` | generation evidence新table。M11への単列UNIQUE＋FK、DELETE RESTRICT、owner複合Index。 | M11と作成順序に依存。FK名はDB-wide。衝突はM01で先行拒否、途中CREATEは手動復旧。downは証跡削除なので不許可。 |
| M13 `2026_10_14_150000` | write attempt新table、M11 FK＋UNIQUE・状態等。 | M11に依存。CREATE/FK間の停止は自動採用なし。downは試行証拠を削除するため不許可。 |
| M14 `2026_10_14_154500` | operational profile新table、Plan単列UNIQUE/FK DELETE CASCADE。 | 親のPlan PKは名前でなく定義・参照整合性が必要。FK新名衝突を検査。downはprofile削除、禁止。 |
| M15 `2026_10_14_164000` | operational target新table、Plan UNIQUE/FK CASCADE、revision/backup/restore記録。 | M14と独立、M16/M17の前提。CREATE残骸を採用しない。downは運用証拠削除、禁止。 |
| M16 `2026_10_14_185000` | M15へmanual service checkのnullable3列追加。 | 複数ALTERの途中は重複列で停止。downは確認記録削除。旧名非依存、変更不要。 |
| M17 `2026_10_14_201000` | M14/M15へstorage revision fence列追加。default/nullableは固定sourceと全column hashで照合。 | 複数table ALTERで停止し得る。再実行を無条件許可しない。downでrevision根拠を失う。変更不要。 |
| **M18** `2026_10_15_070000` | study proposal新table。user/Plan/Task/grant FK CASCADE、grant/idempotency UNIQUE、owner/Plan複合Index。write scopeを有効化しない。 | 旧版は列だけあれば欠けたFK/Indexを完了扱いし得た。**pending upで既存tableなら常に拒否**へ修正。全constraints済みでledger未記録でも自動採用せず審査。CREATE直後停止/再試行を実検証。downは提案/監査根拠削除、禁止。 |
| **M19** `2026_10_15_110000` | watch state/change新2table。Plan/user FK CASCADE、Plan UNIQUE、dedup UNIQUE、複合lookup Index。機能はOFF。 | 旧版は列存在だけで残骸を採用し得た。**両tableを最初に検査し、どちらか存在すればDDL前拒否**。1table目CREATE直後停止と2table目衝突のPHP負例を検証。downはwatch根拠削除、禁止。 |
| M20 `2026_10_15_120000` | source epoch新table、Plan UNIQUE/FK CASCADE、epoch default0。 | 既存tableは列・PRIMARY/UNIQUE/FK定義を確認し、不完全なら拒否する既存修正を維持。CREATE直後停止/拒否・完全table再実行と行保持を検証。全停止点の網羅とはしない。downは世代記録削除、禁止。 |

旧94の制約名が影響する依存は、M08の旧UNIQUE、および新FKの参照先である旧tableのPK/unique/index意味とDB-wide FK名namespace。P0修復2件が別名を保持し得ることを再利用し、旧94ファイルを変更しない。新しいFK名と同名にした**別tableの旧FK**を負例とし、Index同名は**別tableなら誤検出しない**正例を用意する。

## B/C：再現された問題と対策

| 再現 | 修正/判断 |
| --- | --- |
| M08旧名aliasで、旧版はledger114でも余分な旧UNIQUEを保持 | 自動rename/DROPせず先行BLOCK。実本番ではEXPECTEDという証拠を保持し、不要な本番変更を提案しない。 |
| M08同名異定義・prefix違い・重複・新名衝突 | full index definitionを確認して拒否。名前だけでDROPしない。 |
| 新FK名が別tableの既存FKと衝突すると、新CREATEの残骸を残し得る | M01の初期guardでDB全体を照合し、初回DDL前に拒否。Index名は当該table内だけ照合。 |
| M18/M19の列のみ残骸が無条件採用され得る | pending upの既存tableを拒否。Laravelはapplied ledgerをskipするため正常な2回目migrateには影響しない。 |
| 複合順序/UNIQUE/参照先/DELETE違いで件数だけ合う | #791のstrict/semantic診断を維持して**移行前**に拒否。診断BLOCKを無視した実行パスを新設しない。 |
| M03/M04/M20などのpartial DDL | state/ledgerを維持して再試行を拒否。万能な自動修復を実装しない。M08の検証済み整合prefixだけ再開可能。 |

初期guardは、DDL principalの同一接続が見えるmetadataに対するpoint-in-time検査であり、DB全体をロックして後続20件と原子的にするものではない。全writer/DDL actor停止・完全TRIGGER可視性がなければ、後からの衝突や変更を防げない。権限追加で検査を通さず、監査用最小権限とmigration principalを混同しない。

テストはlabel/loopbackを検査した使い捨てMySQL8.0/8.4のみ。旧94から合成user/Plan/Task/Attempt/session/link/grant/audit/resourceをseedし、**migrations以外の全旧table・全旧列・全行をPK順digest**で封印する。正例は114全構造・Index/FK多重集合・全triggerを期待canonicalと照合、旧行保持、DBのFK/UNIQUE/旧writer fenceを確認し、2回目のmigrate成功DDL数0を確認。負例は全行/構造/ledgerの前後一致を検査し、実DDL commit直後の例外を注入する。生metadata/dump/errorはprivate一時領域のみ、artifactにしない。

名前だけの正例は、Indexのみ/FKのみ/両方、別tableのIndex同名を含む。負例は複合順序、同列の異なるUNIQUE、同名異定義Index/FK、新Index/FK/trigger名衝突、M08旧名alias/重複/prefix/不可視、M05前提trigger欠損を含む。停止点はM03/M04/M08列/M08新UNIQUE/M08旧UNIQUE削除/M08history Index/M18/M19/M20の9箇所。合成名は本番の実名と対応付けない。

M18/M19は修正後の拒否だけでなく、固定c09ファイルをGitから私有tempへ抽出した**修正前control**も実行する。CREATE直後に停止して再実行すると、旧版は欠けた制約のままledger114へ進み、元のstrict auditはBLOCKすることを確認する。本番で発生したという主張ではなく、固定ソースの合成再現である。

## D：名前差の例外審査案（未実装・承認なし）

**strict結果はBLOCKのまま保存する。** 意味的一致は別の診断証拠として紐付け、プログラムでPASSへ変換しない。本Draftは例外承認API/waiverファイル/環境変数による昇格を追加しない。誰がJSONに承認と書いても実行権限・公開判定は増えない。

人間のリリース審査へ進める前提：

1. ownerが採用候補の全40桁SHAとrelease refを別承認し、source/新seal/全差分・当該CIが一致。
2. 認可済みDB operatorが、私有の対象binding・確認時刻・tooling SHAと実測を保管。GitHubへは安全な固定結果のみ。実DBごとに記録し、合成証拠をREAL_ACCEPTEDへ昇格しない。
3. strict BLOCKの原判定と、Index/FK意味一致・M08期待名/定義・新設名namespace衝突なしを**別々の実測**として関連付ける。
4. 完全TRIGGER可視性/定義/順序、M04→M05、実DDL principalの必要権限・binlog policyを独立に受入。
5. 実backupと隔離restoreの完了、旧データ/認証/セッション/暗号鍵/実ファイルの整合性、RPO/RTO、復旧先/費用を確認。
6. Web/worker/queue/旧instance/DDL actorの停止、起動check・失敗時の状態別forward recovery、固定sourceへの復旧条件を準備。
7. 許可されたoperator本人の認証済み監査記録と、owner本人の**対象SHA・限定scopeを明示した別承認**を確認。自由記述の自己申告やCI greenで代用しない。

以上は「人間が審査できる資料」の条件でありGOではない。将来例外を採用する場合も、既存branch protection/required checkを回避する自動化は別実装・別審査が必要。本Draftの判定はNO-GOのまま。

## E：現状ゲートとoperatorの最小操作

| ゲート | 現在 |
| --- | --- |
| 旧94保持・全20静的監査・別名/衝突/中断/保持の合成検証 | 当該Draftの固定head CIで確認。最終URL/件数はPRと#488に記録。 |
| 本番のIndex/FK名前差だけ・M08旧名一致 | オーナー提出REAL_ACCEPTED。ただし元のstrict BLOCK維持。 |
| 新ソース採用・新source seal・新release ref | **未承認/BLOCK**。旧c09候補と異なる。 |
| 本番の新設名衝突・完全TRIGGER・実DDL/binlog | **未検証/BLOCK**。新guardの合成成功で代用しない。 |
| 実backup/隔離restore・writer fence・実機/実認証 | **未検証/BLOCK**。既存#488の停止ゲートを再利用。 |
| 名前差例外審査・production promotion | **提案のみ/未承認/BLOCK**。本Draftは承認を実装しない。 |

オーナーが今行うのは**新しいDraftの差分・固定head CI・本票のレビューとoffline source packet確認だけ**。#791/release用の既存checkoutを変更せず、新規clean cloneで当該Draftの40桁SHAをdetach checkoutし、次を実行する（実接続オプション自体なし）。

```sh
python3 -B scripts/ci/release20_proposal_packet.py --proposal-sha <レビュー対象の40桁SHA>
```

期待：`OFFLINE_PROPOSAL_PACKET_ONLY / REVIEW_REQUIRED`、`new_candidate_adopted=false / strict_block_waived=false / release_authorized=false`。source/clean/blob不一致はBLOCKで停止。旧#791 collectorを新ソースへ無理に適用しない（sealが変わるため拒否が正しい）。
その後のmain統合・新候補採用・新source監査器/参照sealの承認・実Aiven追加SELECT・実backup/restore・DDL・production merge・Render/Auth0は**各別承認**が必要。本作業では実行しない。fixed release branch・main・productionを更新せず、最終 **NO-GO/HOLD**。

# B案 #791 / #792 統合前最終レビュー・別承認待ち

2026-10-19 JST。正本 [#488](https://github.com/1kz-ma1/Canovia-web/issues/488)。本票は後続Draftの差分・最終HEAD CI・Issue記録と併用する。main統合・候補採用・本番操作を承認しない。production **NO-GO / HOLD**。

## A：固定観測と差分の責任範囲

| 対象 | 固定SHA / 状態 |
| --- | --- |
| main / `release/2026-10-19-c09a8fc7` | `c09a8fc718c338f98a4045a4b5f2260c7571fdaa`。両ref一致。#790のmerge commit。 |
| production | `15abdc9de6f24b5250fb255c579d1aa55facaf1a`。refの観測だけでRender Liveの再確認ではない。 |
| #791 → main | `e6124170e4ab8f92709d7683544d999822a96581`、OPEN/Draft、MERGEABLE/CLEAN。固定HEAD全10チェックSUCCESS。 |
| #792 → #791 branch | `c3ecddacebab499ea6f9c1c454542edea6c3810e`、OPEN/Draft、MERGEABLE/CLEAN。固定HEAD全8チェックSUCCESS。e612は祖先。 |
| 本追加Draft → #792 branch | `fix/release20-integration-final-review`。HEADと当該CI URLはPR・#488に40桁で固定。上記既存HEADを変更しない。 |

#791単独は **c09→e612の17ファイル**（診断・意味比較・補助SELECT・参照・テスト・CI・操作票）。app/migration/auth/startup/lockfile変更なし。#792単独は **e612→c3の22ファイル**（guard2個、M01/M08/M18/M19、source由来absence契約、提案用参照、rehearsal/packet/test/CI）。累積c09→c3は **36ファイル**。重なる3パスはmigration workflow、diagnostic workflow、diagnostic rehearsalであり、後者は固定c09の回帰と提案ソースの回帰を分離する変更。#790は基点に含まれ、再適用/cherry-pickしない。36ファイルを#792だけの変更と記載しない。

main branch APIは`protected=false`、適用rulesは空。classic protection詳細APIは403で取得不可。required contextsはbranch応答で空、強制されるチェック/承認者が存在するとは扱わない。未解決review threadは#791/#792とも0（全ページ確認）。ownerレビューはCOMMENTEDで、今回のmerge GOではない。repositoryはmerge/squash/rebase許可、auto-merge無効。設定変更は実施しない。main統合の承認を与える場合は、本票のCIをoperatorが全件確認する条件を明示し、強制化するならownerの別作業とする。

productionは有効rulesetに削除/force-push禁止、PR・merge方式限定、5 required checks（Production promotion contract / workspace-resume / migration-recovery / P0 schema integrity MySQL8.0 /8.4）。これはmainの保護でもproduction承認でもない。最終操作時には設定・refを再取得し、観測差はSTOP。

## B：移行・セキュリティ最終レビュー

全20件の操作/down/依存は[既存監査表](transition-safety-review.md#a固定20件の監査)を再利用。今回の差分だけを追記する。

| 領域 | 結論 / 残る制限 |
| --- | --- |
| #791 strict/意味比較/collector | 原compare/SQL/hashは不変。補助比較は複合制約の順序保持の多重集合で名前だけを除外する。差分相殺・prefix/式/UNIQUE/可視性/参照/動作差を拒否。元BLOCKをPASS化しない。collectorは既定offline、TLS/最小権限/候補・tooling各pin/新たな明示承認が必要。今回実接続なし。 |
| M01 | 11table・旧tableへの7列・新Index名（table scope）・FK/trigger名（DB scope）を最初のDDL前に照合。metadata不可視性や20件の原子性を保証しない。 |
| M08 | 旧名の存在と定義を別検査。alias/重複/同名異定義を拒否しDROP直前も検査。旧UNIQUEが残る間に新UNIQUEを先に確保。整合prefixのみ再開。既存original identity/grant/audit行を消さない。data backfillは移行本来の処理であり、writer停止必須。 |
| M18 | 既存tableはpending upで常に拒否。旧「safe to retry」コメントを停止・operator審査という現在動作へ訂正。正常migrateは台帳skipであり、既存tableの自動採用ではない。 |
| M19 | 最初に両tableを検査。片方だけ存在してもDDL前拒否。競合CREATEはDBが拒否し、列だけの代替採用なし。 |
| M04→M05 | M04のmember trigger名をFOLLOWSで参照。順序変更禁止。欠損前提は失敗する。完全可視性/定義・実DDL権限/binlogは別の本番ゲート。 |
| **M20：追加の必須修正** | 旧コードはFK DELETEとrequired keyの存在だけを検査し、UPDATE変更・余分なUNIQUEを採用し得た。追加PHP負例2件が修正前に失敗。source通りのUPDATE NO ACTION/同一schema参照、FK1個・Index2個を要求。MySQLでは列順序/全6列の型・nullable/default/extra・生成式、文字列charset/collation、table engine/collation、Index全partのunique/prefix/式/方向/可視性/種別、観測可能なCHECK/trigger不在も確認。別名でも完全定義一致は許可し、名前を選んでDROP/修復しない。不可視triggerの不存在を証明する検査ではない。 |
| 再開/復旧 | M18/M19残骸・M03/M04/M20不完全状態は無変更で停止。M20の完成した整合状態のみ、定義確認後に再開。台帳手動編集なし。down/fresh/無条件rollbackを本番手順へ追加しない。 |
| 起動 | 累積差分でDocker/Render entrypointやstartup policy/認証routeは変更なし。既定checkは台帳の読取だけでpending/unknownを拒否、物理整合性やrelease承認の証明ではない。applyは実行SHAと40桁承認SHA一致で1回、失敗は自動retryなし。隔離staging専用の別retry分岐を本番許可へ流用しない。 |

全guardはpoint-in-timeで、metadata SELECTとDDLを原子的にしない。全writer/worker/queue/旧instance/他DDL actorの停止・可視性確認がなければNO-GO。旧writerの合成fenceテストは実稼働旧instanceの互換性/停止を証明しない。新しい公開機能・権限・OAuth・課金・DB migration本数の追加はない。内部移行安全性のみのためpublic roadmap更新は不要。

## C：新しいソースと検証範囲

本追加Draftでは未適用 **M20も変更**するため、累積変更migrationはM01/M08/M18/M19/M20の5件。旧94件と総数114は不変。M18コメントだけでもmigration blobが変わる。提案用CI参照の新sealは `a3494c1d1cf60546a86312f249f7d44b2ac52e44669752a65e1c70011ec9f91b`。最初の追加CIでは別名正例のfixtureがUPDATEをRESTRICTへ変更しており、既存の厳密な意味比較が拒否した。元のNO ACTIONを保持する正例へ修正し、RESTRICT自体も負例へ分離する。比較条件は緩めない。原strict/診断/意味参照は一切変更せず、提案参照の全物理hashは原版と同一。packetはこの5件だけを変更許可集合に固定する。旧4件の#792 Mac受入を新版へ流用しない。

新HEADでMySQL8.0/8.4の既存31ケースを再実行し、M20の完成DDL後・台帳前で停止させ、別名正例とUPDATE CASCADE/RESTRICT/余分UNIQUE/不可視UNIQUE/default差の負例、固定c09が誤UPDATEを採用する修正前controlを追加する（**各38ケース**）。台帳の手動改変で停止状態を作らない。既存94→114・全旧行保持・constraints/trigger/構造・二度目DDL0・合成隔離restore、固定c09診断29ケース/engine、関連PHP/Python、起動ポリシーも新HEADのCI対象。

新しいtest caseが必要なのは上記M20の実証された検査不足。M01/M08/M18/M19/M04→M05には既存の実DDL注入・負例があり、同じ内容のテストを重ねない。ブラウザ/UI/実OAuthには変更がなく、この変更の追加E2Eは不要。実機/実認証のリリース受入は別の未達ゲートのまま。最終実行件数・PASS/FAIL・run URL・新HEADはPRと#488の固定証跡を正本とする。

## D：推奨する統合順序（未実行・明示承認後のみ）

**#791 → #792 → 本追加Draft**を各merge commitで統合する案を推奨。通常のmain向けsquash方針からの例外としてownerが明示的に選択する。理由は以下の隔離Git履歴検証。repositoryのmerge許可は承認を意味しない。

- c09→e612→c3を一時bare object storeでシミュレーション。#791 merge後の#792 merge treeはc3のtreeと同一、固定e612は祖先のまま。
- 両方をsquashすると最終treeが同じでもe612祖先が失われ、現packetの`merge-base --is-ancestor e612 HEAD`を満たさない。
- #791 squash直後に#792を単にmainへ向け直すと、merge-baseはc09のままでPR三点比較は36ファイル（#792責任範囲は22）になる。重複コミット表示と差分の誤認が生じる。
- rebase/cherry-pickで差分を作り直すと新HEADとなる。祖先pinの設計変更・新レビュー・新CI・新Mac受入が必要であり、本票は無断rewriteを推奨しない。祖先検査を緩めてsquashを通さない。

承認時のoperator手順：

1. 直前に全refs/3 PRのHEAD・Draft・base・diff・threads・保護を再取得。不一致/競合/非成功CI/未承認ならSTOP。M18コメントとM20の修正を本追加Draftで受入れ、#792だけを本番候補へ進めないことを承認scopeへ含める。
2. #791の固定10チェックを確認し、ownerがDraft解除後、期待HEAD付きmerge commitでmainへ統合。実行例（**今回未実行**）：`gh pr merge 791 --merge --match-head-commit e6124170e4ab8f92709d7683544d999822a96581`。新main SHAを取得し、tree一致・e612祖先を検証。必要な承認/required checkを回避しない。
3. #792のbaseだけをmainへ向け直す（例：`gh api repos/1kz-ma1/Canovia-web/pulls/792 --method PATCH -f base=main`）。HEAD c3不変と単独22ファイルの責任差分を確認。既存8ジョブを全再実行し、current mainとのmerge treeがc3と一致することも検証する。base変更イベントだけで全workflowが走るとは仮定しない。既存runの再実行はhead証拠であり、新baseのmerge commit証拠ではない。必要ならownerがclose/reopen等で新PR eventを明示発生させる。書換・追加commitが必要ならSTOPして新HEADの全再受入。
4. Draft解除・別承認後、`gh pr merge 792 --merge --match-head-commit c3ecddacebab499ea6f9c1c454542edea6c3810e`。新main treeがc3、e612/c3が祖先であることを確認。
5. 本追加Draftのbaseをmainへ向け直し、承認済みの新40桁HEADが不変であることを確認。単独差分・merge tree・全8ジョブを再確認してから、同様に期待HEAD付きmerge commitで統合。**ここで初めて修正5件を含むmainになる**。一連の途中でrelease候補を切らない。
6. 各main merge SHAでsource inventory/startup/diagnostic/upgrade等の関連CIを再実行。#792と追加Draftの統合後は`gh workflow run release20-transition-safety.yml --ref main`で全8ジョブも明示実行（default mainにworkflowが入った後のみ）。runのheadShaが固定mainと一致しなければSTOP。mainが動けばそのSHAへ証拠を読み替えない。条件付きskip/neutralは必要検証の成功と扱わない。

現在のPR CIはheadをcheckoutするため、post-merge SHAの検証を代用しない。新しいmain SHAでもmigration全blobが同じならsealは同じ（commit IDはseal入力ではない）。コメント・rebase時の内容修正・conflict解決でblobが変わればseal再生成・提案参照再固定・当該CIとMac packet再受入が必要。原本番参照を上書きしない。e612祖先喪失はsealが同じでも別STOP。

## E：新候補採用の未達ゲート

| ゲート | 設計 | 合成CI | 実環境 | 承認 |
| --- | --- | --- | --- | --- |
| 統合source / SHA / seal / CI | 上記順序 | Draft最終HEADのみ確認対象 | 新main未作成 | **未承認** |
| strict BLOCKと名前差例外 | [独立審査案](transition-safety-review.md#d名前差の例外審査案未実装承認なし) | 自動waiverなし | 元BLOCK維持 | **未承認** |
| Index/FK意味・M08旧名 | source/定義を別検査 | 別名/誤定義を再現 | owner提出REAL_ACCEPTED、旧c09に限定 | 新候補には再審査 |
| 新設名衝突 / M20完成形 | scope別guard | 正負を再現 | **未検証** | **未承認** |
| 完全TRIGGER定義/順序/可視性 | M04→M05・別審査 | 観測/権限不足の合成例 | **未検証** | **未承認** |
| 実migration principal / binlog | 監査用最小権限と分離 | disposable限定 | **未検証** | **未承認** |
| backup/隔離restore/RPO/RTO | #488運用票を再利用 | 合成logical restoreのみ | **未検証** | **未承認** |
| writer/worker/queue/DDL actor | 停止・失敗時forward recovery | fence/中断・保持の合成例 | **未検証** | **未承認** |
| Web/iOS/実認証/主要操作 | 既存受入票を再利用 | 本DraftはUI変更なし | 新候補では**未検証** | **未承認** |
| 候補採用・production deploy | 各別owner GO | CI greenは承認でない | **未実行** | **未承認** |

新main統合後にも、旧c09専用collector/参照は新migration sealを拒否するのが正しい。提案packetはDB接続機能がなく、CI用新seal参照を渡して本番BLOCKを回避してはならない。新候補の採用とrelease ref作成には別承認が必要で、採用sourceに結び付く実監査tooling/参照sealの独立レビューも未完了。その後も実Aivenへの追加SELECT、backup/restore、DDL、production merge、Render/Auth0は各別承認。固定旧release branchは残す。

## F：現在の最小owner操作とSTOP

追加DraftのM18/M20・新CI・本票をレビューし、新しいclean checkoutで**新HEAD**のoffline packetを受入れる。#791/#792の既存checkout/refをresetしない。

```sh
python3 -B scripts/ci/release20_proposal_packet.py --proposal-sha <追加Draftの新40桁HEAD>
```

期待：`REVIEW_REQUIRED`、changed IDs=M01/M08/M18/M19/M20、old94/original audit unchanged、candidate adopted/strict waived/release authorized/real Aiven connectedすべてfalse。dirty/source/pin不一致はSTOP。旧c3のMac結果を再掲して済ませない。

そのうえでownerが承認する次のscopeは「3 PRを上記順序・merge commitでmainへ統合し、各SHAで再検証する」だけ。候補採用/本番接続・移行・公開の承認を含めない。本作業では全て未実行。最終reviewは、新HEAD CI成功・残存条件を明示した **REVIEW_REQUIRED / 統合準備完了、オーナー承認待ち**。チェック失敗やrefs不一致があれば **統合準備BLOCK**。productionは常に **NO-GO/HOLD**。

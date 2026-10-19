# #792 / #793：identity migration事前拒否のCI契約修正

2026-10-19 JST。正本 [#488](https://github.com/1kz-ma1/Canovia-web/issues/488)。この票は最新HEADのPR/CI証跡と併用する。mainの追加merge・候補採用・本番操作は未承認。production **NO-GO / HOLD**。

## 原因の確定

[#792失敗run 39192998135](https://github.com/1kz-ma1/Canovia-web/actions/runs/39192998135)はPostgreSQL17・MySQL8.0/8.4の全3ジョブで、`McpMigrationRollbackSafetyTest::test_replaying_identity_migration_cannot_make_two_live_owners_of_a_corrupt_legacy_subject`が失敗した。固定例外`RuntimeException: RELEASE20_IDENTITY_PREFLIGHT_BLOCK`を各ログで確認。旧テストはbackfill時のUNIQUE違反`QueryException`/SQLSTATEだけをcatchしていた。

使い捨て・label確認済み・loopbackのみのMySQL8.0で、旧テスト1件が同じ固定例外により失敗することを独立再現。M08は最初に`Release20IdentityMigrationGuard::assertCompatible()`を呼び、重複するlinked・未revokedのidentityをSELECTで検出してbackfill前に拒否する。検査順序と例外が旧期待より早いのが原因であり、ガードを削除する理由ではない。

## 修正と確認する不変条件

変更はテスト契約と本票のみ。アプリ・guard・migration・audit・referenceは変更しない。

- `RuntimeException`の**正確なclassと固定コード**を確認。`QueryException`を含む別例外は成功扱いにしない。広いcatchによる無条件合格なし。
- corruption fixture自身にgrant・auditも作り、全link/grant/audit/migration ledger行を順序付きdigestで前後照合。既存fixture行も含み、行や認証情報をassert出力へ渡さない。
- identityの全column/index/FK定義digestも前後照合。
- `beforeExecuting`でSQL試行を観測し、read以外の試行0を確認。成功したQueryExecutedだけで失敗したDML試行を見逃さない。
- 所有者/subject参照を保持し、有効fingerprintは元の1件だけ、他方はNULLのまま。2件のcorrupt linkを削除・再割当して「修復」しない。
- 正常なM08再実行、期限なしgrant、履歴再利用、危険なdown拒否は既存の正常テストを維持する。

local MySQLで関連2クラスは **6 passed / 58 assertions**。これはPostgreSQLや新HEADのCIを代用しない。新HEADごとにMCP Lockの3 DB matrix、Transition Safety、Migration Safety、Schema Diagnostics、P0、Recovery、Preflight、認証/MCP/Learning Context/Workspaceの必要ジョブを実行し、件数とrun URLはPR/#488へ固定記録する。以前の成功runを新HEADの証拠に転記しない。skip・neutral・cancelled・未実行は必要検証の成功ではない。

## 履歴・source seal・依存更新

- 現在mainはownerが#791をmergeした `d417b2593d3f54939f4c96b52481296533635ac5`。treeは#791固定e612と一致。旧main c09への差分として扱い直さない。
- #792は旧HEAD `c3ecddacebab499ea6f9c1c454542edea6c3810e` へ通常commitを追加する。baseはmain。履歴のrewrite/force-pushなし。
- 更新した#792 HEADを、#793旧HEAD `94667a628d04846134f66c68404005e673f3bf7e` へ**通常の2親merge commit**で取り込む。両方が祖先か、元のM20修正・M18コメント・既存guardが保持されているかを確認。#793のbaseは#792 branchのまま。
- 両新40桁SHAと親・差分は最終PR/#488記録で固定する。旧SHAへのownerの統合GOを新SHAへ流用しない。
- migration blobは本修正で全114件不変。旧94保持。#792のsealは `4aa5f72ba860f14f15ef106df6b87cf772cb35d5fc0e4e845b18c264eddeac21`、#793は `a3494c1d1cf60546a86312f249f7d44b2ac52e44669752a65e1c70011ec9f91b` を維持。両者の違いは従来のM18コメント/M20修正によるもの。reference再生成・strict比較緩和は不要で、実施しない。

## CI起動と停止条件

#792は通常pushに伴うPR synchronizeでmain向け全必要workflowを確認する。#793のstacked baseでは一部のmain向けworkflowが自動起動しないため、既存の`workflow_dispatch`を使用する。手動入口がない純粋テストworkflowは、必要なら#793側で入口のみ追加し、job・検証内容・permissions・既存PR/push条件を保持する。実deploy/環境設定/本番データへの入口を追加しない。GitHubが新HEAD実行を拒否した場合は迂回せず、必要なowner操作を記録して **INTEGRATION_BLOCKED** で停止する。PR close/reopenや一時的なbase変更は本作業で行わない。

## Mac再受入と統合再承認

両SHAが変わるため、migration sealが同じでも**Macのoffline packet再受入が必要**。旧受入checkoutを上書きせず、別のcleanな全履歴checkoutを各新40桁HEADへdetachする。

```sh
python3 -B scripts/ci/release20_proposal_packet.py --proposal-sha <新792の40桁HEAD>
# 別の新793 checkoutで:
python3 -B scripts/ci/release20_proposal_packet.py --proposal-sha <新793の40桁HEAD>
```

期待は`OFFLINE_PROPOSAL_PACKET_ONLY / REVIEW_REQUIRED`、old94/original audit unchanged。#792の変更IDはM01/M08/M18/M19、#793はそれにM20。candidate adopted/strict waived/release authorized/real Aiven connectedは全てfalse。dirty・pin・source・seal不一致はSTOP。

全必要CIが新HEADで成功し、差分/親/packetが一致すれば **INTEGRATION_READY_REVIEW_REQUIRED**。ownerは新両HEADのMac再受入後、#792→#793のmain merge commit統合だけを再承認する。未達CIがあれば **INTEGRATION_BLOCKED**。どちらも本番GOではない。

固定release `release/2026-10-19-c09a8fc7` /候補c09、production15abdc9、#791履歴を変更しない。実Aiven・Render・Auth0・実backup/restore・実ユーザーデータは対象外。元strict BLOCKと新候補/衝突/完全TRIGGER/DDL-binlog/復元/writer停止/実機の本番ゲートを維持する。

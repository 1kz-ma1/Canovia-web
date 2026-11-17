# Future Online Support — Laravel単一正本の優先方針

- ID: `FUTURE-SUPPORT-A-2026-11-17`
- 日付 / 決定者: 2026-11-17 JST / product owner（本タスクの明示指示）
- 決定状態: **ACCEPTED — 今後の優先アーキテクチャ方針のみ**
- Domain: Platform / Experience。製品採用・本番有効化・マージの承認なし。
- **SECURITY_BLOCKED / PRODUCTION_NO_GO_HOLD / release_approved=false**

## 決定と根拠

A案、既存Laravelの`roadmap_features` / `roadmap_votes`を単一正本とし、Native APIと既存Web Supportが同じ台帳を使う方針を優先する。独立したNative票台帳・候補マスター・集計正本を増設しない。匿名credentialの認証メタデータは票台帳とは区別する。

B案（別APIサービス・同じ正本）は追加の運用・secret・通信・transaction境界を持つため今回優先しない。C案（別backend/DB）は票・候補の分裂と移行を伴う。今後変更する場合も単一正本の移行設計・別の製品判断が必要。

期待する効果はWeb/Native間の集計整合性と運用重複の削減。費用削減額、ユーザー効果、本番可用性は**UNMEASURED / UNKNOWN**。匿名credentialは「世界で一人一票」の本人証明ではない。公開roadmapの編集用config、Social Cheer機能のruntime、ローカル診断・Planは別の責務のまま。

両PRはDraftを維持する。[Web #923](https://github.com/1kz-ma1/Canovia-web/pull/923) / [iOS #46](https://github.com/1kz-ma1/Canovia-iOS/pull/46)。SwiftUI画面の最終接続、既存Native MVPの変更、main merge、Render/Aiven/課金・支出上限・runner設定変更、本番DB/デプロイはこの承認に含まれない。

## 証拠の境界

初回再確認の固定SHAはWeb `3e8d4f05cb9857e3733bea6924171ea3e7b0bd2d`、iOS `a46d9d9e31cd53f057683b8a61894c2a1a0bd110`。mainはWeb `a84dda3a91d07ca32e7ffe0e1c9a4cac77733c5f` / iOS `fc92fba3c34e626c67b3abfb172f00cd260123df`。Web root AGENTSを確認、iOSにtracked AGENTSなし。

| 記録 | 判定 |
| --- | --- |
| [Actions 43810007997](https://github.com/1kz-ma1/Canovia-iOS/actions/runs/43810007997) | **FAILED BEFORE EXECUTION**。Mac/Linux両jobがrunner_id=0、steps=[]。check annotationはpayments失敗またはspending limit増額が必要との記載。どちらが該当するかはUNKNOWN。実装FAILでも最新ペアPASSでもない。環境要因として保持し、再実行・billing調査/変更なし |
| [以前の成功 43809199731](https://github.com/1kz-ma1/Canovia-iOS/actions/runs/43809199731) | iOS43ac814 + Web1d1e5662。歴史的成功であり、新しい組合せの代用にしない |
| 2026-11-17の既存Linux環境 | 上記a46d9d9 +3e8d4f0を実際に実行。Swift6.1.3単体14 Debug +14 optimized、実Laravel/SQLiteとSwift2 client・Python第3 client、12並行重複要求、lost-ack GET照合、4 PHP worker、server再起動保持が **PASS_SCOPED** |
| 実行方法 | 既存Swiftとキャッシュ済みPHP8.4.26 Docker imageを使用。新規worktree / owned一時SQLiteだけ。元integration.pyとPHP launcherは変更せず、一時adapter経由でPHPを実行。serverはhost127.0.0.1のみlisten。containerのcapabilitiesなし・read-only rootfs・非root、mountは新規Web worktreeと今回の一時rootだけ。Composer.lock一致の既存vendorをコピーし、既存Composer imageでnetworkなし・no-scripts/no-plugins install検証。DB/環境/launcherの拒否条件は維持 |
| Mac / Simulator / 実機 / HTTPS・ATS / 本番DB並行性 | 今回 **NOT_TESTED**。PHP containerやHTTP loopbackの成功で代用しない |

後続の資料更新後の固定HEADペアは、両PR本文で**実際に実行したSHAと結果**を記録する。API/client/launcher/workflow/source-lockの実装pinは変更しない。実装treeがbyte一致でも未実行のHEADペアをPASSにしない。GitHub Actionsの無意味な再試行を避けるため、資料更新commitは`[skip ci]`とする。ローカル実行をCI PASSとは呼ばない。

## 製品採用前の意思決定表 — 全項目OPEN

2026-11-17追加指示: ユーザーはiOS #46 `ceeb2449b7a227d5fa6e693a1d0758c389ce690f` + Web #923 `a7b2a1a32b07b78e7f67df46f060535dcdbf1b4f`の最新固定SHA Linux隔離統合を **PASS_SCOPEDとして受入済み**。共有投票・取消・重複要求（計12要求、最大6並行）・応答喪失時GET再照合・server再起動保持に限る。両PR本文の固定ペア証拠を参照。Mac/製品/本番の承認ではない。

機能実装は一区切りとし、新規実装・製品UI結合・main統合を一時保留する。[P01–P08製品判断パケット](../FUTURE_SUPPORT_PRODUCT_POLICY_OPTIONS_2026_11_17.md)に「現在の支持」を第一候補とする選択肢、データ移行、API変更、合成受入計画を整理した。**すべてPROPOSED / OPEN**であり、A案承認と区別する。特に「credential期限切れ後も票が残り本人が削除できない」状態は無条件に製品承認しない。将来案の試験はNOT_IMPLEMENTED / NOT_RUN。本追加は文書のみで、runtime/CI/本番設定を変更しない。

以下は現行実装の観測と選択肢。A案の承認は、ここにある案の自動承認ではない。製品責任者がprivacy/security/運用担当と決定し、API/Native契約・コピー・検証を合わせる。

| ID / 判断事項 | 現行実装・不足 | 決める内容 / 受入条件 |
| --- | --- | --- |
| P01 credentialの90日 | 発行時から90日の絶対期限。利用ごとの延長・更新・rotationなし。期限後はGET/Support/revokeとも401。Nativeはexpires_atを検証するがtokenだけ保持し、期限をStateに公開しない | 90日の採否、絶対期限/非利用期限、期限予告・再同意・更新/再発行、失敗/応答喪失時の扱い。期限を表示するならserver発行の期限を保持する契約が必要。端末時刻+90日で推測しない |
| P02 票の意味と保持 | 期限切れでも過去票は自動削除されず集計に残る。期限切れactorを再発行すると同じ人が再投票できる可能性 | 現在の有効なSupportか、過去の意思表明か。票/認証metadata/IP/access log/backupごとの保持期間、集計から外す時点、削除後のcountの意味と案内を決定。「90日で票も消える」と案内しない |
| P03 取消と削除 | 公開・投票可能な候補だけ個別取消可。停止/非公開候補の個別取消は拒否。認証可能なactorのrevokeは停止候補を含め自分の票を全削除しcredentialを失効させるが、hash/UUID/expires_at/revoked_atのmetadata rowは残る | 個別取消・全票削除・識別情報削除の区別、停止候補での撤回導線、metadata/backupの削除範囲と期限。現行revokeを「すべての情報を消去」と表現しない |
| P04 期限切れ/紛失後の削除 | expired credentialではrevokeも401。紛失したtokenの復旧もなく、残った票を本人が消す経路は未実装 | 自動期限削除、限定的な削除専用proof/grace、別の本人確認付き窓口など、権限を維持する回復方式を選ぶ。回復不能なら保持/案内と整合させる。client user_id/UUIDだけでは所有権を認めない |
| P05 再インストール/端末変更 | Keychain whenUnlockedThisDeviceOnly・非sync。再インストール後に残るかはOS依存で未検証。残れば同actor、紛失/別端末では復旧できない。自動置換なし | 再同意・残存credentialの再利用・明示reset・紛失時対応、同一人の多重票の説明。再インストールが消去/本人確認になると約束しない。実機のlocked/unlocked・更新・再install・移行試験が必要 |
| P06 アカウント統合 | Native actorとWeb guest/user actorは別。ユーザーIDによるNative認証・自動linkなし | anonymous credentialの保持証明と認証済みaccountの双方、明示preview/同意、account切替、重複票の集約/保持、count変化、旧credential失効を決定。transaction・冪等receipt・並行投票/revoke・中断の受入が必要。cookie/user_idの一致だけで統合しない |
| P07 削除応答喪失 / vault障害 | revoke応答喪失ではcredential保持。serverで失効済みでも再要求は401になり得て、削除完了の証明にならない。確認済みrevoke後のvault削除失敗はメモリidentity無効・再発行block。再起動を含むKeychain障害回復は未検証 | 冪等な削除確認/receipt、削除結果unknownの案内、local cleanupとserver削除の区別、再起動時回復。401を削除完了や新規actor発行許可に読み替えない |
| P08 乱用・費用・責任者 | 一credential/候補のunique制約とIP/actor rate limit。世界で一人一票ではなく、分散rate store/信頼proxy/運用監視は未監査 | 票の信頼性の約束、再発行/端末数/不正対策、費用予算・監視・incident/削除窓口の責任者とstop条件。AppAttest等を採る場合は別設計。課金・上限・runnerをこの作業で変更しない |

統合時に「最新の意思で上書き」する案は、票の不存在から最後の取消時刻を復元できるとは限らないため、履歴・証拠がないまま採らない。count、本人の状態、削除を異なる正本へ分裂させない。

## UI引継ぎと採用ゲート

[iOS #46](https://github.com/1kz-ma1/Canovia-iOS/pull/46)にpublic Repository/ViewModelの実在する署名・状態/error・async責務・copy可能な合成接続例を整理する。画面構成/画像配置/カルーセル/最終SwiftUI接続はUI担当の別作業であり、今回実装しない。現在ViewModelにrevoke entryはなく、期限もStateにない。未実装の削除/復旧/期限表示UIを提供済みとしない。

本番APIはdefault OFF、local/testing以外をコードで拒否する。productionはenv toggleだけでは有効化できない。将来は公開API境界、canonical DB/auth metadata migrationと復元、privacy/保持/削除、TLS/ATS/Keychain/実機、rate store/proxy/監視/費用、製品binaryの独立監査と別途承認が必要。現在の本番有効化・製品採用・main merge・Render/Aiven変更・デプロイは行わない。

再レビュー条件: P01–P08の決定、別環境/本番DB/実機の証拠、契約変更、独立audit、または正本分離を要する提案。現時点の評価は**PARTIALLY_SUPPORTED**（合成共有台帳/通信の整合性に限る）。本番安全性・利用者効果・運用費は未評価。公開カードは未採用・未deployの内部設計のため変更しない。

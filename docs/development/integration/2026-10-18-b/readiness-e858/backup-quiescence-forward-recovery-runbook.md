# e858：backup・writer停止・forward recovery運用計画

正本 [#488](https://github.com/1kz-ma1/Canovia-web/issues/488)、固定sourceと未達は[監査票](release-candidate-readiness-audit.md)。**未承認・未実行 / PRODUCTION_NO_GO_HOLD**。本票には本番DDL、接続、backup/restore、サービス停止の実行権限はない。使い捨てCIの修復SQLを本番へコピーしない。

## 実行前のowner/DBA決定欄

| 項目 | 必要な判断 / 現在 |
| --- | --- |
| 現場責任者・代行・連絡/停止権限 | owner/DBAとincident担当を指名。未確定 |
| RPO / RTO・停止window | 許容データ損失と復旧所要の数値、計測開始/終了をownerが承認。**未確定、CI秒数から発明しない** |
| backup方式・時点 | 現契約でのsnapshot/PITR/logical backupの可用性、時点整合・暗号化・権限・保持期間を確認。実方式未確認 |
| 隔離restore先 | 本番とは異なる承認済対象、ネットワーク/認証/鍵/実データアクセスの分離。未確定。有料fork/新resourceが必要なら費用を先に別承認 |
| 復旧code/秘密/ファイル | 旧稼働SHA、採用source、APP_KEY/session/cookie、暗号化ファイル/外部storageとの時点整合。秘密の公開保存禁止 |
| 監査/DDL/復旧principal | 3者の用途と権限を分離。実効権限・binlog・TRIGGER policy未確認。監査userを昇格しない |
| 事後廃棄 | 認可者、restore後保持期限、鍵/DB/一時媒体の廃棄・課金停止証拠。未確定 |

原workflowにはFree環境の未検証restoreリスク受容という選択肢もあるが、**今回の実復元ゲートをその文言だけで満たさない**。リスク受容への変更はownerの別判断・仕様変更であり、Codexは未検証をPASSへ変えない。

## バックアップと隔離restoreの最短チェック

1. 個別承認後、DBAが対象binding/backup方式/時点・保持期限とrestore先の隔離を私的照合。現契約の復元可能性・必要費用・最大停止時間が不明ならSTOP。
2. backupを実取得/確認する承認と、隔離restoreの承認を分ける。provider完了表示だけでなく、**そのbackupから実際に復元**する。実ユーザーデータを含むため実行者/アクセス者を限定し、Codex/CIへdumpを渡さない。
3. restore環境で外部メール/AI/OAuth/worker/scheduler/Storage write等を隔離し、誤送信と本番接続を防ぐ。APP_KEY等は安全な既存secret管理で参照し、公開artifactへコピーしない。隔離性を証明できなければ起動しない。
4. 認可DBAが全tableの行数・必要な順序付きdigest、ledger全件/batch、全column/Index/FK/UNIQUE/check/triggerの定義・順序・可視性を照合。backup時点と比較し、期待source形状とは別に記録。実値はprivate、公開は固定分類・集計だけ。
5. 隔離した復旧codeのstartup `check`、login/session/CSRF、暗号化値/ファイル復号、本人権限・取消/監査・Plan/Task/Attemptの対応を認可担当が検証。個人情報のサンプルを公開しない。旧94backupに新114codeをそのまま起動してpendingを無視しない。
6. 計測RPO/RTOをowner基準と比較。不足ならNO-GO。完了後の廃棄/保持・課金終了も確認し、backup時点/source/binding/結果/担当/時刻だけを証跡化する。

CIの合成logical restore成功は方式のテストだけで、Aiven backup、実鍵/ファイル、RPO/RTOの証明ではない。移行直前に使うbackupの時点が受入したbackupと異なる場合、その復元経路・整合性と有効性を再確認する。

## writer / DDL actor停止の網羅表（まだ停止しない）

| 主体 | 後日の停止・fence方法案 | 必要な停止/残留証拠 |
| --- | --- | --- |
| Render Web全instance・旧deploy | ownerが承認したmaintenance/ingress遮断に加え、実instanceをdrain/停止。閲覧GETがsessionを書き得る点にも注意 | providerの全instance/旧image一覧、request終了、DB接続/txのDBA確認。maintenanceページだけでは不十分 |
| queue worker / retry / delayed jobs | 新規enqueueとproducerを止め、処理中jobを安全にdrain後、全consumer停止。queue:restartだけは自動再起動し得る | active lease/処理中job0、全worker停止、retry/delay処理の不在。job/queueを消して通さない |
| scheduler / cron / Render cron | 承認されたschedule停止・自動再起動抑止 | 定義と実行中プロセス、lock/lease、次回自動実行が起きない証拠 |
| background/外部writer | webhook、別app/バッチ、admin手動処理、古いinstance、外部連携を責任者に列挙・停止 | 主体別停止と権限/fence証拠。把握できないwriterが残ればNO-GO |
| 他DDL / migration actor | CI/deploy hook、手動artisan、別operatorを凍結、実行者1名へ限定 | 同時DDLなし、承認済み単独実行leaseと失効/復旧手順。Laravel migrationだけに排他を期待しない |
| 自動deploy / autoscale / restart | ownerが各automationを確認し意図しない旧writer復活を防ぐ | Auto Deploy off、再起動設定、旧image再接続防止。設定変更は別承認 |

停止順はproducer/自動起動→ingress/drain→consumer/scheduler/外部actor→DBAの残留session/transaction/metadata lock確認→単独migration lease取得。監査userへPROCESS/admin権限を追加せず、既存認可DBAが私的確認する。生processlist/query textを公開しない。

M08は旧global UNIQUEを外してactive UNIQUEへ移行する。旧バイナリが新列/履歴を理解せず書く可能性があるため、**新旧writerを混在させない**。個別承認したcredential/fence等で旧writer復活を防げなければSTOP。db permissionを自動変更する手順は追加しない。point-in-time guardや二度目DDL0の合成証拠は、この実停止を代用しない。

## 移行失敗時の状態別判断（本番SQLは別設計）

| 観測 | 即時対応 / 再開条件 |
| --- | --- |
| backup未完了/restore失敗/停止不能 | migrationを始めない。旧稼働への復帰もschema未変更を確認しowner判断 |
| 最初のDDL前の固定guard拒否 | writer fenceを維持し、DBAがledger/物理/行保持を私的確認。原因・固定source・再実行可否を審査するまでretryしない |
| M03/M04等の部分DDL、M18/M19残骸 | 台帳と物理の差を保存し、未記録CREATEを採用しない。正確な状態に対する別forward-repair PR/SQLと使い捨て再現・owner GOが必要 |
| M08の列/UNIQUE/DROP/history途中 | guardが認める整合prefixでも、実owner/データ/actor状態の確認なしに再開しない。alias/余剰/重複/誤定義はDROPせずBLOCK |
| M20のCREATE直後/台帳直前 | 完成した厳密形状だけがsource上の再開対象。不足FK/UNIQUE、余剰/不可視UNIQUE、UPDATE/DELETE/default等の差はSTOP |
| 接続断・timeout・結果不明 | DDLがcommit済みの可能性を扱う。全migration一括retryなし。operatorが実状態を確定するまでwriter停止 |
| ledger114だが物理/trigger不一致 | trafficを開けない。台帳を削除/追加して整合させない。原因別forward repairまたは整合backup+code復旧を別承認 |

MySQL DDLは非トランザクショナルなcommit境界を持つ。`migrate:fresh`、無条件rollback/down、ledger手動編集、データ削除による再実行は本番手順に含めない。ソースのM08 backfill等は承認された移行処理であり、metadata-only承認には含まれない。

復旧責任者は、実測状態を合成fixtureへ最小限・匿名で再現し、全旧行/認証/同意/監査と物理整合性を証明するforward planを審査する。backup復元を選ぶならsource/鍵/ファイル/ledgerの同時点整合と、backup以降の正当な更新を失うリスクをownerが別承認する。**古いimageへのrollbackだけでschemaと認可が戻るとは扱わない。**

## 再開・公開までの順序

1. DBAがledger全114と物理・trigger完全性、row/FK/UNIQUE整合、旧データ/鍵/同意保持、単独actorを確認。名前差strict BLOCKが残れば本番再開不可。明示的に別審査された対応なしに開けない。
2. 実際のruntime40桁SHAとDDL承認SHA、tree/sealを再照合。startupは通常`check`へ戻す運用を**別承認**し、applyを再起動のたびに走らせない。旧image再起動を防止する。
3. production merge/Render specific-SHA deployはそれぞれowner別GO。設定保存自体がdeployを誘発し得るため、無承認で保存しない。Auto Deploy OFFを維持する。
4. 認可済みの限定trafficで新runtimeのhealth、実Web/session/iPhone/学習/Developmentと、必要なら別承認のAuth0/本人overviewを受入。その後ownerがwriter/consumer/scheduler/外部連携を一群ずつ再開し、エラー・lease・新旧混在なしを確認する。
5. 全体再開・L0/L1実効公開・AP個別同意・一般公開/課金は同じGOにまとめない。どの段階でも不明/失敗なら再開を止めincident責任者へ戻す。

費用/RPO/RTO/停止方法/責任者の未記入を「準備済み」と扱わず、[承認表](production-gates-and-approvals.md)のUNVERIFIEDを保持する。

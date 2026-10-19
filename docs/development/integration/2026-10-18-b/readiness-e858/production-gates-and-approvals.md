# e858：Production Readinessのゲート・個別承認

2026-10-19 UTC / 正本 [#488](https://github.com/1kz-ma1/Canovia-web/issues/488)。対象は[監査票](release-candidate-readiness-audit.md)の固定main。**PRODUCTION_NO_GO_HOLD**。PASSは記載範囲内だけ、UNVERIFIEDは運用上BLOCK。下表の承認はすべて未取得であり、文書作成やCIが承認を代行しない。

| 優先度・ゲート | 現在 | 次の証拠・判断者 / STOP |
| --- | --- | --- |
| P0 Source・親/tree・旧94/114・seal | PASS（固定e858） | ownerが[固定結果](source-audit.json)を審査。ref/集合/blob/seal/祖先差で失効 |
| P0 指定合成CI | PASS、3本13 jobs | [全run](fixed-main-ci-evidence.json)。対象HEAD違い、必須fail/skip/neutral/未実施はSTOP |
| P0 新candidate用監査tooling/reference | UNVERIFIED / 未実装 | 別準備PRで候補SHAとtooling SHAを別固定、正負CI・Mac offline受入。旧collectorのBLOCKを解除しない |
| P0 候補採用・新release pin | 未承認/BLOCK | ownerが40桁source・seal・scope・未達を承認。新ref作成は別操作、moving main/旧release更新禁止 |
| P0 INDEX/FK | 旧c09実測NAME_ONLY、strict BLOCK | 認可operatorによる新対象の多重集合定義/衝突の別実測。名前一致だけ・同件数相殺・UNIQUE余剰/欠落は不可 |
| P0 M08旧名・定義 | 旧c09でEXPECTEDの限定実測 | 新監査で旧名と定義を再確認。別名/曖昧/重複/旧writer生存はSTOP |
| P0 新設名衝突 | UNVERIFIED | 11 table/7列/13 FK/3 triggerと新Indexをsource契約に照合。Indexはtable scope、FK/triggerはDB scope。不可視は不存在証明でない |
| P0 完全TRIGGER / M04→M05 | UNVERIFIED | 既存認可DBAが対象/時刻/完全可視性を裏付け、定義/順序を照合。監査userの権限拡張で解決しない |
| P0 migration principal / binlog | UNVERIFIED | 実行予定principal・実効DDL/TRIGGER権限、managed binlog policy、主体分離をDBAが審査。REFERENCES監査userでmigrationしない |
| P0 実データ重複・部分DDL | UNVERIFIED | metadata計画で個人行を読まない。後日の個別承認されたoperator事前検査/guard拒否・復旧条件が必要。事前BLOCKも移行全体の原子性ではない |
| P0 backup / 隔離restore / RPO・RTO | UNVERIFIED | [復旧票](backup-quiescence-forward-recovery-runbook.md)。ownerが数値/費用/責任者を先に決め、実復元を検証。snapshot存在や合成restoreのみは不可 |
| P0 writer/worker/他DDL actor停止 | UNVERIFIED | 網羅一覧・停止/残留証拠・単独実行lease。ひとつでも不明/停止不能ならNO-GO |
| P0 Web / session / 旧user互換 | UNVERIFIED（新候補の実受入） | 既存Chrome合成session/E2Eは参考。実desktop login/callback/logout/renewal/CSRF/既存Plan・学習/開発を固定runtimeで確認 |
| P0 iOS WKWebView / Safari/PWA | UNVERIFIED | 実iPhoneで認証持続・戻る/再開・Plan/学習。LinuxのUA偽装やheadlessは代用不可。別nativeリリースならXcode/App Iconも別ゲート |
| P0 Auth0 / MCP / AP本人 | UNVERIFIED（本番） | 独立tenant/2client、issuer/aud/client/primary subject/PKCE、本人Planの同意と取消。下記段階別、実行には別承認 |
| P0 公開範囲 | UNVERIFIED（実効設定） | 一般L0＋明示本人L1は推奨案。L0/L1はMCP grantを失効させない。新AI/Watch/G3/write/課金はOFF、設定変更は別承認 |
| P0 Render / production保護 | 既存観測のみ、現在UNVERIFIED | ownerがLive/branch/autoDeploy off、runtime SHA/env、required checksを操作前に再確認。予期しないdeploy/Auto Deploy ONはSTOP |
| P1 その他の既存PHP失敗/警告 | 未解決 | [従来baseline](../../2026-10-16/review-packet/existing-failure-index.json)を保持。新固定sourceで再現/非再現を判別するまで解消扱いしない。認証/保存/権限/移行に関わる失敗と判明したらP0へ上げる |

## 機能受入の最小差分

ソースは[AP本人連携受入仕様](../../../MCP_AP_MAIN_B_OWNER_READ_ACCEPTANCE_2026_10_18.md)、[Staging実OAuth記録](../../../MCP_CHATGPT_STAGING_REAL_OAUTH_READ_REVOCATION_ACCEPTANCE_2026_10_14.md)、[既存review packet](../../2026-10-16/review-packet/README.md)を再利用。各票の古い「Draft」「main SHA」「起動migrate」記述は当時の状態で、新e858の状態は本監査票で上書きせず別記する。

| 影響するフロー | 手元にある証拠 / まだ必要な実受入 |
| --- | --- |
| login/OAuth/callback/logout/renewal | auth/session設定blob保持や合成CSRF/sessionの過去証拠。実APP_KEY/cookie/store、native持続、実production callbackは未受入 |
| MCP link/grant/revoke/relink | #792/#793時点の実DBロックmatrixと現在のM08ガード。旧JWTと取消済grant/subjectが再接続で復活しないことを実tokenで確認する必要あり |
| AP overview | 最初は本人のprivate/non-collaborative Plan1件・overview同意だけ。別人/別Plan/未同意/期限切れ/取消後は拒否、tasks空。実Plan readは今はしない |
| tasks→summary→context snapshot | それぞれ別承認・別同意。最大8 Task、承認済集計、許可されたSnapshotに限定。summary/context/提案writeは最初OFF |
| Plan作成/閲覧/学習/Development | 過去の使い捨てChromiumと履歴保持証拠を参照。固定新runtimeの既存user/Task/Attempt/再開、権限拒否を実desktop/iPhoneで受入。スコア・履歴を修復名目で書換しない |

Stagingの実OAuth成功は**合成Development fixture限定**で、本番AP本人の証拠ではない。Auth0 logout/refresh token取消だけでは既発行JWTを即時無効にする保証がない（最大3600秒のJWT境界）。Canovia grant取消/unlink/tools OFF後の既存bearer拒否を別確認する。既にChatGPTへ渡った情報は取消で回収できない。

## 承認の順序と最初の依頼

1. **今、最初にownerへ依頼するscope：固定e858を監査候補として正式採用し、新release pinを作ることの審査・個別承認。** 操作対象案は `release/2026-10-19-e858b9c8` を上記40桁SHAへ新設することだけ。今回は作成しない。旧release・main・productionを動かさず、**実行SQLは0件**。不足を受け入れて本番適合性まで承認する意味ではない。新監査セットのoffline準備/審査が未完了ならpin後も実接続はSTOP。ownerが採用を保留するなら全てHOLD。
2. 新source-bound監査セットの固定tooling SHA・SQL blob・reference・Mac offline結果をownerが別受入。その後だけ、[metadata-only実測](real-aiven-readonly-diagnostic-plan.md)の1回・有効時間・既存対象/主体を明示して承認。現PRにはその実行可能toolingはない。
3. DBAの権限/TRIGGER確認、実backup/隔離restore（費用含む）、writer停止・復旧演習、実migrationを**それぞれ別scope**で承認。SQL/サービス停止の包括承認にしない。
4. 実migration GOとproduction merge GOとRender deploy GOは別判断。production merge commitのruntime SHAも別固定し、候補sourceとmigration seal/実行承認SHAを再照合する。
5. Web/iOS受入と、本番Auth0設定・本人overview readの各GOを別取得。一般公開・課金・writeへの拡張は含めない。

全段階で操作者・確認時刻・対象binding・固定source/tooling・証拠と明示scopeをprivate運用記録に残す。認可operator以外がJSONを編集して判定昇格しても効力はない。実環境操作前に権限/対象/承認のいずれかが不明ならSTOP。今回のユーザー指示が本番操作を禁止しているため、以上は**オーナー向け承認案**であって実行依頼の自動承認ではない。

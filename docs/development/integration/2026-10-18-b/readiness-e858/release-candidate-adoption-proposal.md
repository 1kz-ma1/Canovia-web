# e858：新候補採用の提案（未採用）

正本 [#488](https://github.com/1kz-ma1/Canovia-web/issues/488)。[監査票](release-candidate-readiness-audit.md)のsource/CI PASSは採用許可ではない。旧release c09とproduction15abdc9を維持し、**PRODUCTION_NO_GO_HOLD**。

## 採用対象とfreeze

提案sourceは **`e858b9c855970fbe73f37a2d60c9db6525b132d6`**、tree `f6990e8bcf7904be2fdc2706d6a525a29ccef9ee`、migration seal `a3494c1d1cf60546a86312f249f7d44b2ac52e44669752a65e1c70011ec9f91b`。[source/114 manifest](source-audit.json)と[指定CI](fixed-main-ci-evidence.json)を同時に審査する。

ownerが採用を個別承認した場合のrelease名案は `release/2026-10-19-e858b9c8`。**このPRではrefを作成せず、旧releaseを更新しない。** 採用は「今後この固定対象だけを監査する」という候補宣言であり、本番適合性・DB接続・移行・公開のGOではない。[workflow](../../../../CANOVIA_DEVELOPMENT_WORKFLOW.md#controlled-production-promotion-and-ios-web-delivery-approved-2026-10-13)に従い、実監査の前にreview済みmain祖先をrelease pinする。

今回作る文書PRのHEADはe858の子でも**新候補として自動採用しない**。採用予定sourceを変更する場合、差分・source/CI/tooling/Macの審査をやり直す。同sealは新commitのCI成功を代用しない。

## 新source-bound監査セット：次の独立準備PRの条件

現collectorとdiagnostic collectorは旧c09専用で、新mainをoffline拒否するのが正しい。原本を書き換えず、環境変数で無理に通さず、CI用proposed referenceを本番既定参照へコピーしない。

必要な別準備PRでは次を満たす。**本票にはこのadapter実装や実接続コマンドは含めない。**

- **候補source SHAと実行tooling SHAを別引数/別承認で固定**。candidateはowner採用release refと一致しmain祖先、toolingは別のclean/full-history checkoutと保護対象blobで検証。dirty/shallow/差替symlink/ignored上書き/別環境の資格情報overrideを拒否。
- 新ディレクトリに候補source SHA・migration manifest/seal・engine・正規化version・SQL/collector/parser/guard blobを結び付けたreferenceを用意。旧c09原参照/厳密hashはそのまま保管する。単なるseal文字列置換は禁止。
- 固定e858から94→114を両MySQLで再構築して参照を生成・再現照合し、旧94と全pending対象を監査。現在のproposed referenceの物理hash一致は設計根拠になるが、実監査への転用承認にはならない。
- strict物理照合は名前も含めた従来契約を維持。semanticはグループ化したIndex/FK多重集合の補助診断。name-onlyでも元strictはBLOCKのまま。手動PASS/waiverフラグ/APIは実装しない。
- schema identityは私的なDB/service/TLS bindingで照合し、referenceはsource由来構造を証明する。どちらも他方の代用ではない。原データから期待referenceを生成して差を消す行為は禁止。
- 新設名衝突、M08期待名/定義、完全TRIGGER可視性、実principal/binlogを独立項目にする。metadata-onlyで実データ重複/既存identity内容を証明しない。
- [実測計画](real-aiven-readonly-diagnostic-plan.md)のSQL allowlist、時間/行数/bytes制限、固定出力・private削除、CI実接続拒否、改変/切詰め/不正scope負例を検証。旧の60秒timeout/事後bytes検査だけで新計画を実装済みとしない。
- 正負CIとMacの新tooling SHA offline受入を行い、採用/release許可/実接続falseを確認してから、ownerが別途1回のmetadata readを判断する。

## strict名前差の審査をどう扱うか

旧c09のstrict BLOCKと旧semantic名前差は別の証拠として保存。新対象でもstrict BLOCKが続くなら、そのままNO-GO。実DBごとの同時点のstrict結果、全定義のsemantic一致、M08依存名一致、衝突なし、完全可視性、backup/復旧/writer/実機を揃えて初めて**認可DBAとownerの人間審査に提出できる**。

この条件を揃えることもBLOCK解除ではない。将来の例外採用や正当な互換性対応は、別設計・別承認・別PRが必要。自由記述の「名前差のみ」やoperator自己申告、CI greenだけで厳密判定やproduction保護を迂回しない。

## 個別GOの分離

| 判断 | 判定者・前提 | 含まないこと |
| --- | --- | --- |
| 候補採用GO | owner、固定source/seal/差分/指定CIの受入、未達ゲートの明記。新release pinのみ別操作 | DB接続、DDL、復元、Render、Auth0、公開 |
| 実Aiven metadata read GO | owner＋認可operator、採用済release/new tooling/new referenceのoffline受入、私的対象binding、期限付き1回 | 実データ、追加GRANT、TRIGGER権限拡張、strict waiver |
| backup/隔離restore・writer停止 GO | owner＋DBA/復旧責任者、費用/RPO/RTO/対象/廃棄/leaseの承認 | 実migrationとdeployの自動承認 |
| 実migration GO | owner＋DBA、全P0 evidence、actor停止、復元済backup、失敗時復旧案、実行runtime40桁SHA | production昇格・公開・機能flag変更 |
| production merge / Render deploy GO | owner、固定release、保護/5必須check、merge tree/新runtime SHA、DDL/source一致、Auto Deploy OFF | 未完了実機受入の完了認定、一般公開、Auth0変更 |
| 限定機能受入GO | owner、実Web/iPhone、独立Auth0/本人同意・取消検証を段階実施 | tasks/summary/context/write/一般公開の一括許可 |

actual runtimeがproduction merge SHAになる場合、release SHAと文字列が異なる。tree・migration blob/sealを照合して、**実際にapplyするruntime SHA**を別承認する。`CANOVIA_APPROVED_MIGRATION_SHA`へ候補SHAを機械的に流用しない。どの段階もsource/ref/環境/権限/証拠が変われば差し戻す。

# 統合main e858：リリース候補適格性の独立監査

2026-10-19 UTC。正本は [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488)。本票は候補採用・実接続・本番操作の承認ではない。作成PRのHEADと、以下の**監査対象SHA**を混同しない。過去の親資料は当時の固定観測として保存し、本票を現在の差分審査入口とする。

| 独立した判定軸 | 結果 |
| --- | --- |
| Source / 指定合成CI | **SOURCE_AND_SYNTHETIC_CI_VERIFIED** |
| Production Readiness | **READINESS_REVIEW_REQUIRED**：実環境ゲート未達 |
| Production | **PRODUCTION_NO_GO_HOLD** |

## 固定ソースと再計算

| 対象 | 40桁SHA / 今回の観測範囲 |
| --- | --- |
| 監査対象・現在main | `e858b9c855970fbe73f37a2d60c9db6525b132d6`。Gitリモートrefと一致 |
| 旧release/2026-10-19-c09a8fc7 | `c09a8fc718c338f98a4045a4b5f2260c7571fdaa`。未変更 |
| production | `15abdc9de6f24b5250fb255c579d1aa55facaf1a`。Git refのみ再確認 |
| Render Live / Auto Deploy | #488の2026-10-19既存観測：旧15abdc9、no/off。**今回再照会なし、現時点はUNVERIFIED**。変更が判明したらSTOP |

Codexがclean・full-history・固定SHAのcheckoutで独立照合した詳細は [source-audit.json](source-audit.json)、[全114件のmanifest](migration-manifest-114.tsv)。時刻はJSONのUTCフィールドを参照。

| Merge | 第1親 | 第2親（PR最終HEAD） | tree照合 |
| --- | --- | --- | --- |
| #791 d417b259 | c09a8fc7 | e6124170 | 一致 |
| #792 36159871 | d417b259 | 5099ed01 | 一致 |
| #793 e858b9c8 | 36159871 | 8a64d05d | 一致 |

全40桁親とtreeはJSONに固定。全114件は#793最終HEADと同一blob。productionの旧94件も全件不変。c09から変わったpendingは **M01/M08/M18/M19/M20**、追加本数は20のまま。production→固定mainの全差分は1020パスで、既存979行manifestは当時の在庫のまま保存する。再生成方法は下記。パス分類は審査キューであり安全認定ではない。

sealは採用済み値の転記ではなく、`git ls-tree -r <SHA> database/migrations`から全PHPファイルの**拡張子なしbasename→Git blob ID**辞書を作り、`hashlib.sha256(json.dumps(mapping, sort_keys=True).encode()).hexdigest()`で独立再計算した。Python標準のJSON区切り（空白あり）、UTF-8、末尾改行なしが入力。94件の集合/同blob、114件の集合、差分20件を先に確認する。

結果：`a3494c1d1cf60546a86312f249f7d44b2ac52e44669752a65e1c70011ec9f91b`。期待sealと一致。sealはmigration内容だけを封印し、アプリ・helper・起動・SQL・reference・CI・対象DBや承認を封印しない。SHA→tree→manifest→sealと、別途のprotected blob/CI/run HEADを併せて結び付ける。ソースSHAが変われば同sealでもソース/CI審査は再実施。migration変更、集合変更、祖先喪失、reference/SQL/helper変更、dirty/shallowは対応証拠を失効させる。

```sh
# 別のclean full-history checkoutを上記e858の40桁SHAへdetachした状態だけで実施
python3 -B scripts/ci/release20_proposal_packet.py --proposal-sha e858b9c855970fbe73f37a2d60c9db6525b132d6
python3 -B scripts/ci/integration_freeze_manifest.py --base 15abdc9de6f24b5250fb255c579d1aa55facaf1a --candidate e858b9c855970fbe73f37a2d60c9db6525b132d6
```

packetは実行済み：REVIEW_REQUIRED、old94/original audit unchanged、候補採用/strict waiver/release承認/実Aiven接続/DB変更false。後者の完全在庫も生成・件数照合済みで、重複した1020行の本文は掲載しない。

## 指定CIの独立照合

検証主体：CodexによるGitHub読み取り。実行主体：GitHub Actions、ownerのworkflow_dispatch。各runは**固定e858 HEAD**、全job completed/success、fail/skip/neutral/cancelを成功算入していない。[機械可読の時刻・job証跡](fixed-main-ci-evidence.json)。

| Workflow / 2026-10-19 UTC開始〜完了 | 結果 |
| --- | --- |
| [Schema Diagnostics 39201947065](https://github.com/1kz-ma1/Canovia-web/actions/runs/39201947065) 04:56:01〜04:57:28 | 2/2成功。MySQL8.0/8.4の旧c09診断回帰各29ケース。**検査toolingはe858、fixture候補はc09** |
| [Migration Safety 39201955575](https://github.com/1kz-ma1/Canovia-web/actions/runs/39201955575) 04:56:07〜04:57:26 | 3/3成功。固定e858の94→114、旧行/物理形状/trigger保持、中断・復旧、再実行、合成隔離restore |
| [Transition Safety 39201963912](https://github.com/1kz-ma1/Canovia-web/actions/runs/39201963912) 04:56:13〜04:59:45 | 8/8成功。MySQL8.0.46/8.4.11各38ケース、関連PHP39/331 assertions、Python32+4。再利用jobを含む |

合計3 workflow/13 jobs。重複するreusable jobの同内容を独立した追加coverageと数えない。startup側はPHP29/360 assertions＋5/8、Python32。旧PR HEADでのMCP PostgreSQL17/MySQL8.0/8.4各6ケース/58 assertionsとPHPUnit警告は[過去証拠](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6259521602)であり、e858の新規実行としない。固定mainの指定3本に新たな失敗はない。全PHP既存失敗の解消、全browser/実機/実Auth0検証の完了は主張しない。

## 証拠4層と移行coverage

| 層・検証主体・対象 | 状態 / 根拠 / 限界 |
| --- | --- |
| Source：Codex、固定e858、JSON記載UTC | PASS：上記Git/manifest/seal、guardと元strict監査の保持。実DBへの適合は別 |
| Synthetic CI：Actions、固定e858、上記時刻/URL | PASS：両MySQL、各38ケース。正常形、合成別名、衝突、部分DDL、旧行保持、二度目DDL0。実Aivenの実名再現ではない |
| Existing Real：owner、旧c09＋tooling dbe3、2026-10-18 22:19:37Z記録 | [物理診断の元証拠](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6255859198)：94 ledger、91 table/1154 column、check/triggerは観測0/0。Index604/FK181行でidentity不一致。strict BLOCK。同一JSONの二重貼付を独立2回の証拠に数えない |
| Existing Real：owner、旧c09＋tooling e612、2026-10-19 00:25:05Z記録 | [意味診断の元証拠](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6257050070)：INDEX450制約/604行・FK181/181は名前差、M08期待名/定義観測あり。strict BLOCK。完全trigger可視性未確認。両記録とも実行の正確な時刻/現在性は未確定で、新mainへ再認定しない |
| Missing Real：新候補/e858、未実行 | UNVERIFIED：新設名衝突、完全TRIGGER、実DDL/binlog、data重複、backup/restore/RPO/RTO、writer停止、Web/iOS/Auth0/本人Plan。source/CIで補わない |

| 変更 | 固定e858にある契約 / 正負の証拠 | 本番の未確認前提 |
| --- | --- | --- |
| M01 | 最初のDDL前に新table/列、table-scope Index名・DB-scope FK/trigger名を検査。case衝突も38ケースで無変更拒否 | 完全metadata可視性、検査後の他DDL不在 |
| M08 | 旧UNIQUEの名前と全定義、active重複を検査。整合した停止prefixのみ再開。grant/audit/link保持、alias/重複/prefix/不可視を拒否 | 旧c09の旧名一致は参考のみ。新監査時の定義/データ重複、旧writer停止。許可されたmetadata-onlyでは重複実データを読まない |
| M18 | pending upの既存CREATEを自動採用しない。固定c09の不完全採用controlと、修正後の行/構造/ledger保持BLOCK | 残骸の実在・復旧状態 |
| M19 | 両tableの存在をDDL前に検査、片方だけでも拒否。部分CREATEと定義不足の負例 | 実DDL actor停止、未知の残骸 |
| M20 | 完成形だけ再開。列/Index/FK数・順序・物理定義・UPDATE/DELETEを検査。余剰UNIQUE/不可視/default/動作差を拒否 | 完全trigger可視性、未知の実データ/物理状態 |
| M04→M05 | M04 member trigger名へのFOLLOWS依存。欠落前提・権限不足を合成で拒否、旧writer epoch/fenceも検証 | 実TRIGGER権限/binlog、全定義と実行順序 |

全20件の操作/down/依存は[既存監査表](../transition-safety-review.md#a固定20件の監査)を再利用する。負例はDDL後停止を含むが任意の全SQL境界の網羅ではない。Laravel ledger一致と物理DDL一致を別に確認し、114 ledgerだけで合格にしない。guardはpoint-in-timeでありDDLを原子的にしない。

## reference・起動・残るcritical path

元strict audit/SQL/grants guard/referenceはc09と同blob。元sealは `821e10e942cdd225bf12767ea8a1411105570c4fd755d274c0411a91f52fbcec`。`release20_proposed_reference`は新sealを持つ**CI専用**で、両版のbaseline94/candidate114の物理hashは原参照と一致することを独立照合。これを本番collectorの既定参照へコピーしない。

旧collectorと診断collectorを固定e858で**offlineのみ実行**し、それぞれ固定BLOCKを確認した。新seal不一致と旧c09 pinによる正しい拒否であり、source障害や解消すべき「設定不足」ではない。新source-bound監査セットは未実装/未審査で、別準備PRが必要。

起動既定`check`はledger読取だけ、pending/unknownでHTTP前に停止。`apply`は承認40桁SHA=`RENDER_GIT_COMMIT`を要求し一回のみ、失敗時自動retryなし。これは物理hash/owner承認を自動証明しない。隔離stagingのretry分岐を本番へ流用しない。実Render環境変数・旧image起動/再起動時挙動は未照会。

順序：**本票レビュー → 新source-bound監査セットのoffline準備/審査 → ownerによる監査候補採用・release pin（別承認）→ metadata-only実測（別承認）→ 権限/可視性/復元/writer/実機の各ゲート → 実migration GO → production/deploy GO**。詳細は[承認表](production-gates-and-approvals.md)、[候補提案](release-candidate-adoption-proposal.md)、[実Aiven計画](real-aiven-readonly-diagnostic-plan.md)、[復旧票](backup-quiescence-forward-recovery-runbook.md)。本PRは文書のみで、新source・監査器・候補・本番状態を変更しない。

# AP本人連携：B案（main統合リリース）実環境受入と停止条件 — 2026-10-18

> **CURRENT DECISION / REVIEW ONLY / PRODUCTION NO-GO**。オーナーはA（旧94件先行バックポート）でなく **B（開発mainの全差分を正規経路で統合）を優先する方針**を表明。これは本番昇格・DB更新・Auth0/MCP有効化・個人Planアクセス・追加費用のGOではない。
>
> 参照優先：最新 `AGENTS.md`、`docs/CANOVIA_DEVELOPMENT_WORKFLOW.md`、[統合マスター](CANOVIA_INTEGRATION_FREEZE_RELEASE_MASTER_2026_10_16.md)、[Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488)、[AP本人パイロットの権限要件](MCP_AP_REAL_OWNER_PRIVATE_PILOT_GATE_2026_10_15.md)、[実アカウント連携の受入](MCP_REAL_ACCOUNT_CONNECTION_RELEASE_GATES_2026_10_14.md)。旧A案の[PR #785](https://github.com/1kz-ma1/Canovia-web/pull/785)と[PR #786](https://github.com/1kz-ma1/Canovia-web/pull/786)は履歴・証拠としてDraft/HOLDのまま維持する。A実装をmainに機械的に移植しない。

## 1. 現在地 — 完全Git blob treeに基づく観測

| 対象 | 現時点の固定ソース |
| --- | --- |
| GitHub `production` / Render Web Live | `15abdc9de6f24b5250fb255c579d1aa55facaf1a` |
| `main` 検証基準SHA | `4e3a6e97949602409ea337290c4d4f2052fda998` |
| Git blob tree | 旧1,929 → main 2,681ファイル。追加752、既存変更227、削除0、**合計979パス**。両treeとも `truncated=false`（APIの通常の比較ファイル上限300の推計ではない） |
| Laravel migration source | 旧94件 → main114件。**新規20件、旧94件のblob変更0、削除0** |
| 実Aiven確認（オーナーがMacから提出した固定判定、別途独立再確認必要） | 制限された監査ユーザーの`SHOW GRANTS`判定がPASS、`FULL_MIGRATION_LEDGER_MATCH_NOT_RELEASE`は適用94/予定94/残0、P0対象スキーマ判定`TARGETED_METADATA_MATCH_NOT_RELEASE`。これらは**現行旧94 source**との一致を示すもので、114 sourceへの移行のGOではない |
| バックアップ | オーナーのUI上で直近Fullの記録あり。**独立復元・フォーク/復旧の成功なし** |
| Render | `My Workspace` / Canovia Web、Auto Deploy OFF。サービスのソースbranch設定はまだ`main`、実Liveは旧SHA。未承認のenv更新・手動デプロイ禁止 |

**AP利用価値:** mainに既に`McpAuth0JwtTokenVerifier`、`McpReadOnlyStudyContextTool`、`McpReadOnlyStudyPracticeSummaryTool`、`McpReadOnlyLearningContextSnapshotTool`、`McpProposeStudyResultTool`等のコードがある。ただし現時点で**productionの本人アカウントで動作した証拠はない**。学習コンテキスト共有には`learning_context`等の別同意・フラグ、採点結果提案には個別write権限とオーナーによる適用が必要。公開時に一度に全部をONにしない。

## 2. 新規20 migrationのソースを必ず審査

```text
2026_10_12_010000_add_source_revision_to_development_activity_observations
2026_10_13_021000_add_source_epoch_to_development_activity_observations
2026_10_13_110000_add_authorization_epochs_to_collaborative_plans
2026_10_13_120000_enforce_authorization_epochs_in_database
2026_10_13_130000_immutable_plan_member_binding
2026_10_13_200000_add_recall_candidate_review_provenance
2026_10_14_000100_allow_nonexpiring_mcp_grants
2026_10_14_000200_preserve_historical_mcp_identity_links
2026_10_14_100000_create_private_file_cleanup_entries
2026_10_14_120000_create_private_file_path_fences
2026_10_14_130000_create_private_file_upload_intents
2026_10_14_140000_create_private_file_generation_evidence
2026_10_14_150000_create_private_file_write_attempts
2026_10_14_154500_create_development_operational_profiles_table
2026_10_14_164000_create_development_operational_targets_table
2026_10_14_185000_add_manual_service_check_to_development_operational_targets_table
2026_10_14_201000_add_storage_revision_fence_to_development_operational_records
2026_10_15_070000_create_mcp_study_result_proposals_table
2026_10_15_110000_create_learning_context_watch_persistence
2026_10_15_120000_create_learning_context_source_epochs_table
```

特に**DB trigger（authorization epochとPlan member）、MCP identityのuniqueness差し替え・既存行、null expiry、Private Fileの整合性、watch用永続化、再実行・部分DDL**を新旧DBで検証。既存の`94→113`固定19件用CIと`release488`の特定2件用ゲートは、**114件/追加20件の完全検証に流用してPASSとはしない**。本番DB権限は別途operatorが確認する。

## 3. B案の受入順序とSTOP条件

1. **候補SHA固定・実装差分審査（OFFLINE）:** オーナー承認済みの最新main SHAを`release/YYYY-MM-DD-<shortsha>`として切り、`production`から979パスを全件レビュー。auth/session/CSRF・公開レベル・MCP/外部write・billing・UI・worker・startup/Composer/部署設定を変更・未変更問わず分類。途中でmainが動いたら新しいSHAを再選定し、後段の監査をやり直す。**この文書で引用する`4e3a6e...`は検証基準であり、まだ本番候補branchを作った証拠ではない。**
2. **使い捨てMySQLの完全移行検証:** 現行94 sourceを`migrate`し、合成レガシー行（同意/subject/Plan/Task/共有権限/Private Files）を作り、候補の**追加20件全部を順序どおり**適用。MySQL 8.0/8.4および対象Aivenエンジン差分、trigger権限/DELIMITER、旧writer fence、既存データ維持、migration ledger、再実行、途中失敗・forward recoveryを確認。**DisposableのPASSを実Aiven成功と誤認しない**。
3. **実Aivenの独立監査:** 既存で成功したMacのowner-operated、固定source、TLS `VERIFY_IDENTITY`、`REFERENCES` on target + `SELECT` on `migrations`限定の方式を再利用。source114と実ledger94の差分を、**20件だけが期待pending**でありUNKNOWN/部分DDLがないこととして評価し、型・索引・制約・trigger状態をレビューする**新しい20件専用判定契約**を先に実装・CI合格させる。旧94件用判定器で代用しない。生ledger/DB名/host/認証情報をGitHubやチャットに公開しない。**現在の94件受入結果は、新候補の実Aiven監査実施とは見なさない**。
4. **復旧・移行実行の承認:** 本番バックアップ一覧だけでなく、Aiven Freeの実行可能な復旧手段/復旧先/復旧時間と未実証リスクを審査。実書き込みworker停止・デプロイ順序・maintenance・旧writerとの非互換DDL・DDL権限・移行失敗時forward fixを決める。DBへの`GRANT`/`DDL`/backup/restoreはそれぞれ別の明示owner GO。
5. **新mainの安全な起動順序:** mainの`docker/startup-migration-policy.sh`は既定`CANOVIA_STARTUP_MIGRATIONS=check`で、114 sourceに未適用20件があると起動を停止する。例外的`apply`は`CANOVIA_APPROVED_MIGRATION_SHA`が`RENDER_GIT_COMMIT`と完全一致する場合のみ一度適用（失敗時自動ロールバックしない）。**旗をOFFにするだけではDB移行を回避できない**。候補sourceと移行承認SHA・リカバリー計画を一致させる。
6. **正規の本番昇格（Owner GO後）:** `release/* → production`のDraft PR、production上の5 required CI（`Production promotion contract`、`workspace-resume`、`migration-recovery`、MySQL 8.0/8.4）、main ancestryを全て確認。保護ルールを無効化せず、**merge commit**で統合。Renderの監視branchをproductionに切替える場合も別GO。Auto Deploy OFFで固定SHAを初回手動デプロイ。現在の旧版Auth0/Sessions/CSRF/Plan/UIがWeb・iOS WKWebViewで維持されたことを確認。未検証の一般公開レベルを勝手に上げない。
7. **AP本人連携の段階的ON（別GO）:** まず外部MCPがOFFで他アカウント・Plan・Tasks等を読めないことを実証。Auth0 real JWT（issuer/audience/scope/内部CIMD `tpc_` client）、第一者リンクとChatGPTクライアントが別であること、GitHub immutable subject一致、本人に許可されたプライベートStudy Plan ID、OAuth再確認とPlan scope/期限/取消を確認。**overviewだけ → tasks → approved summaries → `learning_context`** と別同意で広げる。結果提案のwriteは当面OFFのままでよい。同一未失効bearerによる取消直後DENY・緊急OFF・別ユーザーDENYまで実測。

## 4. B案の当面のSTOP/合格境界

| ゲート | 現状 |
| --- | --- |
| 旧本番94 migration実Aiven一致・P0対象metadata | オーナーMac実測PASS（上記限定） |
| main source 114 migration / full tree差分の一覧 | **GitHub read-onlyで確定**。979パスのセキュリティレビューは未完了 |
| 追加20 migrationの正確なDisposable rehearsalと20専用Aiven監査 | NOT_RUN |
| 実Aivenの114候補に対する台帳/物理DDL/trigger確認 | NOT_RUN |
| 独立復旧・可逆性・継続稼働の検証 | NOT_RUN |
| 本番release branch/PR/保護CI/Owner GO/Render移行 | NOT_RUN |
| Auth0実ユーザー連携、個人AP Planへの許可付read | NOT_RUN |

**結論：B案の受入設計とソース検査を開始するが、リリースはHOLD。** 旧A案用PRの安全テストは証拠として保持し、B実装と同一成果と混同しない。個別PlanやGitHub認証の生IDを記録しない。一般向け公開・課金は変更なし。

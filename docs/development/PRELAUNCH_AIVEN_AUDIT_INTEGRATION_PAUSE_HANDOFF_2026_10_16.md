# Platform / Release — Aiven固定94→113監査の作業停止・統合引き継ぎ（2026-10-16）

> **状態: PAUSED / INTEGRATION HANDOFF / PRODUCTION NO-GO**  
> 対象: `1kz-ma1/Canovia-web`、Platform・Release #488 の **固定MySQLソース監査と本番昇格前ゲート**。新機能や実Aiven接続の承認文書ではない。  
> GitHub照合の基準 `main`: `eae0cd4fb653af92ebf93fb56b0071b63714a35d`（照合時点のスナップショット。以後動く `main` と同一視しない）。  
> 状態の正本: [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488)。運用規則: [AGENTS.md](../../AGENTS.md)、[開発・本番昇格手順](../CANOVIA_DEVELOPMENT_WORKFLOW.md)。本書は**停止時点の差分だけ**を記録し、既存の詳細手順・判定規則を複製しない。

## 1. 担当領域と目的

- **担当**: Platform / Release（Orchestratorの全体統合に引き渡し）。LearningやMCP/Intelligenceの新機能実装ではない。
- **目的**: 現行Web LiveのGitソース（migration 94件）と、固定先行公開レビュー候補（113件、追加19件）の**ソース差分**を、漏えい・接続先誤り・権限過大・部分DDLに対してfail-closedで監査する準備。開発 `main` と本番 `production` / Render Live の乖離を安全に解消するための独立した証拠ゲート。
- **対象外**: 実DB変更、権限変更、バックアップ取得/復元、Render起動・デプロイ、OAuth/MCPの有効化、iOS配布、公開GO。監査の `REVIEW_REQUIRED` はリリース承認ではない。

## 2. CURRENT / IMPLEMENTED — 実装済み・検証根拠

| 現在実装されている仕組み | 実装/検証の根拠 | 証明する範囲 |
| --- | --- | --- |
| 固定Live 94 → Candidate 113の使い捨てMySQL 8.0/8.4移行・合成データ保持/再実行 | [PR #729](https://github.com/1kz-ma1/Canovia-web/pull/729)、[固定移行CI](../../.github/workflows/prelaunch-pinned-mysql19-upgrade.yml) | **使い捨て環境のみ**。Aivenの実権限・復元を証明しない |
| 19件専用の台帳/13状態メタデータSELECT・厳格なオフライン判定 | [PR #733](https://github.com/1kz-ma1/Canovia-web/pull/733)、[専用監査仕様](PRELAUNCH_PINNED_MYSQL19_SELECT_ONLY_AIVEN_AUDIT_2026_10_15.md) | UNKNOWN、部分移行、期待外の履歴をBLOCK。#513の「2件用」判定器とは別 |
| 実行可能な監査ユーザー相当の限定 `REFERENCES` + `migrations` 表 `SELECT` 検証 | [PR #749](https://github.com/1kz-ma1/Canovia-web/pull/749) | CI使い捨ての権限・SQL拒否試験。Aiven実ユーザーの権限確認ではない |
| 監査コード・SQL・旧新migrationの改変、未追跡・ignoredファイルを接続前に拒否 | [PR #752](https://github.com/1kz-ma1/Canovia-web/pull/752) / `e04c646c78e021268801cacb1d8ef1b0ad276101` | `--preflight-offline` はMySQL/DBへの接続ゼロ |
| Macで固定3checkoutを私有ディレクトリへ準備するstageツール | [PR #753](https://github.com/1kz-ma1/Canovia-web/pull/753) / `8b2a3fb30871026c91fd58418293b9b0e3702043` | `--destination` に既存でない絶対パスを指定。fetch/本番接続/上書きなし |
| 本番DDL権限・独立復元が未証明ならNO-GOを維持するオフライン判定 | [PR #738](https://github.com/1kz-ma1/Canovia-web/pull/738)、[DDL・復元仕様](PRELAUNCH_AIVEN_RUNTIME_DDL_AND_RECOVERY_GATE_2026_10_15.md) | 20項目の自己申告でも自動GOは出さない |

**CI証跡**: PR #752の対象head `bf36f18f889cfb87ac77a3fffba04d1dddc59109` では Platform Release Preflight / Pinned MySQL 94-to-113 / Development Prelaunch / Migration Recovery / Workspace Resume の5件が `success`。PR #753の対象head `7a082944af392cf6638ff978eb0a2fb3c4f85b83` では Development Prelaunch / Migration Recovery / Workspace Resume / Pinned Aiven Mac Staging / Platform Release Preflight の5件が `success`。いずれも**そのheadに限る**。

**オーナーMacでの事前確認（2026-10-15〜16、チャット申告の限定事実）**:

- `$HOME/canovia-aiven-audit-20261015` に固定 `audit/live/candidate` と `offline-verdict.txt` を作成。ステージング表示は `OFFLINE_PREPARED_NO_AIVEN_CONNECTION`。
- `PINNED_MYSQL19_LOCAL_SOURCE: OFFLINE_SOURCE_INTEGRITY_CONFIRMED_NOT_AIVEN_READY`、`AUDIT_SOURCE: PASS`、`MYSQL_CLIENT: READY`、`MYSQL_LOGIN_EDITOR: READY` を確認。
- Render接続先とAivenの対象サービス/DBはオーナーが画面照合して一致と報告。別の監査専用アカウントあり、CAはダウンロード済み。
- ローカル証明書がX.509として有効期限内（`TLS_CA_FILE: VALID_LOCAL_CERTIFICATE`）。これは**対象Aivenサービスとの一致/サーバー身元の実証ではない**。
- MySQL login-pathがローカルに存在（`AUDIT_LOGIN_PATH: READY_LOCAL_ONLY`）。**認証/接続の成功ではない**。
- `MYSQL_TLS_OPTION: SUPPORTED` の結果は**未受領**。この領域の今回の作業に対して **実Aivenへの接続・SELECTは未承認/未実施**。既存オフライン成功を実監査成功と記さない。
- 過去の[ドラフトPR #513](https://github.com/1kz-ma1/Canovia-web/pull/513)には **別の固定SHA `bb8248f`、94→96（2件）** に対するオーナーMac実Aiven SELECT-only判定の記録がある。**今回の94→113（19件）監査を代替しない**。

## 3. OPEN / UNVERIFIED — 未完了・既知の制約

1. **独立承認された今回の94→113実Aiven SELECT-onlyは未実施**。実サービスidentity、実ユーザーの `SHOW GRANTS`、`VERIFY_IDENTITY`、実94件台帳と物理状態を未実測。MacのTLSオプション対応出力も未報告。
2. 実行アプリの**本番DDL実効権限**（`CREATE/ALTER/DROP/INDEX/TRIGGER/REFERENCES` 等）、binlog/triggerポリシーと19件全体の互換性は未確認。監査専用read-only principalとLaravelのmigration principalは異なる。
3. Aiven Freeのバックアップ存在と**独立復元の成功/RTO/整合性**は別。Fork不可・部分DDL・書込み競合・復旧手順未確定をHOLD。
4. Web Liveの現行flag（DB、session、worker、Auth0/MCP、Native AI）、`docker/render-start.sh` の `php artisan migrate --force` 起動影響、事前書込み調整が未受入。**Webを起動する目的のHTTPや手動デプロイで監査を代替しない**。
5. 19件候補での実iPhone WKWebView、Safari/PWA/desktop、ログイン/学習/開発/本人権限の受入未完了。DB事故/障害を伴わない停止時点で、今回の監査コード固有の再現済み未修正バグは把握していないが、現場未検証を品質保証と誤認しない。
6. **本番昇格対象未確定**。旧[ドラフト #513](https://github.com/1kz-ma1/Canovia-web/pull/513)は2件用固定 `bb8248f` のまま、19件候補の `6d7dbcf` とは異なる。動き続ける `main` をそのまま公開対象としない。

## 4. Git履歴・branch / SHA と統合状況

| 区分 | 固定参照 | 状態 |
| --- | --- | --- |
| GitHub `main` | `eae0cd4fb653af92ebf93fb56b0071b63714a35d` | **停止調査時点のHEAD**。上記PR #729/#733/#738/#749/#752/#753はマージ済み |
| GitHub `production` とWeb Live | `15abdc9de6f24b5250fb255c579d1aa55facaf1a` | 本番現行。監査機能も新しいアプリコードも未配信 |
| Static Welcome Live | `ee533028c866e201d61c1430c15eaedb9c843524` | Webとは独立。本番反映未更新 |
| 19件用レビュー候補 | `release/2026-10-15-6d7dbcf` → `6d7dbcfedd35f33ba2827ed8c7c3d98ca30fc3d6` | 固定審査スコープ。productionへの昇格指示ではない |
| 監査プログラム固定版 | `e04c646c78e021268801cacb1d8ef1b0ad276101` | 19件候補を実測する際は監査ツールも固定し、moving mainを実行しない |
| 旧ドラフト | `release/2026-10-13-bb8248f` → `bb8248f25545ae0d600fbb68f66c41c360004e2c`, PR #513 → `production` | **draft/open/未マージ**。2件専用、19件とは混同禁止 |

停止調査時のRender read-only確認: workspace `My Workspace`、Web `srv-dagfn2id0e5s73cac7ng` は `branch=main` / `autoDeploy=no` / `autoDeployTrigger=off`、Live deploy `dep-db5fgl942hec738pbq30` は `15abdc9...`。Static `srv-dahn5k142hec739l0fmg` も同じくAuto Deploy OFF、Liveは `ee533028...`。GitHub `production` は `protected=true`、HEADは `15abdc9...`。**mainマージ ≠ productionマージ ≠ Render deploy ≠ 実機検証**。

## 5. DB・認証・環境依存／他領域との境界

- 監査ローカル: `mysql`、`mysql_config_editor`、Python 3、Git、MacのCA（秘匿場所）。実実行スクリプト `scripts/ops/prelaunch_pinned_mysql19_collect.sh` は `CANOVIA_PINNED_MYSQL19_LIVE_CHECKOUT` / `CANOVIA_PINNED_MYSQL19_CANDIDATE_CHECKOUT`、`CANOVIA_P0_LOGIN_PATH`、`CANOVIA_P0_DATABASE`、`CANOVIA_P0_CA_FILE`、および別途承認後のみ `CANOVIA_PINNED_MYSQL19_OPERATOR_APPROVED=YES`、`CANOVIA_P0_TARGET_VERIFIED=YES`、`CANOVIA_P0_GRANTS_REVIEWED=YES` を要求する。**値・ログイン情報・実台帳・GRANTSをGit/Chatへ保存しない**。 `DB_URL`、`DATABASE_URL`、`MYSQL_PWD`、`DB_PASSWORD`、`MYSQL_TEST_LOGIN_FILE` の紛れ込みは禁止。
- 実監査は `SHOW GRANTS FOR CURRENT_USER()` で限定権限を確認後にのみ台帳/`information_schema` SELECTする。CIでは実DB権限・Restore能力・実MCP/Auth0接続を証明できない。
- Learning / Development / Intelligence: 19 migrationsのPlan・Task・Observation・権限epoch・DB triggerの互換性、旧データ維持、タスク進捗の誤更新なしを領域担当と合意する。
- Experience / iOS: WebはWKWebViewに直結する。Static-only公開は独立トラックだが、古いWeb起動・`/health`へのアクセスさえ `migrate --force` と関係する可能性がある。安全な手動スモークの許可順を守る。
- MCP/Auth0/G3/AI: 本番有効化を自動決定しない。ReleaseLevel・権限・セッション・コストを、本番effective flagsと実actor受入で別審査する。

## 6. 再開時の推奨順序と必須テスト

1. **Orchestrator/Platformが停止時点を再検証**: `AGENTS.md`、最新`main`、Issue #488、PR #513、`production` と実Render LiveのSHA、両Auto Deploy OFF、候補ブランチの差分を再取得。前回の「CI緑」や `main` SHAを再利用しない。
2. **先にリリース対象を決定**: 旧2件候補、固定19件候補、別の限定Web候補、Static-onlyのどれをどう扱うかをオーナーが審査。固定候補と `main` の依存差分、全migration、Composer/Docker/権限変更をレビュー。#513を黙ってリターゲットしない。
3. **静的・ローカルのみの点検**: 固定audit/live/candidate SHAと `offline-verdict.txt` を再確認。MySQL `--ssl-mode` 対応、ローカルlogin-path・CA実ファイルを秘密保持のまま点検。古いcandidateで新しい `main` のmigrationを監査したことにしない。
4. **別途本人の明示的承認後だけ**固定19件専用のoperator-local `SHOW GRANTS` → 台帳/metadata SELECT。TLS `VERIFY_IDENTITY`、アカウントの最小権限、DB一致をfail-closed確認。**この引き継ぎ自体は接続を承認しない**。出力は匿名化されたverdict/countersのみ。
5. **独立ゲート**: 実migration principalのDDL/trigger/binlog権限、復元可能なバックアップ・RTO・書込み停止/forward repair、実効Render設定の確認。未実証ならNO-GO。Freeプランのbackup一覧を「復元検証済み」にしない。
6. **候補SHAのCI再実行**: disposable MySQL 8.0/8.4で旧94→選定候補の全移行、既存合成データ保持、二度目のmigrate、トリガー/権限epoch、partial-DDL・未知台帳のBLOCK、限定監査 principalでのusers/identity SELECT拒否、Workspace Resume、Migration Recovery、Production Promotion Policy、必要なLearning/Development/Auth0/MCP regressionsを評価。**対象が変わればテストのexpected migration集合も再策定**。
7. **別承認の本番昇格**: `release/* → production` の差分・CI・Owner GOが揃って初めて**merge commit**を検討。初回Auto DeployはOFFのまま手動デプロイを別承認。実Live SHA、migrationログ/失敗対応、5xx/SQL、ログイン/セッション、Learn/DevとiPhone WKWebView/Safari/PWA/desktopを実測。自動デプロイ有効化はさらに後の独立判断。

**停止時の禁止事項**: `artisan migrate` / `migrate:fresh`、`SET GLOBAL`、GRANT変更、Aiven dump/restore、Render wake/HTTP/デプロイ、OAuth変更、`production`へのmerge、CI未検証PRの強制merge、CA/パスワード/生台帳の共有。

## 7. 統合仕様に持ち込む設計判断（現行とFutureを区別）

### CURRENT / 承認済みの安全契約
- **immutable target + immutable audit tool + sanitize output**。`main`が動いてもソース・監査SQL・対象DBの承認範囲を自動拡張しない。
- オフライン整合性・CI disposable移行・real Aiven SELECT・runtime DDL権限・独立復元・実機/E2E・Owner GOは**独立したゲート**。一つのPASSから後段を推定しない。
- 運用上の唯一の本番ゲートはIssue #488。Web/Staticの公開トラックを分ける。Render Web startupはmigrateを実行し得るので、未承認の「軽いヘルスチェック」でも起動副作用を評価する。
- 生データ・認証情報・SHOW GRANTS・証明書をGitHub/ChatGPTへ出さない。合成MySQLで通ったSQLは本番権限・バックアップ復元の証明ではない。

### FUTURE / CONCEPT・未承認
- 後続の`production` + `checksPass`自動CD運用やAiven復元自動化は、[CD準備仕様](PRODUCTION_CHECKS_PASS_READINESS_2026_10_13.md)にある**将来運用候補**。初回手動リリース・required checks・owner承認方式・復旧条件の検証までは有効化しない。
- DBベースの自動監視、trigger拡張、AIによるrelease自動承認・自動migration・MCP/OAuth有効化は**この監査領域の実装済み成果ではない**。関係するIntelligenceのCI専用SQL trigger実験（例: [v0.15](LEARNING_CONTEXT_PROBE_FILTER_AND_LOCK_V015_2026_10_15.md)）も本番採用を意味しない。

## 8. 次回統合担当者が最初に見るもの

1. [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488) と [本番審査範囲](PRELAUNCH_FIXED_SCOPE_RELEASE_EVALUATION_2026_10_15.md) — 今回どの候補が対象か。
2. [固定19件用監査仕様](PRELAUNCH_PINNED_MYSQL19_SELECT_ONLY_AIVEN_AUDIT_2026_10_15.md) と [DDL・復元仕様](PRELAUNCH_AIVEN_RUNTIME_DDL_AND_RECOVERY_GATE_2026_10_15.md) — 非接続と実接続、read-only principalとmigration principalの区別。
3. [AGENTS.md](../../AGENTS.md)、[開発・本番昇格手順](../CANOVIA_DEVELOPMENT_WORKFLOW.md)、[旧ドラフト #513](https://github.com/1kz-ma1/Canovia-web/pull/513) — 技術的PASSと本番GOの境界。

**引き継ぎ結論**: この領域の新規実装は停止。監査コード/オフラインMac準備は `main` に統合済みで、実94→113のAiven SELECT・DDL/復元・公開/実機受入は未完了。統合責任者は**候補の固定と最新Live差分**を最初に再確認する。
# AP本人MCP先行版：旧94スキーマ・ソース昇格前レビュー

2026-10-18 JST。状態: **候補差分の機械検査を実装 / 本番昇格はHOLD**。
正本: [旧版バックポート受入仕様](MCP_AP_LIVE94_OWNER_READ_ACCEPTANCE_2026_10_18.md)、[PR #785](https://github.com/1kz-ma1/Canovia-web/pull/785)、[統合・本番収束マスター](CANOVIA_INTEGRATION_FREEZE_RELEASE_MASTER_2026_10_16.md)、[AGENTS](../../AGENTS.md)。

## 実装した限定検査

`scripts/ci/mcp_ap_live94_source_gate.py` と `ap-mcp-live94-source-gate.yml` がGitHub上の**固定SHAだけ**を読み、ネットワーク・Auth0・Render・Aiven・本番データへ接続せず、以下を確認する。

- PR対象productionの実SHAと固定旧Live `15abdc9de6f24b5250fb255c579d1aa55facaf1a` が一致。対象ブランチが移動したらSTOP。
- 候補HEADが旧Liveを祖先に持つ。変更パスが、現行のAP接続レビュー用allowlistだけに収まること。追加のmiddleware・route・Docker・予想外のUIやmigrationを拒否。
- 旧94件のmigrationソースが一件も追加・修正・削除されず、件数も94であること。
- MCPの重要な設定（公開・読み取り・同意・GitHub-only・JWTなど）が既定OFFを保持すること。Composer更新時はlockが対応すること。
- 判定は `REVIEW_REQUIRED` または `BLOCK` のみ。**`production_release_authorized=false` は常に固定**。実DBのスキーマ一致・バックアップ復元・本人OAuth認証を代用しない。

検査は機能の正当性やOAuth本人一致を保証しない。合成JWT/MySQL 8.0・8.4テストも別に必須。検査allowlistにファイルを追加すること自体もレビュー対象。

## 昇格規則の問題（未解消）

1. `main` の `production-promotion-policy.yml` は「`release/*`で、HEADが`main`祖先」の通常昇格だけを認める。一方APの旧版選択移植は旧Liveから分岐し、`main`の祖先ではない。
2. 現在の`production`と選択移植候補には **このpromotion-policyワークフロー自体が存在しない**。したがって`main`にある昇格規則や今回のソース検査が成功しただけでは、GitHub上の本番ブランチ保護・承認を満たしたとはみなせない。
3. 旧Liveの `docker/render-start.sh` は本番起動のたび `php artisan migrate --force` を実行する。候補の0追加migrationは「実Aiven ledger一致」「既存テーブル実物一致」「起動時DDLなし」の証明にはならない。
4. Render Webは現状 `main` 監視、Auto Deploy OFF。候補・GitHub`production`更新と、実際のRender Live反映は別の操作・別の所有者承認である。
5. GitHub Ruleset [Canovia Production Protection](https://github.com/1kz-ma1/Canovia-web/rules/24954459) は2026-10-18のread-only API観測で `enforcement=active`、`production` 限定、force-push/削除禁止、merge-only PR、bypass actorsなし。必須チェックは `Production promotion contract`、`workspace-resume`、`migration-recovery`、`P0 schema integrity (MySQL 8.0)`、`P0 schema integrity (MySQL 8.4)`。**AP用のSource-Only CI/JWT/MySQL CIとは別の名前**であり、これらの成功は本番rulesetの必須チェックを満たした証拠ではない。古いproductionには当該 promotion workflow 定義がないため、候補配備前のbootstrap方針をownerが承認しない限り、必須チェックを一方的に取り除いたり代理のステータスを作らない。GitHub branch-protection REST endpointは接続権限不足で403だが、Ruleset GETは成功した。

## 狭い先行公開へ進む場合の別審査（まだ実装しない）

- **専用昇格例外の正式採否:** Ownerが通常release規則からの逸脱を承認した場合に限り、既存`production`保護の実効性を確認し、固定候補SHA・コードallowlist・no-DDL・Auth0本人/非本人拒否・必要CI・復旧手順を満たす場合のみ許可する*狭い*変更を別PRで審査する。ポリシーの一般的な無効化、force push、allowlist外の暗黙許可は不可。承認不在または強制保護未成立ならPath B（通常main release）へ戻す。
- **Aiven/バックアップ:** 許可されたoperatorが実台帳/物理schemaをSELECT-onlyで照合。FreeのFull backup存在は確認済みでも、独立restoreは未実証。復旧能力・起動時migration・稼働中既存セッション/CSRFを確認するまでdeployしない。
- **Auth0とChatGPT:** `canovia-mcp-prod`で署名済みaccess tokenがGitHub固定subjectと同一になること、Auth0 Link clientとChatGPT実CIMD clientの識別、正しいaudience/issuer/scope、PKCE callback・キャンセル・期限切れを実環境で確認。トークンやsubをログ/PR/チャットへ載せない。
- **デプロイ前後:** 全フラグOFFでリリースし旧機能の正常性をWeb・iPhoneで受入後、Owner限定フラグを別承認でON。同意前deny→1件overview read→取消後同一未失効トークンでdeny→緊急OFF→denyを確認。問題/成績/Learning Contextは一切含めない。

**現在の判断:** Draft #785を維持。通常`main`のルールを勝手に書き換えず、`production`マージ・Render/env・Aiven・Auth0実設定はしない。

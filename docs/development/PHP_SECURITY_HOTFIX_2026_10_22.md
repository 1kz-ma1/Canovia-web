# PHP Security Hotfix 2026-10 — CVE-2026-109618 / CVE-2026-109619

> 状態: **main向けHotfix候補 / 本番未反映 / Render操作未承認**  
> 作成日: 2026-10-22  
> 管轄: Platform / Hotfix  
> 変更範囲: `Dockerfile`, `Dockerfile.mcp-staging`, 独立Docker CI。環境変数・本番DB・認証・公開判定の変更なし。

## 1. 目的と一次情報

PHP公式が2026-10-21に公表した **CVE-2026-109618** と **CVE-2026-109619** の未認証リモートコード実行リスクに対し、CanoviaのWeb/FPMイメージを修正済みPHPへ更新できる状態にする。PHP 8.4系の公式修正版は **8.4.27**。今回のHotfixはPHP 8.4系を維持し、8.5系への移行は含めない。

- PHP公式アドバイザリ: https://www.php.net/archive/2026.php#2026-10-21-5
- PHP 8.4.27: https://www.php.net/releases/8_4_27.php
- 使用するDocker公式イメージ: https://hub.docker.com/layers/library/php/8.4.27-fpm-bookworm/images/sha256-eb37f58646a901dc7727cf448cae36daaefaba79de33b5058dab79aa4c04aefb

**脆弱性の存在と侵害の発生は別**。Render稼働コンテナ内の `php -v` と実イメージDigest、過去の侵害有無はこのGitHub変更だけでは確定できない。既存稼働イメージが自動的に更新されることもない。

## 2. 2026-10-22 監査時点の事実

| 項目 | 観測結果 | 保留事項 |
|---|---|---|
| GitHub開発main | `5f0be190dc759bc6e842aa2c2888f1a41c295865` | Hotfix開始基準。作業時に再確認 |
| GitHub production | `15abdc9de6f24b5250fb255c579d1aa55facaf1a` | 開発mainとの未公開差分は解消しない |
| Render `Canovia` | Live SHA `15abdc9d` / 最終成功デプロイ2026-10-11 | 実PHPパッチ番号・Digest未取得 |
| Render `canovia-mcp-staging` | Live SHA `1e93b6ce` / 最終成功デプロイ2026-10-14 | 実PHPパッチ番号・Digest未取得 |
| Renderアクセス | 両WebサービスのIPv4アクセス許可は `0.0.0.0/0` | Laravel/ネットワークによる個別制御は別途評価 |
| Renderデプロイ設定 | 両サービスAuto Deploy OFF | このHotfixで変更しない |
| PHP構成 | Nginx + PHP-FPM、元のベースは `php:8.4-fpm-bookworm` | PHP公式はPHP-FPMをリスク対象としている |

## 3. 実装仕様（今回の変更）

1. 両方のDockerfileのPHPベースイメージを **`php:8.4.27-fpm-bookworm`** に明示固定する。Debian bookwormとFPM、Nginx/拡張の既存構成は維持する。
2. Dockerビルド中に `PHP_VERSION_ID >= 80427 && PHP_VERSION_ID < 80500` を検証し、対象バージョン以外ならfail closedする。これは公式の8.4系修正済みパッチを強制するための床値。将来の8.4.28以上は明示的にタグとCI契約を更新する。
3. 独立GitHub Actions `php-security-hotfix-2026-10.yml` で両Dockerfileを別々に `docker build --pull --no-cache` する。ベースのDigestを記録し、実イメージからPHP/FPM/Nginx/Composerプラットフォーム条件を確認する。
4. CI検証時は本番・Stagingの環境変数、秘密情報、認証サービス、外部DBを使用しない。デフォルトのRender起動スクリプトは呼ばず、実DBへのDDLや移行を起こさない。
5. 既存の `mcp-staging-docker-smoke.yml` による**使い捨てSQLite/PostgreSQLを使った隔離HTTP検証**も重要な受入証拠とする。
6. `composer.json` の `php: ^8.3` は今回変更しない。これはComposerの互換性宣言であって本番PHPのパッチ適用証明ではない。

## 4. 合格条件と保留ゲート

**main向けPRの受入条件**

- Dockerベースイメージの指定が一致し、ビルド時に脆弱なPHPバージョンを拒否する。
- 独立CIで本番用とMCP Staging用の両イメージを実ビルドし、PHPの実バージョン・FPM/Nginx設定・Composer依存を確認する。
- 既存の隔離StagingのHTTP・認証拒否・DB分離検証、および必要なrequired checksがすべて成功する。
- 差分を確認し、DB migration・APP設定・OAuth/MCP能力・本番運用経路が変更されていないことを検証する。
- `AGENTS.md`に基づき、必要なCI・レビュー・マージ条件が不足していれば **PR OPEN/HOLD** とする。

**本番適用には別途承認が必要**

- `main` 統合、`production` への昇格、Render実デプロイ、実DB操作、iPhone/Web受入はすべて別の状態。今回のHotfix PRを作成・統合しても本番適用済みとは宣言しない。
- Web本番への緊急適用は、現在のLive SHA `15abdc9d` へ**PHP修正だけ**を安全に持ち込める固定リリース候補とロールフォワード経路を別途監査する。開発 `main` の大きな未公開差分を混入させない。
- 検証済み修正イメージのDigest・PHP実バージョン・本番デプロイSHAを記録し、Live/Smoke/運用ログ確認後にのみ `REMEDIATED` とする。
- 新しい修正済みイメージの障害時に、**脆弱な旧イメージへの安易なロールバックを禁止**する。必要なら公開制限・修正済み代替イメージへのロールフォワードを選択する。

## 5. 残存リスク・運用確認

- 修正前のWeb公開期間について、認証されていないアクセス、PHP-FPM障害、異常なプロセス・ファイル変更等のインシデント調査は未実施。ログに異常がないだけでは非侵害の証明にならない。
- PHP修正完了までの外部アクセス制限は運用者判断。今回のGitHub作業ではRender設定を変更しない。
- イメージDigest固定は本Hotfixでは見送り、公式の**具体的なパッチタグ**とビルド時・CI時のバージョンゲートを採用。CIログでPull時のDigestを追跡する。以後のOSパッケージ修正を含む継続更新は別の保守運用として管理する。
- 認証・Session・CSRF・Study・Development・MCP・ファイルアップロードとWeb/iOS回帰は、将来の本番適用時に重要。既存の本番公開/DB整合性/実機受入ゲートをスキップしない。

## 6. 明示的に今回実施しないこと

Render設定変更・新規デプロイ・再起動・シェル操作、本番Aivenへの接続/DDL、`production` ブランチ更新、`main` の大量差分の本番投入、OAuthや課金の有効化、脆弱性の能動攻撃・侵害再現は範囲外。

参照: `AGENTS.md`, `docs/CANOVIA_DEVELOPMENT_WORKFLOW.md`, `docs/development/CANOVIA_INTEGRATION_FREEZE_RELEASE_MASTER_2026_10_16.md`。

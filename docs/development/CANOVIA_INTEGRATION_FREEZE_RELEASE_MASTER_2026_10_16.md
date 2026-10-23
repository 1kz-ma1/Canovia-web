# Canovia — 統合停止・本番収束・段階公開マスター仕様（2026-10-16）

## 2026-10-23 Product-scope supersession (safety gates unchanged)

**Product owner後続決定:** [先行公開は正式版のための事前準備に限定し、Canonical DB継続を第一候補とする](PRELAUNCH_PREPARATION_SHARED_DB_RELEASE_STRATEGY_2026_10_23.md)（[D-2026-10-23-01](decisions/2026-10-23_PRELAUNCH_PREPARATION_SHARED_DB_DECISION.md)）。本書§4の「Study最初の一問/Development次Actionまで先行公開」は**履歴的な旧スコープ**であり、必須の初回公開要件としては**後続決定により置換**された。先行版は元のCanovia Webで診断・目標・Plan作成/保存/再訪を準備し、正式版に同じ所有データを引き継ぐことを優先する。

**変更なし:** §1–3, §5–7、Issue #488の共通DB/実Aiven/auth/session/権限/物理schema・migration/独立復元・release flags・actual device・Production GOの安全ゲート、Codex/Owner権限境界、Cloud §NO-GO/HOLD§。機能OFFは共有コード/migrationの無害化証明ではない。新チャット/実装は後続仕様§5–6を参照する。



> 状態: **INTEGRATION PLANNING / IMPLEMENTATION PAUSED / PRODUCTION NO-GO**  
> 担当: Orchestrator + Platform（各レーンは調査・引き継ぎの参照元）  
> 固定調査基準: GitHub `main` `626a4ea6b19842089da91b107cc61da234858c7d`。本文の観測値は継続的に更新されない。Codex着手時は再取得すること。
>
> この文書は**統合時に参照する判断と未検証事項の索引**である。個別仕様や履歴を置き換えず、詳細を重複転記しない。本書を作成・mainへマージしても、本番公開・DB変更・既存レーンの再開を承認しない。

## 1. 目的・停止ルール

- 第1目的: 開発用 main と本番 production/Render の大きな乖離を安全に縮め、以後の差分を小さいリリース候補に保つ。
- 第2目的: **コードを配備する行為**と**ユーザーごとの機能公開**を分離する。データを消さずに Super Admin Preview / 限定Early Access / 一般公開 / 非公開を管理する。
- 第3目的: 安全な復旧・監査と必要CI、Web/iOS/MCP受入の証拠を揃える。初回の本番昇格は別途オーナーGO/NO-GO。
- 並行レーンの**新規機能実装は一時停止**。例外はオーナーが個別指定した緊急修正と、この統合に必要なdocs/tests/feature-gating/release-safety作業のみ。レーン別未完成物やFuture Designを勝手に公開対象へ追加しない。
- 未確認事項は UNKNOWN / HOLD と記す。CI green、main merge、release candidate、Render Live、実機受入は互いに代替しない。

## 2. 証拠・仕様の優先順位

1. 最新の `main` 実コードと Git SHA、`AGENTS.md`、`docs/CANOVIA_PRODUCT_SPEC.md`。
2. 現行プロダクト/運用契約: `docs/CANOVIA_DEVELOPMENT_WORKFLOW.md`、`docs/CANOVIA_RELEASE_LEVEL_SPEC.md`、`docs/development/ROADMAP.md`、Release Issue [#488](https://github.com/1kz-ma1/Canovia-web/issues/488)。
3. 専門領域の versioned/active docs、テスト/CI、PRのactual diff、当該Renderサービスの実Live SHAと環境の非機密メタデータ。
4. 本文の固定時点索引と、領域停止時点の引き継ぎ。過去のSHA/旧snapshotの数は最新状態の代わりにならない。
5. `docs/future/*` は **Future Design**。明示的なプロダクト判断なしに現行要件として実装しない。

停止記録の存在確認をした書類:
- [Learning/AP80 停止引き継ぎ](LEARNING_AP80_INTEGRATION_PAUSE_HANDOFF_2026_10_16.md): PR #768（mainに統合済み）。教材の人間・権利承認は0であり、非公開を維持。
- [Platform/Aiven監査 停止引き継ぎ](PRELAUNCH_AIVEN_AUDIT_INTEGRATION_PAUSE_HANDOFF_2026_10_16.md): PR #767（mainに統合済み）。94→113固定候補のオフライン準備で、実Aiven監査未実施。
- **全レーンの個別停止報告が上の2ファイルへ集約済みであるとは証明されていない。** 他チャットの引き継ぎが既存領域specへの追記に留まる可能性がある。Codexは「停止済み」のユーザー判断を尊重しつつ、下記の参照資料と最新PR/branches/CIを照合し、見つからない引き継ぎは evidence gap として報告する。独断で実装を再開しない。

既存の領域別確認先（内容の複製はしない）:

| 領域 | 重要な参照 | 状態を取り違えないこと |
| --- | --- | --- |
| Experience / 初回導線 | [IA-2j](PRELAUNCH_IA2J_STUDY_WORK_FOCUS_2026_10_15.md)、[IA-2i](PRELAUNCH_IA2I_MAC_LOCAL_ACCEPTANCE_LAB_2026_10_15.md)、[IA-2k](PRELAUNCH_IA2K_SECOND_PINNED_MAC_SAFARI_ACCEPTANCE_2026_10_15.md)、[初回価値の範囲](PRELAUNCH_FIXED_SCOPE_RELEASE_EVALUATION_2026_10_15.md) | 開発・ローカルSafari・Chromium・iPhoneと本番Liveを区別 |
| Learning | [AP80停止](LEARNING_AP80_INTEGRATION_PAUSE_HANDOFF_2026_10_16.md)、[V6.21 draft保存](LEARNING_FIRST_DRAFT_CONTINUITY_V621_2026_10_15.md) | 未監修問題を公開しない。legacy draft_answersは採点済みAttemptではない |
| Development | [V6.6 pinned release受入](DEVELOPMENT_RELEASE_513_EVIDENCE_NEXT_ACTION_V6_6_2026_10_15.md)、[V6.9 Chromium PR #671](https://github.com/1kz-ma1/Canovia-web/pull/671) | Evidence保存と本人承認済みTask進捗は別。GitHubイベントを実施証拠と誤認しない |
| Intelligence | [Learning Context v0.18判断](decisions/2026-10-15_LEARNING_CONTEXT_SOURCE_AUTHORITY_V018.md)、[v0.19](LEARNING_CONTEXT_CAPTURE_DIAGNOSTICS_V019_2026_10_15.md)、[v0.20](LEARNING_CONTEXT_CAPTURE_BENCHMARK_V020_2026_10_15.md) | 現行全履歴source hashが権威。Epochはshadow、SQL triggerはCI専用、Watch/通知はデフォルトOFF |
| MCP / AP | [実アカウント要件](MCP_REAL_ACCOUNT_CONNECTION_RELEASE_GATES_2026_10_14.md)、[合成ChatGPT E2E](MCP_CHATGPT_STAGING_REAL_OAUTH_READ_REVOCATION_ACCEPTANCE_2026_10_14.md)、[AP pilot HOLD](MCP_AP_PRIVATE_PILOT_PINNED_RELEASE_INVENTORY_2026_10_15.md) | 合成Development Plan読み取りは成功。実AP本人データ・一般公開は未受入 |
| Platform / DB / Release | [Aiven監査停止](PRELAUNCH_AIVEN_AUDIT_INTEGRATION_PAUSE_HANDOFF_2026_10_16.md)、[Release運用](../CANOVIA_DEVELOPMENT_WORKFLOW.md)、[checksPass設計](PRODUCTION_CHECKS_PASS_READINESS_2026_10_13.md) | 本番DB実台帳と復旧は未保証。起動時migrate副作用あり |
| G3 / Provider | [provider GO/NO-GO](G3_PROVIDER_AUTHORIZATION_DECISION_R40_2026_10_15.md)、[Inbox atomic](G3_INBOX_RESOURCE_ATOMIC_COMMIT_R43_2026_10_15.md) | AWS/S3実プロバイダのGOなし。外部DELETE/自動実行を有効化しない |
| Public surface | [最小公開対象](PRELAUNCH_FIXED_SCOPE_RELEASE_EVALUATION_2026_10_15.md)、[公開レベル仕様](../CANOVIA_RELEASE_LEVEL_SPEC.md) | Static WelcomeとWebは独立。roadmapのreleased表記は実本番受入後のみ |

保存すべきWIP: `docs/wip/2026-10-08_STATE_FIRST_PRE_RELEASE_EXECUTION_SPEC.md`、`docs/wip/PERSONALIZATION_BOOTSTRAP_IMPLEMENTATION.md`。一時停止を理由に削除・完了扱いしない。

## 3. 実測境界（2026-10-16確認）

| 境界 | 今回確認した状態 | 意味 |
| --- | --- | --- |
| GitHub main | `626a4ea6b19842089da91b107cc61da234858c7d` | 統合仕様の観測点。新しいコミットで動く |
| GitHub production | `15abdc9de6f24b5250fb255c579d1aa55facaf1a` | 現行本番コード系統 |
| GitHub compare production..main | **278 commits ahead / 0 behind** | files配列はGitHub APIの上限300件で打ち切り。**全changed paths数とpending migrations数は未確定** |
| Render Canovia Web | `srv-dagfn2id0e5s73cac7ng`; Live `15abdc9d...` | Web service branchは**main**、Auto Deploy **OFF**。productionへの切替は未実施 |
| Render MCP Staging | `srv-db43l4nlk1mc73emseig`; Live `1e93b6ce...` | 合成Development専用。独立PostgreSQLとAuth0。実ユーザーデータを渡さない |
| Render Static Welcome | `srv-dahn5k142hec739l0fmg`; Auto Deploy **OFF** | Webとは別の配信線。StaticのLive SHAはリリース直前に独立照会する |
| production PR | [Draft #513](https://github.com/1kz-ma1/Canovia-web/pull/513) `bb8248f...` → production | **OPEN / DRAFT / NO-GO**。2件migration候補。新しいmain候補へ勝手に置換しない |
| GitHub open PR | 今回の検索では #513 のみ | 未統合feature branch/外部変更/保留設計までゼロと推定しない |

旧「210コミット/643ファイル/18 migration」「94→113/19 migration」は**それぞれ当時の固定スナップショット**。現在のfull-history manifestや実Aiven pending migration数として転用しない。

## 4. 決定済み統合方針

- 原則 **integration-first**: 安易なMCP単機能バックポートより、共通の認証/DB/ReleaseLevel/初回導線を整合させ、main→固定release候補→productionへ収束する。
- ただし **一括コードレビュー/検証を省略してmainを即deployしない**。必要なら安全性・依存関係に従い複数のimmutable release候補を切る。古い #513 は独立してNO-GOを維持。
- Publicは当面「PlanなしWelcome → Studyの最初の一問 → Developmentの次Action → Feedback」の審査済み最小価値に集中。MCP実ユーザー連携、Career一般公開、Dev Pro自動変更、G3外部接続、Watch通知、課金は自動公開しない。
- 既存 `FeatureFlagService`、`ReleaseLevelService`、`ReleaseGateService`、Admin Preview、user override、Entitlement、Plan ownershipを**再利用**し、重複した公開制御基盤を作らない。
- 層の契約: **deploy**（コード）→ **environment/feature kill switch**（全体遮断）→ **release level**（actor向け成熟度）→ **entitlement**（契約）→ **owner/resource authorization + separate OAuth/Plan grant**（個別データ権限）。どの上位LevelもOAuthやowner認可を迂回しない。
- 公開ステータス: `internal` / `selected_beta` / `public` のような段階は**設計上のターゲット**。有効な実DB管理UI・percentage rolloutが現在あると推定しない。`config/release_levels.php`の default public_level=L4 InternalPreview は**リリース設定上の要監査事項**。単純にフラグ値を変更せず、匿名・一般・選択Beta・Adminの真の表示/POST/API/直接URLをE2E確認する。
- 既存dataは上位Levelを下げても削除しない。UI/navigation/URL/API/queues・background jobsを揃えて制御する。403/404/Coming Soonを適切に使い分け、認可拒否を誤って「未公開」に置換しない。
- WebがSwiftUI WKWebViewへ配信されることを前提に、Web releaseごとのMac Safari/実iPhone WKWebView/PC/PWA受入を必要とする。Native Swift repoやApp Store配布は別の審査線。

## 5. 実装・統合の作業パッケージ（Codex）

### A. Freeze/manifest・仕様矛盾の解消（本番操作なし）
1. 最新main/production/Render Live/Staging/StaticとPR/branches/CIを再取得。比較はlocal full-history Gitで算出し、files API上限300を完全件数と誤認しない。
2. `docs/README.md`、`ACTIVE_LANE_HANDOFFS.md`、`ROADMAP.md`、WIP、各レーンの最終spec/PRを読み、CURRENT/TESTED/NOT DEPLOYED/FUTURE/UNKNOWNを分類。古いwave rosterは**PAUSED**扱い。
3. inventory: 変更ファイル・migration全件・authorization/session/CSRF/OAuth・worker/queue・feature flags・Web/iOS/public nav、外部プロバイダ連携、実験用/本番可の区分。担当/依存/テスト/rollback/公開状態を1行ずつ記す。
4. 競合・重複・未文書化・missing human approvalを意思決定表へ。歴史的spec/WIPを削除・書き換えず正本へのリンクを整える。

### B. Staged publication/safe defaults（先にCI/隔離環境）
1. 現行Release Level/Flags/Entitlementの実際の評価順序、production effective configでの事故経路を監査する。特にL4 default、認可されないdeep link/POST/外部write、Admin previewの一般ユーザー流出を検証。
2. 最小限の修正PRを分割し、Super Admin-only preview・allowlisted tester・Public L0/L1とMCP/AI/G3のdeny-by-defaultを整合させる。設定値を管理者UIから変える追加は必要性と永続化安全性を仕様確認してから。
3. Feature visibility・server route/mutation権限・セッション復帰・iOS/WKWebView・新規/既存ユーザーの行動導線を一組のE2Eで確認する。権限昇格とデータ消失を負のテストに含める。

### C. Migration/release hardening（実Aiven変更なし）
1. 本番 `docker/render-start.sh` の起動時 `php artisan migrate --force` とリトライ/部分DDLの影響を評価。どの方法で事前承認/適用とアプリ起動を分けるか、互換移行・旧writer fence・forward recoveryと合わせて提案/使い捨てCI検証する。
2. 本番Sourceへの**実際のpending migrationsは未知**。選定SHAを固定し、disposable MySQL 8.0/8.4で旧スキーマ→新スキーマ、再実行、既存データ保持、trigger/uniqueness、旧コード互換/rollbackを検証。94→96 / 94→113用チェックを最新全差分への証明に使わない。
3. Aiven real SELECT-only、effective DDL/trigger privileges、復元可能なbackups、Render env/branch操作、App本番機能有効化は別途owner承認待ちゲートとして表示する。秘匿値をGit/PR/logへ出さない。

### D. Release path・受入（書類とCIまでは実装可能、本番GOは停止）
1. 既存 `main → release/<date>-<SHA> → production` と `production` Merge commit / required MySQL + Workspace + Recovery + Promotion checks契約を維持。SQUASHで本番系統を作らない。
2. 旧 #513 の継続/置換/閉鎖はオーナー判断。新しいrelease候補は**固定main SHA**かつ全差分審査済みで独立PRにする。未承認の機能はflagと権限で遮断。
3. 最初のproductionリリースはAuto Deploy OFFで**オーナーGO後の手動**。Renderの監視ブランチをproductionへ変更するのもオーナー承認事項。CI成功だけでdeployしない。
4. 初回受入後、GitHub `production` protection/owner release approvalを実効化し、`After CI Checks Pass`が**実際のmerge SHA上**のrequired checksを待つことを検証してから別承認で有効化。現在のRulesetはrequired approving review count=0に注意。
5. Render live SHA/health/migration logs/5xx、session/CSRF/権限、Web/PWA/Mac/iPhoneの実測を条件にReleaseLevelを段階的に拡大する。

## 6. Stop / GO boundaries

Codexが承認なく実施できる作業: read-only調査、仕様/CIテストの追加、isolated disposable環境でのテスト、feature/fix branchからmainへの通常PR（既存AGENTSのCI・mergeabilityを満たす場合のmain統合のみ）、その証拠の整理。

Codexが**独断で行わない**作業: `production`へのmerge、Renderのservice branch/ENV/Auto Deploy/手動デプロイ変更、Aiven実接続/DDL/backup/restore、OAuth/IdP/ChatGPT client権限変更、個人Planの実読み込み、外部AWS/S3 provider操作、課金/有料upgrade、App Store配布、本人確認・同意のバイパス、権限や公開範囲の実本番ON、既存feature/WIP/古いrelease PRの無承認削除。

失敗/不明/未受入時の既定: HOLDして証拠を提示。失敗したmigrationを自動rollbackしない。レビュー・CIが不足ならPRを開いたままにする。

## 7. 成果物/受入基準

Codex実行後に次を残す（正本へのリンク中心、長文の重複は避ける）:
1. freeze snapshot: main / production / Render live / candidateのSHA、open PR・branch inventory、正確な変更パスとmigration全件、stale-doc index。
2. decision register: 未公開/公開候補/延期、リスク、Owner意思決定待ち、担当と依存、仕様差分。
3. Feature exposure contractとserver-side deny/actor override/rollback E2E（デフォルト非公開、データ保存、MCP/G3境界）。
4. migration+CI/release candidate packet: exact-SHA disposable verification、Aiven operator-only gate、recovery手順、正式なmanual GO/NO-GO一覧。
5. 分割PRリンク・main merge SHA・実行したCIの成功/失敗/未実行、Render Live status・実機未検証事項、Codexが**停止した承認境界**。
6. 新規実装レーンの再開条件（例: 初回限定公開受入/次回release CIの安定）と、main/liveの差分を抑制する継続release cadence。

**本書を満たすことはそのまま本番GOではない。** Ownerのレビュー可能な資料と変更を揃え、安全な昇格を選択できる状態を完了点とする。

# AP学習優先：普段のCanoviaアカウント → ChatGPT本人限定MCP先行接続

2026-10-18 JST。**ACTIVE engineering scoping / implementation NOT YET WRITTEN.** 先行版候補の安全な切り分け用。production GO、既存候補の置換、Aiven/Render操作の承認ではない。

## 0. 今回のユーザー判断
一般公開・全UI統合より、**既存の普段使いCanoviaアカウントのAP学習PlanをChatGPTから参照できること**を先に進める。AP科目Aの学習で利用するため、初回は私的オーナー1名のPlan1件、`overview`のみ、最長1日の明示同意と取消確認。必要になればTasks、学習実績、Learning Context Snapshotを**別承認・別同意・別の実装評価**で追加する。現段階では学習量・弱点・答案は渡さない。概要接続だけで学習支援が完成したと誤認しない。

## 1. 確認済みの固定根拠

| 項目 | 観測・含意 |
| --- | --- |
| Render production | 2026-10-18観測、サービス `srv-dagfn2id0e5s73cac7ng`、Live `15abdc9de6f24b5250fb255c579d1aa55facaf1a`、Auto Deploy off。本番設定の実効値・実Aiven schemaはまだ未確認。 |
| 開発 main | 固定スナップショット `4e3a6e97949602409ea337290c4d4f2052fda998`。この文書の比較基準であってproduction候補ではない。 |
| 認証 | オーナー報告：別のproduction Auth0 tenant `canovia-mcp-prod`、GitHub social接続、専用first-party/ChatGPT第三者クライアント、限定read delegated grant、owner-only Post Login Action Apply、domain-level GitHub接続、S256/RFC9207 discovery。実JWT/callback/実際の拒否は未受入。 |
| 本番DB | 2026-10-13のオーナーMac read-only監査：当時の候補で94 applied / 2 pending。2026-10-17のAiven Full backupは存在、**別ターゲットへの復元未検証**。Free plan Fork unavailable。 |
| 現行main MCP | `McpReadOnlyStudyContextTool` + `McpStudyPilotAccessPolicy`、RS256/JWKS verifier、S256/GitHub-only link、locked per-Plan grants、失効・取消が実装。現本番にはStudy tool/Auth0-JWT verifierがない。 |
| 既存release | `release/2026-10-16-641e2105` = `641e2105...` は20追加migrationを含む包括候補 **HOLD**。Draft #513もNO-GO。既存の#488、#783、AP接続ゲートを再利用、重複するproduction実施許可票を作らない。 |

## 2. 技術判断：先行版は「本番旧版ベースの選択的バックポート」の可否から始める

**結論：0追加DDL版は現時点で候補であり、実装成立・安全性は未証明。** 既存mainをそのまま本番反映すると、MCP以外を含む20追加migration・startup schema-ready gate・既存大規模差分の共通release審査が必要になる。MCP専用に解消したという主張はできない。

優先候補A：本番Live `15abdc9...` の旧94 migrationスキーマと実装を土台に、Auth0のsigned JWT verification / GitHub subject validation、本人限定AP Study Plan読取、scope=overviewだけの明示同意・取消を**最小限選択移植**する。別の固定`release/ap-mcp-...`候補・専用testsを作成する場合も、既存mainとproductionブランチへの変更は別ゲート。既存のstaging synthetic fixtureを実データ用に流用しない。

Aの成立を判定する差分/依存チェック：

1. **既存スキーマ適合**：旧schemaの`mcp_linked_subjects`、`mcp_delegated_grants`、`mcp_delegated_access_events`、`plans`、`tasks`だけを使用できるか。追加migrationファイル・`migrate --force`・`active_identity_fingerprint`・NULL `expires_at`・結果提案/学習Contextの新規表に依存しないこと。旧DBの`identity_fingerprint`全体UNIQUEは保持し、他アカウントとの再紐付けを許さない保守的な契約にする。
2. **JWT verifier**：`McpAuth0JwtTokenVerifier`、`McpJwtClaimConstraints`、`McpExternalTokenVerifier`の依存とcomposerライブラリ互換性を実確認。Auth0 RS256/JWKS、`iss`、単一正規resource audience、実発行`azp/client_id`、S256、`sub=github|数字`、read scope、`iat/nbf/exp`最大3600秒をチェック。署名を検証しないトークン/メール照合/IdP暗黙トークンの流用は不可。
3. **2つの独立client**：first-party confidential account-linkとChatGPTの`tpc_`実クライアントを別設定。RFC9207の正しい成功/エラー`iss`、CIMD URL/callback、正確なresourceとredirect、Auth0 User-Delegated permissionを確認。`connection=github`の指定だけで本人と断定せずJWT主体とAction実動作で確認。
4. **オーナー/Plan fencing**：単一Canovia数値owner IDをserver allowlist。本人が既存のprivate Study Plan IDを**自分で**選ぶ。非study、他人/共同Plan、過去grants、任意Plan列挙を拒否。Auth0 Actionは他clientを拒否する必要があり、App-levelだけに依存せず各resource callでもowner + Plan grantを独立検査。
5. **同意と取消**：`overview`かつ1日だけ、本人のsessionと5分のfresh PKCE再認証・別CSRF確認が必要。`tasks`、`until_revoked`、`study_progress`、`learning_context`、writeは一切受け付けない。従来の`McpExplicitPlanConsentService`はdevelopment-only、新版は概要同意でも`mcp_study_result_proposals`へUPDATEするため**無改変移植は禁止**。旧表未導入でも動く独立した同意分岐を設計し、再同意時にgrant旧権限・取消を復活させない。本人による取消/リンク解除で同じ未失効tokenが直後に拒否される。
6. **ツール公開境界**：`tools/list`は`canovia_get_study_plan_context`のみ（AP pilot ON時）、`scope=overview`のみ受理。返却は`title`（最大160文字）と本人記録の`recorded_deadline`だけ。Tasks空、学習実績・回答・説明・Evidence・プロフィール・Development toolは出さない。既存Webへの影響と旧ルート名/route cacheも静的照合。
7. **運用**：productionのOAuth/MCP flagは全OFFが初期状態。候補には明示独立owner-only switchとkill switchを置き、誤設定・欠落・混合モードでdeny。既存`APP_KEY`、Laravel session、Cookie、DB accountを変更しない。秘密・実個人ID・Plan ID・tokenはIssue/CI/ChatGPTへ掲載しない。

### 先行案AのSTOP条件

- 旧94 schemaの実物との適合またはmigration 0が証明できない。
- 本番の旧コードと切り出しコードの関係が複雑になり、既存Core/Auth/Planの回帰を評価できない。
- Auth0の実認証/本人拒否・token署名/クライアント一致が検証できない。
- 無料Aivenで別環境への信頼できる復元手段が確保できず、ownerが復旧リスクを受け入れられない。
- 本番旧writerや起動時migration、owner限定にできない共有リンク/Development許可、grant取消不備が残る。

いずれかでSTOPなら、**Aを無理に最適化せず、包括候補B（20 migrationの安全な隔離復元・検証を経た#488 release）の中でMCP本人接続を最優先**にする。A/Bどちらでも実Canoviaデータの送信は別承認後。

## 3. 最初に行うGit実装スライス

- S1：固定Live SHA基準でMCP/認証依存の厳密なPHP/Composer/route/UI/DBテーブル・列のリストを作る。旧94スキーマで成立する**具体的なファイル差分**を決める。勝手なmigration 1件追加も禁止。必要ならS1でAをNO-GOと判定する。
- S2：最小認証・owner/Plan guard・概要同意・MCP readだけの独立実装と専用テスト。誤config/拒否/失効/再認証レース/未知Planへの否定を先に赤→緑。
- S3：SQLiteだけでなくDisposable **MySQL 8.0/8.4へ本番旧94 migration**を適用し、候補をDDL無しで実行・既存app互換E2Eを完走。今の全main CI成功を旧94候補の成功として代用しない。
- S4：Auth0だけを使うOAuth実通信受入、実owner/non-owner認証制御、署名JWT callback、失敗分類。実Canovia本番データには接続しない。
- S5：固定候補 SHA・release branchルール・owner専用限定公開・Render起動契約・既存Aivenバックアップ/復元ルートを確認。別途オーナー明示GO後の本番反映（デフォルト全OFF）。新Web初回login後もPlan共有・readはOFF、さらに別同意・本番OAuth証明と別GOでAP Plan1件`overview`を開通。最後に取消と同一token再read拒否。

## 4. 受入条件と現在の状態

| 条件 | 今 |
| --- | --- |
| Aの正確な移植ファイル・DBテーブル互換性・実行可能な差分 | **未実装/未確認** |
| 追加migration 0で旧94件MySQLテストPASS | **未実施** |
| Auth0で本人以外を拒否する模擬Actionテスト | オーナー報告済み、実OAuthフローは未実証 |
| 実Canoviaアカウント/ChatGPT OAuth同一issuer+subject | **未確認** |
| private Study Plan1件・1日overview consent/read | **未実施** |
| 他人/他Plan/tasks/unknown client deny、取消後deny | **合成検証予定、本番未実施** |
| Aiven別ターゲット復元・運用rollback、iPhone/Web実機 | **未検証/NO-GO** |
| productionに適用/一般公開 | **一切承認なし** |

関連正本：[Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488)、[既存MCP owner準備Draft #783](https://github.com/1kz-ma1/Canovia-web/pull/783)、[実アカウント接続ゲート](MCP_REAL_ACCOUNT_CONNECTION_RELEASE_GATES_2026_10_14.md)、[AP本人限定ゲート](MCP_AP_REAL_OWNER_PRIVATE_PILOT_GATE_2026_10_15.md)。本票は`owner-mcp-production-read-preparation.md`の包括候補とは**代替案**であり、旧版へのMCP部分移植を承認したものではない。

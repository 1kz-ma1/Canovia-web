# 本人の本番AP Plan → ChatGPT：概要のみの準備票 / HOLD

2026-10-17 JST（Git観測: 2026-10-16 16:39 UTC）。**調査・準備PRのみ。本番操作・実データ読取の承認ではない。**
正本は Issue [#488](https://github.com/1kz-ma1/Canovia-web/issues/488)、[既存の実アカウント接続ゲート](../../../MCP_REAL_ACCOUNT_CONNECTION_RELEASE_GATES_2026_10_14.md)、[AP本人限定ゲート](../../../MCP_AP_REAL_OWNER_PRIVATE_PILOT_GATE_2026_10_15.md)。本票はそのオーナー操作差分で、別のロードマップを作らない。

## 1. 固定した調査範囲と実装差分

| 対象 | 今回の根拠・限界 |
| --- | --- |
| 本番Live基準 | オーナー指定・既存証跡の `15abdc9de6f24b5250fb255c579d1aa55facaf1a`。GitHub productionも一致。本番Renderへの再接続はしていないため、現在Liveの独立した再観測ではない。 |
| 最新main観測 | `4e3a6e97949602409ea337290c4d4f2052fda998`。調査の固定スナップショットであり、リリース候補への採用ではない。 |
| 既存固定候補 | `release/2026-10-16-641e2105` = `641e2105efccf6b1c2f13c3013eae0c638e5aa9d`、引き続き **HOLD**。本票による更新・採用・承認なし。 |
| 現アカウント表示 | オーナー報告の「外部認証未設定／共有設定なし／外部IDなし／MCP読取無効」を使用。サーバーの実効設定・本人データ・接続成功を確認した証拠ではない。 |

Live基準にも PKCE本人紐付け、明示Plan同意、RFC7662検証、Development読取のコードとMCP基礎テーブルはある。最新mainは Auth0 RS256/JWKS検証への明示切替、GitHub primary subject強制、失効・再紐付け安全性、Study専用の本人許可制読取、別同意の集計／Context／書込提案を追加している。**LiveにはStudy読取toolもAuth0 JWT経路もなく、フラグだけでは使えない。**

必要な読取経路は次の既存実装を再利用する。今回のランタイム修正・新規migrationは **0**。

| 依存 | 既存コード / 確認点 |
| --- | --- |
| OAuth境界 | `McpProtectedResourceConfiguration`、`McpExternalTokenVerifier`、`McpAuth0JwtTokenVerifier`、`McpJwtClaimConstraints`。信頼したissuer/JWKS、署名、resource、実発行client、read scope、有効期限を検証。JWTは最大3600秒。 |
| Canovia本人紐付け | `McpOAuthAccountLinkConfiguration/Provider/Committer` と対応Controller。ログインsession＋CSRF＋PKCE＋5分以内の別確認。メール一致で紐付けない。 |
| Plan同意と所有権 | `McpExplicitPlanConsentService`、`McpDelegatedPlanAccessPolicy`、`McpStudyPilotAccessPolicy`。private Study、正しいowner、外部subject、client/resource、現時点のscope/期限を再検査。Plan/link/grantをロック。 |
| 概要・Task | `McpReadOnlyStudyContextTool`、`McpReadOnlyResourceController`。明示Plan IDのみ、列を限定。取消は `McpDelegatedRevocationService` とAccount UI。 |

比較コマンド（Gitのみ、外部サービスやDBには接続しない）:

```sh
python3 scripts/ci/mcp_ap_private_pilot_release_inventory.py \
  --baseline 15abdc9de6f24b5250fb255c579d1aa55facaf1a \
  --snapshot 4e3a6e97949602409ea337290c4d4f2052fda998 \
  --output /tmp/canovia-owner-mcp-source-inventory.json
```

結果: 全体979パス（追加752、変更227、削除0）、追加migration **20**、既存migration変更0、`HOLD / release_authorized=false`。これはMCPだけの変更件数ではない。
`git diff 641e2105..4e3a6e97 -- config/canovia_mcp.php 'app/Services/Mcp*' 'app/Http/Controllers/Mcp*' database/migrations docker/startup-migration-policy.sh` は空。関連実装を新候補へ載せ直す必要はないが、他の共通コードまで同一という主張ではない。

## 2. 今回許可するデータと公開境界

**第A段階は本人が選ぶ既存AP Study Plan 1件、`overview`、推奨1日だけ。** 返すのはタイトル（最大160文字）・本人が記録した期限。`tasks=[]`、完了は未検証。Plan一覧検索、問題・回答・思考・採点・学習実績・説明・証拠・プロフィール・書込を含めない。

- `CANOVIA_MCP_STUDY_SUMMARY_READ_ENABLED` は「Plan概要」ではなく **学習実績集計** 用。今回はfalse。Context Snapshot・結果提案もfalse、Auth0に `canovia.study.write` を付与しない。
- Task取得成功は **第B段階の別承認・新しいOAuth本人再確認・`tasks`への再同意後だけ**。Aの受入ではTask要求が拒否されることを確認する。Bを未承認のまま実データTask取得を試さない。
- 本人制限は `CANOVIA_MCP_AP_PILOT_OWNER_ID` の単一正整数のCanovia user ID。空・不正・他人はStudy同意/読取を拒否する。Plan IDやAuth0/GitHub IDを入れない。対象Planは本人が正規UIで選び、他Planのgrantを作らない。
- 一般L0＋本人に明示L1の方針を維持する。L1はStudy Workspaceの入口条件。Entitlement/各機能フラグ/所有権も別条件であり、L1・管理者・ChatGPT共有準備設定・外部ID紐付けのどれもMCP同意の代わりにならない。
- **MCP APIに `release.level` middlewareはなく、delegated policyもL1を毎回検査しない。L1取消・一般L0だけでは既存MCP grantを失効させられない。** MCPの停止にはPlan grant取消／外部ID解除／MCP read停止が必要。
- 本人allowlistは **Study用**。共通Account link、Development同意・toolまで本人専用にはしない。ONの共通フラグを本人限定の保証と誤認しない。本番Auth0で当該2クライアントへの本人以外の入場拒否が実現・検証できることを独立した必須ゲートにする。実現できない場合は、サーバー側の共通本人制限を別の安全性PRで設計・検証するまでSTOP。一般ユーザー向け案内・公開・新規grant配布は行わない。

## 3. オーナーが私的に確定する設定台帳（現在は全て未適用）

`H` = 別途承認した本番HTTPS origin（末尾 `/` なし）。既存証跡のホストは `https://pacekeeper-d3mm.onrender.com` だが、設定時に正規originを再確認する。
`I` = **本番専用**Auth0 issuer（末尾 `/` あり）。`canovia-mcp-dev`、Staging plugin/DB/秘密値を流用しない。

| 場所 | 将来の設定・確認 |
| --- | --- |
| Auth0 tenant / GitHub | 本番tenant、地域・保持方針・利用枠・料金・本人限定入場を承認。GitHub social connectionを明示し、必要な本番用GitHub OAuth appのcallbackは **Auth0が表示するもの** を使用。Canovia callbackと混同しない。dev keysやメール突合せは使わない。 |
| Auth0 API | Identifier = `H/api/mcp`、RS256、短命access token（3600秒以内）、User-Delegated Accessの `canovia.development.read` のみ。名前はlegacyだがStudy概要もこれを使う。M2M・無制限third-party許可・write permissionは不要。 |
| Canovia用Auth0 app | 別のconfidential client、Authorization Code + S256 + `client_secret_basic`、callback **`H/account/mcp/link/callback`**。このclientにも必要なread API許可を個別確認。 |
| ChatGPT用Auth0 app | 別の非公開接続から提示されたCIMDを登録/確認。strict third-partyなら実発行内部 `tpc_…` clientを私的に確認。Canovia用client/secretを渡さない。callbackはChatGPT表示とRFC9207実挙動の双方で決め、推測・ワイルドカード禁止。 |
| Render URL群 | `CANOVIA_MCP_RESOURCE_URL=H/api/mcp`、`CANOVIA_MCP_OAUTH_ISSUER=I`、`CANOVIA_MCP_ACCOUNT_LINK_METADATA_URL=I.well-known/openid-configuration`（Iと文字列連結）、`…AUTHORIZATION_ENDPOINT=Iauthorize`、`…TOKEN_ENDPOINT=Ioauth/token`、`…REDIRECT_URI=H/account/mcp/link/callback`。実discoveryとの一致を確認。 |
| Render client/鍵 | `CANOVIA_MCP_ALLOWED_CLIENT_ID` = 実トークンで確かめたChatGPT内部client。`CANOVIA_MCP_ACCOUNT_LINK_CLIENT_ID/SECRET` = 別のCanovia client。`CANOVIA_MCP_IDENTITY_FINGERPRINT_KEY` = 新しい専用高エントロピー秘密（32文字以上）。既存APP_KEY/session/cookieを維持し、fingerprint keyを日常的に回転しない。 |
| 検証方式 | `CANOVIA_MCP_TOKEN_VERIFICATION_DRIVER=auth0_jwt`。承認段階で `CANOVIA_MCP_AUTH0_JWT_VERIFICATION_ENABLED=true`、`CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED=false`。同時ON・検証緩和は禁止。 |
| 本人 | `CANOVIA_MCP_AP_PILOT_OWNER_ID` は正規の本人確認済みfirst-party手段で得た数値。現Account画面に数値表示があるとは確認していない。取得手段がなければ別途許可した運用手順が必要。推測・URL総当たり・公開Issueへの記載をしない。 |
| 一般公開/不要機能 | `CANOVIA_PUBLIC_RELEASE_LEVEL=0` と本人だけの明示L1許可を別途承認。`CANOVIA_MCP_STUDY_SUMMARY_READ_ENABLED=false`、`CANOVIA_MCP_LEARNING_CONTEXT_SNAPSHOT_ENABLED=false`、`CANOVIA_MCP_STUDY_RESULT_PROPOSALS_ENABLED=false`。本番でStaging synthetic bypassやsealed-wiring startup検査を使用しない。 |

**OAuth実通信の未達ゲート**: production issuer discoveryのS256/RFC9207、成功/エラー両callbackの正確な `iss`、2クライアントの同じ不変 `issuer+sub`（GitHub primary）、実署名/鍵更新、`aud`/`azp`または`client_id`/read scope/`iat,exp,nbf`、再認証/refresh、誤アカウント拒否。トークンはオーナーの私的検証でのみ扱う。ChatGPT clientでは不要なOIDC scopeを要求しない構成を優先し、未検証の互換性を本番で緩和しない。

RFC9207対応時の安定callbackは `https://chatgpt.com/connector_platform_oauth_redirect`、CIMDは `https://chatgpt.com/oauth/client.json`。非対応時はChatGPTが表示する接続固有URI。詳細は[既存callback/scope契約](../../../MCP_CHATGPT_AUTH0_CALLBACK_SCOPE_ACCEPTANCE_2026_10_13.md)。最新JWT実装は正確なresource単独に加え、署名済み `openid` tokenの **resource＋issuer配下userinfoの厳密な2要素だけ** を認める。古い文書の「常に単一aud」説明ではなく `McpJwtClaimConstraints` を確認し、任意audienceやIdPトークンを許可しない。

## 4. DB・起動・復旧は #488 の共通ゲートを再利用

- [20 migration用オーナー操作票](owner-l0-l1-operations.md)をそのまま正本にする。旧18/19件のAP在庫・固定19件検査を流用しない。641e2105も本main観測もsource94→114、追加20件は同一。**実Aivenの適用済み数・物理schemaは未確認**。
- MCP直接依存は既存 `mcp_linked_subjects / mcp_delegated_grants / mcp_delegated_access_events` と、`2026_10_14_000100_allow_nonexpiring_mcp_grants.php`、`2026_10_14_000200_preserve_historical_mcp_identity_links.php` の列・制約。有限1日grantでも新コードはactive fingerprint列を要求する。非期限grant・履歴保持の安全なdown拒否を無視しない。
- Plan/memberのauthorization epoch / immutable binding trigger、既存DB・認証・session・Feature/Release/Entitlementは共通依存。集計・write提案用tableがこの概要読取に不要でも、**統合releaseは全20件を監査・適用し114 ledger整合を要求**。MCPだけを選択適用するリリースは準備・承認していない。
- 既存のMySQL8.0/8.4 94→114、再実行、synthetic復元・session証跡を再利用。新コードの通常起動は `CANOVIA_STARTUP_MIGRATIONS=check`。実際のpendingだけを、全旧writer（Web/worker/別instance）停止・バックアップ隔離復元・明示承認後に事前適用する。`apply`経路は実行runtimeの40桁SHAと承認SHA一致・一度のみ。候補source SHAと後のproduction merge SHAを混同しない。
- 失敗・部分DDL・旧writer不明ならSTOP。自動retry/down/旧イメージ復帰をせず、物理状態と互換コードを確認し、承認済みroll-forwardまたは整合したDB＋アプリ復元を選ぶ。秘密鍵・APP_KEYと同意履歴を保持。バックアップ存在だけでは復元成功/RPO/RTO証拠にならない。
- 既存PHP全体失敗は未解消。初期129件や今回84件の成功を全suite成功に読み替えない。#488の既存失敗分類と本番阻害判定、実機受入は引き続き必要。

## 5. 将来のオーナー操作順（各段階で別の明示承認が必要）

以下は実行済み手順ではない。操作の承認には対象service/tenant/client・exact SHA・設定差分・費用を含める。

| 順序 | オーナーが行うこと | STOP条件 |
| --- | --- | --- |
| 0 — 判断 | #488の候補/20 migration監査・隔離復元・既存失敗・実機ゲートを確認。対象本人とPlan1件、1日の範囲、ChatGPTへの送信/保持方針、費用上限を私的に確定。641e2105/HOLDを自動GOにしない。 | 未監査/移動SHA、復元不可、本人/Plan/費用不明、一般L0影響未承認。 |
| 1 — Auth0 / ChatGPT準備 | 承認した本番tenant/API/GitHub connectionと独立2クライアントを準備。ChatGPTに**別の本人限定・非公開**接続を準備し、表示CIMD/callbackをAuth0に正確に登録。既存Staging pluginは変更しない。まだPlanを読まない。 | 本人限定入場を証明できない、client/callback/issuer不一致、未承認課金、登録手段/プラン非対応。 |
| 2 — #488 release | 明示承認後にwriter fence→backup/restore確認→監査したpending事前適用→114/schema確認→承認済みreleaseのproduction merge commit→specific commit手動deploy。通常起動check、Auto Deploy OFF。MCP switchesは全てOFFのままWeb/iPhone受入。 | DDL失敗、古いwriter、実行SHA違い、起動/認証/既存データ不整合。MCP目的で共通ゲートを省略しない。 |
| 3 — Render設定 | 別承認後、上のURL/client/秘密を対象本番serviceに設定。本人IDを設定し、discovery・auth0_jwt verification・account_link・github_only・plan_consent・delegated_policy・study_readをON、**toolsはOFF**。集計/Context/write/IntrospectionはOFF。正規HTTPS/metadata、401等の期待応答、一般L0・本人L1を確認。 | env保存でmoving mainがdeployされる可能性、設定だけで勝手に再deploy、canonical origin違い、secret漏洩、意図しないtool読取。保存が起こすdeployも事前にexact SHAへ拘束できないなら変更前にSTOP。 |
| 4 — Canovia本人 | 通常の本番アカウントへlogin→AccountでGitHub本人確認→正しい紐付けを別確認→既存private AP Study Planを自分で選択→`計画名・期限のみ`/1日→新鮮なOAuth再確認→previewを確認して最終承認。共有準備設定だけでは不足。不要な他Plan grantがないことを本人UIで確認。 | 別GitHub/Canoviaユーザー、共同/別カテゴリPlan、期限/範囲/プレビュー違い、未確認の古いgrant、本人IDを取得できない。 |
| 5 — 読取開通 | 別承認したexact SHA/flagsで `CANOVIA_MCP_TOOLS_ENABLED=true`。ChatGPTの新しい非公開接続で **同じGitHub primary subject** をOAuth認可。readのみ再発行・tool再検出。本人が明示した1件に `overview` を依頼。 | 署名/issuer/aud/client/scope/期限/subject未検証、write/集計/Context tool出現、不要scope要求。configを緩めず停止。 |
| 6 — A受入/取消 | 下表Aを実施し、最後にCanoviaでPlan共有を取消。同じ既存ChatGPT接続で再読取し拒否を確認。継続利用は本人が再同意する。保存ログには時刻・SHA・固定pass/deny分類のみ。 | 取消後read成功、Taskや他Plan漏洩、既存学習実績/Task進捗変更、秘密を記録。 |
| 7 — 任意のB | **今回の初回範囲外**。Task拡張を別承認後、同じPlanの`tasks`を新しい本人再確認で再同意し、Bを受入。 | A未合格、追加同意なし、他Planへの拡張、8件超/非許可列出力。 |

Accountの「紐付け済み」「grant有効」「ChatGPT側OAuth接続」は別状態。Canovia UIの保存成功だけで接続完了と扱わない。

## 6. 受入記録（実アカウントは全て未実施）

| 段階 | 操作 | 合格条件 |
| --- | --- | --- |
| A 正しい本人 | 自分が指定した1件の `canovia_get_study_plan_context`、`scope=overview` | title/recorded_deadlineのみのPlan projection、tasks空、completion未検証。問題/回答/成績/Task本文なし。業務データ更新なし（同意・読取監査のmetadata保存はある）。 |
| A scope拒否 | 同じtoken/overview grantで`scope=tasks` | `result.isError=true`、Task内容なし。HTTP200だけで成功と判断しない。 |
| A 他人/他Plan拒否 | **隔離合成環境**で他ユーザーtoken/別owner Plan/別client等を使う。将来の本番は別途承認したテスト主体のみで、private Plan総当たりはしない。 | 不正tokenは401、不認可Planは内容なしのtool error。存在/タイトルを明かさない。合成試験と本番の観測結果を別欄に残す。 |
| A 取消後拒否 | 本人UIでgrant revoke後、まだ期限内の同じChatGPT接続で再要求。別途unlinkも確認。 | 後続呼出しに内容を返さない。unlinkは当該subjectの全grant失効。再linkしても古いgrant復活なし。既にChatGPTへ渡った情報は取消で回収されないため、会話/接続の削除・保持設定はChatGPT側でも確認。 |
| A 非公開/無書込 | 一般ユーザーのStudyアクセス・Auth0入場拒否、tool一覧とscope、既存Plan/Task状態を確認 | owner Study fence維持、一般向け公開なし、書込/集計/Context機能なし、Task進捗・Attempt・実績不変。Development読取toolの定義は共通実装上残るが、対象外のgrantを与えずデータ読取を拒否。 |
| B Task（別承認） | 同じ1件に新たな`tasks`同意後だけ、Task要求 | doing/todo/pausedの最大8件、id/title/status/recorded_progress_percentのみ。説明・証拠・問題・回答・採点なし。その後取消して再拒否。 |

緊急時は **本人によるPlan grant取消→必要なら外部ID解除** を先行できる。別途承認した運用でtools/study_read OFF・discovery/新規link/consent停止を行い、既存tokenで拒否を確認する。フラグOFFだけではgrantレコードを消さず、再ONで有効grantは再び使えるため、取消も記録する。トークン無効化だけに頼らず、Auth0側の事故対応も別途実施。復旧の再ON/再同意は新しい判断で、一般公開を拡大しない。

## 7. 検証・残存ゲート・費用

- 既存環境を再利用。上記mainと同じランタイムで関連PHP **84件 / 1,207 assertions成功**（`McpReadOnlyResourceTest`、`McpExplicitPlanConsentTest`、`McpOAuthAccountLinkTest`、`McpDelegatedRevocationTest`、`McpAuth0JwtTokenVerifierTest`、`McpAuth0JwtProviderRoutingTest`、`McpJwtClaimConstraintsTest`）。SQLite合成fixture・HTTP mockであり、本番token/Planを使わない。
- 既存実DBロックCI [38663776736](https://github.com/1kz-ma1/Canovia-web/actions/runs/38663776736) は `be834268a165b9d910a1f55098f4192fd76d3b45` でPostgreSQL17/MySQL8.0/8.4の3job成功。過去証跡として再利用し、今回のexact main・実Auth0・Aiven・全競合順序まで検証したとは扱わない。[#488共通証跡](README.md)とproduction token/本人入場/ログ最小化・監視/即時取消/実機受入の未達ゲートは残る。
- UI/ランタイム変更がないためブラウザE2Eは新規実行していない。過去のChatGPT E2Eは**合成Development Stagingのみ**。実AP本人接続・物理iPhone/WKWebView・本番read/revokeは未実施であり、上のオーナー受入が必要。
- **今回の外部契約・本番リソース・設定変更・追加課金は0。将来の追加費用ゼロは保証できない。** Auth0 FreeのMCP/CIMD・2client・本人制限・ログ/保持・利用枠を本番tenantで再確認（[価格](https://auth0.com/pricing)）。ChatGPTの非公開OAuth接続機能と現契約/管理者権限、Renderの固定SHA設定/実行機能、Aivenのbackup・隔離restore/fork保持/ストレージに費用があるかをオーナーが確認する。新serviceは必須と決めず、見積/有料化が必要なら購入・作成前にSTOP。

本票だけで未達ゲートを閉じない。production/Render/Aiven/Auth0/実データ/一般公開/Draft #513は変更せず、新候補も自動承認しない。

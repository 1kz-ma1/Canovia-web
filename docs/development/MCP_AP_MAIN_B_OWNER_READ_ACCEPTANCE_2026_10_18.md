# B案 AP本人連携：実装差分・段階受入 / Draft・NO-GO

2026-10-18 JST。対象は最新main観測 **`4e3a6e97949602409ea337290c4d4f2052fda998`**、production/既存Live基準 **`15abdc9de6f24b5250fb255c579d1aa55facaf1a`**。正本 [#787](https://github.com/1kz-ma1/Canovia-web/pull/787) / [#488](https://github.com/1kz-ma1/Canovia-web/issues/488) / [実アカウントgate](MCP_REAL_ACCOUNT_CONNECTION_RELEASE_GATES_2026_10_14.md)。全体manifest・20 migration・起動/復旧は独立[Draft #788](https://github.com/1kz-ma1/Canovia-web/pull/788)を参照。main/productionや既存641e2105/HOLDを動かさず、新候補採用なし。

## 最小修正・旧A案との違い

- 旧A案 #785/#786は94件限定のバックポート/政策検査であり、Bへcherry-pickしない。mainにはJWT/PKCE/同意・取消/Study/richer Contextが既にあるため再実装しない。
- **再現した不足:** 既存mainのAP本人policyは `is_public` を見ず、既存grant付きStudy Planがpublicへ変わっても許可した。新しい合成テストは修正前に1件失敗。Bの共通policyでprivateかつnon-collaborativeの明示falseを要求し、欠けた属性も拒否する。新scope/公開/書込基盤は作らない。
- 同意前・OAuth確認後から最終確定までのpublic化・既存grantでの4読取projectionを拒否する。DBロック下で権限を再確認し、public/共同化/owner変更がcommitした後の読取も拒否する。拒否時に内容やcontext_read監査を増やさない。
- 本人pilotのAccount案内をStudy入口へ向け、本人が確認中/有効な自分のPlanだけのIDを表示。AccountのPlan relationはdomain/public/collaborativeも明示取得して判定する。foreign/失効/public PlanのID案内を出さず、「保存＝ChatGPT接続済み」「常にMCP無効」という誤表示を避ける。通常DevelopmentとStagingの既存境界は維持。
- A案はStudy-only tool一覧、mainはDevelopment tool定義も残る。**定義の存在はデータ許可ではない**。今回のpilotでDevelopment/他Plan grantを配らず、Auth0の本人限定入場も独立受入する。Study allowlistを共通Account連携全体の制限だと扱わない。

## 証拠の分類（本番の本人APデータ受入は全てNOT_RUN）

単一の「完了」で実装と実環境を混同しない。下表のSYNTHETIC_TESTEDは既存の対応テストと今回の対象CIを使い、最新exact headの成否はPR Checksに固定する。失敗/未実行のjobを成功へ格上げしない。

| 機能 | ソース | 合成の根拠 | 実AP本人 / 未達 |
| --- | --- | --- | --- |
| Auth0 RS256/JWKS/issuer/aud/client/scope/clock | IMPLEMENTED | SYNTHETIC_TESTED: `McpAuth0JwtTokenVerifierTest`, `McpAuth0JwtProviderRoutingTest`, `McpJwtClaimConstraintsTest` | NOT_RUN: 本番tenantの実JWT/鍵更新/client/ChatGPT互換性 |
| GitHub primary subject・PKCE・別clientの紐付け | IMPLEMENTED | SYNTHETIC_TESTED: `McpOAuthAccountLinkTest`, routing tests | NOT_RUN: 実GitHub本人一致、2client、callback/RFC9207 |
| 明示Plan同意・scope変更・期限・取消/再link | IMPLEMENTED | SYNTHETIC_TESTED: `McpExplicitPlanConsentTest`, `McpDelegatedRevocationTest` | NOT_RUN: 普段の本番Account、実端末、実tokenでの取消 |
| 本人allowlist/private Study/別人・別Plan拒否 | IMPLEMENTED（public fenceを限定修正） | SYNTHETIC_TESTED: `McpReadOnlyResourceTest`、consent負例。実DBロックは `McpDatabaseLockContentionTest` のMySQL8.0/8.4/PostgreSQL17 matrixで確認 | NOT_RUN: 本番本人のprivate Plan選択、Auth0本人限定入場 |
| overview / tasks | IMPLEMENTED | SYNTHETIC_TESTED: ResourceTestのscope別出力・最大8件・非許可列拒否 | NOT_RUN: まずoverviewのみ受入、Task成功は次段階 |
| 承認済み演習集計 / Learning Context Snapshot | IMPLEMENTED | SYNTHETIC_TESTED: ResourceTestの`study_progress`/`learning_context`独立同意、未適用/未承認除外、情報最小化 | NOT_RUN: 実データ有用性・本人同意。誤答傾向/復習案は暫定、検証済み習熟としない |
| 緊急OFF・既存bearerで取消後拒否 | IMPLEMENTED | SYNTHETIC_TESTED: ResourceTestのtools OFF/再ON、local grant revoke/unlink、token検証中の取消 | NOT_RUN: 実Render操作とChatGPT再要求、可観測性/incident運用 |
| 旧本番94台帳・P0対象schema | 既存source | 今回114の合成検証とは別 | REAL_ACCEPTED（owner提出の限定結果のみ）: 94/94、pending0、対象metadata一致。独立再接続なし |
| 将来の一般公開・Study専用OAuth scope・自動更新 | FUTURE / 今回対象外 | NOT_RUN | 未承認。write提案・課金・Dev ProもOFF |

既存ChatGPT Stagingの実OAuth/read/revoke証跡は**合成Development Planの受入**であり、上表の本番AP REAL_ACCEPTEDには含めない。

## 本人が広げる順序（コード配備と共有許可は別）

全段階で独立した本番Auth0/第一者confidential client/ChatGPT client、厳密なissuer/resource/実発行client、GitHub primary `issuer+subject`、owner ID、private Study Plan1件、fresh PKCE＋最終同意を要求する。read OAuth permissionは既存 `canovia.development.read`。write permissionは付与しない。

| 段階 | 本人のPlan同意 | 追加flag（Study read/tools等の共通gateは別） | 合格条件 |
| --- | --- | --- | --- |
| A 最初 | `overview`、推奨1日 | summary/context/proposals OFF | title＋本人記録deadlineのみ、tasks空。tasks/集計/Context要求は拒否、別人・別Plan拒否、取消後は同じ接続でも拒否。 |
| B 別承認 | 新しい本人再確認で`tasks` | summary/context/proposals OFF | 最大8件のTask id/title/status/recorded progressのみ。生問題・回答・説明・証拠なし。取り消して再拒否。 |
| C 別承認 | 新たに`study_progress` | `CANOVIA_MCP_STUDY_SUMMARY_READ_ENABLED=true`、context/proposals OFF | 本人が反映したAttemptと既にowner承認した外部結果だけを限定集計。未適用/未承認・raw問題/回答/思考を出さない。既存tasks同意で代用しない。 |
| D 別承認 | 新たに`learning_context` | 上記summary＋`CANOVIA_MCP_LEARNING_CONTEXT_SNAPSHOT_ENABLED=true`、proposals OFF | 明示許可したPlan/Task/承認済み集計に由来するSnapshotのみ。暫定的な弱点/提案を確定習熟としない。通知/Task進捗/再計画は変更しない。 |

`CANOVIA_MCP_STUDY_RESULT_PROPOSALS_ENABLED=false`、Auth0 `canovia.study.write`なしを全段階で維持。readは業務データを更新しないが、同意・取消・context_readのmetadata監査は保存する。

## 拒否・取消の意味とSTOP

- 期限切れJWT、誤issuer/aud/client/subject、未同意、scope不足、別owner/Plan、public/共同化、grant/link失効は拒否。DB matrixは実際の2接続でPlan/link/grantをロックし、commit後のvisibility変更・取消で後続readを拒否する。全ての競合順序や実Auth0の証明ではない。
- **Auth0単独のlogout/refresh-token取消と、Canovia grant取消は別。** statelessな署名済みaccess JWTを毎回IdPへ照会する実装ではない。JWT有効期間は最大3600秒、Canovia側grant取消/unlink/tools OFFで即時遮断する。IdP側だけの個別JWT取消に即時追随する保証は未実装（FUTURE）。これが必須なら本番はSTOPし、その運用/設計を別審査する。実providerで未検証の取消を合格扱いしない。
- L0/L1取消だけではMCP grantを失効しない。tools OFFだけでもgrantレコードは残る。事故時はownerのPlan grant取消/必要なunlinkに加え、別承認したtools/discovery/link/consent停止を実施し、既存接続でdenyを確認する。取消済みgrantを再ONや再linkで復活させない。
- ChatGPTへ既に渡った情報は、Canovia側の取消で回収できない。会話/接続の保持・削除・共有設定と利用契約も本人が確認する。実アカウントの内容・token・subject・内部client IDをGitHub/チャットへ貼らない。

## 次のオーナー操作・承認

まずは **#788と本PRのexact head・CI・差分のレビューだけ**。GitHub Draftを勝手にmainへ統合せず、新候補も採用しない。採用後は#788のoffline-first Mac手順から1ステップずつ進める。

個別GOが必要: (1) main統合/固定候補採用、(2) 実Aiven20件read-only監査、(3) backup/隔離復元・費用、(4) writer停止・実DDLと復旧案、(5) production保護下のmerge、(6) Render branch/env/specific-SHA deploy、(7) 本番Auth0/client設定、(8) 実機と本人Plan1件の概要read、(9) B/C/D各scope拡張。write・一般公開・課金は今回許可に含めない。

本番tenant・2client・本人限定入場・実署名token・独立復元・実機・実Plan readはNOT_RUN。費用見積は未確定、有料契約/リソース作成なし。UI/PHP/合成ブラウザCIの成功を本番GOにしない。**結論: Draft/HOLD・本番NO-GO。**

# AP本人MCP：旧94件先行版の本番昇格ブリッジ審査（2026-10-18）

> 状態：**提案・読み取り専用CI準備／PRODUCTION NO-GO**。オーナーのGo・GitHub本番保護変更・本番デプロイの承認ではない。
> 実装: [PR #785](https://github.com/1kz-ma1/Canovia-web/pull/785)。既存の[本番収束マスター](CANOVIA_INTEGRATION_FREEZE_RELEASE_MASTER_2026_10_16.md)と[AP限定レビュー](https://github.com/1kz-ma1/Canovia-web/blob/feature/ap-mcp-live94-auth0-20261018/docs/development/MCP_AP_LIVE94_SOURCE_GATE_2026_10_18.md)を優先。Future Designではなく、現行候補のSTOP条件を可視化する審査資料。

## 1. 固定された現在地

| 項目 | 実測値・意味 |
|---|---|
| GitHub `main` | `4e3a6e97949602409ea337290c4d4f2052fda998`。通常の `release/*` 昇格規則が実装されている |
| `production` / Render Live | `15abdc9de6f24b5250fb255c579d1aa55facaf1a`。別途 read-only Render確認。起動は `php artisan migrate --force` を実行 |
| AP限定 `feature/ap-mcp-live94-auth0-20261018` | `d28899ddc647ec74fbe7c0e4d28967582a910067`。旧Live94から派生。合成JWT/OAuth、旧94 MySQL 8.0/8.4、ソース限定CI合格。本番実データには未接続 |
| GitHub Ruleset | [Canovia Production Protection](https://github.com/1kz-ma1/Canovia-web/rules/24954459) active、merge-only、force push / 削除不可、bypass actorなし。次の5件必須 |

GitHub `production` Rulesetで要求するcheck context:
`Production promotion contract` / `workspace-resume` / `migration-recovery` / `P0 schema integrity (MySQL 8.0)` / `P0 schema integrity (MySQL 8.4)`。

いまのAP候補には、これらのcontextを作るための既存main用 workflow sourceがなく、候補のブランチ名は `feature/*`、候補HEADはmainの祖先でもない。**GitHubの必須チェックを一時的に外す、他人のチェック名を偽装する、force-pushする、production直接反映する方針は採用しない**。

## 2. 実装したブリッジ・プレビュー

`scripts/ci/ap_mcp_release_bridge_review.py` とそのnegative tests、`.github/workflows/ap-mcp-release-bridge-review.yml` を **mainベースの独立PR** に追加。固定実際のgit commit objectのみを使い、read-onlyで次を判定する。

- `main`、`production`、AP候補のSHAがレビュー固定値に一致（変化したらSTOP）。
- 候補が旧94件から派生、migration sourceは旧94件と完全に同一。
- mainの昇格ポリシー本文と必要workflowファイルが存在し、従来のmain ancestry契約が改変されていない。
- AP候補に必要な5つのworkflow sourceがあるか、main祖先条件・`release/*`命名に適合するかを確認し、欠落の内訳を出す。
- 例外の導入が必要でも `production_release_authorized=false` を**固定**。テストには候補差し替え・本番SHA変化・94件差分改変・main契約改変・必要workflow欠落・git証跡不在・全workflowが揃ってもGOにならない負のケースを含める。

このCIは本番保護Rulesetそのものを変更・検証したものではない。Auth0実認証・Canovia実Plan・Aiven接続・Render変更・環境変数/secret・人的Go/復旧能力についていかなる肯定判断もしない。`REVIEW_REQUIRED` は **リリース可能** という意味ではない。

## 3. 安全な候補Aの実施可能性・境界

1. **リリース経路のOwner意思決定:** 旧Live94の選択的バックポートを正式候補として認め、通常main固定候補とは異なる狭い例外を設計してよいか別途決める。NOならAをSTOPし、main全差分を審査したPath Bへ切り替える。現状の「進めて」は調査・CIの継続であり、production本番昇格のGOではない。
2. **保護ルールのブートストラップ:** 既存Rulesetの必須チェック5件が現在の候補では発生しないことを解決するには、所有者が別途GitHub側の運用手順を承認・操作する必要がある。Rulesetは削除/無効化しない。必要なworkflow/rollbackレビューを済ませても、技術的に既存保護が実施可能か確証が無ければSTOP。
3. **実スキーマと復元:** 旧版 `docker/render-start.sh` の自動 `migrate --force` と MySQL/Aiven ledgerを照合し、復旧手段を独立検証。候補ファイル0 DDL差分だけでは証明不可。
4. **OAuth受入:** production Auth0の本物のsigned JWTで `issuer` / audience / CIMD内部 client / Github `sub` / 2クライアント同一subject / PKCE / 本人以外denyを確認。トークン、sub、Plan ID、秘密情報はログに出さず、当該本人承認が必要。
5. **フラグOFFのデプロイ:** 別オーナーGO後にのみ本番へ段階反映。初回配備では個人Plan共有はOFF。Web・iPhoneでSession/CSRF/既存Plan正常性を確認後、別承認で本人のみ ON。1 Plan overview 1日、同意前deny→read→取消後deny→緊急OFFを確認。

## 4. 今回しないこと

既存 `.github/workflows/production-promotion-policy.yml` の書き換え、GitHub `production` Ruleset/branch、Render、Aiven、Auth0 tenant設定、MCPフラグ、実データ、課金は一切変更しない。古いPR #513も保持する。

**結論: 候補Aは機能合成テスト成功、配備ルートは依然としてHOLD。** いまの実用化スピードを優先しながらも、現状のrequired status checksは機械的に突破できないという事実を保存する。次のオーナー判断は「限定版の本番昇格例外を審査対象として採用するか」と「実DB/復旧の検証作業を承認するか」であり、どちらも自動GOにはならない。


### 後続のセキュリティテストに伴う固定SHA更新

元のAP候補PR #785に、別コミットで非owner紐付け拒否、OAuth issuer不一致時のstate再利用拒否、Plan共同化後の同意拒否、subject解除直後のbearer拒否テストが追加されたため、固定調査対象を `d28899ddc647ec74fbe7c0e4d28967582a910067` に更新した。CI実行中に同PRがさらに動いた場合はwarningを表示するが、**現在のブランチ最新HEADの受入とは見なさない**。レビュー結果は明示した固定SHAについてのみ成立し、今後の変更は再審査する。

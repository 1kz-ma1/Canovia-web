# D-2026-10-23-01 — 先行リリースは事前準備、データは正式リリースへ継続

> Decision owner: **Canovia Product Owner** / Date: **2026-10-23 JST**
>
> State: **ACCEPTED for product and architectural direction / NOT implementation, database, or production release approval**
>
> Domain: Product / Experience / Platform / Data continuity
>
> Active implementation/acceptance entry: [先行リリース事前準備・共有DB戦略](../PRELAUNCH_PREPARATION_SHARED_DB_RELEASE_STRATEGY_2026_10_23.md)。正確な運用ゲートは[Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488)、[統合停止マスター](../CANOVIA_INTEGRATION_FREEZE_RELEASE_MASTER_2026_10_16.md)。この短いDecision Recordは重複する実装仕様ではない。

## Problem / context

2026-10-23にStudy/Development Local-first Preview（PR #846/#848/#850/#852/#853/#854）が開発`main`へ統合され、合成ChromiumとNode試験は成功したが、Product ownerがMacで触れた結果、元のCanoviaとは体験が大きく異なると評価した。ローカル版の独立UIで「中核価値を小さく今すぐ完走」させることと、Canoviaの既存製品へ準備を蓄積する目的は異なる。

Product ownerは、先行公開では中核価値が直ちに体験できなくてもよく、診断・計画を作成して正式リリースに備えられれば成功とする方向を選択した。HP/Canovia Futureが開発段階と将来価値を伝える役割を持ち、正式リリースでは既存の準備データを利用して初回から行動できることを目指す。

## Considered alternatives

| Alternative | Benefit | Cost / limitation |
| --- | --- | --- |
| 独立Local-firstを先行製品として公開 | Cloud共通基盤の本番HOLDを切り離せる可能性 | 元のCanoviaとのUX乖離、Local保存消失/端末間非共有、別DB移行が必要 |
| **A: 元のWeb・同じCanonical DBで準備機能だけ公開** | ブランド/Plan/owner IDの継続、正式版での移行負担削減 | 実DB/auth/recovery/共有startupと公開制御の安全受入が先に必要 |
| **B: Preview別DBに保存し、正式版へ安全移行** | 本番DBと独立した段階提供を設計できる | 個人情報の別運用、本人確認/ID mapping/二重取り込み・原子移行・復旧の追加負担 |
| 全機能の完成まで一切公開しない | UX全体の受入後に紹介できる | 目標準備と関係形成の機会を延期する |

## Decision / rationale

1. **先行リリースの製品目的を「正式版に向けた安全な準備」に変更する。** 診断・目標・現在地・計画作成/保存/再訪が最低限成立すれば、Study採点やDevelopment自動実行まで公開することは必須ではない。
2. **既存Canovia Webの体験・アカウント・Canonical DBを継続するA案が第一候補。** そのための実Aiven schema/必要DDL・認証/認可・権限・バックアップ復元・Feature Flagと実機/Ownerの安全HOLDが解消していることが**前提**。完了しているとは言わない。
3. **B案はAを安全に受け入れられないときの代替**。採用するときは独立したDB/認証の安全性、本人性とデータを一度だけ正しく移行する能力を証明し、Product ownerが別決定する。ローカル版は技術検証用資産として保全し、既存Canoviaの代替製品へ自動昇格しない。
4. **公開対象の縮小とコード本番配備を混同しない。** DB migrations / shared auth / jobs / route / old features / WebViewは非公開でも影響し得る。機能の単なる隠蔽は安全審査を省略する根拠にならない。
5. 2026-12-01は**安全とデータ継続性が満たせた場合の目標**であり、延期も選択する。正式リリース後の機能/日程は未保証と明示する。

本決定は、[2026-10-15固定スコープ](../PRELAUNCH_FIXED_SCOPE_RELEASE_EVALUATION_2026_10_15.md)や[10-16統合停止マスター](../CANOVIA_INTEGRATION_FREEZE_RELEASE_MASTER_2026_10_16.md)の**当初「最初の一問・次Actionまで見せる」公開体験の優先順位を置き換える**が、安全ゲート、HOLD、履歴、旧証跡は削除・変更しない。

## Hypotheses / measurement

**UNMEASURED**: 先に診断・Planを蓄積できると、正式版でのオンボーディング負担が減り初回利用まで進みやすくなる可能性がある。指標候補は診断開始/完了、Plan保存、再訪、正式リリース後の元Planからの実行移行率、保存失敗/データ事故。母数、観測期間、プライバシー同意、期待閾値は未設定。「公開で価値が証明された」とは言わない。

## Safety / privacy guardrails

本人/Planの認可、Aiven実schema、固定候補migrationと現行データ保全、独立復元、ユーザーの誤認防止、Release Level/Route/Job deny-by-default、デプロイ/Operatorの個別GOはすべて維持する。実データや認証情報をGitHub/AIへ含めない。B案も本番HOLDを理由に安全要件を省略しない。

## Evidence / current result

- Evidence base reviewed: 開発`main` `bcf385ea18fc97902d3381099094aecae9044ec1`（2026-10-23）、Local-first PR #846/#848/#850/#852/#853/#854と[受入記録](https://github.com/1kz-ma1/Canovia-web/issues/488#issuecomment-6358472705)。
- Local-first: **IMPLEMENTED / CI VERIFIED / BROWSER VERIFIED** for pinned SHA. **DEVICE UNVERIFIED / NOT DEPLOYED / RELEASE NOT APPROVED**。
- Same-DB preparation-first production: **PRODUCT DIRECTION ACCEPTED; IMPLEMENTATION & RELEASE NO-GO/HOLD**。本決定によるAivenアクセス、Restore、Render/production操作、Auth0変更なし。
- Observed user outcome: **INSUFFICIENT_EVIDENCE**（先行一般ユーザー/正式利用の検証なし）。

## Revisit triggers / successor decision

再検討: Aのshared DB/auth/recovery gateの長期未達、Guest/Account継続性破綻、元UIでの診断・Plan準備UXが不十分、Bの安全性/総コストが明確に優位、正式版へのPlan互換性喪失、ユーザーの期待誤認。追加のOwner決定は日時と新IDを付け、この元の判断を黙って書き換えない。

**Next work:** [実装/監査パケットと新チャット用プロンプト](../PRELAUNCH_PREPARATION_SHARED_DB_RELEASE_STRATEGY_2026_10_23.md#5-codex向け実装監査の実行パケット次チャットからの起点)。

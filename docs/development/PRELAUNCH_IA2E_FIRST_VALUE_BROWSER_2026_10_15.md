# Pre-Launch IA-2e — 最初の価値を一連の実ブラウザ操作で受入確認

**2026-10-15 / Experience lane / 開発専用検証。production・Static Site・Web/iOS本番への公開承認ではない。**

## 背景と目的

- [IA-1](PRELAUNCH_IA1_STATIC_PUBLIC_INTRO_2026_10_15.md) で独立紹介ページと任意のCanovia Future/無料Support導線を実装した（Render Static/Web Auto Deployは両方OFF）。
- [IA-2d](PRELAUNCH_IA2D_DISPOSABLE_BROWSER_E2E_2026_10_15.md) では匿名Welcomeの診断・会話・Planなし閲覧の3分岐を実ブラウザで確認した。
- [Development V6.10](DEVELOPMENT_BROWSER_P0_V6_10_2026_10_15.md) では開発Plan→利用者の手動JSONインポートによる最初のTask→Guided Execution→Evidence→本人承認のNext Action→再ログイン復帰を確認した。

今回の差分は **同一Chromiumコンテキストの一人の利用者が、最初のBrowseを入口にしてPlan/Task/Evidenceと目に見える次のActionへ到達できるか**。別スクリプトの成功を合成して「一人が体験できた」と見なさない。

## 新しい E2E（IA-2e）

`.github/workflows/prelaunch-ia2e-first-value-browser.yml` / `scripts/ci/prelaunch_ia2e_first_value_browser_smoke.mjs` は、毎回新しい破棄可能な SQLite と `127.0.0.1:8797` 限定のLaravel/Chromiumを起動する。無関係の認証情報を扱わず、`APP_ENV=testing`、`DB_CONNECTION=sqlite`、`CANOVIA_PUBLIC_RELEASE_LEVEL=1`、`NATIVE_AI_DRIVER=disabled` と専用fixtureフラグを厳密に要求する。GitHub/AWS/Render/Aiven/MCP/外部AIへの通信やテスト専用HTTP書き込み口は作らない。

シナリオは **画面で実際にクリック/送信してサーバー保存まで検証** する。

1. 匿名 Welcome から **「まず見てみる」** → Planのない Overview を表示。実際には存在しないCurrent Actionが出ないことを検証。
2. Overviewの **任意の手動Plan作成** → カテゴリを明示選択して本人が保存。匿名Planを後から本人の登録アカウントに引き継ぐ。
3. 空Planの画面にある実際の Task計画導線 → **本人が入力した1 TaskのJSON** を既存フォームから読み込み。CLI/DBでTaskを投入せず、登録ユーザー自身の保存と対象Taskの特定のみread-only CLIで確認する。これはAI自動提案の証明ではない。
4. Guided Executionで実行方針を開始、実際のブラウザフォームから振り返りを提出して **TaskEvidenceを保存**。Task進捗0%は勝手に上がらない。本人がNext Action提案を明示承認。
5. **Plan Dashboard** を実ブラウザで開き、存在するPlan、1 Task、進捗セクション、承認した次のActionが画面上で見えることを確認。**Global Overview** の空Plan案内が消え、本人のPlanが認識されることも確認。
6. Guided Executionに戻り、本人承認のActionで次の作業を開始。ログアウト/再ログイン後、同じPlan/Task/次Actionに復帰。
7. 読み取り専用CLIで実データのPlan ID/Task ID、Task=1件、Evidence=1件、GuidedExecution=2件、Task進捗=0、承認されたnext_actionを突き合わせる。

## 事前公開範囲の事実・残るGap

- **実装統合/CI成功 != 本番配信**。Chromium headless (desktop engine) + disposable SQLite は物理iPhone WebView/Safari、ライブRender/Aiven、実ユーザー理解度、無料AI原価、障害からの復帰、既存データの後方互換まで保証しない。
- Evidenceは実行振り返りで **別のTaskEvidenceレコード** として保存される。Task進捗率は本人の明示判断なしに変えない。`PlanDashboardBoardService` の既存「最近の実績」欄は `workLogs` のみを投影し、Guided ExecutionのEvidenceとは**異なる**。今回のE2Eが証明する可視化は **Guided Executionの前回の振り返り / Plan DashboardのPlan状態・Next Action / Overviewの既存Plan**。振り返りがPlan Dashboardの「最近の実績」に表示されると主張しない。今後の実ユーザー受入でEvidenceが探しにくければ、本人の可視範囲とソース分類を保ったまま別Sliceとして検討する。
- 開発専用のQAコード・スクリプトのみを追加。LaravelのRoute、本人データ構造、Queue Worker、OpenAI、課金、GitHub自動Task反映、公開ロードマップの自動更新や実アプリのRelease Levelは変更しない。
- **次の受入**: 実際のWebとiPhone/Safari/PWAでWelcome/Plan/Task/保存/復帰/UIの理解しやすさを目視受入。公開する機能は本番権限・法務・障害対応と共に別ゲートで選定。Frozen [PR #513](https://github.com/1kz-ma1/Canovia-web/pull/513) を今回のテストで自動解除しない。
- [公開ロードマップ](PRELAUNCH_MANUAL_PUBLIC_ROADMAP_2026_10_15.md) は外部利用者に意味のある進展が実際に生じた時だけ手動で見直す。今回の開発内部テストだけで「公開済み」とはしない。

## QA操作と期待する結果

- GitHub ActionsのIA-2e専用ジョブが実Chromiumを実行し、全ステップ成功。
- 既存の必須CIは最終PR HEADで成功し、Review/Concurrent main競合がない。
- 実機と本番の受入は **unknown / pending** のまま。統合は開発`main`だけ、RenderはAuto Deploy OFFを確認して手動デプロイしない。

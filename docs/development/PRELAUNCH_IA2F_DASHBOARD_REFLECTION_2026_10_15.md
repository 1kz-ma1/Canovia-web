# Pre-Launch IA-2f — Plan Dashboardへの本人限定「振り返りEvidence」表示

**2026-10-15 / Experience / development `main` only. Not a public release approval.**

## IA-2eからの受入Gap

IA-2e実Chromium受入ではPlan→Task→Guided Execution→本人承認Next Action→Plan Dashboard→Overview→ログイン復帰が成功。一方、Plan Dashboardの「最近の実績」は`WorkLog`のみを表示し、Guided Executionの`TaskEvidence`が見つかりにくかった。

IA-2fでは同じ「最近の実績」リージョン内に **作業ログ（実時間/進捗変化）と振り返りEvidence（主観的な自己申告）を別セクションとして表示する**。集計・自動採点・成果誤認を避ける。

## 表示・セキュリティ契約

- `PlanDashboardWorkspaceService`の既存`canView`を先に認可。さらに本人の編集権限がある場合にのみ本人のGuidedExecution 3件を返す。`TaskEvidence`保存済みの`completed`レコードだけに限定し、EvidenceのPlan/Task一致と`guided_execution_reflected`種別を検証する。
- ログイン本人の`user_id`に一致する記録、または本人が持つ未引継ぎGuest `actor_token`の記録だけを表示。`GuidedExecutionService::ownQuery`と同じ本人境界。別ユーザーの記録や共同Plan閲覧のみのメンバーには表示せず、編集できない画面へのリンクも出さない。
- タイトル/日付/短い結果（最大110表示幅）と元のGuided Executionへのリンクのみ。表示はBladeの自動HTML escapeで出力し、他のメタデータ・AIプロンプト・リポジトリ/資格情報を公開しない。
- `workLogs`のProjectionと進捗計算を変更しない。**Guided Executionの振り返りは時間を追加しない、Task進捗率を増やさない、Task完了にしない。** 本人の次のAction承認は既存の別フロー。
- Plan Dashboardの共有`dashboard-information-board` Partialsにのみ反映。既存の standalone/Map互換の両表示から読める。新規Route、migration、Queue Worker、外部連携・GitHub自動Task反映・AIなし。

## CI・受入

- `PrelaunchPlanDashboardReflectionV1Test`: 最初は空→本人のWebフォームから保存→Dashboardに1件・元のTaskリンク表示、Time WorkLogは空、Task進捗不変。共同PlanのEditor/ViewerがOwnerの文章やリンクを閲覧できないことも確認する。
- IA-2eの **実Chromium** 回帰を拡張し、初回Browse→Task→Evidence→Dashboardで「振り返りEvidence」1件・本人が書いた結果・元Taskのパスに戻るリンクを確認する。併せて「作業ログがない」表示が残ることを検証する。前回のGuide→Next Action→再ログインまでを削らない。
- **未確認**：Safari/iPhone WKWebViewのタップ/横幅、実データでの長文・多数件、Render/Aiven Live、本人による画面の理解容易性。公開前に別途実機と本番受入が必要。
- [公開ロードマップ](PRELAUNCH_MANUAL_PUBLIC_ROADMAP_2026_10_15.md)は今回の開発テストだけで`released`にはしない。公開状態として meaningful であれば次の手動レビューで反映する。Frozen初回本番候補PR #513は別。

## 次のUX仮説

「最初の作業を振り返ったのに何も残っていない」と感じる摩擦は、保存済みEvidenceと戻る道筋の表示で低減できる可能性がある。ただしこれは**仮説**であり、実ユーザーの理解・反応はまだ測っていない。

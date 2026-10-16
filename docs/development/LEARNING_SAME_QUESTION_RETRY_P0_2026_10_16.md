# 同問再回答と別問題確認の分離 — P0

2026-10-16 JST。復習Workspaceの後続PR。DB migrationなし。641e2105/HOLD、本番・Render・Aiven・設定・#513への操作禁止を維持。

## 二つの入口

1. **同じ問題への再回答**：元Attemptの誤答・一部正解だけを、元問題のID/全文/全選択肢のまま再提示。以前の回答は入力しない。IDや形式の曖昧な組は開始せず、#778の既存ガードをそのまま適用。一般学習の正常な複合形式・旧type/choicesを維持する。
2. **別問題で確認する方法を探す**：既存Bankの `learning.index?review=1` へ移動し、Bank自身の復習条件を確認する。AI演習のTopicを確定診断として渡したり、AttemptをBank AnswerEventへ変換したりしない。

既存V6.13/16/17を調査：別問題ルートは本人のBank回答履歴を基にTopicを決定し、公開Packの未回答・独立した2問を検証する。AI Attemptとは別モデルで、元AI問題と同一でないことや同分野の供給を外部AIの評価だけから保証できない。そのため新しい自動橋渡し/出題は作らず、既存の条件確認・Pack選択・開始POSTを再利用する。Bank側の表示・POSTで条件を満たす場合だけ既存の別問題経路を利用できる。

## 独立した保存

再回答は専用の **StudyPracticeSession（question_provider=review_retry、completed）** に、元Attempt ID、元ordinal、question snapshot、今回のtyped answersと結果を保存する。採点済みAttemptを増やすと既存推論へ混ざるため、新規StudyPracticeAttempt・TaskEvidence・Bank AnswerEventは作らない。元Attemptのスコア、回答、評価、applied_atやTask進捗、既存の途中演習sessionは変更しない。

`selected_questions` は**NULL**。既存のBank曝露/信頼度スキャンの `whereNotNull` 対象にならず、回数制限のある履歴windowから本来の問題を押し出さない。completedなので通常のdraft/再開処理も対象外。専用結果GETと元Attemptのページ付き再回答履歴から本人だけが再読込できる。

Plan/Taskの閲覧権限＋本人user/guestを検証。再回答の作成には編集権限も必要で、POSTはPlan/Taskをlockし再検証する。CSRFとthrottleを維持。request UUID uniqueと正規化answers/source fingerprintで同一再送を同じ記録へ戻し、別内容・別actor・別sourceのUUID競合は409。壊れた/不正な欄・選択肢・未回答は422で保存しない。

## 正誤と理解度

- 保存済みのserver Bank根拠がある問題だけ、その凍結ruleと照合する。現在のBankへ正答を問い合わせず、後日の編集に影響されない。記述・思考過程の正しさは採点しない。
- 根拠がない外部AI/旧問題は**正誤保留**として回答を保存。架空の0点・誤答・正答・解説を生成せず、AI APIも呼ばない。UIは照合可能件数・一致件数・保留件数を分ける。
- 同問で、解説を既に見ている可能性もあるため、別問題の理解・習熟・弱点解消の証拠にはしない。Task完了・進捗変更・弱点推論・行動提案refreshを呼ばない。新たなDont Know状態も導入しない。

## 検証

PHPで再送/競合、本人/guest分離、他user/Task拒否、元Attempt/Task/途中draft不変、旧形式/複合互換、無効入力拒否、保存Bank変更後の照合、既存draft APIによる完了記録の上書き拒否を確認。既存Bankのdistinct/delayedテストも再実行し、新経路と混同しないことを確認する。

Chromium E2E：通常の復習から空の再回答欄→独立保存→正誤保留→再読込/履歴、HTTP同UUID再送・競合拒否、別登録ユーザーの読取/追加拒否、no-JSフォーム、Bank候補の確認でrunを勝手に作らないことを検証する。元Attempt hashとTask進捗は全工程で不変。

実機Safari/iPhone/WKWebView・読み上げ・実AI由来の評価品質は未検証。#778は実JSON未取得・実事象未再現・根本原因未確定のまま、推測修正を追加しない。課金・一般公開・本番昇格は行わない。

# 採点後の復習Workspace — P0

2026-10-16 JST。対象は旧AI演習のStudyPracticeAttempt。開発main向けで、本番候補641e2105/HOLDは変更しない。

## 表示と正本

採点後のサマリー直下へ問題別レビューを常時表示。二重の折りたたみを解消し、計画反映の操作だけ別の折りたたみに残す。順番は誤答→一部正解→評価不能→正解、同じ評価では元の順番。各カードは問題文、全選択肢、自分の回答本文、記録済み記述・思考過程、問題別フィードバック、誤解候補をまとめる。

正本はAttempt.questions/answers/assessment。question_idとfield_id、choice.idの完全一致で対応付け、配列位置でfallbackしない。欠損・重複IDの対応は不明として表示し、他の問題や欄から借りない。旧type/choices、answers[].answerも読める。保存済みスコア・Task進捗・履歴を書き換えず、DB migrationはない。

`/study-practice/history`と`/study-practice/attempts/{attempt}`に本人の履歴と復習画面を追加。Plan/Taskの閲覧権限に加えuser_idまたはguest actor_tokenを検証する。同じPlanの共同利用者にも他人のAttemptを見せない。PHP sessionや演習Sessionが消えても、本人のAttemptから復習できる。

## 正答・解説の出典

外部/Native AIのフィードバックは保存評価として表示し、独立検証されていないことを明示。問題JSON内のcorrect_answer/explanationや、現在のBankを使って過去の正答を補わない。記録のない思考過程も生成しない。

新しくサーバーで行ったBank採点だけ、公開・publication readiness・対象問題文/回答欄一致・対応ruleを確認して、採点時点のrule/正答本文/解説/出典を `assessment.review_reference_v1` に保存する。AI生成sourceと未監修/draft Packは根拠として採用しない。外部/Native JSON取込はこのmetadataを通さない。過去Attemptのbackfillはしない。

表示時は保存されたquestion全体のcanonical SHA256（JSON objectのkey順を正規化）と一意IDを確認。後日のBank編集・削除で再構築しない。根拠が無い回は「確定した正答・解説は表示しない」と明示する。公開Bankの保存根拠はコンテンツの普遍的な正しさや第三者の受入を保証しない。

## 検証と未完了

関連PHP：新レビュー、旧draft/履歴、Bank採点、Native、#778拒否処理。旧Sessionなし、順序の違うanswers/feedback、重複ID、共同viewer/別user/guest分離、外部による根拠偽装拒否、Bank変更後の保存根拠保持、既存データ不変を検証。

既存の実Chromium draft→評価journeyを拡張。常時表示・誤答優先・全選択肢/自分の本文/思考記録、終了後の履歴/再読込、元Attempt hash/Task進捗不変、no-JS閲覧、別登録ユーザー拒否を確認。実機Safari/PWA/iPhone/WKWebViewと人手読み上げは未検証。

再挑戦は独立PR。#778は実JSON未取得・実事象未再現・根本原因未確定のまま、安全な既存拒否を維持し、本件で推測拡張しない。production/Render/Aiven/本番設定/#513へ操作せず、課金・決済・公開levelは変更しない。

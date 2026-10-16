# 学習「わからない」— 独立した後続実装の設計案

2026-10-16 JST。**設計のみ／ボタン・状態保存・migrationは未実装**。今回の依頼で設計対象にしたが、PR1/PR2の導入条件や初回リリース候補には含めない。固定候補641e2105/HOLD、production/Render/Aiven/実公開設定/#513への操作禁止を維持。

参照実装はmain `39e58c74`。AP単問の欄混入修正は [PR #778](https://github.com/1kz-ma1/Canovia-web/pull/778)、Future表示は [PR #779](https://github.com/1kz-ma1/Canovia-web/pull/779)。Futureのイラストにボタンが描かれていても実装・提供済みとは扱わない。

## 1. 状態を三つの軸に分ける

| 軸 | 値と意味 |
| --- | --- |
| 回答意思 `response_kind` | `unanswered` 未確定／未回答、`answered` 有効な回答を確定、`dont_know` 学習者が明示的に回答不能を確定 |
| 採点 `grading_outcome` | `not_graded`、`correct`、`incorrect`、`ungradable`。サーバーの信頼できる採点結果だけが決定。`dont_know` は `not_graded` |
| 理解の自己申告 | 未申告／`not_understood`／`understood` の将来の別入力。「解説を読んでも理解できなかった」は回答不能や採点上の誤答へ変換しない。初回実装には追加しない |

空文字・未選択・不正選択肢・タイムアウト・419・通信失敗を「わからない」に変換しない。誤答は有効回答を採点した結果であり、本人の回答不能申告とは異なる。過去の欠損データからも本人の意思を推測しない。

## 2. 操作と確定境界

1. 「わからない」を選ぶと未確定の回答意思になる。この時点では採点・次問移動・解説開示・弱点確定をしない。「回答を選び直す」で戻せる。
2. 「わからないとして回答」を押してサーバー保存が成功した時だけ確定。通常回答と同じCSRF・actor/Plan/Task/item・現在ordinal・公開/Entitlement・request UUIDの検証を行う。入力値と`dont_know`が同時に確定payloadにあれば422。クライアントが指定する正誤・信頼度・弱点を受け付けない。
3. 確定前の選択値はactor/item限定の下書きへ保持できるが、inactiveな候補値を確定回答として送らない。意思の取消で候補値を復元し、別問題や別actorへ流用しない。
4. 確定後は既存の回答と同じく変更不可。同じ意思・値の再送は同じ結果を返し、競合する通常回答／dont_knowの後着は409。訂正は既存の評価調整や新しい演習の経路で扱い、確定履歴を上書きしない。
5. 200/204等の成功応答前に「保存済み」としない。通信切断時は未確認表示と同一UUID再送。419は再認証案内、409は現在状態の再読込とし、失敗を成功扱いしない。

## 3. 保存・復帰の互換性案

現状を区別する：

- Bank単問：`AdaptiveLearningTypedAnswerService` が有効値を必須とし、`LearningAnswerEvent.was_correct` は非nullable boolean。`answer_payload` は回答形式/値であり、回答不能の保存場所ではない。
- Bank下書き：`AdaptiveLearningAnswerDraftService` はactor/run/item限定の**session寿命**。別端末やsession期限後の復帰を保証しない。
- 旧AI演習：`StudyPracticeSession.questions_snapshot/draft_answers` はDBに保存し、`StudyPracticeAttempt` は評価確定後の履歴。JSON手動評価とBankの確定処理は異なる。

### Bank：推奨する加算的候補

既存の非nullable正誤列へ`false`や架空の選択肢を入れない。後続候補では専用の回答意思レコード（仮称`learning_response_decisions`）を追加する案を推奨する。

- `learning_run_item_id` unique FK、`request_id` unique UUID、`response_kind`（確定値`answered`/`dont_know`のみ）、確定時刻、version。actor/所有権は必ずitem→runから検証。任意のactor IDを受け付けない。`unanswered`は確定レコードなしで表す。
- 新しい通常回答も意思レコード＋従来の採点イベントを同一transactionで保存する。dont_knowは意思レコードのみ。旧`LearningAnswerEvent`は真の採点記録として維持し、旧履歴のbackfill/削除/書換えをしない。
- completion resolverは「意思レコードあり、または旧採点イベントあり」。画面だけでなくnext/finish/時間切れ/候補選定/履歴/復帰の全consumerを変更し、旧イベントだけでも完了を復元できるようにする。
- run/itemロック下で既存意思・旧イベント・UUIDの整合を確認。新方式の全回答はunique意思レコードを通し、通常回答とdont_knowの競合も一度だけ確定する。旧イベントのUUID再利用は拒否。別runへの同UUID再送も拒否し、完全一致のretryだけ冪等にする。
- 新writerが揃い全readerが両形式を読めるまでは新しい意思を書かない。旧writerとの混在や旧image復帰では新レコードを読めないため、flag OFFだけで互換rollbackできるとは扱わない。migrationは別PRで事前適用/復旧・DB別CIを審査する。

Bank下書きにはversion付きの意思とcandidate値を加えるが、DB確定レコードにはしない。復帰時はactor・run状態・item・snapshot形式と確定有無を再検証。古い下書きは従来の選択値として読む。別端末の下書き同期は別要件であり、今回約束しない。

### 旧AI演習：独立した互換変更

`draft_answers`の中へ架空のchoiceを混ぜず、Sessionのversion付き`draft_response_intents`と、Attemptの確定`response_intents`を加算する別候補が必要。元snapshotは不変。legacy欠落値はunknownへ変換しない。

回答意思はresponse_field単位でなくquestion全体にする。正常な複合設問を勝手に一欄だけ無回答にせず、`dont_know`なら設問全体を回答不能として表示する。任意の判断理由だけを最終回答と取り違えない。required検証・answersFromDraft・再開・手動/Native評価契約・request hash・履歴表示を同じversionへ揃える。

評価プロンプトへ「本人がわからないを選んだ事実」を渡し、正誤の推測を禁止。サーバーが回答意思を正本にし、AIが`incorrect`や正答を返しても意思を改変しない。未検証AIの解説を確実な正解として自動開示しない。既存の過去Attempt・得点を再計算しない。

この2系統を1つの型やDB migrationで強引に統一しない。各系統の実装と回帰が揃った経路だけにボタンを出し、未対応経路では表示しない。

## 4. 解説と試験モード

| 経路 | 確定後の扱い |
| --- | --- |
| 理解/練習・信頼できるBank | 対象itemのreview済み・公開許可済み・固定snapshotに解説がある場合だけ既存解説へ進む。空/未審査/採点不可能なら正解を推測せず「解説を確認できません」とする |
| 外部/Native AI演習 | 信頼できる解説が無ければ自動生成・自動採点しない。明示した既存の評価経路へ進み、dont_knowは非採点として保持 |
| 試験シミュレーション | 確定しても途中の正解/解説を返さない。hidden DOM、JSON/エンドポイント、prefetchにも正解を含めない。全体終了/時間切れをサーバーで確定後、許可したレビュー画面だけで開示 |

時間切れの未回答はunansweredのまま。試験採点でdont_know/未回答を0点にしても、集計上は別件数を維持し、通常の誤答へ変換しない。

## 5. 学習分析と履歴

- 理解/練習の正答率は**実際に採点した回答数**を分母にする。`correct / (correct + incorrect)`、分母0は「未算出」。別にdont_know件数・未回答件数・回答範囲を表示。試験得点は公式の全問分母で0点扱いを区別して表示する。
- dont_knowは「本人の回答不能申告」1件。1回でknowledge_gap/weakness/低理解度を確定せず、既存の複数問題・時系列の根拠と区別。次の任意復習候補には使えるが、Task進捗・Plan完了・課金・評価confidenceを自動更新しない。
- 現在の履歴サービスの`(bool) was_correct`集計やreview候補を全監査し、dont_knowをfalseにcoalesceしない。意思＋採点をjoinしても1問を2件として数えない。評価除外/訂正を尊重し、raw履歴は保持する。
- 解説を見た時刻は、提示した事実であり理解した証拠ではない。自己申告・正誤・解説閲覧を別の根拠として扱う。

## 6. 後続実装の受入条件

| 検証 | 必須ケース（現時点では未実装・未実行） |
| --- | --- |
| PHP/DB | 空/invalidとdont_knowの区別、型別通常回答、generic複合互換、通常/dont_know排他・同UUID/別UUID再送・並行確定、他人run/guest分離、状態/ordinal/権限変更、rollbackで半端な意思/採点を残さない |
| 下書き | 意思選択→取消→候補値復帰、session/DB経路別の再読込、旧format、session期限/別端末の限界、204/419/409/切断、確定後に古いdraftが戻らない |
| 評価/分析 | 成績未算出、dont_know独立件数、二重計数防止、旧履歴不変、弱点単発断定なし、評価除外、Task進捗不変、AIが返した矛盾する正誤を保存しない |
| ブラウザ | 単回答/複合/補助欄、キーボード/読み上げ/モバイル、保存確認・復帰・確認後の次問、no-JS通常POST、誤タップからの取消 |
| 試験 | 途中のHTML/JSON/関連APIに正解なし、時間切れ/終了後だけレビュー、未回答/dont_know別表示。新形式の全consumer対応前はボタンOFF |

本設計時に既存contractのPHP21 tests / 255 assertionsを実行し成功（TypedAnswersL3、SessionDraftL3c、HistoryUi、旧PracticeDraft）。新しいdont_know機能の成功証拠ではない。新runtimeがないため新E2Eは追加せず、PR1の既存draft→履歴E2Eを再利用し、上表は後続実装の必須検証として残す。

実装着手時はその時点のmainを再確認し、推奨テーブル/列案・得点表示・説明の信頼基準を具体的な変更候補として審査する。この設計PRのmergeはmigration実行・新ボタン公開・本番昇格の承認ではない。

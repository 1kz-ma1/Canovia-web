# Canovia Learning — Adaptive Learning Experience（暫定仕様書）

> 更新日: 2026-10-08 / version: draft-v1 / Status: **In Progress（調査と仕様整理）**
>
> **本書は消さない。** 今後のLearning開発で実装・検証状況を追記する。設計の確度と実装状況は独立に管理する。
>
> 判定: `CONFIRMED`=設計方針として合意、`PROPOSED`=候補、`EXPERIMENTAL`=測定・実証待ち、`UNDECIDED`=未決。
> 実装: `Not Started` / `In Progress` / `Implemented` / `Verified`。**既存機能の実装済みと本構想の実装済みを混同しない**。

## 0. 前提・着手ゲート

- **CONFIRMED** 進行中の別件PRとCIを先に完了。V58.75 (#359) / V58.76 (#360) はmainに統合済み。調査開始時main=`47a00822675db35c97bf292cc06002ebcdbdc3c1`。旧PR #310 は別件として開いたままで、この計画で操作しない。
- **CONFIRMED** 先に調査→本仕様保存→段階的実装。Question Bankや従来演習を破壊的に置換しない。Task進捗、旧Question Bank、既存Attemptの意味を遡って変更しない。
- **CONFIRMED** 本番反映とiOS WKWebView/PWA/モバイル実機確認はGitHub CIと別の検証ゲート。

## 1. 開発目的と背景

**CONFIRMED**: 「1問だけ解きたい」需要に応え、1問ごとに解答・採点・履歴保存・中断を可能にする。理解を深める学習と高回転の演習、事前固定・結果一括開示の模試を別運用にする。到達速度と本番再現性は両方重要だが、最適配分は未確定。

**EXPERIMENTAL**: 短時間開始率、継続率、初見問題正答率、模試の安定性・解答時間、費用を新旧フローで比較。利用回数増＝理解度向上と取り違えない。

## 2. 現行実装の調査結果（2026-10-08 main）

| 領域 | 実在する実装・コード | 確認された限界／活用案 |
|---|---|---|
| 学習演習 | `StudyPracticeController`、`resources/views/study_practice/show.blade.php` | セット単位の出題・submitAnswers・採点/適用。1問継続UIとは別。旧フローは維持。 |
| 一時保存/再開 | `StudyPracticeSession`、`saveDraft`、`resume`、`draft_answers`、`questions_snapshot` | 回答ドラフトは永続化されるが、採点済み履歴は原則セット単位。新規フローで1問ごとの独立履歴が必要。 |
| 履歴と進捗 | `StudyPracticeAttempt`、`applyAssessment`、`EvidenceProgressService`、`StudyTaskProgressionService` | Attemptはquestions/answers/assessmentの塊。適用でTask進捗に影響し得る。新しい回答イベントを既存Attemptに偽装せず別保存し、初期はTask進捗へ自動反映しない。 |
| 問題Bank | `QuestionPack`, `Question`, `QuestionBankCoverageService`, `QuestionBankStudyPracticeQuestionProvider` | Published Pack・active Question、source_type/reference、response_schema、grading_rule、explanation、learning_metadataを再利用。既存選択器は固定長・Coverage判定に依存。1問用Adapter要。 |
| 採点 | `QuestionBankGrader`（exact_choice, exact_multiple, numeric_tolerance）、`StudyPracticeProviderRouter` | Bankは確定ルールで採点可。記述・追加思考過程は必ずしも機械採点不能。1問採点はBankルールを使い、評価不能なら無理に正誤を断定しない。 |
| 選択・弱点 | `StudyPracticeStrategyService`, `StudyWeaknessPrioritizationService`, `StudyPracticeRoutingPolicyService`, `StudyExamConvergencePolicyService` | 既存のWeakness Reinforcement/General Practice/exam_modeは**出題戦略・段階**であり、今回のUnderstanding/Practice/Exam Simulationの**回答体験モード**と別概念。 |
| 試験フォーマット | `StudyPracticeExamProfileService`, `StudyOfficialExamReferenceService` | AP科目Aのヒューリスティックな形式ヒント等はあるが、試験別の厳格な問題数/時間/受験形式の正式登録ではない。既存の`exam_mode`を新模試完成扱いしない。 |
| AI | `NativeAiStudyPracticeQuestionProvider`, `HybridStudyPracticeQuestionProvider`, `ExternalAiStudyPracticeQuestionProvider`, 各AssessmentProvider | フォールバック可能な設計はあるが、1問ごとのAI強制呼出しは費用・待機の問題。Bank優先。生成API・支払い・レート制限が未確認の段階で自動利用しない。 |
| 学習評価 | `StudyPracticeCumulativeCheckpointService`, `StudyPracticeReliabilityService`, `StudyScoreObservation`, `StudyRecall*`, `StudyBehaviorPersonalizationAdapter` | 回答単位の評価根拠・信頼度・誤操作調整を追加し、従来の集合成績からの一律換算をしない。Reliabilityの数値は学力測定の確率ではない。 |
| UI・回帰 | `StudyWorkspace*`, `StudyAdaptiveActionController`, tests: `QuestionBankV402Test`, `StudyPracticeDraftPersistenceV4071Test`, `StudyPracticeResumeFastPathV565Test`, `StudyPracticeRoutingV564Test`, `StudyPracticeCumulativeCheckpointV5817Test` | Learning専用Workspaceに任意入口を追加、既存ルート/履歴表示/復元を優先して維持。 |

**CONFIRMED**: `study_practice_sessions`にstatus・selected_questions・questions_snapshot・draft_answers、`study_practice_attempts`にquestions・answers・assessment・request_hash・適用前後Task進捗、`question_packs`/`questions`に出題元・採点ルールが存在する。現時点で今回の「共通の1問回答イベント」テーブルは確認できない。

## 3. 変更対象

- **CONFIRMED**: 新しい実行体験、単問回答イベント、3モードの定義、問題集合選択・試験プロファイル、評価証拠の表示を既存データに追加する。
- **PROPOSED**: 新`LearningRun`系の独立保存層＋既存Question Bankのアダプタ＋学習UIへの任意リンク。旧`StudyPracticeSession`を即時に置換しない。
- **CONFIRMED**: 未対応のモード/問題集組合せは「未対応」で明示し、本番仕様らしい画面だけを出して擬似模試と称さない。

## 4. 変更しない既存機能

**CONFIRMED**: 従来のStudy Practice/Question Bankの出題・既存Attempt・保存済み回答・歴史的得点、Recall、簿記仕訳/Placement、Task/Plan Progress、Canovia Intelligenceの既存状態、外部AIハンドオフ、公開/課金/権限、チーム権限。旧履歴を削除・再採点・自動「習得済み」へ書換しない。新フローのTask進捗は明示的な整合性検証を経るまで**0件変更**。

## 5. 学習モード（学習戦略・問題集から分離）

| モード | 確度 | 出題 | 解答/採点 | 表示 | 中断 |
|---|---|---|---|---|---|
| Understanding | **CONFIRMED** | 1画面1問。直近確定、先は動的候補 | 1問ずつ保存・安全に採点 | 直後に正誤・正答・解説。必要なときのみ記述/思考過程 | 任意のタイミング |
| Practice | **CONFIRMED** | 1画面1問。直近確定、先は動的候補 | 1問ずつ保存・採点 | 直後は原則正答のみ、任意で解説 | 任意のタイミング |
| Exam Simulation | **CONFIRMED** | 開始前に全問・順序・制限時間・範囲を固定 | 中は採点結果を非表示、終了後一括 | 試験中の正答/解説/採点を一切出さない | 再開規則は試験プロファイルに従う（未確定な場合は提供しない） |

- **PROPOSED**: Understandingの記述は選択式の追加入力を許容するが毎回必須ではなく、AI/人間が採点できないときは評価不能にする。Practiceでは追加記述を原則要求しない。
- **CONFIRMED**: モードを「AIが強制的に選ぶ」ことはない。推薦と実際の選択は別。
- **UNDECIDED**: 模試の一時停止・離脱/タイマー継続・期限切れ扱い、初回の対応試験（正式メタデータが必要）。

## 6. 動的問題キュー（A/B専用）

```text
Learning Run -> [locked/served: CURRENT+nearby] -> AnswerEvent(immutable scoring)
                     [candidate: replaceable] <- policy vN + recent evidence
                     [strategy: history/confidence/preferences]
```
- **CONFIRMED**: 「確定キュー」は通常の再評価で変更しない。「候補キュー」は回答後に更新可。「学習戦略」は根拠・信頼度・目的・出題方針を別管理する。単発正誤で過敏に振れない。
- **PROPOSED**: 確定2〜3問、候補数個を初期実験値として扱い、`config`化する。フェッチはBank優先、既出IDをsession内で除外。先読み問題が不足したら未準備/再試行を明示し、API待機を「次の問題」を押した時に必須にしない。
- **EXPERIMENTAL**: 先読み深さ、候補回転、重複制限/許容、弱点から他分野への切替、キュー遅延・費用・精度影響。
- **CONFIRMED**: AI評価APIは毎問必須にしない。AI生成は予算制限・キュー待ち・失敗フォールバックの契約を定めた後のオプション。

## 7. 問題集とモード

**CONFIRMED**: mode（解答体験・採点開示時期）とcollection（対象問題集合）は独立。Question Bank既存の`QuestionPack`が物理的な集合の第一候補。

| collection | A/B | C |
|---|---|---|
| AI推奨 / 現在地診断 / 分野別 | **PROPOSED**: Bankの検索/フィルタで実現 | 原則不可、別の本番対応静的集合のみ |
| 年度別過去問 | **PROPOSED**: ライセンス・出典確認済みのBankのみ | 本番規格・静的全問/採点/時間が揃ったときのみ |
| 間違えた問題 / お気に入り | **PROPOSED**: ユーザー固有の履歴から論理的集合 | 原則不可（模試の再現性を損なうため） |

**UNDECIDED**: 各collectionのフィルタ定義・お気に入り永続DB・著作権/利用許諾の差分と公開条件。
**CONFIRMED**: Domain/APや問題集の分類を、Learn modeの3種と混同しない。

## 8. 現在地確認とオンボーディング

- **CONFIRMED**: 初心者は基礎または任意診断、既学習者には現在地確認を任意提案、履歴十分なら再診断不要を優先。診断を飛ばし、1問から直接開始できる。
- **PROPOSED**: Plan/Taskと`StudyPracticeAttempt`の既存集合成績、`StudyScoreObservation`、Recall履歴、今後の単問イベントの有無から入口選択。既存Attemptの問題ごとの正誤が不明な場合は一律の単問証拠へ変換しない。
- **EXPERIMENTAL**: 診断問題数/停止基準/カバレッジと、未学習者の認知負担の比較。

## 9. モード推薦ランキング

- **CONFIRMED**: 根拠と限界を文章表示し、百分率「おすすめ度」を作らず、好きなモードを自由選択。推薦エンジンは差替可能。
- **PROPOSED**: v1は時間が短い場合Practice、説明補助が必要な場合Understanding、複数回の初見正解と十分な試験仕様のある場合Exam Simulationを候補にする説明可能ルール。ただし未対応Examを1位にしない。
- **EXPERIMENTAL**: 推薦順位の更新周期/ヒステリシス、評価信頼度の重み、直近成績/残日数/希望の寄与。重みは今は固定しない。

## 10. 学習履歴・評価と理解度

- **CONFIRMED**: 元の解答イベントと、それから導いた`KnowledgeEvidence`/理解度推定は別。元イベントは不変。誤タップの申告は別の調整レコードで追跡し、原データを削除しない。
- **PROPOSED**: 共通イベントはrun_id/question_id/source/mode/response/answered_at/duration_ms/grading_result/grader/explanation_seen_at/retry_of_id/reasoning_assessment/evaluation_contribution/confidence等の欠損を許容して収集。出題と解答時点は必ず分ける。
- **CONFIRMED**: Understandingで解説を見た後の再回答と、Exam Simulationの初見・制限時間下の回答を同じ強さの証拠にしない。偶然の正解や計算ミスを断定的な理解度変更に使わない。採点可能な正誤と理解度の推定をUI・DB・APIで区別。
- **UNDECIDED**: 誤タップ調整UIの運用権限/申告期限と、学力推定モデルの校正基準。

## 11. API・DB変更案（案。最終Migrationは各Sliceでレビュー）

- **PROPOSED**: `learning_runs` — plan_id, task_id, owner identity(user_id/actor_token), mode, collection_key, strategy_version, status, exam_profile_key/version, started_at/ended_at, queue_state, preference snapshot等。
- **PROPOSED**: `learning_run_items` — run_id, ordinal, question_id/source/version, source_snapshot, state(locked/candidate/served/answered), immutable exam snapshot; 問題ID重複をユニークキーで制御。
- **PROPOSED**: `learning_answer_events` — run_item_id, request_id(unique), response_json, grading_result, assessed_at, answered_at, duration_ms, explanation_seen_at, retry/reflection metadata, evidence_confidence。答え直しは上書きでなく別イベント。
- **PROPOSED**: `learning_evaluation_adjustments` — answer_event_id, reason, actor, contribution_override, created_at。旧Attemptは変更しない。
- **PROPOSED**: `learning_exam_profiles`（管理されたバージョン付き設定）— exam_code, version, total, duration, format, coverage/rules/source + official verification time; 未確認なら模試の開始不可。
- **PROPOSED**: API `GET/POST /plans/{plan}/tasks/{task}/learning/runs[/{run}]`、`POST .../answers`、`POST .../finish`、将来 `.../retry`, `.../evaluation-adjustments`。
- **CONFIRMED**: Session/actor identity + Plan/Task権限をサーバで検証。公開の採点ルールや正解を出題レスポンスへ混ぜない。Examは開始時スナップショット固定。共通履歴との二重計上はしない。重複POSTはrequest_idとrun_itemで冪等。
- **UNDECIDED**: 既存`StudyPracticeSession`と新Runとの中長期統合方法、非同期Jobの再試行・支払予算、全試験profileの認定ソース。

## 12. UI/UX変更案

- **CONFIRMED**: 1画面1問、回答操作の負担を抑える。終了ボタンがいつでも見つかる。即時結果と次の問題を明瞭に分ける。モバイル縦画面・キーボード操作・スクリーンリーダー配慮。
- **PROPOSED**: Learning専用画面に任意入口、`mode picker` + `collection picker` + `1問画面` + `回答/結果` + `続ける/終了`。旧演習入口は維持。
- **CONFIRMED**: 模試中は正答・解説・学習評価・次問最適化を画面/レスポンスに出さない。
- **EXPERIMENTAL**: 待機許容時間/自動次問表示/解説開閉・追加記述の頻度/中断再開のUX。

## 13. 段階実装計画（依存順）

| Slice / Gate | 内容 | 設計確度 | 実装状態 |
|---|---|---|---|
| Phase 0 | 先行作業のテスト・PR・マージ確認。V58.75/76統合済み、別PR #310は非対象 | CONFIRMED | Verified |
| Phase 1 | 既存コード・DB・UI・履歴・認可の調査 | CONFIRMED | Verified（リポジトリ静的調査） |
| Phase 2 | 本暫定仕様書の作成・永続保持 | CONFIRMED | In Progress |
| Phase 3 | DB/API依存・互換性・コスト/試験再現性の分割計画 | CONFIRMED | In Progress |
| Phase 4a | Bank限定の新Run/1問回答イベント、1件終了/再開、既存Task/Attempt不変、リトライ耐性 | CONFIRMED（境界）/ PROPOSED（schema） | In Progress（V58.78初期実装・CI検証待ち） |
| Phase 4b | Understanding/Practice体験、表示分離、問題集合、履歴共通投影 | CONFIRMED / PROPOSED | In Progress（V58.78 Bank単一選択UIのみ） |
| Phase 4c | 確定/候補/戦略キュー、適応更新と重複回避、AI不可時のBank fallback | PROPOSED / EXPERIMENTAL | In Progress（V58.79繰り返し誤答に基づくBank候補層のみ、他は未実装） |
| Phase 4d | 試験別profile + C完全固定・採点終了後開示、模試停止/再開ポリシー | CONFIRMED（制約）/ UNDECIDED（各試験詳細） | In Progress（V58.80 設定ゲート・静的問題・最後に一括採点、正式試験profile未登録） |
| Phase 4e | 診断任意入口、3モードランキング、誤操作調整と校正 | PROPOSED / EXPERIMENTAL | In Progress（V58.81 説明可能なMode推薦と診断任意ガイドのみ） |
| Phase 5 | 費用/負荷/重複/権限/アクセシビリティ/モバイルと実機E2E | CONFIRMED | Not Started |
| Phase 6 | 本書更新、根拠付き実装/未実装表・残課題、段階的公開ゲート | CONFIRMED | Not Started |

**Gate**: 4aはFeatureテスト+既存Study回帰+Migration/rollbackを通すまでmainへ入れない。C開始は正式profile+漏洩テストが揃うまで解放しない。4b以降も最小PR単位。計測できていない性能は「高速」と宣言しない。

## 14. テスト方針

- **CONFIRMED**: 1問解答直後のDB保存、途中終了/再開、リロード・複数端末、同一回答のリプレイ、並列送信、Task進捗不変、旧Attempt不変、Plan/Task/ユーザー境界、認可。
- **CONFIRMED**: A/Bの直近キュー固定・候補差替・Bank不足での振舞い、C全問/時間/正答のサーバ側スナップショット・試験中漏洩なし、試験終了後のみ採点。
- **CONFIRMED**: Query数・UX待機時間・AIトークン/通貨コスト、出題ID重複、弱点の単発誤判定/誤タップ調整、推薦理由/ユーザー選択の優先、過去問出所・許諾。
- **CONFIRMED**: SQLite CIでなくMySQL本番差異も監視し、GitHub ActionsのWeb build/view cache/migrate/Study既存Featureを通す。iOS WKWebView/PWA/PC実機E2Eは別。
- **PROPOSED**: エンドツーエンドの学習シナリオを、1問→終了→翌日再開／採点レスポンス横取り／Examタイムアウト等で自動化。

## 15. 未確定事項・検証課題

| 問題 | 状態 | 固定しない理由 |
|---|---|---|
| モード推薦の重み・更新頻度 | EXPERIMENTAL | 短期合格と長期定着の測定データ不足 |
| 確定/候補キューの最適数 | EXPERIMENTAL | 遅延と柔軟性、Problem Bank残数のトレードオフ |
| AI評価頻度・同期/非同期・予算 | UNDECIDED | プロバイダ/費用/課金許容、品質測定不足 |
| 現在地診断の問題数・停止条件 | EXPERIMENTAL | 初期習熟度と試験特性によって変化 |
| Exam試験別時間・形式・中断方針 | UNDECIDED | 最新の公式受験仕様・権利・バージョン確認必須 |
| 誤操作申告と理解度の補正強度 | EXPERIMENTAL | 採点と学力推定の分離の実験が必要 |
| 間違え/お気に入り等コレクションのルール | PROPOSED | 旧Attemptと新Answerイベントが混在 |
| 学習分野推定や曖昧な結果の扱い | UNDECIDED | 既存学習分類/Sourceの信頼度検証 |

## 仕様更新ルール

- 各PRで該当Sliceを `Not Started→In Progress→Implemented→Verified` のどこまで到達したか更新する。**CI成功だけで実機Verifiedと書かない。**
- 1問イベントのschemaなど変更決定後に `PROPOSED→CONFIRMED` を更新し、差分・理由・回帰テストを記録。設計上の未確定を埋めるためにアルゴリズムの定数を先に固定しない。
- 恒久資料として残す。本書を作業完了時に削除しない。

## 2026-10-08 Phase 4a 初期Slice（V58.78、PR進行中）

設計ゲートはV58.77相当の仕様書PR #361として先にmain統合済み。以下の実装がCI通過したときのみ `Implemented` に更新する。

- `learning_runs`（actor/runと選択mode・pack情報）、`learning_run_items`（Bankの問題・解説・正答ルールのサーバ側スナップショット、ordinal）、`learning_answer_events`（1問1イベント、UUID冪等、採点結果、解答時刻、解答経過時間）を**旧StudyPracticeSession/Attemptと別**に用意。
- A/Bに共通のBank-only単一選択`exact_choice`体験を先行実装。Aは回答後に解説を開示、Bは任意のdetailsで表示。解答前のHTTP画面には正解/採点ルールを渡さない。
- モードと公開Packを独立に選択。初回キューは暫定`config('study.adaptive_learning.locked_queue_size')=2`件。確定済みの先の問題は勝手に変更しない。出題済みBank IDはそのRun内で再利用しない。補充はBankのみ。AI呼出しなし。
- 1問採点が即時コミットされ、保存後に終了/再開しても残る。同じ問題の二重投稿は上書きしない。回答内容が変わった再送は409。旧Attemptの追加・Task進捗の更新は**行わない**。
- 新UIは従来のStudyPractice画面から任意に入れる。既存ルートは維持。模試Cは開始不可、公式Exam profile未確認の模擬試験を名乗らない。
- 未実装：汎用入力（数値/複数選択/記述・追加思考過程）、将来候補キューの再ランキング、共通履歴の横断集計/理解度反映、誤タップの調整、問題集のその他の論理Collection、検証済み試験別固定模試、モード推薦。旧Attemptの変換なし。
- Verification: V58.78 Featureテスト（1問終了・再開・同一POST・同Plan権限/所有者境界・固定Bank snapshot・先読み・A/B UI・C不可・Task旧Attempt不変）＋既存QuestionBank/StudyPractice回帰＋CI。**PWA/iOS/Render実機E2Eは別ゲート。**

## 2026-10-08 Phase 4c 初期Slice（V58.79）

- **PROPOSED / EXPERIMENTAL**: `learning_run_candidates`に、確定済み`learning_run_items`と別の「入替可能な先の候補」を保存。候補生成は既存公開Bankかつ同一Run内で未出題の問題だけから行う。追加出題では候補を優先し、足りない場合はBank順に戻る。
- 各回答を原子保存した後に候補を再計算。**一度確定した出題順・出題スナップショットは変更しない。** 候補generation/position/reasonを残し、強制AI API呼出しはしない。
- 候補の先頭優先は「同じ概念の独立した複数問での繰り返し誤答」のみ。単発1問の誤答は分野を即時変更しない。直近2回の同概念正解は通常のBank順に戻す弱い証拠として使用。これは暫定的な分岐であり、実力・習熟を断定しない。
- `study.adaptive_learning.minimum_misses`、`signal_window`、`candidate_limit`、`locked_queue_size`はすべて**EXPERIMENTAL**の設定であり、試験・利用状況・コストから再校正する。デフォルトを最適解として固定しない。
- 既存V58.78のSnapshotに`learning_metadata`が存在しない場合はBank順へ安全に戻る。旧`StudyPracticeAttempt`から正誤の根拠を捏造せず、現時点では新Run内の回答イベントのみを使う。
- **未実装**: Plan横断履歴の重み付き統合、本人希望の細分化、難易度校正/解答時間重みづけ、AI事前生成・非同期、模試C、正式な信頼度推定。Phase 4cの完了扱いはしない。

## 2026-10-08 Phase 4d 初期Slice（V58.80）

- **CONFIRMED**: `config/adaptive_exam_profiles.php` は意図的に `profiles=[]`。公式試験の問題数・時間・範囲を確認せず AP のダミー模試を公開しない。テストでは架空のTEST試験の認定Fixtureだけを設定。
- **PROPOSED**: `AdaptiveExamProfileRegistry` は管理済みのprofile key/version、試験コード、subject、正式出典、確認日、問題数、時間、採点可能形式をバリデート。対応する公開QuestionPack側のprofile key/versionも一致し、**active問題の全件が**検証済み形式・ちょうど所定件数を満たしている時だけ開始を許す。
- **CONFIRMED**: 開始トランザクションで問題/出典/採点ルール/解説を全件固定する。回答中は正答・正誤・解説・スコアをDBイベントやHTMLに生成せず、`learning_exam_response_drafts`に選択肢だけ保存。既存A/Bの即時採点経路から完全に分離。
- **PROPOSED**: v1の中断ルールは `continue_timer`（ブラウザを閉じてもサーバの終了時刻は変わらない）。期限切れ後の回答/移動を拒否。期限後は結果提出操作で一括採点。明示終了前には採点を開示しない。終了後に未回答を分母に含む合計結果/問題別結果を表示。Task進捗・既存Attemptは変えない。
- **EXPERIMENTAL**: 試験別の中断・途中提出・非回答の採点・解答時間の再現性、制限時間UIの視認性は実機検証が必要。暫定の回答形式はBank採点対応の単一選択のみ。他形式は正式出題profileの整備後に追加。
- **UNDECIDED**: 対応試験別profileの正式採用、学校試験/資格試験ごとの時間/休憩/途中保存/時間延長、実際の公開問題集の利用許諾確認。
- **未完了**: 複数形式対応、全問の分野別成績/次の学習への統合、推奨ランキング、1問履歴の評価補正や横断集計。試験の再現性を実証した意味での `Verified` は付与しない。CIで設定ゲートと表示分離の機能を確認し、Render・iOS・PWA実機は別。

## 2026-10-08 Phase 4e 初期Slice（V58.81）
- **PROPOSED / EXPERIMENTAL**: `AdaptiveLearningModeRecommendationService::VERSION=multi_answer_rules_v1`。新Learning Answer Eventの同じPlan/Task/actorへの証拠を最大30件参照。単一正答ではPracticeを1位にしない。複数回答・別Runの根拠が揃って初めてPractice優先を検討。閾値は実験用設定であり最適値ではない。
- **CONFIRMED**: 3モードの推薦理由・根拠の不足・模試の提供条件を画面に明示。模試が利用できない場合は未対応と表記。ユーザーの選択は強制しない。「おすすめ度%」「合格確率」等の架空の精度は作らない。
- **CONFIRMED**: 旧`StudyPracticeAttempt`は過去の学習実績として件数だけ参照しても、単問正誤として直接換算しない。任意の現在地確認を案内し、診断を強制しない。1つのユーザー/Plan/Taskの履歴を別人へ混ぜない。
- **未実装**: 多分野の理解度信頼度統合、速度や残り時間等の個人希望と精密な優先順位、実際の現在地診断専用問題集・誤タップによる理解度調整、ランキングA/B検証、実機E2E。Phase 4eは完了とはしない。

## 2026-10-08 Phase 4e 評価調整Slice（V58.82）
- **CONFIRMED**: 1問ごとの正誤・回答文・解答時刻をimmutableで保持。本人が誤タップを申告した場合、別テーブル`learning_answer_evaluation_adjustments`に理由/effectをappend-only保存し、元の`learning_answer_events`を一切変更しない。
- **PROPOSED**: 初期実装の理由は`accidental_tap`のみ、効果は`exclude_from_recommendations`。単一回答に対する同じ申告の再送は冪等。A/Bと完了済みC結果から入口を提示。Plan/Task/actor scopeで本人以外の書込を拒否。
- **CONFIRMED**: 次の候補選択とModeランキングから申告対象を除外し、採点結果の記録やTask進捗は変更しない。「訂正したから正解にする」のではない。
- **未実装**: 理解度そのものの統計的校正、申告取消・不正申告防止ポリシー、レビュー済み正誤への学習補強、時間制限中の誤タップ申告、実機検証。適切な影響調整の強度はEXPERIMENTAL。

## 2026-10-09 Learning usability recovery — Question-per-screen entry and immersive header

Status: **PR implementation proposed; CI and actual iOS/PWA/production verification pending**. This slice does **not** reclassify the adaptive Bank Run as a generic AI question generator.

### Confirmed implementation boundary

- The adaptive one-question `LearningRun` still requires a published Question Bank with supported `exact_choice` questions; a bundled JSON file in the repository is **not** evidence that the relevant pack has been imported/published in production. No pack is silently created or published by this change.
- From Study Activity's question-practice entry, expose direct access to the adaptive mode selection. When no eligible published pack is available, explain the restriction and offer the already-supported legacy StudyPractice route instead of a dead end.
- In **existing legacy StudyPracticeSession answer forms**, progressively enhance the screen to display one question at a time with previous/next controls, a clear count and a final `回答をまとめて評価へ進む` action. This retains the **single set-wide server-side assessment**, answers payload, durable draft autosave and resume, input types, legacy Attempt and Task progression rules. **Do not claim per-question immediate grading in this fallback.**
- With JavaScript disabled or failed, the original list of all questions and the original set-wide submit remain accessible. In enhanced mode, restoring a draft selects the first unanswered question (or first server-side field error); navigation does not erase other answers. The submit action stays on the final page and hitting Enter earlier advances rather than submitting the full set.
- Immersive Learning/legacy Practice/exam header adds a small top inset above its 44px minimum-height exit/home touch targets, including `env(safe-area-inset-top)` for iOS PWA/WKWebView. No main workspace shell/navigation changes.

### Verification and non-goals

Relevant tests: `AdaptiveLearningSingleQuestionV5878Test`, `LegacyPracticeImmersionV5889Test`, `LearningImmersionShellV5888Test`, existing `StudyPracticeDraftPersistenceV4071Test`. Validate frontend build and required CI on exact branch SHA. On device verify responsive safe-area, multi-answer types, autosave after moving backwards/forwards, empty published Bank fallback, new Bank one-question Run, reload/return and normal 403/404 permissions.

No DB schema, pricing, rollout/entitlement, AI provider, official exam profile, source attribution, private user data or per-answer legacy scoring changes. Render live deployment and WKWebView/PWA device checks require separate evidence.

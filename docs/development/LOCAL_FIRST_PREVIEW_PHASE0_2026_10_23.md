# Canovia Local-first Preview — Phase 0 移植棚卸し・実装契約

> 2026-10-23 / Orchestrator（Learning・Development・Experience・Platform横断）
> 状態: **PHASE0_SOURCE_REVIEWED / IMPLEMENTATION_NOT_STARTED / RELEASE_NO_GO**
> 根拠: GitHub `main` `3dc91feae58d88513218d0d949f1a1efb95fa67b`（2026-10-23照合）。コード・文書の静的調査のみ。Local-first版のブラウザ動作、PWA/端末保存、iPhone、公開・本番環境は未検証。
> 本書は2026-12-01の**限定Preview目標**に向けた新しい提供方式の検討・実装境界を記録する。既存Cloud本番の公開・`e858`統合GOを意味しない。再開前に最新`main`を再照合すること。

## 1. なぜ独立Previewにするか

既存Laravel/MySQLのリリース・実Aiven物理スキーマ・独立復旧・OAuth/本人接続の未解決ゲート（[Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488)）を先行公開の必須依存から外しつつ、「Plan→Task→実行→記録→現在地→次Action」の実質的な価値を届ける。従来の[統合停止マスター](CANOVIA_INTEGRATION_FREEZE_RELEASE_MASTER_2026_10_16.md)を消さず、Cloud昇格は引き続きHOLDとする。

目標はStudyとDevelopmentの**両方で同じ実行・記録・判断ループを完走**できること。単なる静的モック、タイマーだけのオフライン画面、公開済みLaravel画面の表面コピーをPreview完成と見なさない。本人の明示操作がない限り、経過時間・推定弱点・GitHub事象だけでTaskの達成率を上げない。

`Phase 0` の完了条件は *対象、非対象、再利用できるコード境界、データ契約、受入基準、未確定ゲート* の整理。Phase 1以降の実装・E2Eは別PRで証明する。

## 2. 最新mainに基づくコンポーネント棚卸し

| Previewの機能 | 実コード／仕様の参照先 | 現状の依存・観測 | 判断 |
| --- | --- | --- | --- |
| 初回診断とStudy/Development Seed | `app/Services/PersonalizationBootstrapService.php`、`docs/wip/PERSONALIZATION_BOOTSTRAP_IMPLEMENTATION.md` | `buildContext()` と `seeds()` がある。PHP由来でそのままStaticで動かない | **規則移植**。最小質問とSeedをJSで再実装 |
| Plan CRUD | `app/Http/Controllers/PlanController.php`、`app/Models/Plan.php` | Laravel Request、Session/Ownership、Eloquent/DB、GoalContextへ依存。作成時 `create_request_id` による重複抑止 | **データ項目・バリデーション移植**。権限/共有/公開フラグは除外 |
| Task CRUD | `app/Http/Controllers/TaskController.php`、`app/Models/Task.php`、`app/Services/TaskDependencyService.php` | 必須状態 `todo/doing/done/cancelled`、分・進捗・優先度、最大20件の前提Task/循環禁止。DB transaction使用 | **ローカルCRUD移植**。複雑な依存UIは初版非対応（UIに表示しない） |
| Plan進捗・期限・残工数 | `app/Services/PlanProgressService.php` | 完了/取消除外、見積分加重平均、残分、期待進捗、Availability計算をServiceで実施 | **純粋計算関数化**。最初はAvailability未設定として扱う |
| Plan優先度 | `app/Services/PlanPriorityService.php` | 期限・期待進捗差・キャパシティ・履歴等に依存 | **簡易版**。入力と根拠を明示する決定的ランキング |
| 作業タイマー・WorkLog | `public/offline.html`、`app/Services/WorkSessionService.php`、`app/Http/Controllers/OfflineWorkSessionController.php` | 既存`offline.html`のIndexedDBはサーバー`snapshot`と`/offline/work-sessions/sync`を前提とする。一時停止・離席・UUIDは再利用候補 | **タイマー状態機械を参考に新実装**。既存の同期エンドポイントには接続しない |
| Study演習 | `resources/views/study_practice/show.blade.php`、`resources/js/study-practice-one-question.mjs` | Blade・Laravelセッション・POST依存。`study-practice-one-question.mjs`は旧フォームのページ送りに限定 | **表示構造のみ参考**。ローカル演習専用UIを作る |
| 旧Study回答ドラフト | `app/Http/Controllers/StudyPracticeController.php`、`docs/development/LEARNING_FIRST_DRAFT_CONTINUITY_V621_2026_10_15.md` | 旧方式はセット中の`draft_answers`保存と採点済みAttemptを別管理。1問入力時点で採点済み履歴を作らない | **意味論維持**。ローカル下書きと確定採点履歴を分ける |
| 新Study LearningRun | 旧画面から`plans.tasks.learning.index`へ分岐、`resources/js/learning-answer-autosave.mjs` | 新方式は1問単位、autosave JSは**server session authoritative**と明記 | **直接移植しない**。Previewでは独立1問ローカル採点を実装 |
| Question Pack・ローカル採点 | `resources/question_packs/ap/*.json`、`app/Services/QuestionBankGrader.php`、`app/Services/QuestionPackCatalogService.php` | `grading_rule`は`exact_choice` / `exact_multiple` / `numeric_tolerance`。既存JSON: Core 74、IPA 35、未公開候補80、補足10（ファイル内件数の静的照合） | **JSON schema/採点規則を再利用**。初版は監修済み単一選択だけを配布 |
| 学習履歴・弱点 | `app/Models/StudyPracticeAttempt.php`、`app/Services/StudyWeaknessPrioritizationService.php` | ServiceはDBの試行・重み・エラー分類等の複雑な履歴に依存 | **簡易版**。試行履歴と分野ごとの正答/総数のみ |
| Study/Developmentのトップ | `resources/views/workspace/study/index.blade.php`、`resources/views/workspace/development/index.blade.php`、`docs/development/PRELAUNCH_IA2J_STUDY_WORK_FOCUS_2026_10_15.md` | Blade部分表示、推薦/認証/DBからの合成が多い。WorkとAnalysisの役割は既に分離 | **IA/外観を参考**。Bladeそのものの静的再利用はしない |
| Roadmap/Timeline | `resources/views/plans/partials/roadmap-list.blade.php` | DB側Projectionやサーバー画面に依存 | **単純Task一覧・日別WorkLog履歴**を先行実装 |
| 星座・Map UI、GitHub自動Evidence、共同編集、AI自動生成、Premium/Pro | 各現行Service/UI（横断） | サーバー権限、連携、複雑なグラフ/課金等が必要 | **2026-12-01 Preview範囲外** |

**重要:** `public/offline.html`は既存サーバーの復帰用フォールバックであって、単独でPlan/Taskを作成・保存するLocal-firstアプリではない。また、ブラウザに配るQuestion Pack内の正答・採点規則は利用者が閲覧できる。試験監督/不正防止を保証する設計としては使わない。

### 出題コンテンツの公開ゲート

- 74問や35問がJSONに存在することは**公開承認**ではない。Coreには参照元・派生問題の扱いがあり、IPAには出典・利用条件がある。最新の[Learning/AP80停止記録](LEARNING_AP80_INTEGRATION_PAUSE_HANDOFF_2026_10_16.md)では未公開80問の人間・権利承認はゼロ。
- 公開パックは`question_id`、`source_type`、出典表記、本文/選択肢/解説の品質、人間レビュー状態を別途検査し、承認された項目**だけ**をビルドに含める。承認不明なら配布ゼロとしてデモ用の独立・権利クリア済み問題から開始する。未公開80問を誤って出荷しない。
- `grading_rule`の正答は配布ファイルから見えるため、練習・自己学習の用途に限る。解説は判定後に表示する。

## 3. Previewの独立構成（Phase 1実装候補）

`Static UI (Study / Development / minimal common shell)`
→ `Local Application Services (seed / progress / recommend / grade / work-session)`
→ `IndexedDB Repository (single transaction boundary)`。

- **物理分離:** 既存Laravelと切り離したStatic Web/PWAを別ホスト/オリジンで配布。`main`/`production`の既存Laravel routeやsession cookieと混用しない。Previewホスト名、配布手段、CSPおよびService Worker scopeはリリース前に別途固定し、既存`offline.html`/既存SWを上書きしない。
- **依存ゼロ:** Preview通常操作は`/api`、`/offline/work-sessions/sync`、OAuth、GitHub、AI、Aiven、Render Webを呼び出さない。外部CDN、外部フォント、分析SDKも初版は入れない。Network断/ログイン無しで全操作が成立する。静的ファイルの配信/更新は別途存在する。
- **DB分離:** 専用IndexedDB名 `canovia-local-preview-v1` を使用。既存`pacekeeper-offline-v1`等を直接読込・破壊しない。取り込みは人間操作と検証がある場合だけ。
- **Client:** `crypto.randomUUID()`が使えるsecure context（HTTPS / localhost）で実行。使えない環境では危険な識別子を発行せず操作を停止し案内する。IndexedDB書込失敗、容量超過、Private modeでは「保存済み」を表示しない。
- **モバイル:** PWAと既存SwiftUI+WKWebViewは保存領域、インストール削除、Service Worker挙動が異なるため独立受入。iOS公開やApp Store審査は本Previewの自動結果ではない。

## 4. Local data contract（初版規約案）

全レコードで`id: UUID`、`created_at/updated_at: ISO8601 UTC`、`schema_version`を定義。参照は整数Laravel IDではなくUUIDで行う。UTC保存、画面はユーザーローカル時刻/日付。Date-onlyの`deadline`はタイムゾーン変換によって日付がずれない文字列`YYYY-MM-DD`を使う。ユーザー入力文字列はtextContent等で無害化し、HTMLとして描画しない。

| Object store | 主要項目 | 不変条件 |
| --- | --- | --- |
| `profile` | `id`、`domains[]`、`goal/current_state`、`weekly_capacity`、`setup_version` | 端末内でのみ保持。認証ユーザーを暗黙に紐付けない |
| `plans` | `id`、`domain: study/development`、`title`、`description`、`start_date`、`deadline?`、`priority`、`priority_mode`、`seed_key?` | ドメインと日付の検証。公開・共同編集・サーバーowner IDは持たない |
| `tasks` | `id`、`plan_id`、`title`、`description?`、`status`、`estimated_minutes`、`remaining_minutes`、`progress_percent`、`priority`、`activation_cost`、`sort_order`、`next_action_note?` | Plan存在、0–100進捗、分>=0、`done`時remaining=0。初版は依存グラフなし |
| `work_sessions` | `id`、`plan_id`、`task_id`、`started_at`、`paused_at?`、`paused_seconds`、`ended_at?`、`status` | 有効なTask、開始<=終了、pause/resume二重適用禁止。再起動後も復元 |
| `work_logs` | `id`、`session_id?`、`plan_id`、`task_id`、`worked_on`、`actual_minutes`、`memo?`、`progress_before/after?` | 同一`session_id`の確定Logは高々1件。実績は保存するが時間だけで進捗を変更しない |
| `practice_sessions` | `id`、`plan_id`、`task_id`、`pack_id`、`question_ids[]`、`draft_answers`、`status`、`submitted_at?` | 下書きと採点済み確定を分け、同一セットで二重採点しない |
| `practice_attempts` | `id`、`session_id`、`question_id`、`answer`、`correct`、`topic_keys[]`、`graded_at`、`pack_version` | 出題時の選択肢と採点規則のスナップショットに照合し、追跡可能にする |
| `meta` | `schema_version`、`installation_id`、`last_export_at?`、`import_receipts[]` | アプリ全体のVersion、ID重複/再インポート防止の記録 |

更新は**必要な関連レコードを1つのIndexedDB transactionで確定**し、`tx.oncomplete`で初めて保存成功表示。期限切れ書込、タブ二重操作、削除済み参照、不完全レコードを拒否。確定した`practice_attempt`と`work_log`を推薦/集計の事実として使用。削除は明示確認、孤児Task/Log/Attemptを作らない。複数タブでのロストアップデート防止はPhase 1の必須検証事項（`revision`比較・排他等で設計）とする。

### 進捗と推薦の意味

1. `PlanProgressService`に合わせ、取消以外のTaskの`estimated_minutes`重み付きで進捗率を算出。`remaining_minutes`合計と`work_logs.actual_minutes`合計は別指標。Availability設定機能を移すまでは不足時間を精密に判定したふりをしない。
2. `WorkLog`保存→当日作業実績・履歴を更新。本人が明示入力/確認したTask進捗・残分だけ変更可能。AI推定や単なる経過時間で自動完了しない。
3. `practice_attempt`保存→正答率と`topic_key`別の正答数・出題数を再計算し、弱い分野（例: 回答2問以上で低正答率）を**観測された参考情報**として次の問題選択に使用。誤答理由・理解不足の原因・Task完了は推定しない。新しい問題が足りない場合はその旨を表示する。
4. 次Action候補の優先度は「未完了・開始可能・期限・ユーザー優先度・最近の実績・弱点（Studyのみ）」の明示ルールで導出する。推薦と本人が指定した現在Taskは別。選択の理由を1～2行で提示し、人間が変更可能。
5. RoadmapはTaskの`sort_order`と状態、Timelineは`work_logs`/`practice_attempts`の時系列。既存の高度Projectionや星座/Mapを再現したとは表示しない。

## 5. 将来のCloud統合とバックアップ

- **PreviewのLocal record IDは将来の移行用候補キー**であり、Cloud`plans.id`/`tasks.id`へ直接割り当てるものではない。移行用`source_installation_id + source_record_id`と新Cloud IDの対応表、所有者本人確認、所有権検証、検疫、冪等性を専用インポート工程で実装する。既存本番DBへ直接書き込まない。
- Phase 4のJSONは`format`、`schema_version`、`exported_at`、レコード配列、ファイル全体の整合性確認値（SHA-256等）を持つ。**破損検知**であって署名・改ざん耐性や秘密保持ではない。個人情報を含むこととローカル保存の消失リスクを利用者に説明。
- インポートはファイルサイズ・Version・型・参照整合性・UUID衝突・件数上限・ハッシュを全件検証し、**dry-runプレビュー→本人確定→一括transaction**。完全一致の同一データは再取込しない。異なる内容でUUID衝突した場合は全件拒否し勝手に上書きしない。
- **Exportしただけでは合格としない。** 新しい隔離ブラウザプロファイルでImport→Plan/Task/Session/Attemptが全て復元するE2Eが必要。バージョン更新、再取込、途中失敗も検証する。
- `window.localStorage`、IndexedDB、Safari Website Data、PWA削除、端末紛失は永続性を保証しない。データ損失の可能性と定期Export案内を明示する。「クラウドに自動保存」「後日必ず移行」は未実装として表示しない。

## 6. 実装順序とPR単位

| Phase | 実装PRの責務 | 次へ進むためのGate |
| --- | --- | --- |
| 0（本書） | コード対応表/最小データ契約/受入境界を固定。実装なし | ownerがスコープを確認。source-reviewedのみでruntime完成とは言わない |
| 1a | 独立Static shell + IndexedDB repository/Version/UUID + テスト | 新規/更新/削除/再起動/複数タブ/Quota失敗のE2E |
| 1b | 診断Seed + Plan/Task CRUD + 期限/基本Roadmap | 初回診断→作成→再起動→復元のWeb E2E |
| 2 | タイマー/WorkLog + 進捗純関数 + 根拠付き次Action | 離席・pause/restart/重複確定・完了更新・無断進捗更新なし |
| 3 | レビュー済み問題Pack + 1問UI + draft/採点/履歴/弱点 | 既出重複抑制、問題改版、正答スナップショット、誤判定なし |
| 4 | JSON Export/Import + migrations + integrity/duplicate checks | 新規隔離Profileで復元・二重取込拒否・旧Version扱い |
| 5 | PWA/スマホ実機 + Offline/First Use + Device復帰 | iPhone Safari/Android・PC・PWA境界の証拠とPreview公開GO/NO-GO |

公開を急ぐ場合でもPhase 4（バックアップ復元）をスキップしない。StudyとDevelopment双方の一連操作がE2Eで成功しなければPreviewを完成と宣言しない。

## 7. リリース前の必須安全・QAマトリクス

- **Storage:** IndexedDB利用不可/Quota/transaction abort/途中タブ終了/競合タブ/削除・インポート整合性/Date-only境界。
- **Execution:** timer pause・resume・away・端末時刻変更・reload・finish再送、WorkLog冪等、進捗更新の明示性。
- **Study:** 正答の可視性、`exact_choice`判定境界、空回答、下書き/確定の分離、設問バージョン固定、複数出題での重複排除、分野別分母の整合性、教材レビュー/利用条件。
- **Security:** 新オリジンとCSP、XSS（説明・ユーザー入力・Import JSON）、埋込外部URL/ネットワークリクエスト禁止、旧サービスworker・既存Laravel Cookie汚染なし、Importのスキーマ詐称/巨大入力拒否。
- **Recovery:** 故意に壊したバックアップはPreviewのみで拒否、元データ保持、再Export/再Import、将来移行用キーの保持。
- **Access & Release:** 公開先URL/静的ホスティング設定、配布ソースとビルド物の一致、Browser+iPhoneの実機結果、既存Canovia本番の`production`・`e858`・Render・Aiven・Auth0を変更していないこと。

**CIと手元E2Eはまだ実行していない。** 本書の作成・GitHub PRはPreview本体の実装、テスト成功、デプロイ、公開判断のどれも代替しない。

## 8. 次の作業で確定する未解決点

- Static Previewの配信ホスト名とService Worker scope、既存の公開Webと分離されるDNS/インフラ境界。
- Phase 1での同時タブ更新の排他方式とIndexedDB schema upgradeのトランザクション戦略。
- ユーザーに見せる初回質問数・Plan Seedの初期Task粒度（情報不足時に自動で補完・断定しない）。
- 教材の人間レビュー・著作権/出典の公開可否と、初回同梱する最小問題数。
- Cloud統合時の本人確認とインポートの採用時期（先行リリース時点では自動同期・自動移行を約束しない）。

## 9. 既存契約との整合と変更の影響

本書は[プロダクト正本](../CANOVIA_PRODUCT_SPEC.md)の「実績を次の行動へ結びつける」「FactsとInterpretationを分ける」「努力の権利は無料」と矛盾しない。新しいPreview専用保存モデルは既存DBスキーマ・Laravel認可を置換しない。既存の公開制御・Entitlement・共同計画機能もPreviewへ持ち込まない。

**今回の変更範囲:** 設計文書のみ。DB migration 0、ルート/Controller 0、認証/課金 0、既存`main`/`production`直書きなし、デプロイなし。公開roadmapの`rolling_out/released`表記を変更する根拠はまだない。

## 10. Phase 1a 実装契約（2026-10-23）

独立`local-preview/`を静的rootとし、既存Laravel/public/SWは変更しない。依存なしのES moduleと専用IndexedDBを採用。論理Object群は本書のモデルを維持するが、**物理的には単一state store/root snapshot**に格納する（schema1）。小規模Previewの上限20,000レコードで、参照整合性・関連更新・将来importを1 transactionで検証/確定するため。readwrite transaction内でrevisionを比較し、古いタブの更新は拒否、oncomplete後だけ成功を返す。BroadcastChannelは通知のみで排他の根拠にしない。unknown schemaは消去せずBLOCK、quota/abortで既存データを保持する。将来大容量化は別migration設計。

ローカルfixtureは127.0.0.1:18799だけで静的rootを配信しCSPを設定する。配布先の独立origin/同等headers/HTTPSは公開前の未解決gate。repositoryの実ブラウザ試験は競合/abort/容量失敗/再読み込みを対象とする。実機・配備・公開承認は未実施。

## 11. Phase 1b 診断・Plan・Task

初回は領域/目標/現在地/週の分数/任意期限を本人入力し、Planと編集可能な最初の2 Taskを同じtransactionで作成。情報不足から能力を推測しない。Study/Developmentは専用切替、期限/単純Roadmap/CRUDを提供。Task進捗は本人の明示保存のみ、完了指定で100%・残0。実績を持つTaskの削除は拒否し取消を案内、Taskを残すPlanの削除も拒否する。ユーザー文字列はHTML escape、競合時は入力を保って更新を拒否し明示refreshを促す。診断と履歴は端末外へ送信しない。

## 12. Phase 2 実行ループ

作業は単一active WorkSession、保存した秒数と本人確認Task進捗を分離。UIの単調時計で5秒ごとにcheckpoint（同一revision transaction）し、pause/resume/finishを状態機械で処理、同sessionのLogは1件のみ。見積加重進捗・残分・実績分を別表示、取消を進捗から除外。推薦は本人priority/doing/実績未記録/順序の決定的な参考候補。

離席visibilitychangeでpauseを試みるがpage closeの非同期書込成功は保証しない。reload/別タブ復帰では保存済みactive秒数だけを残してrunningをpausedにし、未観測の離席時間を加算しない。古いタブはCAS失敗で停止。時計差5秒超/観測間隔15秒超は未観測時間を加算せずpause、書込失敗はpulseを停止し成功表示しない。Task状態・進捗は実績時間や正答率では更新しない。これは稼働率/勤怠保証ではなく、短い未保存区間が失われ得る保守的な個人記録。

## 13. Phase 3 学習ループ・教材境界

既存QuestionBankGraderのexact_choice意味論を、1問LearningRun相当の独立ローカルsessionに移植。選択時にdraftだけ保存、本人の確定操作でsnapshotに対して決定的採点しAttemptを1件作成、二重採点拒否。全選択肢/回答本文/正答/解説を表示し、後のpack変更で履歴を再採点しない。分野別集計は実Attemptのみ、2問以上かつ70%未満を参考弱点候補とする。未出題を優先、同じ出題回数なら弱点分野を優先。原因や習熟/Task完了は推測しない。同一問題再回答を別問題確認と区別する。

同梱は第三者素材を含まない新規オリジナルの**動作検証専用**6問（簡単な数・論理）。review_status=validation_only、AP試験教材の承認とは扱わない。既存AP Core/IPA/AP80 JSONは一切コピーしない。一般向けrelease artifactの作成には、別途人間レビュー・教材公開承認と静的release gate通過を必須とする。本コードのGitHub統合/ローカル動作は教材公開ではない。

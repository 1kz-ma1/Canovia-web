# V6.5 — PR #513: Task 0件から本人の操作だけで最初のTaskを作れるか

> 2026-10-15 JST。**初回FreeユーザーのHTTPルート統合検証**。候補の採択・本番公開・実ブラウザ/物理iPhone E2Eではない。
> 対象は既存Draft PR #513の**完全固定SHA** `bb8248f25545ae0d600fbb68f66c41c360004e2c`。候補のコード・commit・production branchを変更しない。

## V6.4からの改善点

V6.4の通しテストはPlan保存→Workspace→実行→Evidence保存まで動いたが、**途中でTaskをテストfixtureに直接挿入**していた。V6.5では、新たなテスト`tests/Feature/Release513FirstActionWithoutSeedV65Test.php`で**Taskモデルの手動挿入を一切行わず**、候補に実在するHTTP操作を通す。

### 学習：範囲を人間が確定→実際のActionを実行

1. Freeの合成アカウントで`POST plans.store`→資格学習Plan作成。初期Tasks=0。
2. `POST plans.study_action.execute`はまだTaskを作らず、`plans.study_scope.index`へのリダイレクトを返す（先に範囲を把握する）。
3. `POST plans.study_scope.store`で合成PDFをアップロード。テストでは`native_ai.driver=disabled`、秘密情報・実外部AI送信なし。
4. `POST plans.study_scope.confirm`にて本人の明示した数学/ネットワークなどの範囲を確定。まだTasks=0。
5. **二度目の`POST plans.study_action.execute`で本物のStudyActionTaskProjectorが最初のTaskを生成**し、実行画面へ遷移することを確認。再POSTしてもTaskは重複しない。

合格できても、ブラウザ上で「範囲資料の準備」と「Scope確認」をユーザーが直感的に操作できるか、問題Bankが用意できるか、適応レコメンドの教育的妥当性は未確認。

### 開発：空Planから初期タスク作成画面→本人がJSONを取り込む

1. Freeの合成アカウントで`POST plans.store`→個人開発Plan作成。Tasks=0。
2. Development Workspaceに`初期タスクを作る`が表示され、`plans.ai_task_assistant.show`へ遷移できること。
3. **本人が`tasks_json`に有効なPlan Generation JSONを貼り付け**、`POST plans.ai_task_assistant.import`で最初のTaskが作られること。再POSTしても重複しない。
4. 作成されたTaskで`GuidedExecution`のprepare→reflectを実行し、TaskEvidence保存と進捗の無断更新なしを確認。

**重要な制限**：開発側はTaskのDB fixtureをなくせても、依然として**外部生成/手書きJSONの用意が必要**。これは「即座に一つの行動を出す」という初回価値に対して摩擦が高い可能性がある。HTTP経路が動いても自動パーソナライズの初回体験合格ではない。

## 実行分離

`.github/workflows/development-release513-v64-pinned-p0.yml`の固定候補CIにV6.5を追加。PRのテストファイルを`audit/`、固定候補を`candidate/`にcheckoutし、HEADが候補SHAと完全一致することを2回確認。検証用PHPファイルだけを一時的にコピーし、秘密情報なし・合成データ/SQLite in-memoryで`php artisan test`を実行。これはGit commitや本番環境への変更ではない。

V6.4/V6.5のHTTP統合成功・実際の初回利用価値・CI成功・本番承認を混同しないこと。公開前にはPC実ブラウザ、物理iPhone、認可・CSRF・AI障害時・ログイン復帰・本番前データ復旧の独立受入が必要。

## 製品P0判定

- 学習P0-03は**動作する候補のHTTP経路を検証**できる。ただし新規ユーザーの実UI操作と完走時間は未計測。
- 開発P0-03は**ユーザーによるJSON貼り付けを条件としてTask生成**。この手順を省いたゼロ摩擦導線の動作は未確認。
- P0-06の学習Evidenceに基づく本当に適切な次Actionと、開発Reflectionからの自動次Actionは未確認のまま。
- P0-01/02/07/08のFree実ユーザー完走と同一ファネル、Web・iPhone実機、Aiven/Render/復旧・本番GOは別ゲート。PR #513の**選定状態は`BLOCKED_NEEDS_P0_E2E`のまま**。

**Next**：開発側の「AI生成JSONを貼る」摩擦を取り除くには、V6.5テストの成功だけでなく、既存のPlan Action Draftや開発Workspaceの直接開始導線を比較し、オーナー選定のスコープで別の機能改善候補を設計する必要がある。候補PR #513を勝手に改変してはならない。

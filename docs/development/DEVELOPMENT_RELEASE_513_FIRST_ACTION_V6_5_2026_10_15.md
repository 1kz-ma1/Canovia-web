# V6.5 — PR #513: Task 0件から本人の操作だけで最初のTaskを作れるか

> 2026-10-15 JST。**初回FreeユーザーのHTTPルート統合検証**。候補の採択・本番公開・実ブラウザ/物理iPhone E2Eではない。
> 対象は既存Draft PR #513の**完全固定SHA** `bb8248f25545ae0d600fbb68f66c41c360004e2c`。候補のコード・commit・production branchを変更しない。

## V6.4からの改善点

V6.4の通しテストはPlan保存→Workspace→実行→Evidence保存まで動いたが、**途中でTaskをテストfixtureに直接挿入**していた。V6.5では、新たなテスト`tests/Feature/Release513FirstActionWithoutSeedV65Test.php`で**Taskモデルの手動挿入を一切行わず**、候補に実在するHTTP操作を通す。

### 学習：範囲未登録からTaskを直接投影（固定候補CIでの実測）

1. Freeの合成アカウントで`POST plans.store`→資格学習Plan作成。初期Tasks=0。
2. **候補実測の結果、`POST plans.study_action.execute`は範囲未登録でも最初のTaskを直接作成し、`plans.tasks.study_practice.show`へ遷移する。** 元の設計予想だった`plans.study_scope.index`へのリダイレクトとは異なる。
3. 同じPlanにTaskが1件だけ作成され、Taskの画面とWorkspaceが表示できること、再POSTによってTaskが増殖しないことを検証する。

学習範囲未確認のまま実践Taskが生成されるため、「十分な情報なしでも次の行動へ進める」というUXの利点と、「範囲/理解度に根差した推奨か不明」という品質リスクを別に記録する。実際の学習問題が出題可能か、自然な手順で正しく採点・保存されるか、適応推薦が妥当かはこのテストの成功だけでは証明できない。

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

- 学習P0-03は**Task=0件から直接作成するHTTP経路を固定候補で実測**できる。ただしScope未確認の妥当性、新規ユーザーの実UI操作と完走時間は未計測。
- 開発P0-03は**ユーザーによるJSON貼り付けを条件としてTask生成**。この手順を省いたゼロ摩擦導線の動作は未確認。
- P0-06の学習Evidenceに基づく本当に適切な次Actionと、開発Reflectionからの自動次Actionは未確認のまま。
- P0-01/02/07/08のFree実ユーザー完走と同一ファネル、Web・iPhone実機、Aiven/Render/復旧・本番GOは別ゲート。PR #513の**選定状態は`BLOCKED_NEEDS_P0_E2E`のまま**。

**Next**：開発側の「AI生成JSONを貼る」摩擦を取り除くには、V6.5テストの成功だけでなく、既存のPlan Action Draftや開発Workspaceの直接開始導線を比較し、オーナー選定のスコープで別の機能改善候補を設計する必要がある。候補PR #513を勝手に改変してはならない。

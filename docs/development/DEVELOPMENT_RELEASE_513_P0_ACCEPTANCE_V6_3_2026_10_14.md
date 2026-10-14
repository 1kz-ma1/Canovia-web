# Canovia V6.3 — PR #513 初回価値P0実装照合・不足受入監査

> **2026-10-14 JST / 実装・テスト参照の監査。公開候補の採択ではない。**
> 実在するDraft [PR #513](https://github.com/1kz-ma1/Canovia-web/pull/513) のHEAD `bb8248f25545ae0d600fbb68f66c41c360004e2c`を変更せず評価した。比較元GitHub `production`は`15abdc9de6f24b5250fb255c579d1aa55facaf1a`。これは**現在稼働中のRender Live SHAの新規実測ではない**。
> 出典：GitHubで各`tests/Feature/*.php`を上記**候補SHA指定で読み取った**コード証拠、PR #513の変更ファイル全219件の一覧、固定候補SHAのworkflow実行状況。Canoviaの実アカウント/本番/物理iPhoneを操作した実行結果ではない。

## 結論

**PR #513は「8つのP0価値循環を完成・実ユーザー検証済み」と判定できない。現状は`BLOCKED_NEEDS_P0_E2E`。** 一方、該当テスト・モデル・UIが全く存在しないわけではない。個別の機能を裏づけるテストがあることと、新規ユーザーが実際の画面を一周できることは別々に扱う。

V6.2のP0を以下の8ゲートとして、証拠名と次の実証試験を固定した。各GitHubリンクは**候補HEADのソース**であり、最新mainの変更を混ぜない。詳細なJSON版は [固定監査台帳](audits/release_513_p0_evidence_2026_10_14.json)。

| Gate | PR #513の既存ソース・テスト | 未確認／追加受入（完了と言わない） |
| --- | --- | --- |
| P0-01 登録・復帰 | [FirstRunUxV4123Test](https://github.com/1kz-ma1/Canovia-web/blob/bb8248f25545ae0d600fbb68f66c41c360004e2c/tests/Feature/FirstRunUxV4123Test.php) と [WorkspaceResumeAndSessionScopeV5612Test](https://github.com/1kz-ma1/Canovia-web/blob/bb8248f25545ae0d600fbb68f66c41c360004e2c/tests/Feature/WorkspaceResumeAndSessionScopeV5612Test.php) | 新規Freeユーザー登録→開始→ホーム/専門Workspace→ログアウト→再ログイン→同じPlanの継続を公開予定の設定で一気通貫 |
| P0-02 目標と現在地 | [AdaptiveGoalDiscoveryV4114Test](https://github.com/1kz-ma1/Canovia-web/blob/bb8248f25545ae0d600fbb68f66c41c360004e2c/tests/Feature/AdaptiveGoalDiscoveryV4114Test.php)、[ConversationalOnboardingV4116Test](https://github.com/1kz-ma1/Canovia-web/blob/bb8248f25545ae0d600fbb68f66c41c360004e2c/tests/Feature/ConversationalOnboardingV4116Test.php) | 既知情報を聞き直さず、AIが使えないケースでもユーザーが手助けなしに実行可能な行動へ進めるかを実測 |
| P0-03 最初の行動 | [PlanActionFirstCreationV5866Test](https://github.com/1kz-ma1/Canovia-web/blob/bb8248f25545ae0d600fbb68f66c41c360004e2c/tests/Feature/PlanActionFirstCreationV5866Test.php)、[StudyAdaptiveActionV536Test](https://github.com/1kz-ma1/Canovia-web/blob/bb8248f25545ae0d600fbb68f66c41c360004e2c/tests/Feature/StudyAdaptiveActionV536Test.php)、[DeveloperHomeV573Test](https://github.com/1kz-ma1/Canovia-web/blob/bb8248f25545ae0d600fbb68f66c41c360004e2c/tests/Feature/DeveloperHomeV573Test.php) | **最優先の連続テストがない**。Plan作成テストはTasksが0件のままでも正常としており、タスク0件でも仮想Action/案内が実際に表示され、行動を開始できることを別途確認する |
| P0-04 実行 | [GuidedExecutionV4114Test](https://github.com/1kz-ma1/Canovia-web/blob/bb8248f25545ae0d600fbb68f66c41c360004e2c/tests/Feature/GuidedExecutionV4114Test.php)、[AdaptiveLearningSingleQuestionV5878Test](https://github.com/1kz-ma1/Canovia-web/blob/bb8248f25545ae0d600fbb68f66c41c360004e2c/tests/Feature/AdaptiveLearningSingleQuestionV5878Test.php) | 新規Planから実行導線をたどり、学習1問または開発作業1件を実ブラウザで完了。問題Bankが空の場合やGitHub未接続の場合も逃げ道があるか |
| P0-05 保存と再開 | [StudyPracticeDraftPersistenceV4071Test](https://github.com/1kz-ma1/Canovia-web/blob/bb8248f25545ae0d600fbb68f66c41c360004e2c/tests/Feature/StudyPracticeDraftPersistenceV4071Test.php)、[GuidedExecutionV4114Test](https://github.com/1kz-ma1/Canovia-web/blob/bb8248f25545ae0d600fbb68f66c41c360004e2c/tests/Feature/GuidedExecutionV4114Test.php) | 連続した新規ユーザー操作で実績が同一Plan/Taskに保存されるか、ブラウザ終了/Session喪失/再ログインで消えないか、再送で二重記録されないか |
| P0-06 次の行動を更新 | [StudyAdaptiveActionV536Test](https://github.com/1kz-ma1/Canovia-web/blob/bb8248f25545ae0d600fbb68f66c41c360004e2c/tests/Feature/StudyAdaptiveActionV536Test.php)、[StudyPracticeLearningLoopV393Test](https://github.com/1kz-ma1/Canovia-web/blob/bb8248f25545ae0d600fbb68f66c41c360004e2c/tests/Feature/StudyPracticeLearningLoopV393Test.php)、[DeveloperTaskAssociationV572Test](https://github.com/1kz-ma1/Canovia-web/blob/bb8248f25545ae0d600fbb68f66c41c360004e2c/tests/Feature/DeveloperTaskAssociationV572Test.php) | **最優先の連続テストがない**。学習Evidence→復習Action、開発作業Evidence→次Task/見直しを画面で追い、誤った自動進捗や無断更新がないことを確認する |
| P0-07 無料で完結 | [FeatureAccessV406Test](https://github.com/1kz-ma1/Canovia-web/blob/bb8248f25545ae0d600fbb68f66c41c360004e2c/tests/Feature/FeatureAccessV406Test.php)、[ConversationalOnboardingV4116Test](https://github.com/1kz-ma1/Canovia-web/blob/bb8248f25545ae0d600fbb68f66c41c360004e2c/tests/Feature/ConversationalOnboardingV4116Test.php) | 課金/Entitlement無しのFree利用者がP0-01～06を完了できるか。AI失敗時の代替、利用回数、権限フラグを候補実設定で確認 |
| P0-08 観測 | [EarlyAccessObservabilityV5823Test](https://github.com/1kz-ma1/Canovia-web/blob/bb8248f25545ae0d600fbb68f66c41c360004e2c/tests/Feature/EarlyAccessObservabilityV5823Test.php)、[BehaviorTrackingTest](https://github.com/1kz-ma1/Canovia-web/blob/bb8248f25545ae0d600fbb68f66c41c360004e2c/tests/Feature/BehaviorTrackingTest.php) | Cohort/訪問/Feedbackのテストはあるが「最初のAction表示→実行→保存→次Action」が同一ユーザー内で追えること、PII過剰収集しないことは未実測 |

**特にP0-03/P0-06は、個別機能テストがあるのに初回価値循環の連続性を証明できないゲート**。これは「実装されていない」と断定するものではない。実装あり/テストあり/連続E2E未確認を分けること。

## 固定候補のCIと、今回実行していない検証

GitHubのPR #513のHEAD `bb8248f...`のworkflow履歴で確認できる成功：
- Production Promotion Policy Gate: success (run `38287330118`)
- Production Migration Recovery Validation: success (run `38287330100`)
- P0 Disposable MySQL Migration Integrity: success (run `38287330136`)
- V56.12 Workspace Resume Validation: success (run `38287330121`)

これは**2026-10-13の候補HEADで実行された自動CI**。今回この候補への追加変更や再実行はしていない。CI成功は新規FreeユーザーのP0-01～08連続E2Eの完了ではない。

GitHub [PR #513](https://github.com/1kz-ma1/Canovia-web/pull/513) に記録された2026-10-13のAiven metadata監査とバックアップ存在観測は、復元実証でも新しいmainのDB監査でもない。GitHub実測結果とRender Live稼働実証も混同しない。

## 次の受入テスト：まず手動で一周できるか

**安全な独立テスト環境**（本番DBと接続しない。合成ユーザー/Planのみ）で、公開候補の**同一固定SHA**を起動して確認する。必要ならテスト用のFake AI/Bank/GitHub Sourceを使い、実API認可を検証したとは扱わない。

1. **学習A**：新規Freeユーザー→目標「応用情報」→現状診断→Plan作成（Task0件も検査）→今日の学習Action→固定/正当な問題1件→回答・結果・弱点記録→次Action更新→ログアウト・再ログイン→同じPlan/実績が復帰。
2. **開発B**：新規Freeユーザー→目標「Webアプリを公開」→Plan作成（Task0件）→最初の開発Action→GitHub未接続でも1件の手動作業→Evidence/Reflection→次Action→再ログイン後の復帰。GitHub連携UIは未接続を明示し、外部証拠を捏造しない。
3. **例外C**：AIプロバイダ停止/問題Bank空/遅延/二重POST/古い画面/セッション失効/権限外ユーザーを含め、案内か代替経路で復帰し、プライバシー侵害やデータ消失がないこと。
4. **UX**：5名程度の候補初期テスターが実際に操作し、10分以内の最初の行動提示・理解・保存完了を観測する（V6.2で提案した目標、**未採択・未計測**）。対応チャネルでPCと物理iPhoneを個別に記録する。
5. **可観測性**：同じ匿名/仮名テストユーザーのファネルとFeedbackを照合。画面表示だけでなく保存先の実績を確認し、個人情報/生成内容の過剰ログがないか確認。

**合格証拠形式**：候補SHA・テスト環境/公開チャネル・日時・テスト種別・ブラウザ/デバイス・テストユーザー(匿名化)・各操作/期待/実測/バグID・テスト担当を紐付けた受入表。PASSは実測した項目に限る。必須項目にBlock/未確認があれば候補は`BLOCKED_NEEDS_P0_E2E`のまま。

## V6.3オフライン検証器

`scripts/ci/development_release_v63_p0_evidence.py`と固定JSON台帳は**管理すべき証拠項目の完全性と嘘のGO防止**用。8ゲート・候補SHA・参照テスト形式とE2E未確認/公開不可が変化していないことを確認する。通常CIは台帳の形式だけを検査するため、**mainのテストが存在することを候補の証拠に置き換えない**。

すでにクローンした固定候補をオペレーターが所持する場合、オフラインで以下を実行できる（副作用・ネットワーク無し）。`--candidate-repo`はHEADが固定候補SHAと完全一致し、列挙されたPHPテストメソッドがそのcheckoutに存在することだけを検証する。**テストを実行したり実ユーザー動作を証明するものではない。**

```sh
python3 scripts/ci/development_release_v63_p0_evidence.py \
  --candidate-repo /path/to/existing/pinned-pr513-clone
```

成功しても常に`result=BLOCK`・`release_authorized=false`。パスがない/候補HEAD不一致/偽のPASS値/未確認削除/テスト参照不一致は明示的に拒否。台帳を含む開発PRのCI成功も候補選定・本番GOを意味しない。

## 判断

- **PR #513は現段階では選定保留（公開GOではない）。** P0-03とP0-06を中心に、学習/開発の実利用連続E2Eを先に実施する。
- **学習と開発は分離可。** 一方の領域だけ通った場合は、未達領域のWebルート/API/公開案内も含めて遮断を検証し、限定Alphaの範囲をオーナーが別途判断する。
- PR #513に本当に必要な安全修正が未収録なら、候補を無断修正せず、修正の依存関係を再監査して新候補/縮小候補と比較する。
- 仮にP0連続E2Eが成功しても、Aiven本番の現在の識別/ledger・実行可能な復旧・Render LiveのSHA・GitHub productionのowner承認・物理iPhone受入・明示的な本番go/no-goは独立条件のまま。

`production`、PR #513、実Render/Aiven環境・秘密情報・ユーザーデータは一切変更していない。

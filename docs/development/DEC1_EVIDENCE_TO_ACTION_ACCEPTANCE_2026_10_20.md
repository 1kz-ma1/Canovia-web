# 12/1先行リリース — Evidence → Current State → Next Action 横断受入

2026-10-20 JST · owner-directed integration slice.
**DEC1_CROSS_MODE_TEST_CANDIDATE / REAL_AIVEN_UNVERIFIED / PRODUCTION_NO_GO_HOLD**

正本：[Release Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488)、[統合マスター](CANOVIA_INTEGRATION_FREEZE_RELEASE_MASTER_2026_10_16.md)、[e858監査](integration/2026-10-18-b/README.md)。

## 目的

**普段別々に行う学習・開発の行動が、本人が許可した範囲でCanoviaのEvidenceになり、現在地と次の一歩へ届く。**

初期公開は「どんな外部アプリからでも自動同期」「GitHubマージで自動100%」「ChatGPT連携完了」「本人確認なしの横断情報取得」を約束しない。AP科目A=11/10、科目B=11/25と12/1先行公開を両立するため、既存の構造を再利用し、最短のユーザージャーニーを検証する。新規大規模エンジンは不要。

- Observation: 真に観測された出所つき事実。GitHub webhookはroutingのみで、REST再取得が権威。
- Evidence: ownerと正しいTaskにひも付いた事実。学習結果の保存とTask進捗は別の判断。
- State/Action: 既存のStudy / Development Intelligenceで現在地と次の行動を決める。確信できない数値を捏造しない。
- Home: 両Modeの優先Actionと現在地を横断表示。詳細は専門Workspaceへ渡す。
- Timeline: 現行実装はWorkLog、共同作業、Plan完了の投影が中心。全Study AttemptやGitHub Observationの統合feedとはまだ言えない。

## 現行ソースの実装境界

| 領域 | 現行コードにあること | 残る実証 |
| --- | --- | --- |
| Study | [Practice評価の保存](../V39.3_STUDY_PRACTICE_LEARNING_LOOP.md)、[次の演習推薦](../V56.14_STUDY_RECOMMENDATION_SURFACE.md)、[V6.10合成Question Bankの実ブラウザ2問と3問目への再開](LEARNING_BROWSER_P0_V6_10_2026_10_15.md) | 10問の結果→弱点→次の推薦の同じactorでの実ブラウザE2E、問題品質、実機 |
| Development | [GitHub Observation](../V57.1_DEVELOPER_ACTIVITY_OBSERVATION.md) → [人間によるTask関連付け](../V57.2_DEVELOPER_TASK_ASSOCIATION.md) → [Evidence](../V53.7_DEVELOPER_EVIDENCE_SYNC.md) | 実GitHub Appの認可・webhook・REST・UI→Evidence→次Actionの連続E2E |
| Home | [Overview](../V54.5_OVERVIEW_WORKSPACE.md)、[Relevant-only](../V58.65_OVERVIEW_RELEVANCE.md) | 同じ実本人が学習・開発をした後の更新とiOS/PC表示。共通Timelineへ全Evidence自動流入は未実装 |
| 他の学習サービス | [合成provider Activity intake](../V55.9_PROVIDER_CONNECTION_AUTHENTICATED_ACTIVITY_INTAKE.md) | 実外部学習providerのOAuth/署名/本人/失効受入。合成で通っても実provider同期とは呼ばない |

## 12/1価値を判定する3本の実ユーザージャーニー

### A — 学習の積み上げ

1. 本人FreeアカウントがStudy Planと初Taskを画面で作成。初Taskをfixtureで注入した場合は「first action未検証」と明記。
2. 出典/権利確認済み教材、または隔離環境の合成教材で10問解答。回答途中draftと採点済みAttemptsは分離。
3. 成績/弱点/回答数を保存し、再読込・ログアウト/ログイン後も同じPlan/Taskで継続。
4. 次のPractice推薦が新しい記録を使い、根拠/確信度を表示しているかをBefore/Afterで確認。
5. 本人の進捗反映承認なしに、Task status・progressが勝手に変化しない。

**GO条件:** 正しい本人における画面→採点→保存→次の演習→再訪の同一データ/根拠/権限の一致。既存V6.10の2問ブラウザテストだけでは完走扱いにしない。

### B — 開発の積み上げ

1. 開発Planを作り、最小の読み取り権限で本物のGitHub Appインストールを本人が許可。Private Repoを公開させない。
2. PR、Issue、commit、CI更新が署名済みwebhook→既存GitHub REST再取得→本人PlanのObservationへ反映。
3. Taskが未特定なら「観測済み／未関連付け」。本人がTask候補を確認するとEvidenceへ変換。
4. Developer Homeで事実・Release Gap・次の検証Actionが確認できる。CI/mergeだけでTask doneや本番Deploy済みとはしない。
5. webhook重複、古い更新、別installation、取消、他人のPlanの負例が遮断される。

**GO条件:** 実installation・実API・画面と保存データの一致。合成EvidenceをテストDBへ直接作っただけでは本物のGitHub同期とは言わない。

### C — ホームで横断反映

1. A/Bと同じ本人がOverviewを開き、Study + DevelopmentのMode summaryと全体のCurrent Actionへ到達。
2. 学習の新Attemptと開発の新Evidenceの前後で、関連Workspaceの状態と次Actionが保存済み事実に合う。古いキャッシュを最新と偽らない。
3. Evidenceがなければ虚偽のReadinessを表示しない。個人のTask進捗を本人同意なしに増やさない。
4. 別ユーザー／別Planやprivate内容を表示しない。GETだけでPlan・Task・Evidenceを作成しない。
5. Safari/実iPhone WKWebViewで左右のMode、Current Action、戻る、ログイン後の復帰、403/419表示を受入。

**GO条件:** 同一actorの両活動→同一ホームの最新現在地→各専門Workspaceの証拠、というBefore/After E2E。2枚の固定Planカード表示だけでは「実績反映完了」とは呼ばない。

## 証拠のレベルを分離する

| Level | 対象 | 判定 |
| --- | --- | --- |
| L0 契約 | この文書。source identity、権限、progressの境界固定 | 本PRで追加 |
| L1 合成PHP | Dec1EvidenceToActionCrossModeTest: 同じownerに保存した合成学習Attempt/Evidence + 合成GitHub Evidence → Overview + 他アカウント拒否 + Task進捗不変 | 最終HEADの新規workflow greenが必要。**実providerではない** |
| L2 合成Chromium | 既存V6.10/V6.9を再利用し、**同じ本人・同じ使い捨てDB**でStudy→Development→OverviewのBefore/After。UIのクリック、CSRF、保存、再ログインを確認 | 今後の独立実装/レビュー。既存別々のbrowser CIは代わりにならない |
| L3 実Providerと実機 | 本人GitHub installation/webhook、Studyの許諾済み実問題、Safari・物理iPhone/WKWebView | 実環境個別受入。認証/permission変更は別承認 |
| L4 本番公開 | 固定review済みrelease、実Aiven ledger・物理metadata、backup/復旧、Auth0/session、オーナーGO、手動Renderと実機検証 | **PRODUCTION_NO_GO_HOLD**。このPRでは実施しない |

新しいテストfixtureは、StudyPracticeAttempt と TaskEvidenceService が記録した学習Evidence、GitHub接続済みを模したRepositoryと直接作成した開発Evidenceを使う。**これはfake provider、fake CI/mergeであり実GitHubを接続した証拠ではない。** 合成AllAccess grantを使うためFreeユーザーの利用資格証明でもない。

## 統合と日程

- 10/20〜10月末: L1で横断データ境界を固定。L2用に既存safe browserテストの再利用範囲と不足を明示。既存e858本番安全性ゲートの審査は別レーン。
- 11/10 科目Aまで: 新規機能は原則停止、既存Study/DevelopmentのP0とL2検証を優先。
- 11/11〜11/25 科目B: テスト結果の確認と重大バグ以外の開発は最小。実機受入の時間を短く確保。
- 11/26〜11/30: 候補固定・本番DB/復旧/認証/端末の受入。新機能追加を停止。
- 12/1: ゲートを満たしたスコープだけ公開。未達なら告知日を変更。公開日を理由に安全性を緩めない。

## 今回のPRが変更すること・しないこと

新規の合成PHP回帰テスト1、隔離CI1、本書1。既存app source、migration、SQL/reference、package lock、feature flags、外部接続、e858固定監査candidate、production、Render/IdPは不変更。mainへ統合しても固定e858 releaseへ自動採用しない。本番用のRead・DDL・env編集・deploy・OAuth登録・GitHub App権限編集は別のオーナーGOが必要。

**Status: DEC1_CROSS_MODE_SYNTHETIC_EVIDENCE / REAL_PROVIDER_E2E_PENDING / REAL_AIVEN_UNVERIFIED / PRODUCTION_NO_GO_HOLD**

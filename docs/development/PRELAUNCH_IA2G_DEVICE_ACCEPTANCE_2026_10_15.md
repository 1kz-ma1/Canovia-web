# Pre-Launch IA-2g — モバイルWebKit事前検証と実機受入判定表

**2026-10-15 / Experience lane / code SHA単位の検証。未デプロイ。** 本書は受入の**手順と空の記録欄**であって、iPhone実機または本番配信の合格報告ではない。

## 対象・実行環境の区別

| 検証対象 | 実施方法 | 主張可能な範囲 |
| --- | --- | --- |
| Chromium desktop | 既存IA-2e, Ubuntu+SQLite, 1280x850 | 初回利用からEvidence/再開までの実ブラウザ機能 |
| **WebKit mobile-size/touch (IA-2g)** | 新しい独立GitHub CI、Linux WebKit、390x844、isMobile/hasTouch | **WebKitレンダリングエンジンの事前E2E**。iPhone実機/Safariアプリの検証ではない |
| iPhone Safari/PWA | オーナー/実機テスターがiPhone上で操作 | OSキーボード、Safe Area、履歴、Cookie、入力復帰、横幅・スクロール、画面の理解 |
| iPhone WKWebView | `1kz-ma1/Canovia-iOS`のXcode実機ビルドでWeb URLを開く | Native shell/OS bridge、同じWeb機能、リンク、戻る、写真/ファイル入力、再起動時のSession |
| Mac Safari | オーナーが実ブラウザで操作 | Safariでのフォーム、Cookie、状態の再開 |
| Static Canovia-Welcome & Render Web Live | **別々の**静的/動的Renderリリース後の実URL | 配信された**正確なSHA・Release Level・権限範囲**に限る。本番は現時点で古いバージョン |

現在Render Static/WebはどちらもAuto Deploy OFF。実機iOS Shellの`WKWebView`はWebを共有しているため、**現行Live URLで初回体験の新機能を検証するのは不可**。開発`main`へCIを追加しても、Liveへのデプロイやリリース審査を自動承認しない。

## WebKit事前テスト（開発CIで完了できる部分）

- `.github/workflows/prelaunch-ia2g-mobile-webkit.yml`：`workflow_dispatch`もしくは対象PRでLinux WebKit専用エンジンをインストールし、既存IA-2eと**同じユーザー操作**を390pxタッチviewportで実行。
- ゲストWelcome → 「まず見てみる」 → 空Plan Overview → 任意Plan作成 → ユーザー登録/Plan引継 → 本人がフォームで1 Task作成 → Guided Execution → 振り返りEvidence → 本人承認Next Action → Plan Dashboard/本人のEvidence → Global Overview → 次の作業 → Logout/Login復帰。
- 本人のEvidenceは作業ログの時間・進捗計算と別枠。毎回新しいDisposable SQLiteにのみ書き込み、`APP_ENV=testing`、`CANOVIA_PUBLIC_RELEASE_LEVEL=1`、`NATIVE_AI_DRIVER=disabled`、ループバックの環境ガードを維持。GitHub/本番/Aiven/外部AIへの書き込みなし。
- モバイル390pxの実ブラウザE2Eが初回実行で発見した不具合: `resources/css/app.css`の`@media (max-width:767px)`で`.adaptive-entry-card`を非表示にしていたため、空Planの`[data-plan-optional-task-planning]`も見えず、最初のTask作成で行き止まりになった。**空Planだけ`data-plan-empty-entry`を付与してモバイル導線を再表示**し、既存の非空Planの通常ルールは維持。リンクの可視性と実クリックをWebKit E2Eが検証する（合格には新CI HEADでの再実行が必須）。
- **成功しても** iPhone SafariやWKWebViewの互換性・画面のわかりやすさを保証しない。

## 実機テスター向け手順（必ず正確なリリースSHAと環境名を記録）

本番・Aivenや自分の大切な本物のPlanで試さず、**権限が明示された専用の検証アカウントと削除できるデータ**を使う。固定のRelease候補と実サイト/端末が準備できてから、次の順で確認する。**本番URLに新実装が配信されていない場合、合格判定をつけない。** 独立して設定するL0/L1/早期アクセス権限を超えた機能や他人のPlanを開こうとしない。

1. iPhone Safariでページを開き、初回Welcomeに「診断する」「相談する」「まず見てみる」がわかりやすく並び、「まず見てみる」が診断を強制しないことを確認。PlanなしOverviewに架空の達成実績やNext Actionがない。
2. 任意で小さな検証用Plan（開発）と1 Taskを作る。登録・ログイン・Guest Plan引継は本人の明示操作だけで行う。認証時のCookie/CSRFと戻る・リロードが正常。
3. TaskからGuided Executionを開き、タップ/キーボード操作で実行→振り返りを保存。画面に保存済み表示があり、フォーム再送や再読込でEvidenceが二重作成されない。
4. Plan Dashboard→「最近の実績」を開き、**作業ログとは別の「振り返りEvidence」**に自分の文章が1件表示され、元Taskへ戻れる。時間やTaskの進捗%が勝手に増えない。
5. Next Action候補が勝手に反映されず、本人の確認後だけTaskに反映され、開始できる。全体OverviewにPlanがある。ログアウト/ログイン後に同じPlan/Taskへ戻れる。
6. **モバイル操作**：キーボード表示/非表示、タップ可能領域、横向き・縦向き回転、Safe Area（ノッチ/ホームインジケータ）、フォーム入力中スクロール、長文表示、複数回のBack、リロード、タブを閉じて再開、オフライン→オンライン、低速時の再試行で操作不能にならない。
7. **iOS Native shell**：Safariと区別してXcodeビルドのWKWebViewで同じ操作をやり直し、Native Back/外部リンク/写真・ファイル選択/アプリ再起動後のログイン保持を確認。Xcodeの開発用Originと実際のWeb配信SHAが一致することを事前確認。Nativeのビルド/Signingを勝手に変更しない。
8. **新規ユーザー価値の確認**：説明なしで初回利用者が「何をしたか／何が保存されたか／次にやること」を指させるか、保存後の戻り先を迷わず見つけられるか。本人の印象を定量CI合格とは区別して記録。

### 判定ルール

**PASS**：指定された**同じビルド/リリースSHA**で、該当端末とOSの全必須項目を実際に操作して再現。**FAIL**：操作不能、誤った進捗・時間、本人のEvidence喪失/二重作成、他人情報の漏洩、予期せぬ403/500、ログイン復帰不能、戻る/キーボードで入力不能。**BLOCKED**：アプリ版未配信、環境不明、対象アカウント/権限なし、権限/公開承認なし。**NOT RUN**：まだ実機で試していない。スクリーンショット/ログにはトークン、Cookie、実ユーザーデータを含めない。

| チェック | 現在の判定 | Owner記入：日時・端末/OS・Safari/WKWebView・SHA・所見 |
| --- | --- | --- |
| 実URLと配信SHA・Release Levelの確認 | **NOT RUN** | — |
| iPhone Safari：初回3分岐とPlanなしOverview | **NOT RUN** | — |
| iPhone Safari：Plan/Task→Evidence→次Action→再開 | **NOT RUN** | — |
| iPhone Safari：Evidence別表示と進捗・時間非改変 | **NOT RUN** | — |
| iPhone Safari：Keyboard/Rotation/Safe Area/Back/Offline | **NOT RUN** | — |
| iOS Native WKWebView：同一操作・戻る・再開・選択UI | **NOT RUN** | — |
| Mac Safari：ログインと入力/履歴/再開 | **NOT RUN** | — |
| 初回利用者の価値理解・迷いの記録 | **NOT RUN** | — |
| Static Welcome実URL・起動待ち・公開Future導線 | **NOT RUN** | — |
| Render/Aiven本番リリース・データ保全承認 | **BLOCKED**（別途リリース審査） | — |

## 現状と次の判断

- User-facing IA-1/IA-2e/IA-2fは開発`main`に実装済み。ただし [固定公開候補の監査](PRELAUNCH_FIXED_SCOPE_RELEASE_EVALUATION_2026_10_15.md) は**NO-GO/未完了**であり、この受入表から勝手に解除しない。
- 運用順序：**(A)** 新WebKit CIを開発`main`で完走 → **(B)** 本番とは別に許可された実機検証先と候補SHAの整合をオーナーが確認 → **(C)** テスターが実iPhone/Safari/WKWebViewを操作して表を埋める → **(D)** Release Issue #488で別途本番GO/NO-GOを判定。
- ターゲットユーザーにとってEvidenceが見つけづらい/操作に迷う場合は、再現経路をもとにExperienceの小PRで修正。公開ロードマップは実際の提供段階が変わる時のみ手動で検討し、CI合格だけで`released`にはしない。

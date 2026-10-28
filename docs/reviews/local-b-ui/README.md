# Canovia Local-first B — UIレビュー用画像

2026-10-28 / [Draft PR #918](https://github.com/1kz-ma1/Canovia-web/pull/918) / **未統合・未公開**。仕様・安全性の正本は[既存戦略§19のUI追補](../../development/PRELAUNCH_PREPARATION_SHARED_DB_RELEASE_STRATEGY_2026_10_23.md#2026-10-28-local-first-b-uiux刷新候補experiencedraft限定)と[Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488)。このページは画面判断のための補助資料です。

「すべての一歩が、未来につながる。」をHomeの理念に。明るい紙面、紺のヒーロー、藍の操作色と静かな光/レールで、診断→ふりかえり→自分のPlanをつなぎます。背景・文字・余白・カード・入力・focusを共通化し、モバイルは下部4項目＋headerのデータ管理です。

![主要4画面のBeforeとAfter](comparison.png)

まず確認してほしいのは3点です。操作や個人データの提供は不要です。

1. 光とレールの静かな図案が、OPの世界観と調和するか。
2. 「目指す未来」と「先行版で今できること」が区別でき、診断なしのPlan入口も分かるか。
3. 記録があるとき、初回説明を挟まず診断の続き/結果/最新Planへ戻れそうか。

円形5択のデザインは本人承認済みで、UI-2では再設計していません。

| 画面 | Before | After |
| --- | --- | --- |
| 未開始Home | [旧UI](before-home.png) | [新UI](after-home.png)・[Planあり](after-home-saved.png) |
| 診断 | [旧UI](before-diagnosis.png) | [新UI](after-diagnosis.png)・[設問までスクロール](after-question.png) |
| 30回答済み結果 | [旧UI](before-result.png) | [新UI](after-result.png) |
| 保存済みPlan | [旧UI](before-plans.png) | [一覧](after-plans.png)・[編集](after-plan-editor.png)・[初回入力](after-plans-empty.png) |
| Future / Backup | — | [Future](after-future.png)・[データ管理](after-data.png) |
| 起動失敗 | — | [保存領域を開けない場合のSTOP](after-error.png) |
| 幅の違い | — | [320px](after-home-320.png)・[1280px](after-home-desktop.png) |

撮影はloopback配信の実アプリをLinux Chromium 154 / Playwrightで操作したものです。合成の診断・Planのみ。390×844px（小幅320、desktop1280×900を除く）。画像モックではありません。AfterのHomeはUI-2 Source `3c2d46e0baa794feb69b0319dc7fe59cd31003cb`、診断は承認済み円形Source `c5648a69e7987e369b89c5784b1728f6ec795ec6`、結果/Plan等は変更していない初回候補 `f8d514b88d5b038133e712ba458126541948099f` の画像を保持し、Beforeは `ed6a18a63dac1efbba81999fa0a9611f1c3be104`。比較シートはブラウザで原寸画像を縮小配置して撮影しています。画像ファイル自体は個別の原スクリーンショットです。

Beforeの診断は初回ページで1回答を選択中、結果は30回答済み、Planは合成の保存済み1件です。AfterのHome/診断/空Planは新規の検証用context、保存済みHome/結果/Planは旧UIと同じ専用persistent profile・Originの記録です。起動失敗は新規contextでIndexedDB openだけを合成例外にした負例です。実際のiPhone画面・Safari受入証拠として扱わないでください。

ローカルテストは採点/JSONのpure10件、PHP133ケース一致、B/C browser回帰、mobile追加回帰、配布12件・Render計画11件がPASS。主要6画面のaxe検出違反0は限定的な自動検査です。再現コマンド・hash・未検証範囲は§19参照。GitHub Actionsは無料枠の都合で未実行。Safari/WKWebView、VoiceOver、native Files、iOS upgradeは未検証です。

新UI採否決定まではiOS pin/HTML/hash、iOS #20/#22、mainを変更しません。採用後のCIとiOS取込は別工程です。`PRODUCTION_NO_GO_HOLD` / `release_approved=false`。


## 円形スケールへの更新

PR #918を継続し、診断のカード回答だけを変更しました。直前のカードSourceは `3edde97f424d6dd71bcac5a60dd82fe98385c192`、新しい製品/テストSourceは `c5648a69e7987e369b89c5784b1728f6ec795ec6`。iOS pin/Release/保存・採点は変更しません。

![円形回答の全状態](circle-states.png)

左から **3（緑・大）／2（緑・中）／unknown（灰・小）／1（紫・中）／0（紫・大）**。最小の円もnative radioは44px以上×48pxのタップ領域を持ちます。選択は塗り＋白いcheck、focusは濃色outline。完全な回答ラベル・fieldset/legend・矢印/Space操作は保持しています。中央は「判断できない」という独立回答で、中立点や未回答ではありません。

| 比較・状態 | 原寸画像 |
| --- | --- |
| 直前のカード→円形（390×844） | [Before/After](circle-comparison.png) |
| 初期の未選択 | [質問1](circle-none.png)・[390pxの画面](circle-after-390.png) |
| 強い肯定 `3` / 弱い肯定 `2` | [3](circle-3.png)・[2](circle-2.png) |
| 独立した `unknown` | [わからない](circle-unknown.png) |
| 弱い否定 `1` / 強い否定 `0` | [1](circle-1.png)・[0](circle-0.png) |
| 小型画面 / 同じページの5問 | [320px](circle-320.png)・[390pxで5問全体](circle-five-390.png) |

各状態は実アプリの質問1を要素撮影、320/390はviewport撮影、5問全体は390px幅のfull-page撮影です。合成データのみ。5問全体は縦スクロールで回答し、844px内に全問を押し込む設計ではありません。比較シートも原画像をブラウザで並べて撮影しています。

追加のローカル回帰で全5値の保存/reload、unknownと解除、順序・直径・44px領域、矢印/Space/Reduce Motion、混在5値を含むJSON実download→隔離Import→選択復元がPASS。B/Cの30問・採点133参照・JSON・保存失敗・通信境界回帰もPASS。旧カードで保存した専用profileの全stateが円形UIのbrowser再起動後にも完全一致しました。詳細・ZIP証拠は既存§19の円形追補へ集約しています。

円形5択のデザインは本人承認済みです。UI-2では描画・操作・CSSを維持し、GitHub CI・Safari/WKWebView/VoiceOver/実機の未実施範囲はそのまま残します。

## UI-2：OP世界観とホーム体験

「すべての一歩が、未来につながる。」を、静かな光とレールの図案でHomeへつなぎました。図案は装飾だけで動きません。OP/Remotion projectは未参照・未変更で、最終映像とのフレーム/遷移照合やiOS再生/SKIPは今回の対象外です。

![初回・途中・Planの390px比較](ui2-home-comparison-390.png)

| 状態 | 320px | 390px | 主な導線 |
| --- | --- | --- | --- |
| 初回 | [画像](ui2-home-first-320.png) | [画像](ui2-home-first-390.png) | 理念→先行版で今できる2入口。診断なしでもPlanから開始 |
| 診断途中 | [画像](ui2-home-partial-320.png) | [画像](ui2-home-partial-390.png) | 保存済みの前回ページへ。初回ガイドなし |
| 30問選択済み | [画像](ui2-home-answered-320.png) | [画像](ui2-home-answered-390.png) | 結果をふりかえる/Planを作る。unknownは中立点としない |
| Plan保存済み | [画像](ui2-home-plans-320.png) | [画像](ui2-home-plans-390.png) | 最新更新Planへ。診断なしで作ったPlanも再開可能 |

[320pxのBefore/After](ui2-home-comparison-320.png) / [desktop](ui2-home-desktop.png)。Beforeは直前候補 `30bda7b560554007e9334eef54e4e8a79c1b4345`、Afterは製品/テストSource `3c2d46e0baa794feb69b0319dc7fe59cd31003cb`。初回の比較は各版の新規context、途中/Planの比較は旧UIで保存した2つの専用profileを新UIで再開し、各合成記録の全stateが完全一致したことを確認して撮影しました。30問選択済みは自動試験の別contextを実操作して撮影しています。

理念は「Canoviaが目指す未来」、現在の入口は「先行版で、いまできること」と分けています。Futureカードは将来構想と表示し、AI支援・自動記録・外部サービス連携を先行版では提供していないと説明します。利用可能なボタンとして見せません。初回判定は既存の診断/Planの有無だけを使い、初回flagや行動記録を保存しません。

新しいHome回帰を既存B/C browser試験へ組み込みました。4状態×320/390の入口・44px領域・overflow・装飾animation 0・非書込、診断なしPlan入口、前回step再開、30問→結果、複数Planの最新選択、B保持/C reload消失を確認。Home各状態のaxe対象tagsはB/Cとも検出違反0。既存の円形値・JSON・採点133参照・保存失敗・CSP/Origin回帰もPASSです。Safari/WKWebView/VoiceOver/実機・GitHub CIは未実施です。

最終的には、**OPと図案の調和、理念/現機能の分かれ方、初回/既存利用の優先順位** を画像でご確認ください。実機操作は不要です。Draft・未統合・未公開、`PRODUCTION_NO_GO_HOLD`を維持します。

## ローカル再現

専用clean checkoutと新規の一時出力先を用います。PHPはLaravel/bootstrap/DBを起動しないpure scriptだけを実行。Playwright 1.56.1とbrowserは検証環境側に用意します。system Chromium使用時のみ `CANOVIA_BROWSER_EXECUTABLE_PATH` を指定できます。公開用wrapperや実Originは使いません。

```sh
PREP_UI_TMP=$(mktemp -d)
php scripts/ci/preparation_static_data.php --data > "$PREP_UI_TMP/data.mjs"
cmp preparation-local/src/data.mjs "$PREP_UI_TMP/data.mjs"
php scripts/ci/preparation_static_data.php --vectors > "$PREP_UI_TMP/vectors.json"
PREPARATION_VECTOR_PATH="$PREP_UI_TMP/vectors.json" node preparation-local/test/core.test.mjs
node preparation-local/test/backup.test.mjs
python3 -B scripts/ci/preparation_static_build.py --out "$PREP_UI_TMP/B"
python3 -B scripts/ci/preparation_static_build.py --profile C --out "$PREP_UI_TMP/C"
python3 -B scripts/ci/preparation_static_security_test.py
python3 -B scripts/ci/preparation_static_render_test.py
PREPARATION_DIRECTORY="$PREP_UI_TMP/B" PREPARATION_VECTOR_PATH="$PREP_UI_TMP/vectors.json" BROWSER_ENGINE=chromium node tests/browser/preparation_static.mjs
PREPARATION_DIRECTORY="$PREP_UI_TMP/C" PREPARATION_VECTOR_PATH="$PREP_UI_TMP/vectors.json" BROWSER_ENGINE=chromium node tests/browser/preparation_static.mjs
PREPARATION_DIRECTORY="$PREP_UI_TMP/B" BROWSER_ENGINE=chromium node tests/browser/preparation_mobile_ui.mjs
```

最後のUI検査に `AXE_SOURCE=/absolute/path/to/axe.min.js` を渡すと同じ自動a11y検査を追加できます。今回は検証環境のaxe-core 4.11.0を使用。製品に同梱しません。WebKit実行stepはCIに残していますが、今回の環境にWebKit binaryがなく、ダウンロード先も許可対象外のため実行していません。Chromiumを代用してWebKit PASSと記録しません。

# Map Node表示・スマホUI

## 表示仕様

V47.7のLeafを役割名だけで表示する方針を一部更新する。Task / Tool / Evidence / Inbox / Collaboration Itemは原則として、具体名を最大2行、既存の意味に対応する短い役割を1行で表示する。ただしPrimary Taskだけは「おすすめ」を役割そのものとして扱い、Canvasには具体Task名を出さない。おすすめの実Task名・全文は既存の詳細パレットで確認する。

- 何なのか: 原則Nodeの具体名。空のTask名だけ「名前のないタスク」にフォールバックする。Primary Taskは例外で、Canvasでは「おすすめ」だけを表示し、具体名は詳細パレットへ置く。
- 今どういう状態か: 詳細の「状態・関連情報」に既存metaをそのまま表示する。
- なぜここにあるか: 既存position_role / eyebrowから説明できる役割だけ「ここにある理由」に表示する。不明な役割は説明を作らない。
- 次に何ができるか: 「次にできること」に既存actionsを表示する。

「次にやる」は候補を意味し、readyや完了を推定しない。外部状態は確認先として説明する。Nodeの内部ID、raw eyebrow、subtitleを可視ラベルへ追加しない。具体名はBladeの通常のエスケープで出力する。

## スマホの表示

Leafは104px幅、具体名14px、役割12pxを基本にする。Primary Taskは具体名をCanvasへ出さず「おすすめ」を単独表示する。その他のLeafは装飾Glyphの領域を具体名へ割り当てる。詳細のタイトル・meta・長い英数字を折り返し、操作・閉じる・広げるは44px以上の高さを確保する。外部リンクchipと本文には独立した余白を確保する。既存のパレット内スクロールと展開操作を利用する。

`map-node-boxes.mjs`は既存mobile layoutの結果と実際のNode寸法だけを入力とする表示補正である。通常は中央を保ち、端から8px、Node間8pxを空けて最も近い空き位置へ移動する。中央固定では収まらない密集状態だけ、読みやすい寸法を保つグリッドへ再配置する。極端に高さが足りない画面では全Nodeの同時表示を保証しない。座標を保存せず、接続線は補正後の座標で描画する。

Leafタップではpointer capture先を詳細リンクにする。Sceneにcaptureすると後続clickがSceneへ向き、詳細選択が消失するため。既存ドラッグ後click抑止とpinch / zoom計算は維持する。

## 並行実装との境界

- 主な変更先: `MapNodePresentation.php`, `map/node-readability.css`, `node-context.blade.php`, `map-node-boxes.mjs`。
- `map/presentation.css`: 専用CSSのimportを1行追加。
- `map/partials/surface-templates.blade.php`: 詳細の役割表示、説明部品のinclude、状態・操作見出しのみ。
- `living-map.mjs`: mobile描画前の表示補正呼び出しとLeafのpointer capture先のみ。
- hierarchy-navigation、Breadcrumb、最大抽象度復帰、route、階層判定、semantic zoom、履歴、canonical graph、projection keyは変更しない。

PR #152取り込み済みのmainを基点に、抽出済み部品へ変更を配置した。`map/index.blade.php`と`app.css`には差分を残さない。Navigation側との統合時はmobile表示補正呼び出しを保持し、共通ファイル全体を片側で上書きしない。

## 検証

- PHP表示テスト8件 / 49 assertions成功。
- JavaScript全46件成功。320 / 390 / 430 / 767pxの密集7Node、実寸境界、非重複、入力不変、決定性を含む。
- Vite production build成功。
- ローカルの実Blade出力をブラウザーで確認: 320×568 / 390×844、Node名、端切れ、通常クリックで詳細表示、展開操作、横はみ出しなし、詳細ボタン44px。
- 関連PHP26件中4件はmainでも同じ失敗を再現: V476の旧説明文、V440の旧position_role、V473の旧chip文言、V451の旧close aria-label。今回の変更による新規失敗ではない。

Migrationなし。Free経路・billing / payment / entitlementの変更なし。実端末での複数指pinchや本番データの検証は未実施。

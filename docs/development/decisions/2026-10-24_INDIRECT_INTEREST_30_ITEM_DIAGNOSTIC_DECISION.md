# D-2026-10-24-02 — 関心分野を直接聞かず、日常質問30問から推定する先行診断

> Owner: Canovia Product Owner / 2026-10-24 JST  
> **Product direction: ACCEPTED / questionnaire item wording, scoring thresholds, implementation: PROPOSED・UNVALIDATED / DEPLOYMENT: NO-GO HOLD**
>
> **この決定は[D-2026-10-24-01](2026-10-24_PRELAUNCH_BROAD_INTEREST_DISCOVERY_DECISION.md)の「直接カテゴリ複数選択→興味強度を回答」を置き換える。** 広い関心分布/本人グラフ/Canovia Future/任意Plan/需要を別に測るという目的と安全ゲートは維持する。  
> 技術への依存：[先行公開戦略 `12](../PRELAUNCH_PREPARATION_SHARED_DB_RELEASE_STRATEGY_2026_10_23.md)、[30問のオリジナルドラフト](../PRELAUNCH_INTEREST_DIAGNOSTIC_ITEM_BANK_V0_2026_10_24.md)。

## Why

本人のSafari実機所感で、既存の現在地入力フォームが「診断」に見えないことが判明。次の直接的なカテゴリ選択は興味アンケートに見えるので、MBTI風の何気ない生活/思考/行動の質問から意味を推定したいとの追加方針。**MBTIの名称や標準尺度・公式の問題を使うこと、同等の測定妥当性を主張することは決定していない。**

## Accepted direction

1. 本人へ最初から「学習/開発/就活を選べ」と問わず、幅広い日常場面の自然な質問**30問前後**を答えてもらう。
2. 選択肢は**「わからない」を含む計5つ**。回答尺度は4段階の賛否＋Unknown欠測のバランス設計を初期案とし、5件の賛否尺度＋Unknownの6択とは明確に区別する。中立意見が回答しにくくなる場合は試用で修正する。
3. 質問の内部対応は分野横断で複数の重みを許す。本人には結果をカテゴリ別の相対的な興味の**グラフと短い説明**で返す。Unknownは「興味なし」にせず未判定を出す。
4. 本人向け結果画面に**Canovia Futureへ行く目立つCTA**、承認された成熟度表示付きの将来体験、任意Plan準備導線を備える。対応する公開カードがない/正式版の提供未決定ならそのまま伝える。
5. Canovia側の需要判断は**診断による間接推定とは別**に、将来支援を使いたいという**任意の明示的な利用意向**やその後の行動で判断。回答者の分布＝市場規模ではない。
6. 診断質問の採点タグを設問画面へ直接表示しない一方、**何を推定して何に使うかは本人へ明示**。関心の推定を適性・人格・健康・就労能力として断定しない。本人による修正/削除・分析集計の用途説明/任意選択を保つ。

## Status and safety

- 設問とマッピングは**未検証のドラフト**。質問の自然さ・所要時間・途中離脱・回答の一貫性/バイアス・分野間の重複を初見試用で確かめる。得点合計100%は表示用の正規化であり「本人の本質」や診断精度ではない。
- 現行`PersonalizationController`はStudy/Development/unsure限定、`/personalization`はL1。新しい公開L0向け30問は既存の詳細診断を勝手に置き換えず、別契約/本人権限/継続保存で実装する。
- 結果、未回答、後日の修正はCanonical Account所有データに記録し、旧Study/Development self-report / Plan / Task / AP履歴 / FirstRunなどのデータモデルを壊さない。新migration不要の推測・現行Context JSONへの無検証書き込みは禁止。
- Canovia Futureは手動承認された`PublicRoadmapProjectionService`のみ紹介。未公開のCareer等を「正式版で必ず使える」と約束しない。
- 本Decision自体は**docs only**、研究データの取得、アプリ実装、Aiven/Render/production操作、本人Safari受入を行わない。`CANOVIA_PREPARATION_ONLY`、既存AP画面停止影響、実DB/20件Migration/復元・実効config/旧worker・Mac/iPhone・Owner GOなどは`PRODUCTION_NO_GO_HOLD`のまま。

## Revisit

30問離脱率、望ましい「中立」を答えられない、カテゴリ偏り/誘導や確信度不足、説明と本人認識の乖離、根拠不足の測定保証、個人情報/研究同意の不足、既存DB継続性へのリスク。結果レビューと技術検証を経てQuestionBank v1採用または改訂の別判断を記録する。

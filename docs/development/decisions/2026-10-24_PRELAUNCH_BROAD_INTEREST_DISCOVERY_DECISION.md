# D-2026-10-24-01 — 先行診断を「幅広い関心の発見＋製品需要の検証」へ

> Decision owner: Canovia Product Owner / Date: 2026-10-24 JST
>
> **Decision status: ACCEPTED product direction; interaction/scoring/storage specifics: PROPOSED pending UX and engineering acceptance.**
>
> **Engineering/release state: NOT IMPLEMENTED by this document / NOT DEPLOYED / PRODUCTION_NO_GO_HOLD**
>
> 詳細な候補仕様・Codex引継ぎ：[先行リリース共通DB戦略 §11](../PRELAUNCH_PREPARATION_SHARED_DB_RELEASE_STRATEGY_2026_10_23.md)。安全ゲート：[Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488)。

## Problem and observed feedback

Owner's 2026-10-24 Mac Safari screenshot/feedback: `tester@example.test` の準備診断は**正常表示**されたが、実態はStudy/Developmentだけを中心とする目標入力フォームで、ユーザーが期待する「診断」と認識しにくい。これはL1/保存バグの確認ではなく、**価値提案とUIの不一致という本人のUX観測**。実データ一般利用者による評価はまだない。

また「先行は正式版の準備」という製品目的に加え、Canovia側が**幅広いカテゴリの相対的な興味・将来の利用希望**を理解し、どの分野を優先するか検討するための需要探索を行いたい。

## Accepted direction

1. **先行の最初の診断は学習/開発限定にしない**。本人に複数分野の興味の強さを尋ね、割合で可視化した自己申告の「関心プロフィール」を返す。関心ゼロ/未定/スキップも許可する。
2. **関心の分布とCanoviaの利用意向は別の概念**。「仕事に興味がある」だけで「Canoviaの仕事モードを使う」と推測せず、利用したい領域について追加の任意回答を得る。登録/計画作成は診断完了の条件にしない。
3. **診断結果からCanovia Futureを探しやすくする**。本人が関心を持ったカテゴリに関する承認済みの公開ロードマップ/製品体験紹介を、成熟度ラベル付きで表示し、Futureへ明確に遷移できるようにする。Study/Development/Careerについて将来の可能性を説明しても、正式公開時期/実装範囲を保証しない。
4. **必要なユーザーだけが次へ進む**。分布確認→興味分野のFuture確認、または興味の高い領域から任意でPlan/詳細診断へ。従来の本人Plan/Task/履歴と共通認可の継続性を守る。
5. **任意の調査回答を製品改善に使うときの説明・選択を分ける**。本人へのプロフィール保存と、集計された需要分析の扱いを区別。高リスクの個人詳細、機微データを収集する設計へ広げない。

## Alternatives and rationale

| 代案 | 問題/利点 |
| --- | --- |
| 現行Study/Devフォームを「診断」と呼び続ける | 実装負担は低いが関心探索とUX期待に合わない |
| 全カテゴリに高度AI診断/専門プラン生成を実装 | 効果検証前に範囲が大きくなり、先行公開のDB/安全HOLDを増やす |
| **横断的な関心プロフィール＋必要時のみ詳しい分野診断** | 最短のグラフ体験、将来のPlan/機能への接続、需要仮説の分離が可能 |
| 未検証のロードマップを各分野へ自動生成 | 将来提供を誤認させるため採用しない |

## Measurement hypotheses (UNMEASURED)

- UX：質問開始→結果表示→任意のPlan保存/再訪の転換率（各分母とスキップ率を記録）。
- 需要：選択された関心カテゴリの人数/強さ、**Canoviaで使いたいと明示された**カテゴリ、Futureの紹介閲覧/クリック、再訪・Plan作成（興味から利用希望/利用へ漏斗を分ける）。
- 集計の注意：自己選択で診断した訪問者の分布であり、一般人口・潜在市場規模・課金意思の推計ではない。分母、時期、サンプル数、選択バイアス、未回答を表示し、各ユーザーの割合の合計やクリック数を「市場需要」と言い切らない。
- 本人画面では性格・能力・適性の客観的測定結果と誤認させない。**自己申告時点の関心の配分**と明示する。

## Engineering and safety boundaries

- 検討する興味カテゴリ例：学習、開発・ものづくり、就職・キャリア、仕事・生産性、生活・習慣、趣味・創作、お金・家計、健康・ウェルネス、その他/未定。**カテゴリは興味のラベルであり、その領域の機能提供を約束しない**。敏感な領域は詳細な健康/資産/家族情報を質問しない。
- 現行 `PersonalizationController`/`PersonalizationBootstrapService` は `study/development/unsure` 限定。 `PersonalizationContextService::normalize()` と `selfReportedPayload()` は固定キーを再構成するため、単純な未知JSONの追加は永続化を保証しない。現在の `UserPersonalizationContext` 内に保存する場合も、バージョン付きの読書き契約・旧データ互換性/上書きなしの検証が必要。新migrationは不要と推測してはいけない。
- 推奨描画は**カテゴリ名＋%の横棒グラフ**と代替テキスト/表（狭い画面、キーボード、読み上げに配慮）。点数の正規化合計100%は表示用の定義であり診断精度ではない。
- Future連携は既存 `PublicRoadmapProjectionService` と `config/canovia_public_roadmap.php` の承認済み公開項目のみを使う。現時点でCareerの確定提供を示す公式公開カードは確認できないため、創作して「正式版提供確約」にはしない。必要なら公開文面と状態をオーナーが別PRで手動承認。
- `CANOVIA_PREPARATION_ONLY`、Release Level L0/L1、Owner/Plan認可、CSRF/IDOR、worker/route/APIのdeny、既存AP学習停止影響、実DB・追加20migration/実復旧、固定SHA・本番GOは全て従前どおりHOLD。広域診断の一般提供には独立した審査が必要。

## Decision evidence and revisit

基準GitHub開発main：`6c39f9ba619f11346263d07f06bca5dff0a0939b`（#861統合）、OwnerからのSafari現物画像と説明。これは**製品方向への本人回答**であり、新しい計測・市場調査・ブラウザE2Eを実施した証拠ではない。

再検討：カテゴリ設計が多すぎて離脱、パーセントが誤認を招く、機微データが集まる、現行Contextが破壊される、Futureの提供約束が現実と食い違う、集計母数や同意の品質が不十分、または先行HOLDを不必要に増やす場合。最新の採否・証拠は独立した日付付き追記を行う。

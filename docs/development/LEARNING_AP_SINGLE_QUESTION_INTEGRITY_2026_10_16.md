# AP科目A・単問四択の回答欄混入ガード

2026-10-16 JST。開発main向け修正。固定候補641e2105/HOLD、本番操作禁止を維持。

## 再現と切り分け

最新main `500844dd` 上で、Q3電子署名の1 questionに電子署名とソルトの必須single_choiceを2件入れた合成fixtureを使用。取込は成功し、Bladeが両方を列挙することを確認した。新規3テストは修正前2失敗、1成功。

- 生成：外部AI promptは「response_fields 1〜4件・組合せ可」とし、Native schemaも独立回答数を制限していなかった。混入を防ぐ契約が不足していた。
- 取込：normalizeQuestionsは型・ID等を確認するが、AP科目Aの最終回答が1件かを確認していなかった。
- 保存／表示：snapshotとBladeは受け取った欄を維持しており、今回のfixtureでは表示側が別問題から選択肢を取り出しているわけではない。

実際の報告payload、利用AI、生成元は未取得。実ユーザーのPlan/DBは調査していないため、実事象の上流生成原因を確定したとは扱わない。

## 実装境界

既存exam profileが `ap_subject_a_exam` でsingle_choiceを含む問だけ、最終回答欄を1件に制限。同じ問の任意textareaは補助欄として許可する。他カテゴリの複合回答、以前のnumber/記述形式、旧type/choices互換は保持し、旧選択肢数を一律4件へ変更しない。

取込前とNative providerのsnapshot確定前に共有ガードを実行。問題番号と「別questionに分割・元の問題文と照合」の案内を返す。片方を自動削除したり、意味から正解を推測したりしない。Nativeはrun失敗と既存手動fallbackへ進み、混入snapshot/Attemptを確定しない。

既存snapshotは保存時のexam profile（無ければ既存Plan/Taskから解決）で検査。表示は回答フォームを止め、元JSONと修復コピー導線を出す。draft/回答送信/新規評価取込にも同じガードを適用。snapshot・途中回答・過去Attemptを消したり書き換えたりしない。修正JSONの再取込は従来どおり利用者の明示操作。

## 検証

- 関連PHP 22 tests / 175 assertions成功：混入拒否、既存draft非置換、保存済み混入の表示/POST停止、generic複合・旧形式互換、Native HTTP fake、従来のdraft/評価/冪等性。
- disposable SQLite＋実Chromium：混入の修復案内とsession/Attempt非作成、単回答＋任意textarea、正常取込、204保存、419未保存、localStorage消去後の復帰、評価JSON修復、明示評価後の履歴1件のみを確認。
- 実AIの生成品質、Safari/iPhone、本番データ／配備は未検証。一般の意味的な問題文・選択肢整合性を保証する検閲ではない。

新規migration、課金、権限拡大、公開設定変更はない。「わからない」の状態追加は独立した後続仕様にする。

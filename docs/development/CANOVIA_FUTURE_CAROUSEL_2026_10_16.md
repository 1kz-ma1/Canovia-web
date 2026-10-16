# Canovia Future・横スライドカード

2026-10-16 JST。添付 `Canovia_Future_Carousel_Assets_20261016.zip` の CODEX_BRIEF と参考画像を、今回のユーザー依頼の範囲で採用。文書中の架空票数や提供状況は仕様・実績へ転記しない。本番候補641e2105/HOLDと本番操作禁止は維持。

## データと表示

- 手動公開の開発ロードマップと、DBの公開済みSupport候補を**別カルーセル**で表示。既存Projectionの公開・状態・実受入ガードを維持。名称類似でjoinせず、公開状態・新規レコード・架空票は生成しない。
- 開発近況は「投票対象ではありません」と詳細開閉。Support候補だけ既存POST/DELETEとDBの実票数を表示。停止中はボタンを出さず、hidden/停止中への取消もSupport同様404。自分の投票以外を削除できない。
- 画像対応は明示key設定だけ。現在の公開項目と意味が合う `progress-and-records → shared-context`、`external-activity-sync → github-integration` の2素材だけ配置。後者は初回公開の自動連携を約束せず、既存の将来構想の説明を維持。対応素材のない初回導線・Social等はブランド図と「専用イラストは準備中」の代替説明。Study/Development/Map/Dev Proの未対応画像から新しい公開カードを作らない。
- 画像は16:9・寸法予約・lazy/async・説明alt。構想図であることを画像直下に明記。読込失敗でも本文/操作を保持し、JSなしでもaltが残る。

## 操作

中央カードを強調し、隣のカードが部分表示されるscroll-snap。3枚以上では2枚目を初期表示して左右を見せる。自動再生・強制loopはない。

タッチ／トラックパッドのnativeスクロール、矢印・位置ボタン、viewportフォーカス時だけ左右/Home/Endキー。フォーム操作のキーを奪わず、フォーカス・現在位置・live statusを提供。reduce-motionではアニメーションしない。no-JSでも横スクロールと通常のPOST/DELETEフォームが使える。空一覧に動かない操作ボタンを出さない。

実票数のPOST後は従来どおりページを再表示する。DB・認証方式・voter key・閾値・投票の意味は変更しない。migration、課金/決済、一般公開、Entitlement変更はない。

## 検証と限界

関連PHP12件/75 assertions：既存公開Projection、guest重複投票/取消、登録ユーザーの分離、hidden/停止中404、同名でも非join、空状態、レコード非生成。

既存disposable browser runnerへFuture専用L0の一時SQLite journeyを追加。実Chromiumで中央/左右peek・touch drag・キー/フォーカス・reduce-motion・ページ幅・guest/登録/再送/取消・HTTP404画像fallback・no-JSフォーム・空Support・JSエラーなしを確認。CIはChromiumとWebKitを別jobで実行し、合成データのログ/画像のみ保存する。

WebKitのnative overflow/snap検証は物理iPhone Safariのtouch受入と同一ではない。実機Safari/PWA/WKWebView、音声読み上げの人手受入、実ユーザーの投票DB/配備は未検証。参考画像の架空票数128/96/72は使用しない。

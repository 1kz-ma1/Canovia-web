# Pre-Launch IA-1 — 静的紹介ページと起動待ちの共存

**2026-10-15 / 開発 `main` 対象・本番公開の承認ではない。** IA-1 の最小 Slice。関連: [Pre-Launch Public/App/Future IA](PRELAUNCH_PUBLIC_APP_FUTURE_IA_SPEC_2026_10_13.md)、[IA-2d 実ブラウザ検証](PRELAUNCH_IA2D_DISPOSABLE_BROWSER_E2E_2026_10_15.md)、[手動公開ロードマップ](PRELAUNCH_MANUAL_PUBLIC_ROADMAP_2026_10_15.md)。

## 目的・既存機能

既存の Render Static Welcome (`static-welcome/index.html` + `app.js`) は、Free Web 起動のヘルスチェック、手動再試行、オフライン/低速時の案内、準備完了後のアプリ入場、再訪者の準備完了後自動入場を担当。**この起動待ち機能をLPに置き換えない。**

IA-1 は `static-welcome/intro.html` を静的な「Canoviaとは」ページとして追加。訪問者はアプリが休止中でも説明を読める。既存 `index.html` に紹介ページへの任意リンクを置く。既存の静的配信 `Root Directory: static-welcome / Publish Directory: .` を利用し、新規サービス・CMS・DB・有料リソースは不要。

## 表示と導線

- **Canoviaを知る:** 決める → 作業を残す → 現在地を知るという目指す体験を平易な文で紹介。実装/公開の区別を保つため「先行公開に向けて準備中」「機能とアカウントで利用可能性が異なる」と明示。
- **無料で試す:** `intro.html → index.html → appUrl`。静的LPから `/personalization` 等の直接的な内部リリースレベル回避を行わず、既存WebのWelcome/Guest・認証・Release Gateに任せる。実際の3分岐（診断/相談/まず見る）は既存 IA-2b のWeb Welcome。
- **開発の現在地 — 静的サイトだけ先行公開する期間は準備中:** 現行Live Web（`15abdc9...`）の `/feedback` は無料Support候補のみで、新規の手動公開ロードマップを表示しない。`intro.html` に未公開のロードマップへ誘導するリンクを出さず、『Web本体更新後に公開』と明示する。`config.js` の `publicRoadmapReady=false` のまま維持する。旧ブックマークの `?destination=future` は `/feedback` の既存無料Support候補と正確なラベルへフォールバック。
- **応援・意見:** `intro.html → index.html?destination=support → appUrl/feedback`。既存Live Webにもこの公開GETルートは存在。`#support-candidates` は最新のWebで初めて追加されたため、`config.js` の `supportAnchorReady=false` の間はアンカーを付加しない。金銭的支援・支払いと混同しない。
- `app.js` のURLクエリは列挙済み `future`/`support` のみ許容。Web新バージョンが公開・実機受入されるまでは静的設定の2フラグをOFFとし、外部URL・任意pathへのリダイレクトは行わない。空/未知の値は従来の `appUrl` に戻る。`/health` の `X-Canovia-Ready: 1` を確認するまで通常の入場ボタンは無効。利用者が明示的に「そのまま開く」を選ぶ既存の救済導線は維持。
- 紹介ページは静的HTML/CSSのみ。ログイン・個人Plan/Taskデータ・トラッキング・外部AI・API・個人情報・新しい自動進捗同期は扱わない。

## 受入条件・未完了

- Node回帰テスト：導線の存在、広告/購入誤認のない表現、無JSの静的紹介ページ、既存の起動待ち復旧コントロール、実ヘルスReady後の入場、固定ルートのみの誘導、再訪者の準備完了後の自動入場、起動中の救済。
- 実Chromium（ネットワークでの本番サイト接続を禁止）：紹介ページのモバイル390px/デスクトップ1280px表示、水平はみ出しなし、3つの体験/2つのFuture導線、既存Welcomeから紹介ページへの画面クリック遷移。これは **ローカル静的ファイル** だけの動作確認であり、本番の配信・実機iOSの検証ではない。
- 静的サイト配置時には `intro.html` の直接URL/リンク、モバイル表示、アクセシビリティ、Web Appのゲスト `/feedback` と `/welcome` を手動で確認する。まだ **Render Static Site / 実機 / Safari / iPhone WKWebView で検証済みではない**。
- **Render設定の経緯と最新実測（2026-10-15）:** 初回点検時、`Canovia-Welcome` Static Site (`srv-dahn5k142hec739l0fmg`) は `branch=main`、`autoDeploy=yes / autoDeployTrigger=commit` であり、未承認の静的公開を防ぐため当初PRはDraft/HOLDとした。その後**オーナーがAuto DeployをOFFに変更**。OrchestratorがRender `My Workspace` の同サービスを再取得し **`autoDeploy=no / autoDeployTrigger=off`、Live SHA `ee533028c866e201d61c1430c15eaedb9c843524` のまま**を確認。開発`main`への統合による静的自動公開リスクは解消したが、**実配信への手動デプロイ・実URL/スマホ/既存起動待ち受入は未承認・未実施**。本体WebのAuto Deploy OFFや `production` Releaseは別ゲート。
- 静的サイトの自動公開を運用で制御したうえで統合し、実配信後にユーザーが直接 `/intro.html` を閲覧できることを確認する。本番Web/productionブランチ/Aiven/iOS/権限/課金への変更は行わない。
- 独立Marketingドメイン/SEOの本格設計・CMS・個別更新記事・有料開発支援・行動解析・機能比較・動画・全画面刷新は後回し。既存Static Welcomeの復帰信頼性と最小価値の伝達を優先。

## 公開用文面の更新

訪問者向けの進捗説明は [`config/canovia_public_roadmap.php`](../../config/canovia_public_roadmap.php) に集約し、PRで手動更新する。`intro.html` は説明と入口だけを保持し、各カードの進捗表示や内部GitHub/CI結果を独自コピー・自動同期しない。更新不要な機能PRではLP/カードを変更しなくてよい。

## Static-only 公開直前のOwner受入ゲート（2026-10-15）

1. 新しい `intro.html` が開発mainにあってもRender Static Liveは古い `ee533028c...` のまま。Auto Deploy OFFを再確認。**Static手動deployは別のOwner GOが必要**で、Web/production/Aiven/iOSマージとは独立。
2. 旧Config先 `https://app.canovia.com` はGitHub Actions公開DNSで **DNS_UNVERIFIED**。Static ConfigをRender公式の `https://pacekeeper-d3mm.onrender.com` に暫定切替（開発PR／未配信）。正しいRender Web originとのTLS接続は検証可能だが、実際のTLS、ゲストWeb `/feedback`、`/health`のReadyヘッダーとCORSがブラウザで一致するか、操作前に検証する。ツールから実URLが参照できないことをWeb受入成功と扱わない。
3. Old Live Web版には `/feedback` が存在するが、新公開ロードマップと `#support-candidates` アンカーはない（Live SHAのviewソースで確認）。**公開済み表示・アンカー付与・ロードマップの見せかけを禁止**。`publicRoadmapReady=false`、`supportAnchorReady=false` で配信し、Static公開時もWebの新機能は利用可能と告知しない。
4. Static Deploy後は実URL `/intro.html` / `/index.html` / `/index.html?destination=support` と公開Web `/feedback`、冷起動・再訪者自動入場、手動再試行、オフライン案内、390px/1280px、iPhone Safariを実利用でチェック。**デプロイ前のCIやGitHub SHAは実配信証拠ではない**。
5. Web本体の独立した新リリースが別途GO/Live SHA/実URL/iPhoneで確認された後、新しいPRでのみ設定フラグとロードマップ入口を戻す。`main`マージから自動的にFuture公開ステータスを更新しない。Issue #488の本番GO/No-Goは未承認。

6. **2026-10-15 URL診断の訂正:** Public DNS/TLS CIで `app.canovia.com=DNS_UNVERIFIED`、`pacekeeper-d3mm.onrender.com=TLS_VALID`、`pacekeeper-welcome.onrender.com=TLS_VALID`、Static `index.html` の既存Live確認、`intro.html` は未公開と判定。暫定Render Web URLへの切替は **静的配信準備のみ**。公開前にWebのCORS/Ready/GuestとSession Domain/リダイレクトを所有者が実ブラウザで確認。Webコールドスタートによる既存 `migrate --force` の起動は読み取りDNS/TLSプローブでは行わない。

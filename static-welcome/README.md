# Canovia Static Welcome

Render Free Web Service がスリープ中でも、先に表示できる静的な入口です。

## Render Static Site 設定

- Repository: Canovia と同じ GitHub repository
- Root Directory: `static-welcome`
- Build Command: 空欄
- Publish Directory: `.`

公開後は、その Static Site の URL を新規ユーザーへ共有します。

`config.js` の `appUrl` は Laravel 本体の公開 URL です。現在は `https://app.canovia.com` を設定しています。

Static Site は起動直後から `/health` をポーリングします。Laravel が実際に応答できるまで、Canovia の特徴を数秒ごとに切り替えて表示します。準備完了後に「Canoviaを開く」が有効になります。2回目以降は準備完了後に自動で本体へ移動します。

## 先行公開の紹介ページ (IA-1)

- `intro.html` は Canovia が目指す体験と、アプリ・開発の現在地・無料Supportへの入口を示す **独立した静的HTMLページ**。URLは Static Site の `/intro.html`。
- 既存 `index.html` の「Canoviaについて知る」から入れる。入口の起動待ちや遅延時の再試行、再訪者の自動入場は維持。
- 「無料で試す」は `index.html` 経由で Laravel アプリへ進む。公開ロードマップと無料Supportは同じ起動待ちページの `?destination=future` / `?destination=support` に誘導され、起動後は既存 `/feedback` / `/feedback#support-candidates` を開く。**クエリからの任意URL指定は不可**。
- アプリが休止中でも `intro.html` は静的表示できるが、`/feedback` と実利用にはWebの起動が必要。アクセス範囲はWebのRelease Level/認証が引き続き制御する。
- 更新はGitHub PRで手動。公開状況は `config/canovia_public_roadmap.php` が別途管理する。実機とStatic Site実配信の検証はデプロイ後に行う。CIだけでは本番提供を保証しない。

詳細: [IA-1の範囲と受入](../docs/development/PRELAUNCH_IA1_STATIC_PUBLIC_INTRO_2026_10_15.md)。

> **Release boundary (2026-10-15):** 当初のRender `Canovia-Welcome` は `main` / Auto Deploy ONでありPRをDraft/HOLDとしたが、その後オーナーがAuto DeployをOFFに切替。`My Workspace`のサービスを読み取り確認し `autoDeploy=no` / `autoDeployTrigger=off`、Live SHAが旧 `ee533028c866e201d61c1430c15eaedb9c843524` で不変と検証した。開発`main`への統合は静的配信を更新しない。**実サイトへの手動デプロイとURL・端末受入は引き続き別承認**。本番Web/DB/iOSのリリースと混同しない。

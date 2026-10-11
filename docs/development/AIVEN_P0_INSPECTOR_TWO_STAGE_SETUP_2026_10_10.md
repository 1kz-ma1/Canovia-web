# Aiven 本番 MySQL P0 検査用アカウント — 2 段階安全セットアップ

> 2026-10-10 / Platform P0 / **手順準備済み・本番操作は未実施** / Issue [#418](https://github.com/1kz-ma1/Canovia-web/issues/418)、Draft PR [#443](https://github.com/1kz-ma1/Canovia-web/pull/443)

## 現時点の確定情報と境界

- オーナーが Aiven Console の Connect → Users で確認した結果、**登録済みサービスユーザーは `avnadmin` のみ**。独立した検査専用ユーザーは **存在しない**。
- `avnadmin` は管理者のため、P0 検査スクリプトに使用しない。アプリがそのユーザーで接続しているかも未検証であり、無断の切り替えや既存権限変更はしない。
- 実際の Aiven サービスは Console 上 MySQL 8.4.8、稼働中。画面で Full バックアップ履歴を観測済みだが復元性は未確認。GitHub Actions の MySQL 8.0/8.4 合格は実本番の健全性を証明しない。
- 現在の ChatGPT の GitHub/Render 接続には、Aiven MySQL の **ユーザー作成・権限付与・SQL 実行**用権限がない。ここに記載の操作をユーザーに代わって実行したとは扱わない。

## Aiven 公式仕様から確定した注意点

[Aiven MySQL Manage service users](https://aiven.io/docs/products/mysql/howto/manage-service-users)：
通常の Console のユーザー追加と、`mysql_grants` を省略した API / CLI のユーザー追加は、**初期管理者権限が付く**。一方、対応サービスへの API `ServiceUserCreate` で明示的に `"mysql_grants": []` を送れば **接続以外の権限なし**で作成可能。非対応サービスでは `400 Bad Request` で拒否されるため **空配列フィールドを消して再送しない**。サーバーのメンテナンス更新・契約変更も自動実施しない。

[MySQL 8.4 GRANT reference](https://dev.mysql.com/doc/refman/8.4/en/grant.html)：スキーマ単位の `REFERENCES` とテーブル単位の `SELECT` を分けられる。Aiven の `mysql_grants: ["SELECT","REFERENCES"]` は **付与権限の種類を指定するもの**であり、既存 `migrations` テーブルのみを読める保証がない。**その配列では代用しない**。

## オーナー承認後、ローカルの信頼できるクライアントで実施する段取り

1. **誤接続防止**：Aiven Console で本番サービスのプロジェクト・サービス・論理 DB 名・MySQL バージョンを非公開のまま照合。登録済みユーザーがまだ `avnadmin` だけと確認。接続先の IP 制限はこの手順では変更しない。
2. **Stage A：管理者権限なしで新規作成（Aiven API のみ）**。ローカルで `python3 scripts/ops/p0_aiven_zero_grants_payload.py --username canovia_p0_audit` を実行すると、次の *本文のみ* を生成する：

   ```json
   {"username":"canovia_p0_audit","mysql_grants":[]}
   ```

   Aiven の認証済み API クライアントから、公式 `POST /v1/project/{project}/service/{service}/user` に **この完全な本文だけ**を送信。API トークン・パスワード・ホスト名・証明書は **会話、GitHub、CI、共有画面に絶対貼らない**。API アカウント作成の実行はオーナーの明示確認後に限る。応答が `400` または不明なら **中断**し、`mysql_grants` を省略した再送信や Console 作成で回避しない。**ツール自体は API に接続しない。**
3. **Stage A の直後**：作成したばかりのアカウントを、オーナーの安全な接続手段で確認し `SHOW GRANTS FOR CURRENT_USER()` が *接続用 USAGE 以外なし* と照合。管理者権限・予想外のスコープがあれば Stage B へ進まず STOP。パスワードや実ユーザー名は共有しない。
4. **Stage B：正確な 2 件の権限だけを付与**。Aiven で既存 DB に対するテーブル単位の SQL `GRANT` がサポートされ、オーナーが権限変更を承認し、作成ユーザーの **正確な MySQL account host** と論理 DB が検証済みの場合に限り、管理者が以下の **概念上の 2 件**を実行する（そのままコピーして実行しない）。

   ```sql
   -- PRIVATE REVIEW TEMPLATE ONLY; not an executable production command.
   GRANT REFERENCES ON `VERIFIED_DB`.* TO 'VERIFIED_USER'@'VERIFIED_HOST';
   GRANT SELECT ON `VERIFIED_DB`.`migrations` TO 'VERIFIED_USER'@'VERIFIED_HOST';
   ```

   どちらも MySQL のアカウント権限を変更する操作であり、事前の別途承認が必要。既存ユーザーの REVOKE / 変更、`GRANT SELECT ON db.*`、`WITH GRANT OPTION`、管理ロール付与は禁止。
5. **Stage B の実測**：同ユーザー自身から `SHOW GRANTS FOR CURRENT_USER()` を取得し、`REFERENCES ON verified_db.*` / `SELECT ON verified_db.migrations` / 任意 `USAGE` **以外の grant がない**ことを厳密照合。MySQL 8.0/8.4 の使い捨てCIでは `users` の実レコード参照が拒否されることまで検証済みだが、Aiven でも拒否されることの独立確認が必要。サービス実機で未実証なら STOP。
6. **Stage C：メタデータ収集**。Aiven の CA 証明書と `mysql_config_editor` のローカル login-path を安全に設定し、既存 `scripts/ops/p0_mysql_operator_select_only_collect.sh` を *手動* 実行する。これはデータ行を読まない SQL 限定で、出力は匿名化された `BLOCK` / `REVIEW_REQUIRED` だけ。GitHub には結果コードのみ記録。**本番バックアップの復元試験とマイグレーションの実行承認は別ゲート**。

## 中止条件・実行しないこと

- 本番サービス同一性/論理 DB 名/ログインアカウント host が不明、`mysql_grants=[]` の API 対応がない、API が `400`、Aiven が SQL table-scoped grant を拒否、`SHOW GRANTS` が想定より広い → **作成/付与/検査をその場で中止**し、まず原因を匿名化してレビュー。
- `avnadmin` または既存アプリの秘密情報を検査ツールに転用しない。新しい DB、コストが発生するサービス、Render の環境変数、アプリ認証、既存ユーザー権限、Aiven のネットワーク許可を変更しない。
- PR #443 と #459 は Draft・未マージで維持。本番 `php artisan migrate` を自動で走らせる main マージは禁止。Stage A～C はアカウントと検査だけであり、ユーザーデータや DDL を変更しない。

**現在地:** Stage A の安全な *API 本文生成器* と使い捨て MySQL 8.0/8.4 権限テストを準備した。**Aiven API送信/ユーザー作成/GRANT/本番スキーマ検査は一切未実施。**

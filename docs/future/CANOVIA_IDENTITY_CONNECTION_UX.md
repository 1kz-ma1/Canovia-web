# Canovia Identity & Connection UX — Future Design

> Status: **Candidate / 未実装**
> Decision recorded: 2026-10-14
> Owner intent: OAuthの境界は維持し、本人確認と接続・共有承認のUXを段階的に簡素化する
> Authority: latest `main`, 現行のMCP account link / Plan consent契約が常に優先される
> 2026-10-15 reconciliation: Staging signed GitHub subject/indefinite synthetic grants were introduced after this proposal; durations below describe the 10/14 proposal, not a fixed current feature limit.

## 1. Purpose / Non-goals

Canoviaを外部AI・専用アプリとの接続ハブとして育てるにあたり、本人確認の重複、認証アカウントの取り違え、汎用エラーからの復帰難を減らす。安全な本人性確認と、外部サービスに対するデータアクセス承認を**別の判断**として扱う。

このFuture文書は、**今のOAuth受入テストの仕様を変更しない**。パスキー、OTP、ワンタイム接続コード、SSO、外部アプリへの「Sign in with Canovia」の実装や本番有効化を承認しない。

## 2. CURRENT / ACTIVE にある境界（要最新コード確認）

- Canovia自身のセッションユーザーと、Auth0などの外部IdPで確認した不変 `(issuer, sub)` は別の主体。
- 現行MCP連携はCanoviaのconfidential account-link clientによるOAuth Authorization Code + PKCE S256で外部IDを確認。ChatGPTのOAuth clientとは分離。
- `mcp_linked_subjects` があってもPlan共有を認可しない。各Planの明示的な共有承認（期限付きまたは明示的に取り消すまで）が別途必要。
- 現行Plan共有には、**同じ外部IDでの新しいOAuth確認**、Plan/範囲/期間の確認、最後の確定操作が必要。将来のセッション再利用案はこれを黙って省略してはならない。
- `issuer + sub` のHMAC fingerprintによる照合、active/revoked、現在の所有権、client/resource、scope、expiry、各リクエストのtoken検証とrevocationを維持する。
- 実装時は `docs/development/MCP_OAUTH_ACCOUNT_LINK_CONTRACT.md` と `MCP_EXPLICIT_PLAN_CONSENT_CONTRACT.md` を改めて参照する。

## 3. Target experience / FUTURE

1. 外部AIから「Canoviaに接続」を開始。ChatGPTとの実際の通信は互換OAuth認可フローを使用。
2. Canoviaの**現在の認証状態**を確認。保証レベルや有効期間に不足がある場合のみ追加認証を要求する。
3. 追加認証の第一候補は、Canoviaアカウントに正しく登録済みのパスキー等のフィッシング耐性の高い方法。既存のGoogle/Auth0外部IDの不変性を自動で変更しない。
4. 対象の外部アプリ、共有するPlan、`overview | tasks`、期間（現行仕様で許される選択肢。歴史的例：`1 | 7 | 30 days`、または別途受け入れた取り消すまで共有）を表示。ユーザーの明示操作でのみ同意する。
5. 接続成立後も継続的なtoken検証と現在の同意・Plan所有権・revocationを適用する。連携済みアカウントと共有許可の状態を明瞭に区別する。
6. 外部IDの不一致時は接続を拒否しつつ、認証済みの本人に限って安全なアカウント切替・再連携案内を出す。第三者に別アカウントの存在・IDを漏らさない。

## 4. Optional one-time connection code

利用目的は**既に強く認証済みのCanoviaセッションと、別端末の未確定接続リクエストの取引結合**であり、それだけで本人性確認・OAuth token発行・Plan閲覧を許可するものではない。

候補条件:
- 暗号学的に安全なランダム値、サーバー側ハッシュ保管、単一用途・単一取引・単回使用。
- 期限は原則5分以下。試行回数と発行レートを制限し、成功/失敗とも監査用の安全な固定カテゴリを記録。
- 認証済みactor、session/transaction、承認先client/resourceにバインドする。ブラウザに認証トークンやIdPの `sub` を出さない。
- コードの所持だけでは現在の外部IDの不一致を解決しない。承認対象Planの特定と明示的な最終共有承認を省略しない。
- ChatGPT側が対応しない独自コード入力をOAuth標準の代替と見なさない。
- メールOTPはメールアカウントの到達確認には使えても、唯一の高保証本人確認にはしない。

## 5. Identity lifecycle & recovery

- Identity proof（Canovia login / IdP / passkey）、account link、OAuth client authorization、Plan grantを独立管理。
- 異なる `sub` を同じメールアドレスという理由で自動統合しない。本人確認済みのアカウント切替/再連携には明示的な別フローと監査が必要。
- Fingerprint key rotation時は、旧リンクとの互換性/計画的移行/段階的再認証を別途設計。鍵の変更でリンクが不一致になる可能性を無視しない。
- 失効、拒否、キャンセル、Plan譲渡、共同Plan化、権限縮小、clientの廃止、session交替はfail closed。
- UX上の具体的な原因案内は、actor認証済みの範囲でのみ表示。公開のOAuthエラーはprivacy-safeな定型文を維持する。

## 6. Phased promotion proposal（未着手）

**Phase A — Now:** 既存OAuthの受入テストを完了する。ステージングの不一致原因を安全に切り分け、現行guardを弱めない。専用runbookを参照。

**Phase B — Recovery UX candidate:** 既存の認証済み画面で「連携済み」「共有承認済み」「不一致」を区別し、安全な再認証・再連携の説明を追加。APIやDB権限は変えず、既存不変ID照合は維持。

**Phase C — Strong reauthentication candidate:** Canovia認証方式の適合性とパスキー登録/復旧を調査。既存のfresh OAuth必須契約を変更するなら、保証レベル・脅威モデル・失効・クロスアカウント安全性の独立審査と新Active Specが必要。

**Phase D — Connection-code candidate:** 別端末UXが実際に必要なケースでのみプロトタイプ化。OAuth互換性・誤承認防止・レート制限を実機で検証。

**Phase E — Family identity candidate:** 将来の `Sign in with Canovia` と外部サービスごとの同意管理へ拡張。現行MCPでの成功のみをもって一般化しない。

## 7. Required acceptance before ANY promotion

- 正しい同一IdP主体を再認証すると同じCanovia actorへ連携できること。
- 別の `sub`、別actor、別client、異なるissuer、HMAC鍵不一致、取り消し済みリンク、期限切れ状態は拒否。
- リンクのみではMCP読取不可。適切なPlan同意があるときだけ許可され、scope/Plan/期限/失効が毎回効く。
- OTP/recoveryの総当たり、フィッシング、セッション固定、CSRF、コードリプレイ、クロスデバイス誤結合、漏えいをテスト。
- 外部アカウント・個人データ・秘密値を診断ログに出さず、認証済み本人だけに適切な案内を表示。
- CI・セキュリティレビュー・ステージングE2E・運用/ロールバック方針を別PRで承認。Production/Auth0/Renderの設定や課金を自動変更しない。

## References

- Current contract: `docs/development/MCP_OAUTH_ACCOUNT_LINK_CONTRACT.md`
- Current consent: `docs/development/MCP_EXPLICIT_PLAN_CONSENT_CONTRACT.md`
- Wider Future identity concept: `docs/future/CANOVIA_PLATFORM_ECOSYSTEM.md`
- Incident triage: `docs/development/MCP_STAGING_IDENTITY_LINK_MISMATCH_RUNBOOK_2026_10_14.md`

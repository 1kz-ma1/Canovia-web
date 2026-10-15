# Learning V6.27 — AP科目A P0 6問の一次レビューと応用問題案

2026-10-15 JST · Learning · development-only · **未監修・未公開**

## 記録の性質

審査対象は現行v0.4の未公開80問候補。オーナーが一時チャットで別途評価した6問の内容と、続くCanoviaでの採用・改訂方針を編集メモとして保存した。人間の独立専門家による査読・法務/利用権の承認・出典の個別再認証が行われたとはみなさない。AIが出した参考採点は客観的な項目反応理論（IRT）や実受験者の識別力ではない。

機械可読の編集資料:
`docs/learning/review_packets/AP_A_80_OWNER_FIRST_PASS_6Q_V627_2026_10_15.json`

資料は`candidate_key=ap/ap-a-2026-cbt-80-candidate-v2`、`candidate_version=0.4.0-review-required`と、元の原本`resources/question_packs/ap/ap-a-2026-cbt-80-candidate-v2.json`の**Git blob SHA-1**に紐づけている。今後原本の1バイトでも変われば検証を失敗させる。審査者の本人確認・独立権利審査・公開許可はすべてfalse。V6.24の80行review-notes、V6.25 proposal、V6.26 output、Admin DBでは**ない**。

## 対象・編集方針

| 既存ID | 番号 | 一次判定 | 対応 | 新問候補 |
| --- | ---: | --- | --- | --- |
| `db-view-009` | 13 | 正答妥当、絶対的断定で消去法が容易 | 基礎問題維持（修正なし） | `ap80-extra-view-update-v1` |
| `calc-mm1-015` | 24 | 正答・式が正確、公式が与えられ応用診断は弱い | 基礎問題維持（説明補足は将来検討） | `ap80-extra-mm1-arrival-v1` |
| `sec-dkim-023` | 34 | 正答妥当、`DKIM-Signature`が直接ヒント | **設問+解説**修正案 | `ap80-extra-dmarc-alignment-v1` |
| `quality-reliability-032` | 48 | 正答妥当、ISO 2011主特性を明示したい | **設問+解説**修正案 | `ap80-extra-availability-v1` |
| `test-boundary-043` | 61 | 正答妥当、3値分析の範囲は区別が必要 | **設問+解説**修正案 | `ap80-extra-bva3-v1` |
| `sec-csrf-047` | 68 | 正答妥当、`CSRF対策トークン`がヒント | **設問+解説**修正案 | `ap80-extra-samesite-lax-v1` |

### 修正文案と制限

4件は既存の問題ID/4肢/正答/採点規則/出典/難易度/順序/タグを一切変更しない、`prompt`と`explanation`の**全文置換案**。新しい技術情報を含むため、各設問・解説は引き続き独立の技術・権利審査対象。提案は**編集上の草案**であり、新しいv0.5の正式候補にも現行Packにも適用されていない。

- DKIM: RFC 6376に沿って選択ヘッダーと署名対象本文、`d=`/`s=`と`l=`の留保を記載。Fromドメインの整合はDMARCの責務である点を維持。
- ISO: ISO/IEC 25010:2011の**主特性**と明記。可用性は信頼性の**副特性**であり、2023年版との差分は補足。
- BVA: 境界の切替対象を限定。3値分析を4つの境界値に適用した8値と、受理側2境界に限る6値の例を混同しない。
- CSRF: 攻撃名を含む設問の直接ヒントを除去。有効なセッションCookieが実際に送信された状況を前提化。`SameSite`の**ブラウザCookie送信制御**とサーバによる拒否を分ける。

問68の別チャット改訂例は正答をイに移していたが、今回は既存の「ア」のままで本文だけ改訂する。問61で提案された4値ずつの選択肢構成も元の問題に**適用していない**。V6.25/26が正答・選択肢の変更を許可しないため、将来的に独立新問を査読する。

### 新規応用問題候補6件

同じJSONの`new_question_candidates`に、それぞれ**独立ID・問題文・4肢・正答・解説・技能タグ・根拠**を記録した。実行時読み込み対象の`resources/question_packs/`や`resources/learning_review/`に新規登録していない。既存80問の総数や試験相当80問の構成を変えていない。

- SQL `CREATE VIEW`、PostgreSQLの`GROUP BY`自動更新制限
- M/M/1の到着率`λ`、利用率`ρ=λT`、最小値
- DKIM/SPFがpassでもDMARCアライメント失敗
- ISO 25010:2011の信頼性の副特性・可用性
- ISTQBの3値BVAを4境界値に適用した完全な8値
- `SameSite=Lax`を**明示**したクロスサイトPOSTのCookie送信条件

**テストで保証されるもの**: データ形状、元source blob固定、6件の識別子と原正答、4件がV6.25比較を通過する仮想シナリオ、V6.26が**メモリ内**で別slug未公開Draftを組み立てられること、各新問は固有ID/4選択肢/模擬正答を持つこと。いずれも人間による真正な審査、別問の専門正答レビュー、権利監修、試験相当性/IRTの統計評価を保証しない。

## 次のオーナー操作と実審査ゲート

1. 審査メモの6件と4修正案を見直す。`review_items`の記載は実際の本人確認・監修承認の証明ではない。
2. V6.24で**本人が実際に確認した**80行のレビュー記録を用意する。該当4件を`needs_revision`にする場合は真実の観測と記録を使う。テストコードの`synthetic-fixture-v627`は**テスト専用**であり、レビュー記録や公開承認に絶対に流用しない。
3. 修正案は適切なV6.25 proposalへ変換する際に、直近の原本`candidate_content_sha256`と各行の`question_content_sha256`を使う。原本が変わったら再評価。`study:ap80-revision-compare`で内容指紋と編集フィールドを検証する。
4. 実際のV6.24 + V6.25が一致した後、V6.26で**local/testingの非公開ファイル**へ新Draftを出力できる。V6.26テストの合格や未承認の編集メモだけを根拠に実際のV6.24 review-notes を偽造してはいけない。
5. 新Draftに対して再度、正答、3誤肢、解説、重複・難易度、出典・利用権を個別に独立審査する。35件のIPA由来過去問を含む80問の利用条件/原典再確認は未完。
6. 新規6問は適切な出典・権利・問題類似度/範囲・計算確認を経て**別の新規Draft**としてレビューする。現行80問へ自動追加しない。
7. 将来の承認済み段階で初めて、別の明示許可により管理者インポート・公開ゲート・Render/iOSデプロイへ進む。

## 根拠資料（参照先。URLだけでは人手審査済みではない）

- [RFC 6376 DKIM](https://www.rfc-editor.org/rfc/rfc6376) / [RFC 7489 DMARC](https://www.rfc-editor.org/rfc/rfc7489)
- [ISO/IEC 25010:2011](https://www.iso.org/obp/ui/?_escaped_fragment_=iso%3Astd%3Aiso-iec%3A25010%3Aed-1%3Av1%3Aen) / [ISO/IEC 25010:2023](https://www.iso.org/standard/78176.html)
- [ISTQB CTFL v4.0.1](https://istqb.org/wp-content/uploads/2024/11/ISTQB_CTFL_Syllabus_v4.0.1.pdf)
- [OWASP CSRF Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html)
- [PostgreSQL CREATE VIEW](https://www.postgresql.org/docs/current/sql-createview.html)

## Production / public impact

編集資料・専用テスト・CIのみ。DBテーブル、Laravel実行API、既存Pack/JSON、Study UI/Exam profile、権限、課金、Render/Aiven、`production`、iOS、PR #513に変更なし。GitHubのdevelopment `main`は本番公開の証拠ではない。実利用者向けの公開ロードマップも更新しない。

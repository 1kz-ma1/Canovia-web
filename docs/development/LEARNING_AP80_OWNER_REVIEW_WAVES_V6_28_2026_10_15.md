# Learning V6.28 — AP科目A 残り74問の一次レビュー・8バッチ引継ぎ

2026-10-15 JST · Learning / local operator · 未承認・未公開

## Intent

V6.27の**6件は、オーナーが別のAIチャットによる独立評価を読んで持ち込んだ一次的な編集判断**。これは人間の認証された独立専門監修ではなく、V6.24の正式な署名済み内容監修・権利許諾資料でもない。残り74問はまだ一次評価未提出。レビュー実施を自動で捏造せず、次の74問を再現可能な**優先順位/ID/元データ版/SHA**で案内する。

V6.28はGit管理済みの元v0.4とV6.27の読み取り専用メモを使用する。`source` JSONの**Git blob SHA**と現行V6.24のcanonical SHAが一致すること、P0は6件、P1のIPA35件、P2の独自39件の全件が重複なく存在することを確認する。ソースが変化すれば古いレビュー準備を流用せず失敗する。

## How to use

ローカルのCanovia-webソースを使用し、Laravelの依存関係を準備したうえで：

```sh
# 8バッチのリストのみ（問題・答・解説の本文なし）
APP_ENV=local php artisan study:ap80-review-wave

# 次に確認すべきIPA過去問10件の番号・ID・原典表記・SHA
APP_ENV=local php artisan study:ap80-review-wave --batch=1
```

**ブラウザで問題自体を読む場所**: 管理者権限の` /admin/question-packs `（前後の空白なし）の「80問それぞれの出典・正答・解説と監修項目を確認する」。元の出題と正答、誤答肢、解説、原典/解答資料リンクを照合する。`main`にしか存在しない変更は本番ブラウザへ反映されているとは限らない。

**レビューの入力と貼り付け**: 各問ごとに「番号/ID」「原典・正答一致」「誤答3肢の一意性」「解説の正確性」「測定する能力」「難易度と重複」「出典・利用条件」「基礎確認/弱点診断/本番相当の用途」「維持/修正/別問題追加」を確認してオーナーが所感を整理。AIチャットから出た評価は、その真偽と原典を実際に確認し、審査メモとして持ち込む。自動採点/原典PDF存在/権利確認が済んだと推測しない。

## Priority and review waves

| Batch | 優先度 | 対象 | 問題数 |
|---|---|---|---:|
| 1 | P1 | IPA 2025秋の最初の10件 | 10 |
| 2 | P1 | 次のIPA10件 | 10 |
| 3 | P1 | 次のIPA10件 | 10 |
| 4 | P1 | 最後のIPA5件 | 5 |
| 5 | P2 | Canovia独自の最初の10件 | 10 |
| 6 | P2 | 次の独自10件 | 10 |
| 7 | P2 | 次の独自10件 | 10 |
| 8 | P2 | 最後の独自9件 | 9 |

同じ優先度内では元の80問データの出現順に並べる。**番号は元の80問中の番号であり、IPA原典の問番号とは異なる**。

### Batch 1 — Next ten official IPA items (first-pass NOT YET SUBMITTED)

| 元の80問番号 | 元候補ID / 2025秋IPA原典問 |
|---:|---|
| 1 | `ipa-2025-autumn-ap-am-q03` |
| 3 | `ipa-2025-autumn-ap-am-q04` |
| 5 | `ipa-2025-autumn-ap-am-q05` |
| 7 | `ipa-2025-autumn-ap-am-q06` |
| 10 | `ipa-2025-autumn-ap-am-q07` |
| 12 | `ipa-2025-autumn-ap-am-q08` |
| 14 | `ipa-2025-autumn-ap-am-q09` |
| 17 | `ipa-2025-autumn-ap-am-q11` |
| 19 | `ipa-2025-autumn-ap-am-q12` |
| 21 | `ipa-2025-autumn-ap-am-q13` |

IPA出題の原典PDF・公式正答表・引用/レイアウト整形の差分・Canoviaの自作解説の正しさ・出典表示・利用条件を別々に確認する。公式原文を**このハンドオフJSONに複製していない**。公式過去問の正答・引用条件の原典資料リンクは現行Admin詳細にある。参照する[IPA試験FAQ](https://www.ipa.go.jp/shiken/faq.html)は全提供形態への一括許可証ではなく、個別の出典表示/改変表記/利用方法を確認する。

## Technical contract and safeguards

`ApExamCandidateOwnerReviewWavesService::plan()` = all batches, `describe()` = contentless summary, `describe(1..8)` = exactly one selected batch's metadata details. CLI`study:ap80-review-wave --batch=N` outputs JSON with source ID, number, `source_reference`, limited concepts, canonical per-item SHA and fixed review focus. **No problem body, choices, correct answer, explanation, reviewer name, private file path, original published questions copied**. Only local/testing permitted. No DB writes, remote links downloaded or contact with providers.

Review status is exclusively `owner_first_pass=not_yet_submitted` on all 74, `independent_content_review=pending` and `independent_rights_review=pending` on all 74, while the exact 80-item independent human reviews remain 80 pending. The other six earlier recorded owner notes do not count toward 80 independently attested signoffs.

No side effects on Git bundled question data, 6 earlier review records, V6.24 notes, V6.25 proposed changes, V6.26 preview output or storage. All 8 batches are *review planning*, not 74 verified outcomes, and do not authorize any release.

## Future steps

The next actual owner review is **80問の問1（IPA原典令和7年度秋期AP午前問3）** in batch 1. Display it in protected Admin (or check the public official original), independently compare original/answer, assess distractors, explanation, use rights and whether the problem measures knowledge or a learned strategy, then paste back the independent evaluation. After 10 owner first-pass notes are collected, a separate future slice can classify changes without faking human authorizations. Existing P0 edits remain editorial proposals only, and 6 candidate applied questions still unpublished.

**Production impact**: development-only command/service/test/spec; no deployed public UI or iPhone acceptance, migrations, entitlement, Render/Aiven, DB import, question count changes, or production promotion. PR #513 remains NO-GO. Public roadmap unchanged because this only helps offline review preparation.

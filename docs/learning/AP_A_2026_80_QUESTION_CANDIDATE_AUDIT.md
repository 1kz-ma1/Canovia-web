# AP 科目A（2026 CBT）80問模試候補 — 内容審査・公開ゲート

> 2026-10-09 / Candidate v0.3.0-review-required / **NOT REVIEWED · NOT INSTALLED · NOT PUBLISHED**
>
> Source of truth: `resources/question_packs/ap/ap-a-2026-cbt-80-candidate-v1.json`. Merely committing this file does not install it in a database, grant reuse rights, or authorize a real-exam-quality label. Production and device verification remain separate.

## Confirmed official exam FORMAT (not question content)

- IPA AP information: <https://www.ipa.go.jp/shiken/kubun/ap.html>. 2026 CBT Subject A is **150 minutes, 80 questions answered out of 80, four-option single answer**.
- IPA's released past-question usage guidance: <https://www.ipa.go.jp/shiken/mondai-kaiotu/index.html> and <https://www.ipa.go.jp/shiken/faq.html>. IPA retains copyright, allows use of published past exam questions in exam-preparation materials under stated conditions, and requires explicit year, period, exam classification, section and question-number source attribution. This does **not** validate third-party text extraction, illustrations or the content of Canovia's original derivatives.
- **Question distribution of Canovia candidate is not certified to represent the exam**; official format verification does not imply this set predicts the 2026 AP exam.

## Assembled inventory (draft only)

| Group | Included | Origin | Human verification |
|---|---:|---|---|
| IPA 令和7年秋期 original official questions (text-formatted) | 35 | `ap-a-ipa-2025-autumn-official-v1` | Compare to IPA source problem + answer PDFs, year/period/q no.; source usage and content accuracy |
| Canovia Core original/derived topic questions | 35 | `ap-a-canovia-core-v1` | Independently check correctness, calculation conditions, distractors, derived attribution, scope |
| Canovia original Management / Strategy supplement | 10 | `ap-a-canovia-business-management-supplement-v1` | Draft (not expert reviewed); manually check content, keys, distractors and rights |
| **Total** | **80** | Both sources, unchanged question/choice/answer text | **Not approved** |

- Originally **109** available source questions: 74 Core + 35 IPA. v0.3.0 uses 35 of the original Core, 35 original IPA and **10 newly authored Canovia original management/strategy draft questions** in their own unreviewed source pack. Original Core and IPA source packs remain unchanged. Source links are preserved in `learning_metadata.curation`.
- Question IDs are unique, each has `ア・イ・ウ・エ` choice labels and a matching answer key; the original prompt, options and official answer are copied without rewriting. This v0.2.0 revision **adds 35 independent Canovia-authored draft explanations** to the candidate only (not to the original IPA source pack), with per-question origin and pending-review markers. The display order remains 1–80.
- **v0.3.0 provisional broad-domain split:** technology **50**, management **10**, strategy **20**. This aligns with a *historical old-AP morning exam breakdown*, not a published 2026 CBT guaranteed quota. Sources: <https://www.ap-siken.com/s/apkeisiki.html> and <https://www.ipa.go.jp/shiken/2026/ap_koudo_sc_kikan.html> (official IPA confirms no 2026 scope / count changes, but not an exact domain quota). Category labels are taken from official questions and Core topic tags; an expert still needs to audit individual items against the current syllabus, depth, learning objectives and topic variety.
- **2026-10-09 explanation progress:** CI previously found **35 missing explanations in the original IPA-source pack**. v0.2.0 now contains **35 newly drafted Canovia explanations** for those official questions; all 80 candidate entries now have text. Each official explanation is clearly labeled `canovia_independent_draft` / `awaiting_subject_expert_check`. Original IPA pack remains unchanged and continues to have 35 missing explanations. This solves the candidate's text-coverage gap but **not** expert verification. The exam readiness gate blocks `explanation_review_state=pending_human_subject_review` and requires `exam_simulation_review.explanations_checked=true` plus the content fingerprint.
- **Near-duplicate review (partial, recorded 2026-10-09):** five Core questions with strongly overlapping concepts were removed in favor of official source items: `calc-availability-016` ↔ IPA q13 (MTBF formula), `calc-sampling-018` ↔ IPA q25 (Nyquist interval), `sys-cache-019` ↔ IPA q19 (LRU and same values), `sys-semaphore-020` ↔ IPA q16 (two sensors on I²C), `sys-deadlock-021` ↔ IPA q18 (resource acquisition order). The candidate now retains the official counterpart only. Another five Core technology examples were replaced to make room for 2 management + 8 strategy subjects: `net-cidr-004`, `net-stp-007`, `db-mvcc-010`, `algo-hashtable-040`, `calc-bottleneck-027`. Those are **valid practice topics**, not necessarily incorrect. The complete Core source remains available separately.
- Automated QA can detect exact normalized prompt matches, unsupported choice formats, missing source references, and inconsistent answers. It **cannot** detect subtle reworded duplicates, missing figures/tables, wrong arithmetic assumptions, learning-level calibration or rights sufficiency.

## Fail-closed release gates

1. **Import is an explicit administrator POST**, not part of deploy, app startup or migration. Admin bundled Pack name: `ap/ap-a-2026-cbt-80-candidate-v1`. It becomes a **Draft** in that database.
2. Pack metadata `review_state: pending_human_content_and_rights_review` and `explanation_review_state: pending_human_subject_review` **block ordinary published status** even if all 80 are structurally valid and nonempty. Neither PWA nor Native Learning Run will see an unapproved candidate.
3. Complete a question-by-question human checklist:
   - Check source problem/answer for all 35 IPA problems, keeping publisher/year/period/section/question number and confirming IPA usage notices;
   - **Drafted (not approved):** independently check the 35 Canovia explanatory drafts against all official questions and answer keys; verify nuanced definitions, maths and distractor rationale, recording reviewer and corrections. Never misattribute these explanations to IPA;
   - Check all 35 retained Core original/derived items and 10 new Canovia supplements for accurate wording, calculations, answer keys, explanations, credible distractors, the requirement to say `病気` rather than a decontextualized `異常` in Bayesian exercises, and semantic near-duplicates;
   - Inspect four-option format, 80 unique source questions, subject/syllabus scope, domain breadth and accessibility without lost diagrams, tables or mathematical notation;
   - Record reviewer, review date, issues found/resolved, exact source revision, and rights determination outside the exam profile; do **not** mark reviewed based solely on CI.
4. Where corrections are needed, update a **new draft JSON revision/version** (published/retired packs may never be overwritten), import/review again. Maintain original official source text or explicitly mark any derived changes as derivatives with correct attribution.
5. Only after substantive human review, set `pack.metadata.review_state` to `reviewed`, **`explanation_review_state` to `reviewed`**, and include `exam_simulation_review` containing:
   `reviewed_pack_version` (identical to `pack.version`), `reviewed_at` (YYYY-MM-DD), `format_checked: true`, `answer_key_checked: true`, **`explanations_checked: true`**, `content_rights_checked: true`, and **`reviewed_content_sha256`**.
6. The admin Pack status page shows **the SHA-256 for the actual normalized active database questions**. It is calculated by `AdaptiveExamPackReadinessService::contentFingerprint`, over exact question text, answer keys, choices, explanation, source attribution, metadata and order. After all edits and the final Draft re-import, copy the hash from this admin page into review metadata and re-import once more. Merely copying the hash is **not** human approval. Any edited question or answer invalidates the approval, even without a version change.
7. Separate ordinary Question Pack preflight, then explicitly publish through admin. **Only then** can the exam entry appear if the 80-question AP profile check also passes. Restartable mock attempts freeze questions and time separately from the bank.

## Verified by CI vs not yet verified

- CI: source composition audit (35 IPA + 35 Core + 10 supplemental original), source problem and answer fidelity, 50/10/20 *provisional historical* domain target, five explicit known semantic duplicates excluded, distinct authored candidate-only explanations for each IPA source question, explanation-review status, four choices, unique keys/prompts, provenance, Draft-only import, unauthorized admin denial, publication blocked; SHA-256 invalidation when a correct answer changes; synthetic 80-question exam route, timeout and final grading.
- Not yet done: full question/choice-by-question visual comparison with IPA source PDF (the official **answer keys** have been checked against IPA's published answer table, but that alone does not verify all transcription), expert verification/sign-off of all 35 draft explanations, accuracy/sign-off of 45 Core questions, semantic duplicate review, syllabus-proportional coverage calibration, owner approval and rights determination, production DB import, production availability, real iOS/PWA operation. Do not report these as completed.

## 2026-10-09 L5-d: 実務監修用の問題別検査・確認キュー

- `ApExamCandidateAuditService` は静的Bundled JSONだけを読み取り、80問それぞれの出典元Packと外部キー、正答、選択肢、解説、問題番号、IPA原問題リンク、未確認の人手監修項目を返す。DB変更や承認・公開操作は一切行わない。
- 管理者専用 `/admin/question-packs` に折り畳み式の80問監査キューを表示する。一般利用者には正答を公開せず、問別の検査フラグと「人手監修待ち」の区別を確認できる。**検査が0件失敗しても80件全てが専門監修待ち** であり、機械的な原典コピー照合を「正答確認済み」と表現しない。
- 現状構造監査: 全80問の元問題・4択・正答キー・出典紐付け・完全一致重複チェックは通過。候補v0.3.0の分野は暫定50/10/20、既知の5組の明示的な重複除外を再チェック。
- 代表的なCore/追加の9問は、MSS・M/M/1・ベイズ・MIPS・D/A・SLA・損益分岐・RAID5・ROIの式を別に計算し、**実際の選択肢ラベル**と一致することをCIで確認。EVMのSV/CVも別計算。これは**限られた計算問題の客観的な検算**であって、残りの正答や説明全体への独立専門認定ではない。
- 監修者はIPA35問について原問題PDFとCanovia独立解説35件の照合、Core35問と新規10問について正答・誤答選択肢の理由・類似性・現行シラバスの深度・適法利用を問題別に確認。結果や修正履歴を記録し、レビューの最終版だけに `exam_simulation_review.explanations_checked` と `reviewed_content_sha256` を適用。**このPRで承認メタデータは設定しない**。

## 2026-10-09 L5-e: IPA原典PDFの部分照合と内容監修の優先度

- 原問題PDF（IPA: https://www.ipa.go.jp/shiken/mondai-kaiotu/nl10bi0000009lh8-att/2025r07a_ap_am_qs.pdf ）の**画像**とCanoviaテキスト版を比較。まず **問11 (PDF 8ページ), 問15 (10ページ), 問23 / 問25 (14ページ), 問57 (27ページ), 問64 (30ページ)** の設問条件・数値・4選択肢が一致することを確認。特に問11の表形式ノード数/TFLOPS、問15の表形式の到着時刻/処理時間は、Canoviaのテキスト再構成後も必要な値が含まれる。
- 内容根拠を `resources/learning_review/ap-a-2026-source-spotchecks-v1.json` に原PDFのページ番号、出題ID、実際に目視照合した内容、照合対象の問題文/4選択肢/正答キーのスナップショットとして保持。問題・選択肢・正答・Pack版の変更があればその照合結果は失効する。
- 上記は**限られた6問の原文/選択肢スポット照合**であり、35問の専門家による解説評価・全件原文照合・利用権審査・監修署名ではない。**公式問題29問は原典PDFの目視照合未実施**のまま。
- 管理者監査キューをP0（未照合IPA29問＋機械的不整合）、P1（照合済みIPA6問の解説監修＋新規問題10問＋主な計算Core）、P2（その他Core）でソート。これは作業順の推奨であり、**専門監修の進捗は80/80未完了**、模試公開ゲートは閉じたまま。優先度によって試験の問題順や採点キーを変更しない。

## 2026-10-09 L5-f: 公式35問の原典・解答表の画像照合完了（**内容監修とは別**）

- L5-eで原典PDFと確認した6問に加え、残り29問の該当ページも問題冊子の画像から確認。候補に収録した **公式35問の設問条件・全4肢・表の数値** が視認範囲で一致することを照合し、個別の照合根拠を `resources/learning_review/ap-a-2026-source-spotchecks-v1.json` へ追加。従来の「6/35」の状況はここに更新され、**35/35の原典照合、0問が原典照合待ち**。
- IPA公式の解答例PDF（https://www.ipa.go.jp/shiken/mondai-kaiotu/nl10bi0000009lh8-att/2025r07a_ap_am_ans.pdf）の35問分の正答と候補の正答を比較し、別入力の期待値35件としてCIテストに固定。管理者の監査カードは問題冊子および解答例の両方へリンクする。
- 記録は `candidate_version` および各問の `verified_snapshot`（設問文・全選択肢・正答）に紐付き、どれかの変更時に自動失効。これは**自動OCRの結果ではなく原PDFページ画像との目視比較に基づく記録**だが、問題の商用利用条件・Canovia独自解説・選択肢の教育上の品質・全体の難易度・2026 CBTとの適合性を保証するものではない。
- 管理者画面のP0（原典未照合）は現状0問。IPA35問は解説や出典条件の別監修が必要なためP1に残る。新規独自問題10問もP1、その他CoreもP1/P2で人手確認が必要。**80/80問の独立内容・解説・権利の承認はまだゼロ、公開ゲートは閉じたまま**。
- 未実施: 公式35問の独自解説案の独立正確性レビュー、Canovia Core35問+新規10問の全選択肢・説明の第三者確認、同義/類似問題の網羅的検査、最新シラバスへの最終適合評価、著作権/再配布条件の承認、実機検証と本番DBへの問題集取込。

## 2026-10-09 L5-g：Canovia独自問題45問の誤答肢レビュー案（専門承認なし）

- 80問候補内の**既存Core35問＋新規Canovia10問の45問**について、四択の正答以外の**135肢**に個別の「その肢が不適切な理由」の草案を追加。ソースは `resources/learning_review/ap-a-2026-canovia-45-choice-audit-v1.json`。内容はCanoviaが作成した検討用コメントであり、第三者の正答保証や監修者の署名ではない。
- 45件全てについて現行問題文・4選択肢・採点キー・解説・Pack版の完全一致を要求。一文字でも変われば個別のコメントは `missing_or_stale` として表示し、再点検の優先度P0へ。**既存の正答・問題文・解説、元Pack、模試Packには一切変更なし**。
- 管理者画面の監査キューは各誤答理由と重点注意事項を折り畳み表示。うち6問は条件付きの注意点を記録：`sec-csrf-047`（現代ブラウザのSameSite等）、`db-view-009`（DBMSごとのVIEW更新条件）、`quality-reliability-032`（品質規格と信頼性）、`test-boundary-043`（境界の直内値）、`sec-dkim-023`（署名ドメインとFromの相違）、`calc-mm1-015`（M/M/1安定条件）。参考：OWASP CSRF Cheat Sheet、PostgreSQL CREATE VIEW、ISO/IEC 25010:2023公式概要。
- 内容の真偽を自動的に承認するものではない。特に**CSRFの攻撃例とブラウザ設定が食い違わないか、品質特性の規格版、誤答に正解となる余地がないかは人間の再点検が必要**。合格点や本試験との等価性は証明されない。
- CIは45問・135肢の漏れ、正答肢への誤答理由誤付与、内容/版/採点キー/解説変更時の証拠失効、管理者以外への監査非表示、公開ゲート閉鎖と80/80件の独立監修待ちを確認する。**`review_state`、`explanation_review_state`、`exam_simulation_review`は更新しない**。

## 2026-10-09 L5-h: 高優先度6問の精査・校正提案（未適用）

- 独自問題45問のうち条件・規格上の注意が必要な6問について、公式技術資料（RFC 6376/7489、OWASP CSRF、PostgreSQL CREATE VIEW、ISO/IEC 25010:2023、ISTQB CTFL 4.0.1）を確認し、修正案を `resources/learning_review/ap-a-2026-six-priority-clarifications-v1.json` に保存。**設問変更案4件・解説変更案6件・採点キー変更0件**。意味を損なわずに問題・誤答肢の根拠を明確にする。
- 優先度最高: `sec-dkim-023` の「送信元ドメインのなりすまし」の表現はDKIMだけでRFC5322.Fromを保証するように誤認され得る。DKIMのd=署名ドメイン・署名対象メッセージの整合性と、Fromドメインとの照合を行うDMARCの役割を区別する案を作成。
- `sec-csrf-047` はCSRF対策トークン等がない脆弱な実装という成立条件を設問中に明記する案。
- `db-view-009` はビューが定義できることと、更新可能かどうかが異なることを説明（DBMS固有の条件に留意）。
- `quality-reliability-032` は信頼性・可用性の関連とISO/IEC 25010の版差に留意する案。
- `test-boundary-043` は2値境界値分析を明示し、3値境界分析との違いを解説する案。
- `calc-mm1-015` はT>0、0≤ρ<1の安定条件を明示し、不等式の変形根拠を補う案。
- どの提案も候補v0.3.0の設問本文・選択肢・正答・解説を**変更していない**。既存Core Packと本番DBも変更しない。原設問・全4肢・正答・解説・候補版に紐付けた `ApExamCandidateAuditService::matchesRevisionProposal` で、後から内容が編集された場合には提案が失効・再監査に戻る。管理者画面で未適用・未承認と明記して比較できる。
- 次の工程で独立した内容監修者が承認・調整し、新バージョンの候補に取り込む際は、原典との変更点を記録し、IPA原典35問の照合証拠・独自45問の誤答理由135肢・公開前レビューSHAを適切に更新する。**この作業では監修者の承認を偽装しないし、正式模試の公開はしない**。

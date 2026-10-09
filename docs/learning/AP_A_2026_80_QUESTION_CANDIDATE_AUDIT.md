# AP 科目A（2026 CBT）80問模試候補 — 内容審査・公開ゲート

> 2026-10-09 / Candidate v0.1.0-review-required / **NOT REVIEWED · NOT INSTALLED · NOT PUBLISHED**
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
| Canovia original/derived topic questions | 45 | `ap-a-canovia-core-v1` | Independently check correctness, calculation conditions, distractors, derived attribution, scope |
| **Total** | **80** | Both sources, unchanged question/choice/answer text | **Not approved** |

- The original **109** available questions include **74 Core + 35 IPA**; 29 Core questions are left outside this candidate to reduce a concentrated topic load. The selected 45 Core IDs and source relationships are embedded per question at `learning_metadata.curation`.
- Question IDs are unique, each has `ア・イ・ウ・エ` choice labels and a matching answer key; the original prompt and answer are copied without rewriting, and the only deliberate changes are the fixed display order (1–80) and additive source mapping.
- Initial **tentative** broad-domain split by Core question's category/tag and official source-domain labels: **technology 60, management 8, strategy 12**. This is intentionally **not** an official AP domain distribution. In particular strategy/management coverage and depth should be reviewed against the then-current syllabus before claiming comparability.
- **CI audit found 35 missing explanations, all in the original official-source pack.** None was written as part of candidate assembly. A reviewed AP mock now requires an independently checked explanation for every question; the exam readiness gate blocks missing explanations. Do not fill with invented or generic answer comments.
- Automated QA can detect exact normalized prompt matches, unsupported choice formats, missing source references, and inconsistent answers. It **cannot** detect subtle reworded duplicates, missing figures/tables, wrong arithmetic assumptions, learning-level calibration or rights sufficiency.

## Fail-closed release gates

1. **Import is an explicit administrator POST**, not part of deploy, app startup or migration. Admin bundled Pack name: `ap/ap-a-2026-cbt-80-candidate-v1`. It becomes a **Draft** in that database.
2. Pack metadata `review_state: pending_human_content_and_rights_review` intentionally **blocks ordinary published status** even if all 80 are structurally valid. Neither PWA nor Native Learning Run will see an unapproved candidate.
3. Complete a question-by-question human checklist:
   - Check source problem/answer for all 35 IPA problems, keeping publisher/year/period/section/question number and confirming IPA usage notices;
   - Author and independently check solution explanations for **all 35 official-source entries** whose original record has no explanation; mark commentary as Canovia's explanation rather than misattributing it to IPA;
   - Check all 45 original/derived items for accurate wording, calculations, answer keys, explanations, credible distractors, the requirement to say `病気` rather than a decontextualized `異常` in Bayesian exercises, and semantic near-duplicates;
   - Inspect four-option format, 80 unique source questions, subject/syllabus scope, domain breadth and accessibility without lost diagrams, tables or mathematical notation;
   - Record reviewer, review date, issues found/resolved, exact source revision, and rights determination outside the exam profile; do **not** mark reviewed based solely on CI.
4. Where corrections are needed, update a **new draft JSON revision/version** (published/retired packs may never be overwritten), import/review again. Maintain original official source text or explicitly mark any derived changes as derivatives with correct attribution.
5. Only after substantive human review, set `pack.metadata.review_state` to `reviewed`, and include `exam_simulation_review` containing:
   `reviewed_pack_version` (identical to `pack.version`), `reviewed_at` (YYYY-MM-DD), `format_checked: true`, `answer_key_checked: true`, `content_rights_checked: true`, and **`reviewed_content_sha256`**.
6. The admin Pack status page shows **the SHA-256 for the actual normalized active database questions**. It is calculated by `AdaptiveExamPackReadinessService::contentFingerprint`, over exact question text, answer keys, choices, explanation, source attribution, metadata and order. After all edits and the final Draft re-import, copy the hash from this admin page into review metadata and re-import once more. Merely copying the hash is **not** human approval. Any edited question or answer invalidates the approval, even without a version change.
7. Separate ordinary Question Pack preflight, then explicitly publish through admin. **Only then** can the exam entry appear if the 80-question AP profile check also passes. Restartable mock attempts freeze questions and time separately from the bank.

## Verified by CI vs not yet verified

- CI: source composition audit (35+45), exact source fidelity, four choices, unique keys/prompts, provenance, Draft-only import, unauthorized admin denial, publication blocked; SHA-256 invalidation when a correct answer changes; synthetic 80-question exam route, timeout and final grading.
- Not yet done: review of IPA PDFs against all 35 transcription entries, accuracy/sign-off of 45 original problems, semantic duplicate review, syllabus-proportional coverage calibration, actual owner authorization to publish, production DB import, production availability, real iOS/PWA operation. Do not report these as completed.

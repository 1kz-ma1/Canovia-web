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

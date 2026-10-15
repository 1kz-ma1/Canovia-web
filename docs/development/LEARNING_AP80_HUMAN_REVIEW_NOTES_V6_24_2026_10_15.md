# Learning V6.24 — AP科目A 80問Draft 人手レビューの提出前照合

**2026-10-15 JST · Learning lane · development only · no human signoff or publication**

## Scope and existing implementation reuse

The **current unapproved AP A v0.4 80-question bundled Draft** already has a per-question pending worklist, P0 six editorial corrections, official 35 source spotchecks, original 45 distractor notes, content-revision evidence and a read-only admin audit queue. These come from `ApExamCandidateQualityReviewService`, `ApExamCandidateRevisionPreviewService` and the existing resource manifests.

V6.24 **does not generate another question set, import any Pack or claim independent reviewer approval**. It adds the missing *read-only operator handoff and intake validation* for an externally edited notes document, kept completely separate from V6.20–V6.23's official syllabus mapping registry. No question is declared official-syllabus mapped by this feature.

## Operator command

Use only an explicitly approved local/testing operator environment. These commands only read repository `resources/question_packs/` and `resources/learning_review/` inputs. They do not read a Canovia DB or call an external AI provider.

```sh
umask 077
APP_ENV=testing php artisan study:ap80-review-notes > /tmp/ap80-review-notes.json
# After actual independent human review of exact current questions:
APP_ENV=testing php artisan study:ap80-review-notes --review=/tmp/ap80-review-notes.json
```

Reviewers inspect the actual 80 question texts, answer options, explanation, source credit, difficulty and use rights **separately** through the existing admin-only QA queue and original permitted source documents. The exported notes JSON contains only item number, external key, provenance category, priority and an item-level SHA-256 computed with the existing importer normalization/exam-readiness fingerprint (includes canonical prompt/options/answer/explanation/provenance/order), plus blank review fields.

The 80 rows are bound to the exact **`ap/ap-a-2026-cbt-80-candidate-v2`** / `0.4.0-review-required` and exact preview pack-wide content SHA-256. Any changed item content, version, item order, addition/deletion or altered status fails closed; an old notes file is never carried over as verified after a changed question.

## Human notes states and semantics

- `pending`: five required dimensions (`answer_key_and_domain`, `incorrect_choice_uniqueness`, `explanation_accuracy`, `semantic_overlap_and_difficulty`, `content_rights_and_attribution`) are pending and all review identities/dates/evidence refs are null.
- `needs_revision`: at least one dimension = `needs_revision`, with a syntactically valid quality reviewer identifier and valid review date. These are **unverified self-reports**, not authenticated persons; the pack remains unchanged. Other dimensions may be pending or claimed checked.
- `submitted_for_external_verification`: all five dimensions = `checked_claim`, two **different** syntax-valid quality/rights reviewer identifiers, review date and opaque local rights evidence reference; this is a *claim needing external verification*, **never approval**. Mere distinct strings, dates or evidence handles do not establish independent actual reviewers, expertise or legal permission.
- Any missing/duplicate/reordered 80 rows, unknown fields, forged approval flag, changed hash, invalid state/date, self-review identity collision or missing evidence handle is `blocked`.
- The preflight emits only allowlisted error codes and item counts, **not** reviewer IDs, source text, answers, claims or rights evidence contents.
- Every successful result says `notes_structurally_valid_not_approved`; flags for `independent_human_review_verified`, `rights_independently_verified`, `exam_content_approved`, `publication_authorized` and `database_import_allowed` remain false. A 100% self-reported checklist is not approval. No official-coverage percentage or readiness inference is added.

## Remaining independent human workflow and release gates

1. Humans actually inspect every content item / IPA past question and its answer/explanation/rights, review semantic duplicates and verify permitted use. P0 six changed items first; P1 official 35; P2 other Core/supplement 39. IPA past-question source attribution is distinct from the 2026 AP syllabus.
2. Correct any errors in a **new** Draft revision/slug, regenerate all affected hashes and review evidence, and re-review changed items. Preserve 2025 IPA original fidelity, and the distinction between source checks and independently written explanations.
3. Separately authenticate reviewers and independently validate rights records; CI cannot establish truth of human signoff. This service does not write approval metadata or convert notes to `exam_simulation_review`.
4. After separate owner-accepted substantive review, use the existing L5/QuestionPack publication preflight on an exact imported Draft with signed evidence and current live DB fingerprint. Manual admin import/publish, production deploy and physical device acceptance are **separate authorized actions**.

## Verification / non-impact

- Focused PHPUnit tests: 80 distinct bound items, zero initial signoffs, six P0, closed publication, empty notes acceptance without authorization, stale total/item hash, missing/swapped rows, forged approvals, partial revisions and claimed external verification, duplicate reviewer and malformed dimensions, local operator CLI privacy.
- CI also executes existing `ApExamCandidateQualityReviewTest`, `ApExamCandidateRevisionPreviewTest`, `ApExamCandidateBundleAuditTest` and V6.23 regression on disposable SQLite.
- Never changes `resources/question_packs/` or `resources/learning_review/`, Question Pack DB status, content, grading rules, Study UI, official syllabus registry, user Plan/Task/Evidence or entitlement. No migrations, job, HTTP, external provider I/O, production branch/Render/Aiven/iOS deploy, or draft release PR #513.
- Internal operator support only, so no public-roadmap change; CI success does not prove the real human review, publication, deployment or device usability.

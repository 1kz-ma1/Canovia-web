# Learning V6.26 — AP科目A 80問 v0.5独立Draftの安全な生成

2026-10-15 JST · Learning lane · development only · unpublished and unapproved

## Purpose

After V6.24 notes and V6.25 digest-only revision comparison, prepare a new versioned JSON **without** modifying the frozen v0.3/v0.4 bundled source or any live DB. The current human-reviewed corrections are **not present**: the implementation handles hypothetical/manual editor proposals and deliberately does not claim authentic independent signoff.

Input files are V6.24 exact 80-row `needs_revision` reviewer-notes and V6.25 exact-content revision proposals. A V6.25 passing status is a structural/content identity check, NOT a human or legal approval. V6.26 always keeps all real review and publishing gates pending.

## Operator steps

After actual reviewers have prepared their local files:

```sh
umask 077
# Hash/redaction preflight without writing:
APP_ENV=testing php artisan study:ap80-prepare-new-draft \
  --notes=/tmp/ap80-review-notes.json \
  --proposal=/tmp/ap80-unapproved-proposal.json

# Explicitly save a new private local preview (never resources/):
APP_ENV=testing php artisan study:ap80-prepare-new-draft \
  --notes=/tmp/ap80-review-notes.json \
  --proposal=/tmp/ap80-unapproved-proposal.json --write
```

Only `local` or `testing` is supported. JSON files are individually bounded to 1 MiB. The output JSON file is under `storage/app/private/learning-ap80-draft-previews/`. It is exclusively created (`fopen xb`, process umask `0077`) using a deterministic slug+short-content-hash filename; reruns cannot overwrite an existing output. Do **not** place private text in public/, Git resources/ or an app storage public link. The command logs only the new file name and an allowlisted status/count/hash summary, never actual problem text, answer keys, reviewer names, local input paths or rights documents.

Neither the service nor the CLI calls an external provider, imports into DB, edits the Git checkout, touches an existing Pack, certifies human work or automatically publishes. The operator must separately review/edit the generated candidate content before deliberately adding a versioned source under a future code review.

## Candidate contract

- `schema_version = "1.0"` (compatible with the current QuestionPackImportService) and **new, non-colliding** `slug=ap-a-2026-cbt-80-candidate-v3`, `version=0.5.0-review-required`.
- `exam_code=AP`, `subject=科目A`, `downloadable=false`, `metadata.review_state=pending_human_content_and_rights_review`, `metadata.explanation_review_state=pending_human_subject_review`, `rights_and_subject_review_completed=false`, **no** `exam_simulation_review` or expert-approved hash.
- Exactly 80 active Questions in their previous order, including the 35 IPA original problems byte-for-byte unchanged. Only `prompt` and/or `explanation` of requested Canovia items can differ. `response_schema`, `grading_rule`, source_type/reference, learning_metadata and origin, difficulty/order and question IDs are immutable.
- Every changed question's canonical item SHA and the hypothetical entire Pack SHA must match V6.25's revalidated comparison. Source authorship and revision evidence are not inferred from changes.
- Metadata is rebuilt via **allowlist** of attribution / exam routing basics. Old v0.4 proposal/technical-check/rights evidence references, checklists, submitted reviewer claims, signoffs, official syllabus coverage and approval metadata are **not** transferred. `review_evidence_state=all_items_pending_new_version_review` for the entire new version, not just edited items, and `prior_revision_evidence_transferred=false` / `prior_review_signoffs_transferred=false`.
- The preview is a separate local file only; it is **not** a Bundled Question Pack in the repository, not installed in a database and not visible to Study users.

The public candidate publication preflight must continue to block due to `review_state` and `explanation_review_state` even if explicitly imported to a **disposable testing SQLite** DB. The real production operator is not authorized to import or publish by this tool.

## Acceptance / next manual boundary

Tests validate immutable source byte hash, strict v0.5 slug/version and refusal to collide, all 80 identities/order and 35 official unchanged, one corrected Canovia explanation, exact SHA-256 compared with V6.25, absence of inherited review/evidence credentials, dry-run behavior, explicit non-overwrite private file save, malformed inputs, no real DB writes, and an **explicit disposable test import** that remains Draft/non-publishable.

No real content correction or actual subject-expert approval is represented by synthetic tests. New documentation and GitHub CI success do not prove real rights, professional review, deploy, learner app visibility or exam-equivalent quality.

Next (outside this scope): independent content+rights review of the *new v0.5* exact question fingerprints, rerun all source/technical evidence for changed items, manual owner-approved versioned resource PR, authorized Admin import, preflight on exact DB SHA, separate publish/release approval, and real Web/iPhone tests. `production`/Render/Aiven/iOS/PR #513 remain unchanged. No public roadmap update is needed for this internal tool.

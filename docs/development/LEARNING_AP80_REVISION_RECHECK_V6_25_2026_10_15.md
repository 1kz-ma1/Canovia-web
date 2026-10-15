# Learning V6.25 — AP科目A80問の修正案比較と再審査リセット

**2026-10-15 JST / Learning / development only / no database or publication changes**

## Source and safety boundaries

V6.24 provides a version- and SHA-bound 80-item reviewer-notes document. Its `needs_revision` flag is a self-reported observation, **not authenticated human review**. The original v0.3 candidate, existing v0.4 unapproved preview and v0.4 expert-review queue remain unchanged.

V6.25 adds **in-memory comparison only**. Only Canovia-authored (non-IPA) questions explicitly flagged `needs_revision` in structurally valid V6.24 notes can be proposed for modification. Only complete replacements of `prompt` and/or `explanation` are allowed; all other fields, including answer keys, choices, provenance, tags, difficulty, sort order and status, are immutable in this workflow. IPA source questions are rejected. All initial and future rights reviews remain separate human work.

## Operator workflow

1. Prepare the V6.24 80-row review-notes JSON with actual human findings, keeping its candidate and item fingerprints current. A `needs_revision` row must have a relevant dimension marked `needs_revision` and a reviewer/date claim.
2. Prepare a separate local revision proposal with exactly one edit per marked row, no extra rows, current question hash and a nonempty reason. Only `prompt` and `explanation` can change (UTF-8, trimmed, 1..12000 chars). No silent truncation, answer/choice edits or official IPA edits.
3. In an approved `local` or `testing` environment only:

```sh
umask 077
APP_ENV=testing php artisan study:ap80-revision-compare \
  --notes=/tmp/ap80-review-notes.json \
  --proposal=/tmp/ap80-unapproved-proposal.json
```

Each JSON file is bounded to 1 MiB. The CLI reads only bundled repository files and the supplied local notes/proposal, with no database, network or file write. Its output **never prints actual question/answer/explanation text, human reviewer IDs, local paths or the proposal reason**. Reviewers compare the real content in their protected local source files.

## JSON proposal skeleton — not a real completed human review

```json
{
  "schema": "canovia.learning.ap_a_80_revision_proposal.v1",
  "candidate_key": "ap/ap-a-2026-cbt-80-candidate-v2",
  "candidate_version": "0.4.0-review-required",
  "base_content_sha256": "REPLACE_WITH_EXACT_V624_64_HEX_PACK_HASH",
  "review_stage": "unapproved_revision_comparison",
  "changes": [{
    "key": "sec-dkim-023",
    "base_question_sha256": "REPLACE_WITH_EXACT_V624_ITEM_HASH",
    "reason": "Human-identified wording issue; substantive external check remains pending.",
    "fields": {"explanation": "COMPLETE_LOCAL_CORRECTED_EXPLANATION"}
  }],
  "publication_authorized": false
}
```

The preflight uses the existing QuestionPack importer to normalize each original/proposed question and the existing AdaptiveExamPackReadinessService SHA-256 algorithm for both item and hypothetical 80-item candidate fingerprints. It detects stale full-item digest, wrong key/order, duplicates, unknown/extra edits, forged approval, no-op, overlong edits, protected source edits or silent normalization changes.

## Output and required human work

A structurally passing proposal yields `draft_comparison_rereview_required`, per-item changed field names, before/after field SHA-256 and character counts, before/after Question SHA-256, and a **hypothetical** full-pack SHA-256. This is a digest-only comparison, **not** a generated/new Draft or actual human-reviewed Pack.

All five checks must be repeated for every changed item: answer/domain, unique distractors, explanation, semantic overlap/difficulty, and rights/attribution. Every changed row states `review_reset_to=pending_full_independent_rereview` and `old_review_transferred=false`, even if prior notes claimed `submitted_for_external_verification`. Review records of unchanged questions are not approved by this comparison either.

All results (success or fail) keep `new_draft_written=false`, `existing_candidate_changed=false`, `human_rereview_verified=false`, `rights_independently_verified=false`, `exam_content_approved=false`, `publication_authorized=false`, `database_import_allowed=false`, `read_only=true`. Blocked results expose only allowlisted error codes and empty diffs.

**Future gates (not implemented):** human review of actual corrected wording and source rights; separate owner-reviewed *new* Draft slug/version and regenerated provenance/rights artifacts; real reviewer identity confirmation and genuine subject-matter signoff; admin import, exact-DB fingerprint readiness, manual publication, production deploy and physical device E2E. No official IPA 2026 syllabus map or complete-exam-quality claim.

## Verification and production impact

- New synthetic PHPUnit tests cover current content fingerprints, no original file changes, full five-dimension rereview reset, rejection of IPA corrections and answer/choice/metadata edits, no-op and oversized edits, stale pack/item/review notes, duplicate/missing edits, forged approval, redacted CLI and malformed JSON.
- Run V6.24 review notes, v0.4 review queue, revision preview and bundled candidate Draft publication regression tests in the dedicated PHP 8.4 SQLite-only CI.
- No runtime Study UI, Question Pack DB, Plan/Task/Evidence, syllabus mapping, jobs, HTTP routes, source resources, entitlement, external calls, Render/Aiven/production, iOS release or PR #513 change. Internal reviewer workflow only; public roadmap status unchanged.

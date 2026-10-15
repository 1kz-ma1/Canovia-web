# Learning V6.22 — Human syllabus submission structural preflight (NO automatic approval)

**2026-10-15 JST / Learning lane / development-only / source-only registry remains EMPTY**

## Why this slice

V6.20 pinned the IPA AP 2026 Level 3 syllabus **Ver.7.2 (2026-01-08)** with a source-only registry; V6.21 added a **read-only** pack inventory containing the current content SHA-256 of eligible Question Bank items. Neither created independently reviewed official scope nodes.

V6.22 adds a local disposable SQLite-only **offline structural preflight** for a separately prepared human review proposal. It checks source/version, bounded scope/links, page anchors, two distinct reviewer identifiers per scope/link, content and rights review decisions, and live Question SHA-256 equality. It never transcribes official material, checks copyright law, authenticates human reviewers, or applies the proposal.

The intentionally limited result `structurally_complete_manual_evidence_review_required` is **not** an approval result. Every report, including a structurally complete one, always returns `independent_human_review_verified=false`, `rights_independently_verified=false`, `eligible_for_registry_import=false`, `production_authorized=false`, and null official coverage percentage/denominator.

## Local-only command

Use an **isolated synthetic SQLite testing database**, with one already published/explicitly AP-routed 科目A Pack:

```shell
APP_ENV=testing DB_CONNECTION=sqlite php artisan study:syllabus-review-packet --pack=EXACT_PACK_SLUG > /tmp/review-packet.json
APP_ENV=testing DB_CONNECTION=sqlite php artisan study:syllabus-review-preflight --pack=EXACT_PACK_SLUG --review=/tmp/proposal.json
```

- The operator must independently draft `proposal.json` using the approved external human review process. The V6.21 inventory output is **not** a validated submission and must not be auto-marked reviewed.
- Production or non-SQLite CLI invocation fails before file or database access. A local file path is required, maximum 256 KiB. No HTTP endpoint, import, task mutation, external provider I/O or storage writes are introduced.
- CLI output contains only status, allowlisted error codes, counts and explicit non-approval fields; no question content, reviewer identifiers, citation text, user data or file path is echoed.
- Structural checks use a freshly built V6.21 packet, **not hashes submitted by an external actor**.

## Review submission JSON contract (synthetic placeholder; never a real reviewed item)

```json
{
  "schema": "canovia.learning.syllabus_review_submission.v1",
  "official_source": {
    "exam_code": "AP",
    "subject": "科目A",
    "source_version": "7.2",
    "published_date": "2026-01-08",
    "source_index_url": "https://www.ipa.go.jp/shiken/syllabus/gaiyou.html",
    "source_document_url": "https://www.ipa.go.jp/shiken/syllabus/omgdg50000005kq5-att/syllabus_ap_ver7_2.pdf"
  },
  "pack": { "slug": "REPLACE_WITH_PUBLISHED_PACK_SLUG", "version": "REPLACE_WITH_VERSION" },
  "review_stage": "human_review_submission",
  "scope_nodes": [{
    "code": "SYNTHETIC-NODE-1",
    "label": "Synthetic example only - not a real IPA entry",
    "source_version": "7.2",
    "source_page": 1,
    "source_locator": "Synthetic page/section pointer only",
    "transcription_review": {
      "reviewer_id": "reviewer-one",
      "reviewed_at": "2026-10-15",
      "decision": "confirmed"
    },
    "independent_review": {
      "reviewer_id": "reviewer-two",
      "reviewed_at": "2026-10-15",
      "decision": "confirmed"
    }
  }],
  "question_links": [{
    "scope_code": "SYNTHETIC-NODE-1",
    "source_version": "7.2",
    "pack_slug": "REPLACE_WITH_PUBLISHED_PACK_SLUG",
    "pack_version": "REPLACE_WITH_VERSION",
    "question_external_key": "REPLACE_WITH_EXACT_QUESTION_KEY",
    "question_snapshot_sha256": "REPLACE_WITH_64_CHARACTER_V621_HASH",
    "content_review": {
      "reviewer_id": "reviewer-two",
      "reviewed_at": "2026-10-15",
      "decision": "confirmed"
    },
    "rights_review": {
      "reviewer_id": "rights-reviewer-three",
      "reviewed_at": "2026-10-15",
      "decision": "permitted_for_intended_use"
    },
    "rights_evidence_ref": "SYNTHETIC-LOCAL-EVIDENCE-001"
  }]
}
```

Both sets of reviewer IDs must be distinct for their respective decisions and dates must be genuine calendar dates between publication and current date. Only an **opaque local rights-evidence reference** is carried; actual evidence and permissions must be verified outside GitHub. These fields are **self-reported**, never cryptographic signatures or independent attestation.

Every link must identify a live, supported, published AP科目A Question Pack/Question and match its V6.20 snapshot SHA-256 exactly. Each scope requires a PDF page in 1..500 and explicit locator. Reject forged source versions/pack IDs, extra completion claims, stale hash, duplicates, skipped reviews, missing rights evidence and oversized submissions without changing any registry data.

## Subsequent owner/reviewer gates (not part of V6.22)

1. Human transcription of **selected actual IPA PDF sections** with source anchors and evidence of document version, without inferring completeness from Question Bank tags.
2. Independently verify that the human reviewer identities are genuine and independent; review meaning, answer keys, source usage and reuse rights. The offline command checks **only the syntax of claimed identities/decisions**, not whether review actually occurred.
3. Review exact Question content and repository diff in a **separate proposed data/config PR** only after those signoffs, with explicit authorization and evidence provenance. The runtime config stays `catalog_state=source_only`, `scope_nodes=[]`, `question_links=[]` in this slice.
4. Any future V6.20 `reviewed_partial` PR remains limited and cannot assert official full coverage, % or denominator. Observe release/device gating separately.

## Verification and risk

- V6.22 feature tests: synthetic structurally complete submission still not approved, stale/missing Question, unreviewed/duplicate/conflicting reviewer, forged source/pack/version and CLI no-leak, no-mutation gates.
- Re-run V6.21, V6.20 and V6.19 PHPUnit regressions on disposable SQLite, exact PR HEAD. Full diff and required CI review prior to development `main` integration.
- No production, Render, Aiven, migrated DB, iOS binary, background worker, AI call, exam-question publication, public roadmap release claim, or release PR #513 alteration.

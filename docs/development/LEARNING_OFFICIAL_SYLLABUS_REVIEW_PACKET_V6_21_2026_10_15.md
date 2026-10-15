# Learning V6.21 — AP official syllabus independent-review preparation packet

**2026-10-15 JST · Learning lane · development-only · not a syllabus import**

## Decision and relationship to V6.20

[V6.20](LEARNING_OFFICIAL_SYLLABUS_BRIDGE_P0_V6_20_2026_10_15.md) pins the IPA AP 2026 syllabus **Ver.7.2** (2026-01-08) but deliberately keeps `scope_nodes=[]` and `question_links=[]`, and refuses to report official coverage. That remains unchanged.

V6.21 provides the **next human-work intake artifact only**: an operator-side, read-only inventory of active supported questions from exactly one already-published, explicitly AP-routed **科目A** Question Pack. It includes immutable content snapshot SHA-256 values and **blank** reviewer fields. It does not transcribe the IPA PDF or assign scope codes. Human content/rights review and specific page-anchored mappings are still **not done**.

## Operator command

On a disposable **local/testing** database with an explicitly permitted synthetic or reviewed published pack:

```sh
APP_ENV=testing php artisan study:syllabus-review-packet --pack=<exact-published-ap-pack-slug> > syllabus-review-packet.json
```

The JSON has schema `canovia.learning.syllabus_review_packet.v1`. Each `question_candidates[]` record contains only source Pack slug/version, external question key, V6.20-compatible content SHA-256, and **null** proposed scope code/source page plus **pending** content/rights review. This is **not** in the `config/study_official_syllabus.php` import shape. Do not paste candidates there as reviewed mappings.

- Requires V6.20 pinned **source-only** registry, published AP 科目A Pack, explicit AP routing and existing publication preflight. Unknown/mismatched source, unsupported/no active supported questions, wrong exam, draft Pack and oversized inventories fail closed.
- No question prompt, answer, grading rule, explanation, personal Plan/Task/Evidence, account identifier or provider token is exported.
- No HTTPS route, queue, background processing, database mutation, auto-publish, grading change or release-level change.
- The command intentionally rejects production environment. Do not use exported snapshots as proof of real official syllabus coverage or proof of copyright permission.
- V6.20 hashes are content snapshot fingerprints, **not** human approvals. After any source Question edit regenerate the packet; reject older hashes.

## Required next **independent human process** (not performed by V6.21)

1. Independently open the pinned IPA official Ver.7.2 PDF. A person records each *selected* official scope item with stable unique code, **actual page number**, label, precise source/version and transcription evidence; do not infer coverage from existing Canovia tags.
2. A second human validates scope transcription/meaning against the document. Separately record the rights/usage determination for the proposed item/question text and sources. Track reviewer identities/dates and content/rights unresolved issues. **Two distinct people must be involved in substantive review before any data PR claims approval.** Metadata strings or green CI alone cannot establish independence or expertise.
3. For each proposed Question link, check the actual published source Question, source attribution, rights, explanation and semantics; attach exact Pack slug/version/external key and current V6.20 question snapshot SHA-256. If the Question changes, repeat review.
4. Submit a separately scoped, reviewed data/config PR. Review actual official page anchors, semantic correctness, provenance and rights evidence **outside** the repo if sensitive. Use only the bounded `reviewed_partial` state after verified approval. Do not auto-transcribe an official outline, publish unreviewed questions or claim complete coverage.
5. Keep `coverage_percent=null`, `coverage_denominator=null` and `index_complete=false` regardless of partial mappings. Check previous V6.20 owner/exam/permission gates before integration.

This operator packet **does not itself enforce independent human identity, certify rights, or authorize a mapping PR**. It merely reduces avoidable transcription errors in source-Question identity/hash.

## Verification and release

- New feature tests: source-only/empty reviewed mapping, no question text leakage, exact snapshot hash and staleness after grading edit, wrong Pack state/exam/routing/source refusal, and command stdout/read-only behavior.
- Run existing V6.20 official-syllabus and V6.19 coverage regressions in disposable SQLite.
- PR CI and full-diff review required at exact current head SHA before integration to development `main`.
- No production, Render, Aiven, iOS runtime, public roadmap publication or release PR #513 change. Actual human transcription/review, real-device acceptance and production deployment remain unknown / separate gates.

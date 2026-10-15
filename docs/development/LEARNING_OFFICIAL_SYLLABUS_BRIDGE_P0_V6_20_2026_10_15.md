# Learning V6.20 — Official AP 2026 Ver.7.2 syllabus provenance and reviewed mapping bridge

**2026-10-15 JST · Learning lane · development-only P0**

## Official source verified, coverage not verified

IPA's [current syllabus index](https://www.ipa.go.jp/shiken/syllabus/gaiyou.html), last updated July 2026, explicitly lists **応用情報技術者試験（レベル3）シラバス Ver.7.2**, published **2026-01-08**; it notes that A/B-style subject terminology was refreshed July 6. Source URL: `https://www.ipa.go.jp/shiken/syllabus/omgdg50000005kq5-att/syllabus_ap_ver7_2.pdf`. The distinct [2027 proposed new exam-system syllabus](https://www.ipa.go.jp/shiken/syllabus/henkou/2026/20260630.html) is *not* promoted into the current 2026 AP registry.

The verified **existence/version** of that official source does **not** imply a complete transcribed topical index, exam subtopic weightings, approved Question Bank mapping, independent educational-content review or content-reuse rights. All these latter claims are deliberately blocked.

## V6.20 registry contract

`config/study_official_syllabus.php` contains one `ap_subject_a_2026` manifest with exactly pinned official source identity and explicit `catalog_state=source_only`, empty `scope_nodes` and empty `question_links`. This is a deliberately **safe and meaningful incomplete state**, not a missing result to fill with guesses.

The read-only `StudyOfficialSyllabusBridgeService` consumes this manifest only after V6.19's actor/editor/Plan AP Bank sample eligibility gate, and is shown on **Study Work + Analysis**. It requires exact official source identity before acknowledging the document, prevents duplicate scope node identifiers, and never computes or displays any **official coverage percentage** or **official completeness denominator** even when partial mapping records exist.

A future, separately reviewed partial mapping could add at most 120 tagged scope nodes, each with version, stable code, label, PDF page anchor, reviewer identifier, reviewed date and `review_state=reviewed`; max 240 explicit item links, each with exact scope code, source version, Question Pack slug/version, source Question external key, reviewer/date and `review_state=reviewed`. For a mapping to count as a **registered reviewed link**, runtime must additionally find a currently published, explicitly AP-routed AP科目A Pack passing existing preflight, an active supported Question, and an exact SHA-256 of its **current prompt, response schema, grading rule, learning metadata and explanation**. Stale/renamed/removed/unpublished/changed source Question links fail closed; duplicate entries rejected. Raw review fields are themselves unverified records: **not independent third-party approval or proof of permission**.

`catalog_state=reviewed_partial` is a narrow internal registry marker, never full official mapping coverage. `source_only` is the only initially committed actual state. Any real official scope-item transcription, partial mapping, source rights or expert signoff requires an independent evidence-reviewed PR, not AI-generated assertion or inferred topic equivalence.

## UI and exclusion boundaries

- Source Ver.7.2/2026 publication and 2026 terminology-only label update, with links to IPA list and original document.
- Source-only state: 0 vetted scope nodes, 0 reviewed links, no fabricated topic names or “0%” or “100%” official coverage.
- Optional manually reviewed partial state: rows with explicit version/page and registered reviewed-link count; prominent warning registered review records are not independently attested.
- Invalid source identity -> no report. AP Subject B only and other certifications remain hidden, as in V6.19. Foreign users/non-editors cannot see actor-bound Study surfaces.
- Existing V6.19 published Bank-tag sample remains separate, never elevated into the official taxonomy. V6.18 Learning priorities and owner-consented Runs remain unchanged. No schema/DB migration, AI provider, publishing, downloads or paid service. No Task progress, Evidence, Next Action or background scheduling mutations.

## Acceptance

1. New PHPUnit tests: source-only identity and null official percentage; synthetic review-record data can match a current Question snapshot exactly but never create coverage%; changed question prompt invalidates link; wrong version, duplicate code, unreviewed link, unpublished Pack fail closed; unrelated owner/exam and noneditor isolated.
2. Existing V6.11–V6.19 Learning suite remains green.
3. Real disposable Chromium: synthetic Guest→Free owner/AP Study Plan/Task sees separate official Ver.7.2 block with source-only gate and no made-up topic rows or official percentage. Previous V6.19 Bank no-record→partial and V6.18→V6.17 explicit graded review loop still pass. The legacy 26-hour shift remains a local SQLite fixture, not a real waiting/retention claim.
4. Exact PR-head required jobs, full diff and latest `main` mergeability, no unresolved review threads before development-only squash.

## Release and next dependency

The next task is **independent human transcription + two-person content/rights review and source anchors**, followed by an explicit PR authorized to add real nodes and links. No content from the PDF is transcribed automatically by V6.20, no official topic distribution assumed and no readiness score.

`production`, Render, Aiven and release PR #513 remain untouched / Draft **NO-GO**.

# Learning V6.23 — Official syllabus independent human review workbook

**2026-10-15 JST · Learning lane · development-only · read-only operator support**

## Motivation / authority

V6.20 (#713) pinned the IPA 2026 AP (Level 3) syllabus Ver.7.2 and kept official scope and question mappings empty.
V6.21 (#718) added eligible, already-published AP subject A Question identity / content SHA-256 inventory.
V6.22 (#721) added an **offline structural preflight** of *separately human-prepared* submissions. Its "structurally complete" result is not actual independent reviewer identity, permission, correctness or import approval.

V6.23 closes the **handoff usability gap**: an operator can create a **blank human review worksheet** from the exact current eligible Pack. It does NOT extract or copy official syllabus text, infer any Question-to-scope mapping, certify reviewers or rights, mark records reviewed, or convert a worksheet to the production registry.

## Export contract

The local/testing SQLite-only artisan command is:

```shell
umask 077
APP_ENV=testing DB_CONNECTION=sqlite php artisan study:syllabus-review-workbook --pack=EXACT_PUBLISHED_AP_A_PACK_SLUG > /tmp/ap-review-workbook.json
```

Use a **disposable and approved** local SQLite fixture. Merely setting APP_ENV=testing does not prove a DB path is a safe isolated copy; verify the database identity before running the command.

The exported JSON schema is `canovia.learning.syllabus_review_workbook.v1`. It includes:

- The verified V6.21 official source identity and exact published AP科目A Pack identity.
- A **separate** `submission_draft` matching V6.22's top-level fields but with `review_stage=draft_pending_review`, `scope_nodes=[]`, `question_links=[]`.
- `question_candidate_rows` with Question external key, Pack slug/version and the V6.20 **current content SHA-256**, with **null** proposed scope/page/locator/reviewer identities/dates/rights reference and `pending` review decisions.
- An allowlisted handoff instruction sequence, excluded unsupported question count, no-question-content flag, no percentage/denominator and multiple explicit non-approval flags.
- No Question prompt, answer, explanation, response schema, personal account/Plan/Task/Evidence, token, extracted IPA text, or private reviewer evidence.

This workbook is **not itself an accepted V6.22 submission**. The structural preflight rejects the untouched `submission_draft`. It does not automatically copy rows into the scope map or replace the official registry.

## Manual reviewer workflow

1. Verify actual original IPA syllabus Ver.7.2 and record *selected* real official scope items by stable code, label, PDF page and source locator in a separately prepared submission. The workbook never supplies official scope labels or "official completeness" denominators.
2. Have a distinct person independently check the source transcription and meaning. A separate actual person must verify the proposed Question content / rights decision and its evidence. Two syntactically different reviewer IDs **do not establish real review**.
3. Cross-check each Question against its actual current source Pack and the extracted hash; select only substantiated links, without guessing scope from learning tags. Ensure evidence/ref checks and legal reuse determinations are performed by humans outside GitHub if sensitive.
4. Only after actual human work, populate a **separate** V6.22 submission and explicitly change `review_stage` to `human_review_submission`. Run `study:syllabus-review-preflight` against the current disposable database; a stale Question hash requires renewed human review.
5. An error-free preflight remains `structurally_complete_manual_evidence_review_required`, never approval. A future **separate owner-authorized data PR** is required to register partial mappings, with an actual human review of source anchors, accuracy, identity and rights.

## Boundaries and regression acceptance

- Workbooks only from V6.21 source-only, published, AP-routed, preflight-eligible and bounded Question Packs.
- No source text, automatic transcriptions, official complete taxonomy, fake expert signoffs, default reviewed decisions, auto-publish or registry import.
- New focused PHPUnit covers blank-review defaults, no source content leak, V6.22 rejection of untouched draft, regenerated stale-hash behavior, unpublished/wrong-version rejection, CLI JSON and SQLite guard.
- Run V6.23 and existing V6.22/V6.21/V6.20/V6.19 regression tests at exact PR HEAD; assess CI and full diff before a development-only squash merge.
- No DB migration, HTTP route, external AI, queue/background process, user-data mutation, changes to Canovia Study UI or release/entitlements. `production`, Render/Aiven, iOS device smoke and Draft release PR #513 remain out of scope.
- Internal reviewer convenience changes do not affect user-facing public product status; no public-roadmap edit required.

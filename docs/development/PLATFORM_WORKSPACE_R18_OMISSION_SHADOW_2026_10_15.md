# Platform R18 — Plan Action Draft one-occurrence omission shadow trial

> Status: EXPERIMENT DESIGN / NOT A DEDUPLICATION DECISION (2026-10-15 JST).
> Source baseline: `main` `538bd9d65e8327161580270e5f06bff0eca66033`.
> Owner: Platform CI; named domain acceptance owner: Development.
> Prior evidence: PR #653, #656, #657 / `PLATFORM_CI_AND_BRANCH_TRIAGE_2026_10_14.md`.

## Decision boundary and exact scope

The existing normal Workspace Resume uses **72** `php artisan test` commands with **160** listed test-file appearances, **118** unique paths, **21** repeated paths and **42** additional appearances. R17 observed distinct co-invoked test sets for all 21 repeated files; this is *not* evidence that any repeated invocation may be removed.

R18 measures the **local effect of excluding exactly one repeated file from one historical command** in a disposable runner. Pin the full original `V58.74 Owner-approved Sequential Action Draft` command to these four files in the exact order:

1. `tests/Feature/PlanActionDraftV5874Test.php`
2. `tests/Feature/PlanActionDraftV5873Test.php`
3. `tests/Feature/PlanActionDraftV5872Test.php`
4. `tests/Feature/PlanActionDraftV5871Test.php` — the **shadow-only omission candidate**.

The normal CI continues to include all four, with every other run unchanged. The V5871 class is independently present in four other named normal CI commands. This does **not** establish that those four repetitions replace V58.74's regression intent or state isolation.

## Test protocol

In the existing path-scoped, read-only GitHub Actions disposable workflow, execute **three separate PHP processes** against fresh local SQLite `:memory:` and array session/cache, after the same PHP 8.4, Composer and npm build prerequisites as Workspace Resume:

- A: original four-file V58.74 group;
- B: only V5871 omitted; the other three files retain exact order;
- A′: original four-file group restored, as a bounded within-run consistency control.

Read all JUnit testcase identities. Both A and A′ must pass with the **same entire testcase inventory and observed class order**. B must pass, contain exactly the original non-V5871 testcase identities and classes, and contain **none** of V5871's identities. Any missing JUnit, failure, skip, duplicate, unexpected class, changed retained testcase, missing omitted-class evidence, or missing exact named CI command is **BLOCK** (nonzero exit code). If B fails, stop and preserve no raw error logs or XML in public output. Only fixture/class/method identifiers under source control are compared; individual method titles are exposed only for the bounded omitted fixture.

Bounded output: A/B/A′ total testcase counts, exact omitted testcase identifiers, original group class order, retained-case parity, the scoped named CI step and observed execution durations per **whole group**, with no per-test savings claims. Report explicitly:
`cross_context_assertion_equivalence_proven=false`, `state_boundary_equivalence_proven=false`, `safe_to_remove=false`, `permission_to_remove_repeated_invocations=false`, and `production_release_authorized=false`.

## Interpretation and next review

A passing omission experiment **must show that four V5871 testcases are absent in B** while the remaining cases keep their identities; it does not show whether V5871 contributes required assertions/side effects when co-run with V5874/73/72, whether a separate command covers an equivalent workflow-specific condition, or how full normal CI would behave if modified. No production cost, CI speedup or cross-tenant/DB/session equivalence conclusion is possible from this local experiment.

**Next distinct gate**: Development-owner inspection of the exact V58.74 regression acceptance intent, code/assertion and state boundary, and comparison with the other four V5871 contexts. Require tailored negative controls and a separately approved `main` change **if ever** considering removal. Until then the 42 repeats remain unchanged.

## Verification, deployment and impact

- New synthetic tests: passing A/B/A′; contaminated B; missing target in A; different restored A′; missing class; failing or skipped JUnit; invalid workflows and outside-GitHub fail-closed.
- GitHub Actions: existing `Platform Workspace Scenario Equivalence (Disposable)` plus existing Platform Preflight, Workspace Resume, Development Prelaunch and Migration Recovery; check current PR HEAD and full diff before `main` integration.
- No normal Workspace Resume changes, new required CI, branch rules, source app, API, schema/migration, environment secrets, production, Render, Aiven, iOS, billing or release operations. It is an isolated **CI evidence experiment**, not a production deployment.


## Real isolated observation — PR #660 (initial tested head)

**OBSERVED on `9a1127600754753367cd760342a80140e5cba4ac`, 2026-10-14 UTC:** [Disposable Actions #38588375574](https://github.com/1kz-ma1/Canovia-web/actions/runs/38588375574) succeeded in the original scenario probe, R18 A/B/A′ step, existing 12-run repeatability step, independent 12-run runner and cross-runner JUnit fingerprint comparison. The new offline R18 contract suite passed **13/13**. The initial earlier PR head failed one synthetic test due to modifying a PHP-lint appearance instead of an Artisan command; the test fixture was corrected to mutate the actual V58.73 Artisan command before this successful run.

| Observation | A original | B only V5871 omitted | A′ restored |
| --- | ---: | ---: | ---: |
| Entire group JUnit cases | 27 | 23 | 27 |
| Whole group duration in this runner | 1.742 s | 1.577 s | 1.741 s |
| Executed selected V5871 cases | 4 | 0 | 4 |

The four source-controlled V5871 cases **absent only in B** are:
- `test_manual_evidence_creates_inert_proposal_and_accept_once`
- `test_legacy_work_log_is_scoped_to_plan_and_snapshot_is_kept`
- `test_rejection_and_cross_plan_draft_do_not_mutate_tasks`
- `test_collaborative_editor_viewer_and_stranger_are_forbidden`

All 23 non-V5871 JUnit testcase identities matched, and A/A′ matched in inventory and observed first-appearance class order. These are **local test-identity omissions**, not demonstrated removal of four unique assertions from the overall Workspace Resume, proof of no cross-context side effects, or savings of ~0.165 seconds per production CI run. Authorization/Plan isolation remains particularly sensitive; **no removal is approved**. This evidence is from the initial tested PR head; the documentation HEAD that records it must independently complete relevant PR checks before development `main` integration.

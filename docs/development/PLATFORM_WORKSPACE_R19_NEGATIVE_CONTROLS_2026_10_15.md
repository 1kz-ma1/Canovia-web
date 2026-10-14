# Platform R19 — V5871 four-method negative-control cross-context probe

> Status: PENDING disposable-CI acceptance (2026-10-15 JST)
> Baseline: `main` `cfa3ee04a2b7306557088fe0ed83e5d44016430a`
> Lane: Platform CI. Domain acceptance owner: Development.
> Related: R17 PR #657, R18 PR #660 and `PLATFORM_WORKSPACE_R18_OMISSION_SHADOW_2026_10_15.md`.

## 1. Question, evidence and non-goals

R18 showed that omitting `PlanActionDraftV5871Test.php` only from the `V58.74 Owner-approved Sequential Action Draft` group lowers JUnit testcase inventory from **27 to 23** and restoration returns it to **27**, with four V5871 tests absent in that group. It did not show whether the same four methods can detect a regression in the other four historical contexts, much less semantic equivalence across those contexts.

R19 checks *detection coverage*, not substantive behavioral equivalence. The four V5871 methods inspect:
1. Manual evidence proposal remains inert until owner approval, once-only Task creation and immutable accepted draft.
2. Legacy Work Log cannot cross Plan, evidence snapshot remains after source deletion, previous Task progress unchanged.
3. Cross-Plan accept is rejected, dismissal prevents subsequent accept, no stray Task is created.
4. Collaborator editor/viewer/stranger actions are forbidden and proposal remains unchanged.

These are source-observed assertions, not independently proven domain requirements. The method fixture uses `RefreshDatabase`; its presence does not prove fixture or assertion equivalence when invoked with different neighboring Feature classes.

## 2. Exact scope: five existing, named regression contexts

All five fixed groups retain `PlanActionDraftV5871Test.php` in **normal** Workspace Resume:

| Step | Co-invoked test files (V5871 omitted from this column) |
| --- | --- |
| V58.75 Draft Quality and Staleness Guard | V5875, V5874, V5873, V5872 |
| V58.74 Owner-approved Sequential Action Draft | V5874, V5873, V5872 |
| V58.73 Typed Evidence and Domain-aware Plan Draft | V5873, V5872 |
| V58.72 Multi-evidence Draft and Revision Diff | V5872 |
| V58.71 Action-grounded Draft and Owner Approval | PlanActionFirstCreationV5866 |

The exact step labels, file membership/order and count are validated from the real Workspace Resume source. No normal invocation is removed, regrouped or reordered.

## 3. Fail-closed, disposable negative-control protocol

The **existing PR-scoped** `platform-workspace-scenario-equivalence.yml` runs `scripts/ci/platform_workspace_r19_negative_controls.py --run` only on its ephemeral Ubuntu/PHP 8.4/Composer/npm built checkout. It requires `GITHUB_ACTIONS=true` and the original R18 fixed manifest. No AWS, Render, Aiven, user data, persistent migrations, GitHub write token or production resources are used.

1. Execute all five historical groups separately (one fresh PHP process and SQLite `:memory:` context each); require a passing, complete JUnit with all expected classes and **exact four** V5871 methods.
2. Execute the V58.74 shadow-with-V5871-omitted group; require its full JUnit inventory to equal the target-free portion of original V58.74.
3. For each of the four methods, temporarily inject a literal `RuntimeException` at the start of **that method only** in the GitHub-hosted throwaway checkout. Run all five groups separately and require exactly that testcase to fail in JUnit, no other failures/skips/missing or duplicated testcase identities, and a nonzero process exit. Then run the V58.74 omission shadow and require all its original 23 retained cases to pass.
4. Restore the original PHP file bytes in a `finally` clause after each method; independently verify the original SHA-256. Also verify restoration at outer exit. Each JUnit output stays under a temporary directory, with no artifact upload or raw app logs.
5. Fail closed if any group drift, source/method declaration drift, surprise/missing failure, unexpectedly successful negative process, missing XML, unexpected class, altered other testcase, bad exit, or failed restoration occurs.

**Fixed bounds:** 6 baselines + 4 × (5 negative groups + 1 omitted shadow) = **30** PHP process invocations. The four methods each have 5 intentionally failing normal-group observations and 1 passing omission observation. Positive success is only `OBSERVED_FOUR_METHOD_NEGATIVE_CONTROLS`; it must permanently report `assertion_or_state_semantics_equivalence_proven=false`, `permission_to_remove_repeated_invocations=false`, `production_release_authorized=false`.

## 4. Decision boundary

If R19 passes, it establishes that the identical source-controlled four methods are *executed and failure-detecting* in all five historical contexts on this runner/SHA; the deliberately omitted group loses these detection signals. **It does not establish** that other co-invoked files substitute the four assertions, that mutation of actual application behavior is detected across all contexts, or that user/Plan/tenant and DB state isolation are equivalent under all group combinations. No test, assertion, security boundary or production check may be deleted based on R19.

A subsequent, separately reviewed phase could compare specific application-level mutation outcomes and owner-approved acceptance criteria in one named context. For this phase the only appropriate decision is **HOLD: retain all 42 repeated appearances**.

## 5. Verification and production impact

The static Python tests simulate missing/wrong/duplicate/skip/failure testcase JUnit, changed step source, absent/renamed methods, forbidden environment, proper cleanup under success and failure, and fail-closed default entry. CI runs them in existing path-scoped disposable and offline Platform Preflight workflows; normal Workspace Resume, migration/recovery, Development prelaunch must remain green at exact PR head before `main` merge. No new always-on CI, source application changes, production deployment/Render/Aiven/branch-rule/iOS/billing or release authorization.


## 6. First observed GitHub Actions evidence (PR #663)

**OBSERVED on initial exact HEAD `5fa4ee66409ee3bab59dc305c8476455d2d2d8ce` (2026-10-14 UTC):** [Disposable Workflow #38591787725](https://github.com/1kz-ma1/Canovia-web/actions/runs/38591787725) passed the R19 step, original/reordered/R18 checks, the independent replay runner and final fingerprint comparison.

The real runner's machine-readable report confirmed:
- **30** independent PHP processes completed (6 unchanged baselines, 20 injected expected-failure runs, 4 target-omitted runs).
- **4/4** selected method tripwires were tested; all **20/20** expected failures were detected in the five original named historical contexts, **without a second unexpected failing testcase**.
- All **4/4** V58.74 omission-shadow calls passed their retained testcases while the injected target method was not present.
- Original temporary PHP source restored with SHA-256 `aa72d90bf00fd4e10fa5322a984d48f3ff69665c92079ef0e6666d83061fc20d`.
- Offline synthetic contract tests: **14/14 passed**. The report explicitly retained `assertion_or_state_semantics_equivalence_proven=false`, `permission_to_remove_repeated_invocations=false`, `production_release_authorized=false`.

**Interpretation: HOLD.** Tripwires are intentionally injected into the **test methods**, not into the production implementation. Therefore this is a detection-presence observation, not mutation coverage of real app branches, no proof of absence of context interactions, and no permission to remove any of 42 repetitions. This initial-head evidence alone does not cover later docs/CI revisions; before merge, revalidate all applicable workflows at final HEAD. No environment deployment or device test was performed.

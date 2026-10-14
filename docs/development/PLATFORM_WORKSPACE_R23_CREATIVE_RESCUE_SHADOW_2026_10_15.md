# Platform R23 — Legacy Creative Development Rescue: two-context isolation and security mutation shadow

> Status: awaiting disposable-CI observation (2026-10-15 JST)
> Initial source branch base: `cd28f91b6cd2afc88d64f91a701b7817f623ad31`.
> Owner lanes: **Platform CI** experiment; **Development** owner approval of actual Creative Plan semantics remains unverified.
> Related: R17 PR #657, R22 decision register PR #674. This file **must not be used as permission to remove repeated checks**.

## 1. Selection — new repeated-file scope

Choose `tests/Feature/DevelopmentCreativePlanRescueV5863Test.php` as the **first source outside the previously studied V5871 Plan Draft group**. It is executed by exactly two named normal Workspace Resume regressions:

| Original named step | Original argv members (exact order) |
| --- | --- |
| V58.63 Legacy Creative Development Rescue | `DevelopmentCreativePlanRescueV5863Test`, `WorkspaceModeResolverV540Test` |
| V58.69 Team Task Dependencies | `DevelopmentTeamTaskDependencyV5869Test`, `DeveloperWorkspaceSurfacesV580Test`, `DevelopmentCreativePlanRescueV5863Test` |

These step labels declare intended regression purposes, **not** owner-certified coverage contracts. The same V5863 source exercises four methods. Its source contains `RefreshDatabase`, auth `assertForbidden` and `assertNotFound`, category preservation, viewer vs owner state and lack of intelligence-projection side effects. That is evidence of asserted boundaries, not proof that adjacent files duplicate their behavior.

### Observed source assertions to preserve

1. Existing legacy collaborative Creative Plan appears as an explicit **opt-in**, remains in original category and can be opted out again; unrelated plans stay unchanged.
2. Ordinary Development and Study plans are not reclassified; choosing a Study plan from Creative recovery remains 404.
3. Unrelated actor cannot see, opt in to, or open another user's private Creative Plan.
4. Shared viewer can opt in to their own session's Development/team surface, but does not gain editor/owner role, change category or create intelligence projections.

## 2. Fixed disposable experiment

`scripts/ci/platform_workspace_r23_creative_rescue_shadow.py` runs only when `GITHUB_ACTIONS=true`, `CI=true`, event `pull_request` to `main` and workdir equals `GITHUB_WORKSPACE`. It runs in the **existing** path-scoped disposable workflow with PHP 8.4, Composer, npm assets, read-only contents permission and SQLite `:memory:` per PHP subprocess, array session/cache, native AI disabled. No real user data or production connections.

1. Strictly validate that the two normal named command sequences still exist in Workspace Resume, that the target appears in exactly two commands, and that the four V5863 methods and source boundary assertions are still present.
2. For each named command, separately run A (original), B (omit **only** V5863), A′ (restore original). Require all pass with complete JUnit inventories; the two A case orders match exactly; B retains all the non-V5863 cases. Measure **whole-command** wall-clock duration for A/B/A′ as contextual diagnostic only.
3. On throwaway runner's **application controller bytes only**, introduce two independent security-relevant negative controls:
   - `creative_plan_owner_view_fence`: remove the `store()` actor `authorizeView` call for a private Creative Plan; expected to be detected by V5863's `test_other_persons_private_plan_never_appears_and_cannot_be_opted_in`.
   - `creative_plan_category_fence`: remove the `store()` Creative category assertion; expected V5863 detector: `test_normal_development_plan_and_unrelated_study_are_not_reclassified`.
4. For **each mutant**, run both historical original groups (each must fail in the exact expected V5863 testcase; full JUnit identity set unchanged) and both omitted groups (observe whether their companions detect the fault). Require PHPUnit status and JUnit error/failure indicators to agree. Do **not** predeclare a shadow result.
5. Restore original controller bytes inside `finally` after every mutant, and re-check SHA-256 in an outer `finally`. Never commit, print, upload, or deploy mutated application source. No application code or standard Workflow commands are edited.

**Maximum and required matrix:** 2 groups × A/B/A′ = 6 baseline processes; 2 controller mutants × 2 groups × original/shadow = 8 processes; **total 14 independently booted PHP test processes**. The runner reports original/shadow testcase counts and class-level mutation detection, source restore checksum, and observed group durations.

## 3. Decision and missing evidence

Either shadow outcome is useful evidence: green neighbors would show these two failures are not caught in this particular local context; failing neighbors could show limited cross-test coverage. **Neither outcome proves one invocation may be removed.** Normal Workspace Resume still executes both copies, in historical order. There is no whole-workflow omission/restart simulation, no isolated DB equivalence proof across all 72 normal commands, no broader mutation suite for team-role escalation or Plan lifetime bugs, no security owner review, and no statistically supported net CI savings.

The decision is **HOLD**. Retain all **42 additional test-file occurrences** from R22, including both V5863 appearances. Do not regroup, reorder, bypass auth, change release gating or consider `main` CI successful proof of production behavior.

## 4. Verification and release boundary

Offline synthetic tests cover workflow drift, exact source/method/RefreshDatabase signals, A/B/A′ source and JUnit drift, targeted mutant negative detection, allowlist-only mutation, temp restore on success/failure, observer variants and non-PR denial. They run in existing Platform Preflight and the real 14-process experiment runs in existing disposable PR CI. Also require final-head Workspace Resume, Development Prelaunch, Migration Recovery plus PR mergeability/reviews before squash merging **main only**.

No new always-on workflow, no normal CI command edits, no production or staging deploy, no Render/Aiven/Keycloak changes, no purchases, no API/app/schema/permissions change, no iOS/TestFlight or device verification. An experiment success is not a production acceptance.

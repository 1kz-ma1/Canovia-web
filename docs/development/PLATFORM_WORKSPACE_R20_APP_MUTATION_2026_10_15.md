# Platform R20 — application-level mutation sensitivity in V5871 contexts

> Status: CI experimental acceptance pending (2026-10-15 JST)
> Baseline: `main` `a4efcfbb5ed7ceaf39cc56119ce14fdabb8933ab`
> Lane: Platform; Plan Draft domain owner: Development
> Prior: PR #657 (R17), #660 (R18), #663 (R19).

## Question and scope

R19 proved that an exception deliberately inserted **in the V5871 test method** is detected across five named Workspace Resume contexts. It did **not** demonstrate whether real application-code faults would be detected when the V5871 file is absent from a group. R20 introduces two small, exact, deliberately **unsafe** controller variants **only inside an ephemeral pull-request GitHub Actions checkout**. The original controller remains unchanged in Git, in `main`, in Workspace Resume, and in all production/staging environments.

| Mutation | Temporary controller change | Expected detecting V5871 method | Security impact **if ever deployed** |
| --- | --- | --- | --- |
| `cross_plan_worklog_source_scope` | remove `plan_id` constraint from `store()` WorkLog lookup | `test_legacy_work_log_is_scoped_to_plan_and_snapshot_is_kept` | cross-Plan source reference acceptance |
| `dismiss_owner_authorization` | bypass `authorizePlan` inside `dismiss()` only | `test_collaborative_editor_viewer_and_stranger_are_forbidden` | unauthorized Draft dismissal |

**These unsafe changes must NEVER be committed to application source.** Only allowlisted literal anchor replacements are applied via a Python diagnostic script and then restored. The draft's accepted/rejected behavior, Plan relationships, permissions and DB schema are not changed in the repository.

## Read-only experiment on disposable runner

`scripts/ci/platform_workspace_r20_app_mutation.py` requires GitHub Actions + CI + `pull_request` targeting `main` and `GITHUB_WORKSPACE` matching the script root; it must fail closed outside that environment. It uses existing `platform-workspace-scenario-equivalence.yml` with read-only repo permissions and setup PHP 8.4, Composer and built npm assets. The runner isolates each `php artisan test` invocation with fresh SQLite `:memory:`, array session/cache, disabled native AI, no service credentials.

Bounded plan: 5 baseline historical named V58.75–V58.71 groups + 1 R18 V58.74 shadow without V5871; then 2 app mutations × (5 original groups + 1 shadow) = **18** independent PHP invocations.

For each baseline, every expected class and the exact four V5871 methods must appear with no skipped/failed/duplicate testcase; shadow must contain exactly the non-V5871 23-case baseline identity set. For each mutated historical group, require nonzero PHP status, full unchanged JUnit testcase identity set, no skips/duplicates, and the specified targeted V5871 method to fail. Additional failures in neighboring classes are counted by class, not automatically treated as proof they substitute the target. For mutated shadow, report **whether it remains green or whether one or more other tests detect the flaw**. Both are meaningful observations; missing case, result/status conflict or malformed JUnit is BLOCK. The report is bounded to class names, counts and allowlisted mutation identities: no raw logs, XML, request/user contents, secrets or mutated source.

After EACH mutation and in an outer `finally`, byte-for-byte restore the original controller and assert its SHA-256; restoration failure is BLOCK. Unit tests simulate source/anchor drift, wrong failure method, surprising JUnit cases, skips, tampered XML, exit mismatch, environment escape, positive and negative restoration, and shadow detection/survival. **No normal CI file list changes.**

## Decision boundary

This can show whether two very specific application faults are detected under each historical context, and whether the other three co-runner files detect the fault even when V5871 is absent. It is **not** proof of broad assertion equivalence, authorization invariance, lack of hidden shared state, or a safe path to remove one of the 42 repeated test occurrences.

The decision stays **HOLD: 42 repeated appearances preserved**. No production rollout, release, Aiven/Render, iOS, payment, GH branch rules, or runtime application controller modifications are permitted. Integration is only for read-only CI diagnostic code, synthetic offline tests, and this specification. Exact PR HEAD must pass disposable probe, Platform Preflight, Development Prelaunch, Workspace Resume, and Production Migration Recovery before main-only squash merge.

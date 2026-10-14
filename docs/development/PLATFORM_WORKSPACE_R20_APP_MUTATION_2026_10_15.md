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


## First observed disposable evidence (PR #666; pre-documentation final HEAD)

**OBSERVED on exact initial corrected PR SHA `ac6135dbfaac6b7ee08678f22bb7d6d6347c1562`**, [Disposable GitHub Actions #38595913142](https://github.com/1kz-ma1/Canovia-web/actions/runs/38595913142) R20 actual application mutation step completed successfully.

| Controller mutation | 5 historical groups containing V5871 | V58.74 shadow *without* V5871 | What the experiment establishes |
| --- | ---: | ---: | --- |
| WorkLog Plan ownership restriction removed | **5/5** detected by V5871 legacy WorkLog test | **0** failing testcases | Three other group files did not expose the cross-Plan WorkLog flaw in this one scoped run |
| Draft dismiss owner authorization bypassed | **5/5** detected by V5871 collaboration permissions test | **0** failing testcases | Three other group files did not expose this actor-permission flaw in this one scoped run |

- Each of the **10** mutated original-group invocations had **exactly one failing case**, always the explicitly selected V5871 method; **no other class** reported a failing testcase. The independent PHP process count was **18/18** (6 positive baselines + 12 mutation/shadow invocations).
- Both three-file shadows remained green under mutation, retained the complete baseline JUnit identity inventory and did not run V5871.
- The controller was restored byte-for-byte, with SHA-256 `f347864c13669d7d479a43f4d10ab1284b2cb0ca98a6716106dcd97c8a46f1d1`. **No application-code mutation was committed.**
- The final corrected initial HEAD passed **12/12** offline mutation contracts in Platform Preflight; the first PR HEAD had one incorrect synthetic skipped-case fixture and was fixed before the observed run. No initial failing HEAD is acceptable merge evidence.

**Interpretation:** These two application mutations strengthen evidence that the V5871 test class has *substantive security-regression detection* in each of its five historical invocations. Its omission from the V58.74 group leaves the three neighboring classes blind to **these specific two flaws** in the tested invocation. Nevertheless, V5871 remains present in four other full Workspace Resume groups, so the experiment neither proves full-CI loss of detection if this single occurrence were removed nor warrants that removal. It also does not prove state or assertion equivalence, or uncover all possible mutant classes. **Decision: HOLD, preserve all 42 repeated appearances** pending separate Development acceptance and broader cross-context semantic/reliability evidence.

The observation comes from the cited PR SHA; documentation-only revisions and any later branch changes require their own exact-final-HEAD CI before `main` integration. No Render, Aiven, production or device verification was performed.

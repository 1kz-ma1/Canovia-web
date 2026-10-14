# Platform R21 — five-context, one-V5871-occurrence-out mutation detection matrix

> Initial status: EXPERIMENT PREPARED; do not interpret as a deduplication decision (2026-10-15 JST)
> Development source baseline: `main` `a630d2da41df1293d61c359ce87ce15492a6a5d6`.
> Lane owner: Platform CI. Domain acceptance: Development/Plan Draft.
> Prior: PR #657 (R17), #660 (R18), #663 (R19), #666 (R20).

## Decision question and exact target

R20 demonstrated that temporary weakening of **PlanActionDraftController** in two ways was detected by `PlanActionDraftV5871Test.php` in all five named historical regression groups, but **not** by the three neighboring files in the V58.74 shadow group after V5871 was omitted. R21 asks the more specific system-level question: when **exactly one** of the five occurrences of that test class is omitted from its named group, do other intact named groups still detect either of these two injected application faults in the *same scoped five-group corpus*?

The scoped groups, unmodified originals:

| Named regression context | Named companion files (V5871 always present in original) |
| --- | --- |
| V58.75 Draft Quality and Staleness Guard | V5875, V5874, V5873, V5872 |
| V58.74 Owner-approved Sequential Action Draft | V5874, V5873, V5872 |
| V58.73 Typed Evidence and Domain-aware Plan Draft | V5873, V5872 |
| V58.72 Multi-evidence Draft and Revision Diff | V5872 |
| V58.71 Action-grounded Draft and Owner Approval | PlanActionFirstCreationV5866 |

All are executed as separately launched PHP processes using SQLite `:memory:` and array cache/session. **The 72 normal Workspace Resume commands, 160 file appearances, 21 repeated files, 42 extra occurrences, other workflows and repository application source are left unchanged.**

## R21 experiment and acceptance protocol

In the existing PR-scoped, disposable GitHub Actions workflow only:

1. Verify exact literal five regression commands from the live Workspace Resume manifest and validate two allowlisted application mutation anchors.
2. Run **five original** and **five V5871-omitted** baseline groups in separate PHP processes. Require all groups pass; all expected testcase class sets and the exact four V5871 method identities appear in originals; omitted groups must contain *exactly* the remaining JUnit testcase identities (no other case dropped or added).
3. For **each** of R20's two narrowly defined temporary real controller faults—cross-Plan WorkLog lookup and bypassed dismissal owner-authorization—modify only the ephemeral checkout copy, run the **five original** and **five omitted** groups separately and read complete JUnit results. Each original must have its expected V5871 method fail. Each omitted group may fail through a neighbor (report the class) or pass; missing/extra/skipped/duplicate case, result/exit mismatch and wrong expected method are **BLOCK**.
4. For each candidate single-omission context, calculate a five-step *logical detection ledger*: the candidate omitted group contributes whether its remaining neighbors detected the mutant, **plus four unchanged original groups** whose V5871 method detected the fault. Explicitly distinguish this ledger from a **real end-to-end sequential run of all 72 commands**. Both faults must be detected in each original group, irrespective of omitted-group result.
5. Restore application controller bytes in a `finally` after **each mutant**, verify SHA-256, and independently repeat restoration on outer exit. No mutated code/JSON raw error traces/JUnit XML are committed or uploaded.

**Fixed total:** 5 original baselines + 5 omitted baselines + 2 mutations × (5 original + 5 omitted) = **30** fresh independent PHP processes. Report 10 original detection cells, 10 omitted shadow detection cells, and the five leave-one-out vectors per mutation. Bounded output names only pinned tests, mutation labels and group classes.

## Hard interpretation / HOLD

Even if each candidate omission retains four detecting *other* historical groups, this does not prove dropping any single V5871 run is safe. The groups have different named acceptance purposes and companion files; cross-process state isolation, nonlocal group side effects, full 72-step CI outcomes, flake rates, performance gains and all failure modes remain unproven. A retained four-way detection vector is a statement about **only two controller mutations on this runner**; it does not replace Development domain-owner review of each named step.

The implementation **always** sets `normal_workspace_workflow_modified=false`, `complete_workspace_resume_equivalence_proven=false`, `cross_context_state_isolation_proven=false`, `permission_to_remove_repeated_invocations=false`, `production_release_authorized=false`. No PR merges that delete tests or relax auth/release gates, no production/Render/Aiven/paid/billing/iOS actions.

## Integration requirements

Offline synthetic tests cover five companion-set identities, exact historical commands, tampered controller anchor, missing target case and drifted other cases, missing/forged original detection, spoofed V5871 appearing in omitted group, omission variants that do or do not detect, negative environment guards, `finally` byte-restoration after success/failure and default-deny CLI. Existing Platform Preflight, Development Prelaunch, Workspace Resume, Production Migration Recovery and disposable replay workflows must all finish success at exact PR head before merging `main`. The only changes allowed in this PR are this specification, a diagnostic script + Python contracts and path-scoped CI wiring. No normal CI command is removed, reordered or parallelized.


## Observed disposable result — PR #670

On initial tested PR HEAD `821c55d2e5a1b15812dc9c47afee469e940852c9`, [GitHub Actions #38602121674](https://github.com/1kz-ma1/Canovia-web/actions/runs/38602121674) completed the R21 scoped experiment with result `OBSERVED_FIVE_CONTEXT_LEAVE_ONE_OUT_MATRIX`.

| Mutation | Unchanged five named groups detecting | Five V5871-omitted shadows detecting | Five logical leave-one-out corridors with four other detectors |
| --- | ---: | ---: | ---: |
| `cross_plan_worklog_source_scope` | **5/5** | **0/5** | **5/5** |
| `dismiss_owner_authorization` | **5/5** | **0/5** | **5/5** |
| **Total** | **10/10** | **0/10** | **10/10** |

Each of five original groups had exactly one failed testcase under each mutation, in `PlanActionDraftV5871Test`. None of the V5871-free companion groups detected either fault; they retained exactly their original non-V5871 JUnit cases and passed. However, each isolated one-occurrence-omission scenario still retains four *other* original named groups detecting both faults. **This is an arithmetic ledger over separately executed PHP processes, not an actual modified 72-step Workspace Resume execution.**

The runner completed **30 independent PHP processes**: 5 baseline normal, 5 baseline omitted, 10 application-mutated normal and 10 mutated omission groups. Added offline synthetic R21 contract suite passed **15/15**. The disposable controller was restored to SHA-256 `f347864c13669d7d479a43f4d10ab1284b2cb0ca98a6716106dcd97c8a46f1d1`.

**Interpretation and decision: HOLD.** These two app mutations are real security-relevant changes to code in the disposable runner, but this does not establish any other security or state boundary, performance improvement, or safe test deletion. The R21 PR still requires its own final-HEAD checks after adding this evidence. No application source change, production deployment or release permission is included.

# Platform R22 — regression-repeat evidence decision register

> Status: CURRENT STATIC INVENTORY + HISTORICAL EXPERIMENT REFERENCES; **HOLD** (2026-10-15 JST)
> Scope: Platform / `V56.12 Workspace Resume Validation`
> Source of truth: current `main` workflow at execution time; current `main` at phase start `7b25d450cc7d7e436e3724fe2b242f924786a096`.
> Historical source evidence: PR #657 (R17), #660 (R18), #663 (R19), #666 (R20), #670 (R21).

## Purpose and known state

Current normal CI explicitly launches 72 Artisan test processes with 160 file arguments covering 118 unique paths. Of those, 21 paths are repeated: **63 appearances**, i.e. **42 additional appearances** relative to running each file once. R17 verified that each repeated path is co-invoked with a different group of companion tests, all under named regression steps. Neither distinct neighbors nor identical method fingerprints prove any removal safe.

R18–R21 performed real, bounded, disposable GitHub Actions comparisons for **one** repeated path: `tests/Feature/PlanActionDraftV5871Test.php`, in **five** named contexts. R18 showed 27→23→27 JUnit cases for one omission; R19 detected four method tripwires across five contexts; R20 caught two security-relevant controller mutants in 5/5 intact contexts, not in the first shadow; R21 produced **10/10 intact detections and 0/10 V5871-free detections** across five original and five shadow contexts. The five-context *logical* leave-one-out view still had four other intact detection groups under each simulated single omission. None of these experiments ran a modified full 72-step Workspace Resume, evaluated other vulnerabilities, or established domain intent/state equivalence. A historical experiment cited by a checked-in document is **not** a fresh attestation for the current commit.

## Implemented audit

`python3 scripts/ops/platform_workspace_r22_decision_register.py` is offline and read-only. It reuses the fail-closed R17 current workflow parser and per-file PHP source signals; it does not invoke PHP, fetch GitHub Actions, create test mutants, edit normal CI, or connect to providers.

The register enumerates all **21** repeated paths, all **63** appearances, every named step, exact ordinal/command fingerprint and neighbor set. It enforces the pinned 72/160/118/21/42 inventory and cross-checks that the studied V5871 file still appears under the same five named steps. It checks that all four R18–R21 observation documents exist with key historical anchors, and labels them `HISTORICAL_DOCUMENTED_DISPOSABLE_CI_RESULT_ONLY` — evidence references, **not** revalidated CI.

| Category | Paths | File appearances | Extra appearances | Evidence available | Verdict |
| --- | ---: | ---: | ---: | --- | --- |
| V5871 Plan Draft | **1** | **5** | **4** | R17 current static + R18–R21 historical scoped experiments | **HOLD** |
| Other repeated files | **20** | **58** | **38** | R17 current static step/companions/source signals only | **HOLD** |
| **Total** | **21** | **63** | **42** | Uneven evidence, no complete sequential equivalence | **HOLD** |

The report returns `HOLD_MANUAL_CONTEXT_DECISION_REQUIRED` if its evidence structure is intact; it returns nonzero `BLOCK` on any inventory/source/evidence drift. **Successful audit execution means HOLD, not GO.** It permanently sets `automatically_approved_removal_candidates=0`, `permission_to_remove_repeated_invocations=false`, `permission_to_regroup_or_reorder=false`, `production_release_authorized=false`.

## Per-occurrence review gates before even considering a code-changing proposal

1. **Domain owner confirms specific regression step purpose** and why the named companion suite was originally bundled with that repeated file.
2. Compare actual *assertions, fixtures, side effects and authorization boundaries* in the target group and other original groups. A matching testcase name is not a semantic guarantee.
3. Demonstrate preservation of *real process and database/session isolation*, ordering constraints, and fail-first behavior across the complete sequential workflow. A sum of independently executed group outcomes cannot replace full-flow validation.
4. Select a broader and explicitly justified real application mutation matrix, including negative controls, authorization/Plan boundary cases, and failure restoration; two R20/R21 mutations are insufficient.
5. Pin exact SHA, require repeatability across independent runners and all relevant CI; attribute cost improvement to measurements under representative runners rather than speculative individual-file timing.
6. Demand an independently reviewed, *separately approved* change to the normal Workspace Resume, with a clear owner/rollback gate. An audit never automatically changes the workflow or approves release.

The register intentionally assigns security-sensitive V5871 to **known auth/Plan impact manual review first** and leaves the risk priority of all other 20 **unclassified**, not “security safe.” Its per-occurrence `other_companion_files_alone_detected_these_two_mutants` is `false` only in V5871's five historically tested contexts and `null` for all other contexts. Each context's state equivalence and step owner acceptance remain false.

## CI coverage, operational boundary and next step

New Python contracts validate actual 21-path census, all 63 occurrences held, 5 vs 58 studied/static partition, historical-doc not-fresh classification, drifted source/step/doc rejection, never-grant predicates and read-only CLI. They run in **existing path-scoped Platform Release Preflight**, with no extra always-on workflow or added ordinary Workspace Resume test process. Verify final PR HEAD CI, whole diff and mergeability before `main` integration.

Next phase, **R23**, should be an intentionally bounded cross-runner review of *one* newly selected repeated file or an explicit Development domain-owner acceptance for a single named regression context. Do not build an automatic optimization scheduler, remove existing 42 repeats or change production until separate evidence and authorization requirements are satisfied.

**Production impact:** no application PHP/runtime/API/schema change, no permissions relaxation, no data writes, no Render/Aiven/prod/iOS/billing operation, no release. `main` integration is not a deployment or device verification.

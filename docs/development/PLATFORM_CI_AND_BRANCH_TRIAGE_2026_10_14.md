# Canovia Platform — CI Run Triage & Branch Inventory V3

Date: 2026-10-14 JST. Status: ACTIVE development tooling; PRODUCTION NO-GO. This document does not authorize a release or any deletion. AGENTS.md, development workflow and existing production rules remain authoritative.

## CURRENT — read-only evidence

- GitHub API inventory observed 54 workflow files, 412 remote branches and 22 open pull requests. Snapshot only: parallel work may change the counts.
- In 100 recent Actions runs, 49 were push-event failures of the Adaptive Context G3 Companion Product Grant Revocation Race workflow. Representative run 38534331353: completed/failure with zero jobs. Checked-in workflow declares pull_request only, not push. This suggests investigating workflow loading/configuration or policy, **not** declaring the PHP tests failed. The exact validation error/root cause is NOT VERIFIED.
- Other PR workflows including Platform Preflight, Workspace Resume, Migration Recovery and Development Prelaunch have success evidence at specific SHAs; that is not proof that every check is green.
- Six release branches including pinned production candidate PR #513 and MCP staging candidates are present. Other lanes are active. No branches/PRs are changed or closed in this phase.

## ACTIVE — offline GitHub Actions run triage

The script scripts/ops/platform_ci_run_triage.py reads only locally exported run and job JSON. It makes no provider requests, does not print raw logs/environment values and cannot authorize release.

Use the authenticated GitHub CLI on the owner's Mac, saving exports OUTSIDE the repository:

~~~sh
RUN_ID=38534331353
gh api --method GET "repos/1kz-ma1/Canovia-web/actions/runs/$RUN_ID" > run.json
gh api --method GET "repos/1kz-ma1/Canovia-web/actions/runs/$RUN_ID/jobs?per_page=100" > jobs.json
python3 scripts/ops/platform_ci_run_triage.py --run-json run.json --jobs-json jobs.json
~~~

Only output classification, run ID and job counts. Keep raw exports private and remove after use. The first-page job limit is intentional: incomplete/paginated evidence yields BLOCK, not success. Categories: NO_JOBS_WORKFLOW_SETUP_OR_POLICY_REVIEW (zero job failure; **cause unknown**), EXECUTED_JOB_FAILURE (test job ran and failed), WORKFLOW_STILL_PENDING, NO_JOBS_NO_TEST_EVIDENCE, WORKFLOW_OR_JOBS_REQUIRE_REVIEW, and ALL_REPORTED_JOBS_SUCCESS_NOT_RELEASE_GO. All categories preserve release_authorized=false and root_cause_verified=false.

Always match the exact SHA/event/check context. GitHub PR CI success cannot be used as evidence for an untested production merge SHA.

## ACTIVE — offline branch review inventory

The script scripts/ops/platform_branch_inventory.py only reads local Git remote-tracking refs and a snapshot of open PRs. It neither deletes, pushes, updates, nor closes anything.

From a verified full-history local clone:

~~~sh
git fetch --prune origin
gh pr list --repo 1kz-ma1/Canovia-web --state open --limit 1000 --json number,headRefName,headRefOid > open-prs.json
python3 scripts/ops/platform_branch_inventory.py \
  --repo . \
  --open-prs-json open-prs.json \
  --attest-complete-pr-snapshot > branch-review.json
~~~

Before attesting completeness, verify that the snapshot contains every open PR and refers to the correct repository; the export is not silently trusted. Without complete PR evidence, omit both PR arguments: classification is more conservative. Local git fetch --prune only prunes local remote-tracking references, **not GitHub branches**. For a cautious first run, use git fetch origin without prune and confirm ref freshness independently.

| Classification | Operator meaning |
| --- | --- |
| HOLD_PRODUCTION_OR_RELEASE_HISTORY | main, production, any release/*: preserve |
| HOLD_OPEN_PR | Active PR head with matching SHA: preserve |
| HOLD_OPEN_PR_HEAD_MISMATCH | PR/head ref drift: preserve and investigate |
| REVIEW_IN_MAIN_NO_OPEN_PR | Already reachable from main, no currently open PR: human review candidate, **not deletion permission** |
| REVIEW_UNMERGED_OR_DIVERGED | Unique/unintegrated history may exist: preserve, inspect |
| REVIEW_OPEN_PR_EVIDENCE_MISSING | PR export incomplete: no cleanup inference |
| BLOCK | Git refs or JSON evidence incomplete/invalid: stop |

Before any proposed deletion inspect *closed and merged PR history*, activity in other chats, release dependencies, SHA ancestry and owner intent. Present explicit branch names for separate owner approval. Scripts have no delete capability.

## RECOMMENDED — later improvements (not implemented here)

1. Diagnose no-jobs G3 failure via GitHub Actions UI annotations/check diagnostics, with the active G3 lane. Do not edit its workflow on speculation.
2. Measure workflow runtimes and coverage before deduplicating broad PR checks. Avoid removing required security tests. Workflow path filters can create absent/skipped checks and must be evaluated against branch protection.
3. Investigate repeated Composer/npm installation/build cost across 54 workflows after mapping their actual test responsibilities.
4. Generate deletion review ledger from branch inventory, then request separate approval.
5. Keep release PR #513 Draft, Render Auto Deploy OFF while bound to main, and all owner-operated Aiven/backup/real-device gates intact.

## Tests, limitations

Nine synthetic local Git topology tests and nine synthetic workflow-run metadata tests were added. The existing path-scoped Platform Release Preflight workflow is extended; no extra workflow is added.

NOT VERIFIED: exact G3 setup failure cause, live Aiven schema/privileges/backup freshness, offsite decrypt/recovery, actual iPhone smoke, authorization to delete or release.


## ACTIVE follow-up — all-workflow YAML syntax gate

After PR #617 fixed the unquoted colon in the G3 grant-race workflow, its real GitHub PR Actions job ran successfully. To prevent the same pre-job parsing class of regression, the existing **Platform Release Preflight (Offline)** workflow now triggers when any file under .github/workflows/ is changed and parses every source-controlled .yml workflow before the other Platform tests. No new GitHub workflow file or production Required Check was created or modified.

The source-controlled Ruby Psych validator is scripts/ops/platform_workflow_yaml_check.rb, invoked as:

~~~sh
ruby scripts/ops/platform_workflow_yaml_check.rb .github/workflows/*.yml
~~~

It checks **YAML syntax only**, not GitHub Actions trigger semantics, permissions, branch rules, or runtime behavior. It prints a fixed error label, filename and line number but no YAML source snippets, and fails closed for empty/missing/symlinked inputs. Seven disposable tests cover valid YAML, the previously broken unquoted-colon case, its quoted fix, empty/missing/symlinked files and no input.

This is a low-cost guard (Ruby standard-library Psych; no Composer, npm, credentials, remote API or service dependencies). It does **not** automatically fix workflow files, change required checks, or authorize production releases. A workflow with syntactically valid YAML may still fail to load or run for other reasons; inspect live Actions job creation and results as separate evidence.


## ACTIVE follow-up — bounded Actions runtime metrics (2026-10-14)

**CURRENT observation (single sample, not representative):** exact PR-head
`3129d72636ba3020dbd8414bca968aa3de6df7ab`:
- Workspace Resume [run 38537757172](https://github.com/1kz-ma1/Canovia-web/actions/runs/38537757172): **96 s** job runtime (08:48:36–08:50:12 UTC); Composer **7 s**.
- Production Migration Recovery [run 38537757142](https://github.com/1kz-ma1/Canovia-web/actions/runs/38537757142): **32 s** (08:48:37–08:49:09 UTC); Composer **6 s**.
- Development Prelaunch [run 38537757167](https://github.com/1kz-ma1/Canovia-web/actions/runs/38537757167): **22 s**; Composer **6 s**.
- Platform Release Preflight [run 38537757096](https://github.com/1kz-ma1/Canovia-web/actions/runs/38537757096): **7 s**.

These values are GitHub job wall-clock intervals, **not GitHub billable minutes**. The same PR triggers multiple independent jobs. Repeated Composer/npm/build steps are *observed work*, not sufficient proof they can be removed: migrations, session compatibility and MySQL safety rely on distinct domain tests. Reducing coverage or using skip/neutral as substitute for Required Checks is forbidden.

**ACTIVE implementation:** `scripts/ops/platform_ci_runtime_report.py` collects a bounded sample of up to 30 Actions runs using only GitHub CLI `gh api --method GET` for a fixed repository and read-only run/job endpoints. No token is printed or passed as a command argument. It prints only aggregate job durations (median and nearest-rank p90), successful setup-step repetition, and zero-job/incomplete evidence counts. It neither alters CI nor certifies test coverage equivalence or release readiness. With the owner's existing GitHub CLI login:

~~~sh
python3 scripts/ops/platform_ci_runtime_report.py --live --event pull_request --limit 20
~~~

If missing permissions, incomplete results, pagination, invalid timestamps or no successful jobs, the tool stops/reports insufficient evidence. Raw API JSON/logs are never written by live mode; operators must not redirect secret-bearing raw logs into GitHub. Synthetic offline snapshots for tests can be provided with `--snapshot /path/to/synthetic-evidence.json`; live REST access is **not** required in CI.

**NEXT, not implemented:** compare three or more samples over distinct PR SHAs before changing cache paths, scheduling or test selection. Group by workflow and content/trigger paths, compare test-coverage contracts, and identify safe reuse. Investigate Composer cache integrity and PR poisoning risk before enabling shared caches; do not reduce unconditional production checks.

**Branch review examples, NOT DELETE APPROVAL:** `ci/p0-release-gate-20261011` is an ancestor of main (ahead=0 at sampled comparison), so it is a human-only review candidate after confirming PR linkage and no consumers. `docs/development-operational-v2-status-20261014` (ahead 2 / behind 23) and `feature/v52-0-ios-readiness-foundation` (ahead 2 / behind 2316) have unique history and must stay on hold. A Squash-merged branch may still appear diverged by ancestry; never infer safe deletion solely from ahead/behind. No branches were deleted or closed.

**Status:** CURRENT measured metadata; ACTIVE read-only metrics tool; FUTURE selective CI optimization; NOT VERIFIED production Aiven/Render changes, iOS smoke, runtime improvement percentage, exact cleanup ownership.


## ACTIVE follow-up — safe superseded-PR cancellation (2026-10-14)

### CURRENT runtime evidence (GitHub Actions job metadata, not billing)

Sampled completed PR runs:
- Workspace Resume: 96, 140, 90, 149, 141 seconds (five observed jobs; median **140 s**).
- Production Migration Recovery: 32, 39, 34 seconds (three jobs; median **34 s**).
- Development Prelaunch: 22, 19, 18 seconds (three jobs; median **19 s**).

Workspace Resume repeatedly spends ~15–18 seconds on Composer/PHP/Node/npm/build setup, while hundreds of checks and application regressions account for the remaining runtime. Thus removing a 5–8-second Composer step without established equivalent coverage would not solve the main bottleneck. Different exact PR SHAs and environmental load also affect measurements. These are job wall-clock durations, not billed compute minutes.

### ACTIVE change and acceptance boundary

The existing **Workspace Resume** and **Production Migration Recovery** workflow files receive a workflow-level concurrency key. **Only** a `pull_request` event targeting `main` groups by workflow identity and PR number, and sets `cancel-in-progress=true`. When another commit arrives on the *same* main-targeted PR, its old in-progress run is allowed to cancel; latest PR SHA still executes all unmodified tests with the same job/check names.

All other contexts—especially `push` to `production`, PR into `production`, historical hotfix push, and `workflow_dispatch` where applicable—use **a unique GitHub run ID** and `cancel-in-progress=false`. Unique run IDs are essential: GitHub concurrency can replace a pending run **even if cancel-in-progress=false** when it shares a group. A shared production group is prohibited.

No test command, Required Check, job name, branch trigger, branch protection, production operation or Deploy is removed. The dedicated static contract test rejects missing main-only cancellation guards, removal of production push/PR triggers, loss of production test commands and replacement of unique production run IDs. The Platform YAML syntax gate and the existing broad PR CI also run.

### Acceptance and limitations

- **Verified by PR CI:** the final candidate SHA runs Workspace Resume, Migration Recovery, Development Prelaunch and Platform Preflight successfully, and the new static contract tests pass.
- **NOT VERIFIED until a later PR iteration:** GitHub Actions actually cancels a superseded run in practice, any minute savings, stability under multiple rapid changes. Record before/after evidence using the bounded runtime tool.
- **Production-safe by source contract, not yet empirically exercised:** production push and production PR contexts must remain independent. No production push/deploy is initiated for this test.
- **RECOMMENDED:** expand to Development Prelaunch only after its current active Development-lane owner confirms the shared workflow boundary; do not modify it in parallel.

This is a scheduling/cost optimization for obsolete *main PR* revisions, not a new path filter. Never treat cancelled/old-SHA CI as release evidence. Re-run/inspect final SHA checks before merging to main. Production promotions still require owner GO, full required checks on the exact merge SHA and actual Render/Aiven evidence.

## Platform PR concurrency live acceptance — evidence protocol (2026-10-14)

**CURRENT TEST STATE: IN PROGRESS; NOT VERIFIED.** Only the Platform lane's own documentation is changed on this branch; no product behavior, GitHub rules or production settings are modified.

1. Start a normal PR from the dedicated Platform feature branch into main. Capture each GitHub Actions run ID, PR number, SHA, status and conclusion for both long-running always-on checks (Workspace Resume, Production Migration Recovery).
2. While the first Workspace Resume run is executing, add a second documentation-only commit to this same PR to trigger a second run.
3. Check the two distinct head SHAs. Expected: old Workspace Resume and old Migration Recovery runs may have conclusion cancelled if still active; new SHA must run fully with success. Any old run already completed successfully before the new run started cannot demonstrate cancellation.
4. Record the full run IDs and observed results. An old run marked pending while a new run is also pending is inconclusive. No force-cancel through API is permitted.
5. All current-head CI including Platform Offline, Workspace Resume, Migration Recovery and Development Prelaunch must succeed before main merge. Protect production pushes/PRs: no production event is created by this experiment.

**LIMIT:** A successful cancellation for a main-target PR does not prove production-context isolation at runtime. Static production isolation contract tests remain the only verification there; owner release GO is still required.

### Controlled second revision — evidence marker

The second commit was deliberately made after GitHub API showed the first SHA's Workspace Resume run 38546176452 as **in_progress**, along with Migration Recovery run 38546176439 as **in_progress**. Old head SHA: 65efc41a8917173f377b57e30d291df6f35508c7. The observable outcomes are assessed only using the GitHub API run statuses after this commit; no claim of cancellation is made by this marker alone.

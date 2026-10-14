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

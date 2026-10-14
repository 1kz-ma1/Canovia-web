# MCP Staging — Render API deploy drift incident and safe configuration gate

Date: **2026-10-14 JST**. Owner lane: Platform / MCP. Status: **incident contained, development-only preventive controls**. This runbook is not permission to change Render settings or deploy. Read `AGENTS.md`, [MCP release gates](MCP_REAL_ACCOUNT_CONNECTION_RELEASE_GATES_2026_10_14.md), and [candidate #630](https://github.com/1kz-ma1/Canovia-web/pull/630) before any operational decision.

## 1. Grounded incident timeline, not guesses

| UTC timestamp on 2026-10-14 | Observed provider event |
| --- | --- |
| 10:06:43 | Owner manually deployed exact isolated Staging candidate `9cc3f361941facc23bc81958c6aa81134a75c046`; Render later marked it Live. |
| 10:07:47 | Candidate deployment succeeded; runtime PostgreSQL migrations and Nginx/PHP-FPM startup reported success. |
| 10:11:48 | Immediately following this chat's use of the **Render connector** `update_environment_variables(..., replace=false)` for `CANOVIA_MCP_ACCOUNT_LINK_GITHUB_ONLY_ENABLED=true`, Render started an **API-triggered** deploy `dep-db7lap49v7es73cfd8h0` of unrelated moving-`main` commit `c8749324a4701e11346ba1349d45272f01e74a88`. Auto Deploy stayed OFF. This event violated the operator's exact-SHA approval. |
| 10:12:49 | Unapproved `c8749324` became Live; user instructed to roll back; no second connector mutation or redeploy attempted. |
| 10:16:10 | Owner started Render Dashboard rollback `dep-db7lcqfavr4c738m67a0`, explicitly targeting `9cc3f361`. |
| 10:16:53 | Render marked the rollback Live; old bad deploy deactivated, startup and DB migrations succeeded. |

**Root cause boundary:** Official Render API documentation states direct environment-variable API edits do not automatically deploy, **but this connected tool operation was followed by an API-triggered new commit deploy**. The connector's internal implementation has not been independently inspected; do not claim the core Render API behaved contrary to its own contract. Treat the *combined connector operation* as unsafe/unpinned until a provider fix with independent proof.

Sources: [Render API Update env vars](https://api-docs.render.com/reference/update-env-vars-for-service), [Render deployment options](https://render.com/docs/deploys), [Render env configuration](https://render.com/docs/configure-environment-variables), [Render rollback behavior](https://render.com/docs/rollbacks).

## 2. Hard restrictions effective immediately

- **NEVER invoke ChatGPT's Render `update_environment_variables` or `trigger_deploy` for this Staging service**, even with Auto Deploy OFF. The former demonstrably initiated an API deploy of moving `main`; the latter's available connector interface has **no `commitId`/exact-SHA field**.
- Do not retarget service branch, enable Auto Deploy, merge draft #630 or #594, promote to `production`, or enable OAuth/ChatGPT MCP flags by automation. Keep Stage isolated and synthetic-only. Never touch Aiven/real personal Plan.
- Do not claim rollback removed the **saved** GitHub-only environment variable. Render's rollback uses the chosen deploy's historical variable values *for that rollback*, but does **not** overwrite current saved service configuration. Flag's current stored value is **UNKNOWN** until privately inspected in Render Dashboard (without copying secrets).
- The explicitly owner-approved code commit was `9cc3f361941facc23bc81958c6aa81134a75c046`. A later exact-SHA release is a **new decision**, not automatically covered. `main` has continued advancing rapidly independently.

## 3. Safe operator-only sequence (distinct future approval required)

This does not grant authorization; before doing any of it, confirm the user's next explicit Staging change approval.

1. In the Render Dashboard of **My Workspace → canovia-mcp-staging** (the service with ID `srv-db43l4nlk1mc73emseig`), inspect **Environment** and report only whether `CANOVIA_MCP_ACCOUNT_LINK_GITHUB_ONLY_ENABLED` is **saved as true/false/missing**. Do **not** reveal any other key's values, Auth0 client secrets, session cookies, DB URL or IDs. A saved value is not proof of an active runtime value.
2. Verify current Live SHA is `9cc3f361`, the newest Render deploy is Live (no other build or deploy in progress), workspace ID matches, Auto Deploy remains `no/off`, PR #630 stays Draft/unmerged, and its **exact head SHA** and all 3 scoped CI checks are still green. Obtain fresh metadata from provider, never rely solely on the 2026-10-14 snapshot.
3. If a flag change is **still approved**, select **Save only** in the Render Dashboard. Do **not** select “Save, rebuild, and deploy” or “Save and deploy.” **Save only does not activate runtime changes.** Immediately read Render Deploys: a new or unexpectedly triggered API deploy is **STOP / incident**.
4. For runtime activation, obtain a separate explicit approval of the **exact new deployment SHA** and current flag state. From Render → Manual Deploy → **Deploy a specific commit**, enter that SHA and validate the confirmation text before executing. **Never** use Deploy latest commit or the connected `trigger_deploy` tool. The Dashboard can deploy the specified commit.
5. Compare the new `Live` SHA with the approved SHA after deploy, verify synthetic-only startup/migrations, login/authorization closed surfaces, safe stage logs, active configuration, user-visible subject-selection result. Wrong commit → stop all testing and return to last known-good build via Dashboard Rollback.
6. Only after distinct owner approval for real provider login, test one synthetic owner and synthetic Plan, with exact matching signed Auth0 GitHub primary ID on both clients; begin from previously revoked/denied grant, conduct fresh preview + separate CSRF approval, test wrong-subject denial and post-revocation denial. No Aiven, ordinary Plan or production accounts.

## 4. Offline drift gate

`scripts/ops/mcp_staging_release_drift_guard.py` accepts only **sanitized metadata JSON** with fields `service`, newest-first `deploys`, `candidate`, `checks`, and a *human independently inspected* `stored_github_only_flag` marker of `verified_true`, `verified_false` or `unknown`. Values of actual secrets are neither needed nor accepted as part of the contract. Example schema (all example entries except the pinned SHAs are placeholders, not live evidence):

```json
{
  "service": {
    "id": "srv-db43l4nlk1mc73emseig",
    "ownerId": "tea-d4vb3f6mcj7s73djkrqg",
    "name": "canovia-mcp-staging",
    "repo": "https://github.com/1kz-ma1/Canovia-web",
    "branch": "main",
    "autoDeploy": "no",
    "autoDeployTrigger": "off"
  },
  "deploys": [{
    "id": "dep-example",
    "commit": {"id": "9cc3f361941facc23bc81958c6aa81134a75c046"},
    "status": "live",
    "trigger": "rollback"
  }],
  "candidate": {
    "head_sha": "9cc3f361941facc23bc81958c6aa81134a75c046",
    "base_sha": "8a831448eef528b786053ba5506517300bc001c7",
    "draft": true,
    "merged": false
  },
  "checks": [
    {"name": "provider-routing", "status": "completed", "conclusion": "success"},
    {"name": "auth0-sealed-config", "status": "completed", "conclusion": "success"},
    {"name": "isolated-staging-boot", "status": "completed", "conclusion": "success"}
  ],
  "stored_github_only_flag": "unknown"
}
```

Run locally with `python3 scripts/ops/mcp_staging_release_drift_guard.py --snapshot operator_sanitized_snapshot.json --mode audit`. No Render, GitHub or network access is used; no mutation is possible. It refuses bad SHA, mismatched workspace/service, Auto Deploy ON, pending/unapproved latest deploy, changed PR/CI or unknown saved flag. A **read-only preflight success** only returns `REVIEW_ONLY`, `write_or_deploy_authorized=false` and `production_authorized=false`. `--mode render-connector-env-write` is **always BLOCK** and nonzero, even for an otherwise healthy snapshot; the two Dashboard/commit planning modes also require separate human approval. No stage settings or deployment can be authorized by this tool.

CI: `.github/workflows/mcp-staging-release-safety.yml` runs offline Python tests on all changes to guard/runbook. Tests reproduce the erroneous moving-main deploy during Auto Deploy OFF, in-progress API build, stale/missing flag state, wrong service/workspace, wrong PR head and failed checks.

## 5. Current NO-GO

- Last verified Live was `9cc3f361`; stored environment configuration unknown.
- No provider-native verified saved-flag read is currently available through the connected Render tool.
- **Further Staging updates, real GitHub login acceptance and any production access remain paused pending a new explicit owner approval and safe per-SHA operator sequence.**

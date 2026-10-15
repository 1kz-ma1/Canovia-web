# AP Study MCP private pilot — pinned release inventory / decision HOLD

Date: 2026-10-15 JST. **Read-only source inventory; not a deployable release candidate.**
Owner: Platform + Learning. Companion to [private AP pilot authorization gate](MCP_AP_REAL_OWNER_PRIVATE_PILOT_GATE_2026_10_15.md) and [production workflow](../CANOVIA_DEVELOPMENT_WORKFLOW.md).

## Verified source/environment distinction

| Boundary | Exact observed value | Meaning |
| --- | --- | --- |
| Render Canovia Web Live | 15abdc9de6f24b5250fb255c579d1aa55facaf1a (deploy dep-db5fgl942hec738pbq30) | Observed Live; re-observe before release |
| GitHub production branch | 15abdc9de6f24b5250fb255c579d1aa55facaf1a | Code branch, not evidence of the live DB schema |
| GitHub main snapshot | e81e2ac4a82aa37012aa7f716ad3a60543b5a489 | **Inventory snapshot only**, not a reviewed deployment candidate |
| Render synthetic MCP Staging Live | 1e93b6ce17b29e0b029b78293462839fdcbd5a80 | Synthetic-only; never repoint existing ChatGPT Staging plugin |
| Canovia Web and MCP Staging Auto Deploy | OFF at observation | No environment or deploy mutation authorized |

Evidence: GitHub tree/branch and Render read-only service/deploy APIs on 2026-10-15. They do NOT attest the Aiven physical schema, OAuth clients, real AP owner/Plan, backups or restore.

## Static Git delta: fixed production baseline → main snapshot

- 210 commits ahead (verified Git ancestry), but main may move independently.
- **452 added, 191 modified, 0 removed Git blob paths; 643 distinct changed paths.** The pinned inventory contains all path names for line-by-line review.
- **18 source-new Laravel migrations** and 0 modified existing migration files. Actual production *pending* migrations are UNKNOWN pending an Aiven ledger/schema audit.
- MCP runtime: 14 newly added controller/model/service paths and 14 modified paths. New Auth0 JWT verifier, owner/Plan consent, protected resource, Study overview/tasks, aggregate read, write-scoped PENDING proposal and owner-confirmed commit cross the baseline.
- Current production source does NOT have the Study read endpoint. Enabling a flag alone cannot make it work.

| Migration / surface | Source-level hazard to examine |
| --- | --- |
| 2026_10_13_120000_enforce_authorization_epochs_in_database.php | MySQL authorization-epoch triggers; Aiven privileges, semantics and interrupted nontransactional DDL |
| 2026_10_13_130000_immutable_plan_member_binding.php | Second trigger prohibiting in-place reassignment of Plan membership |
| 2026_10_14_000200_preserve_historical_mcp_identity_links.php | Existing-row updates, replacement uniqueness/index constraints, conditional down failure |
| 2026_10_15_070000_create_mcp_study_result_proposals_table.php | Pending result and approved result storage; approval remains Canovia owner-only |
| Other 14 source-new migrations | Development observations, recall provenance, private-file fencing and operational metadata must be audited independently |

**Critical deployment behavior:** docker/render-start.sh runs Laravel migrate --force before starting HTTP. Turning OFF AP flags does NOT prevent other pending migrations applying. Code rollback cannot reverse live schema/row changes safely.

## Decision: HOLD

Do not deploy this snapshot and do not call it a release candidate. Repository policy requires a reviewed source pin on a release/* branch that is an ancestor of main, a separately owner-approved production merge commit, and an explicit first manual Render deployment. A selective production-based backport would need a separately reviewed exception/new promotion contract.

Three routes require a conscious, independently approved decision:

1. **Audited integrated release:** pin an approved main candidate, audit the complete 643-path delta, all 18 source-new migrations, MySQL 8.0/8.4 rehearsal, Aiven ledger and iOS/Web/session regressions. This is the normal repository release path, not currently justified as an AP quick fix.
2. **Scoped backport:** inventory and test the true closed set of required code/auth/migrations against old production; separately design approval and release ancestry. Not built, tested or authorized. Do NOT cherry-pick AP endpoints without their OAuth verifier, identity link, grant, schema and privacy guards.
3. **Separate private real-user runtime:** first approve service isolation, data access, cost and identity boundaries. Do not repurpose synthetic Staging, attach the production database, copy real user data or provision services during this investigation.

**Next engineering slice:** construct an exact closed dependency manifest for read-only overview and tasks from the Live baseline, compare options (1) and (2), and produce a disposable DB rehearsal plan. Aggregate read and the independent canovia.study.write scope remain OFF until real owner/Plan read and denial/revocation acceptance.

## Runnable evidence and scope

In a full Git checkout (no credentials needed):

python3 scripts/ci/mcp_ap_private_pilot_release_inventory.py --baseline 15abdc9de6f24b5250fb255c579d1aa55facaf1a --snapshot e81e2ac4a82aa37012aa7f716ad3a60543b5a489 --output ap-private-pilot-pinned-diff.json

The script verifies exact SHAs and ancestor relationship; enumerates changed Git paths, new/changed/removed migrations and selected shared runtime hotspots; flags lexical trigger/raw-SQL/backfill/drop patterns for HUMAN review. These markers cannot validate actual SQL execution or schema state. A successful inventory deliberately returns **status=HOLD, release_authorized=false**; missing evidence returns BLOCK. CI uses read-only checkout and disposable Git unit tests, stores an inventory artifact and deploys nothing.

## Required manual gates — all OPEN

1. Re-verify current Render Live SHA, watched branch/auto-deploy and exact chosen release SHA.
2. Review every changed path and minimum runtime/authorization dependency closure; run MySQL 8.0/8.4 migrations and legacy/retry tests in a disposable, representative database.
3. Operator-only Aiven SELECT ledger and physical schema audit at the exact candidate; unknown/partial DDL is BLOCK.
4. Independently verify current backup and feasible recovery route; explicit owner acceptance of untested restore risk; never rely on blind down migration.
5. Review production startup, session/CSRF, login, MCP/IdP and distinct Canovia confidential vs ChatGPT OAuth clients without logging tokens or secrets.
6. Owner selects their numeric Canovia user ID and their existing AP Study Plan via authenticated first-party UI; IDs are NOT in public GitHub docs and must not be guessed.
7. After specific owner approval for service, SHA and feature switches: enable owner-only read first; verify overview then tasks, wrong-owner/wrong-Plan/denied-scope/revoke on existing JWT. Independently authorize aggregate and write scope only after that.
8. Separate explicit owner release/go-no-go, production branch merge and exact-commit manual Render deployment; real iPhone/Web/desktop smoke. Existing synthetic-only Staging unchanged.

This audit does NOT provision resources, query real learner data, apply Aiven DDL, change Auth0, alter Render settings, promote production or approve any release.

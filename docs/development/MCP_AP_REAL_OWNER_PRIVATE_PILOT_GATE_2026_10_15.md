# Canovia MCP — AP real-owner private pilot release gate

Date: 2026-10-15 JST. **Preparation only. Not approved for deployment or access to any real learner records.**

## Read-only inventory at this checkpoint

- GitHub: `1kz-ma1/Canovia-web`; AP Study read, applied-practice aggregate read, write-scoped **PENDING** proposal, CSRF-protected owner approval were merged in PRs #678, #684 and #691.
- **Real Canovia production web service** Render `srv-dagfn2id0e5s73cac7ng` in workspace `tea-d4vb3f6mcj7s73djkrqg`, URL `https://pacekeeper-d3mm.onrender.com`. Its Live commit at inventory time was **`15abdc9de6f24b5250fb255c579d1aa55facaf1a`**, older than the Study MCP changes. AutoDeploy OFF.
- **Synthetic-only MCP Staging** Render `srv-db43l4nlk1mc73emseig`, Live **`1e93b6ce17b29e0b029b78293462839fdcbd5a80`**, AutoDeploy OFF, only approved synthetic Development Plan ID 1. The existing `Canovia MCP Staging` private ChatGPT plugin points at this service and must not be repointed to real-data deployment.
- There is **NO separately confirmed real-user MCP pilot service / Auth0 permission / ChatGPT OAuth client registration** and no personally verified live AP Plan ID in this task. This document does not claim otherwise.

## What this PR adds

- **`CANOVIA_MCP_AP_PILOT_OWNER_ID`** (single positive decimal Canovia user ID, initially empty). Without one exact operator-selected ID, all Study MCP consent, preview, task read, practice aggregate read and proposed results fail closed. A wrong user ID, comma-separated list, zero or malformed value is denied.
- Owner allowlisting is an *additional* restriction, not authentication: each call still needs an independently verified OAuth access token; trusted issuer/audience/client/resource/subject, linked identity and exact per-Plan granted scope are checked. Neither user account IDs nor Plan IDs from tool input are trusted as authorization.
- The owner's OAuth consent can continue using `overview`, `tasks` or explicitly chosen `study_progress`. The write tool still requires the extra `canovia.study.write` permission; even a successful proposal requires the owner to confirm in Canovia before counting toward aggregate study history.
- `tools/list` hides all Study tools unless a valid private pilot ID and feature flags are configured. The protected resource metadata only advertises write scope after all flags and allowlist are properly set.
- Synthetic Staging denies real Study data even if its operators accidentally toggle these flags. Development Plan access is unaffected.

## Real-account pilot go/no-go checklist (must secure **new explicit approval**)

1. **Confirm source and release boundaries.** Create a scoped, reviewable release candidate against the actual **production Live SHA**; compare every file/migration against currently deployed production code. `main` has moved beyond production; do **NOT** deploy `main` or save Render env via the connector. Verify a restore/rollback path, database backup and migration dry-run on a disposable restored copy; never migrate the actual production DB to test.
2. **Pin the owner.** The authenticated owner may retrieve their numeric Canovia user ID via an authenticated UI or first-party account data view (do not guess). Set `CANOVIA_MCP_AP_PILOT_OWNER_ID` only to that exact value in the **approved** deployment. Never put email, Auth0 subject, GitHub ID or Plan ID in this variable. This numeric value should not be published in a public discussion or repository.
3. **Review identity infrastructure.** The Auth0 **resource identifier** must be the exact new HTTPS host + `/api/mcp`, and the Auth0 application's allowed callbacks/resource/audience/issuer must be bound to that host. Ensure the *Canovia confidential account-link client* and *ChatGPT's client* remain distinct and share the correct verified GitHub primary subject. Do not copy staging token-signing keys, HMAC secrets, synthetic credentials or database into production.
4. **Read-only first:** behind OFF-by-default flags configure `CANOVIA_MCP_DISCOVERY_ENABLED`, `CANOVIA_MCP_TOOLS_ENABLED`, `CANOVIA_MCP_PLAN_CONSENT_ENABLED`, `CANOVIA_MCP_DELEGATED_POLICY_ENABLED`, GitHub-only account link, one exact client/resource, `CANOVIA_MCP_STUDY_READ_ENABLED`; **leave summary/write flags OFF initially**. Set flags only during an approved, SHA-pinned release. Confirm resource metadata, 401 challenge, read-only `tools/list`, wrong-owner deny and fresh login.
5. **Owner-selected Plan:** owner explicitly identifies an existing private AP Study Plan; preserve actual Canovia plan and task data. After the owner confirms GitHub authentication and per-Plan consent, verify `overview` first, then `tasks`. Wrong Plan / Tasks without Tasks grant must deny. Never enumerate private Plans or infer the owner's Plan ID.
6. **Assessments read phase:** enable `CANOVIA_MCP_STUDY_SUMMARY_READ_ENABLED` only after explicit approval. Re-consent `study_progress`. Verify only *applied* native attempts and already owner-approved external results are summarized. No raw problems/answers/assessment text. Downgrade/revoke and verify denial on preexisting JWT.
7. **Write proposal phase, separately approved:** register **`canovia.study.write`** as an Auth0 API permission and explicitly authorize it for the **correct** ChatGPT OAuth client; refresh/re-authorize the ChatGPT plugin. Enabling `CANOVIA_MCP_STUDY_RESULT_PROPOSALS_ENABLED` does **not** create that OAuth permission, request it automatically from Auth0, or give the current staging plugin real-data capabilities. Verify a real newly issued signed bearer has both the existing read and new write scopes before enabling. Create a 10-question **pending** proposal, confirm it in Canovia's Account UI; verify *only after confirmation* that the aggregate includes it. Reject + expiry + replay + revoke + wrong-owner cases must deny. The tool does **not** automatically advance Study Tasks or grade correctness.
8. **Rollback and non-interference:** turn OFF new Study/read/write flags and confirm MCP no longer sees Study tools; previously consented Development-only and synthetic staging behavior must remain unchanged. Revoke the pilot Plan's consent separately if desired. No broad customer opt-in until an independent security/privacy review.
9. **Every release action requires an explicit owner approval** identifying Render service, exact Git SHA and flags. The Render connector's env mutation previously triggered an **unpinned moving-main deployment even with AutoDeploy OFF**; therefore use the Render Dashboard **Manual Deploy → Deploy a specific commit** and do not use unpinned API deploy/update to accomplish this.

## Known limitations to resolve rather than claim complete

- The current Staging ChatGPT plugin points to a synthetic-only service. It is not and must not become the personal AP account connector.
- A private `CANOVIA_MCP_AP_PILOT_OWNER_ID` acts as a server-side feature fence, but the signed IdP token and per-Plan owner consent remain the actual authentication/authorization.
- The shared MCP protocol retains legacy `canovia.development.read` for existing verifier flows. Split independent Study OAuth resources/scopes before wider public use.
- The client cannot write results without a separate, **real Auth0-granted** `canovia.study.write` bearer. Private plugin creation and end-user OAuth authorization will require user action.
- The current code does not update native exercise questions/answers/Task progress, and external user-approved scores are not externally verified.

**Status:** Preparing a fail-closed private owner pilot gate and CI only; NOT a production deployment, real-user read/write activation or completed user-facing AP integration.

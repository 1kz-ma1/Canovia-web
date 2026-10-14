# Isolated Staging: privacy-safe ChatGPT identity guide release candidate

Date: 2026-10-14. Status: **REVIEW / CI; NOT LIVE, NOT DEPLOYED**.
Scope: one owner-approved, synthetic-only Canovia MCP Staging tenant and Plan ID 1.

## Why this isolated release is different from main

The Render staging service currently has a manually pinned Live diagnostic SHA `9a01312c9aa20f3f3814e47fc334a9edcb0feb65`. It includes *temporary* `McpStagingPlanReadDenialDiagnostic` logging from draft PR #571. The normal `main` branch has diverged with unrelated product changes and **must not** be used as the staging manual-deploy target.

This candidate starts **directly** from the verified **pre-diagnostic** MCP Staging SHA `366fc03126183ebd70e850e4746b93371d903bca`, keeping its sealed Auth0/RS256/JWKS token, client mapping, strict subject-fingerprint link, synthetic fixture policy, CSRF+PKCE+explicit Plan consent, scope/expiry, revocation and generic fail-closed read behavior.

Only the following minimal additions are intended:
- The 26-line staged owner Account view guide from reviewed, merged PR #575: distinguish Canovia subject linking, independent Plan consent and separate ChatGPT OAuth login, including the risk of browser Auth0 SSO Google/GitHub mismatch.
- Its 70-line synthetic in-memory feature regression for disabled, active and revoked states.
- A **dedicated isolated release CI** checking PHP/Blade, existing consent/read/revoke regressions and an exact staging Docker image build; asserts the temporary Plan-read diagnostic class and callsites are **absent**.
- This dated operator handoff.

The main merge commit for this UI is `8964e85bc19c3b3148d2689577fd55bb63ad87ee`. That commit is **evidence of code review, not a safe staging deploy target**. Inspect the final GitHub PR head SHA of `release/staging-mcp-identity-guide-20261014` after CI. **Do not deploy the floating branch or the moving `main`.**

## Previously verified live acceptance (do not recreate private grants)

See `MCP_CHATGPT_STAGING_REAL_OAUTH_READ_REVOCATION_ACCEPTANCE_2026_10_14.md` on `main` (PR #573). A real ChatGPT OAuth connection first failed safely with a Google subject while Canovia's independent Link client used GitHub. Correcting the ChatGPT subject permitted a scoped synthetic `overview` and `tasks` read. The owner revoked the synthetic grant; both scopes then correctly denied. **Leave it revoked**. No real personal Canovia data has been approved for external AI reads.

## Required operator and CI gates

1. Compare the release PR against `staging/mcp-identity-guide-release-base-20261014`, which pins SHA `366fc031...`. Confirm exactly the view, its feature test, the release-scoped CI and this document changed. In particular `McpDelegatedPlanAccessPolicy.php` and `McpReadOnlyContextTool.php` must be **byte-for-byte identical** to that pre-diagnostic base.
2. Require every **relevant and required CI** green, including the separately targeted synthetic Plan and grant security regressions. Resolve failures rather than bypassing status checks. The limited simulated test harness does not prove real Auth0 sign-in behavior.
3. Do **not** merge the special staging-only PR into main, do not deploy its PR merge commit, do not change Auth0 client registrations, secrets, DB/network permissions, cost plan, or Render flags. Do not create/restore grant or raw token diagnostics.
4. Only with **separate explicit owner approval of the exact candidate SHA**, go to **Render My Workspace → canovia-mcp-staging → Manual Deploy → Deploy a specific commit**. Enter the approved full SHA, not the branch name or current main. Keep Auto Deploy OFF. The staging service must still use the same isolated pinned Render Postgres, Dockerfile.mcp-staging, and existing verified flags. Never promote or mutate production.
5. Post-deployment: verify Render's returned **Live SHA** matches the approved candidate and the service is healthy; verify the staged `/account` page still permits only the synthetic authenticated owner and shows the guide with **Canovia link = linked, Plan grant = no active permission**, and without claiming ChatGPT identity is visible.
6. Perform only one real connected `plan_id=1, scope=overview` read after revocation; the correct result is generic `Plan unavailable or not authorized for the requested scope.` with NO private Plan data. Confirm no `mcp_stage_plan_read_denied` category from the temporary diagnostic on this new SHA (do not confuse old historical logs).
7. If anything unexpected occurs, use the known pre-diagnostic commit **`366fc03126183ebd70e850e4746b93371d903bca`** as the stage-only rollback target; both the release and rollback must preserve revoked grant state. Always verify the actual Live SHA and deny result.

## Remaining separate roadmap

This change is **pilot-only UX guidance**, not an actual same-external-subject cross-client introspection service and not general-purpose user identity/account linking. Future production onboarding must be separately designed around authenticated owner status, explicit per-Plan grants, precise non-sensitive state guidance, independent ChatGPT reauthorization, revocation and audit, multi-user/privacy/rate-limit boundaries. This candidate must not be interpreted as a production or real-user deployment authorization.

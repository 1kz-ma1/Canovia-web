# Isolated Canovia MCP staging: determine Plan 1 read rejection after verified consent

Date: 2026-10-14
Status: **DIAGNOSTIC IMPLEMENTED IN DRAFT PR ONLY — NOT DEPLOYED.**
Target: exact live staging SHA `366fc03126183ebd70e850e4746b93371d903bca` as base; do not deploy moving main or change production.

## Actual observations

- Synthetic Canovia Plan consent succeeded: the owner saw the exact successful Plan scope-and-expiry approval screen, which the deployed controller only emits after a successful grant transaction.
- First ChatGPT tool call to `plan_id=1`, `scope=overview`, later `tasks`, then another `overview` after allowing all tools and reconnecting the ChatGPT connector with GitHub/Auth0, all returned `Plan unavailable or not authorized for the requested scope.` No private Plan/Task data was delivered.
- The connected MCP tool itself is accessible; a JSON-RPC `tools/call` denial is returned via HTTP 200. That status does not demonstrate authorized access.
- In the pinned code, consent scope `tasks` **includes overview**, so a `tasks` grant should allow both. The active grant is bound to exact actor, linked subject, Plan, client/resource fingerprint, scope, status and expiry.
- Reconnection with GitHub eliminates the simplest Google browser-session explanation as a sufficient diagnosis. It does not prove the access-token `sub` or client/resource fingerprint matches the stored link and grant.
- The original issue remains unresolved; the existing production/sharing controls must not be bypassed.

## What the proposed diagnostic adds

Only after the existing MCP token verifier has cryptographically verified the bearer and the signed principal is handed to the read-only tool, return the **same** error on denial while attempting to emit exactly one fixed category on isolated, explicitly approved staging:

- `plan_not_found`: exact ID does not exist in that staging DB.
- `policy_precondition`: gate/trust mode/resource not ready.
- `principal_constraints`: exact signed principal vs configured issuer, audience, client, scope, expiry mismatch.
- `fingerprint_unavailable`: required server HMAC fingerprint configuration invalid.
- `linked_identity_mismatch`: no active linked `issuer,sub` fingerprint matches the verified MCP principal.
- `plan_owner_or_category`: Plan not personally owned by linked synthetic actor, wrong category or collaborative.
- `stage_fixture_mismatch`: Plan is not the one approved immutable synthetic fixture.
- `grant_client_binding_mismatch`: an otherwise active grant exists for this linked actor/Plan but not the verified MCP client+resource binding.
- `grant_missing_or_inactive`: no active unrevoked/unexpired grant for this actor/Plan.
- `scope_denied`: stored grant scope does not authorize requested scope.

No actual Plan ID/title, issuer, subject, fingerprint, client ID, token, Authorization header, JWT claims, IP address, request body/URL or exception content appears in diagnostic log records. The diagnostic logs are server-only; public HTTP responses remain the same generic error for every denial.

### Guard

The fixed-category log is disabled unless all are true: `APP_ENV=staging`, `canovia_staging.isolated`, `synthetic_read_pilot_enabled`, `mcp_explicitly_approved` and `canovia_mcp.tools_enabled`. Extra read-only metadata existence check for a client/resource binding mismatch is performed only under those flags and only after principal+link+Plan authorization conditions pass; no additional lookup in production or disabled staging.

## Required gates / rollback

1. Exact-head GitHub Actions syntax, synthetic policy negatives, no-log-on-production tests, existing OAuth/MCP grant and tools/call tests must pass; perform full diff security review.
2. **Draft PR only**. No merge to main and no stage deployment under this task; a separate owner-approved, pinned staging deployment is required for this temporary diagnostic. Auto Deploy must remain OFF. Production Render/Aiven/Auth0 settings, real accounts and data remain untouched.
3. If explicitly approved and safely deployed later, issue **one** ChatGPT `overview` read of owner-supplied synthetic Plan ID 1. Correlate by operator time using only the fixed category. No fresh sharing approval unless the category requires it. Check whether active grant UI still shows the same synthetic Plan scope and expiry.
4. Fix the identified condition with separate reviewed patch, then verify both `overview` and `tasks` according to explicit grant; revoke the grant and verify a subsequent actual ChatGPT tool call denies.
5. Remove temporary diagnostics after root cause verification, and restore previous exact Live staging SHA `366fc031` if unexpected side effects are observed.

## Sources

- `app/Services/McpDelegatedPlanAccessPolicy.php`
- `app/Services/McpReadOnlyContextTool.php`
- `app/Services/McpStagingPlanReadDenialDiagnostic.php`
- `tests/Feature/McpStagingPlanReadDenialDiagnosticTest.php`
- `docs/development/MCP_EXPLICIT_PLAN_CONSENT_CONTRACT.md`

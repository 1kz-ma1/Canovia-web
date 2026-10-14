# Isolated Staging MCP consent UX — scope preview → one-click confirm → complete / use ChatGPT

Date: 2026-10-14. Release candidate **NOT LIVE / NOT DEPLOYED**. Synthetic staging owner and Plan only.

## Pinned release boundary

- Present isolated Render staging Live commit: **`477e5f2ba8accb922c927b6dfd3cd977edf45e65`** (already tested for revoked Plan 1 denial). Stage Auto Deploy OFF.
- Exact parent baseline `staging/mcp-consent-preview-release-base-20261014` points only to the current Live SHA. Proposed release branch `release/staging-mcp-consent-preview-20261014` is NOT moving `main` and must never be deployed by branch name.
- The ONLY runtime diff is `resources/views/auth/staging-pilot-account.blade.php`; dedicated assertions live in `tests/Feature/McpStagingSyntheticPlanConsentPilotTest.php`. A scoped CI and this handoff document are the only extra files.
- Source of view/test UX change is main-targeted PR #582; no OAuth/PKCE/token/subject mapping, grant policy, DB, stage/prod flags, config or Render settings are changed. The staging-only DB and strict immutable synthetic actor/Plan enforcement remain in force.

## Three mutually exclusive authenticated owner states

1. **Fresh OAuth verified, pending explicit consent (<= 300 seconds)**: verified subject matches already-linked actor and one approved synthetic Development Plan; show only the actual approved **preview of Plan name and (for tasks) at most 5 current whitelisted Task title/status/recorded progress examples, plus scope and time period**. Show large `この内容で共有を許可する` form and secondary `今は共有しない` cancel. No setup wizard or duplicate scope form. This state **does not grant anything**; only existing CSRF-protected server POST commits the scoped grant, consuming pending verification exactly once. HTML escapes owner data; no descriptions, evidence or GitHub data.
2. **Active and unexpired grant**: `計画の共有を許可しました` with the actual stored scope and expiration, honest explanation of what ChatGPT MAY read if separately authenticated as the same subject, and sample Plan-ID question. Keep revocation clearly discoverable and a closed native `details` for optional scope/duration changes; no default duplicate consent form, no claim that ChatGPT has actually connected.
3. **No grant / revoked / expired confirmation**: normal one-time account link and explicit fresh Plan approval steps; no positive grant status, no stale preview. Distinct pending account-link confirmation remains separate from Plan sharing.

The existing server-whitelisted `DevelopmentPrivateAiContextPreviewService` used by MCP is the source of the preview data, only after the owner/fixture/verified identity/scope/expiry preconditions were checked. Preview does not issue a token, grant, audit record or MCP call.

## Acceptance / rollback gates

1. Compare release branch against its pinned exact stage base. Confirm **only four files** changed: view, feature test, scoped CI and this runbook. Runtime OAuth callbacks, controllers and auth/DB/whitelist guards must match exact base.
2. All scoped CI must pass: PHP syntax/Blade compilation, OAuth fail-closed checks, owner-link and Plan scope/grant tests, preview over-description exclusion and expired session/unauthorized no-preview cases, isolated Docker image build/Nginx configuration.
3. Keep owner synthetic Plan 1 grant revoked unless owner deliberately reauthorizes **for later positive manual UX acceptance**. Do not regrant by tool, database or deployment side effect. Do not enable production MCP.
4. After **separate owner approval of the fully reviewed exact release HEAD SHA**, manually choose **Render → My Workspace → canovia-mcp-staging → Manual Deploy → Deploy a specific commit**. Never deploy moving main or a PR merge ref.
5. On Live, verify exact SHA and stage process health; authenticated synthetic owner should see ordinary unshared entry while grant is still revoked. Re-run a single real connected ChatGPT `overview` read and expect the usual generic denial; stage must not expose private Plan/Task values.
6. For manual positive UX review only if owner explicitly elects to reauthorize, complete the existing Auth0 Plan consent flow with the approved synthetic Plan and observe the focused preview screen; confirm explicit approval shows completion-first screen. Separately reverify ChatGPT identity and read scopes, and revoke immediately afterward.
7. Rollback only Staging to known prior successful `477e5f2ba8accb922c927b6dfd3cd977edf45e65` if needed; verify continued owner grant revocation. Preserve My Workspace/Render Postgres pin and Auto Deploy OFF.

**Not a public feature:** no automatic ChatGPT OAuth onboarding, no customer data authorization, no production deploy, no paid service or changes to access revocation mechanics.

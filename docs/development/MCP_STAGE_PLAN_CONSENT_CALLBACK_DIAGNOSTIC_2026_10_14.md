# MCP isolated staging: Plan-consent callback fails after successful Auth0 proof

Date: 2026-10-14. Status: **DIAGNOSTIC ONLY — DRAFT, DO NOT DEPLOY WITHOUT SEPARATE REVIEW.**

## Proven incident

- ChatGPT's separate MCP OAuth connector now discovers `canovia_get_development_plan_context` on pinned stage SHA `8469c4372c829169525885522e2a9d31ab824bb3`, and an unconsented `plan_id=1, scope=overview` test returned `Plan unavailable or not authorized for the requested scope.` No Plan details disclosed.
- Owner selected consent flow on the isolated synthetic Plan. Twice the UI displayed `外部IDの本人確認を完了できませんでした`.
- Render logs at **2026-10-14 09:48:50 and 09:49:40 JST** show fixed code `link_callback_plan_scope` after successful OAuth callback processing (no token-, issuer-, or state-rejection categories). By code path this means the signed access token passed verification and the Plan consent post-token condition rejected.
- Existing code groups into one failure marker: immutable stage fixture/pilot eligibility; consent setting; exact enum scope and duration; personal Development ownership; matching the **currently linked, active external subject fingerprint**. Those are distinct conditions and cannot be distinguished from available logs.
- Owner-specific external Auth0 subject and session data were never requested, logged, or copied. Direct database query from hosted Render integration is blocked by empty IP allowlist. **Do not weaken database firewall or enable broad access**.

## Change — fail-closed diagnostics only

- Split only the existing fixed `link_callback_plan_scope` failure into six allowlisted categories:
  - `link_callback_plan_fixture_missing`
  - `link_callback_plan_stage_guard`
  - `link_callback_plan_consent_disabled`
  - `link_callback_plan_scope_invalid`
  - `link_callback_plan_owner`
  - `link_callback_plan_link_mismatch`
- Same checks, in the same order, deny the same inputs. Public error remains generic. Callbacks still one-time, session and PKCE validated, Auth0 RS256/JWKS and scope checked before consent, and Plan sharing still requires separate authenticated final POST.
- `McpStagingOAuthFailureDiagnostic::record` only emits literal codes when APP_ENV=staging and isolated/login/link pilot flags enabled. No claim, fingerprint, token, user ID, subject, Plan title/ID, OAuth code, state, URL or request data in logs or HTTP responses. Production suppresses the log.

## Verification & next gate

1. Require exact-head PHP syntax and `McpStagingOAuthFailureDiagnosticTest`, `McpStagingSyntheticPlanConsentPilotTest`, `McpOAuthAccountLinkTest` and `McpExplicitPlanConsentTest` green.
2. PR remains draft, based on the **already deployed stage SHA `8469c4372c829169525885522e2a9d31ab824bb3`**; never merge into `main` and never use moving-main deployment. Before a stage-only manual deploy, obtain explicit owner approval and pin reviewed SHA, Auto Deploy OFF.
3. Retry the synthetic Plan consent once, only with the correct Auth0 identity. Inspect the resulting single fixed Render log code. If it is `link_callback_plan_link_mismatch`, verify the user signed into the **same Auth0 account** that they linked previously. **Do not remove links or relax subject checks** to force a pass. Any re-link UX must be separately evaluated.
4. If one category identifies a different guard, address only that guard with evidence. Positive consent and real ChatGPT `tools/call` acceptance still unverified.
5. Rollback with prior pinned SHA and keep the synthetic Plan grant revoked until the user explicitly authorizes scope/duration. Production and Auth0 configuration remain untouched.

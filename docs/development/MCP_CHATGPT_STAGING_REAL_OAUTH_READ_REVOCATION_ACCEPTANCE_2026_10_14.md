# Canovia MCP staging — first real ChatGPT OAuth → Plan/Task read → revocation acceptance

Date: 2026-10-14 (JST). State: **ACCEPTED for the single isolated synthetic staging fixture ONLY**. This is not production, general user onboarding, App Store, deployment promotion, or a proof of all concurrency/security edge cases.

## Exact environment and trust boundaries

- Isolated service: Render `canovia-mcp-staging` in confirmed *My Workspace*, service ID `srv-db43l4nlk1mc73emseig`. Isolated Render PostgreSQL (not production Aiven).
- Approved immutable fixture: personally owned synthetic Development Plan ID `1` with exactly two invented Tasks; no real user Plan/Task content.
- Auth0 issuer: the separately operated `canovia-mcp-dev` tenant. Its confidential Canovia account-link client and ChatGPT third-party OAuth/CIMD client are **distinct clients**.
- Positive/negative connected read proof was observed on pinned **Live staging SHA `9a01312c9aa20f3f3814e47fc334a9edcb0feb65`**. This SHA includes a **temporary stage-only logging diagnostic from draft PR #571** (not part of `main`).
- Pre-diagnostic pinned Live staging SHA: **`366fc03126183ebd70e850e4746b93371d903bca`**. Do not substitute moving `main` for rollback. Render Auto Deploy remained OFF.
- Only server-owned delegated, read-only MCP Context was returned. No external agent mutation, GitHub merge, CI, app completion or production deploy is implied by Context records.

## Direct evidence and outcome

| Approx. time (2026-10-14 JST) | Test | Observed result | Acceptance |
| --- | --- | --- | --- |
| 11:30:47 and 11:46:15 | `plan_id=1, scope=overview`, while distinct external subject IDs were in use | JSON-RPC generic denial, stage log `linked_identity_mismatch` | **PASS — wrong identity cannot read** |
| 12:00:01 | `plan_id=1, scope=overview`, after correcting ChatGPT OAuth login identity | Tool returned schema `canovia.mcp.development_plan_context.v1`, synthetic Plan title, no Tasks, `delegated_read_only` | **PASS — approved overview is readable** |
| 12:00:12 | `plan_id=1, scope=tasks, limit=5`, with approved `tasks` grant | Tool returned same Plan title and two synthetic Task title/status/recorded progress fields, `truncated=false` | **PASS — approved Task metadata is readable** |
| 12:02:14 | Following owner-initiated grant revocation in stage Account, retry `overview` and `tasks` | BOTH calls returned the same generic denial with no data; two `grant_missing_or_inactive` stage-only log markers | **PASS — revoked grant stops subsequent reads** |

The denial text was identically `Plan unavailable or not authorized for the requested scope.` for both wrong-subject and revoked-grant cases, preserving resource enumeration resistance. HTTP 200 for JSON-RPC envelope is **not** evidence of authorization; only actual returned Context/denial establishes the result.

The owner explicitly selected `概要＋Task` (stored as `tasks`), which permits both `overview` and `tasks` in the deployed policy. The second scope was tested only after that express grant.

## Auth0 dual-client issue and user-experience lesson

The owner privately inspected two same-day Auth0 *successful login* events associated with **different OAuth clients**:

- Canovia confidential **MCP Staging Link**: GitHub social connection, GitHub immutable subject.
- ChatGPT third-party OAuth client: Google immutable subject, with an empty prompts array, consistent with existing Auth0 SSO session reuse rather than an interactive Github login.

These distinct subjects explain the observed `linked_identity_mismatch` until the ChatGPT OAuth identity was corrected. **Logging into GitHub for Canovia's account-linking/Plan consent is not equivalent to logging the separate ChatGPT OAuth client into that same subject.** Two matching emails are not valid proof of identity. No permission bypass, subject rewriting, HMAC reset, email-based linking, or automatic merging was necessary.

Future UX (NOT implemented by this acceptance):
- Make the separate Canovia-link and ChatGPT-plugin sign-in steps explicitly visible; tell the user to choose the *same IdP account* in both.
- Provide an owner-authenticated, privacy-safe `Connected / Needs Reauthorization / Plan shared / Revoked` status; never display raw token, subject or fingerprints.
- Guide ChatGPT plugin reauthorization with the browser context/Auth0 SSO session in mind. No generic "enable all tools" fix and no forced provider bypass.
- Keep generic MCP denial responses; provide precise diagnostic codes **only server-side** and only in deliberately isolated, time-bounded staging tests.

## Negative and scope limits

- The subject-mismatch test was not an adversarial cryptographic penetration test.
- Revocation was verified **on later distinct read requests**, not on reads racing a simultaneous revocation transaction.
- No production read, real personal user account, cross-device/mobile integration, session expiry, expired grant, large Task truncation, GitHub-linked evidence, billing or public authorization scale was tested.
- No formal audit of Auth0 connection enforcement, tenant-wide provider policy, rate limits or error-rate monitoring was performed.
- Private Plan data was never requested: all Context came from the immutable staging-only synthetic fixture.
- No tool action created, revoked, renewed or mutated a grant; the **owner** did so through Canovia's authenticated Staging Account UI.

## Temporary diagnostic cleanup / operator-only exact-SHA rollback

At time of this record the **Live stage still runs `9a01312c...`**, which includes temporary fixed-category denial logging. Its source resides only in draft PR #571; `main` does not contain `McpStagingPlanReadDenialDiagnostic`. Reverting the isolated stage to the *pre-diagnostic* SHA `366fc03126183ebd70e850e4746b93371d903bca` removes this **temporary read-denial category logging** without changes to main/production or any database state, while retaining the previously implemented OAuth/read/grant logic.

**No automatic rollback is authorized by this documentation.** Separate explicit owner approval is required for a manual Render deploy of that exact SHA; verify workspace/service/target SHA, Auto Deploy OFF, stage configuration unchanged and owner grant still revoked. After rollback, smoke-test the MCP endpoint's generic fail-closed denial on `plan_id=1, scope=overview` and verify the Live SHA. Do not infer a new grant or restore the revoked one. Never change Render Postgres IP allowlist, secrets or Auth0 tenant settings for cleanup.

PR #571 remains an archived staging diagnostic evidence source unless separately closed; it must not be casually merged into `main`. Other historical staging login-failure diagnostic markers (if any) are **outside this specific rollback** and should be reviewed separately before any broader cleanup.

## Follow-up gates before enabling production or real personal Plans

1. Remove all temporary staging-only debugging through reviewed, exact-SHA deployment; verify the revoked sharing remains denied.
2. Keep the **connected ChatGPT MCP OAuth client** and **confidential Canovia account-link client** clearly separated and prove same immutable subject before Plan consent. Improve consent UX/status and account mismatch remediation.
3. Complete security review for read-vs-revoke concurrency, token validation/expiry and refresh, finite grants, client/resource binding, rate limits, audit, redaction and per-user revocation.
4. Require a distinct security/privacy review, production configuration plan and owner approval before any real Plan, production Auth0 policy or hosted MCP enablement.

### Evidence pointers

- Draft PR #571 and its timestamped comments: https://github.com/1kz-ma1/Canovia-web/pull/571
- Dedicated isolated-staging diagnostic CI: https://github.com/1kz-ma1/Canovia-web/actions/runs/38500738112
- Original Plan consent contract: [MCP_EXPLICIT_PLAN_CONSENT_CONTRACT.md](MCP_EXPLICIT_PLAN_CONSENT_CONTRACT.md)
- Read-only resource contract: [MCP_READ_ONLY_RESOURCE_CONTRACT.md](MCP_READ_ONLY_RESOURCE_CONTRACT.md)
- Delegated revocation contract: [MCP_DELEGATED_REVOCATION_CONTRACT.md](MCP_DELEGATED_REVOCATION_CONTRACT.md)

# Canovia MCP Staging — GitHub identity isolation and revocation adversarial acceptance

Date: 2026-10-15 JST. Platform / MCP lane. **Offline acceptance candidate only. No Render/IdP/environment changes or live grant revocations.**

## Observed live checkpoint
- Render isolated stage `canovia-mcp-staging`: exact Live `1e93b6ce17b29e0b029b78293462839fdcbd5a80` (PR #645 Draft/unmerged), Auto Deploy OFF.
- Operator confirmed that the authenticated active-grant view shows `新しく本人確認する場合にGitHubを使用します`. This confirms the runtime GitHub-only **configuration for new flows**, not historical identity origins.
- The owner successfully followed **fresh GitHub OAuth → same linked subject → Plan consent preview → cancel**, then the ChatGPT MCP tool read the unchanged synthetic overview Plan ID 1. It did not prove a *different* real GitHub user cannot access, nor that revoke blocks a still-valid bearer.

## Adversarial cases added (disposable in-memory fixture)
`McpAuth0JwtProviderRoutingTest::test_github_only_wrong_signed_identity_cannot_inherit_existing_grant_and_revoke_denies_old_token`

1. Provision exactly one fictional owner, Development Plan, immutable linked `github|410001` subject and **existing indefinite overview-only grant**; use a separate signed ChatGPT JWT client (`CHATGPT_CLIENT`) and confidential Canovia account-link OAuth client (`LINK_CLIENT`).
2. Verify the existing correct, signed ChatGPT JWT can read just the synthetic title, whereas a **different, equally valid RS256-signed JWT `github|410002`** with the same issuer/audience/client/scope **cannot inherit the grant** and cannot expose the title.
3. Start consent change/reverification, assert Auth0 connection selector is GitHub and login is prompted. Exchange a signed confidential-client token for the **wrong GitHub numeric identity**. The callback must **not** create a fresh consent preview, accept a final approval, change the linked subject or revoke the original grant. The original ChatGPT subject remains readable; the wrong subject stays denied.
4. Repeat OAuth with matching GitHub subject. After obtaining a verified consent preview, **cancel**. Neither an extra approval nor a change in active grant may result. Existing reads remain possible.
5. Revoke the synthetic grant in test-only DB. Immediately verify the **already-issued** signed JWT is denied on the next MCP read (and title hidden), and both subjects remain denied. Replaying an old confirmation cannot revive the revoked grant. Audit and link rows must not be dropped.

This is **not live second-GitHub-account or live revoke verification**. It uses test-only generated RSA signing keys, fabricated numeric subjects, mocked IdP HTTP responses, in-memory SQLite and never sends OAuth/token/cookies/Plan data to Render or Auth0. The GitHub-only enforcement on the link *initiation* side alone does not imply that historical links must have GitHub subjects; the test explicitly distinguishes a fresh OAuth flow and a separate, signed ChatGPT token.

## CI and release guard
- PR is based on **exact latest Staging Live SHA** `1e93b6ce17b29e0b029b78293462839fdcbd5a80`; compare incremental diff to that commit.
- Draft PR targets the pinned legacy baseline `release/mcp-staging-live-baseline-20261014` only to run `provider-routing`, `auth0-sealed-config` and `isolated-staging-boot` scoped workflows. Do **not** merge it into that base, #630, #645 or moving `main`.
- This test-only slice needs **no new Staging deployment** or live identity change. Auto Deploy stays OFF. The original synthetic grant stays active in Render until the owner intentionally chooses a separately approved destructive live revoke test.
- Actual real wrong-account and real post-revoke denial are still **PENDING**. Such testing needs a separate truly isolated identity and grant or deliberate explicit owner approval to revoke the existing synthetic shared Plan, followed by external ChatGPT re-authorization/read check. Never perform those operations merely to satisfy an automated checklist.

**Outcome classification:** CI pass = signed synthetic security controls verified. Real wrong-GitHub external login and live revocation remain UNKNOWN, not PASS.

## 2026-10-15 Orchestrator mainline regression integration

The original Draft PR #672 is an intentionally unmerged fixed-SHA staging comparison branch. This follow-up copies **only** its adversarial, disposable in-memory feature test into the latest development `main` test suite. A passing PR HEAD does not mean real Staging wrong-account or live revocation has been exercised. `main` source and current production release controls remain authoritative.

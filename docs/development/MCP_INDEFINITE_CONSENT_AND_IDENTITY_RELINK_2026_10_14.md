# MCP owner consent duration and safe identity reuse — 2026-10-14

Status: implementation slice in development **main target PR**, NOT production authorization. The current isolated MCP Staging deploy remains a separately pinned synthetic-only snapshot.

## A. Until revoked (implemented in first feature slice)

- Sharing period is an **explicit user choice**: 1, 7, 30 days or `until_revoked`. A confirmation still requires fresh Auth0 Account Link OAuth+PKCE identity proof, a <=300-second verified server session, and a separate CSRF-protected final button. Identity linking alone creates no Plan grant.
- `mcp_delegated_grants.expires_at = NULL` encodes indefinite owner consent. Existing dated grants are unchanged. The migration does not backfill any nullable records. It refuses schema rollback while any indefinite grants exist rather than silently changing their semantics.
- The MCP policy authorizes a non-expiring grant **only** while the owner, private development Plan, exact active subject link, ChatGPT client/resource/audience/scope and JWT/OAuth access token remain valid. Signed JWT expiry is still enforced on **every call**; indefinite *Plan consent* never implies indefinite access tokens or permanent account linking.
- A single explicit grant revocation, subject unlink, ownership/collaboration/category changes, revoked subject, wrong client/actor/scope or the global MCP OFF gate denies immediately. Existing serialized DB/lock read path remains in force.
- Public and staged views offer `取り消すまで共有する` as the suggested option, show precise confirmation, active status and revoke button. The owner can change indefinite consent back to finite **only with a fresh OAuth check and final approval**. No background auto-renewal or expansion of scope.
- Limit Staging to its existing synthetic fixture; do not use this release on real Plan data without independent production security/privacy review.

## B. Historical identity migration (separate second slice)

An active GitHub/IdP `issuer+subject` identity must remain exclusive to exactly one Canovia account, but owner-initiated unlink should release that exclusivity for a *freshly verified* link to another owner. The current global unique identity fingerprint constraint rejects this even when revoked. The second slice must preserve the original link and old grants/access events for auditing, never transfer grant ownership, never resurrect revoked grants, and enforce database uniqueness on **active** links only. It requires an independently reviewed schema migration and concurrency coverage.

## Release gates

- Tests: finite expiry, indefinite persistence + explicit revocation, scope downgrade and reconsent, owner-only/synthetic-only gates, malformed session denial, isolated stage image/real DB migration, cross-account grant leakage.
- This is a development change only. No app/Render/Aiven/production deploy or environment flags are authorized by passing CI. Pin and approve a separate Staging release after validation. The next live test must keep revoked synthetic Plan 1 access denied until the owner explicitly reconsents.

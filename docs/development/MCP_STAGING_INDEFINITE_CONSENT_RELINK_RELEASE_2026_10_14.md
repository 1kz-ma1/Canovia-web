# Isolated MCP Staging — until revoked consent and safe verified identity reuse

Date: 2026-10-14. Status: **release candidate only; NOT DEPLOYED**.

## Exact parent and staging protections

At preparation the **actual Live** Render `canovia-mcp-staging` was `8f449e48ad867968516744360b08648e0c8b6bdb`, already containing the approved **scope preview + final consent + completion-first** account UX. The earlier release branch based on `477e5f2...` was discarded as an unsafe/misleading base, because it would have regressed the later preview UX.

This candidate starts from **that exact Live SHA**, with a dedicated base branch `staging/mcp-until-revoke-relink-live-base-20261014`. It selectively carries MCP code already reviewed on development `main` as PR #587 (indefinite grants) and PR #589 (cross-owner relink after unlink), without importing newer unrelated moving-main changes. The old Stage-specific `McpStagingOAuthFailureDiagnostic` refusal branch in the link callback was **retained**, not wholesale replaced by the main callback.

**This candidate stays Draft; do not merge it to main.** The main PRs were already integrated. Render Auto Deploy remains OFF. The production branch, Aiven, Auth0 client registrations, credentials and configuration are untouched.

## Exact user-visible behavior

- Existing short options 1 / 7 / 30 days remain. A new default recommended `取り消すまで共有する` option requires the same fresh verified OAuth / PKCE identity, a five-minute one-time confirmation window, and a **separate preview+final approval**. It saves `expires_at=NULL` on this individual Plan grant, not an indefinitely valid access token or perpetual identity session. Each real ChatGPT OAuth token remains subject to normal expiry and authorization on each read.
- The accepted Plan grant completion screen shows `取り消すまで`, exactly what can be read, how to connect ChatGPT and the visible per-Plan revoke control. The owner can reapprove finite time or scope only through fresh OAuth.
- A separate collapsed `本人確認の連携を解除する` control can revoke the *entire* external subject link plus every old Plan grant. Its Canovia owner must be authenticated; isolated Staging requires the specific synthetic actor. When revoked, `active_identity_fingerprint` becomes NULL but historical fingerprint, old link ID, Plan grants and audit entries remain intact and **revoked**.
- The same verified external issuer/subject may then be linked to a different Canovia account via **fresh** Auth0 OAuth+explicit account confirmation, receiving a new owner-bound link row with no previously granted Plan access. The DB enforces one active linked owner with a UNIQUE active-only fingerprint. The new owner must separately approve any Plan grants.
- Isolated Staging **remains synthetic-owner-only** and cannot be used to exercise an ordinary Canovia account's real Plan. Cross-owner switching is demonstrated by feature/DB tests, not by exposing other real user identities on the locked Stage.

## DB safety / migration

1. `2026_10_14_000100_allow_nonexpiring_mcp_grants.php` makes grant expiry nullable. No existing expiry is changed; `NULL` is deliberately set only when the owner explicitly approves indefinite consent. Its down migration blocks if such grants remain.
2. `2026_10_14_000200_preserve_historical_mcp_identity_links.php` adds active-only unique HMAC identity reservation, backfills existing active rows, creates the new unique index before dropping the old global unique, and keeps a nonunique history index. It can be retried after partial DDL. Its down migration **refuses if a real identity handoff has occurred**, preserving audit.
3. **Operator must verify maintenance/atomic deploy safety**, including not running any old application workers after the global unique is dropped. A stale worker could omit the new active reservation column. No manual DDL outside reviewed migration.
4. Require feature regression tests for authorized Plan reads, denial after revoked grants/subjects, no cross-actor old Plan leakage, unique active claims, unchanged synthetic fixture restrictions, and real PostgreSQL 17 schema migration run twice. MySQL 8.0/8.4 migration integrity on the already-merged main PRs was green; this Staging release has its own PostgreSQL and Docker gates.
5. No agent is authorized to touch production, rotate HMAC keys, change Auth0/Render switches, or create/revoke the owner's real grant.

## Exact release acceptance

- Review the dedicated staging PR against `staging/mcp-until-revoke-relink-live-base-20261014`. Audit each changed file, especially the Stage-only OAuth diagnostic branch, server-owned Plan/Task whitelisting and schema migrations.
- Require all scoped checks green, including real disposable PostgreSQL and Stage Docker image build.
- **Only after separate owner approval of the final exact candidate HEAD SHA**, deploy the reviewed immutable commit to the isolated `canovia-mcp-staging` Render service with **Manual Deploy → Deploy a specific commit**. Never use moving `main`, never enable Auto Deploy, do not manually revoke the already revoked synthetic grant.
- After Live: confirm identical SHA, service process/migrations startup, owner synthetic account screen and 1/7/30-day/indefinite selector, and a real ChatGPT MCP read of synthetic Plan 1 `overview` still returning the generic access denial **while no grant is authorized**. Do not infer tool denial from outer HTTP 200.
- Positive QA (only after the owner's consent): freshly authenticate the synthetic owner, view explicit preview, authorize `取り消すまで`, verify completion and exact scope of ChatGPT reads; revoke Plan grant and verify denial again. To test unlink, owner must understand that all existing Plan grants immediately terminate, and fresh account-link authentication will be required. Do not claim a second real Canovia user is supported on synthetic Stage.
- If a regression is found, roll back the isolated app to previous known Live SHA `8f449e48ad867968516744360b08648e0c8b6bdb` **only after separately verifying DB schema compatibility with the old app**. A DB rollback is not automatically safe once a historical identity is transferred or an indefinite grant exists. Stop and request owner plan if schema-level rollback is needed.

## Production boundary

The Staging promotion would validate only a synthetic Plan and a particular Auth0 setup. Wider account migration and indefinite real-user sharing remain blocked behind separate production privacy, DB, security and consent audits. GitHub PR and CI success are not proof of production operation.

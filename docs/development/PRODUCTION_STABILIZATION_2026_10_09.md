# Canovia Production Stabilization — 2026-10-09

> **Status: proposed recovery operating plan / NOT an implementation, deployment or a production database authorization.**
> Tracking: [Issue #418](https://github.com/1kz-ma1/Canovia-web/issues/418).
> Authority: latest `main`, independently observed Render/GitHub evidence, `AGENTS.md`, [canonical roadmap](ROADMAP.md), [parallel development lanes](PARALLEL_DEVELOPMENT_OPERATING_MODEL.md).
> This is a narrowly scoped incident/recovery plan. It neither replaces the roadmap nor cancels active WIP/future design.

## Decision and scope

Temporarily prioritize **production stability over new feature expansion**. Keep three distinct objectives:
1. Restore production MySQL migration/schema consistency and confirm the application is serving the expected database, without destroying or silently altering user records.
2. Prove the existing-account login and PWA/session continuity actually work rather than treating a UI error message or a `live` deploy as recovery.
3. After P0 clears, validate Study-first core UX and Development read-only flows on real clients; resume MCP IdP/ChatGPT experiments as an isolated, separately approved track.

### Evidence snapshot (2026-10-09 UTC; revalidate before work)

| Evidence | Observation | Status / limitation |
| --- | --- | --- |
| `main` | `b39ba5bbbee8d095041131f4bdba89396067ef95` | Reference at plan creation, not an immutable "latest" claim |
| Production Render | Web `srv-dagfn2id0e5s73cac7ng`, deploy `dep-db47t9h42hec73c8s4g0`, commit `b39ba5b`, marked `live` at 05:43 UTC | **Not** proof of login or migrations |
| Failed production schema migrations | Render error logs at ~05:02 UTC: MySQL 1059 identifier too long for `intelligence_decision_traces_intelligence_state_snapshot_id_foreign` and `learning_answer_evaluation_adjustments_learning_answer_event_id_foreign` | Both migrations use auto-generated `constrained()` FK names. Exact current DB FK presence/partial table state **unknown** |
| Existing recovery guards | `2026_10_04_000200...` validates columns on existing table but not FK/indexes; `2026_10_08_230000...` immediately returns when table exists | Partially applied MySQL DDL can be skipped if a table exists. **Potential**, not confirmed schema corruption |
| Existing CI | Production Migration Recovery workflow runs PHP lint, migration and feature tests in default test database; available V56.11 test checks short names for other (reasoning/action) tables | Passing CI does **not** constitute MySQL 8 full-migration / interrupted-migration acceptance |
| iPhone auth | ~05:40–05:41 UTC after PR #416 deploy: `POST /login 302` followed by `GET /login 200` repeatedly | Login failure still observable. Root cause not yet verified; 302 alone cannot identify password / user / session / DB reason |
| Learning & first-run | PR #413 merged for legacy single-question/immersive header; prior first-run/UI changes merged | iPhone PWA, WKWebView, desktop E2E and persistent resume still separate |
| MCP stage | `canovia-mcp-staging` in owner-confirmed Render **My Workspace** `tea-d4vb3f6mcj7s73djkrqg`, separate isolated PostgreSQL 17 | Synthetic owner provisioning unverified; OAuth, MCP public activation remain OFF. Free DB expiration 2026-11-08 UTC |

**No database password, full connection URL, session cookie, bearer token, APP_KEY, raw user record, or private request body may appear in Issues/PRs/log excerpts.** No production data is copied to the separate MCP staging database.

## Recovery order and acceptance gates

### P0-A — Platform: DB integrity and release safety (first)

1. Reconfirm latest production deploy SHA, `APP_ENV`, intended DB driver/name and service ownership **using nonsecret evidence only**. Do not infer successful schema migration from Render `live` state.
2. Establish an approved, safe, **read-only** schema/migration inventory (version/batch, table/column/index/FK presence, no personal rows). Confirm who can run it and backup/restore availability before asking for any production mutation. Avoid broad connector permissions or exposing Aiven credentials.
3. Reproduce **all** current migrations on a disposable matching MySQL engine (same relevant version as production), twice; verify exact schema FK/index names and absence of MySQL 1059. Include interrupted/partially-created table, missing FK/index and already-valid schema cases.
4. Design minimal recovery that preserves real rows. Use explicit <=64-character FK names, test idempotent constraint reconciliation and named uniqueness; do not rely solely on `Schema::hasTable`. Do not rewrite prior applied schema without comparing actual migration state; account for MySQL DDL implicit commit.
5. Before applying to real data, require full diff/security review, exact-SHA relevant CI, **actual MySQL** ephemeral acceptance, verified backup/restoration path, production change window and rollback plan. No `migrate:fresh`, `db:wipe`, destructive rollback, blanket drops, ad-hoc data copy or silent in-place repair.
6. After a reviewed deploy: separately record deploy SHA, migration result, schema constraints and existing-data invariants. Abort if identity/schema mismatches.

**Exit:** correct schema + migration ledger + required FKs/indexes verified; controlled second migrate is safe/idempotent; no unintended user-data deletion; app health not merely container health.

### P0-B — Platform + Hotfix: existing-user sign-in and PWA continuity

1. Using error-safe observations, distinguish `Auth::attempt=false`, validation failure, 419/CSRF, session write/read, cookie domain/SameSite/Secure, redirect logic and DB/user mismatch. Do not log submitted passwords, identifiers, tokens, session keys or other personal values.
2. Review production auth/session configuration through metadata or private Render UI without exposing secrets; correlate requests by sanitized event IDs and deployed SHA, not raw credentials.
3. Add targeted tests for (a) valid login+redirect, (b) invalid credentials with visible error, (c) login -> reload -> authorized page, (d) logout -> re-login, (e) independent browser / iPhone PWA resume, (f) CSRF and foreign-account isolation. Avoid changing credential validation or session security to make a test green.
4. Manual E2E on current deployed commit: iPhone Safari/PWA, desktop, and WKWebView when available. Isolate browser storage differences and stale service worker state before deleting any user data.
5. Record observed root cause and successful user journey; if only UI message changed, keep `login restored` **unverified**.

**Exit:** an existing account can sign in and resume consistently on supported clients, with unchanged authorization and without unsafe session shortcuts.

### P1 — Learning / Experience: practical first-session correctness

- Verify adaptive and legacy single-question entry, answer persistence, next-question progression and resume; Study mode restoration and immersive header/safe area.
- Verify new-user mobile onboarding overflow, clear 403/500 behavior, blank/unauthorized states and shared navigation.
- Keep Plan/Task ownership, historical attempts, group permissions and other data semantics intact. Domain-owning lanes do not change shared auth/schema during P0.
- Validate on real iPhone PWA / Safari, iOS WKWebView and PC; distinguish not-tested from failed. Small isolated PR per symptom.

### P2 — MCP/OAuth / Keycloak: hold external activation

- Maintain `CANOVIA_MCP_TOOLS_ENABLED=false` and separate staging Web access/OAuth gates OFF.
- No Keycloak service, paid IdP tier, additional Render compute/DB, public registration, production OAuth flags, or ChatGPT external delegated reads while P0 is unresolved.
- Keep isolated synthetic PostgreSQL expiration (**2026-11-08 UTC**) in operational follow-up; no important data in Free DB; no automatic migration to paid resources.
- After P0: private synthetic actor evidence -> provider resource/cost review -> real token PKCE/introspection/single audience/consent/revocation tests -> explicit ChatGPT E2E separately.

## Implementation policy and cross-lane collisions

- One production incident = one scoped `fix/*` PR with separately enumerated reproduction, acceptance, tests and rollback. Latest `main` only, no direct pushes; merge governed by `AGENTS.md`.
- **Platform** owns production DB, auth, release and staging isolation. **Hotfix** handles small reproducible production defects after coordination. **Learning** and **Experience** may test/fix isolated UI but must not concurrently edit production auth/migration/Session or common shell without an owner.
- Required CI must be green at exact head SHA **and tests must match the production engine/risk**. SQLite unit tests alone do not validate MySQL FK length, DDL partial failure or real production credentials.
- `main` merges are potential **automatic Render production redeploys even when only docs change**. During the active migration incident, keep this plan's PR unmerged until a fresh safety review establishes that triggering startup migrations is acceptable. Prefer this GitHub Issue for immediate cross-chat handoff.
- Never claim more than observed: `planned`, `implemented`, `CI verified`, `deployed`, `schema verified`, `login E2E`, `device verified` are independent fields.
- Before switching from stabilization back to feature work, confirm the P0 exit gates, document unknowns and reconcile the canonical roadmap. Retain ongoing WIP documents rather than deleting them.

## Evidence / next handoff

- [Issue #418 — incident register](https://github.com/1kz-ma1/Canovia-web/issues/418)
- [Auth error-feedback PR #416](https://github.com/1kz-ma1/Canovia-web/pull/416) — UI error visibility, not root-cause proof.
- [Learning one-question PR #413](https://github.com/1kz-ma1/Canovia-web/pull/413).
- Next executable **code** slice: production-safe migration repair design + disposable actual-MySQL regression, **before** touching Render env/production DB.
- Related: [state-first prerelease WIP](../wip/2026-10-08_STATE_FIRST_PRE_RELEASE_EXECUTION_SPEC.md), [MCP staging security decision](MCP_IDP_SELECTION_AND_STAGING_POSTGRES_2026_10_09.md).

This draft is ready to review, not to auto-deploy.

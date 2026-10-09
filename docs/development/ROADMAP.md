# Canovia development roadmap

Updated 2026-10-09. **Human-maintained intent; read-only GitHub preview available. PR evidence is optional and bounded; no Task auto-sync.** Repository `1kz-ma1/Canovia-web`.

## Verification semantics
Planned = intent. Implemented = code integrated. CI verified = checks for exact SHA passed. Deployed = that SHA live in an environment. Device verified = explicit device E2E evidence. Unknown is not complete. A merged PR does not establish deployment or device usability.

| Priority | Workstream | Existing evidence | Remaining acceptance |
| --- | --- | --- | --- |
| P0 | Release stability | V58 Workspace/Learning work, PR #373 merged | Confirm specific Render deploy; old/new Learning resume, permissions, autosave, iPhone/PWA/PC E2E |
| P0 | Adaptive Learning | PR #362–#366 single-question events, candidate queue, exam framework, mode recommendation, adjustment; PR #371–#373 legacy resume/immersion | Multiple response types, calibrated evidence/history, real-world study verification; official exam profiles only with verified sources |
| P0 | GitHub-native roadmap | Read-only parser/importer, PR/Issue/CI observation, compact Context endpoint (PR #375–#380); scoped handoff (PR #381); manual revision diff (PR #383–#384); GitHub-first agent pull handoff (PR #385–#386) | Validate actual AI-provider GitHub connector access, real-device copy, actor authorization and provider cost limits; Canovia-private AI delegated API is NOT implemented; no Task bulk JSON mutation |
| P1 | iOS | V52.0 SwiftUI/WKWebView bridge foundation | Latest regression E2E, signing, TestFlight, StoreKit purchase/restore |
| P1 | Pricing / telemetry | ProductKey/Entitlement, BehaviorEvent and AI telemetry | Numerical prices/allowances, MRR/MAU/AI cost/gross-margin dashboard |
| P1 | Evidence-first Plan Draft | V58.71–V58.76 work described in [active plan](../wip/2026-10-08_STATE_FIRST_PRE_RELEASE_EXECUTION_SPEC.md) | Verify actual main and approval/notification flows; cost limits, multiple candidates |
| P2 | Bookkeeping | V58.61–V58.62 initial diagnosis and journal input | Expand question coverage and spaced review |
| P2 | Career | Career Capture, Interview Review, V58.60 exploration | E2E and experience-based exploration; user-confirmed MATCH plus import |
| P2 | Development automation | GitHub App + evidence/collaboration; GitHub-first AI handoff in PR #385–#386; owner-only Canovia-private Context preview, ChatGPT sharing preparation (scope/expiry/revoke + account recovery, NO token; PR #388) | OAuth resource discovery/deny-all foundation (PR #389); RFC7662 token verifier (PR #390); inactive external-subject/Plan policy (PR #391); session-only grant/link revocation + audit (PR #392); disabled Canovia actor/IdP linking via PKCE (PR #393); explicit fresh-OAuth per-Plan consent (under test); read-only MCP JSON-RPC + whitelisted private Context and per-read consent/audit (under test; OFF); IdP compatibility and isolated-staging preflight (under test; no new infrastructure); opt-in Canovia-only Docker/Render staging bootstrap with fail-closed DB/HTTP guards (under CI; not provisioned); next inspect Free-hour budget and approve separate stage, durable stage DB and real IdP tenant, ChatGPT OAuth/CIMD callback and end-to-end subject/audience/grant acceptance, actor/Plan/repo revocation/audit, idempotent PR/Issue/Commit evidence, team authorization, no false completion |
| P2 | Launch | Existing branding/X assets | App Store legal/assets, TestFlight, release and retention validation |

## Next slices
1. Verify production deployment and iPhone/PWA/desktop Learning regression at an exact SHA.
2. Validate read-only Markdown preview and the user-triggered scoped AI Context copy on a real device, using a linked public GitHub repository; record repo/path/SHA and unknowns.
3. Validate explicit PR/Issue references and available Checks on demand; separately verify deploy and device behavior. Validate manual SHA-pinned revision comparison and GitHub-first AI pull with an actually connected coding AI.
4. Design a consent-based, revocable delegated external-AI read API only if GitHub-first pull cannot meet private-Context needs. Keep Plan/Task history private. Consider opt-in, reviewable execution Task suggestions **only after** user validation.

2026-12-01 is an initial release-readiness target, not proof of launch. Business success means Canovia-only subscription revenue >= JPY 230,000/month for several months.

References: [Product Spec](../CANOVIA_PRODUCT_SPEC.md), [Learning draft](../learning/adaptive-learning-experience-draft.md), [GitHub-native contract](GITHUB_NATIVE_ROADMAP_CONTRACT.md), [Future architecture](../future/CANOVIA_FUTURE_ARCHITECTURE_OVERVIEW.md).


### Render public-launch service plan (owner decision, 2026-10-09)

At Canovia public launch, upgrade the **production Web service compute** from Free to Render's lowest paid tier (currently $7/month compute on Hobby; confirm pricing immediately before purchase). This is not an immediate approval of a paid staging service, workspace Pro subscription, or additional paid database. Keep Canovia MCP staging physically isolated and perform a separate budget review before provisioning. See [staging bootstrap](MCP_ISOLATED_STAGING_BOOTSTRAP_CONTRACT.md).


### Canovia staging Docker runtime smoke (2026-10-09)

A disposable GitHub Actions job now **builds and boots** the independent staging Docker image with SQLite and a generated test-only APP_KEY, exercises startup guard, migrations, `/up` and the 503 OAuth/MCP lockdown. This is a more realistic check than the earlier unit tests, but **not** a live Render staging deployment. Production Canovia still uses a shared 750-hour/month Free Web instance pool and Render account-level remaining hours cannot be read through the connected service tools; defer creation of a second always-on Free Web instance until the owner verifies remaining hours/budget or approves the early lowest-paid production upgrade. Product owner's agreed paid production launch policy remains unchanged.


### Live Canovia MCP staging smoke provisioning (2026-10-09)

Product owner approved short-lived Free-only staging after the Render
billing snapshot showed 48.78/750 monthly Free hours consumed. A separate
`canovia-mcp-staging` Free Web service was created in Singapore, ID
`srv-db43l4nlk1mc73emseig`, autoDeploy OFF, with staging-only Docker/
SQLite/APP_KEY, all OAuth/MCP features OFF. Render's first deploy of
`d5865f8` is **live**, with migrations confirmed via Render logs. An
external GitHub Actions smoke (run `37867353868`) **passed**:
`/up=200`, login/account/OAuth/MCP GET and POST `=503`. No extra paid subscription, production service,
Aiven database or real ChatGPT/IdP connection was changed. Render Free
auto-idles after 15 minutes of no traffic: no keepalive automation.
See [isolated staging contract](MCP_ISOLATED_STAGING_BOOTSTRAP_CONTRACT.md).


### V59: Provider selection and short-lived staging PostgreSQL (2026-10-09)

A separate **Render Free Postgres 17** resource `canovia-mcp-staging-db` (id `dpg-db43rbbncjis73bmigi0-a`) is **available**, with hard expiration **2026-11-08** and no Free backups. Production Aiven and production Render services remain unchanged. The staging Canovia Web is STILL on SQLite and **has not been linked** to PostgreSQL; external DB IP allowlist stays empty. New staging-only Docker `pdo_pgsql` and strict pinned resource/hostname/DB guard require explicit reviewed cutover before connecting the staging Web. OAuth/MCP remain OFF.

**Keycloak** is the conditional technical IdP frontrunner because of RFC 7662, DCR and experimental RFC 8707 resource support, but no provider has been provisioned. Its memory requirements make another Render Free 512MiB service an unsafe assumption. Auth0's JWT verification approach and ZITADEL's DCR resource audience mismatch require different reviewed architectures; do not relax the existing token checks. See [decision record](MCP_IDP_SELECTION_AND_STAGING_POSTGRES_2026_10_09.md). Next: confirm exact internal DB hostname privately, attach DB URL only via Render staging Environment (never in GitHub/chat), run real staging migration, then decide separate IdP funding/hosting and synthetic user flow. No production paid plan change until public launch.


### V59 staging PostgreSQL real-migration smoke (2026-10-09)

The dedicated Free staging PostgreSQL exists but is not yet connected
to the Canovia staging service. A separate GH Actions disposable Postgres
17 + staging Docker smoke now runs the complete Laravel migration stack
twice and checks that all MCP tables exist. This is a DB-engine
compatibility test **without** private credentials or any live database.
Do not use successful CI as proof of actual Render PostgreSQL connectivity.


### V59 PostgreSQL readiness hardening (2026-10-09)

The actual created Render Free PostgreSQL DB remains **unattached**.
The isolated staging `/up` now includes a **real database+schema
health check only after Postgres mode is deliberately enabled**:
wrong resource/host, unavailable DB or missing MCP tables -> generic
HTTP 503. The CI Docker smoke now starts a full second staging instance
against a fake disposable PostgreSQL using the exact production-like
host/ID guard and checks `/up=200`, private routes=503.
Default live SQLite mode and prod `/up` remain unchanged.

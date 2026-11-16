# Future Online Support — F-0 contract / isolated F-1 candidate

2026-11-16 JST. **SECURITY_BLOCKED / PRODUCTION_NO_GO_HOLD / release_approved=false**.
This is an implementation candidate, not backend/identity/product adoption. No main
merge, Render/Aiven/DDL/deploy, real actors or iOS product integration is authorized.

## Audit and choice

Source: Web main a84dda3a91d07ca32e7ffe0e1c9a4cac77733c5f; iOS main
fc92fba3c34e626c67b3abfb172f00cd260123df. Read AGENTS (Web root; none tracked iOS),
V40.7, product spec §7, prelaunch IA and carousel, ReleaseLevel/Preparation middleware,
FutureController/BehaviorIdentityService/RoadmapFeature/RoadmapVote and migration
2026_09_23_000700. Existing guest actor is a64-character session/encrypted-cookie token;
registered actors use user:{id}. Existing Web supports one record per candidate/actor
and rejects both add/cancel when unpublished or voting-disabled. API routes are closed
in preparation. roadmap_features and roadmap_votes are the **only candidate/vote
master and ledger**, with existing unique(feature_id,voter_key). Manual
config/canovia_public_roadmap.php is a separate editorial projection, never joined.

| Option | Assessment |
| --- | --- |
| A existing Laravel ledger + Native API | Recommended candidate; reuse schema/order/status/count and existing Web counts; needs isolated credentials, abuse controls and production DB/release acceptance |
| B separate API, same authority | Extra service/secret/network/transaction operations, no safety advantage yet; never copy aggregate/candidate databases |
| C new backend/DB | Rejected for this slice: splits votes/identity and adds migration/operating costs unless an explicitly approved single-authority migration exists |

Read Native #40 6f87b44, #41 bebca154, #42 f102b378, #43 89ff374 and audit #39 f2cfb202.
These are independent Draft candidates. #40 functional scoped PASS/protection FAIL;
#43 readback work is not independent audit acceptance. No existing PR is changed.
Existing file-boundary FAIL/INCONCLUSIVE, legacy migration, file protection and product
Archive gates are unaffected by this optional online component.

## Frozen F-0 v1 wire contract

Base path /api/v1/future. HTTPS required. In the lab only, explicit loopback-HTTP
configuration is allowed for127.0.0.1/localhost/[::1], never a public hostname.
API disabled by default, hard denied outside local/testing even if config is true;
preparation mode denies before DB/identity. No existing Release Level is raised.
Production enablement requires a later reviewed change, not an environment toggle.

| Method/path | Auth | Response |
| --- | --- | --- |
| POST actors | None; empty object/body |201 {credential,expires_at}; opaque server-minted bearer, no user ID input |
| DELETE actors/current | Bearer |200 {revoked:true}; remove only this actor's votes and revoke atomically; repeat is401 |
| GET features | Optional Bearer |200 {features:[Feature],fetched_at,request_id}; no voter lists |
| POST features/{feature_key}/support | Bearer |200 {feature:Feature,request_id}; set supported=true, idempotent |
| DELETE features/{feature_key}/support | Bearer |200 same; set supported=false, idempotent |

Feature = {feature_key,title,description,status,support_count,supported,voting_enabled,
image_key}. description is a string (nullDB→empty). status is existing enum; image_key
is the stable feature_key, **not a remote URL**. UI supplies local placeholder by key;
missing images cannot prevent support. Order=sort_order,id. Published valid candidates
only; stopped candidates remain visible with voting_enabled=false, but direct add AND
cancel return404 as Web policy. No automatic threshold/release/entitlement changes.
Counts and own-state are from one SQL projection, not a stored aggregate. Mutation
acknowledges its serialized transaction; other actors can change counts afterwards.

All request bodies/queries must be empty: user_id/voter_key/actor_token, arbitrary
feature payload, diagnosis, Plan etc are rejected422. Strict bearer only; Web cookies,
Session, CSRF, user login or client-created voter identity never authenticate Native.
Requests create no cookie. Responses private,no-store; Vary:Authorization. No permissive
CORS or browser credential sharing. Request IDs server-minted; errors contain code only:
401 FUTURE_UNAUTHORIZED,404 FUTURE_UNAVAILABLE/FUTURE_NOT_FOUND,
422 FUTURE_INVALID_REQUEST,429 FUTURE_RATE_LIMITED (+Retry-After),
503 FUTURE_STORAGE_UNAVAILABLE. Never return DB/query/token/error-stack details.

## Anonymous actor / privacy / concurrency

Server generates fs1.UUID.random256bits, stores only SHA256(token), UUID, expiry and
revoked_at in **isolated auth metadata** future_native_credentials (not a second vote
ledger). Voter key=native:serverUUID, derived only after bearer verification. Native
never claims an existing Web cookie actor or user ID. Credential expires90days;
no account linking, recovery/reset/rotation/automatic reissue. Future registration
requires an explicit merge/duplicate policy and ownership proof, not client user_id.

Apple vault uses Keychain whenUnlockedThisDeviceOnly, no sync/access group/cookie.
Origin-scoped envelope, validation before use. No fallback to insecure file/defaults.
Reinstall retention is OS-dependent/UNKNOWN; key loss/new devices can yield new actors.
One credential/candidate is guaranteed, **not one human worldwide**. IP issuance limits
and per-IP/per-actor request limits reduce bursts but cannot defeat Sybil/IP rotation.
AppAttest/DeviceCheck, issuance challenges, reverse-proxy limits and operational abuse
budget require separate review before production. No DeviceCheck/Apple config added.

Credential row lock serializes revoke vs vote; feature lock and existing unique key
serialize duplicate Native requests. Existing Web and Native share the same counts,
not the same anonymous identity. Web firstOrCreate still uses the existing uniqueness
contract. No destructive Web migration/refactor is introduced. Production concurrent
PostgreSQL/MySQL acceptance remains separate from SQLite synthetic tests.

Self-revocation deletes only credential-owned votes (including stopped candidates,
separate privacy action), then invalidates credential atomically. Offline deletion is
not promised. Expiry does not automatically erase historical votes. Credential/vote
retention, account merge, IP retention and deletion support policy require owner/privacy
review before adoption. No request/token/body logging is added. Infra access logs,
exception telemetry, TLS and redaction must be audited before public activation.

## Native interface / offline semantics

FutureView → @MainActor FutureViewModel → FutureRepository(actor) → FutureAPIClient
→ URLSession. Independent package; no dependencies on N0, Session, Plan or v1 storage.
UI calls activateAnonymousActor only after consent, refresh on open/reappear/manual
refresh, setSupport(key,desired) only while fresh/online/not processing. Typed models,
operation state and last successful fetch date are returned. No SwiftUI carousel or
product navigation is implemented. Local artwork dictionary keys=feature_key; fallback
asset/SF symbol belongs to UI. Social Cheer runtime is not this Support vote.

Session-only memory cache is scoped to endpoint+credential identity. Refresh uses
server data; stale cache is explicitly last-fetched, never current/zero. Offline/errors
disable writes. No offline queue/background resend. Mutation never optimistically
increments counts. On ambiguous response, GET once and compare desired state; if
unreachable retain unknown+stale and require explicit refresh, never retry POST blindly.
No vote operation can serialize local diagnosis/Plan; wire commands have no payload.
URLSession ephemeral, cookies/cache disabled, bounded timeout, HTTPS exact origin,
redirects refused (including same-origin), normal TLS validation, cancellation supported.

## Verification and release stop conditions

Disposable SQLite only: existing Web+Native aggregate equality, two Native clients,
duplicate add/cancel, foreign-actor isolation, hidden/paused/invalid candidates, malformed
bearers/body spoofing, expiry/revoke, exact OFF/production/preparation gates, rate limits,
DB failure, persistent reload, ambiguous writes and cache isolation. Unique indexes and
transactions remain necessary; SQLite success is not hosted DB/concurrency certification.
Swift mock tests plus actual loopback backend clients are distinct from Simulator/device/
HTTPS production acceptance. Test-only transport may map an HTTPS fixture endpoint to
owned HTTP loopback; production transport never bypasses HTTPS/TLS.

Production needs reviewed API exposure, audited canonical DB migration/backups,
APP_KEY/secret/monitoring ownership, distributed cache for all-worker rate limits,
trusted proxy/IP semantics, TLS/ATS device tests, abuse quotas, operational alerts/cost
budget and privacy/App Privacy declaration (anonymous identifier/votes/IP processing).
Prices/current remaining budgets and hosted DB/service readiness are UNKNOWN: no
Render/Aiven calls or purchases in this task. One existing authority avoids duplicate
service costs; actual hosting plan/limits are not inferred. API failure must leave all
local diagnosis/Plan actions available. No public roadmap status is changed for this
unadopted isolated capability.

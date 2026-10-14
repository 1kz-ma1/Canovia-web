# Development Operational Readiness V5.0 — Provider Observation Boundary

> Status: **V5.0 INTEGRATED INTO main VIA PR #609** (2026-10-14). Not connected to a Render account; not deployed; no provider observation is currently available to the Development Workspace. [V5.1 provider capability gate](DEVELOPMENT_OPERATIONAL_PROVIDER_CAPABILITY_V5_1_2026_10_14.md) keeps unsupported credentials disabled.
> Depends on [V1](DEVELOPMENT_PRELAUNCH_PREPARATION_V1_2026_10_14.md), [V2](DEVELOPMENT_OPERATIONAL_EVIDENCE_V2_2026_10_14.md), [V3](DEVELOPMENT_OPERATIONAL_PROFILE_V3_2026_10_14.md), [V4](DEVELOPMENT_OPERATIONAL_ATTESTATION_V4_2026_10_14.md), and the [canonical Operational Readiness design](DEVELOPMENT_OPERATIONAL_READINESS_SPEC_2026_10_14.md).
> V53.8's seven Release Quality Gates are authoritative and unchanged.

## Security decision: no Render API key onboarding in V5.0

Render's documented account API keys can access **all workspaces the holder belongs to** and the Render API includes mutation operations. An application-level `services.read` string is *not* an actual provider-enforced, least-privilege credential. Never collect a broadly privileged Render API key from a Canovia user and describe it as read-only. The ChatGPT-connected Render tool identity is also not the Canovia account user's authorization.

Official references:
- https://api-docs.render.com/reference/authentication
- https://render.com/docs/api
- https://render.com/docs/mcp-server

Before any Canovia-originated Render request, Platform must verify an actual safe provider authentication/delegation path (provider-approved OAuth client and meaningful scopes, or another truly constrained mechanism). This needs a separate security-reviewed PR and owner consent. Until then the connector is disabled; do not store Render credentials, let users paste keys, or proxy ChatGPT's Render connection.

## V5.0 deliverable (not a connection)

`DevelopmentOperationalProviderBoundaryService::assess` is a pure, no-network, no-database-mutation guard for *future* internally sourced service-metadata observations. It accepts a Plan, an exact V4 target, a server-side permission-ledger snapshot and a server-side authenticated-adapter receipt.

It returns only:
- `unknown` when connector/grant/observation is missing, revoked, expired, untrusted or mismatched
- `stale` when an otherwise valid, scope-matched observation is older than 15 minutes
- `observed` for a fresh, authenticated-adapter **service-presence metadata receipt**, never `verified` release readiness

**The `origin=authenticated_provider_adapter` field is not cryptographic proof.** It must be produced exclusively after a genuine server-side authenticated provider call. Do not expose this service to user-submitted payloads, import arbitrary JSON, accept an MCP tool response under an unrelated identity, or feed synthetic test fixtures into production. The V5.0 service deliberately is **not called by any live request path**, so an unimplemented adapter cannot accidentally upgrade a V4 declaration.

## Required identity and authorization join before observation

The guard requires all of the following:
1. Selected Development Plan has a real owner; V4 target belongs to the exact Plan and has a positive revision.
2. Target provider is `render`, environment is the owner's declared `staging` / `production`, and resource ID matches a canonical nonsecret Render service ID.
3. Server-side connection identity is independently verified; grant is active, not revoked, within its expiry (or explicitly until revoked).
4. Grant's owner, Plan, target service ID and target revision all match the currently selected Plan/target.
5. Grant is scoped to one authenticated Render workspace ID and carries explicit `services.read` Canovia-side consent.
6. Connection ID, workspace ID, **service owner ID returned by the provider**, service ID, target revision and monotonically changing consent epoch all match the authenticated provider-adapter observation. The adapter must read the service's actual owner ID from the API response; it must never copy this value from the grant.
7. Observation timestamp is unambiguously timezone-aware, no more than 15 minutes old, and not more than 60 seconds in the future.

Changing target identity or revision, removing consent, expiring the grant, switching account/workspace, or rotating the authorization epoch **must invalidate the old receipt**. Missing permission or a 401/403/404 must be unknown/not proven, not a verified absence or success. The guard returns safe codes without raw provider payloads, secrets, environment variables, owner email, logs, or credentials.

The *future* persistence model must enforce this join transactionally under owner authentication; `state`, `identity_verified`, `scopes` and `origin` must never be editable by a browser. Data-retention and revocation handling, encrypted credentials where permitted, rate limits, source-side revocation, replay resistance, tenant isolation, verified account handoff, and audit are prerequisites for adapter activation.

## Evidence meaning (cannot be widened)

| Observation | Allowed claim | Explicitly NOT established |
| --- | --- | --- |
| Metadata lookup for exact authorized Render service | `service_presence=observed` | Correct Staging/Production mapping, current live SHA, successful deploy, host health |
| Owner registered environment (V4) | `environment=declared` | Verified provider identity |
| Owner said a backup exists (V4) | `backup=operator_confirmed` | Verified backup creation/retention/integrity |
| Owner said isolated restore happened (V4) | `restore=operator_confirmed` | Provider-proven restoration, sample read or full recovery |
| GitHub Production deployment status (V2) | Exact-SHA GitHub observation | Actual running Render service |
| Provider connection absent or revoked | `unknown` | Failure of the hosted app or lack of backup |

Do not make `environment`, `backup`, `restore`, `ci` or `deploy` a V53.8 gate pass based on `service_presence`. At V5.0 the Work UI continues displaying existing V1–V4 states only. Adding a separate, clearly labeled service-presence display or projection to V2 requires a real provider adapter and a new PR.

## Next implementation slices (each separate review)

1. **V5.1 delegated identity and service selection:** evaluate documented provider-supported limited authorization, implement secure account verification, explicit consent, exact workspace/service selection, owner-only grant visibility, and revoke/unlink. If Render offers no adequately constrained credential, keep the Render connector OFF and consider alternative providers or a local, user-triggered operator handoff. Never claim app-enforced scopes change the provider's key permissions.
2. **V5.2 authenticated read-only adapter:** allowlisted Render metadata endpoint(s), fixed Render API hostname, fixed service-ID path, no arbitrary URL input/redirects, minimal response normalization, circuit breaker/rate caps, redacted diagnostics, predictable 401/403/404, receipt bound to the verified grant and consent epoch. Do not fetch secrets/logs or expose other workspace services.
3. **V5.3 Work evidence projection:** project safe, fresh `service_presence=observed` to an owner-only panel alongside V2/V4, with independent source and time. Detect service mismatch without claiming Production classification or running SHA. On revoke return to unknown; retain V4 claims separately under their own validity rules.
4. **V6+ independent deploy/recovery proof:** only after identifying real provider capabilities, authorize and separately validate running SHA, backup retrieval/integrity and **isolated restore-and-read**. These are different evidence types with different scopes, freshness and safety approvals.

## Regression and deployment isolation

Unit tests cover exact match, missing connection, cross-owner/Plan, changed target revision, revoked/unverified/expired grants, wrong workspace/service/connection/consent epoch, stale/invalid/future timestamps, self-attest forgery and lack of backup/restore/deploy promotion. The existing Development V1–V4 and Workspace CI must stay green.

No new migration, route, user-facing setting, API key field, scheduler, provider request, DB write, Render environment change, Aiven access or production deploy is part of V5.0. Integration into `main` is distinct from production rollout.

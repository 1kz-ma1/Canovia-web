# Development Operational Readiness V5.1 — Provider authorization capability gate

> Status: **FEATURE BRANCH; NOT PROVIDER-CONNECTED OR DEPLOYED** (2026-10-14).
> Depends on [V5.0 scope guard](DEVELOPMENT_OPERATIONAL_PROVIDER_BOUNDARY_V5_2026_10_14.md) and V4 owner-declared target and operator confirmations. No release gate authority.

## Verified public provider constraints

Render REST API authentication uses account API keys, which grant access to all workspaces the account belongs to and are not documented as independently read-only, service-specific grants. Render's hosted MCP server supports OAuth for AI clients, but also exposes mutation operations such as redeploy and service environment-variable updates. Choosing a workspace *inside an MCP session* is an application-side selection, not proof that the underlying OAuth credential is restricted to that service or to reads.

Official documentation, rechecked on 2026-10-14:
- https://api-docs.render.com/reference/authentication
- https://render.com/docs/api
- https://render.com/docs/mcp-server

**Security decision:** Neither broad Render API keys nor the currently documented Render MCP OAuth connection is accepted as a provider-enforced read-only identity for Canovia's automated evidence collection. Do not copy ChatGPT/other AI client Render credentials into Canovia. Never implement a user-facing API-key paste step as a shortcut.

## Implemented V5.1 slice

- `DevelopmentOperationalProviderCapabilityService` is a deterministic, safe, non-network capability policy. For absent V4 target it reports `unconfigured`; for Render `unavailable / provider_enforced_read_only_unavailable`; for `other` `unsupported`. All cases have `can_connect=false` and `can_read=false`.
- `canActivate(provider, authMethod)` explicitly rejects `render_api_key`, `render_mcp_oauth`, unknown providers and future-looking fake scope labels. Adding a genuinely scoped credential path requires explicit, reviewed source changes and dedicated tests, not a runtime flag, optional form field, or user-supplied `services.read`.
- V4 owner-only Work details surface shows the actual connection state and warns users **not** to submit API keys/tokens. It does not display a fake `Connect` button, call Render APIs, or claim machine verification.
- Existing V4 target registration and independent backup/restore operator confirmation are unchanged; neither is promoted to provider-verified evidence.
- No new grant rows are created: recording `pending` consent without any verifiable provider identity would be misleading. An actual credential/storage/approval lifecycle is intentionally deferred until a constrained auth mechanism exists.

## Required before enabling provider connection

1. Document a Render-supported authorization path whose **enforced** permission can read a specific service without write access, or adopt a different provider with proven limited grants. Confirm resource/account binding from independent authenticated API identity, not a claimed ID.
2. Complete a platform security review: callback/PKCE and CSRF handling if OAuth, audience and token binding, secret encryption/storage, credential rotation and provider-side revocation, audit and incident response, tenancy, expired/revoked grant behavior and abuse/rate-limit policy.
3. Implement owner opt-in, account/service selection and per-Plan approval in a separate PR *after* those guarantees; register a new consent epoch on change, revoke and account relink.
4. Use V5.0's pure guard only on an authenticated server-produced receipt. Never accept a browser-constructed `origin=authenticated_provider_adapter` field as independent verification.
5. Keep environment presence, running SHA, backup existence, and isolated restore/read as **separate** evidence dimensions. V53.8 seven gates are untouched.

If the provider cannot supply a bounded credential, use a reviewed, user-triggered local operator handoff instead. Human-provided results remain `operator_confirmed` or `unknown`, not independent verification.

## Tests and release boundary

V5.1 unit regression: Render API key and Render MCP OAuth refusal; guessed future scope refusal; unknown provider and missing-target fail-closed states. V4 feature regression: owner-only Work displays unavailable state, collaborator does not see private target or status. Existing V1–V5, Workspace and release CI stay required.

No migration, new route, credential field, provider call, Canovia MCP delegation, scheduled monitor, live Render/Aiven action, production changes, or live release verification is performed in V5.1. A green CI confirms code behavior in disposable tests only.

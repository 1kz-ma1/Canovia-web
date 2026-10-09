# Canovia MCP — hosted Keycloak decision / safe deployment gate

Date: 2026-10-09. Owner workspace: **Render My Workspace**
(`tea-d4vb3f6mcj7s73djkrqg`).

## Decision: do NOT provision hosted Keycloak on Render Free

Read-only Render inspection on 2026-10-09 confirmed:

| Resource | Existing ID | Plan / state |
|---|---|---|
| Production Canovia Web | `srv-dagfn2id0e5s73cac7ng` | Free Web; Aiven MySQL `pacekeeper` (production, not a candidate for sharing) |
| Isolated MCP staging Web | `srv-db43l4nlk1mc73emseig` | Free Web; separate PostgreSQL |
| Isolated MCP staging PostgreSQL | `dpg-db43rbbncjis73bmigi0-a` | Free, available; **expires 2026-11-08 01:04:45 UTC** |

Keycloak is **not** currently a hosted OAuth issuer. Existing CI can
start Keycloak 26.8.0 in an ephemeral GitHub-hosted Linux runner, but
that is NOT an internet-reachable HTTPS service and cannot be used
as a permanent ChatGPT OAuth issuer.

Keycloak's official container guide recommends a container memory
limit of **at least 750MB**, and **2GB for smaller production-ready
deployments**. Render Free Web has **512MB RAM**. Therefore hosting a
production-like Keycloak on a Free Render Web instance is **unsupported
by the sizing guidance**, not a sound zero-cost shortcut. Render also
supports only **one active Free Postgres per workspace**, and it is
already assigned to MCP staging. Do not try to reuse the existing
Canovia staging database credentials or schema for Keycloak.

References (recheck before any purchase or deployment):
- Keycloak container memory:
  https://www.keycloak.org/server/containers
- Render compute plans:
  https://render.com/docs/compute-plans
- Render free plan hours/Postgres limits:
  https://render.com/docs/free
- Render current pricing:
  https://render.com/pricing
- Keycloak MCP OAuth features (resource-indicators/CIMD experimental):
  https://www.keycloak.org/securing-apps/mcp-authz-server

## Required hosting design — NOT YET AUTHORIZED OR CREATED

Before creating any network-reachable IdP service or paid resource,
request the owner's explicit decision **with fresh quote, estimated
monthly cost, and account-level cost-cap / cancellation path**.
Provisioning is not authorized by successful GitHub CI.

1. Dedicated production-mode Keycloak 26.8.0 (or a separately re-tested
   pinned version) with at least 2GB memory, trusted HTTPS, static
   external issuer, strict hostname/proxy headers, health checks,
   restart discipline and documented upgrade/rollback.
2. Dedicated, persistent, **separate** Keycloak PostgreSQL database,
   least-privilege access and backups; not production Aiven and not
   the existing expiring/staging synthetic dataset. Avoid `start-dev`
   and transient H2 for a hosted, internet-accessible provider.
3. Limit Keycloak management surface/admin console to explicitly
   authorized operators, unique secrets and administrator MFA where
   supported. Avoid leaking registration passwords, access/refresh
   tokens, raw `sub`, Client Secret or issuer private keys into logs
   and PRs. Maintain issuer and DB continuity during redeploy.
4. Register **different clients**: confidential Canovia account-link
   OAuth RP (S256 PKCE + RFC9207 callback `iss`), resource-server
   verifier whose Keycloak client ID equals exactly the HTTPS
   `resource_url`, and separately registered real ChatGPT OAuth
   client using only actual callback URI and verified dynamic/static
   registration. Never assume a synthetic RP equals ChatGPT.
5. Configure resource indicators with exactly one MCP URI in `aud`,
   immutable `sub` in RFC7662 introspection, expected `client_id`,
   `iss`, scope, token lifespan <= 3600, and server-only verifier
   secret. Do NOT use Keycloak's introspection-without-audience-check
   fallback or relax Canovia audience/client rules.
6. Preserve Canovia MCP staging sealed posture until staging owner
   credential rotation, clear user-controlled approval, staging
   access review, session/CSRF, callback, separate Plan consent,
   same-subject checks, wrong-user denial and immediate revocation.
   Exposure of staging login/OAuth/MCP endpoints requires a separate
   reviewed rollout/rollback and minimal endpoint exposure.
7. Only after live user auth and consent success: test a **single
   invented private Development Plan**, read-only bounded
   `canovia_get_development_plan_context`. Never expose real accounts,
   plan contents or Task descriptions during initial acceptance.
8. Owner sign-off: validated costs, charges cap, database lifecycle,
   Keycloak operations/backup, log redaction, rollback, and
   eventual production plan readiness. The public-release
   subscription/billing upgrade question is explicitly deferred
   to the final pre-publication review unless a dedicated test
   resource becomes necessary earlier.

## What is safe to do NOW without hosting or new contracts

Continue reproducible **localhost-only disposable Keycloak CI**,
and real Canovia application-side tests with simulated provider
responses. The separate CI validates actual OAuth Code + PKCE,
RFC7662 exact resource `aud`, stable `iss+sub` across two clients,
wrong-PKCE / altered-resource rejection; this branch expands it
to **two different human actors** and **RFC7009 token revocation**.
The Canovia database integration suite separately proves explicit
link → separate scope/expiry consent → authorized only-Plan read →
revocation denies a previously valid token. Neither is a hosted,
fully live chain or real ChatGPT end-to-end.

**Acceptance cannot be claimed before successful CI on the exact
branch commit.** Do not enable any actual environment flags,
alter Render infrastructure or upload credentials from this doc.

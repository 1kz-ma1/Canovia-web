# MCP isolated staging: pinned GitHub-only + 429 release candidate

2026-10-14 JST. **PREPARED FOR REVIEW ONLY — NOT DEPLOYED, NO AUTH0 CHANGE, NO LIVE PLAN READ.**

## Exact origins and ownership

- Live base as rechecked from Render in `My Workspace`, service `srv-db43l4nlk1mc73emseig`: `8a831448eef528b786053ba5506517300bc001c7`.
- This immutable baseline is `release/mcp-staging-live-baseline-20261014`. The candidate is a *separate branch*, `release/mcp-staging-github-diagnostics-20261014`, based solely on that Live parent. Freeze **the actual candidate SHA reported by GitHub after CI**; never deploy moving `main`.
- Port source: reviewed `main` `dc2563764dbe8550a294fef4ad46d08bc4c14df7` at preparation time, with merged GitHub-only #621 and OAuth 429 #624. The newer development main contains 200+ broad/non-MCP file differences from Live and **must not** be deployed wholesale to staging.
- Live-only stage diagnostics for the synthetic Plan consent callback, strict preexisting JWT dual-client-claim policy and stage login/pilot restrictions have been *preserved* rather than overwritten with development-main files. In particular, `McpJwtClaimConstraints.php`, Nginx, stage startup guard and the two already-present MCP schema migrations are **unchanged** in this candidate.
- Existing separate pinned staging release [Draft #594](https://github.com/1kz-ma1/Canovia-web/pull/594) stays Draft, unmerged and unmodified. The release-candidate PR against the dedicated Live baseline is **not** a development-main merge request.

## Scope and security behavior

1. New `CANOVIA_MCP_ACCOUNT_LINK_GITHUB_ONLY_ENABLED=false` remains OFF by default. The ON route requires the separately configured signed `auth0_jwt` and the existing confidential first-party PKCE S256 client. The server chooses Auth0 `connection=github` plus `prompt=login`. The fully verified immutable Auth0 **primary** subject must match `github|<numeric id>`, not email or unverified user-supplied data.
2. Previously verified generic sessions cannot be reused at final identity-link or Plan-consent confirmation if the GitHub-only mode has meanwhile been turned ON. Existing synthetic-only per-Plan scope, fresh OAuth, CSRF-protected final approval and explicit owner revocation remain.
3. Stage-only fixed categories `link_provider_github_subject_rejected`, `link_provider_metadata_rate_limited`, `link_provider_token_rate_limited` are additive to the prior synthetic Plan-denial codes. No token, callback query, subject, email, URI, error body, Retry-After or OAuth secret is logged. Received upstream HTTP 429 fails closed and has **no automatic retry**. It does **not** independently establish whether the provider, gateway or some other service originated the prior observed 429.
4. The isolated synthetic owner account guide calls the action “GitHubで本人確認する” only when the profile is actually configured, and warns about browser SSO selection. The distinct ChatGPT OAuth client must also select the same immutable subject; Canovia never claims to know ChatGPT login status.
5. Synthetic fixture ONLY (Development Plan ID 1); this is not permission to access ordinary Canovia Plans, staging backups or production Aiven data.

## Reproducible CI / release gate

- PR targets `release/mcp-staging-live-baseline-20261014`; scoped Auth0 JWT Provider Routing, Auth0 Sealed Stage Wiring and **Docker Staging Smoke** CI are made eligible for that exact branch, including disposable PostgreSQL 17 and disabled-by-default route lockdown checks.
- Unit/feature coverage: foreign vs GitHub signed subject, PKCE and callback issuer, fresh Plan consent, configuration-switch rejection, outbound provider GET/POST 429 without payload logs, stage-only diagnostic allowlist, existing staged synthetic read/denial and JWT claim hardening. A red PR check **blocks** release.
- The PR remains open as a candidate (do not merge into baseline, `main`, `production`, or Draft #594). If all CI passes, record *candidate head SHA* and make an explicit owner deployment-approval request, not a deployment.
- Deploy is a **separate action needing explicit owner approval for that exact SHA**; likewise, enabling the GitHub-only flag and any Auth0 tenant / ChatGPT client setting changes require separate approval. Keep Auto Deploy OFF, Render service and stage PostgreSQL configuration unchanged.
- In an approved future synthetic-only E2E: confirm real GitHub social availability for the confidential link client; verify the primary signed subject and distinct ChatGPT third-party subject match; do not auto-create consent; start with revoked grant denied, then **owner-approved** overview, optional tasks, revoke, and deny again. Never silently renew consent or read a real Plan.
- Separate production Aiven/MySQL migration, Auth0 tenant segregation and first user Plan acceptance remain **NO-GO** until other named security gates and approvals are satisfied.

## Known acceptance limits

CI cannot prove Auth0 tenant social connection eligibility, provider SSO session selection, the external ChatGPT OAuth client identity, runtime Render flags, live DB state or who caused the past 429. A green build is **not** a live acceptance and this candidate is not deployed.

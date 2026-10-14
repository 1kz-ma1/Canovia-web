# Development Operational Evidence V2 — bounded, source-labeled preparation

> Status: **implemented in feature branch; not deployed**. Date: 2026-10-14.
> Companion contracts: [V1 prelaunch suggestion](DEVELOPMENT_PRELAUNCH_PREPARATION_V1_2026_10_14.md), [operational readiness roadmap](DEVELOPMENT_OPERATIONAL_READINESS_SPEC_2026_10_14.md), [V53.8 authoritative seven gates](../V53.8_DEVELOPER_READINESS.md).

## Intent

During a launch-related Development Plan's **Work** screen, show **one highest-priority verification question** and a collapsible set of six source-labeled checks. Distinguish GitHub *observations* from actual production / database and restore verifications; never fabricate successful checks when connectors or operational evidence are absent.

## Current scope / inputs

- Reuses V1 Plan/unfinished Task keyword cue or explicit \`?release_preflight=1\` opt-in; no universal onboarding requirement.
- Reuses existing \`DevelopmentExecutionContextService\`: only selected Plan's Task-scoped, persisted and reuse-guarded GitHub evidence. The controller also requires current GitHub actor capability and a ready repository connection before using it as current evidence.
- The evaluator performs **no new queries/provider calls or writes**. On mismatched Plan/Task or revoked/unavailable integration, GitHub statuses remain **unknown**.
- CI is \`observed\` only for an exact, valid 40-character PR head SHA matching the CI observation, with \`success\`. Failure ranks first, mismatched SHA is \`stale\`; invalid/missing SHA is \`unknown\`.
- Production deployment is \`observed\` only if GitHub says \`production_environment=true\`, status success and SHA matches the PR head. A different deployment SHA is **review**, not an automatic failure, because merge commits may differ. Staging deployment never passes the production check.
- A GitHub \`observed\` status **never** means live Render is confirmed, all required CI checks are complete, a release is safe, or Web/iOS smoke has passed.
- Source and time are shown only for scoped observations; SHA display is shortened. No raw webhook payloads, URLs, customer data or secrets are included.

## Checks and priority

All shown as **unknown unless supported by eligible evidence**:

| Key | What the user should verify | Boundary |
| --- | --- | --- |
| environment | Real production service, environment, branch | GitHub does not independently identify the running host |
| data | Whether persistent DB/user data is used | No forced DB restore for a static site |
| backup | Source, recency, encryption and off-device copy where relevant | Backup file presence is not proof of restorability |
| restore | Isolated restore and read verification where relevant | Separate from backup creation |
| ci | CI result for current PR head SHA | CI success is not a production deploy |
| deploy | GitHub deployment observation vs actual live SHA | Staging is not Production; GitHub evidence is not host evidence |

**Priorities**: observed current CI failure → mismatched/stale CI → environment/data/recovery identity checks → missing CI/deploy comparison → already observed GitHub statuses. This produces one primary item with supporting details on demand. No pass score or “Release Ready” is computed.

All backup/restore statements are conditional on persistent data; \`unknown\` means **not independently verified**, not “failed” or “does not exist.”

## UX and compatibility

- One compact primary note after canonical Next Action, with details collapsed by default.
- \`data-development-operational-*\` selectors for regression tests.
- No new DB schema, routes, task writes, provider calls, AI calls, persistent checklist or permission grants.
- Existing V53.8 seven release gates and Current Action remain unchanged.
- Creative Plan rescue excluded; Plan owner controls task-assistant CTA.
- Not applicable to V1 prototype without launch context unless user explicitly requests preparation.

## Validation / next stages

- Unit: exact-SHA CI success/failure/staleness, actor/source boundary, staging vs production, invalid SHA, absent proof.
- Feature: single-Plan scoped UI, opt-in, other surfaces, cross-user 404, no Task/Evidence mutation.
- CI: V1+V2 tests plus V54.4/V57.3/V58.0 regressions, lint and Blade compile.
- Next stage requires an explicit actor-authorized lifecycle and target environment binding, a safe structured evidence store with operator confirmations and freshness rules, and independently verified host/backup/restore evidence. That would be a separate Platform-reviewed implementation PR.

This implementation does not authorize #513 release promotion, Render auto-deploy, Aiven SQL, or a production configuration change.

# Development Operational Readiness V5.2 — Owner-only Render manual verification handoff

> Status: IMPLEMENTED IN FEATURE BRANCH ONLY. Not deployed or connected to Render; integration requires passing CI and review.
> Scope: Development Work / V4 registered target. Reuses [V5.0 provider receipt guard](DEVELOPMENT_OPERATIONAL_PROVIDER_BOUNDARY_V5_2026_10_14.md) and [V5.1 denied capabilities](DEVELOPMENT_OPERATIONAL_PROVIDER_CAPABILITY_V5_1_2026_10_14.md).

## Decision

Render's public REST API account key covers all workspaces available to its owner. The official hosted MCP supports OAuth, but also exposes deploy, environment-variable modification and creation actions. A Canovia-side `services.read` label does NOT narrow these credentials. A Render Viewer workspace role exists on **Scale or higher**, but does not provide a universally available independent per-service credential for consumer users.

Sources checked on 2026-10-14:
- https://api-docs.render.com/reference/authentication
- https://render.com/docs/mcp-server
- https://render.com/docs/team-members
- https://render.com/docs/render-dashboard
- https://render.com/docs/projects

Therefore the V5.2 slice is a **safe owner-triggered operator handoff**, not an automatic Render API adapter. Do not collect API keys or OAuth tokens, link the signed-in ChatGPT Render connector to a Canovia account, or imply provider-scope enforcement.

## Implemented UX

Under the V4 optional environment/restore area, show a second collapsed panel **only** to the original Development Plan owner with an existing, valid Render `srv-...` target:

1. Present the registered service identifier and owner's declared target environment, plainly labeled as self-report.
2. Link to the **fixed** Render dashboard origin `https://dashboard.render.com/` in a new tab (`noopener noreferrer`). Do not form a destination URL from an arbitrary user-supplied ID or receive service URLs.
3. Explain a short three-step handoff: independently confirm the Render Workspace and actual service ID; compare declared staging/production classification with Render project environment/deploy branch; compare current deploy / source SHA separately from actual host/device smoke.
4. Tailor recovery guidance to the owner-declared V3 `data_store`:
   - `none`: no forced backup/restore instructions; still verify service/deploy.
   - `server_db` or `mixed`: distinct backup presence and isolated restore/read, never equate provider existence with Aiven/other DB backup.
   - `local_device`: device migration and restore (no forced server SQL).
   - `cloud_files`: file versioning and independent recovery.
   - `unknown` or unexpected: classify storage first; no fake proof.
5. Warn never to paste credentials, database connection strings or operational logs. Existing V4 explicit owner attestation remains separate and revocable; no new confirmation mechanism is created.

This handoff is a **read-only guide** generated from already authorized Plan/target/profile objects, with no new SQL write, credential storage, Render request, task update or foreign Plan read. The static provider link cannot redirect to a user-chosen domain. Creative rescue/collaborator/guest Work paths never render owner-only operational details. Existing V1/V2 evidence and V53.8 seven gates are unchanged.

## Evidence semantics

The guide indicates actions to perform, **not actions Canovia performed**. It never emits `verified`, `observed` or `deploy_success` evidence. The V4 environment remains `declared` and any independent backup/restore operator entry remains `operator_confirmed` or `stale`; a manual service inspection does not itself establish a deploy SHA, health, backup or restoration.

No external service was queried by the newly implemented code. No live or production rollout implied by passing CI.

## Acceptance and next slice

- No target, invalid/unrelated target or unrecognized provider: no Render handoff.
- Owner with exact target: safe fixed link + scoped ID; three base steps.
- `none` never shows server backup/restore work; persistent server DB shows both independently.
- Other owner and collaborative Editor must not see target ID, guide or link.
- Workspace GET cannot change target, confirmations, TaskEvidence, CI observation or release gate.
- PHP lint, Blade compile, V1–V5 regression, existing Workspace/quality CI green on exact PR HEAD.

A future V5.3 may allow a **separate** bounded operator attestation of manual host verification, with proper revision/age invalidation. It must **not** turn that into machine-verified evidence. An authenticated automatic provider adapter remains blocked until provider-enforced limited authorization is independently demonstrated and approved.

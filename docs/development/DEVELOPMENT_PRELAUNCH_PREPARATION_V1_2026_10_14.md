# Development Prelaunch Preparation V1 — implemented first slice

> Status: implemented in its feature branch; not a production-delivered feature until main integration and a distinct verified Render release.
> Date: 2026-10-14.
> Source design: [Operational Readiness / Release Safety Coach proposal](https://github.com/1kz-ma1/Canovia-web/pull/590).
> Applies only to authorized Development Plan Workspaces. No database schema, billing, or provider mutation.

## Intent

Give the developer **one small preparation suggestion** when an unfinished Task or the selected Plan name explicitly mentions public launch / release. Do not require GitHub for a prototype and do not fabricate current production backup or CI conditions.

## Implementation

- \`DevelopmentPrelaunchPreparationService::suggest\` uses **only the authorized selected Plan and already-loaded Tasks**; no provider/API/database fetch.
- A narrow lifecycle-keyword cue in active Task titles or Plan title requests a suggested prelaunch preparation; completed/cancelled Task cues are ignored.
- \`?release_preflight=1\` is an explicit, ephemeral request for any Development Plan. It creates no persisted state.
- The Work surface shows the single suggested action **"公開先とデータの保存・復旧方法を確認する"** and links the existing Plan Task Assistant for plan owners. Users without cues see a small opt-in link; other surfaces remain unaffected.
- Visible evidence state is always \`unknown\`. The screen explains that no actual deployment, production environment, backup, or restore check occurred and that the seven V53.8 quality gates remain distinct.
- Creative Plan rescue (a Creative Plan displayed in Development) is excluded rather than incorrectly claiming Development release evaluation.
- No new route, migration, Task mutation, GitHub call, AI call, workflow execution, automatic release or external secret access occurs on Work GET.

## Important limitations / follow-up

This first slice is a **contextual reminder**, not a verified missing-gap detector or a full release safety coach. A release-related title is a *possible intent*, not proof of public deployment or a DB. \`Plan.is_public\` controls Canovia Plan sharing and is not used as an app launch signal. The Task Assistant link is a next step for manual planning, not a generated preflight checklist or approval.

Next slices (separate PRs and Platform/security review):

1. Explicit lifecycle/target environment selection scoped to Plan + actor permissions; keep prototypes lightweight.
2. Structured, provenance-aware operational evidence (\`verified / operator_confirmed / declared / unknown / stale / not_applicable\`) and expiration for release SHA/environment; backup existence distinct from isolated restore.
3. Deterministic migration/deployment guidance with validated CLI/runbooks; never execute privileged operations from viewing the workspace.

## Acceptance

- Prototype no signal → no forced warning; voluntary check available.
- Task indicating initial public launch → one compact suggestion, no release-ready claim.
- Completed/cancelled launch work → not a stale automatic trigger.
- No database → never insist on a MySQL restore.
- No GitHub or provider connection → no blocker and no provider costs.
- Other actor's Plan remains inaccessible; no cross-Plan leak.
- Other surfaces, Creative rescue and seven release gates retain their existing semantics.
- Tests: \`DevelopmentPrelaunchPreparationServiceTest\`, \`DevelopmentPrelaunchPreparationV1Test\`, V54.4/V57.3/V58.0 regressions.

**Production boundary:** This code/PR does not change production branch, Render autoDeploy, Aiven, environment secrets or existing release candidate #513.

# TEMP V58.4 Specialized Mode Top Canonical Entry

Status: implementation in progress  
Branch: `feature/v58-4-mode-top-entry-routing`

## Goal

Make the mode-level Study / Developer Top pages introduced in V58.2 the canonical
entry point when the user enters or resumes a Workspace mode.

The distinction is:

```text
enter / resume Study or Developer mode
→ Mode Top
→ choose / inspect a Plan
→ open one Plan Workspace

explicit Plan deep link / Plan-local action
→ selected Plan Workspace directly
```

## Scope

### Canonical mode entry

The following mode-level entry flows must resolve to the Top pages:

- Workspace Mode persistent selection (`POST /workspace/{workspaceMode}/select`)
- explicit ephemeral mode navigation (`workspace_modes.enter`)
- root `/` resume when a Study / Development manual preference is active
- Overview first-use mode choices that use `workspace_modes.enter`

Routes:

```text
Study       → workspace.study.top
Development → workspace.development.top
Overview    → workspace.overview.index
Career      → workspace.career.index
```

### Resolve the current URL collision

The generic GET mode-entry route currently shares URLs such as
`/workspace/study` with the concrete Study Workspace route, so Laravel dispatches
to the concrete route instead of `WorkspaceModeController::enter`.

Move the generic ephemeral entry route to:

```text
GET /workspace/mode/{workspaceMode}
→ workspace_modes.enter
```

Keep the route name unchanged so internal callers follow the new canonical path.

### Preserve Plan-local deep links

Do not change:

- `/workspace/study?plan_id=...&surface=...`
- `/workspace/development?plan_id=...&surface=...`
- Career Plan Workspace behavior
- Plan creation redirects that intentionally open the newly created Plan
- Current Action / Plan-specific handoffs
- Study Practice / Recall / Scope / Scores / Resources
- Developer Repository / Team / Improvements / Preview surfaces

Direct `workspace.study.index` and `workspace.development.index` routes remain
valid compatibility routes.

## State / side-effect boundary

V58.4 is routing-only.

It must not:

- alter Workspace preference persistence semantics
- create or select a Plan
- run additional Study / Development Intelligence
- call AI
- add GitHub provider traffic
- mutate Task / Plan progress
- change billing or entitlement behavior

## Verification

Required coverage:

- explicit Study mode entry redirects to Study Top
- explicit Development mode entry redirects to Developer Top
- persistent selection redirects to Top and persists the same preference
- root resume redirects to Top for Study / Development preferences
- Overview / Career behavior remains unchanged
- Plan deep links still open Plan Workspaces
- unknown mode remains 404
- relevant V54.1 / V54.2 / V56.12 / V58.2 / V58.3 regressions remain green

## Completion

When implementation and validation finish:

1. promote this document to `docs/V58.4_SPECIALIZED_MODE_TOP_ENTRY.md`
2. update `docs/CANOVIA_PRODUCT_SPEC.md`
3. leave this TEMP file as a completion pointer
4. create a PR targeting `main`
5. stop before merge

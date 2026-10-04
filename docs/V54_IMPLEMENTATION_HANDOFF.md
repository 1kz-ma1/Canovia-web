# V54 Implementation Handoff

> Temporary implementation handoff for the V54 Workspace Modes & Intelligence UI series.
>
> Delete this file only in the final V54 completion PR after permanent specs are complete.

## Product goal

V53 changed Canovia's reasoning model:

```text
Reality / Evidence
→ State
→ Readiness
→ Gap
→ Decision
→ Current Action
```

V54 changes the product face so users can immediately understand what Canovia is strong at and stay inside a purpose-specific flow.

The product remains one Canovia with one shared Intelligence core.

UI specialization happens through Workspace Modes.

## Stable design rules

- Workspace Mode != Plan category
- Workspace Mode != billing product
- Workspace Mode != separate application
- shared State / Evidence / Readiness / Decision / Action core remains authoritative
- Study and Development can specialize terminology, navigation, empty states and primary CTA
- Overview stays thin and cross-Mode
- Task remains execution/projection detail rather than UI source of truth
- public Mode options must come from a registry, not hard-coded header tabs
- future Mode growth must not require horizontal header expansion
- fixed Mode selector will be dropdown-based
- user manual choice must outrank ambiguous/contextless inference
- unambiguous deep links must preserve their semantic Workspace even when a different preference is stored
- automatic context changes must not feel like random UI mode switching
- no new AI traffic is required for Workspace Mode UI

## V54.0 — Workspace Mode Contract

Status: **MERGED — PR #218**

Branch:

`feature/v54-0-workspace-mode-contract`

Permanent spec:

`docs/V54.0_WORKSPACE_MODE_CONTRACT.md`

Implemented:

- `WorkspaceMode` enum
- `WorkspaceModeSource` enum
- `WorkspaceModeDefinitionData`
- `WorkspaceModeContextData`
- `WorkspaceModeRegistry`
- `WorkspaceModeResolver`
- Overview / Study / Development public Mode definitions
- semantic navigation keys
- semantic empty-state action keys
- explicit separation from broader PlanCategoryProfile keys
- route hint inference
- Plan / Task / WorkSession profile inference
- explicit caller override boundary for future persistence layer
- registry / resolver tests
- final validation run: #37181633651
- V53.9 / V53.8 / V53.6 regressions passed
- temporary validation workflow removed after success

V54.0 intentionally does not render or persist the Mode selector.

Current public mapping:

```text
study       → study
development → development
career      → overview
creative    → overview
general     → overview
```

Next after V54.0 merge:

- V54.1 Fixed Mode Bar
- render current Mode in the global fixed header
- dropdown options sourced only from WorkspaceModeRegistry
- compact desktop control
- mobile sheet/dropdown with larger touch targets
- no persistence yet; current Mode comes from resolver/context
- no horizontal Mode tab strip

## Planned stages

### V54.1 — Fixed Mode Bar

Status: **MERGED — PR #219**

Branch:

`feature/v54-1-fixed-mode-bar`

Permanent spec:

`docs/V54.1_FIXED_WORKSPACE_MODE_BAR.md`

Implemented:

- fixed second-row Workspace Mode Bar inside desktop/mobile sticky app header
- dropdown options sourced only from `WorkspaceModeRegistry`
- Overview / Study / Development semantic icons and descriptions
- `WorkspaceModeController` entry route
- deterministic Study/Development Plan selection on Mode entry
- ephemeral `workspace_mode` query context for empty Mode entry
- no DB/session/localStorage persistence yet
- Focus Mode exclusion
- responsive mobile touch targets and viewport-constrained menu
- Instant Navigation fragment metadata
- Instant Navigation current Mode synchronization
- app/page data attributes for current Mode
- V54.1 feature tests
- final validation run: #37182453095
- V54.0 / V53.9 / Home regressions passed
- temporary validation workflow removed after success
- PR #219 created against main

Important semantics:

- Mode Bar is navigation, not a Plan filter
- Mode selection is not persisted in V54.1
- Study entry opens an existing Study surface when possible
- Development entry opens an existing Development surface when possible
- Mode list remains registry-driven for future expansion
- Career/Creative/General remain valid Plan profiles but are not public Mode entries yet

### V54.2 — Mode Context & Persistence

Status: **MERGED — PR #220**

Branch:

`feature/v54-2-mode-context-persistence`

Permanent spec:

`docs/V54.2_MODE_CONTEXT_AND_PERSISTENCE.md`

Implemented:

- persistent manual Mode selection from the fixed Mode Bar
- authenticated preference in `users.workspace_mode_preference`
- guest fallback in Laravel session
- explicit reset to automatic behavior
- registry validation for persisted public Mode keys
- `WorkspaceModeSource::ManualPreference`
- stable deep-link semantics: route/profile context overrides stored preference without erasing it
- contextless surfaces use the stored preference before Overview fallback
- retained Mode Bar displays fixed / route-following / Plan-following state
- Instant Navigation synchronizes the visible context label
- V54.2 PHP and JS tests
- latest implementation validation run: #37184162982
- V54.1 / V54.0 / V53.9 / Home regressions passed
- PR #220 created against main as a stacked PR on #219

V54.2 precedence:

```text
explicit caller Mode
→ valid ephemeral workspace_mode query
→ strong domain route hint
→ current Plan / Task / WorkSession profile
→ persisted manual preference
→ Overview
```

Important semantics:

- manual preference is the default Workspace on ambiguous/contextless surfaces
- a concrete deep link remains semantically truthful even when another Workspace is preferred
- contextual override never erases the stored preference
- `GET /workspace/{mode}` remains ephemeral navigation
- Mode Bar selection uses `POST /workspace/{mode}/select`
- reset uses `DELETE /workspace/preference`
- `null` preference means automatic behavior
- no localStorage duplication

### V54.3 — Study Workspace

Status: **MERGED — PR #221**

Branch:

`feature/v54-3-study-workspace`

Permanent spec:

`docs/V54.3_STUDY_WORKSPACE.md`

Implemented:

- canonical Study Home at `GET /workspace/study`
- Study Mode entry now lands on Study Home rather than Scope directly
- one selected accessible Study Plan is evaluated at a time
- deterministic default Plan selection: priority → deadline → ID
- explicit accessible Study Plan selection through `plan_id`
- invalid/inaccessible/non-Study explicit Plan returns 404
- no Study Plan → Plan creation empty state inside Study Workspace
- no confirmed Scope → capture-first empty state
- no fake numeric Readiness before confirmed Scope
- confirmed Scope → Exam Readiness / Biggest Gap / Current Action
- Coverage / Mastery / Retention / Remaining Load
- deadline pressure / remaining Study Units
- bounded priority remaining Scope list
- Current Action / Readiness / Scope / Practice / Recall / History navigation
- Practice / Recall navigation reuses target/active Task and never creates one on GET
- bounded Study Intelligence history
- `workspace.study.*` strong route hint
- existing V53 Study Intelligence remains authoritative
- no new AI traffic
- final validation run: #37186624276
- V54.2 / V54.1 / V54.0 / V53.9 / V53.6 / V53.5 / Home regressions passed
- PR #221 created against main

### V54.4 — Development Workspace

Status: **MERGED — PR #222**

Branch:

`feature/v54-4-development-workspace`

Permanent spec:

`docs/V54.4_DEVELOPMENT_WORKSPACE.md`

Implemented:

- canonical Development Home at `GET /workspace/development`
- Development Mode entry now lands on Development Home
- one selected accessible Development Plan evaluated at a time
- deterministic default Plan selection: priority → deadline → ID
- explicit accessible Development Plan selection through `plan_id`
- invalid/inaccessible/non-Development explicit Plan returns 404
- no Development Plan → Plan creation empty state inside Development Workspace
- no Release Evidence → GitHub-first empty state
- no fake numeric Readiness before Release Evidence exists
- Release Readiness / Biggest Release Gap / Current Action
- seven read-only Quality Gate summary
- bounded Release Candidate Task / PR / branch / SHA / deployment context
- stale Verification / Spec Sync warnings
- Current Action / Release Readiness / Quality Gates / GitHub / Evidence / History navigation
- mutation operations remain on existing GitHub Workflow / Execution surfaces
- `workspace.development.*` strong route hint
- existing V53.7 / V53.8 Development Intelligence remains authoritative
- Workspace GET creates no Task / Artifact / Evidence / Intelligence history
- no new GitHub fetch or AI traffic
- final validation run: #37189203048
- V54.3 / V54.2 / V54.1 / V54.0 / V53.9 / V53.8 / V53.7 / GitHub Workflow / Home regressions passed
- PR #222 created against main

### V54.5 — Overview

Status: **IN PROGRESS**

Branch:

`feature/v54-5-overview-workspace`

Permanent spec:

`docs/V54.5_OVERVIEW_WORKSPACE.md`

Implementation contract:

- canonical Overview at `GET /workspace/overview`
- existing Home selection remains global Action authority
- one primary global Action only
- at most one Study and one Development summary
- setup-needed summaries suppress fake numeric Readiness
- bounded Inbox pending summary
- bounded important Action Home signals
- no new ranking / Readiness / notification model
- no AI or GitHub provider traffic
- root Home remains compatible

### V54.6 — State Change Feedback

Planned:

- before/after Readiness changes
- Current Action change feedback
- Evidence → Decision explanation
- only changes that affect user next action

### V54.7 — Mode-specific Onboarding

Planned:

- Study / Development first-use choice
- mode-aware empty states
- registry-driven future expansion

### V54.8 — Polish / telemetry / iOS

Planned:

- real-device fixed header tuning
- safe area
- dropdown/sheet ergonomics
- PWA/iOS navigation behavior
- mode-selected / mode-auto-context telemetry
- permanent docs finalization
- delete this temporary handoff

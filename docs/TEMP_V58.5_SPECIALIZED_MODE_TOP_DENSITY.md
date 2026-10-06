# TEMP V58.5 Specialized Mode Top Daily-use Density

Status: implementation in progress  
Branch: `feature/v58-5-specialized-top-density`

## Goal

Now that V58.4 makes Study / Developer Top the canonical mode entry, the Top
must be light enough to pass through every day.

Current V58.2 Plan cards expose correct information but are visually tall:

- one large nested card per Plan
- progress, task chips, preparation/setup actions always expanded
- Developer GitHub state uses another nested card
- Study exposes Range / Resources / Scores / Plan detail as four permanent buttons

V58.5 keeps all capabilities while changing the default presentation to:

```text
Mode Top
→ compact mode header
→ compact Plan rows
→ Plan name + progress + state visible immediately
→ primary Open action visible
→ preparation / setup tools disclosed only when needed
```

## Scope

### Shared Plan row

Each Study / Development Plan remains one server-rendered item with:

- Plan title
- weighted progress %
- thin progress bar
- status / deadline / remaining days
- active / total Task counts
- primary `学習を開く` / `開発を開く` action

The row must avoid another `page-card` nested inside the parent Plan-list card.

### Study row

Always visible:

- title / progress / schedule
- task count
- simple Scope / Resource counts
- Open Study action

Collapsed tools:

- 範囲
- 教材
- 成績
- Plan詳細

Use native `<details>` so no JavaScript state or new persistence is required.

### Development row

Always visible:

- title / progress / schedule
- task count
- Repository name or `Repository未登録`
- persisted GitHub connection label
- Open Development action

Connection setup remains directly reachable when available.

Plan detail moves into the compact disclosed tool area. Do not add remote
GitHub reads.

### Top chrome

Study / Developer introductory headers should become compact mode headers.

Developer actor-level GitHub integration state remains visible but should not
occupy a large standalone dashboard block.

## Boundaries

V58.5 is presentation-only.

It must not:

- change Mode Top routing
- change Plan sorting
- change PlanProgressService
- run Study / Development Intelligence per Plan
- add AI calls
- add GitHub API reads
- change Task / Plan state
- change entitlements or billing

Existing V58.2 data markers and links remain compatible where practical.

## Validation

Required coverage:

- Study Top still exposes all Study Plan actions
- Developer Top still exposes GitHub setup and persisted connection state
- Top GET remains provider-free
- compact row marker is rendered
- Study secondary tools use native disclosure
- Developer Repository state is inline rather than nested dashboard content
- V58.2 / V58.3 / V58.4 regressions remain green
- mobile Workspace entry regression remains green

## Completion

After validation:

1. promote to `docs/V58.5_SPECIALIZED_MODE_TOP_DENSITY.md`
2. update Product Spec / V58.2 responsibility note
3. retire TEMP to a pointer
4. create PR targeting `main`
5. stop before merge

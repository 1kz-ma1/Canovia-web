# TEMP V58.2 Study / Developer Mode Top Hubs

Status: implementation working spec  
Branch: `feature/v58-2-study-development-mode-tops`

This temporary spec is intentionally committed **before implementation** so the
work can be resumed safely even if the current conversation ends.

---

## 1. Goal

Separate two responsibilities that are currently mixed inside the specialized
Study / Developer plan workspaces.

```text
Mode Top
→ choose / create / prepare a Plan
→ inspect mode-level setup / connections
→ see all Plans + progress

Plan Workspace
→ work inside one selected Plan
→ one screen = one information / operation lineage
```

Every Study / Developer Plan workspace gets a compact top-left escape hatch:

```text
学習トップへ →
開発トップへ →
```

The top pages become the stable place for mode-level navigation and setup.

---

## 2. New canonical routes

Add dedicated top routes without changing existing Plan workspace URLs.

```text
GET /workspace/study/top
→ workspace.study.top

GET /workspace/development/top
→ workspace.development.top
```

Existing canonical Plan workspace routes remain:

```text
GET /workspace/study?plan_id=...
GET /workspace/development?plan_id=...&surface=...
```

The existing routes keep their current deep-link behavior and authorization.

V58.2 does **not** force all Workspace Mode entry flows to the new top page yet.
That decision can be made separately after real-device use.

---

## 3. Shared Mode Top responsibilities

Both top pages show:

- mode identity
- create-Plan CTA
- accessible Plan list
- Plan name
- weighted progress percentage
- compact progress bar
- schedule/status label from existing `PlanProgressService`
- deadline when available
- active / total Task counts
- open-Plan CTA

The top page does not run Study / Development Intelligence for every Plan.

Progress summary uses the existing Plan model facts only:

```text
tasks
workLogs
availabilityRules
availabilityOverrides
→ PlanProgressService
```

This avoids N copies of Adaptive Action / AI / GitHub reads.

---

## 4. Study Top

Study Top is a Plan-selection and preparation hub.

Each Study Plan exposes only existing real capabilities:

- open Study Workspace
- Study Scope / range intake
- Plan Resources / materials
- score observations

No new Study connection provider is invented.

Study Top may summarize setup from already loaded facts, for example:

- task count
- confirmed Study Scope capture/item presence
- resource count when cheaply available

The first implementation should prefer simple factual state over running the
full Study Intelligence pipeline for every Plan.

### Study Plan card

Target hierarchy:

```text
Plan title                        68%
██████████████░░░░░
遅れ気味 · 期限 11/10

12 active Tasks / 18 total

[学習を開く]
[範囲]
[教材]
[成績]
```

---

## 5. Developer Top

Developer Top is a Plan-selection and integration hub.

Each Development Plan exposes:

- open Developer Workspace
- current Plan progress
- registered GitHub Repository if present
- GitHub connection state from existing GitHub App metadata / readiness
- GitHub / Evidence setup entry

The top page should use the existing:

- `GitHubIntegrationReadinessService`
- Repository `PlanArtifact`
- GitHub Workflow route

It must not create a second GitHub connection flow.

### Development Plan card

Target hierarchy:

```text
Canoviaを事業として成功させる       42%
████████░░░░░░░░░░
予定通り · 期限 12/01

GitHub
Canovia-web · 接続済み

[開発を開く]
[GitHub / 接続]
```

If no Repository is registered:

```text
GitHub
Repository未登録
[GitHub / Evidenceで登録]
```

---

## 6. Plan Workspace top-left link

Study Plan Workspace:

```text
← 学習トップへ
STUDY ...
```

Developer Plan Workspace:

```text
← 開発トップへ
DEVELOPER ...
```

The link must be small and low-noise.

It should not consume another permanent header row.

Desktop and mobile use the same semantic link with responsive spacing.

---

## 7. Shared projection service

Prefer one shared service for Mode Top Plan summaries rather than duplicating
progress calculation.

Proposed:

```php
SpecializedWorkspaceTopService
```

Responsibilities:

- receive an already-authorized Collection<Plan>
- calculate bounded progress summary
- count active / total Tasks
- format deadline/status facts
- never perform AI/provider remote reads

Mode-specific controllers add their own setup projection:

- Study: Study preparation links / simple scope facts
- Development: GitHub Repository / connection facts

---

## 8. Authorization

Top pages use the same accessible-Plan collection semantics as the current
specialized workspaces:

```text
PlanOwnershipService::ownedPlans()
→ owner + collaborative editor/viewer + guest-owned
→ PlanCategoryProfile filter
```

Top pages never reveal Plans outside the actor's accessible collection.

Plan-level actions keep their current authorization.

The top page itself is read-only except for existing linked actions.

---

## 9. Performance boundaries

Top pages may evaluate progress for all accessible Plans, but must not:

- run Study Adaptive Action for every Study Plan
- run Development Adaptive Action for every Development Plan
- fetch GitHub API data per Development Plan
- call AI
- fetch remote Resources

Development GitHub status comes from persisted Repository Artifact metadata plus
one actor-level GitHub runtime/capability readiness projection.

No top page GET should trigger external GitHub API calls.

---

## 10. Existing UI relationship

### Study

Current Study Workspace large hero / Plan selector still exists at the beginning
of V58.2.

Phase 1 of this change introduces the Top page and top-left link first.

Then the selected Plan Workspace should become more focused by removing
mode-level responsibilities that now belong on Study Top:

- multi-Plan selection
- mode-level orientation

Do not rewrite the Study State First composition engine in V58.2.

### Developer

V58.1 already has compact Plan + View navigation inside a Plan Workspace.

V58.2 changes responsibility:

- Developer Top owns **Plan selection**
- Developer Plan Workspace owns **View selection**

The Plan Workspace may keep a compact current Plan identity, but should no
longer need a multi-Plan dropdown after V58.2.

This avoids duplicate Plan selectors.

---

## 11. Implementation phases

### Phase A — temporary spec
- [x] commit this working spec before implementation

### Phase B — shared backend
- [ ] add shared Mode Top Plan summary service
- [ ] add Study Top controller
- [ ] add Developer Top controller
- [ ] add routes

### Phase C — top UI
- [ ] add shared Plan list partial
- [ ] add Study Top page
- [ ] add Developer Top page
- [ ] add empty states
- [ ] add create Plan CTAs

### Phase D — Plan workspace links / responsibility cleanup
- [ ] add `学習トップへ →`
- [ ] add `開発トップへ →`
- [ ] move Study multi-Plan selection out of Plan Workspace
- [ ] move Developer multi-Plan selection out of Plan Workspace
- [ ] keep Developer View selector

### Phase E — validation
- [ ] top route authorization / profile filtering
- [ ] progress summary test
- [ ] Study Top links / setup test
- [ ] Developer GitHub status / no remote API test
- [ ] Study Workspace regression
- [ ] Developer V58.0 / V58.1 regression
- [ ] Workspace Mode route hint regression
- [ ] mobile / desktop shell regression

### Phase F — final documentation / PR
- [ ] update canonical Product Spec
- [ ] mark this TEMP spec implemented
- [ ] remove temporary CI workflow after green
- [ ] create PR and record URL

---

## 12. Non-goals

V58.2 does not:

- implement future Study lifecycle dropdown categories
- implement future Developer Automation settings
- change Premium / Pro / Developer Pro billing
- auto-run AI analysis
- auto-run coding agents
- infer Task owners
- add a new GitHub provider
- change Plan progress calculation
- change Workspace Mode persistence
- delete old deep links

---

## 13. Follow-up direction

After V58.2 settles on real devices:

### Study Plan Workspace

Move toward:

```text
実行
準備
分析
記録
```

using the same grouped View pattern as Developer.

### Developer Mode Top

Later top-level controls may include:

- Integration health
- organization / team setup
- Developer Pro automation policy entry

but only after those real capabilities exist.

### Billing boundary

Future product boundary can remain:

```text
Premium / Pro
→ collected data analysis
→ issue detection
→ improvement proposals

Developer Pro
→ approved proposal
→ automatic implementation workflow
```

This billing direction is documented here only as navigation context.
V58.2 introduces no entitlement changes.

# Canovia Parallel Development Operating Model

> Status: **ACTIVE — development process**, not a product feature, deploy decision, or completion claim.
> Adopted: 2026-10-09. Repository: `1kz-ma1/Canovia-web`.
> Companion: [Active lane handoffs](ACTIVE_LANE_HANDOFFS.md).
> Canonical backlog remains [ROADMAP.md](ROADMAP.md); this document does **not** create a second product roadmap or Canovia Task database.

## 1. Purpose and authority

Canovia has several large workstreams in flight. Separate **execution ownership** so that Learning, Development and shared UX can progress without blocking one another, while small production fixes remain possible.

Authority (unchanged):

1. Actual latest `main` code, exact-SHA CI, deploy and device observations.
2. `docs/CANOVIA_PRODUCT_SPEC.md`, release and monetization specifications, and relevant versioned specs.
3. `docs/development/ROADMAP.md` for product intent and acceptance; active WIP drafts for explicitly scoped work.
4. `docs/future/*` only for preserved concepts; **never** auto-promote them to current requirements.

Follow [development workflow](../CANOVIA_DEVELOPMENT_WORKFLOW.md) and `AGENTS.md`. This operating model does not authorize direct pushes to `main`, forced merges, automatic deploys, unattended agents or private Canovia-context reads.

## 2. Lanes and ownership

A lane owns a **decision boundary**, not all the files it may touch. Shared files still need coordination.

| Lane | Owns | Explicit boundaries |
| --- | --- | --- |
| **Learning** | Study workspace, adaptive questions/modes, Answer Events, Question Bank/Recall, exam profiles, learning assessments, bookkeeping **as a Study specialization** | Cross-workspace profile rules belong to Intelligence; payments/auth to Platform; official exam claims need verified sources. |
| **Development** | GitHub roadmap preview, evidence, provider/repository connection UX, Developer Workspace, individual/team project workflow, GitHub-first handoff | Private repo and delegated authorization require Platform/security review; no silent Task progress mutation. |
| **Intelligence** | Personalization Bootstrap, Living Profile, evidence provenance, cross-domain recommendation policies, bounded AI context/cost rules | Learning grading/weakness models remain Learning; actual production AI actions require domain approval. |
| **Experience** | Shared onboarding shell, Overview, navigation, generic UI/accessibility, common visual components, Map/Constellation | Domain-specific Study/Developer screen semantics remain with that domain's lane. Avoid global CSS/Blade hotspots. |
| **Platform** | iOS bridge/distribution, release gates, authentication/security, entitlement/billing, Render/DB/staging, observability/reliability | No production config/DB/service changes from documentation alone. Verify live environment separately from CI. |
| **Expansion** | Career and other **new domain** journeys, experiments and public maturity proposals | Career privacy/permissions co-reviewed with Platform; bookkeeping is Learning, not Expansion; Future documents do not imply active scope. |
| **Hotfix** | Reproducible existing-product defects, urgent regression and small reversible UX corrections | A defect only present on an unmerged feature branch stays in its owning lane. Cross-boundary defect escalates to Orchestrator. |
| **Orchestrator** | Cross-lane dependencies, release order, ownership disputes, shared spec integration, PR collision review and evidence reconciliation | Does not become a mandatory reviewer for every isolated PR or silently edit personal Canovia tasks. |

### Decision routing for overlapping work

- `StudyWorkspace` content → Learning; shared workspace switcher, first-run chrome → Experience.
- GitHub API observations and developer agent handoff → Development; OAuth/account identity, external permission grants and resource security → Platform co-review.
- Domain-specific weak-area ordering → Learning; multi-domain Living Profile updates and source confidence → Intelligence.
- Map general interaction → Experience; team member permissions / Developer screen behavior → Development.
- Study Bookkeeping materials → Learning; Career/MATCH plus → Expansion + Platform privacy review.
- `docs/CANOVIA_PRODUCT_SPEC.md`, route/navigation shell, shared layout/CSS, Plan/Task data model and FeatureAccessService → **shared hotspot**; announce change in the coordination record before editing.

Map-specific parallel file ownership remains in [MAP_UI_PARALLEL_DEVELOPMENT_BOUNDARIES.md](../MAP_UI_PARALLEL_DEVELOPMENT_BOUNDARIES.md); do not replace it.

### Chat entry: choose the lane only when needed

**Intent:** make `Canoviaの実装をしたい` an easy start, without interrupting specific implementation requests.

| User message/context | Expected response |
| --- | --- |
| `Canoviaの実装をしたい` or `次の実装を進めたい`, with no known lane/task in the conversation or handoff | Ask once which lane to work on; offer the eight choices below. |
| `Learningの実装を進めて`, `初回画面のはみ出しを修正して`, or equivalent clear scope | Resolve named/primary lane and start after checking latest GitHub; no redundant classification question. |
| `進めて` when the conversation already has an active lane and slice | Continue existing lane/slice; preserve context. |
| `開発全体を分類して` or `複数レーンを調整して` | Route to Orchestrator without asking which product lane. |
| User reports a defect found during another feature branch | If only on the unmerged feature, keep it there; if on latest main, route to Hotfix; ask only if evidence is insufficient. |

Suggested one-question reply when ownership is unspecified:

> Canoviaのどの領域の実装を進めますか？
>
> ① Learning — 学習/AI演習  ② Development — GitHub/開発支援
> ③ Intelligence — AI/パーソナライズ  ④ Experience — UI/UX/Map
> ⑤ Platform — iOS/課金/インフラ  ⑥ Expansion — Career/新分野
> ⑦ Hotfix — バグ/軽微な修正  ⑧ Orchestrator — 開発全体の調整
>
> 番号か名前で指定できます。

A normal conversational question is sufficient; **do not require a form, eight separate prompts or explicit confirmation after the choice**. When relevant, mention that up to three primary feature lanes are prioritized (Learning / Development / Experience), but do not force that priority over an explicit choice. After the answer, read [ACTIVE_LANE_HANDOFFS.md](ACTIVE_LANE_HANDOFFS.md) and latest repo evidence before choosing work.

This is **repository-level guidance** for agents/chats that read these files. It does not itself configure the global behavior of unrelated new ChatGPT conversations. If the repository context is missing in a new chat, the user may need to point the chat at `AGENTS.md` or use the entry prompt documented in the handoffs.

## 3. Concurrency policy

Start with **three primary feature lanes concurrently**: Learning, Development and Experience. Intelligence and Platform may each run a bounded dependency or stability/security slice when needed; Expansion remains intake/design-first until P0 validation capacity is available. Hotfix is always available, but not an unlimited fourth product feature.

Every active work item has: lane, owning chat/session, exact main SHA used as base, branch, scope, touched/shared hotspots, prerequisites, acceptance tests, PR, CI SHA/status, deploy/device verification state and next handoff.

**No claim of autonomous execution:** a chat only performs work when actually invoked. Separate chats/sessions can progress independently, but branches/worktrees do not keep agents running in the background.

Do not have multiple sessions edit the same working directory/branch. Use distinct Git worktrees for local concurrent work, or independent GitHub branch-based sessions.

## 4. Branch → integration protocol

1. Re-read latest `main`, `AGENTS.md`, this model, the canonical roadmap, the lane's spec and any affected cross-lane contract. Confirm that the work is not already merged.
2. Record the proposed scope and shared hotspots; split into **small reviewable slices** with explicit acceptance and backward compatibility.
3. Create a dedicated `feature/<lane>-<slice>` or `fix/<lane>-<issue>` branch from latest `main`. A lane is **not** a permanent merge branch and does not replace `main`. Existing open PRs keep their own heads/bases.
4. Work in an isolated session/worktree. Never rewrite another active branch or modify another lane's uncommitted work.
5. If changing a shared contract/API/schema/permission boundary, publish the contract and compatibility tests first; dependent lanes build against the accepted interface, not a guessed future shape.
6. Run relevant targeted tests and integration/security regressions; open a PR to `main` with Japanese summary, changed specs, exact verification and deployment uncertainty.
7. Before merge, refresh/rebase onto latest `main` when needed, inspect full diff and conflict resolution, required CI for the **current head SHA**, and GitHub mergeability. Obey current `AGENTS.md` owner-approved merge policy; never merge with pending/failed checks or insufficient safety verification.
8. After merge, reconcile active lane record and canonical `ROADMAP.md` **only if acceptance/evidence actually changed**. A merged PR means integrated, **not** deployed, device-tested, released or externally authorized.

### Conflict and escalation matrix

| Situation | Default resolution |
| --- | --- |
| Different files, compatible contract | Independent PRs; normal CI and integration gate. |
| Same shared Blade/CSS/spec file | Orchestrator assigns one short-lived owner; other lane creates a separate partial/module or waits for the shared contract. |
| Schema, auth, entitlement, billing, AI side effects, privacy, GitHub writes | Contract-first and cross-lane review; broaden tests. No silent migration or user-data rewriting. |
| `main` changes after branch creation | Refresh exact SHA, compare/rebase safely; rerun applicable tests/CI. Never assume prior CI covers new head. |
| Unmerged feature's own bug | Fix in that feature branch, not via unrelated Hotfix against `main`. |
| Production bug while a large feature is open | Hotfix from latest `main`; merge safely; feature lane rebases afterward. |
| Unclear ownership or contradictory specs | Pause affected slice; Orchestrator records decision and references before resumption. |

## 5. Verification vocabulary and release gate

`Planned` = intent only; `In Progress` = work evidenced by branch/PR; `Implemented` = integrated code; `CI Verified` = required checks green at **exact SHA**; `Deployed` = observed environment at SHA; `Device Verified` = explicit target-device E2E; `Unknown` = not verified. These fields must never be collapsed into a single completion percentage.

For Study/Development public readiness, prioritize first-session success, resume/autosave, non-owner isolation, 403/500 errors, zero-action/empty states, mobile Safari/PWA, iOS WKWebView and desktop. Never claim production readiness solely from repository inspection.

## 6. Cross-lane dependencies and rollout

| Dependency | Provider → Consumer | Gate |
| --- | --- | --- |
| Identity, consent, repo access & private MCP security | Platform → Development | Actor/repo/Plan authorization and opt-in validation before live private reads/writes. |
| Evidence source provenance and confidence | Intelligence ↔ Learning / Development | Preserve observed vs self-report vs inferred; no automatic Plan/Task completion. |
| Shared Overview, navigation, onboarding | Experience ↔ Learning / Development | Stable workspace contract, mode restore and accessibility regressions. |
| Release Level, entitlement, cost budget | Platform → all lanes | No activation, provider charges or public rollout without separate verified gates. |
| Future Career experiences | Expansion → Experience / Intelligence / Platform | Explicit promotion to active scope plus privacy and release acceptance. |

Publication focus stays **Study + Development**. Platform reliability/security is an override when needed. Expansion must not consume the critical path simply because a future concept exists.

## 7. Work-item and handoff contract

Use the following compact fields in a GitHub Issue/PR or in [ACTIVE_LANE_HANDOFFS.md](ACTIVE_LANE_HANDOFFS.md):

- `lane`, `item`, `owner-session`, `status`, `priority`
- `main-base-sha`, `branch`, `pr`, `shared-hotspots`
- `source-specs`, `dependencies`, `acceptance`, `tests+ci-sha`
- `deployed-sha`, `device-evidence`, `known-unknowns`, `next-action`

Do not use Canovia Task IDs as substitutes for PR/Issue identifiers. Use GitHub-native roadmap intent and independent evidence as specified in [GITHUB_NATIVE_ROADMAP_CONTRACT.md](GITHUB_NATIVE_ROADMAP_CONTRACT.md). A chat starting a lane should use the handoff page, then independently re-read latest `main` and revalidate every status.

## 8. Change control

- Orchestrator owns changes to lane definitions, the shared hotspot registry and integration protocol.
- Individual lanes own their specific feature/spec PRs; update active handoffs when changing dependencies.
- Product decisions and feature scope changes belong in the relevant current spec, not only in chat history.
- Existing WIP files keep their documented deletion rules; this model does **not** mark them done or delete them.
- Do not create a second app-side orchestration service or a background agent from this process document alone.

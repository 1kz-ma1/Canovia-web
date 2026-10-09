# Canovia Active Lane Handoffs

> Status: **operational snapshot**, updated 2026-10-09 (JST).
> Recheck latest `main` and open PRs every time this file is read. A listed item is **not** evidence that a chat or background agent has started it.
> Canonical product intent: [ROADMAP.md](ROADMAP.md). Operating rules: [PARALLEL_DEVELOPMENT_OPERATING_MODEL.md](PARALLEL_DEVELOPMENT_OPERATING_MODEL.md).

## 0. Snapshot and work-in-progress preservation

- The canonical [product roadmap](ROADMAP.md) is actively maintained; **do not duplicate its acceptance table here**. Use this document to assign *execution lanes* and record cross-lane dependencies.
- Open PRs observed on 2026-10-09: [#409](https://github.com/1kz-ma1/Canovia-web/pull/409) (isolated MCP staging error-log redaction; Platform; open/mergeable when inspected), and [#310](https://github.com/1kz-ma1/Canovia-web/pull/310) (Living Profile observed development persistence comparison fix; Intelligence; open, not mergeable when inspected). Neither was changed by this reorganization.
- The active [State-first prerelease WIP](../wip/2026-10-08_STATE_FIRST_PRE_RELEASE_EXECUTION_SPEC.md) spans Experience, Learning, Development and Expansion. P0.0–P0.4 and P1.1 document integration; P1.2/P1.3 have limited slices with incomplete cost/branching/real-device acceptance. **Keep this file until its own reconciliation/deletion conditions are met.**
- [Adaptive Learning Experience draft](../learning/adaptive-learning-experience-draft.md) is a persistent current draft, **not** a disposable WIP. New Learning Run work has partial slices; formal exam profile, general response types and device verification remain separate.
- [Personalization Bootstrap WIP](../wip/PERSONALIZATION_BOOTSTRAP_IMPLEMENTATION.md) reports Phases 1–3 implemented with manual release review pending; Phase 4 remains planned. Preserve its own gate/cleanup policy.
- [Future Architecture](../future/CANOVIA_FUTURE_ARCHITECTURE_OVERVIEW.md) is **concept context**, not work automatically approved for execution.

## 1. Parallel execution wave

`active` below means **eligible to start in a separate chat**, not already executing.

| Lane | Wave | Assignment / next independently deliverable slice | Gate and boundary |
| --- | --- | --- | --- |
| **Learning** | **W1 / ACTIVE TARGET** | Reconcile current Adaptive Learning code with the draft and `ROADMAP.md`; prioritize 1-question end-to-end reliability, other response types, real Study-first-user verification and official exam profile gaps. | Preserve old Attempt/Task semantics. Verify exam sources; current draft may lag main. |
| **Development** | **W1 / ACTIVE TARGET** | Test GitHub-native Markdown/PR/Issue/CI preview and connector-backed AI handoff with a real authorized repository; record exact repo/path/SHA and gap between CI, deploy, device. Keep automated Task mutation out of scope. | Private delegated resource permission and MCP OAuth belong to Platform security contract; no claim of working ChatGPT delegated access until E2E. |
| **Experience** | **W1 / ACTIVE TARGET** | First-run and Overview/mobile correctness: test onboarding overflow, workspace-mode restoration, 403/500 UX, shared navigation/empty state; isolate common UI changes in small PRs. | Do not alter Study/Development domain rules; coordinate shell/CSS shared hotspots. |
| **Intelligence** | **W2 / BOUNDED** | Review Living Profile regression [#310](https://github.com/1kz-ma1/Canovia-web/pull/310), its stale/merge-conflicting status, existing tests and actual current main; choose minimal fresh fix **only if still reproducible**. Independently collect manual release review gaps for Personalization Bootstrap. | Do not merge an unmergeable/stale PR or duplicate an already-fixed change. No unsupervised profile update. |
| **Platform** | **W2 / STABILITY + SECURITY** | Safely finish/verify existing staging log-redaction [#409](https://github.com/1kz-ma1/Canovia-web/pull/409) separately; preserve isolated Render staging & database OFF posture, then plan iOS/regression, TestFlight, billing/entitlement and production launch readiness in separate slices. | Real Render PostgreSQL cutover and IdP remain **not authorized by this lane plan**; never put internal DB URLs/secrets in GitHub. |
| **Expansion** | **W3 / INTAKE** | Prepare scoped Career experiential exploration/MATCH plus backlog from current WIP; do not start data/PDF integration until permission, privacy and public maturity criteria are explicit. | New user-facing Career scope needs product decision and Platform review. Bookkeeping question quality belongs to Learning. |
| **Hotfix** | **ON DEMAND** | Production-reproducible blockers/low-risk visual fixes discovered while other lanes work. | Branch from latest main. Bug exclusive to a feature branch → that feature's owner. |
| **Orchestrator** | **ACTIVE ON REQUEST** | Maintain cross-lane owner/contract decisions, identify overlapping changes and reconcile finished PR evidence into canonical roadmap. | No automatic implementation, no extra Canovia DB state, no compulsory review for isolated fixes. |

## 2. Dependency queue (minimum shared agreements)

| Key | Contract / shared hotspot | Lead | Consumer(s) | State / next action |
| --- | --- | --- | --- | --- |
| D1 | Study/Developer workspace shell, mode selection/restoration, initial navigation | Experience | Learning, Development | Shared UI contract; regression owner before changes to global layout/JS. |
| D2 | Provenance: known/observed/inferred, evidence-to-progress boundary | Intelligence | Learning, Development | Existing source contract; schema/policy changes require acceptance tests first. |
| D3 | GitHub App private repo access, OAuth resource, consent, actor/Plan scope | Platform + Development | Development | Current public roadmap read contract remains. Isolated staging is not delegated production capability. |
| D4 | Release level, entitlement, AI budget, rollout | Platform | All | OFF-by-default/new cost gates. No paid production/staging change from this spec. |
| D5 | Plan/Task/Domain/Team shared persistence and assignment | Development + Intelligence | Experience, Learning, Expansion | Domain/Collaboration/Specialization already separated in early slices. Direct Task assignee remains a distinct backlog, not inferred. |
| D6 | Common product spec and `docs/development/ROADMAP.md` | Orchestrator | All | One integrating owner for shared spec edits; lane PR adds precise evidence links. |

## 3. Current priorities, grounded in existing specs

**P0 public-readiness focus:** Study + Development user value, release stability, correct first-run and resume across iOS WebView/PWA/desktop. Verify exact deployed SHA rather than treating `main` as live.

**P1 bounded enabling:** first-run/Plan Draft acceptance gaps, Personalization manual verification, iOS TestFlight planning, real AI-cost/quota constraints, cross-domain integration contracts.

**P2 after P0 gates:** Career/MATCH plus expansion, bookkeeping coverage enhancement within Learning, complex Development agent automation, future platform concepts. P2 does not imply cancelation.

**Known staging boundary (2026-10-09, verified):** The existing Free Render MCP staging Web is **connected** to its isolated Free PostgreSQL 17; Render deployment `dep-db460sflk1mc73ev4g3g` completed live and an independent HTTPS smoke confirmed `/up=200`, while account/OAuth/MCP, legacy health and static endpoints remained `503`. This is **not** an IdP/ChatGPT OAuth launch or activation of shared Plan data. The Free DB expires on 2026-11-08 UTC. See the [V59 live acceptance](MCP_IDP_SELECTION_AND_STAGING_POSTGRES_2026_10_09.md) and [roadmap](ROADMAP.md); do not duplicate this staging resource.

## 4. Start-of-chat instruction for each lane

When creating a separate execution chat, specify exactly one lane and paste or refer to this instruction:

> Work on **Canovia-web / <LANE>**. Read the newest main/AGENTS.md/docs/README.md, docs/development/PARALLEL_DEVELOPMENT_OPERATING_MODEL.md, this handoff file, docs/development/ROADMAP.md and lane-relevant specs. Verify which acceptance is genuinely incomplete at exact SHA; do not assume this snapshot is current. Select one narrowly scoped slice, declare shared hotspots and dependencies, create an isolated branch/worktree, implement and test, open a PR and apply the existing verified-merge policy. Maintain relevant specifications and report PR/CI/deploy/device status separately. Do not change another lane's branch or unpublished WIP.

Suggested chat titles: `Canovia｜Learning`, `Canovia｜Development`, `Canovia｜Experience`, `Canovia｜Intelligence`, `Canovia｜Platform`, `Canovia｜Expansion`, `Canovia｜Hotfix`, `Canovia｜Orchestrator`.

### New-chat entry without choosing a lane upfront

When users prefer **the assistant to ask which category to implement**, they can open with:

> Canoviaの実装をしたい。GitHubの `1kz-ma1/Canovia-web` の最新 `AGENTS.md` と `docs/development/PARALLEL_DEVELOPMENT_OPERATING_MODEL.md` を確認して、担当レーンがまだ決まっていなければ分類を聞いて。

If the repository instructions are already available in the conversation, the shorter `Canoviaの実装をしたい` is enough. Agents must **not promise** that arbitrary new chats automatically have access to the repository instructions. Once selected, proceed with the lane's latest handoff without repeatedly asking.

### PR completion report (mandatory)

`Lane | Slice | Base SHA | PR | Head SHA | Relevant tests | Required CI result | Merge commit | Deploy SHA/environment (or Unknown) | Device E2E (or Unknown) | Cross-lane changes | Next dependency`

## 5. Integration update rules

1. Each session updates its relevant feature spec and, **only when status evidence changes**, proposes a concise canonical `ROADMAP.md` update.
2. When a shared contract changes, list affected lanes and backward-compatible behavior in the PR and update D1–D6 above if needed.
3. Orchestrator reconciles conflicting **claims**, not chat summaries; GitHub diff, current tests, SHA, deployment and device evidence govern status.
4. Do not prune active WIP or rewrite historical `docs/V*.md` solely to make classification look cleaner.
5. This roster is a scheduling aid only; it does not auto-create tasks, start work sessions, perform merges, or publish features.

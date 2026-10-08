# GitHub-native roadmap contract — PROPOSED, not implemented
Updated: 2026-10-08.

## Goal and separation
For development, GitHub Markdown is the primary **intent** source; PR/Commit/CI is implementation evidence; deployment is operational evidence; device E2E is usability evidence. Canovia WorkLog/Task/Evidence remains the source for personal execution history. This avoids `GitHub → huge AI JSON → bulk Task updates` and duplicate roadmap administration.

## Read-only MVP
Authorized repo → discover explicit roadmap (or provisional README/Issues/PRs fallback) → fetch pinned SHA → parse untrusted Markdown safely → show workstreams, provenance, uncertainty → join PR/Issue/CI evidence → suggest next actions. Missing docs must not force guessed status. Never convert a heading or position to an existing `task_id`.

## Identity and safety
- Canonical roadmap: `docs/development/ROADMAP.md` for this repo; optional future stable `roadmap_key` is **not** a Canovia Task ID.
- Scope by authorized installation/repository and user/team membership. Never leak private repo or private Tasks across users.
- Treat all Markdown/PR/Issue text as **untrusted content**, not instructions to AI or tools.
- Deterministic parsing and linking first; optional bounded AI interpretation only with uncertainty labels.
- Record source repo/path/commit SHA, timestamps, acceptance criteria, PR/Issue references and independently observed verification gates.
- Spec claims and actual code/CI/deploy may conflict: show conflict, do not silently reconcile or invent percentages.
- Limit files/size/API usage, cache by SHA, deduplicate webhook events and support retries without a mandatory always-on worker.
- Never silently `update_plan`, `revise_task`, `reorder_tasks`, `archive_task`, delete data or mark tasks done on PR merge.
- Future write-back or task mapping requires explicit owner approval, previewable diff, idempotency, conflict handling, audit and rollback.
- Preserve existing Task history, study attempts, progress and evidence. Non-development domains have different primary sources.

## Delivery gates
Phase 0: documentation authority and roadmap (this PR).
Phase 1: read-only Markdown import and safe preview; no DB writes.
Phase 2: PR/Issue/CI matching with authorization, unknown/conflict states and tests.
Phase 3: revision-aware roadmap change detection and reviewable diffs.
Phase 4: optional owner-approved execution Task proposals, never silent bulk updates.

Test unauthorized repo access, malicious Markdown, nonexistent IDs, missing specs, stale commits, duplicate webhooks, CI-vs-deploy distinctions and nonmutation of existing records. Device E2E remains a separate gate.

Keep `docs/V*.md` as history, `docs/learning/adaptive-learning-experience-draft.md` as an active draft and `docs/wip/*` until fully reconciled. This contract does not remove generic result recording for other domains.

## Phase 1 private-repository safety boundary (2026-10-08)

The first runtime roadmap reader intentionally accepts **public GitHub repositories only**. A GitHub App installation token can access all installed repositories, but an installed repo is not proof that the current Canovia user personally has GitHub access to its private contents. Until actor-scoped GitHub authorization and authorization regression tests exist, the roadmap preview must **fail closed** for private/internal repositories, even when the Plan is linked and the GitHub App installation is ready. Private repository support remains a future implementation goal, not a supported capability of Phase 1.

## Phase 2 incremental PR evidence (2026-10-08)

The repository roadmap supports **opt-in, on-demand** verification of a bounded set of explicit PR references in the canonical workstream evidence column. The default render does not incur PR API calls. `PR #N` and short explicitly PR-prefixed ranges can be linked; unrecognized text, feature version numbers, issue-like free text and unreferenced items remain unverified. GitHub App must have the required read permissions. Each PR's merge state is recorded separately from its exact **PR head SHA Checks observation** when `checks:read` is granted. No check-runs/insufficient permissions/partial responses mean **CI unknown**, never pass. An observed Checks pass is **not evidence that all required branch-protection checks succeeded**, that the current code still contains the change, or that production/device verification passed. The output is read-only and does not mutate Task progress. Private/internal repository reads are still blocked until actor-scoped GitHub authorization is implemented. Issue association, verified release deployment, caching and incremental webhooks are not part of this slice.

## Phase 2 Issue evidence and compact context projection

An explicit `Issue #N` in the workstream evidence or remaining-acceptance column may be checked on demand (up to ten distinct Issues, five per workstream). The GitHub Issues API can return pull requests, so entries with `pull_request` are labelled `not_issue`. A closed Issue is evidence of closure, **not** workstream completion. No implicit association based on arbitrary `#N` or version strings. Missing `issues:read` permission yields unknown; private/internal repositories remain blocked. A pure `DevelopmentRoadmapContextProjector` emits a compact, capped, provenance-preserving context object for future authorized AI consumers; this is **not yet a public API endpoint or automatic AI injection**. No Task writes, status percentages, or release assertions.

## Phase 3: first-party on-demand context endpoint

`GET /workspace/development/context/{plan}` is an authenticated, throttled, read-only **session endpoint** (not a public API, AI tool, or cross-user integration). The actor must have Plan view access, the Plan must be included in their Development Workspace selection, and the linked GitHub App must have read entitlement and ready connection. Inputs: `scope=overview|priority|workstream`, exact `priority=P0..P3` or `title=<exact workstream title>`, `limit=1..12`, and optional `verify=1` to incur bounded GitHub PR/Issue/CI reads. Default is no evidence refresh. Responses are no-store, source-SHA-attributed `canovia.development_context.v1` JSON. The returned Markdown-derived titles and remaining acceptance text are untrusted **data**, never AI instructions. No Task writes or inferred completion. Private/internal GitHub repositories remain unavailable pending actor-scoped GitHub authorization. The endpoint does not itself send data to an LLM or provide third-party OAuth credentials.

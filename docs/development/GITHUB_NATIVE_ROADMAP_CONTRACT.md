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

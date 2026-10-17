# AP owner-first MCP — Live94 selective-backport acceptance addendum

Date: 2026-10-18 JST. Status: **CURRENT CANDIDATE / SYNTHETIC-VERIFIED / PRODUCTION HOLD**.

This is a narrow continuation of [AP owner-first lean release scoping (PR #784)](https://github.com/1kz-ma1/Canovia-web/pull/784), the [real-account security gates](https://github.com/1kz-ma1/Canovia-web/blob/main/docs/development/MCP_REAL_ACCOUNT_CONNECTION_RELEASE_GATES_2026_10_14.md), and [PR #785](https://github.com/1kz-ma1/Canovia-web/pull/785). It is not a second release authorization.

## 1. Current scope and acceptance

- Goal: make the existing owner's ordinary Canovia AP Study Plan readable from ChatGPT **before** general public launch. Initial scope is one owner, one **non-public / non-collaborative** Study Plan, one-day `overview` consent.
- Observable MCP content: owner-recorded `title` (bounded) and `recorded_deadline`. No Task detail, practice/evaluation history, learning progress, Learning Context Snapshot, evidence, answers, write access, or account discovery.
- Owner must separately establish a GitHub-only PKCE-linked identity, explicitly confirm one Plan consent, then independently connect ChatGPT. The same immutable verified Auth0 issuer and GitHub subject must be used by both clients; email and login-page success are not authorization.
- In the Account UI, the owner can see the exact Plan ID to supply to ChatGPT. The MCP `initialize` instructions are Study-only when AP pilot is enabled; the legacy Development instructions remain unchanged otherwise.
- Private Plan policy checks `is_public=false` and `is_collaborative=false` both at consent and at every authorized read. Visibility, owner, category, linked identity, client, scope, grant and expiry changes are re-evaluated at call time.
- All MCP/JWT/AP/GitHub/consent switches are OFF by default. The legacy 94 migration source files are unchanged, with no new DB table or column introduced.

## 2. Evidence boundaries (not production acceptance)

- Source baseline: old Live `15abdc9de6f24b5250fb255c579d1aa55facaf1a`. Branch `feature/ap-mcp-live94-auth0-20261018` remains a Draft PR targeting `production` for **review only**, not a valid production release PR.
- CI: synthetic RS256/JWKS, Link PKCE/CSRF, consent, read, revoke/denial and old Development regression on SQLite. Disposable MySQL 8.0/8.4 workflows apply 94 migrations, repeat migrations, and exercise the Study read/link/consent/revoke tests.
- This candidate has **no** actual Auth0 OAuth token, private Canovia Plan read, Web/iPhone acceptance, real Aiven schema or restore, Render deploy, or ChatGPT real-owner proof.
- Every new commit invalidates previous CI as evidence for the new HEAD until the new exact SHA passes.

## 3. Explicitly unresolved release-policy mismatch (STOP)

The repository's active `AGENTS.md` and `docs/CANOVIA_DEVELOPMENT_WORKFLOW.md` require:
`latest main -> release/* (fixed main SHA) -> production PR -> merge commit`.
`production-promotion-policy.yml` requires both `release/*` naming **and** that exact source HEAD to be an ancestor of current `main`.

**PR #785 does not meet this contract:** it starts from old production and selectively backports 24+ files. A green narrow CI **cannot** be promoted through the existing production policy. Retargeting this branch to `main` or force-merging is unsafe because `main` already has newer shared auth/MCP code and additional migrations. Do not modify the promotion workflow silently or bypass it to meet a date.

Decision required before shipping:
- **Path A (narrow Live94 candidate):** owner approves a separately reviewed, bounded exception *proposal* to release promotion rules. Its rule change, exact candidate SHA, complete production diff, branch protection, release safety checks and rollback contract must be verified before any production merge. No exception has been approved or implemented.
- **Path B (normal main release):** use ordinary `main -> release/* -> production` with all dependency/migration/restore review, while limiting actual MCP access through server flags and owner allowlist. This may require more operator work but preserves current policy.
- Do not invent an alternative `release/*` SHA that is ancestor of `main`: it would **not** contain this stand-alone selective backport by default.

## 4. Owner/operator acceptance order

1. Pin exact candidate and verify all CI jobs on that SHA, both MySQL series, negative visibility and consent guards; scrutinize entire diff and the old Web startup contract.
2. Choose Path A or B under the separate release decision. Verify real Aiven migration ledger/schema (operator-local least-privilege metadata only) and a feasible independent recovery path. Full Aiven backup existence alone is not a tested restore.
3. Confirm production Auth0 issuer/JWKS/PKCE/RFC9207 GitHub-only login, first-party vs ChatGPT client IDs (including internal CIMD mapping), resource/audience/scope/redirect and actual signed JWT claims. Use non-sensitive pass/fail evidence only; no secrets or raw user IDs in GitHub/CI.
4. Require explicit operator GO for Render branch/env/deploy, and independent GO for enabling OAuth/MCP flags. No implicit migration on startup. Confirm existing account login/session/CSRF and physical Web/iPhone smoke with owner-only controls OFF before allowing a real grant.
5. With fresh individual consent, verify missing-grant DENY -> exact owner Plan overview READ -> revoke/unlink DENY using **the same still-valid token**, then emergency OFF. Check no other owner/Plan or Task/history became accessible.
6. Only after this first acceptance, separately design/authorize `Learning Context Snapshot`/Task/learning-history reads for effective AP tutoring. Current overview by itself does not enable personalized learning diagnosis.

## 5. Current decision

**Keep Draft/HOLD.** No production branch updates, Render or Auth0 settings, Aiven operations, real user-plan reads, billing or public roadmap release labels are authorized by this document. Existing plans and data remain unchanged.

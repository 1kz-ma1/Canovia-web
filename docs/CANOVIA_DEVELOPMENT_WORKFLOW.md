# Canovia Development Workflow

## Source of truth

The canonical implementation is always the latest `main` branch of:

`1kz-ma1/Canovia-web`

Before starting an implementation, bug fix, or specification update:

1. inspect the latest `main`
2. confirm the current implementation and relevant docs
3. create a dedicated branch from the latest `main`
4. implement and verify on that branch

## Specification authority

Current implementation decisions should follow:

```text
latest main implementation
→ docs/CANOVIA_PRODUCT_SPEC.md
→ relevant implemented/versioned specifications
→ docs/future/*
```

`docs/future/` is long-term design context, not an implementation queue. A Future Design must be explicitly promoted and revalidated against latest `main` before implementation begins.

See `docs/future/README.md` for the full boundary.

## Merge policy

**Never push implementation work directly to `main`.** Render can automatically deploy from `main`, so even a low-impact merge is a production-capable action.

Normal workflow:

```text
latest main
  -> feature/fix branch
  -> implementation and relevant regression tests
  -> Pull Request with impact and risk assessment
  -> verify required CI, review the actual diff and branch status
  -> PRODUCT OWNER APPROVED ALL PR TYPES (2026-10-08)
  -> All relevant tests + required CI green and PR mergeable: agent may squash merge
  -> Failed/pending/insufficient checks, conflicts or unresolved blockers: fix first; no merge
```

### Agent may merge without further approval (owner authorization 2026-10-08)

The product owner has **temporarily authorized agent merge of all PR types, including previously high-impact/manual-review categories**, provided the change is sufficiently verified. This applies to subsequent PRs until explicitly withdrawn or changed by the product owner.

Only when **all** are true:

1. The PR targets `main` from a feature/fix branch; direct pushes to `main` are still prohibited.
2. The full diff and potential data/privacy/security/billing/production side effects are inspected; the PR is mergeable against current `main` and no unresolved required review, branch protection or conflict exists.
3. All required checks have **completed successfully**. Relevant tests cover the changed behavior, including permissions/data isolation and regression tests appropriate to the actual risk.
4. No unresolved critical data integrity, privacy, security, external side-effect or operational risk remains. High-impact changes demand more verification rather than a distinct owner click.

The owner authorization is to **merge verified PRs**, not to bypass safety gates, invent a successful deployment, suppress failures or force-merge a conflicting branch.

When all conditions hold, squash merge with an expected head SHA, confirm GitHub reported success, and tell the user the PR number, test result, commit SHA, and any unverified deployment status. Never claim production verification solely from CI.

### Higher-risk PRs: verify more, not automatically stop

The product owner has approved merges of these categories **after adequate relevant tests and required CI success**. Examples requiring explicit impact assessment, domain-appropriate tests and review of actual diff:

- Database/schema migrations, backfills, destructive data changes, or changes to stored user data semantics.
- Authentication, authorization, account/session security, secrets, PII or sensitive information, permissions, or privacy boundaries.
- Payment/billing, pricing, entitlements, monetization, or externally binding actions.
- Production infrastructure, deploy/build configuration, environment variables, incident remediation, or changes with notable reliability/cost effects.
- GitHub or other third-party write actions, webhooks with side effects, broader automation, cross-cutting architecture, changes to AI actions with material user impact, or removal of existing functionality.
- Unclear business requirements, trade-offs needing the owner's judgment, production impact that cannot be bounded, failed/pending CI, inadequate tests, conflicts, or pending required human reviews.

If verification is insufficient, a conflict remains, required checks are pending, or an unresolved issue cannot be verified, **leave the PR open** and report the blocker. The current authorization is broad and persists until the owner changes it, but does not waive mergeability or safety.

### Production and emergency exceptions

An agent merge is **not** direct authorization to deploy, roll back, alter a production database, or change production settings. Render may automatically deploy after merging to `main`; monitor/verify deploys separately when possible, and label them unverified otherwise.

Direct updates to `main` remain exceptions only when the user expressly authorizes that specific operation. Emergency changes should still prefer a reviewed PR with an explicit risk summary.

## Pull Request expectations

Write PR titles, descriptions, verification results, and known limitations in Japanese. Keep code identifiers unchanged.

A PR should include:

- implementation summary
- specification decisions reflected
- migrations, if any
- tests added or updated
- compatibility / Free-path impact
- known limitations or deferred work
- explicit confirmation that payment / billing behavior was not added when outside scope

## Deploy behavior

Render production deploys from `main`.

Creating or updating a feature branch must not be treated as production deployment.

After the user or agent merges a PR, Render auto-deploy may be monitored separately when requested or when production verification is part of the task.

## Why this policy exists

The workflow keeps:

- `main` stable
- review ownership with the user
- implementation history understandable
- AI changes inspectable before production
- emergency fixes distinguishable from ordinary feature work

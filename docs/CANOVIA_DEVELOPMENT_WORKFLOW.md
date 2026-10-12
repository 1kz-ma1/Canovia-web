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

**Never push implementation work directly to `main`.** Develop on `main`; publish to the separately controlled `production` branch. Do not configure Render Auto Deploy against `main`.

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

An agent merge into **`main` is not authorization to deploy**, roll back, alter a production database, or change production settings. Production promotion is a separate release and requires an explicit product-owner go/no-go.

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

### Controlled production promotion and iOS Web delivery (approved 2026-10-13)

`main` is the fast-moving integration branch. The separate `production` branch
starts at last verified Render Live SHA `15abdc9de6f24b5250fb255c579d1aa55facaf1a`.
**Neither a green development PR nor autonomous squash merge into main authorizes production.**
No direct or force pushes to production.

Release routine:
1. Pin exact main SHA and cut a release candidate branch `release/YYYY-MM-DD-<shortsha>` **before any live DB audit**. Freeze release scope and review *all* unshipped paths, migrations, Composer/deployment/startup changes, data/privacy/feature flags, and required CI.
2. For MySQL migrations, rehearse all pending operations on disposable MySQL 8.0/8.4
   (including legacy-row retention and repeatability); run the reviewed Mac
   metadata-only Aiven audit against the **exact chosen source SHA**.
   Both the restricted Aiven ledger and physical schema must match all migration
   files; no UNKNOWN, partial-DDL or unexpected pending states.
3. Independently confirm current Aiven backup, realistic recovery route, and
   the owner's acceptance of **untested restore risk** on the Free service,
   plus current effective Render security/session/DB/worker configuration.
   Snapshot existence is not a successful fork/restore test.
4. Never audit moving `main` and then promote a different commit. Open a **release/* -> production** PR from the pinned candidate; the workflow rejects a release head that is not an ancestor of current `main`. The PR requires owner
   production go/no-go, a reviewed diff, and passing **Production Promotion Policy
   Gate**, the unconditional **production-mysql-integrity** reusable MySQL 8.0/8.4
   matrix, **V56.12 Workspace Resume
   Validation**, and **Production Migration Recovery Validation**. The development
   main branch keeps its changed-path-only MySQL CI to avoid slowing every
   ordinary PR, while production promotions always run both MySQL engines.
   Promote using a **merge commit**, never squash: retain release/main ancestry so future
   promotion PRs do not repeatedly reintroduce prior changes.
5. **First release only:** Render Auto Deploy must remain OFF. Owner changes the
   watched branch to production via Render dashboard (the connector cannot modify
   service branch). Confirm Live still points to old SHA, then owner explicitly
   authorizes one manual deploy to pinned, promoted production SHA. Verify actual
   Live SHA, successful migrations and health, login/session, Learning, developer
   event ordering, iOS WKWebView/PWA and desktop smoke.
6. **After that first release is accepted**, owner enables Render Auto Deploy =
   commit with `production` as the watched branch. For future updates, the
   approved pinned release-candidate PR merge is the deliberate deployment trigger. Monitor Live
   SHA, errors and smoke after every promotion; pause auto deploy during incidents.

**Branch protection requires owner action:** configure GitHub Settings -> Rules ->
Rulesets (or Branch protection) for `production`: require PR, required CI
including the production promotion contract and MySQL/Workspace/Recovery checks,
reviewer approval or equivalent explicit owner release decision, and disallow
direct/force pushes. This assistant cannot change GitHub administration settings.
Until protection is verified, **do not enable production Auto Deploy**.

SwiftUI WKWebView loads live hosted Web content, so a Web deploy changes iOS
experience without an App Store binary update. Include iPhone smoke and
controlled rollout with every production release. Native Swift code itself
does **not** change through a Web deploy and requires separate binary distribution.
A Web image rollback cannot remove additive MySQL columns; never run blind
migration down, migrate:fresh, or restore over live writes.

Current hold and operator evidence: [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488).
Creating/updating `main` or a feature branch is *not* a production deploy.

## Why this policy exists

The workflow keeps:

- `main` stable
- review ownership with the user
- implementation history understandable
- AI changes inspectable before production
- emergency fixes distinguishable from ordinary feature work

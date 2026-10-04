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

**Do not push implementation work directly to `main`.**

Normal Canovia workflow:

```text
latest main
  -> feature/fix branch
  -> implementation
  -> syntax / regression review
  -> Pull Request
  -> user manually reviews / merges
```

ChatGPT / AI-assisted work should stop at PR creation unless the user explicitly requests a different action.

The user performs the normal merge manually.

Direct updates to `main` are exceptions only when the user explicitly authorizes them for that specific operation.

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

After the user merges the PR, Render auto-deploy may be monitored separately when requested or when production verification is part of the task.

## Why this policy exists

The workflow keeps:

- `main` stable
- review ownership with the user
- implementation history understandable
- AI changes inspectable before production
- emergency fixes distinguishable from ordinary feature work

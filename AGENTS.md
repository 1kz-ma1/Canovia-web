# Canovia Agent Instructions

## Repository workflow

- Treat the latest `main` of `1kz-ma1/Canovia-web` as the implementation source of truth.
- Before implementation, inspect the latest `main` and relevant specifications.
- **Do not push implementation or fixes directly to `main`.**
- Create a dedicated `feature/*`, `fix/*`, or equivalent branch.
- Complete implementation and verification on that branch.
- Create a Pull Request targeting `main`.
- Assess each PR's production impact and whether a product-owner decision is needed before merging.
- **Active product-owner authorization (2026-10-08): agent may squash-merge ALL types of PRs without requesting another owner decision** after relevant tests and every required CI job have completed successfully, the full diff is reviewed, the current base is mergeable, and no required reviewer / branch protection / conflict / unresolved safety concern blocks the operation. This authorization remains effective until the product owner changes it.
- This broad authorization removes the previous high-impact/manual-merge *approval* gate, **not** the verification or safety gates. Explicitly summarize production/data/security impact, test coverage and residual uncertainty for every PR. If the necessary checks fail, are pending, are insufficient for the risk, or the PR has conflicts, do not merge; repair and rerun or inform the owner when resolution needs information unavailable to the agent.
- Never merge when relevant tests or required CI fail, are pending, or are not sufficiently verified. Do not bypass branch protections or required reviewers.
- Direct `main` changes remain prohibited unless the user explicitly authorizes that specific exception.
- A merge into `main` may trigger an automatic Render production deployment; report the merge separately from actual deploy/production verification.

See `docs/CANOVIA_DEVELOPMENT_WORKFLOW.md` for the full workflow.

## Future design documents

- `docs/future/` contains long-term concepts and architecture preservation context.
- **Do not treat `docs/future/` as implemented behavior, an active backlog, or a current requirement.**
- Future documents never override latest `main`, `docs/CANOVIA_PRODUCT_SPEC.md`, or relevant implemented/versioned specifications.
- Only implement a Future Design when the user/Product owner explicitly promotes it into the current implementation scope.
- Before promotion, re-inspect latest `main` and produce/update a current implementation specification rather than coding directly from the Future Design.
- Future Design may still be used to avoid unnecessarily closing architectural extension points.

See `docs/future/README.md` for the authority and promotion rules.

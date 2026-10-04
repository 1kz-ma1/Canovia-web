# Canovia Agent Instructions

## Repository workflow

- Treat the latest `main` of `1kz-ma1/Canovia-web` as the implementation source of truth.
- Before implementation, inspect the latest `main` and relevant specifications.
- **Do not push implementation or fixes directly to `main`.**
- Create a dedicated `feature/*`, `fix/*`, or equivalent branch.
- Complete implementation and verification on that branch.
- Create a Pull Request targeting `main`.
- Stop at PR creation. The user performs the normal merge manually.
- Direct `main` changes are allowed only when the user explicitly authorizes that specific exception.

See `docs/CANOVIA_DEVELOPMENT_WORKFLOW.md` for the full workflow.

## Future design documents

- `docs/future/` contains long-term concepts and architecture preservation context.
- **Do not treat `docs/future/` as implemented behavior, an active backlog, or a current requirement.**
- Future documents never override latest `main`, `docs/CANOVIA_PRODUCT_SPEC.md`, or relevant implemented/versioned specifications.
- Only implement a Future Design when the user/Product owner explicitly promotes it into the current implementation scope.
- Before promotion, re-inspect latest `main` and produce/update a current implementation specification rather than coding directly from the Future Design.
- Future Design may still be used to avoid unnecessarily closing architectural extension points.

See `docs/future/README.md` for the authority and promotion rules.

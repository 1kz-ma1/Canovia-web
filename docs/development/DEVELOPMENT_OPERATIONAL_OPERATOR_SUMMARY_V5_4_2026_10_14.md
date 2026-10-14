# Development Operational Readiness V5.4 — Owner self-report summary

> Status: PR candidate / not deployed. This slice is **read-only** and does not alter production Release Quality Gates.
> References: [V4 independent backup/restore owner confirmations](DEVELOPMENT_OPERATIONAL_ATTESTATION_V4_2026_10_14.md), [V5.3 manual Render service check](DEVELOPMENT_OPERATIONAL_MANUAL_SERVICE_ATTESTATION_V5_3_2026_10_14.md), [V5.2 operator handoff](DEVELOPMENT_OPERATIONAL_RENDER_MANUAL_HANDOFF_V5_2_2026_10_14.md).

## Problem and scope

A Development owner can record three **different** manual claims (service/environment matching, backup performed, isolated restore performed), but has to inspect multiple parts of a collapsed panel to know which statement is missing or expired. V5.4 projects them together into a compact, single-owner context and suggests the next manual action without declaring the project safe to release.

## Implementation

`DevelopmentOperationalOperatorReadinessService::summarize(Plan, ?array $profile, ?DevelopmentOperationalTarget)` is a pure service that reuses V5.3's owner-scoped Render target and V4's per-claim projection. Missing/mismatched Plan, invalid/non-Render resource ID, invalid target revision or ownerless Plan returns null. The Development Work owner's existing private panel is the **only** call site; other members and Creative Plans still cannot see private service details.

It returns three individual source-labeled checks:
- `service`: Render dashboard service/environment manual match; `unknown`, `operator_confirmed`, or `stale`. This is not an authenticated provider observation.
- `backup`: V4 backup owner attestation, independently revision/storage/age bound; `unknown`, `operator_confirmed`, or `stale`.
- `restore`: V4 isolated-restore owner attestation with the same independent freshness rules.

For `data_store=none`, backup and restore show `not_applicable` **only on the owner's declared no-persistence assumption**, not machine-verified irrelevance. For unknown or unexpected data store, show recovery as unknown and direct the owner to classify the store first. The service does **not** infer a data store from the presence of a Render service or GitHub repo. All three rows include source text and recorded timestamps when available, and remain separate from the six V2 operational checks and seven V53.8 authoritative release quality gates.

## Next action: simple deterministic guide

1. Unknown storage (except explicitly declared `none`) → enter the data-store classification first, not a guessed backup instruction.
2. Otherwise inspect service → backup → restore in that order. `unknown` or `stale` becomes the next owner action; valid `operator_confirmed` and `not_applicable` are skipped.
3. Even if every relevant human claim is current, show `release_review`: **independent CI, running production SHA, real deploy and recovery proof remain separate requirements**. Never emit a numeric readiness percentage, a green `GO`, or a `verified` claim on this basis.

The next-action guidance is textual, not an automatic workflow or a new confirmation write endpoint. Existing explicit V4/V5.3 forms and revocation are unchanged. There is no provider call, token handling, background scheduler, telemetry, external AI access or additional database mutation. A service absence or invalid target cannot be displayed as a successful check.

## Safety refinement

V4 recovery projection now rejects backup/restore timestamps more than one minute in the future as `stale` (matching the existing V5.3 future-clock guard). This prevents clock-skew or anomalous stored claims from being presented as current. The original 7-day backup and 90-day restore review cadence, exact target Revision and data-store requirements remain unchanged.

## Tests / rollout boundary

- Unit: exact owner Plan/target validation, classification-first, declared no-data exception, service→backup→restore progression, expiry/rebind/store mismatch, future timestamp and NO independent release pass.
- Feature: owner-only Work UI and source labels, summary progression after existing explicit actions, none bypass, collaborative Editor privacy, read-only GET, no unintended evidence/Task writes.
- Dedicated Development workflow runs lint, Blade compile, and V1–V5.4 regression; Workspace, migration recovery and other required CI remain gates.

**No schema migration, production branch promotion, Render/Aiven operation, real service verification or device smoke is included in V5.4.** V5.3's additive production Aiven migration remains independently unapproved: CI-green `main` is not proof of Live schema upgrade or permission to perform one.

## Follow-up

After manual operator UX is validated with a real authorized Development Plan and appropriate release controls, a separate V6 provider credential/security evaluation may investigate provider-enforced least privilege and independent receipts. Never convert these self-reports into provider-authenticated evidence.

# V6.6 — PR #513 Evidence → Next Action route acceptance

Date: 2026-10-15 (JST). Scope: disposable Laravel HTTP route integration against candidate commit `bb8248f25545ae0d600fbb68f66c41c360004e2c`. The draft release candidate stays unchanged.

## Two observable boundaries

**Study.** User creates a Free Study Plan and invokes its first action (real Task projection). One synthetic practice question is answered via route, an illustrative assessment is submitted, and the user explicitly applies it. Assert separately:
- the original learning suggestion in the persisted Attempt;
- the authoritative Task progress and next-action value;
- an explicit policy matrix for weakness reinforcement, general practice and exam mode.

These scenarios **do not** verify actual phase classification, real AI grading or pedagogical accuracy. An imported suggestion is a different type of evidence from the final Task action.

**Development.** User creates a Free Development Plan and imports an owner-provided task through the existing HTTP endpoint (no seeded Task fixture). The user prepares and records a guided reflection. Verify the durable TaskEvidence and requested next adjustment, reopen the two relevant screens, compare development-action proposals before and after, and ensure reflection is not automatically promoted to a verified change or unapproved Task progress. Confirm repeated submit creates no extra Evidence or Task.

## Remaining product acceptance gaps

A correct saved proposal is not necessarily the right learning recommendation. A stored developer reflection is not necessarily a confirmed next Task. To complete P0-06, validate actual user evidence, rationales and task follow-through in a real browser and physical device, with the expected Free onboarding context. In particular, confirm whether the developer can promote their next adjustment to an executable Action without repeating complex data-entry.

## Verification and authority

Workflow `.github/workflows/development-release513-v64-pinned-p0.yml` temporarily copies these tests into a disposable exact-SHA checkout with SQLite and no connected providers. Tests are synthetic route integration, not human/browser/iPhone E2E. Production Aiven/Render, the release branch, PR #513 and production remain untouched. Release candidate selection remains `BLOCKED_NEEDS_P0_E2E`; CI success is not release approval.

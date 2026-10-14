# Canovia Development V6.7 — Explicit reflection-to-next-action handoff

Date: 2026-10-15. Development-main implementation, **not** part of the unchanged historical release #513. This is not production authorization.

## Motivation

The V6.6 tests found that Guided Execution saves a human-authored `next_adjustment` as TaskEvidence but does not apply it to the active Task's `next_action_note`. Users must otherwise repeat the same thought in Task editing. The original behavior intentionally avoids treating self-reported evidence as verified GitHub implementation.

V6.7 introduces an **explicit, human-confirmed handoff**, rather than an autonomous planner:

1. Complete a Guided Execution reflection with an optional `next_adjustment`.
2. View the last completed reflection and its proposed next step on the existing Guided Execution screen.
3. If the Task is still active and the suggestion has at most 1000 characters, select **この内容を次のActionに反映する**.
4. The app copies the latest author-owned reflection's exact text into **only** `Task.next_action_note`. It never modifies progress, status, GitHub evidence, task list, or the reflection itself. The screen marks the text as adopted after redirect.

## Security, concurrency and data boundaries

- Existing Plan/Task authorization applies before any reflection access or update. The reflection must belong to the same Plan and Task, and the last completed actor-specific reflection must match the submitted ID.
- A completed reflection must be bound to an existing `guided_execution_reflected` TaskEvidence row for that same Task. Cancellation, stale and foreign reflections cannot be adopted.
- The signed-in actor explicitly POSTs with CSRF protection and a hidden SHA-256 of the **currently displayed** Task action. The server locks the Task inside a DB transaction, recomputes the hash and rejects stale changes with HTTP 409. It never trusts an arbitrary submitted proposed action string.
- Exact repeated submission of an already-applied suggestion is idempotent. If the action has changed to something else, the old form cannot overwrite it.
- Completed/cancelled/100%-progress Tasks and empty or too-long suggestions cannot be adopted. Suggestions longer than 1000 characters are preserved in evidence but require deliberate Task editing.
- Ties on reflection timestamps are broken with descending ID, preventing ambiguous "latest" selection. Existing view and reflection URLs remain intact.
- **No database migration, new permission, billing or external provider integration.** An owner/editor authorized for this Task may only adopt their own recorded reflection, consistent with existing actor-specific Guided Execution access.

## Study rationale visibility

The Study Practice result panel now displays the `StudyExamConvergencePolicyService`'s existing learning-phase **reason** alongside the proposed next action for diagnostic/weakness/general-practice/exam routes, rather than inventing a new classification. When a synthetic imported proposal differs from the Task's selected next action, the screen explicitly distinguishes the two. This does **not** change phase thresholds, scoring, completion signals, user-owned historical attempts, or recommendations.

## Verification

`tests/Feature/GuidedExecutionNextActionV67Test.php` exercises:
- explicit adoption and unchanged progress/status/evidence count,
- exact duplicate POST,
- stale browser form after Task edit,
- older completed reflection after a newer one,
- foreign Plan actor access prevention,
- completed Task rejection.

The Development Prelaunch CI runs the test; Workspace Resume, Migration Recovery and Platform checks remain mandatory before main integration.

## Release boundary / remaining acceptance

This PR only targets development `main`. The pinned release #513 is still Draft/NO-GO and its commit remains untouched; its existing V6.4-V6.6 disposable route tests do not automatically cover V6.7 source. A new release candidate would require review of the actual full changed diff, pending migrations, Aiven recovery and identity, Render deployment identity and real browser/iPhone tests with separately explicit approval. Real-user evaluation is still needed to decide whether the next-action handoff and study-phase explanation deliver a clearer first-use experience.

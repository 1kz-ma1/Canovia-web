# Canovia V6.8 — 次Actionを確定したあと、次の実行へ迷わず戻る

2026-10-15 JST. Development-main acceptance, **not** historical release PR #513, production promotion, or a real browser/iPhone test. V6.7 introduced explicit confirmation of a completed Guided Execution reflection into `Task.next_action_note`. V6.8 closes its immediate UI gap: confirmation alone returned to the same page without a clearly identified path to start the new action.

## Product change

On an already adopted completed reflection, and only while no newer Guided Execution is active:

- Display an explicit **このActionで次の作業を始める** link.
- Link to the *existing*, accessible on-page preparation form via `#guided-execution-prepare`. The form's `intent` continues to default from the now-confirmed `Task.next_action_note`.
- Include explanatory copy: the user must prepare/execute and reflect again before a *new* Evidence is recorded; clicking the link alone never changes progress.
- Preserve the alternative active-execution layout: if the user is already preparing an action, don't show a non-existent anchor.
- No new controller, DB schema, mutation, client JS state or external provider. Existing Plan/Task authorization and V6.7 stale/reflection guards remain unchanged.

Study Practice retains its V6.7 explanation of learning-phase reason. V6.8 adds a route-level acceptance check confirming the **actual HTML result panel** displays that explanation with a synthetic assessed Attempt. This checks rendering, not the educational validity of the policy or AI grading.

## Disposable acceptance tests (current development main)

`tests/Feature/DevelopmentFirstActionJourneyV68Test.php`:

1. **Development full route journey**: synthetic Free account and Plan → Guided Execution prepare → reflect with next adjustment → reopen HTTP view and find opt-in confirmation → explicit POST → durable Task action while progress/status/Evidence count remain unchanged → reopen and see adopted state plus accessible next-action CTA and prefilled new intent → new prepare POST → one new GuidedExecution with no new Evidence or Task.
2. **Overlong reflection**: a 1001-character reflection is retained as Evidence, but the UI doesn't offer the adoption/start CTA and a forged POST is rejected with 422, leaving Task untouched.
3. **Study explanation**: synthetic Study attempt submitted through existing answer/assessment routes → real Study Practice HTTP GET displays `data-study-next-action-reason`, explanation, and the original saved proposal; showing a rationale does **not** silently apply the assessment.

The Development Prelaunch CI runs the focused V6.8 test along with V6.7 and its existing larger suite. Workspace Resume, Migration Recovery and Platform Offline Preflight must also pass at the PR head before integration.

## Still outside the evidence

These are Laravel HTTP/HTML tests using synthetic accounts and question payloads, **not** actual web-browser automation, a user observation study, TestFlight or physical iPhone WKWebView acceptance. They cannot assert that people notice the CTA without help, that study explanations improve comprehension, or that AI recommendations are correct. Those require a controlled product test with genuine UI interaction after a suitable candidate is selected.

The pinned PR #513 commit `bb8248f25545ae0d600fbb68f66c41c360004e2c` is unchanged and still draft/NO-GO; V6.8 lives only on development `main` after CI/review. No `production` branch, Aiven/Render, billing or actual customer data changes are authorized. The first-release decision remains `BLOCKED_NEEDS_P0_E2E` until independent P0 and release safety gates are met.

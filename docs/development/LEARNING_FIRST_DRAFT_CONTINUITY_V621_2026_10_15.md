# Learning V6.21 — 初回の途中回答保存の可視化・実ブラウザ検証

> 2026-10-15 JST | Learning / Experience boundary | development `main` only.
> Original owner observation: Mac Safari imported external AI questions successfully and reloaded, but 1-question answer saving was unclear. [IA-2j UX baseline](PRELAUNCH_IA2J_STUDY_WORK_FOCUS_2026_10_15.md).
> **No Render/iOS/public deployment authorization.**

## What is actually persisted

The legacy `StudyPracticeSession` **collects answers within 1–20-question set** and stores partial answers under `draft_answers` (`status=in_progress`), with `draft_saved_at`; it does **not** create a scored `StudyPracticeAttempt` for each input. Actual assessment handoff is triggered by explicit `回答をまとめて評価へ進む`. A graded Attempt is saved only after independently supplied and validated `study_assessment` is explicitly imported. That assessment does **not** automatically increase Task progress; a separate owner-confirmed apply is required.

This is distinct from the Question Bank's newer `LearningRun` with per-answer grade/save, already browser-tested in V6.10–V6.12. **Do not conflate the two or claim a first-answer scored history exists in the legacy path.**

## Runtime adjustment — small UX and fail-closed status

- Existing `resources/js/app.js` draft handler remains the only legacy JSON autosave. It retains local snapshot immediately and posts the same payload to the actor-scoped `StudyPracticeController::saveDraft` after 450ms.
- The page now surfaces an accessible `role=status`, `aria-live=polite` line: default explains draft vs graded record; then **saving**, **server-confirmed draft saved**, or **server save failed**. Confirm success only for unredirected HTTP **204**. A login-redirected HTML 200, 403, 419, 409 and network failures **must not** show server saved. A late response from an older edit cannot overwrite status for a newer edit.
- A localStorage copy is a **best-effort device-local fallback**, not proof of server persistence and not a graded history. We do not change storage key, server auth, response payload, question/score logic, Task progress, or expand data sharing. This UI does not promise successful persistence across other devices when the server refuses it.
- On explicit final submit, existing form and server validation/authorization are unchanged. `StudyPracticeSession` save is still an optional effect of a valid actor/task match, never on a GET.

## Real browser acceptance and fixture boundary

Add `.github/workflows/learning-v621-draft-browser.yml` using headless Chromium at **390×844** with `APP_ENV=testing`, `CI=true`, `NATIVE_AI_DRIVER=disabled`, a fresh **only** disposable `$RUNNER_TEMP/learning-v621-browser.sqlite`, Laravel bound to `127.0.0.1:8799` and no remote provider, real user or secret.

The browser **creates the guest Study Plan and registers the synthetic account through the real UI**. A strictly pinned disposable SQLite helper `learning_v621_synthetic_task.php --seed-task` then creates **one synthetic Task** for this isolated validation, because the new Study Workspace may direct a fresh Plan through missing-context confirmation instead of presenting the assumed Task creation form. **Initial Task creation from first-use UI is NOT proven by this test** and remains a separate acceptance item. From that point, all imported synthetic external-style question JSON, answer edits, autosaves, reload, submission and assessment occur via actual browser UI. There is no Plan/Answer/Attempt database seed or fabricated answer API.

Its read-only PHP checker `learning_v621_draft_readonly.php` is pinned to local CI env, exact temporary SQLite path and test account. It returns **only** synthetic status flags/counters, not Plan IDs/answer text/exam material. It proves:
1. Generated external-style 2-question JSON import creates ready StudyPracticeSession but no graded Attempt.
2. First radio answer causes real POST `/draft` 204 and visible **server saved**; DB has Q1 draft and **zero Attempts**.
3. Clear **origin localStorage** before hard reload; radio answer remains checked, proving server/PHP session restoration rather than relying solely on local cache.
4. Force a browser-only synthetic 419 for Q2 draft write; visible **failed** state, no server Q2 draft and no Attempt. Restore network path, edit Q2, see 204/success.
5. Submit answers to evaluation handoff. DB remains at **zero** Attempts before grading.
6. Import a **synthetic** assessment via the real UI, verify one scored Attempt, study session `assessed`, Task progress unchanged.

This does not attest to actual provider-generated assessment quality, real AP question quality, human task comprehension, iPhone Safari/WKWebView, authenticated real user cross-device verification, production CSRF expiry recovery, or public release.

## Acceptance and blockers

On the **exact PR HEAD**, require V6.21 Chromium run success and all other triggered required workflows; inspect full diff and active-main mergeability before squash merging to **development main** only. Existing release #488, Aiven audit, G3 external-cloud authority, automatic Watch/notifications, entitlements and public rollout remain untouched. Report whether each check actually ran, was skipped, failed or passed. This change has no migration or new route.

**Follow-up after CI:** owner replays the same 1-question local test on Mac Safari using an explicitly new pinned review SHA; old pinned worktree/SQLite must not be modified. Physical iPhone and Render acceptance remain separate.

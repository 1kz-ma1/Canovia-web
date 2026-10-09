# Legacy AI Practice focused-question layout (2026-10-10)

## Scope and owner observation

The user observed that the current `AI演習 | Canovia` page shows one question but its four options and large **optional** reasoning textarea exceed the laptop viewport, while history far below is distracting and the Next question action is on the left. This refers to the legacy `StudyPracticeSession` flow at `study_practice/show.blade.php`, not the newer `LearningRun` views in Draft PRs #456 and #457.

## Implemented UI contract

- Keep the current server-provided questions, answer field names, automatic draft persistence, validation, summary-grade submission, and legacy Task/attempt semantics unchanged.
- Each question is kept in a compact card. Single/multiple-choice answers use **one column on mobile**, **two columns on wide screens** (Tailwind `lg` and above), with readable multiline choice labels and touch-sized targets. Required text responses remain visible with a shorter default textarea that users can resize.
- The **optional** textarea becomes native `<details>`, collapsed when empty and expanded when an existing server draft, old posted input, field error, or newer local-draft restoration contains its content. Its element stays inside the original form so draft autosave and assessment receive the same values, even while collapsed.
- Keep the next action aligned to the **right** of a sticky form action bar. Prior question remains on the left when available; final review/grade submission is in the same bar on the final question. The sticky bar is an aid for tall screens/content, not a fixed overlay and not a guarantee that arbitrarily long questions fit the viewport. Do not clip content: allow normal vertical scrolling on short devices/long prompts or when the keyboard is visible.
- Existing graded-attempt history becomes a **collapsed read-only native details** at page end. Records, weakness feedback, and the next-session input remain unchanged; learners can expand this voluntarily.
- New layout uses HTML and CSS only except the tiny note-disclosure restoration in the existing progressive-enhancement pager. It introduces **no migrations**, backend state changes, new permissions, API calls, AI spending, or entitlement changes.

## Verification and release boundary

- CI: Vite build, Node pager tests for restored notes and one/multi question progression, legacy StudyPractice resumed session Feature tests for empty/saved optional reasoning and history disclosure; existing drafting, learning, route and Blade regressions.
- Manual iOS WKWebView, Safari/PWA, small-screen and desktop viewport checks remain explicitly **unverified** until the owner tests them. In particular verify 320px wide, keyboard presence, long answer options, safe-area inset, back/next navigation and lossless draft restoration.
- This feature branch is independent of Draft PR #456/#457 and must not merge into `main` while the production P0 Issue #418 remains OPEN. CI green does not establish production database/authorization recovery or device usability.

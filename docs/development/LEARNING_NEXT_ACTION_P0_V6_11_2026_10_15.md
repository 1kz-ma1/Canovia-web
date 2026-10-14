# Learning V6.11 — Verified answer Evidence → user-confirmed next Action

**2026-10-15 JST · bounded P0 implementation / acceptance contract.**

## Current architecture and design decision

V6.10 proved Guest→Free Study Plan, Study-owned first Task, real Question Bank answer/grade/history, next-question progression and authenticated resume in disposable Chromium. **Adaptive Learning answer events are not `TaskEvidence`**: `StudyPlanIntelligenceService` consumes `study_practice_assessed` and `study_recall_reviewed` TaskEvidence, while single-question `LearningAnswerEvent` currently supports actor-scoped learning history and a replaceable Question Bank candidate queue. Do **not** synthesize mastery or Task progress from one grade.

V6.11 implements the smallest product-visible bridge: **a read-only Study review proposal** and **an explicit, actor-authorized “次のActionに反映する” form**. Only the current Task's `next_action_note` is updated after owner/editor confirmation; answers, TaskEvidence, Task progress, other Tasks, and locked question snapshots stay untouched. This is **not** autonomously deciding the best future Plan Task.

## Source trust and eligibility

- Scope: same authenticated user or guest actor, Plan, Task; Understanding/Practice answer events only. No Exam simulation or foreign account/question history.
- Existing `AdaptiveLearningHistoryService` eligibility retained: at least **two wrong answers on two distinct source Questions**, no two most recent consecutive correct answers for that concept; excluded mis-taps never establish a repeat.
- Proposed action: `「{concept}」を別の問題で再確認し、誤答の理由を見直す`. Concept comes from the existing validated Question snapshot metadata (trimmed <=50 chars), shown escaped in Blade. One top review topic for this P0 slice.
- Rendered proposal is advisory and never auto-saved. Completed or 100%-progress Tasks receive no proposal.
- Confirmation uses a dedicated 8/min actor/IP rate limiter, isolated from the numeric Study answer/start/next throttles. This prevents a first-user burst from blocking the explicit approval with HTTP 429 while retaining an independent bound.
- Form includes CSRF, SHA-256 source-window signature (event IDs, source Question IDs, recorded correctness, adjustment IDs, actor and scope) and the hash of the current Task next Action. No tokens, private answers or candidate text are accepted from the POST body.
- POST confirms editing permission through `PlanOwnershipService`, Study classification, rechecks current actor-scoped history, locks Task row and rejects stale signatures or changed Task next Action with HTTP 409. Exact re-submission after already applied is idempotent. Shared Task activity logging uses existing non-sensitive metadata only.
- The Task-row lock protects concurrent Task-Action edits and ordinary sequential Evidence changes. It is not a global lock across the independent Learning-answer writer, so **strict serializability against a truly concurrent last-millisecond answer is not yet proven**. No claims of automatic self-updating Task Action.

## Acceptance

1. PHP feature regressions: 1 miss → no proposal; 2 different missed Questions → proposal; click confirm → Task next Action changes, zero mutation to Task progress/Evidence; duplicate confirm no duplicate write.
2. New answer, evaluation exclusion or concurrent Task Action edit between preview and confirmation → 409 or no candidate. Same Question twice, another actor, Exam answer or two subsequent correct answers cannot create a false weakness.
3. Browser: reuse disposable V6.10 synthetic pack only, with Guest→Free user, first Study Task, a wrong answer, correct answer, login resume, then another wrong source Question. Open actual history, click visible confirmation form, see “反映済み”, and read-only DB verify 3 graded answers, 1 correct, persisted next Action, unchanged zero Task progress/Evidence.
4. CI: `.github/workflows/learning-v611-evidence-action.yml` runs isolated SQLite PHP regressions and real pinned Playwright Chromium, plus existing checks at exact PR head. No paid provider or external AI calls.

## Boundaries / next gate

Passing browser & feature CI proves **explicit user-approved Task.next_action_note from repeated graded answers**, not its educational effectiveness, Study's broad Plan-level StateSnapshot decision, appropriate topic allocation, official AP content correctness, real-device iOS/PWA behavior, or automatic response to every answer. Those require separate evidence. There is no new migration, provider access or production action.

Release #513 remains fixed-SHA DRAFT / **NO-GO**. `main` integration never promotes `production`, Render or Aiven. Device acceptance, deployment and backups remain separate release gates.

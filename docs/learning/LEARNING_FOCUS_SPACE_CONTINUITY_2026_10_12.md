# Learning Focus Space & Continue Loop — implementation spec (2026-10-12)

**Status:** Current Learning lane owner-requested implementation. **Base:** `main` 36402448f38a. Supersedes the proposed legacy UI layout of Draft PR #458; no unapproved future architecture promotion.

## Observed problem / truth
1. The screenshots show the **legacy** `StudyPracticeSession` flow, not the per-answer `LearningRun` Draft PR #456/#457. Old Reliability/Strategy cards and four-step preparation metadata appear above the first question.
2. In `StudyPracticeController::applyAssessment`, `continue_after_apply` currently sometimes redirects to Plan detail or Plan review instead of the learner's next activity. The Plan details retain an old roadmap surface.
3. Existing progress data is **Task progression**, not the learning question counter; ranking/quality score is a *heuristic*, not actual learner mastery. Keep them separate.

## Product contract / surfaces
| State | Foreground | Secondary / excluded |
| --- | --- | --- |
| Setup without questions | One clear `学習を始める` action matching available Question Bank / Native AI / external handoff and succinct focus hint | Long Reliability/Strategy and complete Plan dashboard can stay only in optional details or Workspace |
| Answering / restored draft | Question number + total, actual question and answer controls, compact **record-based focus reason** from existing `practiceStrategy` and selected provider; next/previous or grade action aligned correctly | No Reliability scorecard, Task actions, full history, roadmap, top-level setup panels, or unqualified `adaptive` claims |
| Evaluation handoff (when grading isn't direct) | One explicit evaluation handoff action, preserve existing JSON repair path | No unrelated setup explanations |
| Result | Actual score and short *recorded* next-learning hint. **Primary: Continue learning** on the same Task without requiring an explicit Plan/Task progress mutation. | Detailed reasoning, assessment and explicit Plan update stay behind `details`. Never change Task progress merely to continue. |
| No further usable Task | Return to Study Workspace, not the old Plan dashboard by default | Plan detail remains explicitly reachable when chosen |

## Critical server contract
- Reuse existing authorized `StudyPracticeController::reset` with `continue=1` as the **nonmutating** "continue" action after grading. Its POST resets only the current StudyPractice session, not persisted StudyPracticeAttempt, Lesson history or Task progress; it redirects to focused setup. Do not call `applyAssessment` implicitly.
- Continue-after-**explicit**-apply must never *automatically* send learner to legacy Plan dashboard/review; redirect to Study Workspace (or relevant next Task study practice) as appropriate; keep Plan review as an explicit secondary option. Preserve idempotency, owner/actor check, irreversible business logic and `advance_task` consent boundary.
- In answering state, keep the same stored response field names, draft saving, grading form/POST, and session recovery. Never falsely claim immediate per-question grading for a legacy set.
- Human-entered optional reasoning may be collapsed, but must remain serialized; saved drafts must re-open, including restored localStorage.
- Only show the compact hint if actually present in authoritative `practiceStrategy` or selected session context. If absent, say that the current Question Bank set is being used; don't claim an inferred weakness.
- No mutation or publication of unreviewed AP exam packs.

## Old roadmap boundary
- The legacy roadmap component in `plans/show.blade.php` should no longer be a **default discovery/continuation surface for Study plans**. In Study plans, provide a concise link to the Study Workspace instead of the large roadmap visualization; leave other Plan categories and their roadmap UI unchanged. Plan details remain accessible explicitly for management.

## Acceptance / rollout
- Responsive mobile/desktop layout; long questions naturally scroll without clipping, 44px tap targets, iPhone safe area.
- Answering has exactly one obvious primary next action, history/strategy/reliability are not in the primary DOM content above the question. Result has a primary nonmutating Continue action and secondary explicit Apply flow.
- Explicit Apply never unconditionally routes to old Plan details, and existing assessment attempts and progress cannot be silently modified.
- Tests: legacy session resume, one-question JS, result lifecycle/assessment, Task progress isolation, Study vs non-Study Plan roadmap, Blade and layout CI; inspect exact SHA before reporting green.
- **Release hold:** issue #418 remains open. No merge to main and no production Render deploy even if CI passes. Device E2E on Safari/PWA/WKWebView and desktop remains separate and unverified.

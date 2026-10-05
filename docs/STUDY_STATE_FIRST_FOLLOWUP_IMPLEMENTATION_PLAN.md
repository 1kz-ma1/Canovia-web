# Study State-First Follow-up Implementation Plan

> Temporary implementation roadmap.
>
> This file exists only to coordinate the remaining Study State-First work after V56.13.
>
> **Delete this file after all four phases below are fully implemented, validated, merged, and represented in permanent versioned specs / docs/CANOVIA_PRODUCT_SPEC.md.**
>
> Do not keep this document as a permanent product authority once the roadmap is complete.

## Why this exists

V56.13 established:

~~~text
State First
+
Learning Type Router
+
Surface Registry
~~~

The remaining work is to make that state drive the learner's actual study method and next action, rather than only changing which cards are visible.

Target loop:

~~~text
Current Learning State
→ recommended study method
→ exact Strategy / Task action
→ execution
→ Study Evidence
→ updated State
→ next recommendation
~~~

## Phase 1 — V56.14 Study Recommendation Surface

Status: **implemented and merged in V56.14**

Goal:

Expose the same deterministic Study Practice strategy that Canovia will actually use when the learner starts the next practice set.

The Workspace must not invent a separate recommendation model.

Canonical authority:

~~~text
StudyPracticeStrategyService
→ StudyWorkspaceRecommendationService
→ Study Recommendation Surface
~~~

Show, when applicable:

- current Study Practice phase
- strategy label
- target question count
- primary / secondary / diagnostic allocation
- bounded weakness recheck topics
- retention-due topics
- cooldown / mastered context
- suppressed / preferred parent domains when useful
- why this recommendation is being made
- one CTA into the existing Study Practice flow

If a resumable StudyPracticeSession already exists:

~~~text
stored Session strategy
→ recommendation says "continue"
→ do not preview a new Strategy
~~~

The recommendation shown in Workspace and the Strategy used by Study Practice must share the same policy authority.

Do not call provider question generation from Workspace GET.

Implemented in V56.14:

- StudyWorkspaceRecommendationService
- actor-scoped Attempt history
- resumable Session strategy authority
- Study Recommendation Surface
- exact Strategy bucket/count display
- practice-oriented Surface Policy replacement of generic Current Action
- validation run 37316603268

## Phase 2 — Study Method Recommendation

Status: **implemented in V56.15 branch; pending merge**

Goal:

Let Canovia decide not only **what** to study, but **how** to study.

Initial deterministic method candidates:

- broad Practice
- focused remediation
- Recall / retention check
- explanation / material review
- Scope / material organization
- exam-mode practice
- practical Evidence for skill learning

Example rules:

~~~text
repeated knowledge_gap
→ explanation / material review may outrank more questions

retention_due
→ Recall

cooldown/mastered
→ stop deliberate drilling

low exploration coverage
→ broad Practice

exam window
→ Exam Mode

school test + missing range
→ Scope Capture
~~~

The recommendation must use existing Evidence and deterministic policy before adding AI reasoning.

V56.15 reuses the existing V41.10 `StudyActivityPolicyService` as the Task-semantic base and adds State First overrides rather than creating a duplicate Activity classifier.

Implemented in V56.15:

- State-aware StudyMethodRecommendationService
- Question Practice / Recall / Resource Study / Scope Organization / Practical Evidence
- repeated knowledge/concept gap → Resource Study
- retention due → Recall
- Exam Mode → Question Practice
- unfinished Practice Session → Resume continuity
- school test missing Scope → Scope Organization
- memorization → Recall
- skill learning → Practical Evidence
- Workspace and dedicated Study Activity page share the same method authority
- ranked alternative methods
- no mutation / AI / provider traffic on read paths
- code validation run 37319194087

## Phase 3 — Score / Baseline Evidence Capture

Status: pending

Goal:

Add a reusable way to store learning baselines whose scale is not equivalent to Practice accuracy.

Examples:

- TOEIC 480 → target 600
- IELTS 5.5 → target 6.5
- school-test previous score 62 → target 80
- mock-test deviation / domain scores

Requirements:

- do not infer absolute exam score from Practice percentage
- preserve source / observed date
- allow bounded component scores where the exam supports them
- feed Score Exam State First surfaces
- keep Practice accuracy and external score scale separate

## Phase 4 — Learning Type Confirmation / Override

Status: pending

Goal:

Handle ambiguous Plan titles without forcing the router to guess forever.

When deterministic router confidence is low, show a compact confirmation surface such as:

~~~text
この学習はどれに近い？

- スコアを上げる
- 学校のテスト
- 資格に合格
- スキル習得
- 暗記・定着
- その他
~~~

Rules:

- do not ask on every visit
- only ask when classification ambiguity materially changes the Study surfaces
- explicit user choice outranks heuristic classification
- keep the override editable
- do not collapse Workspace Mode and Learning Type into the same concept

## Completion / deletion rule

This roadmap is complete only when:

1. Phase 1 is merged and the Study Workspace shows the real next Strategy.
2. Phase 2 is merged and Study Method selection is state-driven.
3. Phase 3 is merged and score/baseline Evidence is supported.
4. Phase 4 is merged and ambiguous Learning Type can be confirmed/overridden.
5. Relevant permanent versioned specs and docs/CANOVIA_PRODUCT_SPEC.md describe the final behavior.
6. Regression tests cover the integrated State → Method → Action loop.

At that point:

~~~text
delete docs/STUDY_STATE_FIRST_FOLLOWUP_IMPLEMENTATION_PLAN.md
~~~

The deletion is part of the final phase Definition of Done.

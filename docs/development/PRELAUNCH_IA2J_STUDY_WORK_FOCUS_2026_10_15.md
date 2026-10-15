# Pre-Launch IA-2j — Study「今やること」の行動優先と根拠の表示先分離

> 2026-10-15 / Experience lane / development `main` integration only.
> Not a Render/iOS deployment or an approval to release the evolving `main`.

## Observed first-use friction

In owner-operated IA-2i **Mac-local** Safari testing of the frozen `069e9b5790345feb9bb9edd437ac1c5a39a73efd`, the learner successfully created a Plan, imported external-AI questions and reloaded without a visible problem. The owner reported the work surface was crowded by provenance information unrelated to the immediate act of studying. One-question answer persistence is **not independently verified** by this UX change: classic StudyPractice stores server-side `draft_answers` during entry and creates a scored Attempt at submission/assessment. A missing 1-question history row is not by itself evidence of lost draft data.

Prior Study Work displayed `Execution Setup → detailed State Change → published Bank topic sample → official syllabus review status → Plan-wide priority → repeated-miss review → composed next/current Study Action`. This pushed resume and next actions below several long explanatory cards and duplicated detailed Bank/syllabus cards in another view.

## Implemented routing

Existing Study Plan query route `/workspace/study?plan_id=…&surface=…` stays authoritative. No new tab, route, provider or recommendation algorithm.

| View | Primary role | Existing content after IA-2j |
| --- | --- | --- |
| **今やること** (`work`, default) | What to resume/do now | Study composite surfaces (resume/current action/recommended method) **first**; Execution Setup; existing actionable review queue and Plan-wide learning priority; **two short links** to Analysis and History |
| **学習準備** (`preparation`) | Sources/materials/exam setup | Existing scope/material/official-exam reference, **detailed official syllabus / reviewed mapping gate** moved from Work/Analysis |
| **学習分析** (`analysis`) | Why this decision and source coverage | Existing detailed AP published Question Bank topic sample, observed answer evidence, gaps, weakness, readiness (existing registry) |
| **履歴** (`history`) | What State/Decision/Action has changed | Existing Intelligence history plus **detailed State Change with underlying Evidence/Readiness/GAP** moved from Work |

The entire original source/reference caveats remain in their original Blade partials: IPA source version/dates, partial-index / unreviewed status, no invented official exam coverage percentage, Bank tags are **not** exam topic weights, source/question review is not guaranteed, and source observation never adjusts Task progress. Views still use existing `$canEdit` and Plan-authorization controller checks.

The Work section still permits **intentional** topic review and Next Action confirmation: `learning-review-queue`, `learning-plan-priority` retain their links and status, including delayed/non-actionable candidates. Moving these to analysis would bury actionable learner choices and require a separate product decision. The navigation links are GET to selected Plan context and do **not** mutate Task or Evidence.

## Verification requirements

- **Source display**: Work does not render `data-study-learning-bank-coverage` or `data-study-official-syllabus-bridge`; only Analysis renders the Bank sample, and only Preparation renders detailed syllabus. Special AP Subject A scope and no false 100%/0% official coverage remain intact.
- **State change**: Work does not render `data-intelligence-state-change`; History does, using the same trusted `IntelligenceStateChangeFeedbackService` projection and preserving existing actor/Plan binding. None are written by GET.
- **Action/continuity**: Default Work shows the same State-first composed cards and action routes, with the composed action stream before optional follow-up cards. Existing Learn/Review task-owned routes remain unchanged.
- **UI regression**: update V6.19/V6.20 real Chromium scripts to check the Work→Analysis navigation, Bank sample, Prep source/partial review, and Action loop unchanged. Existing V54.6 State Change Feature test targets History. No new migrations, services, AI calls or storage writes.
- **Privacy**: no new provenance data is sent to third parties, no wider access permission, and all owner-only gates retained.
- **Release boundary**: No UI improvement is present in the currently frozen Mac lab source `069e9b57...` merely because its PR merges into development main. To retest, separately build a new isolated review snapshot; never overwrite/mutate the already-tested SQLite or advance that frozen SHA in place. Render Auto Deploy remains OFF; Issue #488 release NO-GO remains independent.

Remaining manual review: Mac Safari real perception, one-answer autosave/recovery observation, physical iPhone Safari/WKWebView and actual deployed SHA after separately approved release.

# Learning V6.19 — AP Subject A Bank-tag coverage vs recorded exposure

**2026-10-15 JST · Learning lane · development-only P0.**

## Precise scope: sample gaps, not national syllabus coverage

V6.18 ranks Tasks with *existing recorded answers*. It cannot surface potential gaps for topics where no question has ever been answered. V6.19 complements, rather than replaces, V6.18 and Study Intelligence with a read-only, explicitly labeled **published explicitly AP-routed Question Bank tag sample** in Work and Analysis surfaces. It is not a syllabus registry, source of official 2026 exam distribution, AP readiness score, or completion evidence.

- Show only for an owned/editable AP-like Study Plan, excluding Plans explicitly marked Subject B only. A Plan with no explicit Subject A signal is labeled **科目A未確定**; it is not silently converted to a Subject A Plan. Other qualifications, foreign actor/noneditor, no Plan or closed-only contexts do not receive authoritative AP coverage claims.
- The AP Subject A verified exam profile currently documents **80 questions** (with source reference/verification metadata) but does *not* authorize treating the published local Question Banks' domain or concept tag frequencies as IPA's official future-exam proportions.
- Sample only database `QuestionPack` rows with `status=published`, `exam_code=AP`, `subject=科目A` **and explicit AP/応用情報 `metadata.match_terms` (or curated official-past-exam content_kind)**, ID order, at most **16** packs and **1,200** supported active source Question IDs. Apply existing `QuestionPackPublicationReadinessService::inspect` before including questions. **Published-but-human-review-pending** candidates marked with existing blocking metadata are excluded; blocked pack count visible. This preflight checks structure/metadata/attribution fields, **not independent expert attestation**, so every eligible Pack is marked human review **not verified by this surface**, even when it is published. No status mutations or approvals.
- Use exact trimmed `learning_metadata.concepts` and `weakness_targets` (maximum 50 characters and at most four labels per Question), not fuzzy Task title matching. Multiple tags per Question can produce overlapping per-topic counts. The percentage is explicitly **share among the sampled eligible Bank IDs carrying that tag**, NOT future exam question frequency and NOT percentage of official scope completed. Return up to **eight** topic rows, unobserved IDs first; partial sampling warns on more packs/topics or 1,200 ID cap.
- For the selected supported Question IDs, query all stored actor/Plan scoped `LearningRunItem.question_id` with submitted `AnswerEvent`; includes all Tasks in this Plan and Exam modes. Excluded/adjusted grades still prove a Question ID was presented and answered, without counting as ability. Foreign user and other Plan excluded; no record here does not mean user never studied elsewhere. **No proficiency, transfer, correctness or required subject-area weights are inferred.**
- Link only to the existing Study Scope **GET** for manual review, not to an automatically topic-locked new Learning Run. No automatic Plan, Task, time, Evidence, next Action, AI, scheduled notification, billing, database migration or rights override.

## Acceptance

1. PHPUnit: verified AP Subject A profile 80-question *format reference* displayed separately; two published supported tagged packs count 5 Questions, show both uncovered topics, external qualification/Subject B excluded. An incorrectly published-but-human-review-pending Pack is blocked and never counted as available; preflight alone never claims human pedagogical/rights approval.
2. Real actor/Plan isolation with another owner and another Plan, multi-Task and Exam answer-event source-ID exposures, no synthetic Task progress. Confirm generic AP title displays **scope-unconfirmed**; explicit Subject B-only and other qualifications hidden. No Bank rows -> informative empty view, no fake gap scores.
3. Real disposable Chromium Guest→Free Study Plan/Task, actual Question Bank seed isolated to loopback SQLite, no answer events -> no-record tag sample with 80-question format context; real browser submitted answers turn sample into partial record; V6.18 immediate→waiting→synthetic 26h due and two further consented Runs still work. The 26h time shift is limited to previously user-submitted browser answer records, not real-world retention evidence.
4. Regression V6.11–V6.18, all exact-head GitHub CI green, reviewed full patch, no unresolved review thread and clean current development main before squash.

## Remaining product gates

This is intentionally **not** an official IPA syllabus/domain-completeness feature and cannot establish 80 approved AP test questions. Next separate stages would require an independently reviewed syllabus/topic index (version+source+rights), verified item-to-topic mapping and approved content with explicit human review evidence. Do not promote AP candidate bundles or make exam-readiness promises from this sample.

Production, Render, Aiven and release PR #513 remain untouched / Draft NO-GO.

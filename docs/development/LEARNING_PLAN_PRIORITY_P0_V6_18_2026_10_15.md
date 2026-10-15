# Learning V6.18 — Plan-wide evidence and actionability ranking

**2026-10-15 JST · Learning lane · development main only**

## Verified inputs and scope

V6.11–V6.16 already expose actor/Plan/Task-bound bounded answer history, repeated misses in two independent Question IDs, approved manual review, and two source-unseen supported Questions. V6.17 additionally produces an actor-bound **24h read-only delayed review cue** after two latest correct; it never schedules a push/notification or changes Task progress.

V6.18 is a **secondary advisory in the Study Workspace Work surface**, not a replacement for Study Intelligence, Plan priorities or the existing V6.12 review queue.

- Only the owner/editor with eligible actor identity can view. Start from most recent **80** actor-bound Understanding/Practice Runs containing submitted answers; inspect at most **12** unique Tasks belonging to the currently selected Plan. Non-editor or foreign actor must get zero ranked items; closed/100% Tasks and Exam-only history are excluded.
- Per Task re-use V6.11 evidence preview or V6.17 delayed cue from the **60 latest** actor/Task scoped immutable answer events. Corrected/adjusted answers excluded by existing derivation. Review candidate may have prior wrong source Questions; not a diagnosis of weakness/mastery.
- Inspect published, active, supported source Question Packs via existing V6.13/V6.16 services. V6.16 **distinct-source** availability uses all prior actor/Plan/Task exposed Question IDs. List at most eight eligible Packs. No fake Bank fallback, no provider AI calls.
- Expose existing Task priority, independently missed source Question IDs, date context, available published Pack count, evidence explanation and whether the candidate can be started **now**.

## Explainable ranking and guardrails

Order evidence/actionability bands: (0) active repeated errors **with 2 unseen source Questions**, (1) active repeated errors using eligible existing source Questions when none unseen, (2) delayed **due** after >=24h **with 2 unseen source Questions**, (3) delayed **waiting** (read-only), (4) unavailable (0 eligible Packs). In a band: lower numeric existing Task priority, more independently missed Questions, original Task sort_order and Task ID. Only display top **three**; disclose up-to-12 Task scan and any hidden rows. Never auto-adopt next Action, auto-create Plan or Learning Run, auto-update Task progress/Evidence, auto-notify or schedule anything.

**Plan deadline**: show calendar days remaining or overdue via existing `Plan.deadline` (or explicit not-registered). It is a **shared Plan-level constraint**, not a Task-specific deadline; therefore it does not artificially change within-Plan ranking. It cannot override Question Bank validity, create content, or turn an unavailable Task into an actionable option. Numeric Task priority values are already persisted, not generated scores.

For immediate errors with eligible packs, button links to existing V6.13 topic review GET (including V6.16's distinct-source option). For due, link to V6.17 Learning index where user must explicitly choose published Bank and submit valid signed POST. For waiting/unavailable, link to read-only history, **not** a start form. The underlying controller **revalidates** actor, current source window and eligible two Questions when starting; these Work-surface URLs convey no auth privileges.

## Proof and non-goals

- Feature tests across three Tasks: immediate unseen, repeat-friendly only, elapsed due; deadline 3 days, task priority tie rules, no TaskEvidence/PlanTask mutations. Pending and unavailable cannot claim actionable; foreign user, completed Task, Exam evidence cannot leak. Null and overdue Plan.deadline are explanatory only.
- Real Chromium: Guest→Free→Study Plan/Task, actual graded incorrect evidence -> Workspace immediate; after two correct on source-unseen questions -> waiting; guarded disposable SQLite timestamp shift of **five genuine earlier browser grades** -> due; user explicitly begins third Run, submits two supported source-unseen correct answers, Task/Evidence unchanged. This is **not** a live 26h wait.
- Full V6.11–V6.17 PHP regression and existing project checks, exact PR-head CI, clean latest main and reviewed patch before development-only squash merge.

Not validated: retention, actual transfer of knowledge, whole user history beyond bounded windows, AP copyrighted/official question quality, physical iPhone WKWebView, real scheduling/alerts, cloud Aiven or Render. Production release PR #513 stays **Draft NO-GO**; no `production` change or deployment.

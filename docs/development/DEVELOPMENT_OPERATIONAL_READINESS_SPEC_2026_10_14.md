# Development Operational Readiness / Release Safety Coach — Specification

> Status: **LONG-TERM PROPOSAL; V1+V2 READ-ONLY GUIDANCE MERGED INTO MAIN** (2026-10-14).
> This document does not claim a shipped Development Mode feature, a completed release, or authorization to change production.
> Ownership: **Development** (experience and state-driven guidance) with **Platform** co-review (security, backup, database, deployment and operational policy).
> Related current contracts: [V53.8 Development Readiness](../V53.8_DEVELOPER_READINESS.md), [V57.3 Developer Home](../V57.3_DEVELOPER_HOME_V1.md), [V58.0 focused surfaces](../V58.0_DEVELOPER_WORKSPACE_SURFACES.md), [Development Workflow](../CANOVIA_DEVELOPMENT_WORKFLOW.md), [GitHub-native roadmap](GITHUB_NATIVE_ROADMAP_CONTRACT.md).

## 1. Problem and product promise

A developer can implement and merge code faster than they establish a safe way to release and recover it. If Canovia waits until the first production launch to ask about database backups, restore drills, deployment branches, secrets and migration risk, the resulting work is both risky and unnecessarily laborious.

**Goal:** before the next irreversible or user-impacting step, show the smallest justified preparation action, its evidence, and a reliable path to complete it. Make recurring safety work reproducible rather than forcing users to repeat an improvised terminal procedure.

This is **not** a universal setup wizard or a generic DevOps score. Early prototypes should remain lightweight; exposure and change risk determine which checks become relevant.

## 2. Current reality vs proposed addition

### CURRENT / ACTIVE — reuse, do not replace

- V53.8 owns seven Task-correlated Release Quality Gates: implementation, CI, review, merge, production deploy, verification, spec sync.
- PR #593 implements a narrow, Work-only prelaunch preparation suggestion (Plan/active Task cue or explicit request). This is a read-only, **unverified** prompt, not a production recovery test; see [implemented V1 slice](DEVELOPMENT_PRELAUNCH_PREPARATION_V1_2026_10_14.md).
- PR #595 implements an additional bounded, ranked, Work-only **GitHub source-labeled** six-check projection. Exact-SHA CI / production deployment may be *observed* through authorized Task evidence, while host identity, stored data, backups and isolated restore remain **unknown** without independent proof. See [V2 implemented slice](DEVELOPMENT_OPERATIONAL_EVIDENCE_V2_2026_10_14.md).
- V57.3 owns State-derived Next Action and Development Home; GitHub is an optional sensor, not mandatory onboarding.
- V58.0 organizes Development Plan Workspaces into Work / Repository / Team / Improvements / Preview surfaces.
- GitHub-native evidence distinguishes declared intent, observed PR/CI, deployment, and independent device confirmation. A green CI does not prove production health.
- Existing GitHub and deployment evidence can be missing or stale. Existing authorization, Task progress, ownership and provider-cost rules remain authoritative.

### PROPOSED / NOT IMPLEMENTED (independent operational attestations beyond V2)

Add an **Operational Readiness projection** that assesses the *release/recovery foundation* for a Development Plan, repository and target environment. It contextualizes the seven existing gates rather than adding new V53.8 weights or silently claiming "Release Ready."

    Development Plan + project lifecycle and explicit target
    + authorized repository / CI / deployment observations
    + separately confirmed operator evidence (backup / restore / policy)
    → foundation gaps with confidence, scope, freshness
    → one contextual Next Preparation / operator handoff
    → explicit evidence refresh after action

The existing Current Action engine remains the canonical day-to-day decision owner; this projection supplies scoped inputs, not a competing recommendation engine. Until implemented, the following is a proposal only.

## 3. Progressive adoption: ask only what matters now

Determine the **declared lifecycle** from existing Plan context where possible; when unknown, ask at most one relevant question rather than a long mandatory form:

- **Prototype / local only:** source control and basic tests are useful; do not force production hosting, DNS, private backups or enterprise compliance.
- **Private preview / staging:** independent environment, test identity, bounded secrets and safe migration rehearsal become relevant.
- **First public release:** protected promotion path, effective runtime/config verification, backup *and restore* proof when persistent data exists, observability, incident/rollback owner, and public client acceptance become relevant.
- **Live operation:** backup freshness and periodic restore drill, credential hygiene, migration impact, deploy-to-SHA attribution, user-facing smoke and incident playbook require ongoing evidence.

Risk-adjust the checks for the actual stack: databases, payment, authentication, private information, iOS/native clients and multiple environments should trigger domain-specific checks; a static landing page need not perform a MySQL restore drill.

The project must remain usable when optional connectors are absent. Missing provider access is **unknown**, not failure or inferred success.

## 4. Operational foundations to assess

| Dimension | Trigger | Evidence needed before saying "verified" |
| --- | --- | --- |
| Repository / change control | Repository linked or team collaboration | Exact repo and branch, reviewed promotion target, permission/approval boundary; no assumptions from a branch name |
| Automated checks | First shared code / release candidate | Exact commit/SHA, required CI status, failed/pending handling, test scope |
| Environment separation | Staging or production target | Independently identified environment/service, no production/test mix-up, actual branch/deploy source |
| Durable data / recovery | Persistent user data or risky DB change | Backup scope and recency **plus independent restore/read validation**; rollback method, retained copy and operator owner |
| Database migrations | Migration/DDL/schema drift | Exact source-to-live ledger, additive/destructive impact, tested rehearsal, locks/time estimate, recovery path |
| Identity / secrets / permissions | Login, GitHub App, payment, MCP or other private provider | Approved scopes and bounded grants, verified runtime flags, no raw secrets in Canovia, privilege expiry |
| Deployment / monitoring | Production releases | Exact deployed SHA, health/telemetry, smoke on relevant Web/PWA/iOS clients, incident response and explicit authority |
| Documentation / handoff | Behavior or operations changes | Current spec, operator runbook, assumptions and executable prerequisite checks match the reviewed implementation |

The **seven release gates** are still evaluated according to V53.8. A passed deploy gate cannot be reused as evidence of a successful restore; a successful restore cannot mark production deployment or live usability as passed.

## 5. Evidence contract and freshness

The future projection should carry scope explicitly: `plan_id`, authorized `repository_id`, environment ID, source SHA, observation time, risk trigger and verification provenance. No guessed source binding.

Possible evidence states:

- **verified:** direct, authorized machine or operator observation with identity and matching scope; record what was tested, not a broader claim.
- **operator_confirmed:** user attests an external action and supplies a bounded verification summary; distinguish from independent machine verification.
- **declared:** a spec/config claims a practice exists but no observed execution.
- **missing / unknown:** not evidenced or connector absent, never auto-pass.
- **stale:** new SHA, service, database or policy invalidates the prior applicable attestation.
- **not_applicable:** explain why the condition is out of scope; reassess if the product's exposure changes.

For example, "backup file exists" does **not** mean "restore tested"; local hash equality does **not** prove remote-cloud recovery; a restored count at time T does **not** prove all live rows at T+1. The app must label this difference in plain language.

Do not store or display database rows, private environment values, credentials, backup encryption keys or full operational logs. Keep only bounded allowlisted facts, reference IDs and safe result codes. Raw docs/CI/PR text is untrusted input, never executable agent instructions.

## 6. User experience: contextual help, not friction

**Before work:** Developer Mode Top or Plan preparation may suggest a small "公開・運用の準備" entry, but only when relevant to the lifecycle. It must not block Plan creation or everyday implementation.

**During work:** Repository/Work surfaces can show a compact risk callout when a consequential change is observed (e.g. new migration with no recovery evidence). One primary action should explain *why now, what proof is missing, and the least-cost next step*. Users can inspect details rather than seeing a permanent eight-category checklist.

**Before release:** display a scoped release preflight with `verified / missing / stale / needs human decision`, linked source SHA and environment. Critical unknowns produce **review required**, not "safe" and not silent deployment. Promote/Deploy remains explicitly authorized according to the actual connected provider's enforcement.

**After release:** verified deployment SHA, environment-specific Web/PWA/iOS smoke, recurring backup/recovery health and incident feedback should refresh recommendations. Historical successes do not automatically carry forward to a different release.

Example prompts:

- "本番公開予定が近いです。DBのバックアップは確認できていますが、復元テストの証拠がありません。隔離環境での復元手順を準備しますか？"
- "今回のPRにはDB変更があります。現行本番とのmigration差分と復元手順を確認してから昇格してください。"
- "CIは成功していますが、本番のデプロイ先と実行SHAはまだ未確認です。Readyとは扱いません。"

## 7. Reducing terminal trial-and-error

A future **operator handoff / preflight generator** should prefer one reviewed, repeatable, platform-specific runbook rather than many ad hoc shell commands.

Minimum safeguards:

1. Confirm runtime/OS, installed executable and supported flags **before** connecting to production. Distinguish shell commands from SQL and avoid shell-specific comment or quoting failures.
2. Confirm identity of environment, database, actor and allowable operations before any mutation. Default to read-only. Never infer production identity from a familiar database name alone.
3. Generate bounded, copyable commands with local credential prompts; never embed secrets, issue unmanaged credentials, or encourage posting logs containing tokens.
4. Use fail-closed error codes, explicit exit status, bounded masked diagnostics, cleanup traps and idempotent/retry-safe steps. Unexpected flags or incomplete prerequisites stop early.
5. Separate "backup created", "integrity checked", "restored and read", "stored off device" and "cloud retrieval tested" as independently attestable stages.
6. Require explicit owner consent for provisioning resources, changed grants, backup export, account creation, deploy/rollback or any production DDL.
7. When a prerequisite fails, explain one safe next action instead of asking the user to rerun a partly completed destructive step.

Automation is an **improvement outcome**, not a promise of perpetual background execution or unattended production changes.

## 8. Phased implementation plan (future; no approval implied)

- **A — Specification and evidence taxonomy (this document):** agree on stages, safety dimensions, scope, proof and risk boundaries. No UI/backend/provider writes.
- **B — Read-only, state-driven preparation UX:** leverage existing Development Plan / GitHub evidence and lifecycle answers; surface missing/stale items without persisting new release grades or issuing provider calls on GET.
- **C — Operator-attested recovery and environment evidence:** explicitly authorized, reviewable summaries tied to correct project/environment, with freshness and isolation. Preserve provenance, per-actor authorization and deletion policy.
- **D — Deterministic release preflight + validated runbooks:** tool capability checks, CI/SHA joins, migration-aware suggestions, safe local execution handoff. Never execute code or create users on page view.
- **E — Opt-in integrations and recurring monitoring:** only after provider security/consent, error handling, audit, cost and tenant-isolation tests. No automatic production deploy until separately approved and mechanically enforced.

Each phase requires its own implementation PR, source review and regression coverage. Pricing/entitlement must not deny basic safety guidance. A higher-tier automation feature must not be confused with safety guarantees.

## 9. Acceptance and negative tests

1. Prototype without database: no forced MySQL backup/recovery checklist, usable without GitHub.
2. Public app with persistent DB and untested backup: missing restore proof is visible and cannot become verified from file presence.
3. Exact source SHA changes, or the environment ID changes: relevant prior evidence becomes stale; past CI/verification does not carry over.
4. Migration detected: source/live ledger and rollback constraints shown as unknown until observed; fail safely on mismatched identity.
5. Successful CI + successful staging deploy: no claim that production deployed, smoke passed or latest live backup recovered.
6. Unlinked/private repo, viewer role or cross-user Plan: no provider/private evidence leak or unauthorized action; no GET-side AI, GitHub fetch or state mutation.
7. No connector or CLI support: transparent manual instruction; no fabricated assurance or one-click action.
8. Operator command generation: test unsupported flags, wrong shell, failed pipeline, partial artifact, credentials in diagnostics and cleanup idempotence.
9. Existing seven V53.8 gates and Developer Home Current Action remain unchanged until an explicitly reviewed implementation slice.
10. Release tooling acknowledges that code rollback does not necessarily undo DB migrations or restore lost writes.

## 10. Scope and current Canovia operations

This document records a product-design lesson from an operator-led encrypted MySQL backup, isolated restore rehearsal, off-device encrypted copy and temporary privilege cleanup. Those actions are **operator-reported external evidence**, not Canovia runtime features. Do not write personal account credentials, customer data, backup filenames/keys or connection strings into repository documents.

Current production release approval, Render configuration and database privileges remain governed by [Development Workflow](../CANOVIA_DEVELOPMENT_WORKFLOW.md) and the tracked [Release #488](https://github.com/1kz-ma1/Canovia-web/issues/488). This spec does **not** approve PR #513, authorize an Aiven/Render change, or turn on auto deploy.

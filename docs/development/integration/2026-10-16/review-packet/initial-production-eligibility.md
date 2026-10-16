# Initial production eligibility — fixed 32733572: NO-GO

2026-10-16 JST. Authority: Issue [#488](https://github.com/1kz-ma1/Canovia-web/issues/488), [integration packet](README.md), and the [release workflow](../../../../CANOVIA_DEVELOPMENT_WORKFLOW.md#deploy-behavior). This is an additional assessment of **`3273357296972b655b1f767b5b9457f54c65265a`**, not a replacement candidate or production approval. The previous 6-workflow/11-job result remains valid within its original coverage.

## Confirmed blockers and narrow repair

Two previously uncovered publication defects were reproduced at the fixed source:

1. `CANOVIA_PUBLIC_RELEASE_LEVEL=1invalid` resolves to L1 in production because config casts before validation. Decimal/exponent and boolean inputs can also be coerced. The repair preserves the raw config value for the existing strict `ReleaseLevelService` validator; valid L0–L3 remain supported, and hosted L4 remains L0.
2. At L0, a regular user with a retained L4 override can directly GET Study/Development dedicated Workspace screens despite hidden navigation. The repair applies the existing `release.level:1` middleware to their four top/detail screens and two operational-profile/target mutation endpoints. Existing ownership, entitlement and feature flags remain mandatory. This is not a new global kill switch for every legacy Plan/Task operation or a claim that all endpoints have been exhaustively audited.

`InitialReleaseAudienceTest` first produced **2 failures / 3 tests** on the fixed source. With the repair, the audience/exposure/foundation/entitlement checks pass **27 tests / 174 assertions**. Production-value cases include malformed numeric prefixes, decimal/exponent/boolean values and valid levels. HTTP checks cover L0 direct entry/mutation denial, retained Plans and saved preferences/override, account/core Plan access, L1 public access, an explicit L1 tester grant at public L0, and foreign-Plan denial. The CI registers these checks. Learning V6.10's disposable fixture explicitly selects L1, consistent with V6.11/13/21.

The repair also adds real-browser invalid question/assessment JSON checks to the existing V6.21 journey: repair UI appears, neither invalid input creates an Attempt, and the existing draft/reload/419/assessment journey still passes locally. Exact-head remote results belong to the repair PR, not retroactively to 32733572. The fixed source remains NO-GO; any repaired candidate needs its own main SHA and exact-SHA evidence.

## L0 / L1 and existing-user decision

| Audience | Expected impact after the repair | Required owner decision/evidence |
| --- | --- | --- |
| L0, no explicit tester grant | Overview, account/legal and core Plan access remain. Dedicated Study/Development screens redirect to Overview; their two operational writes return 404. Existing Plan/task data and saved Workspace preference are retained. | Accept the loss of those dedicated entry points for existing users; L0 is not transparent compatibility with the old permissive default. |
| L1 | Study/Development and their existing Free capabilities can be exposed. Career L3, internal writes L4, feature-specific OFF gates and resource/OAuth permissions remain separate. | Accept the reviewed Study/Development scope and device flows; L1 is global to ordinary eligible users, not an allowlist. |
| Public L0 plus explicit L1/L3 grants | Assigned testers can retain higher access. Stored regular-user L4 is ignored but preserved; admin preview is separately authorized. | Privately audit actual grant counts, admin assignment and effective flags. Lowering the public level alone does not revoke tester grants or external OAuth consent. |

Do not infer effective Live settings from `.env.example`, historical Render observations or CI. Keep APP_KEY, DB session backend/cookie/domain and authentication compatibility unchanged unless separately reviewed. Existing old/new login/session/CSRF rehearsal is reused; actual existing-user/device acceptance is still missing. No user IDs, actual grants or production values were queried here.

## Twenty source migrations: operator order and recovery

The complete names/blobs remain in [the source manifest](../source-manifest.json); 94→114 is a **source delta**, not the actual Aiven pending list. Existing exact-main MySQL 8.0/8.4 upgrade/repeatability/row/fence proof is reused. The repair changes no migration.

| Source order | Dependency / special risk |
| --- | --- |
| 1–2: observation revision, then source epoch | Nullable historical values remain unknown. SQLite indexed-column `down()` failures in the generic suite are not forward MySQL failure evidence or an approved rollback. |
| 3–5: authorization epoch columns → DB triggers → immutable member binding | Columns precede trigger enforcement. Verify effective trigger privileges, definitions and old-writer behavior before reopening writes. MySQL DDL is not transactionally atomic. |
| 6–8: recall provenance → nullable MCP expiry → identity history | Active identity backfill/unique index must precede removal of the old uniqueness rule. Later reused history can make `down()` impossible. Check actual identity/constraint state, not just the ledger. |
| 9–13: cleanup outbox → path fence → upload journal → generation evidence → write attempts | Retain this dependency order; validate physical columns/indexes and existing references. These tables do not authorize external provider operations. |
| 14–17: operational profile → targets → manual fields → storage revision fence | Validate partial-DDL state and expected indexes before permitting new writers. |
| 18–20: MCP Study proposals → Watch persistence → source epochs | Keep unapproved MCP/Watch/notification/provider functionality OFF. Creating storage does not enable the feature. |

Owner/operator sequence, **not executed by this review**:

1. Adopt a repaired, reviewed fixed main SHA, then create `release/YYYY-MM-DD-<shortsha>` at exactly that SHA. Do not create or promote a production PR from moving main or reuse #513. Do not pin 32733572 as eligible while its defects remain.
2. Separately authorize and perform the exact-candidate SELECT-only Aiven identity/ledger/physical-schema/effective-grant audit. Use the existing full-ledger tools; the old fixed 19-migration script is insufficient. UNKNOWN, foreign history, mismatched indexes/triggers or partial DDL stops the release.
3. Independently verify backup freshness/retention and a restore rehearsal with defined RPO/RTO in an isolated target. A snapshot or our disposable logical restore is not proof of Aiven restore. Any untested-restore exception needs an explicit owner policy decision; it is not granted by this assessment.
4. Before DDL, quiesce old HTTP writers, queue workers and scheduled writers using an operator-approved traffic/maintenance plan; verify no old writers remain. Capture a consistent recovery point and source/ledger metadata. Do not assume an ephemeral `artisan down` file covers every instance.
5. Apply only audited pending migrations once in a separately approved, exact-candidate migration job/CLI **before serving the new web image**. The normal web startup remains `CANOVIA_STARTUP_MIGRATIONS=check`. If the reviewed startup policy is used for that isolated job, `apply` additionally requires the full approved SHA equal to the runtime SHA. The configuration match is not owner authorization or image attestation.
6. Verify complete ledger **and physical** postconditions, retained rows, triggers/constraints and absence of pending/unknown history. Start/restart the candidate in check mode, build its caches, then open controlled traffic and verify health/auth/session/CSRF and chosen L0/L1 flows before broader exposure.

On any migration failure: stop the migration job and keep traffic/writers quiesced. Preserve restricted, redacted evidence; compare the exact failed migration, ledger and physical state. Do not retry the whole batch, change ledger rows to force green, run automatic `down()`, or start the candidate on an incomplete schema. Produce a reviewed forward repair or separately authorized restore plan with loss/conflict analysis. Do not promise old-image rollback: post-upgrade old/new login proof is not full old-writer/application compatibility, and old code can reopen publication/authorization boundaries.

## Existing PHP failures: release relevance

The baseline remains 64 failures / 253 errors. The [original comparison](baseline-comparison.json) is preserved, not relabelled green. The initial onboarding 120/2/7 result is a different suite.

- Public-impact subset: 53 tests independently rerun, **38 passed / 15 failed**, same selected historical failures. Many Study assertions expect the older default surface; current [Work/Preparation/Analysis contract](../../../PRELAUNCH_IA2J_STUDY_WORK_FOCUS_2026_10_15.md) and `StudyWorkspaceViewService` separate these views. They are not permission to restore the old UI or waive all failures. Remaining unclassified reachable behavior is an L1 acceptance gate.
- The two invalid JSON repair assertions remain failing in that PHP fixture, while both repairs now pass in the actual HTTP browser journey without creating history. Record the fixture/runtime distinction; do not suppress assertions.
- Account deletion's old test expects bytes to remain inside `RefreshDatabase`. Laravel's testing transaction manager executes callbacks at level 1, contradicting that assertion. An owned, separate MySQL 8.4 DB ran the existing lifecycle/rollback/current-reference tests: **17 passed / 84 assertions**, including real post-commit deletion, other-owner preservation and nested outer rollback. Production storage/cleanup still requires acceptance.
- Error signatures include 96 upload-journal schema-unavailable, 61 SQLite indexed-column-down errors, 49 other SQLite missing-schema, 18 mutation-probe target guards, 13 missing GD fixture generation, 6 Unit application-bootstrap, 3 MySQL-target guards, and 7 other errors. These are classifications of observations, not 253 confirmed harmless defects. A fresh SQLite generation-evidence subset still has 11 journal errors; the full G3 MySQL/PG matrix is required before any G3 enablement. Keep external providers OFF.
- Two provider-triage errors are raised by Laravel's HTTP assertion context (`all()` on an array following a redirect), so the original report does not identify the underlying provider failure. They remain unresolved for any release scope enabling that integration. One Map error interpolates an undefined PHP variable inside the test's expected source string; one benchmark rejects a non-disposable target. Neither proves successful runtime behavior.

## Approval and uncompleted gates

**Current verdict: NO-GO.** Before requesting deployment GO, attach the repaired fixed candidate SHA and exact CI, chosen L0/L1 audience/grant/flag policy, actual Render Web/MCP/Static Live and watched-branch/Auto-Deploy metadata, production branch protections/required checks, Aiven audit and restore evidence, writer-stop/forward-recovery plan, and desktop/Safari/PWA/physical iPhone/WKWebView acceptance. Native Xcode build/App Icon acceptance is still unrun; a Web deploy does not update the native binary or its trusted origin.

Use separate decisions for (a) Aiven audit/backup/restore operations, (b) migration execution and production promotion, (c) one manual Render deployment with Auto Deploy OFF, and (d) actual audience expansion after accepted smoke. No missing decision is supplied by CI success. Production merge must retain ancestry with a merge commit. Future Auto Deploy requires its own verified protection and checks-pass acceptance. No production merge, Render modification/deployment, real Aiven connection/change, OAuth/billing/public enablement or #513 update occurred.

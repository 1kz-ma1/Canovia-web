# Integration review packet — HOLD / production NO-GO

Prepared 2026-10-16 (Japan time). Authority: [master PR #769](https://github.com/1kz-ma1/Canovia-web/pull/769), latest applicable AGENTS and [development workflow](../../../../CANOVIA_DEVELOPMENT_WORKFLOW.md). This packet records completed code/local verification and the external prerequisites that still prevent PR creation, verified main integration and a formal release candidate. It is not owner GO.

## Fixed source and review units

Main remains `626a4ea6b19842089da91b107cc61da234858c7d`; production remains `15abdc9de6f24b5250fb255c579d1aa55facaf1a`. Native main remains `fc0e0c498afe35eda494f63356f7a0bae156bdbb`. Git reads and feature-branch pushes succeeded; GitHub API requests returned Forbidden. These observations do not establish current Render Live, PR state, branch protection or CI results.

| Review unit / branch | Fixed code SHA | Change and validation |
| --- | --- | --- |
| A — `feature/integration-freeze-manifest` | `76cd6ee51dd17072239964574fbe9c30822a5294` | Full local source inventory: 884 paths, 20 added migrations, 94→114, 572 initial branch refs; >300-path and invalid-history regression fixture passed. No runtime change. |
| B — `fix/integration-safe-exposure` | `08a70ddd687c0cfe66ecb43d001b79669333e352` | Safe hosted public default, actor-level maturity before entitlement, override protection and isolated browser runner. 29 targeted PHP tests / 236 assertions passed; Welcome, Development, Study E2E passed. |
| C — `fix/integration-startup-schema-fence` | `99ff945fc190bae41c65ed10ba041027fae3b3d2` | Read-only default startup fence, once-only explicitly pinned DDL, full 20-migration rehearsal and unconditional production workflow registration. 29 targeted PHP tests / 360 assertions (including malformed retry bounds) and 4 existing production workflow contract tests passed. |
| D — `docs/integration-release-packet` | This packet's Git commit | Compact provenance, baseline comparison, recovery/operator gates and portable synthetic session/restore harnesses. No application behavior change. |

**PR numbers and remote CI results are PENDING, not successful or merged.** The branches are pushed for review; API access is required to create the authorized PRs and inspect every required check before squash-merging into main. Do not push directly to main. Preserve PR #769 and Draft #513; no update/merge/close of #513 was performed.

Local aggregate `1875c7ab5d599fff155ef2108e70078ca93c7cc4` combines A/B/C without changing main or production. It is the tested application source, **not a `release/*` candidate authorized for promotion**. [Source boundaries](source-boundaries.json), [aggregate delta](aggregate-delta-summary.json), and [integration-only path delta](integration-change-delta.tsv) distinguish this source from the original freeze. Its migration blobs are identical to frozen main. A formal release SHA must be selected only after actual reviewed main integration and exact-head CI. After the full run, C received a narrowly scoped malformed-retry-bound follow-up (`99ff945f`, two shell/test files only); its targeted 29-test run passed with 360 assertions. The full-suite figures remain explicitly tied to aggregate `1875c7ab`, rather than being relabelled as a later-head run.

## Completed local evidence

| Check | Result / limits |
| --- | --- |
| Full unchanged main, same machine/dependencies | 2,660 tests: 2,255 passed, 64 failed, 253 errors, 88 skipped, 2 risky |
| Full local A/B/C aggregate | 2,675 tests: 2,270 passed, 64 failed, 253 errors, 88 skipped, 2 risky |
| Baseline comparison | **No added failure or error test IDs.** 15 additional tests passed. This is not a green full suite or proof of all skipped behavior. |
| Existing baseline causes | Remain UNCONFIRMED. [Failure/error index](existing-failure-index.json) records what already failed at fixed main; investigate only integration-relevant cases. Never suppress assertions or relabel them as successes. |
| Disposable upgrade | MySQL **8.0.46 and 8.4.11**: historical 94 schema → complete 114, all 20 additions, repeat migration, sealed historical rows, old SQL writer epoch/rebind fences passed at aggregate SHA |
| Additional schema/trigger regression | MySQL 8.0 at aggregate: 23 tests / 345 assertions passed. Independent 8.4 unchanged-main comparison also passed 23 / 345; it is not mislabelled as a changed-candidate result. |
| Old/new HTTP compatibility | Old production authentication/session sources and frozen old lockfile runtime → new aggregate on disposable MySQL 8.4: old login/new account restoration; real invalid-CSRF 419; new logout invalidates old account access |
| Logical backup/restore rehearsal | Both owned disposable MySQL containers: dumped/restored 114 ledger, byte-equal ordered row dumps, equal actual trigger definitions; ~5 seconds each. **Not Aiven backup availability, production RTO or real data recovery.** |
| Three real Chromium journeys | At aggregate SHA: all welcome choices/planless skip; Development guest claim + confirmed follow-through + login restoration; Study first draft + reload + 204/419 feedback + assessment passed. Provider-free child environment and private SQLite per journey. Initial Development Task is synthetic. |
| JS/static / native baseline | Existing 7 JS checks and iOS code/plist/assets checks reused; no iOS source changed. Xcode/Simulator/physical WKWebView unrun; strict release preflight still fails missing App Icon. |

The exact comparison and compact tool reports are committed beside this file. Raw HTML assertion dumps, SQL backups, credential files and session payload/logs are deliberately not committed. The initial environment was reused; no environment rebuild was performed. The second full run was necessary because the original 129-test onboarding result was not a full-suite baseline.

### Reuse the existing audit tools

`scripts/sql/p0_mysql_readonly_full_migration_ledger.sql` and `scripts/ci/p0_mysql_full_ledger_offline_check.py` already support the full current source. The unchanged checker accepted the disposable 114 ledger as **REVIEW_REQUIRED**, never release GO; see [report](disposable-full-ledger-review.json). Use that generic source/ledger comparison for the latest scope rather than changing the old fixed 19-migration verifier. Physical source shape, real service identity and grants still need their own exact-candidate operator evidence.

Portable additional probes, after creating the strictly labelled loopback fixtures and preparing the immutable old source with its locked runtime/assets:

```bash
python3 scripts/ci/integration_cross_version_session.py \
  --old-root /absolute/path/to/production-source-archive \
  --artifacts /tmp/canovia-session-evidence
python3 scripts/ci/integration_disposable_restore.py \
  --series all --artifacts /tmp/canovia-restore-evidence
```

The session probe checks old auth/session Git blobs, uses a synthetic key/password, real local CSRF middleware and DB-backed sessions. It sends the current Native UA suffix, which is **not actual iOS acceptance**. Restore creates/drops only `canovia_restore_ci` inside its verified, owned synthetic Docker targets. Never repurpose these probes for Aiven or an external DB.

## GO/NO-GO register

| Gate | Current decision / precise next evidence |
| --- | --- |
| GitHub operations | HOLD: allow `api.github.com` in this cloud environment (additive draft saved), then re-read #769/#488/open PRs, required checks and protection. Existing GitHub proxy authentication works; do not request another token merely because API egress is denied. Create/review A/B/C/D PRs, run CI and integrate only eligible heads. |
| Render read-only | UNKNOWN: user authorized reading **My Workspace**, but no Render tool is exposed in this session. Enable that connection or supply non-secret current Web/MCP/Static Live SHAs, watched branches, Auto Deploy and observation time. Verify workspace/service ownership; this permission is not deployment/settings approval. |
| Actual Aiven source/DDL | HOLD: separate owner authorization is required even for the new candidate's SELECT-only audit. Obtain exact service identity, complete source/ledger and physical schema, principal-specific effective grants/trigger policy; old #513 or 19-migration evidence cannot attest this scope. |
| Backup / recovery | UNKNOWN: actual backup existence/freshness, independent restore, data retention and operator RTO. Disposable logical restore above is only a rehearsal. No paid upgrade, fork or real restore was attempted. |
| Old writers / partial DDL | HOLD: define quiescence/traffic and worker fencing, compatible order and exact failure-specific forward recovery before applying. Default startup is check-only; do not enable `apply` to bypass a failure. No automatic migration down or restore over live writes. |
| Native / canonical origin | HOLD: Swift shell still trusts the Render origin; production Web may redirect a configured legacy host to a different canonical origin. Actual effective Live settings and physical navigation are unverified. Do not switch Native origin or bypass its origin restrictions without that evidence. |
| Feature audience | HOLD for public change: verify effective public L0/L1, explicit tester grants, Admin-only preview, disabled AI/MCP/G3/Watch/billing gates, ownership and OAuth/Plan grants. AP80 formal approvals remain zero; Future/WIP and paused lanes are preserved. |
| Production promotion/deploy | NO-GO: after reviewed main/CI, pin a new `release/<date>-<SHA>`, review complete diff, obtain distinct owner GO and merge into production with a merge commit. First deploy remains manually approved with Auto Deploy OFF. Render branch/env/deploy changes are separate approvals. |
| Physical acceptance | UNRUN: actual desktop/Safari/PWA/iPhone session/login/CSRF/Study/Development on actual accepted Live SHA. Headless Linux Chromium or Native UA is not a substitute. |

## Recovery and continuing release procedure

Keep `main` as development integration and production as an approved fixed candidate. Before each promotion, refresh the manifest and actual Live, classify migration/flag/audience deltas, and attach exact CI head results. Keep the next release small; an unreviewed moving main is never a release candidate. A successful main PR or source inventory does not activate a feature.

For an incident, stop the planned rollout and preserve evidence; select an already tested exposure reduction or compatible image, keep additive data, and assess old authorization behavior before reverting code. The new fail-closed publication and startup policies must not be casually removed during recovery. Backups, actual restore, production writes and external provider changes require operator authorization and evidence.

Resume feature lanes only after the owner explicitly reopens them with accepted initial limited-release evidence and stable release checks. Preserve specialist specs, state-first/personalization WIP, unapproved AP80 material and Future Design. Public roadmap release labels remain tied to actual separately accepted deployment/device evidence.

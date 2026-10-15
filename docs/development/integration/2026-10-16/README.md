# Integration freeze evidence — Phase A

Authority: [integration master PR #769](https://github.com/1kz-ma1/Canovia-web/pull/769), head `d1cdead047f6fe540a0e10db492709d21715ec1e`, `CANOVIA_INTEGRATION_FREEZE_RELEASE_MASTER_2026_10_16.md`. Its current GitHub PR state is UNKNOWN; the document is absent from the fetched main tree. Existing lane implementations remain PAUSED. No production operation is authorized by this packet.

## Immutable source boundary

| Boundary | Source observation |
| --- | --- |
| Web main | `626a4ea6b19842089da91b107cc61da234858c7d` |
| production branch | `15abdc9de6f24b5250fb255c579d1aa55facaf1a` |
| iOS main | `fc0e0c498afe35eda494f63356f7a0bae156bdbb` |
| Full-history difference | 278 ahead / 0 behind; **884 changed paths** |
| Migration sources | 94 → 114; **20 added**, no modified/deleted migration files |
| Remote branch refs | 572 at Git observation; [complete refs](remote-heads.tsv). Branch presence does not establish an open PR or incomplete implementation. |
| Render Web / MCP / Static current state | **UNKNOWN**. Master-spec observations are historical, not fresh Live verification. |
| Open PRs, Issue #488, required checks and branch protection | **UNKNOWN**: GitHub API denied by current environment egress. Native Git reads work. |

[Complete path and migration manifest](source-manifest.json) contains exact Git blobs, source-only status and provisional path-based owner/test/dependency/rollback/exposure classifications. Those classifications require domain review; they are not acceptance evidence. Source additions are **not** the actual production pending migration list. The old 94→113/19 fixture cannot attest this 94→114 candidate.

Reproduce without network or DB access:

```bash
python3 scripts/ci/integration_freeze_manifest.py \
  --base 15abdc9de6f24b5250fb255c579d1aa55facaf1a \
  --candidate 626a4ea6b19842089da91b107cc61da234858c7d
python3 -m unittest discover -s tests/ci -p test_integration_freeze_manifest.py -v
```

The test covers a >300-path delta, modified/added migrations, exact blobs, deleted paths, reverse ancestry, mutable refs and incomplete history. CI emits a separate inventory for its exact PR head; the checked-in baseline remains immutable.

## Domain decision register

Do not duplicate the specialist specifications linked by the integration master.

| Domain / owner | Current evidence / unresolved dependency | Integration decision |
| --- | --- | --- |
| Experience / Shared | IA-2j/2i/2k, zero-Plan welcome and saved actor state; browser/device acceptance separate | Test all three first-run choices, reload, downgrade and CSRF; no new onboarding feature |
| Learning | [paused AP80 handoff](../../LEARNING_AP80_INTEGRATION_PAUSE_HANDOFF_2026_10_16.md), V6.21 draft continuity | Draft is not a scored attempt. AP80 formal approvals remain zero; no publication or editorial restart |
| Development | V6.6 pinned evidence and V6.9 disposable Chromium | Evidence never confirms Task progress automatically; preserve owner approval and no external writes |
| Intelligence | v0.18 decision, v0.19/v0.20 diagnostics | Full-history hash authoritative; epoch shadow and Watch OFF; no production SQL probe triggers |
| MCP | Synthetic staging Development read acceptance; real AP pilot HOLD | Existing OAuth/subject/Plan grants remain mandatory at every release level; no real-account enablement |
| Platform / DB | [paused Aiven handoff](../../PRELAUNCH_AIVEN_AUDIT_INTEGRATION_PAUSE_HANDOFF_2026_10_16.md) | Exact 20-migration disposable rehearsal needed; real Aiven identity/grants/restore remain owner-gated UNKNOWN |
| G3 / Provider | R40 provider HOLD and R43 atomic commit | No external AWS/S3 I/O or DELETE approval; preserve quarantine and owner/resource guards |
| Public surface | Existing release/flag/entitlement layers; configured default currently L4 | Audit safe fallback and actor overrides before candidate; do not modify live configuration |
| Future / WIP | `docs/future/*`, state-first WIP and personalization bootstrap WIP | Preserve files; concepts are not implemented behavior or implicitly approved scope |

Only Learning/AP80 and Platform/Aiven have dedicated pause handoffs in the inspected main tree. The October 9 active lane roster and roadmap retain historical intent; they do not reopen development. Other lane-specific stop acceptance remains an evidence gap.

## Baseline and integration work

Existing environment results are reused: PHP 120 passed / 2 failed / 7 errors; JS 7 passed. Causes remain UNCONFIRMED until relevant investigation. No environment reinstall was performed. Targeted `ReleaseLevelFoundationV5820Test` + `ReleaseGateInventoryV5821Test` ran **13 tests / 75 assertions, all passed** before changes. This verifies existing explicitly configured L4 behavior, not safe absent/invalid production configuration.

Remaining implementation packages: safe public fallback plus actor/URL/mutation/owner tests; explicit migration/start separation and exact-SHA disposable upgrade; Study/Development/first-run E2E; final full regression and release packet. No candidate is release-authorized by this inventory.

## External gates and continuous release

- GitHub API access must be restored before current PR/Issue/CI checks, PR creation and verified main integration can complete. Do not substitute Git branch existence for PR status or push directly to main.
- Recheck Render Web/MCP/Static SHA, watched branch and Auto Deploy read-only. No Render or Aiven access is inferred from GitHub authentication.
- Preserve Draft #513 unchanged. Its fixed 2-migration candidate is independent.
- After tested main integration, freeze `release/<date>-<SHA>` at that exact main SHA. Attach a refreshed source manifest, exact CI heads and outstanding operator/device evidence; never relabel old 19-migration evidence.
- Production promotion uses an owner-approved merge commit. First deployment remains manual with Auto Deploy OFF, separate owner GO, backup/restore and old-writer conditions, then actual Live/session/CSRF/Web/PWA/iPhone smoke.
- Until first acceptance and production protection are verified, no automatic rollout or new feature lane resumes. Afterwards, keep source candidates small and pinned; code deployment and actor exposure remain separate decisions.

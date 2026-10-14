# Platform R23 — V5872 repeated regression source and intent review

Status: **HOLD; static source-backed review only**, 2026-10-15 JST.

## Selection
From R22's 20 unexperimented repeated files, select `tests/Feature/PlanActionDraftV5872Test.php` because its DB, Plan boundary, multi-evidence preview and collaborator authorization assertions are directly adjacent to the R18–R21 V5871 scope. The test appears in exactly **four named regression contexts**: V58.75, V58.74, V58.73 and V58.72. Its co-invoked suites differ across all four. This adds review scope only, not proof that a repeated invocation can be omitted.

## Verified source assertions (source inspection, not process execution)
The six test methods check (1) multi-WorkLog proposal without mutating Tasks, (2) cross-Plan evidence rejection, (3) read-only diff preview, explicit revision and repeated-apply rejection, (4) stale preview invalidation after manual edits and WorkLog changes, (5) editor/viewer/stranger denial for compose/refresh, and (6) accepted/dismissed draft terminal state. The source declares `RefreshDatabase` but that is a **static signal**, not proof of order-independent real DB/session isolation.

## Decision protocol
`python3 scripts/ops/platform_workspace_r23_v5872_scope.py` calls the existing R17 manifest parser, checks 72/160/118/21/42 unchanged inventory, requires exactly four distinct companion groups, verifies six exact current PHP test method names and the `RefreshDatabase` source signal. It emits the four named contexts, neighbors and command hashes, six risk themes and missing evidence. Any source/manifest drift is a nonzero **BLOCK**.

The following remain missing: domain owner acceptance of each named step's purpose; real A/B/A′ JUnit omission parity; application-level mutation detection for V5872 beyond R20's V5871; assertion/side-effect and DB/session equivalence; independent runner, full 72-command replay and reliable performance comparison; separate workflow modification approval. **Never** infer semantics or security safety from source code alone.

This slice integrates an offline review tool and negative unit contracts in existing Platform Preflight. It does **not** add R23 mutations or a disposable PHP experiment. A further R24 slice can run a narrowly bounded V5872 A/B/A′ and real implementation mutation experiment using isolated PR GitHub Actions, contingent on keeping the normal workflow and all 42 repeats unchanged. The R23 conclusion stays HOLD.

No app/controller/schema changes, no branch protection changes, no paid resources, no Render/Aiven/production/iOS deployment or release approval.

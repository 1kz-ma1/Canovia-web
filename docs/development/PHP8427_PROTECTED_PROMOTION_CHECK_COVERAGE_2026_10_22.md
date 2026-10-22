# PHP 8.4.27 emergency production — required check coverage audit

> 2026-10-22 / Platform Hotfix / **READ-ONLY DIAGNOSTIC, PRODUCTION NO-GO**.
> No production branch or Ruleset change, no Aiven connection, no Render deploy.

## Scope and fixed evidence

- Latest `main` at task start: `da6342a20bd1155e18096bd87c203df92f3057a5` (PR #833 already squash merged).
- Actual GitHub `production` and Render Web Live baseline: `15abdc9de6f24b5250fb255c579d1aa55facaf1a`.
- Already validated image/no-auto-DDL candidate: `0ac198acf3c087652d0a164c55602fb60922a8d0`, isolated CI [39792307763](https://github.com/1kz-ma1/Canovia-web/actions/runs/39792307763). This is NOT a production release.
- GitHub active Ruleset `24954459`: requires PR, **merge** method, no force updates/deletion, and five exact required status names. Ruleset has zero mandatory reviewers; owner GO remains an additional governance requirement.
- Existing `main` normal promotion workflow is not present on old `production`. It assumes a `release/*` SHA from `main`, which is incompatible with the pinned old-Live candidate. Do not silently disable or repurpose the normal gate.
- Old branch workflows `v56-12-workspace-resume.yml` and `v56-8-migration-recovery.yml` listen for **main** PRs, while `p0-disposable-mysql-schema.yml` is path-scoped to **main** PRs. These conditions cannot be relied upon to report the current five required `production` PR statuses.

## Exactly five required GitHub checks

| Context | Intended owner job | Blocker in old `production` |
| --- | --- | --- |
| `Production promotion contract` | `promotion-contract` | Workflow absent |
| `workspace-resume` | `workspace-resume` | PR trigger targets main, not production |
| `migration-recovery` | `migration-recovery` | PR trigger targets main, not production |
| `P0 schema integrity (MySQL 8.0)` | `mysql-p0-schema-integrity` matrix | Path-scoped main PR trigger |
| `P0 schema integrity (MySQL 8.4)` | Same matrix | Same blocker |

GitHub official docs require a qualifying event (normally `pull_request` or `push`) for required check evaluation. A successful `workflow_dispatch` workflow run does **not** satisfy required status checks for a PR; a path-filtered required workflow can remain Pending. Source: https://docs.github.com/en/pull-requests/how-tos/merge-and-close-pull-requests/troubleshooting-required-status-checks

## New offline implementation and test boundaries

- `scripts/ci/php8427_production_required_checks_coverage.py` reads the **fixed old production Git tree only** and diagnoses the four expected workflow sources, job names, unconditional `pull_request` and `push` trigger scopes, MySQL 8.0/8.4 disposable marker, and the exact reviewed Live/candidate SHA presence in a possible emergency promotion contract. It returns machine-readable `NO_GO`, `source_coverage`, `blockers`, `unverified`.
- `tests/ci/test_php8427_production_required_checks_coverage.py` exercises synthetic positive static coverage and denials for wrong branch, path filtering, missing workflow/required job, conditional job, missing MySQL series or isolation marker, `workflow_dispatch`-only, and wrong pinned candidate.
- New independent GitHub Actions workflow reads the exact production SHA by GitHub-only fetch. It **expects coverage BLOCK and production NO_GO**; green CI means the diagnostic found current blockers, not that all required checks will run.
- The parser is intentionally **restricted to known workflow shapes**, not a full YAML parser. Even a PASS from this static inspection is not proof of GitHub's actual event recognition, expected check-app ownership, successful jobs or protected merge behavior. Live PR/merge SHA evidence remains mandatory.

## Next conditional engineering step — owner-reviewed emergency route

This is a proposal only. The release itself is not created here:

1. Preserve original candidate `0ac198ac` as an immutable baseline. Prepare a **new reviewed** candidate based only on `15abdc9d` (or this original candidate), with byte-identical runtime Dockerfile and guarded no-DDL boot, and **only required CI workflow additions/adaptations**, all listed in a frozen allowlist.
2. Add an independently verified **exact-SHA emergency promotion contract** to the new candidate. It must reject every nonapproved head, unexpected path, unexpected migration content, absent owner GO, missing Aiven/backup evidence, and any prohibited direct/squash update. The normal main-ancestry gate must remain intact for nonemergency releases.
3. Make the 5 existing Ruleset check names report on every **production PR** by unconditional triggering: production `pull_request`, with **production push/merge-SHA** checks as appropriate. Do not use renamed, synthetic-success or manual-only status jobs.
4. Preserve meaningful tests rather than adding trivial echos under familiar check names. Legacy workflow tests must be compatible with the old app, disposable MySQL, and the no-DDL boot script. If not, redesign and rerun isolated actual tests; do not claim a copied workflow is sufficient.
5. Verify proposed check identity and workflow execution with a draft `production` PR only after a separate owner authorization for this exception. Do not merge. If workflows cannot run against the PR head because of GitHub event semantics or protection settings, stop.
6. Independently establish current Aiven ledger/physical schema (read-only operator audit), backups/recovery path, production env flags, and actual Live SHA. Existing historical `94/94` evidence is not current evidence.
7. Only an explicit separate owner GO may authorize a two-parent protected **merge commit** to `production`, and a further explicit deployment/Render branch retarget. Auto Deploy must stay OFF until separately accepted. Verify Web and iPhone smoke after approved deployment.

**Forbidden shortcuts:** ruleset weakening/deleting, forgery of check status, broad `main` deployment, manual Render deploy of moving `main`, production Aiven DDL, bypass of owner approval, or interpreting this diagnostic's green result as release clearance.

Related artifacts: PR #832 patched PHP on main; PR #833 offline exact SHA NO-GO preflight; `docs/development/PHP_SECURITY_EMERGENCY_PROMOTION_GATE_2026_10_22.md`; `fix/php-8427-live-no-ddl-20261022`; Issue #488. All remain independent from live deployment.

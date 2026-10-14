# Development Operational V6.0 — Actual GitHub candidate audit and full offline Git delta

> As of 2026-10-14 JST. GitHub read-only observation and a development-only audited tool change. **No release approval, remote production/Render/Aiven change or real iPhone acceptance.**
> Source of truth for baseline: GitHub branch and PR evidence retrieved on this date, not an independently observed current Render deployment.

## Verified GitHub snapshot

| Object | Exact GitHub SHA | Provenance / limitation |
|---|---|---|
| `production` branch | `15abdc9de6f24b5250fb255c579d1aa55facaf1a` | Read-only branch API on 2026-10-14; **not** a fresh independently observed Render Live SHA |
| Original release PR #513 branch `release/2026-10-13-bb8248f` | `bb8248f25545ae0d600fbb68f66c41c360004e2c` | Draft, unmerged into production; pinned branch observed at this SHA |
| Development main at observation start | `1b51344eba91312de1d4db478bbf6a1a107ba517` | Already moved during V6.0 work, **not** frozen candidate |
| Latest main at V6.0 feature-branch creation | `5dd408db2e7a4857657b42723425985c71532fb5` | Source development integration, not release-approved |

GitHub compare `production → release/2026-10-13-bb8248f` reported **46 commits**, **219 changed paths**, two migration files:
- `database/migrations/2026_10_12_010000_add_source_revision_to_development_activity_observations.php`
- `database/migrations/2026_10_13_021000_add_source_epoch_to_development_activity_observations.php`

Compare `bb8248f → 1b51344e` reported **114 further commits**. Its returned changed-file collection was **300 entries, truncated by GitHub API**, so 300 is not the verified total. Among the displayed paths are authorization epoch migrations, MCP consent/identity changes, private file fencing, and Development V3/V4/V5.3/V5.7 operational data changes; those migrations are **not** present in the already pinned #513 candidate. An app QA result from #513 is therefore not evidence for newer main.

PR #513 is still **DRAFT / NO-GO**, with a separately recorded 2026-10-13 operator-led Aiven metadata/ledger audit and Aiven backup *existence* observation. These are historical candidate-scoped reports, not fresh production DB identity, independently verified backup restoration, auto-deploy settings, new features, or real-device acceptance for today's main. Do not upgrade statuses from historical text; re-observe any fact used for a new release candidate.

## Delivered V6.0 code

`scripts/ci/development_operational_v60_git_release_delta.py` is an **offline and read-only local Git object** analysis: it does not query GitHub, Render or Aiven. It requires four explicit inputs:
- `--production-branch-sha`: operator supplied known GitHub production branch SHA; never automatically relabeled as independently observed Live Render.
- `--candidate-sha`: exact 40-digit reviewed release commit.
- `--main-snapshot-sha`: a separately observed full GitHub main SHA, not a moving ref.
- `--release-ref`: existing local `release/YYYY-MM-DD-<matching 7–12-character SHA prefix>` whose exact `refs/heads` value equals candidate SHA.

The current local checkout `HEAD` must equal the release candidate commit, and baseline → release candidate → main snapshot must be a valid ancestry chain. If any input/ref/object is missing, malicious, moved, divergent or a non-commit, output only a constant `BLOCK / GIT_CANDIDATE_DELTA_UNVERIFIABLE`. It never echoes untrusted paths/refs on failure.

On success it computes from the **entire local Git object graph**, not a paginated GitHub response:
- Exact `git rev-list --count` values from production branch to candidate, and candidate to specified main snapshot.
- Full `git diff --no-renames --name-only -z` path counts for each range, grouped into DB migrations, deployment/CI, security/identity, Web/mobile surface, tests/audit and other source.
- Exact safely formatted migration filenames to queue for detailed operator review.
- Candidate not equal to the main snapshot: `NEWER_MAIN_NOT_IN_PINNED_CANDIDATE`.

Even when Git sources are complete, the report is **always `result=BLOCK`, `release_authorized=false`**, and explicitly marks Render Live SHA, backup/restore and device testing false/unverified. `exit 0` means *Git evidence collection succeeded*, not a release decision. The local snapshot is not a remote signed attestation. Later main changes or a different candidate require a **new** audit.

## Safe operator procedure (operator machine only)

This procedure does not change GitHub branches or the actual application:

1. Fetch just the required refs into a trusted clone without merge, and re-read the GitHub exact branch SHA. Do not rename/delete/repoint an existing release branch, or promote to `production`.
2. Ensure a local `refs/heads/release/2026-10-13-bb8248f` exists and resolves **exactly** to the observed GitHub release SHA, then check out the release SHA in a detached checkout. Do not use `main` or `HEAD` as an operator's candidate argument.
3. From that clean, pinned checkout, execute:
   ```sh
   python3 scripts/ci/development_operational_v60_git_release_delta.py \
     --production-branch-sha 15abdc9de6f24b5250fb255c579d1aa55facaf1a \
     --candidate-sha bb8248f25545ae0d600fbb68f66c41c360004e2c \
     --main-snapshot-sha 1b51344eba91312de1d4db478bbf6a1a107ba517 \
     --release-ref release/2026-10-13-bb8248f
   ```
   This reproduces the **initial observed snapshot**, not the newest main. To audit the final candidate/main, substitute a **freshly observed exact SHA**, review the new delta, and run from an exact checkout that contains this V6.0 script. If auditing old #513 source, V6.0 did not exist at that time: run the V6.0 version from a separate vetted tool checkout or approved tooling path while pointing the Git repo worktree at the fixed candidate. Do **not** pretend the historical candidate contains the new V6.0 audit code; see this limitation and rerun the tool against a disposable clone in a reviewed environment.
4. Record only sanitized output and reviewed provenance. The local Git analysis **never replaces** V5.8's operator-held SELECT-only Aiven schema/ledger inspection, V5.9's candidate packet, owner signoff, or the Release #488 production controls.

## Blocking release gaps

- GitHub `production` branch and historical issue statement do not independently verify **the current Render production service owner, deployment SHA, and auto-deploy configuration**.
- At least one candidate is older than current main; choose whether to release #513's pinned scope or prepare and fully review a new candidate. **Do not silently rebase or retarget #513.**
- Git diff proves file changes, not actual effective DB schema. All migration files between the true Live revision and proposed new release require a full audit, not just V5.3/V5.7; Aiven metadata and effective grants must be separately verified for the exact candidate.
- Backup existence does not prove isolated restore. Active service account, permissions, DB URLs, configured environment, migration lock and rollback feasibility remain a separate release decision.
- Production PR branch protection, CI rules and **Owner go/no-go** must be verified on the new exact candidate. PR #513 explicitly records an owner-approval enforcement limitation.
- Real desktop/iPhone WebView acceptance and Render observed deployed SHA remain future **post-approval** checks. No claim of device-tested newer main.

**Do not create another release candidate or update #513 until its desired scope and the full unshipped delta are reviewed.** Main PR merge ≠ production branch promotion ≠ Render deploy.

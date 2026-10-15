# Platform — Render binding phase gate for first controlled release

Date: 2026-10-15. Scope: **offline operator review tool only**. Status: **PRODUCTION NO-GO** until owner separately accepts every release gate. No Render or Aiven mutation, production promotion, branch deletion, or Auto Deploy activation is performed.

## Problem and observed baseline

The live Canovia Web service in Render workspace `My Workspace` (`tea-d4vb3f6mcj7s73djkrqg`, service `srv-dagfn2id0e5s73cac7ng`) was independently inspected through read-only Render metadata and deploy history on 2026-10-15:

- Repository: `https://github.com/1kz-ma1/Canovia-web`
- Git binding: `main`, `autoDeploy=no`, `autoDeployTrigger=off`
- Live deployment: `dep-db5fgl942hec738pbq30`, commit `15abdc9de6f24b5250fb255c579d1aa55facaf1a`
- GitHub `production` branch remains at the same baseline commit.
- Frozen `release/2026-10-13-bb8248f` / PR #513 is **Draft NO-GO**. The separate initial release candidate and its 2-migration Aiven audit do not authorize pushing a moving main, reusing later migrations, or activating deployment.

The central hazard: manually deploying while Render still watches `main` can unintentionally ship unrelated integration code. Even after a branch change, live SHA, Auto Deploy state and release approval must be independently checked.

## Added implementation

`scripts/ops/platform_render_binding_phase_gate.py` reads **one manually curated, secretless JSON snapshot** from outside the repository. It never reads environment variables or calls Render/GitHub APIs and cannot attest the origin of its inputs. A passing local result is `REVIEW_REQUIRED`, never `GO`.

All phases require exact workspace ID, service ID, Canovia repo URL, `live` deployment status, requested expected Live SHA, `auto_deploy=no`, `auto_deploy_trigger=off`, and observation not more than 30 minutes old (or more than 2 minutes into the future). Unexpected keys, secret-bearing fields, malformed data, symlinks, missing/mismatched values or stale snapshots return `BLOCK` (exit 2).

| Phase | Required Render watched branch | Expected Live SHA meaning | Next action |
| --- | --- | --- | --- |
| `before-branch-switch` | `main` | Last independently observed live production baseline | Do **not** deploy; review pinned release and owner/operator gates |
| `after-branch-switch` | `production` | Same **old** Live SHA before first manual deployment | Still OFF; owner may separately decide on an exact release deployment only after all gates |
| `after-manual-deploy` | `production` | Exact owner-approved production release merge SHA actually live | Requires Web/iPhone smoke, migration/log checks, release acceptance; Auto Deploy still OFF |

The phase gate is deliberately not a replacement for `platform_release_preflight.py` (Git ancestry and full changed-path inspection), the real Aiven SELECT-only ledger, recovery exercise, production effective env review or the owner's explicit go/no-go.

### Prepare an operator-observed secretless snapshot

On the owner's trusted machine, **separately** inspect the Render dashboard service identity, repository/branch, Auto Deploy configuration, latest *live* deployment and SHA. Transcribe only these non-secret fields into a temporary JSON file stored **outside** the repository:

```json
{
  "source": "render-ui-secretless-manual-observation",
  "observed_at_utc": "2026-10-15T00:00:00Z",
  "workspace_id": "tea-d4vb3f6mcj7s73djkrqg",
  "service_id": "srv-dagfn2id0e5s73cac7ng",
  "repo": "https://github.com/1kz-ma1/Canovia-web",
  "branch": "main",
  "auto_deploy": "no",
  "auto_deploy_trigger": "off",
  "live_deploy_status": "live",
  "live_sha": "15abdc9de6f24b5250fb255c579d1aa55facaf1a"
}
```

The timestamp is an **illustrative old value, not fresh evidence**: replace all fields with what is currently shown by Render. Never invent an observation, automatically substitute GitHub `main` SHA, export Render credentials, or paste environment variables. Do not copy credentials or raw service JSON into this file.

```sh
python3 scripts/ops/platform_render_binding_phase_gate.py \
  --snapshot "/private/path/render-secretless.json" \
  --phase before-branch-switch \
  --expected-live-sha 15abdc9de6f24b5250fb255c579d1aa55facaf1a \
  --expected-workspace-id tea-d4vb3f6mcj7s73djkrqg \
  --expected-service-id srv-dagfn2id0e5s73cac7ng
```

After a **separately owner-authorized** branch switch, replace the observed branch with `production` and select `after-branch-switch`. After the **separately authorized** manual deploy, select `after-manual-deploy` and pass the exact promoted release merge SHA as expected Live SHA. Do not substitute the source branch SHA if the merge creates a different commit.

### Evidence and limitations

- Classification output intentionally reports no input values, secrets, paths or production data, and always sets `render_binding_attested=false`, `live_deploy_attested=false`, `runtime_environment_verified=false`, `migration_verified=false`, `device_smoke_verified=false`, `owner_production_go_recorded=false`, and `release_authorized=false`.
- CI runs synthetic local JSON fixtures for safe/unsafe phases, live mismatch, configuration drift, snapshot freshness, malformed fields and symlink/oversize inputs. It does **not** execute a real Render branch switch, production merge, manual deploy, iOS smoke or Aiven query.
- Existing `production` Ruleset should mechanically enforce owner-controlled promotion. The earlier observed zero required approving reviews remains an unresolved owner-control design decision; do not assume review alone is enforced.
- Keep `production` Auto Deploy **OFF** until the first manual deploy and physical Web/iPhone acceptance are complete and the production push-SHA required checks exist on `production`; only then separately approve `checksPass`, never `On Commit`.
- Branch hygiene: the separately existing `platform_branch_inventory.py` is the sole read-only classification workflow; no deletion or PR closure is approved by this implementation.

Related: `docs/CANOVIA_DEVELOPMENT_WORKFLOW.md`, `docs/development/PLATFORM_RELEASE_BASELINE_AND_PREFLIGHT_2026_10_14.md`, `docs/development/PRODUCTION_CHECKS_PASS_READINESS_2026_10_13.md`, Issue #488 and Draft PR #513.

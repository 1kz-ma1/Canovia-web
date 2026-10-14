# Development V6.1 — Existing release #513 versus new main-snapshot candidate

> **State: development integration candidate only** (2026-10-14 JST).
> Purpose: bounded, read-only evidence comparison. No production authorization, new release ref, deployment or Aiven DDL.
> Source: GitHub branch and PR queries on this date; latest main may have advanced since this observation.

## Actual read-only GitHub observations

| GitHub object | Observed SHA | Distinction |
|---|---|---|
| `production` branch | `15abdc9de6f24b5250fb255c579d1aa55facaf1a` | GitHub branch identity only; not fresh Render Live verification |
| Original PR #513 `release/2026-10-13-bb8248f` | `bb8248f25545ae0d600fbb68f66c41c360004e2c` | Draft, still unmerged; existing pinned candidate, not retargeted |
| `main` initial V6.1 snapshot | `8b38a7c5361b2a1192213bccbe4240720283940d` | Candidate **hypothesis** only; no new release branch created |

From GitHub compare for these exact Git objects:
- `production → #513`: **46 commits**, **219 changed paths** returned, including **two source-revision/epoch migrations** on 2026-10-12 and 2026-10-13.
- `#513 → main@8b38a7c`: **117 commits** after the old candidate. API returned **300 changed paths, capped**; total path count is therefore **not established** by this API result. Displayed paths include authorization epoch, MCP grant, private file protection, Development V3/V4/V5.3/V5.7 data structures, and others.
- `production → main@8b38a7c`: **163 commits**. API returned **300 changed paths, capped**, so the exact full changed-file count requires the local Git object comparison. The listed migration files are a **partial changed-source-file list**, not proof of actual pending Aiven DDL.

These counts are tied to the snapshot SHAs above, **not live Render and not latest forever**. The old candidate's 46 commits plus 117 later commits equals 163 along this verified GitHub ancestry; **changed-path counts cannot be added**, because later commits can modify or revert previously changed files.

## Two bounded options — neither is automatically approved

| | Option A: retain #513 candidate | Option B: review a new candidate from the observed main SHA |
|---|---|---|
| Source | Existing exact `bb8248f...` draft, already pinned | Observed `8b38a7c...` main commit as a **hypothetical candidate**, not pinned |
| Relative commits from GitHub production branch | 46 | 163 |
| Scope | Limited older release contents; 117 newer commits excluded/deferred | Entire integration scope through the chosen snapshot |
| Changed path count | 219 reported by GitHub | Full count **not established** by truncated API; V6.1 local tool available |
| Migration source paths | 2 reported | Multiple later schema changes; exact complete path audit required |
| Testing evidence | Historical PR #513 CI + candidate-scoped operator metadata audit, **not release GO** | New exact-head full CI, compatibility, data-store and security audit required |
| Operational implications | Smaller source diff than the new scenario, but still includes Auth0/MCP/UI/Learning and production-schema impact | Much larger unshipped surface, plus authorization/file custody and newer Development changes |
| Future scope | Recent Development V5–V6 and other main work deferred | Must explicitly inspect *all* intermediate changes before choosing a release window |
| Authority | Existing draft PR remains NO-GO | No new branch/PR or deployment authorization; entirely an assessment |

**Decision rule:** no automated "best" or risk-percentage formula. A scope owner must independently approve the target release functionality first. Both options require full ledger, provider identity, effective configs, backup existence and isolated restore, release controls, real-web/iPhone acceptance and explicit production GO before any switch. Historically reported metadata/backup presence on PR #513 does **not** prove today’s real restore capability. Selecting new main does not allow silently rebasing/updating the old candidate.

## Delivered V6.1 code

`scripts/ci/development_operational_v61_release_scope_compare.py` reuses V6.0’s strict offline Git-object/ancestry checks. Four required facts are supplied by the operator: GitHub `production` branch SHA, *existing* pinned release SHA, the exact `main` snapshot SHA, and matching local `release/YYYY-MM-DD-<sha-prefix>` ref. The local HEAD must equal the pinned old candidate. `--git-repo` points to a **trusted local clone** with all Git objects; it never connects to GitHub or modifies refs.

It computes the **full** `production→existing`, `production→main` and `existing→main` commit/path/migration deltas without the GitHub API 300-path limit. It returns separate option records, the deferred gap, and the same independent acceptance requirements for each scope. A new main-snapshot **hypothesis** is *not* turned into a release ref. The result is always `BLOCK`, even if the local audit succeeds. A moved ref, invalid SHA, divergent ancestry, truncated/unsafe Git path data, missing object or bad checkout produces the fixed `BLOCK / RELEASE_OPTIONS_UNVERIFIABLE` without echoing inputs.

Execute from a vetted V6.1 tool tree with a separate local pinned historical candidate clone (no real service connection):

```sh
python3 <V6.1-tool-checkout>/scripts/ci/development_operational_v61_release_scope_compare.py \
  --git-repo <trusted-candidate-clone> \
  --production-branch-sha 15abdc9de6f24b5250fb255c579d1aa55facaf1a \
  --existing-candidate-sha bb8248f25545ae0d600fbb68f66c41c360004e2c \
  --main-snapshot-sha 8b38a7c5361b2a1192213bccbe4240720283940d \
  --release-ref release/2026-10-13-bb8248f
```

The clone must contain the correct local release ref and be checked out at exactly `bb8248f`. This command does not create the ref. If the GitHub remote `main` advances or the operator chooses a different release scope, take a **new** exact GitHub snapshot and rerun (not a moving branch name). CLI exit code 0 means an unapproved comparison was generated; output `release_authorized` remains false.

## Blockers and next manual decision

- Aiven production identity/ledger and actual remaining migration list for the exact chosen SHA have not been freshly collected; no Live DDL in this slice.
- Render Live deployment SHA and Auto Deploy OFF must be independently re-observed at release time, not inferred from GitHub production.
- PR #513's stated backup existence is not an isolated restore or current repair test.
- GitHub release rules, owner approval enforcement and real iPhone/Web smoke remain specific independent gates.
- **V6.1 must not open a new `release/*` ref, update PR #513, merge `production` or deploy without a separate release-scope decision and approval.**

## Automated acceptance

- Local disposable Git repo tests for exact two-option commit/path/migration comparisons and deferment without false risk ranking.
- Separate operator-tool checkout CLI and pinned historical candidate clone, including ref-move and untrusted-ref rejection.
- Invalid source/head/baseline ancestry, partial evidence and wrong SHA fail closed without exposing input.
- All output items explicitly retain unverified operator/production/DB/restore/device states. Development, Workspace, Migration Recovery and Platform CI must pass on the feature PR HEAD.
- **No live DB/Render/device work is performed or marked PASS.**

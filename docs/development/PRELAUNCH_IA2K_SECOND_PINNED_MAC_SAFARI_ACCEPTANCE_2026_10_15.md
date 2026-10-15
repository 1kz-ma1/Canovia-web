# IA-2k — Mac SafariのIA-2j表示検証（2つ目の固定ラボ）

> 2026-10-15 / owner-operated / **NOT RUN on Mac until owner follows instructions**.
> Review source: IA-2j [PR #758](https://github.com/1kz-ma1/Canovia-web/pull/758) merged source `fdbe29de79f0ca7df255a978e13f94f248a3ff32`.
> This **does not authorize** any production deployment or automatic iOS release.

## Purpose and strict isolation

The owner successfully launched the older IA-2h/IA-2i localhost review `069e9b57...` and reported a cluttered Study Work view after creating a Study Plan and importing external AI question JSON. The IA-2j Work focus improvement has been CI validated and merged to development main but **is not inside the owner's older frozen worktree or its SQLite**.

This follow-up allows **only** the two explicitly allowlisted reviewed source SHAs to run with the existing safeguarded stdlib Python CLI. It extends `scripts/ci/prelaunch_ia2i_local_lab.py` with `--source-sha` (default = original `069e9b57...` unchanged) and isolates:
- IA-2h: **port 8797**, session cookie `canovia-ia2i-session`, original private SQLite and original Git worktree
- IA-2j: **port 8798**, session cookie `canovia-ia2j-session`, a **new** owner-only SQLite and a **new** Git worktree

The command **rejects all other SHAs**, wrong SHA/worktree pairing, existing `.env`, Laravel cached config, symlink and unsafe lab directories, and cross-SHA lab re-use. Original `init/check/migrate/serve` interface without `--source-sha` keeps working for IA-2h. `--confirm-disposable` is still mandatory for DB migration and server. No release secrets, Aiven, provider keys or Render environment variables are inherited.

The loopback listener only governs **inbound** access; outbound network is not OS-firewalled. Do not enter external service credentials, launch public tunnels or enable AI/billing/OAuth. Use **synthetic accounts and practice content** only. Avoid simultaneous back-and-forth browser tabs between two localhost ports: although Laravel session cookie names are distinct, other cookies are host-scoped and not port-scoped. Prefer stopping the previous server (`Control+C`) and opening a **Safari Private Window** for the new test. Stopping the server does **not** erase its SQLite.

## Mac Terminal operator steps

These instructions assume the user's already verified clone and old frozen lab exist. The user must run them locally; the assistant cannot access their terminal.

### A. Prepare a separate immutable review worktree

```bash
cd "$HOME/Developer/Canovia-web"
git fetch origin main
git cat-file -t fdbe29de79f0ca7df255a978e13f94f248a3ff32
git worktree add --detach "$HOME/Developer/Canovia-IA2j-fdbe29de" fdbe29de79f0ca7df255a978e13f94f248a3ff32
git -C "$HOME/Developer/Canovia-IA2j-fdbe29de" rev-parse HEAD
```

Confirm the exact 40-character SHA, stop on any error. The new CLI helper is a later integration and must be obtained in the **ordinary** development clone:

```bash
cd "$HOME/Developer/Canovia-web"
git status --short
git pull --ff-only origin main
python3 scripts/ci/prelaunch_ia2i_local_lab.py --help
```

If the checkout contains local changes or a non-fast-forward branch, stop rather than resetting/forcing. Confirm the helper's help lists `--source-sha`. The original frozen checkout is **never upgraded to IA-2j**.

### B. Create the fresh owner-only SQLite

```bash
cd "$HOME/Developer/Canovia-web"
FROZEN="$HOME/Developer/Canovia-IA2j-fdbe29de"
LAB="$HOME/Library/Application Support/Canovia-IA2k/fdbe29de"
SHA="fdbe29de79f0ca7df255a978e13f94f248a3ff32"
python3 scripts/ci/prelaunch_ia2i_local_lab.py init --checkout "$FROZEN" --lab-dir "$LAB" --source-sha "$SHA"
python3 scripts/ci/prelaunch_ia2i_local_lab.py check --checkout "$FROZEN" --lab-dir "$LAB" --source-sha "$SHA"
```

Expected: `IA2I_SOURCE_PINNED=fdbe29de...`, `IA2I_LOCAL_URL=http://127.0.0.1:8798`, `IA2I_DB=ISOLATED_OWNER_ONLY_SQLITE`, and `DEVICE_ACCEPTANCE=NOT_RUN`. The prefix remains `IA2I_` for backward compatibility, even for IA-2k. Initial `VENDOR_READY=false` / `ASSETS_READY=false` is normal.

### C. Install locked application dependencies and assets, only in new checkout

```bash
cd "$HOME/Developer/Canovia-IA2j-fdbe29de"
env -i HOME="$HOME" PATH="$PATH" TMPDIR="${TMPDIR:-/tmp}" composer install --no-scripts --no-plugins --no-interaction --prefer-dist --no-progress
env -i HOME="$HOME" PATH="$PATH" TMPDIR="${TMPDIR:-/tmp}" npm ci --ignore-scripts
env -i HOME="$HOME" PATH="$PATH" TMPDIR="${TMPDIR:-/tmp}" npm run build
```

Locked dependency installers download/unpack third-party packages; inspect the source/lockfiles and stop on errors. Do not copy an existing `.env`, database, key or `storage` contents. `--no-plugins --no-scripts` prevents Composer install-time lifecycle code where supported.

### D. Run only disposable local DB migration and local Web, after check

```bash
cd "$HOME/Developer/Canovia-web"
FROZEN="$HOME/Developer/Canovia-IA2j-fdbe29de"
LAB="$HOME/Library/Application Support/Canovia-IA2k/fdbe29de"
SHA="fdbe29de79f0ca7df255a978e13f94f248a3ff32"
python3 scripts/ci/prelaunch_ia2i_local_lab.py check --checkout "$FROZEN" --lab-dir "$LAB" --source-sha "$SHA"
python3 scripts/ci/prelaunch_ia2i_local_lab.py migrate --checkout "$FROZEN" --lab-dir "$LAB" --source-sha "$SHA" --confirm-disposable
python3 scripts/ci/prelaunch_ia2i_local_lab.py serve --checkout "$FROZEN" --lab-dir "$LAB" --source-sha "$SHA" --confirm-disposable
```

Require both readiness values `true` before migration. It uses real Laravel local CSRF/session behavior but only the private SQLite in this lab. Open **Mac Safari Private Window** to `http://127.0.0.1:8798/` (on that Mac only). If an unexpected outbound/API or paid feature occurs, stop. Don't install an iOS build or edit ATS to enable this local browser check.

## Manual acceptance checklist — record observed results only

1. Fresh synthetic learner: welcome and Plan creation, Study Workspace defaults to `今やること`.
2. **Action-first**: `今やること` exposes the recommended/continue practice or next Action **before** execution setup/review candidates, not after long provider/source cards.
3. **No noisy source cards on Work**: official IPA syllabus and Question Bank topic source count, review state and State Change grid are not duplicated. Compact links to `学習分析・出典` and `履歴・State変化` are visible/tappable.
4. **Preparation**: open `学習準備`; when applicable, show IPA official syllabus source version and reviewed-index status; preserve warning about incomplete mapping and **no official coverage percentage**.
5. **Analysis**: open `学習分析`; when test data provides an AP published Bank sample, show recorded/source-ID sample and clear label that Bank topic proportions are **not** official exam weighting. On empty disposable SQLite, **absence of published Question Bank rows is expected**, not an IA-2j failure.
6. **History**: open `履歴`; if state-change evidence exists, show before/after and its evidence; in a fresh lab with no such history, no State Change card should be fabricated.
7. **Continuity**: import only synthetic external-AI questions into one new Study Task; choose one answer, allow short autosave time, navigate away/reload and resume; check the draft answer is restored. Scored history still follows assessment/commit boundaries. **Do not count a missing scored Attempt after only one draft answer as lost data without checking the draft**.
8. **Guardrails**: no unexpected 403/419/500, no duplicate Task/Evidence, no changes to old lab; logout/login with test actors if used.
9. Stop Laravel with `Control+C` when finished. Leave the original 8797 lab/state alone; no directory deletion necessary.

**Evidence:** record screenshot without real content, exact SHA/port, platform `Mac Safari`, PASS/FAIL/BLOCKED per step. Do not claim `iPhone Safari`, `WKWebView` or **production rollout** from this test. Dev-main merge is not deployment. Owner-controlled production migration audit/Issue #488 remains NO-GO.

## Synthetic CI scope

The updated offline guardrail suite runs on **Python 3.9 (owner's Mac version) and 3.12**, builds temporary Git repos, and asserts:
- original pinned-lab compatibility;
- explicit second SHA selection, refusal to accept unknown/incorrect SHA, fresh state isolation;
- distinct port, session cookie and DB path;
- rejected inherited credentials and `.env` / stale source;
- no real Laravel/Render/Aiven operations.

A green CI is **not** an observed Mac Safari acceptance run.

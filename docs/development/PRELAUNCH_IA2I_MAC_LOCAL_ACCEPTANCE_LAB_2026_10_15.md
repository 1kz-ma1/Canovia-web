# Pre-Launch IA-2i — Macローカル隔離Web受入ラボ

> 2026-10-15 / Experience lane / **OWNER-OPERATED / LOCAL SAFETY PREPARATION ONLY**
>
> Fixed source: `review/ia2h-device-readiness-20261015-069e9b57` → `069e9b5790345feb9bb9edd437ac1c5a39a73efd`.
> This is a **review-only** snapshot, not a deployable production release.
> [IA-2h frozen device handoff](PRELAUNCH_IA2H_DEVICE_ENVIRONMENT_HANDOFF_2026_10_15.md) / [IA-2g phone acceptance](PRELAUNCH_IA2G_DEVICE_ACCEPTANCE_2026_10_15.md).
>
> Physical iPhone Safari / WKWebView / Mac Safari acceptance is currently **NOT RUN**. No Mac or iPhone was remotely operated by the assistant.

## Why this exists

The owner has a Mac with an Xcode project locally, but the connected `Canovia-iOS` GitHub remote is empty. The currently available Render services do **not** include a verified isolated Laravel staging instance; deploying the production `Canovia` service would run `migrate --force` against the real production DB. The existing `canovia-mcp-staging` is a different Docker workload.

A **local, disposable, owner-only Web lab** is therefore the preferred next step. It must never use `main` as a moving runtime, existing `.env`, Aiven/Render DB, real user accounts, or the production iPhone Web URL.

## What the guarded tool does

`scripts/ci/prelaunch_ia2i_local_lab.py` is pure Python stdlib, supported on Mac. It requires **two distinct directories**: a clean detached checkout of the exact 40-character source SHA, and a separate owner-private lab folder.

- `init`: check Git SHA, clean tracked files, absence of `.env` and `.env.*` other than `.env.example`, no cached Laravel config, no Vite hot marker, and no symlink paths. Create **only** a new 0600 SQLite file and a 0600 random local `APP_KEY` state file in a 0700 lab directory. Refuse overwriting an existing lab. It does **not** run composer, npm, PHP migrations or start a server.
- `check`: repeat guards and report only booleans for Composer/Vite readiness, source SHA and `NOT_RUN` for devices. **Never print APP_KEY** or app credentials.
- `migrate --confirm-disposable`: run `php artisan migrate --force` in the exact frozen checkout, with a child environment **constructed from an allowlist**, using only the private SQLite absolute path. Does not inherit the shell's DB/Aiven/Render/GitHub/AWS/AI variables. Fails closed if a local `.env`, cached Laravel config or wrong commit appears.
- `serve --confirm-disposable`: same guards, bind Laravel only to `http://127.0.0.1:8797`, verify compiled Vite manifest and Composer autoload exist, and use a distinct session cookie. Enables **Mac-local Safari testing only**. A loopback listener is not an OS outbound firewall; **other routes may still make outbound requests if manually invoked**. Do not enable external features.
- Default local PHP `APP_ENV=local` rather than `testing`, to exercise browser CSRF/session middleware. Local `APP_DEBUG=false`; public Release Level L1; native AI/MCP/GitHub App/AWS/notifications/billing integrations not configured. This environment is for synthetic accounts/Plans only.

The tool cannot prove a physical phone's use, does not configure HTTPS/tunnel, does not create Render/Aiven resources, and does not audit the user's macOS environment or Xcode settings until the user runs it.

## Mac operator instructions (after owner checks the actual filesystem)

These commands are for the **operator's own Mac Terminal**, not commands the assistant has run. Use the actual location of the already cloned `Canovia-web` repository. Avoid putting production secrets or an existing `.env` in the detached worktree.

```bash
cd /path/to/Canovia-web
git fetch origin review/ia2h-device-readiness-20261015-069e9b57
git worktree add --detach ../Canovia-IA2h-069e9b57 069e9b5790345feb9bb9edd437ac1c5a39a73efd
git -C ../Canovia-IA2h-069e9b57 rev-parse HEAD
```

Stop unless the printed commit is exactly `069e9b5790345feb9bb9edd437ac1c5a39a73efd`. The guard script is added in a **later** development-main commit; therefore run the Python file from your normal development checkout, and explicitly pass the **separate frozen worktree** in `--checkout`.

```bash
# From your normal Canovia-web development checkout:
FROZEN="$(cd ../Canovia-IA2h-069e9b57 && pwd -P)"
LAB="$HOME/Library/Application Support/Canovia-IA2i/069e9b57"
python3 scripts/ci/prelaunch_ia2i_local_lab.py init --checkout "$FROZEN" --lab-dir "$LAB"
python3 scripts/ci/prelaunch_ia2i_local_lab.py check --checkout "$FROZEN" --lab-dir "$LAB"
```

Both commands must report a pinned source and private disposable SQLite. `init` is deliberately not idempotent: a second invocation refuses to overwrite the existing lab. If you wish to reset test data, remove **only this explicitly chosen lab folder after confirming its path**; never delete any app DB, `.env`, or git worktree via the helper.

In the frozen worktree, prepare packages and assets using locked dependency installs, after ensuring the checkout contains no `.env` and no real service credentials:

```bash
cd "$FROZEN"
composer install --no-interaction --prefer-dist --no-progress
npm ci --ignore-scripts
npm run build
cd /path/to/Canovia-web
python3 scripts/ci/prelaunch_ia2i_local_lab.py check --checkout "$FROZEN" --lab-dir "$LAB"
```

**Dependency installation executes package code/downloads and is a separate operator trust decision.** Verify the checkout/source and lockfiles first. The tool does not silently perform those installs. If Composer scripts or packages are untrusted, stop. The PHP app never needs a production `.env` for this lab.

Only after confirming `VENDOR_READY=true` and `ASSETS_READY=true`:

```bash
python3 scripts/ci/prelaunch_ia2i_local_lab.py migrate --checkout "$FROZEN" --lab-dir "$LAB" --confirm-disposable
python3 scripts/ci/prelaunch_ia2i_local_lab.py serve --checkout "$FROZEN" --lab-dir "$LAB" --confirm-disposable
```

On the **same Mac**, open Safari at `http://127.0.0.1:8797/` and use only synthetic test actors and Plans. This proves neither iPhone connectivity nor production correctness. Stop server with `Ctrl+C`. Do not run Vite dev server and do not reuse real account credentials. If the app requires any external paid/OAuth/AI integration or triggers an unexpected network request, stop rather than enabling it.

### Why iPhone connection is still blocked

An iPhone cannot reach the Mac's `127.0.0.1` (that address points to the iPhone itself). **Do not** change the server to `0.0.0.0`, open a router port, expose the laptop through an ad hoc tunnel, disable ATS/TLS, or point WKWebView at production to shortcut device checks.

A separately accepted secure iPhone-accessible origin and explicit network-access controls are needed before iPhone Safari tests. The native WKWebView's actual Xcode project, Signing and tested URL must be confirmed independently from the Mac. No shared deployment, tunneling or hosting service is provisioned by this PR.

## Evidence and release classification

- **Machine-verifiable after owner execution:** source SHA, isolation guard result, Composer/Vite readiness, local SQLite migration result, Mac-local HTTP and actual Safari user-flow observation.
- **Not proven by Python/CI:** physical Safari/WKWebView, camera/files, iOS keyboard/safe-area/back/session persistence, human understanding, iOS repository state on Mac, production Aiven migrations/recovery, paid provider access, and public availability.
- The [IA-2g manual PASS/FAIL table](PRELAUNCH_IA2G_DEVICE_ACCEPTANCE_2026_10_15.md) remains `NOT RUN`. `release/2026-10-15-6d7dbcf` is a separate NO-GO production-audit candidate; this IA-2h branch is only a device-review source snapshot.
- If a subsequent Experience fix is required, make a new PR targeting latest `main`, then create a **new** frozen review snapshot with new acceptance evidence. **Do not** move the existing frozen branch.

The offline CI `test_prelaunch_ia2i_local_lab.py` exercises guardrail refusals, secret isolation and command arguments using a disposable synthetic Git repository. It **does not migrate or run Laravel on the user's Mac**.

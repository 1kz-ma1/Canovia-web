# Pre-Launch IA-2h — 実機受入用の固定レビュー候補と環境準備

> Updated 2026-10-15 JST / **REVIEW ONLY — NOT DEPLOYABLE — DEVICE NOT RUN — PRODUCTION NO-GO**
>
> Source: `1kz-ma1/Canovia-web` development `main` at `069e9b5790345feb9bb9edd437ac1c5a39a73efd`.
> **Read-only device-review snapshot:** `review/ia2h-device-readiness-20261015-069e9b57` points to that exact code SHA. This is **not** a validated release image or a promotion to `production`.
> Historical **production** Web Live: `15abdc9de6f24b5250fb255c579d1aa55facaf1a`; Static Live: `ee533028c866e201d61c1430c15eaedb9c843524`. Auto Deploy is OFF for both services at inspection.

## 1. Decision: device acceptance is currently BLOCKED, not failed

The [IA-2g Linux WebKit mobile E2E](PRELAUNCH_IA2G_DEVICE_ACCEPTANCE_2026_10_15.md) proved the guest Browse → manually created Plan/Task → Guided Execution → author-scoped reflection Evidence → approved Next Action → logout/login loop at 390px/touch on its exact PR head `96f4a6514d129d0f0787fff62b363f1718436e38` (7/7 CI green), merged as [PR #734](https://github.com/1kz-ma1/Canovia-web/pull/734) / `42e35b95f6d9af69262677d023cbfa92673bb2fc`.

This **does not imply** that the newer frozen `069e9b57...` was run on a physical device or certified at this SHA. Other development lanes continue to evolve `main`.

Read-only connected environment inspection:
- There is **no confirmed isolated Render Laravel Web acceptance service** for IA-2g/IA-2h in `My Workspace`. `canovia-mcp-staging` is a separate MCP Docker service, not a drop-in version of the Web application. **Do not repurpose it** or attach it to production data.
- Web `Canovia` (`srv-dagfn2id0e5s73cac7ng`) points to production application/Aiven and `docker/render-start.sh` executes `php artisan migrate --force` at startup. **Never use a manual Web deployment as a device test**: the unapproved migrations might alter Live.
- Static `Canovia-Welcome` (`srv-dahn5k142hec739l0fmg`) is independent from Web and its current Live lacks the newly integrated intro page. Its Auto Deploy is OFF. Static-only marketing release is a **separate owner decision**, not proof that the linked Web functions have shipped.
- `1kz-ma1/Canovia-iOS` GitHub repo returned `Git Repository is empty` (commit lookup) and README lookup `404`. **The user's local Mac Xcode project may exist and work**, but iOS Swift source, Signing, associated domains and actual WKWebView URL are **not independently inspectable from this remote**. Do not infer a confirmed or signed build.

All physical iPhone Safari, iPhone WKWebView, Mac Safari and real tester-comprehension statuses remain `NOT RUN` or `BLOCKED`. No physical device / Screen Sharing / Xcode access is available in this connected tool workflow.

## 2. What must be fixed for a truthful device verdict

A usable acceptance run needs **one identified frozen source SHA**, **one isolated Web execution environment** containing the exact candidate, and **one identifiable real device/build**. Record these three identities separately.

| Gate | What is required | Current state |
| --- | --- | --- |
| Source | Fixed review snapshot SHA with exact source, test evidence and migration inventory; do not test moving `main` | **PINNED for review** at `069e9b57...`; **not release-certified** |
| Web target | Disposable Laravel instance at same candidate SHA; isolated database and storage; unshared secrets/sessions; actual URL and Release Level verified | **BLOCKED — no confirmed dedicated Web test target** |
| Safe data | Synthetic accounts/Plans only; no Aiven Live access, external AI, billing, GitHub write or production OAuth; migration-start target independently audited | **NOT VERIFIED** |
| Browser/phone | Real iPhone Safari and iOS version/device notes, usable secure origin and login/CSRF, testing consent | **NOT RUN** |
| Native WKWebView | Owner's Mac Xcode project/build SHA/Signing, actual Web URL and native/back/upload session behavior | **BLOCKED — remote iOS repo empty / local project not inspected** |
| Static intro/Future | Confirm static build SHA independently; route from intro to app uses intended Web candidate /feedback without public-release mislabel | **NOT RUN** |
| Release | Aiven effective DDL principal, real backup/recovery and 19+ migration review, operator GO via [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488) | **NO-GO**; see [release review](PRELAUNCH_FIXED_SCOPE_RELEASE_EVALUATION_2026_10_15.md) |

### Preferred next validation paths (neither is currently approved to start a deployment)

**A — Existing developer Mac + isolated local Web (prefer this before purchasing a service).** First check whether the Mac holds a runnable checkout of the frozen SHA and a separate disposable SQLite/MySQL database; verify no Aiven/Render credentials or production storage are loaded. Run Laravel with the intended L1/AI-OFF fixture and local Safari; only after an operator provides a secure, reachable origin can an iPhone on a controlled network exercise it. Do not expose a default insecure `php artisan serve` on the public Internet, bypass TLS/ATS, disable CSRF, publish a dev account, or copy production data. The iOS native shell must use this same verified test URL/config. A local Mac test alone **does not** prove Render production readiness.

**B — Separate isolated Web staging (requires explicit approval).** Create or designate a separate Laravel-capable service and disposable database, not MCP staging. The owner must approve resource creation/cost, app secret isolation, URL/access restrictions and any migration side effects before deployment. Never attach it to Aiven production, copy real data or enable automated app publication as a shortcut. This is **not** authorized by `進めて` in the Experience lane and is deliberately **not executed** here.

Both paths must check exact running SHA (not only branch name), human account identity, no leaking logs, and default-off external features. If URL, DB destination, build identity or permissions are unknown, record `BLOCKED` rather than pretending the test passed.

**Macローカル準備を実際に進める場合:** [IA-2i Mac隔離Webラボ手順](PRELAUNCH_IA2I_MAC_LOCAL_ACCEPTANCE_LAB_2026_10_15.md) に、固定SHAのGit worktree・別ディレクトリSQLite・`.env`排除・外部変数を継承しないLaravelコマンドのガードを実装した。これはオーナー自身のMacで起動するためのツールで、GitHub CIでは安全性の合成検証のみ。物理iPhoneのURL/TLS/実機操作は依然BLOCKED / NOT RUN。

## 3. Operator evidence template (no credentials, secrets or PII)

Make a private run record outside git for any real test. It can contain only:

| Field | Example / allowed format |
| --- | --- |
| source_sha | Exact 40-character Web Git SHA |
| web_environment | `isolated-local` or independently approved `isolated-staging`; **never production** |
| observed_web_build_sha | Exact **observed running** build/commit SHA or `UNKNOWN` |
| observed_static_build_sha | Separate SHA or `NOT_TESTED` |
| native_build_source | Local Xcode commit/version and bundle/build ID **without signing secrets**; `UNKNOWN` if not verifiable |
| client | iPhone model / iOS version / Safari or WKWebView, or Mac model / Safari version |
| test_account | Synthetic actor label only (no email, password, token, User ID, or Plan ID) |
| result | `PASS`, `FAIL`, `BLOCKED`, `NOT RUN` for each case |
| observations | Only a short anonymized reproduction, expected/actual and optional redacted screenshot pointer |

Do not commit network dumps, session cookies, local private CA, private project files, Aiven metadata dumps, screenshots with personal tasks, raw health payloads or credentials. A successful WebKit CI on Ubuntu is **not** a permissible `client=physical iPhone` entry.

## 4. Concrete acceptance sequence (execute only after the gates in §2)

Reference detailed actions and PASS/FAIL definitions in [IA-2g](PRELAUNCH_IA2G_DEVICE_ACCEPTANCE_2026_10_15.md).

1. **DEV-01 identity gate**: exact frozen Web SHA and observed running SHA match; DB is disposable; Release Level/entitlement verified. If mismatch, `BLOCKED` and stop.
2. **DEV-02 first use**: Guest Welcome 3 optional routes; Browse reaches an honest zero-Plan Overview without diagnosis or fake progress.
3. **DEV-03 initial action**: user chooses a Development Plan and creates first Task. The empty Plan's Task entry is visible and tappable at portrait width (regression for #734).
4. **DEV-04 Evidence**: start Task, save a real reflection and reopen it in the Dashboard; one author-only Evidence entry separate from timed WorkLog; progress stays unchanged.
5. **DEV-05 continuation**: approve Next Action explicitly, start follow-up, logout/login, and resume the same Plan/Task without duplicate Evidence.
6. **DEV-06 touch + platform**: keyboard open/close, safe areas, portrait/landscape, back navigation, long text, reload, offline/recovery. Fail if stuck/403/500/overflow/disabled tap.
7. **DEV-07 native-specific**: independently run WKWebView with the correct test origin; native Back, external link, photos/files, app restart and login persistence.
8. **DEV-08 comprehension**: without instructions ask first-time tester to find (a) what they did, (b) what is saved, (c) what to do next; record confusion even if technical checks pass.
9. **DEV-09 static & Future**: when separately authorized, verify independently deployed static SHA, direct `/intro.html`, cold-start retry, and truthful public Future/Support destination. Marketing deployment is optional for Web device-only test and is not implied by its success.

No script, user assertion or CI may change a `NOT RUN` device check to `PASS` without actual device evidence. No operator check here authorizes release promotion or Aiven DDL.

## 5. Exit and next action

- **Current IA-2h** = `SOURCE_PINNED_FOR_REVIEW`; `ENVIRONMENT_BLOCKED`; `DEVICE_NOT_RUN`; `PRODUCTION_NO_GO`.
- Before local/device execution, operator checks that the Mac has the actual Xcode project and a safe isolated Laravel checkout/storage; if unavailable, a separate staging service requires an explicit infrastructure decision.
- Capture actual test observations against `069e9b57...` or freeze a **new** candidate and repeat eligibility checks if source must change. Do not retroactively attach #734's CI to a later SHA.
- If UX bugs emerge, fix through a new Experience PR targeting latest development `main`, then freeze a new review candidate. Keep `review/ia2h-device-readiness-20261015-069e9b57` immutable as a historical snapshot.
- Production migration audit and controlled Go/No-Go remain wholly separate under [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488).

**No Render deploy, Aiven operation, iOS build modification, paid infrastructure provisioning or production release was performed.**

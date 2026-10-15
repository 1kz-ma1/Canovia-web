# Pre-Launch — 最小公開範囲と固定SHAのリリース審査

> Created 2026-10-15 · Owner-aligned implementation scope / **REVIEW ONLY — NOT DEPLOYABLE / NO-GO**
>
> Source: `1kz-ma1/Canovia-web` main `6d7dbcfedd35f33ba2827ed8c7c3d98ca30fc3d6`.
> Immutable review reference: `release/2026-10-15-6d7dbcf` → `6d7dbcfedd35f33ba2827ed8c7c3d98ca30fc3d6` (GitHub compare `identical`).
> Live Web / GitHub `production`: `15abdc9de6f24b5250fb255c579d1aa55facaf1a`.
> Live Static Welcome: `ee533028c866e201d61c1430c15eaedb9c843524`.
> Canonical release owner gate: [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488). Previous limited-scope production candidate: [Draft #513](https://github.com/1kz-ma1/Canovia-web/pull/513) at `bb8248f25545ae0d600fbb68f66c41c360004e2c`. Neither this document nor the new branch promotes/replaces that PR.

## 1. Product-facing MVP boundary — four connected moments

The **public experience scope**, not the entire codebase, is frozen for review. The candidate repository includes other implementations because it points to existing main; none are automatically enabled, available, release-verified or approved by inclusion.

| Moment | Essential value | Existing evidence in development main | Remaining release acceptance |
| --- | --- | --- | --- |
| 1. Canoviaを知る / 参加する | Static `intro.html`, transparent maturity labels, cold-start Welcome, curated Canovia Future `GET /feedback`, existing free Support/feedback | #717 static Node+offline Chromium CI 6/6, #709 manual public roadmap | Static real URL and device check; Future runs on **Web** and needs separate deploy. No paid Support or release claim from CI |
| 2. Planなしで試す | Welcomeの「診断 / 相談 / まず見てみる」の任意分岐、ゼロPlan Overview、希望者だけPlan作成、Guest→登録引き継ぎ | IA-2a–d incl. disposable real Chromium + Laravel first-run E2E | Actual released Level/actor, desktop + Safari/WKWebView, returning sessions, no unexpected Account/Plan mutation |
| 3. Learning — 最初の一問 | Study Plan→Task/Action→既存**審査済み**Bank問題→回答・採点・履歴→次のAction/再開 | Study Practice and V6 work, isolated first-use and practice CI; public AP provenance/coverage under separate checks | Published/licensed question provenance, answer-save and retry semantics, candidate review gate, no unapproved IPA/AP content, no unsupported official mock exam or automatic mastery claim |
| 4. Development — 作業を次の一歩に | Development Plan→Action開始→作業/振り返りEvidence保存→本人が反映承認→次Action開始→再ログイン復帰 | #V6.9 loopback Chromium journey and development GitHub-native integration | Actual authorized actor/Plan, existing repo only by consent, false progress prevention, browser/device resume, no autonomous code write/merge |

Minimum initial audience: existing server-side `ReleaseLevel` L0/L1 or a distinctly **authorized selected Early Access actor**. No arbitrary anonymous API access, no implicit L2/L3/L4 promotion. Marketing pages may be public separately, but user Plan/Task/actions are never public by association. Scope is **not proof that all four journeys are live**.

### Explicitly out of wave 1
- Premium/Pro paid operation, purchases/StoreKit/Stripe, Coins/Gifts, paid Support, pay-to-prioritize.
- Career expansion/beta and World/Social as generally available.
- MCP external ChatGPT/AI Plan access, account-link or delegated write; no live credential or per-Plan grant creation by this candidate.
- G3 external AWS/S3/private-file provider activation or deletion, automatic GitHub-to-Task progress or Dev Pro autonomous code/PR merge.
- Unreviewed IPA AP question packs, unofficial exam-equivalence promises, automatic content publication or premium native AI generation with unverified quota/cost.
- Public roadmap `released`/`rolling_out` statuses absent explicit post-deploy, eligible-actor and real-device owner acceptance. Until then show development/verification/planned truthfully.

## 2. Two publication tracks (not one combined GO)

**A. Static Canovia-Welcome**: Render `srv-dahn5k142hec739l0fmg`, `branch=main`, **Auto Deploy OFF / trigger OFF confirmed**. Its actual live SHA remains `ee533028c...`; new `intro.html` exists on main, **not** live. After a *separate* owner decision, manual static deploy and real site/mobile/safe navigation/slow-offline-retry smoke are required. The static `future`/Support links route into the **Web** service, so old Web may not yet expose the latest manually curated roadmap. Do not present the two deployments as atomic or new capabilities as already live.

**B. Web/iOS WKWebView**: Render `srv-dagfn2id0e5s73cac7ng`, **Auto Deploy OFF**, live `15abdc9...`, branch `main` still configured but production release contract requires watched `production` with explicit owner decision. Any manual deploy of this Laravel image executes `php artisan migrate --force` during `docker/render-start.sh` startup. Web deploy affects iOS immediately. No release/DDL/auto-deploy change is authorized in this preparation.

## 3. Full repository delta is **not** equivalent to MVP scope

Read-only full recursive Git tree diff (`15abdc9...` → pinned `6d7dbcfedd35f33ba2827ed8c7c3d98ca30fc3d6`), **not** the GitHub compare API 300-file display cap:

- **234 commits ahead**, 757 changed tracked blob paths: **554 added**, **203 modified**, **0 deleted**.
- Code/runtime/config impact: `app/` 196 changed files, `resources/` 43, `routes/` 2, `bootstrap/` 1, `composer.json` + `composer.lock` modified, `docker/` 4, `config/` 6. GitHub PR/Actions/CIs, tests and docs comprise much of the rest; **all path changes still need full release review**.
- `database/migrations/`: **19 new PHP migration files**, not merely the 2 source observation migrations included in old #513. Source count 94 → candidate files 113 is a tracked-file difference, **not a claim about Aiven applied migration ledger**.
- The old #513 covers **2** migration files and its old SHA has **188 subsequent commits** to this pinned review SHA. Never reuse its CI or owner-local Aiven result to authorize this candidate.

### 19-migration inventory — gate classification
| Group | Count | Specific risk to rehearse on disposable MySQL |
| --- | ---: | --- |
| Development Observation source revision + epoch | 2 | additive observation columns/index; preserve existing rows and ordering, exact nullable metadata |
| Collaborative Plan authorization epoch & immutable member binding | 3 | default epochs + **DB triggers** on Plan/member UPDATE, cross-driver semantics, old member rows, authorization invalidation |
| Study recall candidate review provenance | 1 | nullable JSON column and old-record compatibility |
| MCP indefinite grant + historical subject-link reuse | 2 | `expires_at` **nullable change**; existing fingerprint backfill; **replace unique index** preserving active uniqueness and historical grants; MySQL non-transactional DDL/partial retry |
| Private-file cleanup/path/upload/generation/write ledger | 5 | new tables + foreign/restrict semantics, no external S3/AWS calls, safe crash/replay |
| Development operational profiles/targets/service check/storage revision | 4 | new Plan-bound tables and subsequent ALTERs with correct migration ordering |
| MCP Study result proposals | 1 | new table with actor/Plan/Task/grant FKs and idempotency; no external MCP activation |
| Learning Context Watch persistence | 1 | two guarded tables, uniqueness, replay/partial table creation; not a live watch/notification authorization |
| **Total** | **19** | Any failed/unknown/partially applied migration is **NO-GO**, no blind `migrate:fresh`, rollback or production test |

This is a source-content **first-pass risk inventory**, **not** a complete compatibility/security review, nor a disposable-MySQL certification of all 19.

## 4. Acceptance sequence, always tied to one SHA

- [x] Freeze **review-only** branch `release/2026-10-15-6d7dbcf` at the tested source snapshot SHA `6d7dbcfedd35f33ba2827ed8c7c3d98ca30fc3d6`. No promotion PR or Render deployment.
- [x] Establish full Git tracked blob diff counts from both untruncated recursive trees and list all 19 migration names. Treat candidate as **NO-GO**, not a green release.
- [ ] Review **all 757 changed paths** and external effect boundaries for this exact SHA: `bootstrap/app.php`, authentication, DB-trigger SQL, historical MCP unique DDL/DML, `composer.*`, `docker/render-start.sh`, public L1, storage, external AI, iOS WebView behavior, the exact flags/off-by-default requirements.
- [ ] Disposable **MySQL 8.0 and 8.4** clean schema + historical/legacy representative fixtures for all 19 DDLs, repeat-start idempotency, partial MySQL failure/repair, actor/member/revocation/record preservation, index compatibility. Run all relevant current-schema CI and required browser/Dev/Study tests **at the pinned SHA**, not older #513 and not moving main. Record pass/fail per test.
- [ ] Determine **source-checkable SELECT-only operator audit** for 113 tracked migration files / 19 candidate additions. The **old** #513 selector that accepts exactly 2 pending source-observation files must not be used to label this candidate READY. Verify privately: intended Aiven service + `pacekeeper` identity, restricted grants, TLS CA, migration ledger and physical schema, expected vs actual pending/partial/unexpected. Never expose secrets, identities or raw ledger.
- [ ] Establish fresh restorable Aiven backup, practical restore/forward-repair route, RTO, consistency and treatment of MySQL DDL and writes. Aiven Free backup screenshot alone does not prove restore. No new paid fork or external infrastructure without separate owner permission.
- [ ] Effective Render production flags: public Release Level L0/L1, Auth0/MCP private delegated disabled, Native AI/entitlement/paid behavior off unless approved, DB/session/worker/webhook safe. Check values **without disclosure**. No assumptions from code defaults.
- [ ] Real user acceptance: Static URL, public Future free Support, guest→registered, Plan-free/diagnosis/converse, Study single-choice graded saves and resume, Development Evidence review and next Action, older account returning, actor isolation; iPhone WKWebView, Safari/PWA, Mac desktop and failure recovery.
- [ ] Product owner chooses and signs off on the **exact** release SHA and flags, GitHub `production` protected **merge commit** (not squash; required CI), separately controlled Render watched branch and **manual** first deploy (Auto Deploy OFF). Read live SHA, migration logs/schema, 5xx/SQL, mobile smoke. Only afterwards separately consider auto-deploy policy.

**Do not run the 2-migration production classifier as if it certifies 19. Do not write Aiven, deploy Render, change static settings, publish question packs, grant MCP access or merge production while any checkbox is unverified.**

## 5. Decision record / next safe technical slice

The review-only SHA is intentionally **full main**, not a constructed cherry-pick of 4 UI features. Cherry-picking cross-domain Laravel changes without verified dependency closures, migrations, rights, auth and test matrix could be less safe than the frozen whole-source snapshot. This branch serves to measure the delta and decide whether to (a) validate a narrower **static-only public introduction** separately, then Web/L1 later, or (b) prepare a new **minimal Web release branch** whose exact dependency closure and missing migrations are proven before exposure. Both options require explicit later owner GO to deploy; this document does not select or authorize a production promotion.

Track release readiness only in [Issue #488](https://github.com/1kz-ma1/Canovia-web/issues/488), while preserving the historical [Draft #513](https://github.com/1kz-ma1/Canovia-web/pull/513) as its prior pinned 2-migration experiment. Do not silently retarget #513 to this branch.
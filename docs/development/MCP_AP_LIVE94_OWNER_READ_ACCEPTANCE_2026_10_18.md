# AP owner-first MCP — Live94 selective-backport acceptance addendum

Date: 2026-10-18 JST. Status: **CURRENT CANDIDATE / SYNTHETIC-VERIFIED / PRODUCTION HOLD**.

This is a narrow continuation of [AP owner-first lean release scoping (PR #784)](https://github.com/1kz-ma1/Canovia-web/pull/784), the [real-account security gates](https://github.com/1kz-ma1/Canovia-web/blob/main/docs/development/MCP_REAL_ACCOUNT_CONNECTION_RELEASE_GATES_2026_10_14.md), and [PR #785](https://github.com/1kz-ma1/Canovia-web/pull/785). It is not a second release authorization.

## 1. Current scope and acceptance

- Goal: make the existing owner's ordinary Canovia AP Study Plan readable from ChatGPT **before** general public launch. Initial scope is one owner, one **non-public / non-collaborative** Study Plan, one-day `overview` consent.
- Observable MCP content: owner-recorded `title` (bounded) and `recorded_deadline`. No Task detail, practice/evaluation history, learning progress, Learning Context Snapshot, evidence, answers, write access, or account discovery.
- Owner must separately establish a GitHub-only PKCE-linked identity, explicitly confirm one Plan consent, then independently connect ChatGPT. The same immutable verified Auth0 issuer and GitHub subject must be used by both clients; email and login-page success are not authorization.
- In the Account UI, the owner can see the exact Plan ID to supply to ChatGPT. The MCP `initialize` instructions are Study-only when AP pilot is enabled; the legacy Development instructions remain unchanged otherwise.
- Private Plan policy checks `is_public=false` and `is_collaborative=false` both at consent and at every authorized read. Visibility, owner, category, linked identity, client, scope, grant and expiry changes are re-evaluated at call time.
- All MCP/JWT/AP/GitHub/consent switches are OFF by default. The legacy 94 migration source files are unchanged, with no new DB table or column introduced.

## 2. Evidence boundaries (not production acceptance)

- Source baseline: old Live `15abdc9de6f24b5250fb255c579d1aa55facaf1a`. Branch `feature/ap-mcp-live94-auth0-20261018` remains a Draft PR targeting `production` for **review only**, not a valid production release PR.
- CI: synthetic RS256/JWKS, Link PKCE/CSRF, consent, read, revoke/denial and old Development regression on SQLite. Disposable MySQL 8.0/8.4 workflows apply 94 migrations, repeat migrations, and exercise the Study read/link/consent/revoke tests.
- This candidate has **no** actual Auth0 OAuth token, private Canovia Plan read, Web/iPhone acceptance, real Aiven schema or restore, Render deploy, or ChatGPT real-owner proof.
- Every new commit invalidates previous CI as evidence for the new HEAD until the new exact SHA passes.

## 3. Explicitly unresolved release-policy mismatch (STOP)

The repository's active `AGENTS.md` and `docs/CANOVIA_DEVELOPMENT_WORKFLOW.md` require:
`latest main -> release/* (fixed main SHA) -> production PR -> merge commit`.
`production-promotion-policy.yml` requires both `release/*` naming **and** that exact source HEAD to be an ancestor of current `main`.

**PR #785 does not meet this contract:** it starts from old production and selectively backports 24+ files. A green narrow CI **cannot** be promoted through the existing production policy. Retargeting this branch to `main` or force-merging is unsafe because `main` already has newer shared auth/MCP code and additional migrations. Do not modify the promotion workflow silently or bypass it to meet a date.

Decision required before shipping:
- **Path A (narrow Live94 candidate):** owner approves a separately reviewed, bounded exception *proposal* to release promotion rules. Its rule change, exact candidate SHA, complete production diff, branch protection, release safety checks and rollback contract must be verified before any production merge. No exception has been approved or implemented.
- **Path B (normal main release):** use ordinary `main -> release/* -> production` with all dependency/migration/restore review, while limiting actual MCP access through server flags and owner allowlist. This may require more operator work but preserves current policy.
- Do not invent an alternative `release/*` SHA that is ancestor of `main`: it would **not** contain this stand-alone selective backport by default.

## 4. Owner/operator acceptance order

1. Pin exact candidate and verify all CI jobs on that SHA, both MySQL series, negative visibility and consent guards; scrutinize entire diff and the old Web startup contract.
2. Choose Path A or B under the separate release decision. Verify real Aiven migration ledger/schema (operator-local least-privilege metadata only) and a feasible independent recovery path. Full Aiven backup existence alone is not a tested restore.
3. Confirm production Auth0 issuer/JWKS/PKCE/RFC9207 GitHub-only login, first-party vs ChatGPT client IDs (including internal CIMD mapping), resource/audience/scope/redirect and actual signed JWT claims. Use non-sensitive pass/fail evidence only; no secrets or raw user IDs in GitHub/CI.
4. Require explicit operator GO for Render branch/env/deploy, and independent GO for enabling OAuth/MCP flags. No implicit migration on startup. Confirm existing account login/session/CSRF and physical Web/iPhone smoke with owner-only controls OFF before allowing a real grant.
5. With fresh individual consent, verify missing-grant DENY -> exact owner Plan overview READ -> revoke/unlink DENY using **the same still-valid token**, then emergency OFF. Check no other owner/Plan or Task/history became accessible.
6. Only after this first acceptance, separately design/authorize `Learning Context Snapshot`/Task/learning-history reads for effective AP tutoring. Current overview by itself does not enable personalized learning diagnosis.

## 5. Current decision

**Keep Draft/HOLD.** No production branch updates, Render or Auth0 settings, Aiven operations, real user-plan reads, billing or public roadmap release labels are authorized by this document. Existing plans and data remain unchanged.


## 6. Latest synthetic OAuth negative-path acceptance (2026-10-18)

The Live94 branch now includes additional Laravel HTTP/DB tests. These are **synthetic**—they neither contact the actual production Auth0 tenant nor prove a real account is connected.

1. **Wrong callback issuer**: The callback consumes its one-time OAuth state, rejects a mismatched RFC 9207 `iss`, does not exchange a code for a local link, and rejects subsequent replay of the same state.
2. **Wrong Canovia owner**: An ordinary account outside the server-side allowlist cannot initiate the owner-only AP account-link flow.
3. **Plan changed after callback**: If a previously private Study Plan becomes collaborative after external re-verification but before the final owner confirmation, no grant is created; retrying that one-time confirmation after restoring private status still cannot create a grant.
4. **Subject unlink**: An existing, otherwise valid ChatGPT bearer that read the approved owner Plan is denied once the local external subject has been revoked and all its grants revoked. No token renewal or expiry is needed.
5. Original synthetic positive acceptance remains: explicit GitHub PKCE link confirmation -> fresh per-Plan PKCE consent confirmation -> owner-only overview read -> grant revoke -> same-token denial. Both disposable MySQL 8.0/8.4 and SQLite are covered by the existing workflows.

## 7. Real Auth0 / ChatGPT operator acceptance packet (not yet executable in production)

**Before starting:** exact immutable Web release SHA, 94-migration real Aiven ledger/physical-schema operator check, restore method, GitHub ruleset bootstrap, effective Render settings, secure real Auth0 tenant/client setup and separate deployment/enablement owner GO must all be accepted. Do not use the old isolated staging database for private user data.

| Test order | Required observation (no raw IDs/tokens in PR or chat) | Must stop if |
| --- | --- | --- |
| OFF smoke | Ordinary Canovia sign-in, existing Plan/Task, session, CSRF, Mac Safari and iPhone WebView operate normally; external MCP discovery and reads are unavailable. | Site/session/UI regression or unexpected MCP exposure |
| Auth0 issuer/client probe | Real Auth0 issuer, resource, scoped audience, GitHub-only social identity, distinct confidential Link client and issued ChatGPT CIMD internal client identity were independently verified. Same immutable `iss+sub` across clients. | Only email matched; third-party client claim unknown or scope/audience wrong |
| No-grant deny | Valid third-party bearer, exact owner Plan ID, no active Plan approval: MCP returns denied. Non-owner, Development Plan, collaborative/public Plan and Task scope all denied. | Any private payload visible before grant |
| Owner confirmation | Same logged-in Canovia actor explicitly links external ID, chooses one private Study Plan, passes fresh S256/issuer proof and final CSRF confirmation; only one-day `overview` grant exists. | Silent approval, stale callback, missing fingerprint match or grant on different Plan |
| One bounded read | Actual ChatGPT session reads only the owner-entered exact Plan ID, displaying title and recorded deadline but no Task, score, practice, evidence, profile or credential data. | Wrong owner/Plan, unexpected fields or cannot prove client binding |
| Same-token revocation | Revoke grant, then try same still-valid bearer: denied. Separately unlink the subject: all related grants revoked, same bearer denied. | Revocation requires waiting for token expiry, grants silently revive |
| Emergency OFF | Disable through separately approved operator control, verify discovery/tool are denied and existing user data retained. | Read still succeeds or normal Canovia use breaks |

**Evidence format:** record only the tested release SHA, test category, `PASS / FAIL / NOT_RUN`, coarse timestamp and sanitized response code / predeclared event identifier. The operator must never paste OAuth code, callback URL query, `state`, access token, cookies, GitHub subject, account IDs, Plan titles or private Aiven records into ChatGPT/GitHub/CI artifacts.

**Current outcome:** all live acceptance items remain `NOT_RUN`; Draft #785 and production remain on HOLD. Synthetic CI is not release approval.


## 8. AP owner Auth0/operator secretless pre-deploy shape check (2026-10-18)

**Implemented, but NOT proof of production readiness or deployment authorization.**
The old-94 candidate includes `scripts/ops/mcp_ap_live94_auth0_preflight.py` and
synthetic regression tests `tests/ci/test_mcp_ap_live94_auth0_preflight.py`.

A private operator may run:

```bash
python3 scripts/ops/mcp_ap_live94_auth0_preflight.py
```

Run only with the *already approved, effective exported production environment*
in a private operator process; the script does **not** load `.env`, query Laravel,
contact Auth0, read Aiven, open network connections or print any configured
client/issuer/owner ID/fingerprint/secret values. Do not paste secrets or the
full environment into a PR, CI artifact or ChatGPT.

- Requires `APP_ENV=production`, `APP_DEBUG=false`, `DB_CONNECTION=mysql`,
  HTTPS canonical `APP_URL`, matching exact MCP resource + first-party
  PKCE OAuth metadata/authorization/token/callback URLs.
- Requires exact production-style Auth0 issuer, distinct `tpc_...` internally
  registered ChatGPT CIMD client vs first-party account-link client, nonempty
  owner allowlist, separate link secret and identity HMAC key, and explicit
  `auth0_jwt` verifier selection.
- **All MCP/Study/link/consent/JWT flags must be OFF for first-code-deploy phase.**
  A preflight run with any enabled flag is intentionally `BLOCK`. This
  command does not attempt to authorize later owner-only activation.
- Output is a small fixed-code JSON verdict `BLOCK` (exit 2) or
  `REVIEW_REQUIRED` (exit 0), never `GO`. All return cases include
  `production_release_authorized=false`, `auth0_real_token_verified=false`,
  `real_aiven_ledger_verified=false`, and
  `restorable_backup_verified=false`.
- This is a *shape* check only; empty, unset or differing actual operator
  config must be fixed privately through separately authorized procedures.
  A successful synthetic CI run is **not** proof the operator executed it
  against production.

**Current result:** synthetic contract test only. Real production env check,
actual Auth0 access token, 94-migration Aiven ledger, independent restore,
GitHub Ruleset bootstrap, owner GO, deploy and Plan read remain NOT_RUN.

## 9. 実Aiven台帳を旧94件・未適用0件で判定する手順（準備済み／NOT_RUN）

`scripts/ci/mcp_ap_live94_ledger_zero_pending.py` は**旧本番94個のmigration filenameを正本**とする、無通信の厳格な照合器。既存の `scripts/ci/p0_mysql_full_ledger_offline_check.py` の入力検証を再利用する。既知の未適用migrationが1件でもある、未知の適用記録、重複、破損、候補のmigration件数が94ではない場合は `BLOCK`（exit 2）。一致する94件全件適用済みの場合だけ `REVIEW_REQUIRED`（exit 0）。この結果はDBの物理スキーマ・接続先の本人確認・復旧の実証・公開承認を意味しない。

**前提：オーナーから実DBメタデータのread-only監査を別途明示承認されるまで絶対に接続しない。** 実行する場合は、Macなどのoperator-local、固定された旧94件ブランチのソースを使い、既存の `scripts/ops/p0_mysql_operator_select_only_collect.sh` の制限付きTLS・login-path・grant検査を先に満たすこと。CI、Render shell、アプリ用の広いDB権限、プライベートPlanの行取得、ログアップロードは禁止。

制限付きoperator接続先をAivenコンソールで独立検証し、既存のP0 grant検査が正常完了した**あとだけ**、同一login-path/TLS/DBを使用して台帳SELECTを入力できる。以下は変数名を示す**手順例**であり、値はオペレーターのローカル環境だけに保持する。

```bash
set -o pipefail
mysql --no-defaults \
  "--login-path=${CANOVIA_P0_LOGIN_PATH}" \
  --ssl-mode=VERIFY_IDENTITY \
  "--ssl-ca=${CANOVIA_P0_CA_FILE}" \
  "--database=${CANOVIA_P0_DATABASE}" \
  --batch --raw --skip-column-names --skip-auto-rehash \
  < scripts/sql/p0_mysql_readonly_full_migration_ledger.sql \
  | python3 scripts/ci/mcp_ap_live94_ledger_zero_pending.py --input -
```

生のTSV・接続先名・認証情報・生のPlan IDはGitHub/ChatGPTに貼らない。出力は固定コードと真偽値のみ。`REVIEW_REQUIRED` は**次の人間による確認が必要**という意味で、デプロイOKではない。

同時に `scripts/ops/mcp_ap_live94_auth0_preflight.py` は `DB_CONNECTION=mysql` のみでなく、`DB_URL` または `DATABASE_URL` に値があれば、Laravelの接続先優先順位をこのスクリプトだけでは検証できないため `BLOCK` とする。実運用でURL型接続設定を使っている場合、値を削除して迂回せず、別途オーナー承認済みの実効DBターゲット監査手順により確認すること。

**現状：旧94件の合成テストのみ実施。実Aiven ledgerチェック、復元検証、Auth0実トークン確認、Ruleset準拠のproduction昇格はNOT_RUN。**

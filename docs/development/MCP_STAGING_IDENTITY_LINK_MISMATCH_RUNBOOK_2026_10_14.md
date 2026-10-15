# MCP staging — identity-link mismatch triage and acceptance runbook

Date: 2026-10-14
Status: **2026-10-14 historical incident triage, with 2026-10-15 checkpoint**; no runtime/DB/IdP/config mutation is authorized by this document.
Environment: isolated Render `canovia-mcp-staging` in confirmed `My Workspace`. Never use production actor/Plan/Aiven.

## Observed fact vs hypothesis

- Auth0 reported successful login at `2026-10-14T01:03:59Z` (10:03:59 JST), using the first-party confidential **Canovia MCP Staging Link** client. A successful login event alone does not prove grant completion.
- Render stage logs around 10:03:36, 10:03:45 and 10:04:00 JST reported the *fixed* `stage_code=link_callback_plan_link_mismatch` after the OAuth callback.
- Exact observed staging runtime commit: `366fc03126183ebd70e850e4746b93371d903bca`, deployed 10:01 JST. **Do not substitute moving `main`**, which may have diverged.
- At that pinned commit the callback reaches `McpExplicitPlanConsentService::matchesLinkedIdentity($user, $fingerprint)`; its query expects a `chatgpt` link for the same local Canovia user, the same HMAC fingerprint, `linked` status, no revocation, and a valid `linked_at`.
- Reaching this exact stage means the previous callback checks for session/state/issuer, token, synthetic Plan guard, consent scope and owner did not reject this particular attempt. It **does not prove** that the saved linked subject and the freshly verified subject match.

**Potential causes to distinguish, not to assume:**

1. Previous account-linking was confirmed with a different Auth0 `(issuer,sub)` from this Plan-consent attempt (same email display name does not establish same `sub`).
2. An older link is revoked or not active for the current synthetic user, or confirmation never finalized.
3. `CANOVIA_MCP_IDENTITY_FINGERPRINT_KEY` used to store the link and the currently configured key changed, producing different HMACs for the same external subject.
4. Historical link data or staging actor/DB was migrated/recreated inconsistently. No such event has been verified.

## Read-only investigation — safe order

1. Pin and record the **currently live staging SHA** and approximate JST timestamp before comparing logs. Find only fixed-category application logs; never copy `code`, `state`, token, raw JWT, session cookies, subject IDs or client secrets into GitHub/chat.
2. In the authenticated synthetic Canovia staging Account page, inspect whether the UI reports an *existing active external ID link*. If yes, a stored row probably exists, but its fingerprint equality is not established by UI state. Do not perform another Plan approval simply because the card is visible.
3. In the owner's private Auth0 Dashboard → **Monitoring → Logs**, inspect the first successful **Canovia MCP Staging Link** login corresponding to the originally confirmed account link, and compare it with the 2026-10-14 10:03:59 JST event. Filter by application and type using `client_name:"Canovia MCP Staging Link" AND type:"s"`, and optionally bound dates around the initial linking event. Compare the immutable `user_id` and issuer **locally**; do not use email equality as proof. Share only `same / different / unknown` with the development chat; do **not** post raw subjects, full logs or sensitive metadata. A login event is not itself confirmation that Canovia's link-confirm POST completed. See [Auth0 log search syntax](https://auth0.com/docs/deploy-monitor/logs/log-search-query-syntax).
4. Verify privately from audited Render configuration/change history whether the fingerprint key was **the same value** at original link creation and at the failed callback. Share only `unchanged / rotated / unknown`; do not print or copy any value. Treat a key mismatch as a security-sensitive migration issue, not a reason to weaken the validator.
5. If still unresolved, request a separately reviewed **staging-only, read-only, privacy-safe diagnostic** or operator-side DB audit (counts/status/event categories only, no subject/fingerprint values). The hosted Render connector cannot currently query this staging PostgreSQL because its external IP allowlist is intentionally empty. **Do not open DB networking to diagnose**.
6. If the original link is proven to be a different identity, use only an owner-authorized explicit unlink/relink procedure that verifies current ownership and maintains revoked grants; if the current UI lacks such a supported route, stop and design that recovery separately. Never overwrite `mcp_linked_subjects` directly or auto-link by email.

## Success/negative acceptance

- Correct Canovia synthetic actor completes confirmed external-ID link; same immutable IdP subject returns on a separate *fresh* PKCE Plan-consent callback.
- Explicit scope `overview` or `tasks`, exact single synthetic Development Plan and finite 1/7/30-day consent are shown; only final owner POST creates an active grant.
- Unconsented Plan is denied; authorized `tools/call` returns at most the approved synthetic metadata; revocation immediately denies later calls. Confirm true ChatGPT E2E separately from PHP tests/logins.
- Wrong Auth0 subject, revoked link, different actor, wrong client, expired PKCE/state or unapproved Plan must continue to fail closed.
- Keep staging and production separated; no Auth0 tenant-wide policy adjustment, secret exposure, HMAC reset, database network exception, or moving-main redeploy just to make a test pass.

## 2026-10-14 follow-up — reported positive consent (not full MCP E2E)

- The owner reported seeing the exact staging message `指定した開発Planの共有範囲と期限を承認しました。ChatGPTからの直接取得はまだ有効になっていません。` after authenticating with the previously linked account.
- On pinned Live SHA `366fc031`, that exact message is emitted by `McpOAuthPlanConsentController::confirm()` **only after** the `McpExplicitPlanConsentService::grant(...)` call returns true. This is strong source-level support for a successful per-Plan grant transaction; it is not an independently queried DB audit or proof of a ChatGPT read.
- Around `2026-10-14 02:02–02:03 UTC`, Render shows normal OAuth/account redirect requests with no `link_callback_plan_link_mismatch` failure in that checked interval. The anonymized request logger does not expose final grant status.
- The hypothesis is that an existing Google Auth0 browser session was selected when the originally linked external identity used GitHub. **This is not proved** until comparing the two historical IdP `iss+sub` identities privately. Do not treat it as a verified security finding or alter the subject-check code.
- Next separate acceptance: request the exact owner-provided synthetic Development Plan ID, invoke the already connected read-only tool with `overview` first (never infer or discover an ID), optionally `tasks` only if that exact scope was approved; then verify revocation denies a repeated read. Do not label complete until an actual ChatGPT read and subsequent revoke are observed.
- The fixed confirmation string says ChatGPT direct fetch is not yet enabled. This string is static controller text; do **not infer actual runtime tool enablement or disablement from wording alone**. Confirm with a real authenticated tool call.

## 2026-10-14 ChatGPT read rejection after owner approval — confirmed scope semantics

- Owner supplied exact synthetic Plan ID `1`. Two actual ChatGPT tool calls with `scope=overview` and `scope=tasks, limit=2` both returned `Plan unavailable or not authorized for the requested scope.` No private Plan/Task payload was returned.
- Source at pinned stage SHA `366fc031`: `McpExplicitPlanConsentService::grant()` stores one grant scope, either `overview` or `tasks`, overwriting the former scope on re-consent; `McpDelegatedPlanAccessPolicy::authorizedGrant()` **permits overview whenever an active grant exists**, and permits tasks only for `tasks` grant. Thus a user's `概要＋Task` selection (stored as `tasks`) **authorizes both reads**; trying alternative scopes does not fix this rejection.
- Render around `2026-10-14 02:06:48 UTC` and `02:09:58 UTC` logged `POST 200 auth_header_present=1`. Because the MCP handler returns per-Plan denial inside a successful JSON-RPC response, HTTP 200 does **not** imply an authorized tool read. The request log contains no Plan or subject.
- Leading, **unproved**, explanation: owner re-consented through the originally linked GitHub Auth0 identity, while the separately persisted **ChatGPT OAuth connection token** remains associated with a Google/Auth0 identity. A Canovia browser relogin does not update the token stored for ChatGPT. Never assert exact JWT `sub` without safe comparison.
- Preferred non-destructive next experiment: first inspect authenticated staging Account for **active grant and Plan ID 1**, then reauthorize **only the ChatGPT-side Canovia MCP Staging OAuth connection** using the same GitHub/Auth0 account used for the successful Canovia ID link + Plan consent; retain existing Plan grant, synthetic user, stage configuration and production boundaries. Repeat a single `overview` read. Do not disconnect/revoke the Canovia subject link or reset the fingerprint key to diagnose.
- If fresh same-subject ChatGPT authorization still fails, investigate token `issuer, sub`, client/resource fingerprint, owner/Plan binding and grant status through a separately reviewed stage-only read-only fixed-category diagnostic, without exposing raw subjects, hashes, tokens or secrets.

## Immediate operator decision tree

- `sub` differs: preserve refusal; decide whether original link was intended and recover through verified explicit relink (requires user agreement and supported route).
- `sub` matches, fingerprint key rotated: halt; evaluate deliberate key-rotation/migration or fresh reviewed identity re-link; do not silently replace key.
- `sub` matches, key unchanged: inspect only safe linked-row/audit status via approved read-only stage-local instrumentation.
- Any uncertain result: keep Plan grant inaccessible; do not mark acceptance complete.

## Relevant code

- `app/Http/Controllers/McpOAuthAccountLinkController.php`
- `app/Services/McpExplicitPlanConsentService.php`
- `app/Services/McpOAuthAccountLinkCommitter.php`
- `app/Services/McpDelegatedIdentityFingerprintService.php`
- `docs/development/MCP_EXPLICIT_PLAN_CONSENT_CONTRACT.md`

This runbook does not state that the error has been resolved or that the staged token/read E2E passed.

## 2026-10-15 現行環境との照合（歴史的Runbookとして維持）

- GitHubの旧Draft #568は2026-10-14の症状・当時の固定SHAを記録した資料であり、**現行の未解決ログイン障害を主張するものではない**。診断カテゴリを最新に当てはめる際は、新たな実観測が必要。
- Render `My Workspace` read-only確認で、隔離Staging Liveは `1e93b6ce17b29e0b029b78293462839fdcbd5a80` (#645のHEAD) 、Auto Deploy OFF。これまでの `366fc031` 等の診断SHAは現在のLiveではない。
- 所有者が報告したGitHubのみの新規認証設定と既存の合成Development Plan 1の取り消すまで有効なoverview共有は、ライブでの**別GitHub本人**の拒否や、失効後も生きているJWTの再読拒否を証明しない。これらは別途隔離合成テスト #672と明示的に承認されたライブ受入が必要。
- 本文の「1 / 7 / 30日」は2026-10-14時点の操作説明として保存する。最新の共有期間仕様・権限条件は現行 `main` の `McpExplicitPlanConsentService` とActive Specを優先する。未来の接続コードやパスキーは **Future Candidateで未実装**。
- Runbookのmain統合は本番・Stagingのアプリ公開、既存の合成grantの取り消し、OAuthの変更、Render/Aiven/Auth0操作を伴わない。Release Issue #488の独立受入を維持。

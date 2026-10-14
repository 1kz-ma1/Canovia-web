# Canovia × ChatGPT MCP — real-account readiness and controlled release gates

Date: 2026-10-14 JST  
Owner lane: **Platform (security / OAuth / database / deployment)**  
State: **ACTIVE implementation specification; preparation only. NO production authorization.**

This is the current implementation/acceptance contract for preparing an ordinary Canovia account to connect to ChatGPT. It is **not** a new roadmap, a production enablement request, or proof that ordinary user Plans are currently readable. Consult latest `main`, `AGENTS.md`, the canonical roadmap and actual release evidence before each implementation slice.

## 1. Verified baseline and evidence limits

- Development main inspected: `6ad194019886e4992674c0ca2ab0129c919c5cf5` (recheck on every PR). PRs [#582](https://github.com/1kz-ma1/Canovia-web/pull/582), [#587](https://github.com/1kz-ma1/Canovia-web/pull/587), [#589](https://github.com/1kz-ma1/Canovia-web/pull/589) merged to main. Do **not** reimplement their focused consent preview/completion, opt-in indefinite Plan grant, or audit-preserving external subject unlink/relink.
- Isolated Render stage: workspace `tea-d4vb3f6mcj7s73djkrqg`, service `srv-db43l4nlk1mc73emseig`. On inspection 2026-10-14: manual deploy `dep-db7icv0473hc73dgnbk0` **Live** at `8a831448eef528b786053ba5506517300bc001c7`; Auto Deploy OFF; stage release [draft #594](https://github.com/1kz-ma1/Canovia-web/pull/594) stays unmerged.
- Real ChatGPT OAuth + read + post-revoke denial was [accepted](MCP_CHATGPT_STAGING_REAL_OAUTH_READ_REVOCATION_ACCEPTANCE_2026_10_14.md) only for one synthetic staging fixture, not real Canovia user data. The owner's recent “できた” reports a browser-side operation; server-side post-relink grant status, precise identity match and active ChatGPT session were **not independently reverified**.
- Read-only Render request-log search for HTTP 429 in the 2026-10-14 00:00–07:30 UTC staging window found no matching records. This **does not** locate or rule out Laravel/application, proxy, Auth0, ChatGPT or upstream rate limiting. Do not bypass throttles or log tokens to diagnose.
- Production remains separate Render + Aiven MySQL and has **not** been deployed or migrated by this assessment. The isolated free Render staging PostgreSQL has a previously recorded expiry of **2026-11-08 UTC**; verify live provider status before relying on it. Never use it for real Plan data.

## 2. Readiness classification — never conflate these states

| Capability | Main implementation | Isolated staging acceptance | Ordinary-account/production |
| --- | --- | --- | --- |
| PKCE account linking, immutable subject binding, explicit per-Plan consent | Implemented behind independent OFF-by-default flags | Synthetic positive read verified, latest unlink/relink UI owner-tested but post-relink server state not independently accepted | **Not yet configured/accepted** |
| Focused preview, cancellation, completion-first guidance | Merged #582 | Included in pinned stage release | Product account experience needs real-user usability acceptance |
| 1/7/30 days and opt-in `until_revoked` consent | Merged #587 | Included in stage release | Requires indefinite-grant risk controls and production migration |
| Unlink/relink without grant inheritance, retained audit | Merged #589 | Included in stage release | Requires production migration and concurrency/relink tests |
| ChatGPT OAuth token validation + bounded read-only MCP tool | Implemented and gated | Only synthetic Plan/Task read and post-revoke denial accepted | **Never enable for ordinary Plans before security sign-off** |
| Auth0 test tenant and two-client wiring | Stage only; actual linked subject is not the ChatGPT session | One separate ChatGPT OAuth connection read observed | **Production tenant/client/issuer/callback/identity provider not yet approved or independently verified** |
| Auditable access history and abnormal-use alerts | Grant/revocation metadata events exist | No production operations evidence | Review per-read audit, redaction, retention, user visibility, alerting and emergency disable |

No stage-only account, OAuth client, synthetic Plan, provider secret, Render database, Laravel APP_KEY, HMAC identity key, session or cookie may be reused for a normal user.

## 3. Exact public user journey (acceptance specification)

1. **Canovia account**: log into the ordinary Canovia product account. Present the ChatGPT connection option only when the operator has separately enabled a production-ready security gate; otherwise show a truthful “現在準備中” notice and no actionable OAuth/consent form.
2. **Identity check**: begin Canovia's **confidential** account-link PKCE flow. When the user chooses GitHub at the IdP, provide an explicit GitHub choice where supported; never assume the browser's cached Auth0/Google SSO matches. If the IdP cannot enforce provider selection, show an explicit account-choice instruction and test it. Confirm the verified external immutable issuer+subject, not email, against the currently authenticated Canovia actor.
3. **Choose Plan**: list only Plans owned by this actor and classified personal Development, not collaboration, archived foreign-owner records, or inferred Plan IDs. No auto-selection/auto-sharing. Explicit scope: `overview` or `tasks` (Task title/status/recorded progress; maximum **8** per call). No descriptions, evidence, repo credentials, or writes.
4. **Consent**: offer finite 1/7/30-day periods and opt-in `until_revoked`. Present server-generated scoped Plan preview; require a **fresh** external re-verification and a separate CSRF-protected final confirmation. Never create an active grant by visiting a page, accepting ChatGPT's OAuth prompt alone, or merely having an identity link.
5. **Completion**: clearly say “Plan共有許可が保存されました。ChatGPTのOAuth接続は別操作です”; show safe Plan-ID prompt and separate ChatGPT authorization steps. ChatGPT must use the **same immutable Auth0/GitHub subject** as the Canovia link. Never claim the ChatGPT session identity was verified merely because a local link exists.
6. **Owner-only status**: distinguish `Identity linked`, `Plan grant active/expired/revoked`, and `ChatGPT connection independently not observable`. The Canovia server cannot truthfully report `ChatGPT connected` without a separately defined, privacy-preserving proof. Do not expose raw subject, fingerprint, OAuth token, ID token or client secret to users, HTML, telemetry or logs.
7. **Disable/repair**: explicit per-Plan grant revoke; explicit unlink revokes **all** subject grants; no automatic grant revival on re-link; new Canovia account starts with zero grants. Provide signed-out / wrong account / SSO mismatch recovery guidance without silently resetting other accounts.

Japanese UI wording should be user-facing, not technical: `GitHubで本人確認する` (only if the provider can actually honor the choice), `この内容で共有を許可する`, `共有許可を取り消す`, and `ChatGPT側の接続は別途必要です`. Show one primary next action per state. Ensure phone/screen-reader accessibility.

## 4. Environment & production security gates (ALL required)

**OAuth/IdP**
- Select and approve a **production** Auth0 tenant or independently reviewed alternative; isolate from `canovia-mcp-dev`. Confirm its plan costs, issuer, allowed GitHub social connection, S256, RFC 9207 `iss` in success/error callbacks, stable or UI-returned ChatGPT redirect, CIMD internal `tpc_` client identity, precise `canovia.development.read` scope, and exact single MCP audience.
- Use separate first-party confidential account-link and third-party ChatGPT OAuth clients, with minimal scopes and separate redirect allowlists. Keep issuer, audience, client ID, link callback, protected-resource metadata and true `https://<approved-production-host>/api/mcp` canonical resource mutually consistent. Do not invent a production host, redirect ID, credentials or Auth0 tenant settings.
- Prove signed JWT/JWKS signature, algorithm/key rotation, expiry/leeway, revocation and exact client/resource/issuer/scope binding. Confirm refresh/re-auth behavior; ChatGPT and Auth0 configuration must be tested together, not assumed from discovery metadata.
- Existing MCP flags are OFF by default; exposure requires a separate explicit production operator decision. Ensure emergency OFF shuts down discovery, tool read, and grant creation as appropriate and verified without reviving old grants.

**Authorization/privacy**
- An MCP read must independently verify principal→active fingerprint→local owner→current personal Development Plan→active approved client/resource/Plan/scope grant **for every call**, under read/revoke concurrency safety. A transferred Plan, newly collaborative Plan, removed Development category, revoked subject, revoked grant, or mismatched owner fails closed.
- Review indefinite-grant retention/rediscovery, periodic user-facing reminders or review (policy to decide; no silent auto-renew), incident response, immutable audit retention and log minimization. Explicitly avoid logging payload, PII, tokens or OAuth callback query. Decide on per-read audit, alert thresholds, and recovery before enabling personal data.

**429 investigation**
- On a future reproduction capture only approximate timestamp/time zone, request stage, HTTP response status, and safe request-id if available. Check sanitized Render request/app logs and Auth0 tenant rate-limit events via the operator, then identify **which layer** returned 429 before modifying retry policy. Do not indiscriminately retry POST authorization/consent operations or bypass rate limits.

## 5. Database / release migration gate (operator-owned; NO DDL now)

Schema changes from PR #587 `2026_10_14_000100_allow_nonexpiring_mcp_grants.php` and PR #589 `2026_10_14_000200_preserve_historical_mcp_identity_links.php` are **not** a routine no-downtime deploy over old writers.

1. Pin a reviewed `release/*` production candidate at an exact approved main SHA; independently inventory actual Aiven engine/version, existing tables/indexes, nullable expiration and duplicate historical identity fingerprints through **metadata-only** inspection. Never publish sampled fingerprints, private grants or user data.
2. Create/verify an operator-approved **restorable** Aiven backup/snapshot and rehearsal environment. Rehearse forward migration, interrupted/retry path, schema/index integrity, original finite grants, new `until_revoked` grant, new active-only unique index, already-revoked records and cross-account relink on disposable data. Validate MySQL 8.0/8.4 and target production engine, and no old-application writer after global unique index is dropped.
3. Establish an explicit maintenance / atomic application+schema cutover with write fencing (including web workers, queued writers and any second app instance). Stop or fence legacy MCP identity writers before dropping the old global unique index; new application writers must populate `active_identity_fingerprint`. Recheck `active_identity_fingerprint` uniqueness **before opening traffic**.
4. Restoration is **not** a blind `migrate:rollback`. Down of nullable grant expiration refuses when indefinite grants exist; down of historical identity uniqueness refuses when any fingerprint has legitimately transferred. Depending on state, restore an operator-verified database snapshot with compatible application code, or execute an independently reviewed roll-forward fix. Preserve audit rows and user consent history; never delete them to force rollback.
5. Explicit owner approval is mandatory **separately** for production Aiven DDL, production environment/config edits, deployment/promotion, and first genuine user Plan access. A development-main merge does none of those.

## 6. Tests and release acceptance matrix

- **Unit/feature (CI)**: PKCE freshness/replay/CSRF; same vs wrong issuer+subject across both OAuth clients; correct/foreign owner; exact client/resource/scope; 8-Task bound and private-field absence; 1/7/30 expiry and null-expiry behavior; revoke and unlink before/after active read; cross-account re-link never inherits grants; two concurrent re-links cannot both become active; transfer/collaboration/category change; old-schema writer incompatibility.
- **Disposable DB**: PostgreSQL 17 and target Aiven MySQL engine forward/idempotence/retry, unique constraint enforcement and refusal of unsafe downgrade; backed-up restore rehearsal. CI alone is not production backup proof.
- **External synthetic staging**: exact reviewed stage SHA and disabled-flag checks; two-client GitHub identity match; overview/tasks read with real ChatGPT; wrong OAuth account denied; revoke read denied; expired/indefinite grant; status page truthfulness; 429 provenance if reproduced. Stage fixture remains **synthetic only**.
- **Owner-accepted real account (LAST)**: after approved production release and explicit per-Plan consent only, authorize exactly one personal Development Plan at **overview** scope first. Then, only on an additional deliberate consent, `tasks`; confirm partial metadata; change permissions; revoke; prove post-revoke denial; unlink; verify new account gets no old grants. Do not read another real Plan to “test discovery.”

## 7. Small PR sequence, ownership and stop conditions

1. **This PR — spec/evidence only**: record gaps, operator release gates, user-state semantics and test matrix. No OAuth, configuration or DB writes; no production activation.
2. **Next independent code PR — truthfulness and onboarding**: owner-only, safely gated status/next-step UX with regression for linked vs granted vs expired/revoked vs unknown external ChatGPT state; no automatic external account login, credential exposure or production enablement.
3. **Security-test PR(s)**: read/revoke concurrency, active uniqueness/races, token claims, denial redaction and 429 provenance instrumentation with allowlisted categories (no sensitive payload).
4. **Disposable migration rehearsal PR/CI**, then exact-sha staging regression and security/privacy sign-off.
5. **Separate operator-approved production release** only after all above evidence, backup and rollback plan, Auth0 tenant separation, user consent and live monitoring are accepted.

**STOP / NO-GO:** unknown production OAuth issuer/client/redirect; failing or missing required CI; unverified token/client claims; unproven backup restore; old writers not fenced during uniqueness change; wrong-subject acceptance; leaked credentials; inability to revoke; unresolved access control race; absent product-owner production consent.

Related contracts: [Plan consent](MCP_EXPLICIT_PLAN_CONSENT_CONTRACT.md), [account linking](MCP_OAUTH_ACCOUNT_LINK_CONTRACT.md), [read-only resource](MCP_READ_ONLY_RESOURCE_CONTRACT.md), [revocation](MCP_DELEGATED_REVOCATION_CONTRACT.md), [identity relink](MCP_IDENTITY_RELINK_AFTER_UNLINK_2026_10_14.md), [staging acceptance](MCP_CHATGPT_STAGING_REAL_OAUTH_READ_REVOCATION_ACCEPTANCE_2026_10_14.md), [Auth0 callback/scope](MCP_CHATGPT_AUTH0_CALLBACK_SCOPE_ACCEPTANCE_2026_10_13.md), [indefinite/relink staging release](MCP_INDEFINITE_CONSENT_AND_IDENTITY_RELINK_2026_10_14.md).

## 8. Current security slice: corrupt live-reservation and token-verification revocation

Implementation scope of this feature branch (no schema/config/deploy changes):

- A live external-identity record must have an **exact** active reservation equal to its immutable historical HMAC fingerprint. Confirmations inspect linked/unrevoked identity claims by original fingerprint, not only the potentially missing active reservation. If an out-of-band write or interrupted migration leaves a live row with `active_identity_fingerprint=NULL` or a different fingerprint, **both** its owner and a second account fail closed. Normal released/revoked history can still re-link after fresh OAuth and must not inherit consent.
- During a trusted remote bearer verification callback, an owner may revoke an indefinite grant or unlink the external identity. A subsequent request continuation must independently re-check current grant/link records before returning any Plan data, and must not record `context_read`.
- The tests above exercise a deterministic **revocation-between-token-verification-and-read** ordering and malformed-row negative controls. They do **not** establish actual overlapping multi-connection `SELECT ... FOR UPDATE` behavior; a separate disposable PostgreSQL 17 / MySQL 8.0+ multi-connection lock-contention matrix remains required before real user access. Do not label this gate complete on SQLite success alone.


## 9. Disposable real-database contention matrix (separate acceptance)

A dedicated CI-only matrix runs against fresh **PostgreSQL 17 / MySQL 8.0 / MySQL 8.4** databases named `canovia_mcp_lock_ci` on `127.0.0.1`. The job must never use Render, Aiven or public staging databases, and the feature test rejects non-CI environments/targets before creating fixtures.

- A real `McpReadOnlyContextTool::read()` invoked inside an outer transaction holds locks on the Plan, linked subject and grant while producing whitelisted metadata and `context_read` audit. An independent PDO connection issues `FOR UPDATE NOWAIT` and must fail on **all three rows** until the reader commits.
- Once the read commits, a user-initiated grant revoke or identity unlink must succeed and every **subsequent** read must return no Context. The original `context_read` event remains exactly once; denied reads add none.
- An uncommitted active-identity insert must prevent a different owner's simultaneous insert through the DB unique reservation (bounded lock timeout); after commit, the duplicate remains rejected. This independently tests the database uniqueness fence, not a synthetic-only Eloquent precheck.
- All checks must be green for **each** engine. Test runner DB target must be a uniquely named disposable local database. Missing real-DB matrix evidence leaves production authorization **blocked**.
- This proves selected DB row-lock / uniqueness behavior, **not** every real-world clock interleaving, Auth0 token revocation, production Aiven backup or every write path. Those remain separate reviews.

## 11. Unsafe MCP schema rollback and old-writer reproduction (disposable only)

- MySQL 8.0 / 8.4 and PostgreSQL 17 CI additionally exercise both 2026-10-14 MCP migrations **after** real schema creation. A non-expiring grant makes `000100.down()` refuse; reused historic external identity makes `000200.down()` refuse. Neither case may delete original grant rows or metadata-only audit.
- A deliberately unsafe *old writer* using raw SQL inserts a second linked record without `active_identity_fingerprint`. This row is evidence of the **known pre-cutover risk**: even with the active-only unique index, a legacy writer can bypass the reservation. The `000200.up()` replay must reject the duplicate rather than silently accepting two active owners. Production migration must therefore **fence all legacy writers** before changing the global unique constraint.
- CI simulates only disposable DB behavior; no Aiven backup is created or restored, no historic records may be deleted to force a successful downgrade, and no production migration or deployment is authorized by a green matrix. Operator identity and actual Aiven version/restore readiness remain **BLOCKED/UNVERIFIED**.

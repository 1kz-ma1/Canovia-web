# MCP staging — identity-link mismatch triage and acceptance runbook

Date: 2026-10-14
Status: **diagnosis & manual acceptance procedure only**; no runtime/DB/IdP/config mutation is authorized by this document.
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
3. In the owner's private Auth0 Dashboard, compare the original successful **link confirmation** auth event with this reauthentication event using the immutable `user_id` and same issuer. Share only `same / different / unknown` with the development chat; do **not** post the raw subject.
4. Verify privately from audited Render configuration/change history whether the fingerprint key was **the same value** at original link creation and at the failed callback. Share only `unchanged / rotated / unknown`; do not print or copy any value. Treat a key mismatch as a security-sensitive migration issue, not a reason to weaken the validator.
5. If still unresolved, request a separately reviewed **staging-only, read-only, privacy-safe diagnostic** or operator-side DB audit (counts/status/event categories only, no subject/fingerprint values). The hosted Render connector cannot currently query this staging PostgreSQL because its external IP allowlist is intentionally empty. **Do not open DB networking to diagnose**.
6. If the original link is proven to be a different identity, use only an owner-authorized explicit unlink/relink procedure that verifies current ownership and maintains revoked grants; if the current UI lacks such a supported route, stop and design that recovery separately. Never overwrite `mcp_linked_subjects` directly or auto-link by email.

## Success/negative acceptance

- Correct Canovia synthetic actor completes confirmed external-ID link; same immutable IdP subject returns on a separate *fresh* PKCE Plan-consent callback.
- Explicit scope `overview` or `tasks`, exact single synthetic Development Plan and finite 1/7/30-day consent are shown; only final owner POST creates an active grant.
- Unconsented Plan is denied; authorized `tools/call` returns at most the approved synthetic metadata; revocation immediately denies later calls. Confirm true ChatGPT E2E separately from PHP tests/logins.
- Wrong Auth0 subject, revoked link, different actor, wrong client, expired PKCE/state or unapproved Plan must continue to fail closed.
- Keep staging and production separated; no Auth0 tenant-wide policy adjustment, secret exposure, HMAC reset, database network exception, or moving-main redeploy just to make a test pass.

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

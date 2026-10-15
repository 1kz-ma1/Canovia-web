# Learning Context Watch v0.4 — owner-facing consent and manual inspection

Date: 2026-10-15 JST
Lane: Intelligence (persistence policy) + Learning (settings surface).
State: a feature-gated owner-first UI/control implementation on development main; NOT live deployment or automatic monitoring.

## Product intent

Canovia — not ChatGPT — owns recorded facts, source freshness, changes, and eventual notification policy. This bounded slice makes the v0.3 watch baseline (`#711`) usable from the owner's Study Workspace without adding any polling/delivery. The user may opt in, manually compare once, and erase retained data. An inactive/off gate does not prevent erasure of already retained user data.

## Implementation and privacy

- New authenticated GET / POST / POST compare / DELETE routes under `/workspace/study/context-watch/{plan}`. Rate-limited; session CSRF-protected by Laravel web middleware.
- A user may view or opt in only to an **owned, private, non-collaborative Study Plan**. The existing guest-cookie edit permission, shared membership or a different account is not sufficient. Foreign/non-Study Plans do not disclose a settings page. Current owners can still view/erase an already-consented watch after the feature is OFF or the Plan changes category/privacy.
- The Study Workspace link appears **only** for logged-in owner on an eligible Study Plan when the persistence feature is enabled and the environment is non-staging. The default flag is still `CANOVIA_LEARNING_CONTEXT_WATCH_PERSISTENCE_ENABLED=false`, and no production setting was changed.
- Starting recording requires an explicit, visible `consent=1` checkbox. Copy discloses which bounded fields are stored, encryption, tentative retention (30 days / up to 32 comparison records per Plan), revocation and lack of automatic monitoring, push, email, external AI access, or Task/Plan mutation.
- A second POST does **not** silently erase or reset a baseline; explicit OFF → re-ON is necessary to rebaseline.
- Manual compare invokes `advance` only for a currently opted-in owner, using the already existing source-capture/revision/dedupe logic. The page shows simple status and number of currently active review candidates; it never displays raw task descriptions, problem bodies, assessment rationale, hidden snapshot content or fingerprint hashes.
- Explicit OFF atomically removes encrypted baseline and all comparison records; actual Study Plan, Tasks and underlying practice results remain unchanged. OFF remains available when the main feature flag is disabled or the Plan is reclassified.
- Responses from the private settings page are `Cache-Control: no-store, private`. No public roadmap card is upgraded to released by integration into development main.

## Tests

Disposable Laravel Feature tests cover initial OFF, no link, session authentication, wrong user, domain/public/collaboration access denial, consent validation, encrypted first-party records, idempotent repeat POST, explicit manual compare without delivery, change-candidate dedupe, OFF-with-erasure, Staging denial, feature disabled while data exists, and no raw private task descriptions. Existing v0.1–v0.3 tests are rerun; repository release/workspace CI remain separate gates.

## Future safety gate — NOT activated

DO NOT register auto-scheduler, background job, Queue Worker, notification outbox, push/email/webhook/MCP call, or Premium 2 cross-Plan scanning from this slice. Older Task and Study source writers do not yet all participate in a shared Plan-row mutation-lock contract. A successful manual revision recheck does not prove fully atomic concurrent writers or delivery idempotency. Before automatically running `advance`, require a separately tested source consistency/concurrency fence, effective opt-in consent duration and expiry, rate limits, retention purge even when dormant, account deletion coverage, and a separate notification delivery/quiet-hours preference + fresh permission check. Feature rollout must be manually approved after physical iOS/browser acceptance and the SHA-pinned Aiven/Render release audit.

The initial AP personal pilot remains HOLD: merging this PR to development main is not an Aiven DDL execution, real account consent, deployed code, or proof of a Live user flow.

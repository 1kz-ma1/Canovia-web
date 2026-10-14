# G3-R11 — Private File Path Fence Pilot / Release Contract (2026-10-14)

**State: DEVELOPMENT / OPT-IN / NOT A PRODUCTION RELEASE APPROVAL.** Owner: Platform lane.
Based on #567 (durable outbox), #569 (operator health), #570 (fail-closed
preflight), #572 (last DB reference check), #574 (MySQL current reads).

## 1. Problem / objective

The cleanup worker holds its own Outbox row, verifies the current
Inbox/Study Recall/Career database references twice, checks the original
SHA-256, then performs a generic Storage::delete. An uncoordinated writer
can still insert a reference or replace bytes after the final DB check.
MySQL current reads solve *stale snapshots*, not the check→unlink gap.

**R11 objective:** establish one cross-engine, keyed, path-specific lock
primitive with deterministic identity and real independent-session tests.
Pilot it in the deletion worker and Inbox→Recall file copy. Do **not**
claim closure of other writers or external object-store mutation.

## 2. Runtime mode and schema

Config: \`CANOVIA_PRIVATE_FILE_PATH_FENCE_ENABLED\` defaults to **false**.
When disabled, the new service is never called by existing worker/copy
routes. The additive \`private_file_path_fences\` migration creates:

- \`id\` primary key, \`path_digest\` fixed 64 lowercase hex **UNIQUE**, timestamps.
- The digest is HMAC-SHA256 of domain separator + configured disk + private
  storage path, keyed with the current \`APP_KEY\`. Do not persist raw paths,
  filenames, account IDs, disk names or the HMAC secret in the fence table.
- Rows are retained (including former/deleted files); row deletion, key rotation
  and HMAC algorithm version changes require an explicit safety migration.
  Recreating rows mid-flight reintroduces the ABA race.
- Path namespace is strictly limited to \`inbox/\`,
  \`study-recall-sources/\`, and \`career-captures/\`; reject path traversal,
  wrong disk, empty segments, oversized keys and missing APP_KEY.
- Only a DB transaction may call \`acquireInTransaction\`. It first
  \`insertOrIgnore\`s the unique key and then \`SELECT ... FOR UPDATE\` locks the
  exact persisted row, keeping the lock until the **outermost transaction**
  commits/rolls back. Do not call on a separate DB connection.

## 3. Initial participating routes and required order

**Cleanup worker (opt-in):** lock durable Outbox row → decrypt and validate
entry/disk → acquire path fence → first strict live-reference check → verify
storage exists and SHA-256 → final strict live-reference check → delete
physical bytes → consume Outbox entry → commit. If key/table/lock cannot be
acquired, retain bytes and reschedule with fixed
\`path_fence_unavailable\`, preserving existing redacted logs.

**Inbox→Recall copy pilot (opt-in):** lock existing Plan → Task → Inbox →
membership and verify the source → generate an independent UUID destination
path → acquire its fence → copy/verify source bytes → insert Recall source
reference → commit. The final file deletion on transaction rollback is a
legacy *best effort* path and still needs a separate coordinated cleanup
design. A randomly generated destination key is not equivalent to an
enforced immutable object-store generation.

**Avoid lock cycles:** establish a canonical ordering before expanding writer
coverage: parent authorization rows → path lock → destination reference
mutation. Cleanup currently locks Outbox before path. Any writer that also
needs an Outbox lock must review and maintain a consistent global order.
Never hold a lock while calling an AI provider or unbounded external API.

## 4. Writer coverage audit / excluded routes

| Path owner | Observed flow | R11 participation |
| --- | --- | --- |
| Inbox upload, \`InboxController::store\` | File \`storeAs\` before Inbox DB insert | NOT COORDINATED |
| Study Scope upload, \`StudyScopeCaptureController::store\` | File \`storeAs\` before Inbox/Capture transaction | NOT COORDINATED |
| Study Recall upload, \`StudyRecallCandidateController\` | File \`storeAs\` and Recall DB insert | NOT COORDINATED |
| Inbox→Recall copy, \`InboxRecallMaterialTransferGuard::copy\` | UUID destination copy within locked DB transaction | **OPT-IN PILOT** |
| Career screenshot, \`CareerCaptureService::record\` | Currently stores payload DB bytes; path parameter still accepted | NOT FULLY COORDINATED |
| Plan / Account / Career / Study Scope cleanup | Durable Outbox + post-commit worker | **OPT-IN PILOT** at physical unlink |
| External SQL/object-store writers, future import/sync | Not governed by app transaction | NOT COORDINATED |

These incomplete paths mean R11 is a **foundation**, not full cross-writer
serialization. Setting the opt-in flag on a live system before coordinated
rollout is **not authorized**.

## 5. CI and acceptance

- Disposable PostgreSQL 16: fingerprint redaction and strict namespace,
  transaction-only use, rollback, persistent same-row reuse, independent
  connection blocked by FOR UPDATE, opted-in cleanup preserving old contracts,
  missing fence schema fail-closed, and opted-in Recall source copy.
- Disposable MySQL 8.0 and 8.4: independent second connection blocked by
  \`SELECT ... FOR UPDATE\` during the first connection's transaction,
  retained row reuse plus existing committed-reference visibility regressions.
- Existing dedicated G3 file lifecycle / PostgreSQL source-race /
  Workspace Resume / Migration Recovery checks at the exact PR head.
- P0 disposable MySQL migration integrity must validate the additive schema.
- CI GREEN is **not** evidence of live Aiven schema, lock contention under
  realistic volume, object-store conditional unlink, Render deploy or iPhone E2E.

## 6. Next-stage gates (unresolved)

1. Design coordinated Inbox/Study Scope/Recall file uploads with lifecycle
   of bytes written before DB commit and cleanup after failed insert.
2. Index/query-plan benchmark on real-ish volumes for the legacy MySQL
   long-path \`FOR UPDATE\` reference scans; enforce bounded lock waits and
   review deadlocks/latency. New fence row locks do not remove old gap locks.
3. Prove the exact check→unlink race **on both engines** with participating
   writers, including crash, retry, rollback, and same-key/same-byte ABA.
4. Plan permanent fence row size/growth and cryptographic key rotation without
   splitting lock identity; review provider conditional delete/generation.
5. Only after every source and writer is covered, separately review enablement,
   live migration and production operational approval under Issue #488.

**No Aiven/Render/production branch change, scheduler, live deletion,
migration execution, billing change, deployment or device test is authorized.**
G3 stays OPEN.

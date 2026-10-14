# Safe MCP identity reuse after owner unlink — implementation and release gates

Date: 2026-10-14. Development implementation only; **neither production nor isolated Staging deploy is authorized**.

## User journey

- An authenticated Canovia owner may deliberately unlink their existing ChatGPT-associated external identity. The unlink transaction first revokes **every grant** for that subject, regardless of Plan status, and marks the subject record revoked with `active_identity_fingerprint=NULL`.
- Their original identity-link row, historical audit events, old Plan grants, links to Plans and user ownership are **retained**. This is not deletion or transfer of past records.
- A second Canovia owner may perform a **fresh** OAuth/PKCE account-link proof and one-time final confirmation with that exact GitHub identity **only after** the first owner's unlink has succeeded. The second owner's link is a NEW row with the same **historical** HMAC fingerprint but their own owner ID and subject_link_id, and **NO inherited Plan grants**. They must explicitly authorize each Plan again.
- While the first owner's link remains active, the other owner's link confirmation fails closed. A race between multiple confirmations is stopped by a **unique database constraint on `active_identity_fingerprint`**, not simply by an application precheck.
- On subsequent explicit unlink the identity becomes available to transfer back, but **revoked historical grants stay revoked**. A prior owner can reuse their own historical subject row only after fresh OAuth and explicit link confirmation; no previous grant is resurrected by linking alone.

## Schema and integrity

- New migration `2026_10_14_000200_preserve_historical_mcp_identity_links.php` adds nullable, unique `active_identity_fingerprint` (same keyed HMAC as historical `identity_fingerprint` when linked; NULL when revoked). A nullable unique index supports multiple historical revoked rows, while preventing *two active* owners.
- Backfills currently linked rows; the new unique index is created **before** removing the old global fingerprint uniqueness, even on databases where DDL is not transactionally atomic. Adds a nonunique historical index. The migration is designed to retry if interrupted between supported stages.
- Writes through `McpLinkedSubject` automatically keep the active fingerprint consistent. Per-request MCP reads and consent require exact active fingerprint, linked status, unrevoked subject and existing strict issuer/token/client and Plan ownership checks.
- Down migration **refuses** when a historical fingerprint was reused; silently losing audit or deleting records is forbidden. Operators must independently plan a compatible downgrade if the database has legitimate transfers.
- In environments already running an older Canovia binary, the schema migration must be rolled out under a deliberate **maintenance/atomic release gate**. Never drop the old unique index in a live fleet of instances still using the pre-migration code; earlier versions did not populate the new active reservation field and a brief multi-writer race could otherwise arise.

## Staging boundary

- The isolated synthetic Staging actor is the only account whose identity-link unlink form may appear. The stage policy permits only that exact DELETE route while its independent link pilot is enabled; the controller enforces synthetic owner, active subject ID and request ownership.
- This does **not** authorize use of a normal Canovia account or production MCP read on the synthetic Staging resource. Stage and production databases are isolated and do not need shared identity reservations.

## Acceptance requirements

- No live identity can be stolen by another owner.
- After old owner unlinks, new owner may link only via **fresh** external OAuth and explicit confirmation, receiving no previous grants.
- Audit and old revoked grant FK owner/subject IDs remain unchanged.
- Unique DB index rejects direct duplicate active identity rows; wrong client, expired proof, forged/old owner and revoked scope checks stay denied.
- Tests include owner unlink with an indefinite grant, and full MCP OAuth link tests; MySQL 8.0/8.4, PostgreSQL and SQLite migration coverage required before release.
- Do not auto-deploy to Render, apply production migrations, enable MCP for actual private Plan data, rotate an identity fingerprint key, or change cost/plan environment settings. Production and Stage promotions each need **separate explicit owner authorization** and pinned reviewed SHAs.

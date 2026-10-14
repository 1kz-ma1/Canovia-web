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

Config: `CANOVIA_PRIVATE_FILE_PATH_FENCE_ENABLED` defaults to **false**.
When disabled, the new service is never called by existing worker/copy
routes. The additive `private_file_path_fences` migration creates:

- `id` primary key, `path_digest` fixed 64 lowercase hex **UNIQUE**, timestamps.
- The digest is HMAC-SHA256 of domain separator + configured disk + private
  storage path, keyed with the current `APP_KEY`. Do not persist raw paths,
  filenames, account IDs, disk names or the HMAC secret in the fence table.
- Rows are retained (including former/deleted files); row deletion, key rotation
  and HMAC algorithm version changes require an explicit safety migration.
  Recreating rows mid-flight reintroduces the ABA race.
- Path namespace is strictly limited to `inbox/`,
  `study-recall-sources/`, and `career-captures/`; reject path traversal,
  wrong disk, empty segments, oversized keys and missing APP_KEY.
- Only a DB transaction may call `acquireInTransaction`. It first
  `insertOrIgnore`s the unique key and then `SELECT ... FOR UPDATE` locks the
  exact persisted row, keeping the lock until the **outermost transaction**
  commits/rolls back. Do not call on a separate DB connection.

## 3. Initial participating routes and required order

**Cleanup worker (opt-in):** lock durable Outbox row → decrypt and validate
entry/disk → acquire path fence → first strict live-reference check → verify
storage exists and SHA-256 → final strict live-reference check → delete
physical bytes → consume Outbox entry → commit. If key/table/lock cannot be
acquired, retain bytes and reschedule with fixed
`path_fence_unavailable`, preserving existing redacted logs.

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
| Inbox upload, `InboxController::store` | File `storeAs` before Inbox DB insert | NOT COORDINATED |
| Study Scope upload, `StudyScopeCaptureController::store` | File `storeAs` before Inbox/Capture transaction | NOT COORDINATED |
| Study Recall upload, `StudyRecallCandidateController` | File `storeAs` and Recall DB insert | NOT COORDINATED |
| Inbox→Recall copy, `InboxRecallMaterialTransferGuard::copy` | UUID destination copy within locked DB transaction | **OPT-IN PILOT** |
| Career screenshot, `CareerCaptureService::record` | Currently stores payload DB bytes; path parameter still accepted | NOT FULLY COORDINATED |
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
  `SELECT ... FOR UPDATE` during the first connection's transaction,
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
   long-path `FOR UPDATE` reference scans; enforce bounded lock waits and
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

## 7. G3-R12 opt-in native upload path writers (2026-10-14)

**Development-only implementation; feature flag remains OFF.** R12 adds
`PrivateFileUploadService::storeAndPersist`: supplied immutable UUID
destination paths are validated and fingerprint-sorted before any I/O;
every corresponding path fence is acquired within a single DB transaction,
and all destination keys are checked for existing objects **before writing
any bytes**. File uploads happen while locks are held, followed by the
callback that creates all DB source/Inbox rows, then commit. The callback
must not call AI, external network providers or trigger unrelated writes.

If an upload returns an unexpected path, or any owner insertion fails,
the service attempts to delete all newly claimed objects **before**
transaction rollback releases the path locks. It will never try to delete
a key that existed at preflight; incomplete storage cleanup uses fixed
redacted warning codes only. A process crash/power loss during upload can
still leave orphan bytes: there is no durable staging/recovery journal
for these precommit bytes yet.

Coverage with the flag enabled:
- `InboxController::store`: file-based Inbox capture now uploads and
  inserts its Inbox owner in the same path-fenced transaction; chat AI
  remains after commit and outside locks.
- `StudyScopeCaptureController::store`: file upload + Inbox and
  StudyScopeCapture rows within one path-fenced outer transaction; AI
  extraction still runs afterward.
- `StudyRecallCandidateController::extract` and `extractFromResource`:
  file upload and source record in the same fenced transaction. AI
  extraction runs afterward.
- `StudyRecallCandidateController::extractBatch`: all generated paths
  are fenced in sorted order; all files and Recall source rows succeed or
  new files and DB rows are rolled back as a unit before lock release.
- Existing `InboxRecallMaterialTransferGuard::copy` remains the earlier
  initial writer participant.

When the flag is disabled, the old upload/store and DB creation flow
remains in place; no new schema is queried from these routes.

**Safety not closed:** external writers/direct SQL, Career path updates,
rollback cleanup that itself fails, process crash after file write but
before DB commit, leaked keys from historical migrations, APP_KEY
rotation and storage-provider generation/immutable-key semantics all
remain outside the guarantee. A partial writer rollout must not be
described as enabling production cleanup. Runbook §11 and exit audit §57
track these gates; G3 remains OPEN.

## 8. G3-R13 durable prewrite intent ledger (2026-10-14)

**Development-only, second independent flag OFF by default.** Add
`CANOVIA_PRIVATE_FILE_UPLOAD_JOURNAL_ENABLED=false`. Enabling it
requires the existing path-fence flag and new, separately approved
`private_file_upload_intents` migration. In production neither flag is
authorized to turn on under R13.

The upload service validates HMAC path digests before I/O, then, ONLY
when journal mode is enabled, calls `PrivateFileUploadIntentService::prepare`
**before** its storage/DB-owner transaction starts. `prepare` requires
DB transaction nesting level zero, inserts encrypted private path and
digest into the journal with status `prepared`, then **commits** the
entire batch. Any missing table, invalid digest, duplicate historical
path or uncommitted parent transaction refuses the upload before bytes
are written. The primary upload transaction and path fences then work
as in R12; after it commits the journal status advances to
`committed`. If the upload transaction throws, newly claimed files
are best-effort deleted while path locks are held, and the durable
intents are settled as `needs_review` outside the rolled-back
transaction. An inability to settle leaves `prepared` visible for
operator triage and is logged with a fixed redacted code.

Journal rows are retained indefinitely in this design. A unique,
permanent HMAC path digest prevents reusing a previously prepared path
even if its bytes are absent; it also makes APP_KEY rotation a
coordinated schema/identity migration question. `encrypted_path`
is authenticated application encryption, not plaintext; status
inspection does NOT decrypt it.

**Read-only operator interface**:
`php artisan canovia:private-upload-intent-health --json` returns
only `schema`, `health`, `prepared`, `prepared_stale_24h`,
`needs_review`, `committed`, `unknown_status`. With `--strict`,
exit code 1 means unavailable schema/DB and 2 means attention;
default exit 0 for attention. No storage listing, byte read,
decryption, auto retry, row reconciliation or deletion is performed.

A `prepared` intent after a crash **does not prove a file exists** or
that any bytes are orphaned. Conversely, a `committed` status does
not establish that its object survived independent storage faults.
Operator investigation must establish owner reference, object version,
fence/cooperating-writer coverage and generation identity before
proposing any destructive reclamation.

**Remaining gaps**: Prewrite ledger creation can be aborted, but a
separate committed write before the main upload transaction may leave
prepared/needs_review rows when the upload never starts. A process
crash or kill during storage I/O retains the prepared record without
proving object existence. R13 has no independent storage version
reconciliation, automated cleanup, backfill for earlier orphan files,
Career/external writer support, path-row retention policy, key-rotation
protocol, or production deployment approval. If a caller already holds
an outer DB transaction, journal mode intentionally **fails closed**
before I/O. Production traffic compatibility and end-to-end
rollout must be separately evaluated. G3 remains OPEN.

## 9. G3-R14 — Bounded non-destructive upload-intent reconciliation preview (2026-10-14)

**Development-only observer / not a reaper.** New independent
`CANOVIA_PRIVATE_FILE_UPLOAD_RECONCILE_PREVIEW_ENABLED=false`
must be turned on explicitly alongside both existing R11 fence and
R13 journal opt-ins in a disposable non-production environment.
Even if all switches are enabled, the observer refuses execution
when `app()->environment('production')`. There is NO endpoint, timer
or scheduled job. The default production/runtime behavior is unchanged.

`PrivateFileUploadReconciliationPreviewService::preview($limit)` accepts
only a strict maximum **1–20**. It SELECTs **only**
`needs_review` and `prepared` intents older than 24 hours,
in stable ascending intent ID order, and reports the total eligible and
number observed; younger `prepared` uploads and `committed` rows are
never probed. Before looking up any storage metadata it requires the
journal, fence table and all three canonical source reference tables
(Inbox `storage_path`, Recall `storage_path`, Career
`screenshot_path`) and columns to be available.

For each candidate the observer authenticates/decrypts the private
path internally, validates the configured disk and safe namespace with
`PrivateFilePathFenceService::fingerprint`, and compares the
HMAC digest in constant-time. An invalid key, corrupt ciphertext,
unexpected disk or provider/DB exception produces fixed
`unverifiable` aggregate counts without emitting the path/error.
It SELECTs whether a fence row already exists, checks each of the
three source tables for a current reference, then calls targeted
`Storage::disk()->exists(path)` **only**. It never inserts/locks a
fence row, writes a DB row, enumerates storage, reads file contents,
downloads objects or invokes `delete`. Categories describe what was
observed, not authoritative state:

- `reference_and_object_observed`
- `reference_only_observed`
- `object_without_reference_observed`
- `neither_observed`
- `unverifiable`

Outputs are exclusively fixed category names and aggregate counters
(`eligible`, `observed`, fence-row observed/not observed, zero
destructive actions). No intent ID, raw path, file name, ciphertext,
disk identifier, HMAC digest, account, plan, provider exception,
per-object report or deletion candidate list is emitted. Missing
schema or query failure returns an `unavailable` aggregate rather
than partial successes. The CLI is
`php artisan canovia:private-upload-reconcile-preview --json --limit=10`;
without the explicit gates it exits nonzero as `blocked`.

**Critical non-atomicity:** a fence-row SELECT does not acquire a
fence; independent writers may mutate references or bytes between
the DB queries and targeted object existence request. An object
`exists() = false` response also cannot prove absence when its
provider returns false on transient faults. An HMAC match proves
ledger integrity under the current APP_KEY, NOT that the bytes
belong to the intended owner, that no external writer exists,
or that the object has an immutable generation/version.
Even `object_without_reference_observed` does NOT mean a verified
orphan, and MUST NEVER trigger automatic or manual deletion without
a separate approved multi-signal reconciliation protocol.
No production schema/flag/deploy, backfill or destructive action is
authorized. G3 remains OPEN.

## 10. G3-R15 — Optional double-sampled weak metadata and reference consistency (2026-10-14)

**Developer-only, OFF by default and always non-destructive.** The existing R14
preview is unchanged unless the additional
`CANOVIA_PRIVATE_FILE_UPLOAD_CONSISTENCY_PROBE_ENABLED=true`
is explicitly configured alongside the R11 path-fence, R13 upload-journal and
R14 preview opt-ins, in a non-production fixture. The R14 production-runtime
deny gate still takes precedence. No upload or cleanup workflow invokes this
probe. No extra schema or provider credential is required.

`PrivateFileReconciliationSnapshotService::capture` collects a single
**non-atomic** set of *read-only* observations: existence of an already
persisted path-fence row, three separate current SQL reference lookups
(Inbox/Recall/Career) as booleans, and the exact-key
`Storage::disk()->exists(path)` result. Only when the object appears present
does it fetch Flysystem `size(path)` and `lastModified(path)`. It does
not list object storage, read file bytes, compute a checksum, lock a DB
row, acquire/create a path fence, write any DB/Storage state, or invoke a
provider-specific API. A failed/partial metadata read is `unverifiable`.

For each R14-eligible, authenticated private ledger key (still max 20),
the opt-in R15 preview executes **two** such captures on separate
statements. If any observation differs, it increments
`consistency.change_observed` and marks the ordinary R14 category
`unverifiable`. A malformed sample or metadata query exception increments
`consistency.unverifiable`, also with ordinary category
`unverifiable`. Equal complete samples increment
`consistency.weak_pair_unchanged` and retain the existing R14
reference/object observed category. The report emits only fixed aggregate
counters and the fixed mode label
`weak_metadata_not_generation_bound` and always reports
`consistency.generation_verified=0`.

**This is not object version verification.** Generic Flysystem
`exists/size/lastModified` does not provide a strongly immutable
S3 VersionId, conditional-delete `If-Match`/generation precondition,
or transactionally consistent object+DB state. A same-size/mtime replacement,
ABA (A→B→A), owner reference swap that preserves boolean results,
external SQL/Storage changes, or snapshot scheduling can escape detection.
Even a weakly unchanged pair has **zero entitlement to deletion**.
`change_observed` means some non-atomic observation changed, NOT that the
record is orphaned; inconclusive or absent objects are never deletion
candidates. A real provider-specific version contract, immutable generation
capture at write time, storage object-lock capability review and
generation-conditional deletion *would require a separate threat model and
explicit owner approval*. They are NOT implemented or authorized here.

The R14 CLI continues to return only fixed aggregate labels/counters.
The default R14 single-sample path has zero consistency counts and
`consistency_probe=disabled` for backward compatibility. The two-pass probe
doubles the number of metadata/DB observation rounds and is bounded by R14's
1–20 item cap. Tests prove weak-pair equality, reference and storage changes,
provider metadata failure, deliberate same-size ABA false-negative, default
OFF behavior, production deny, redacted output, and no destructive changes on
disposable PostgreSQL16 and MySQL8.0/8.4. **G3 remains OPEN.**

## 11. G3-R16 — Offline storage provider generation contract (2026-10-14)

**Non-production, OFF by default, design contract only.** Existing filesystems
configuration shows the application defaults to `local`, while an `s3`
disk is also defined. Neither a configured S3 disk nor the generic
Flysystem adapter implies a live bucket, versioning enabled, write-time
VersionId capture, or audited conditional-delete operation. The
application's `composer.json` does not explicitly require an S3 SDK
and no G3 upload/delete flow stores or conditions on a provider VersionId.

New `PrivateFileGenerationContractService` provides a bounded, **offline
configuration inventory** behind
`CANOVIA_PRIVATE_FILE_GENERATION_CONTRACT_ENABLED=false`. It always
blocks in production even if enabled. It only reads the configured
default disk driver from Laravel's in-process configuration and emits
fixed labels. It does NOT read credentials, endpoints, bucket names or
files; connect to any provider; instantiate an S3 SDK; write DB rows;
capture a version; or delete anything.

- `local`: `unsupported_local_no_generation`.
- `s3`: `provider_contract_not_integrated` (configured possibility,
  not provider/bucket verification).
- Other or missing driver: `unsupported_or_unknown_driver`.
- Every result: `provider_generation=not_verified`,
  `bucket_versioning=not_verified`,
  `write_time_version_binding=not_implemented`,
  `conditional_delete_enforcement=not_implemented`,
  `all_writers_coordinated=false`,
  `generation_verified=false` and `delete_authorized=false`.

The CLI `php artisan canovia:private-file-generation-contract --json`
prints only this offline, allowlisted report. With the flag OFF or
production environment, the result is `blocked` and exit code 1.
No extra schema/adapter/permission and no runtime rollout is performed.

An additional **synthetic-only** comparison contract
`compareUnboundVersionIds(writeVersion, observedVersion)` returns
fixed redacted labels for missing/invalid, mismatch and matching values.
A version equal to the literal `null` (or empty/oversized/invalid
token) can never count as a non-null immutable generation. Even
`version_match_unbound` returns
`generation_verified=false` and `delete_authorized=false` because
the two caller-supplied tokens are not authenticated against a
durable write-time record bound to provider/bucket/path/owner.

**Important S3 semantics and future implementation requirements:**
S3 versioning-enabled buckets issue opaque VersionIds per object version;
an unversioned or suspended bucket may expose a `null` version which
can be overwritten. S3 `If-Match` with an ETag conditionally deletes
the **current** object, and ETag is *not* equivalent to VersionId.
A version-specific DeleteObject request has distinct semantics from a
conditional delete of the current key: independent live writer/reference
coordination, bucket policy and object version retention still matter.
Before any later implementation: explicitly audit provider/bucket
versioning state, IAM and bucket policy; capture/write-ahead the
authenticated VersionId of the newly created object at upload time;
bind it to durable owner identity and path HMAC, design key rotation;
prove all writers participate in fences; verify API conditional
semantics/real storage races; define authorization and recovery
preconditions with an owner-approved threat model. None is supplied
by R16; no `Storage::delete` call is modified.

Source references (AWS primary):
- https://docs.aws.amazon.com/AmazonS3/latest/userguide/versioning-workflows.html
- https://docs.aws.amazon.com/AmazonS3/latest/userguide/conditional-deletes.html
- https://docs.aws.amazon.com/AmazonS3/latest/API/API_DeleteObject.html
- https://docs.aws.amazon.com/AmazonS3/latest/userguide/DeletingObjectsfromVersioningSuspendedBuckets.html

Disposable PG16 and MySQL8.0/8.4 tests prove default OFF,
`local` and `s3` classification, no secret leakage, no provider calls,
production hard deny, null/suspended-version rejection, token mismatch,
unbound equality remaining unauthorized, and object bytes unchanged.
**G3 remains OPEN.**

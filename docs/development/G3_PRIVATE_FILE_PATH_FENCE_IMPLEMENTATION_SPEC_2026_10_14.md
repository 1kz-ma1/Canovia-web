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

## 12. G3-R17 — Synthetic generation-evidence to upload-owner binding (2026-10-14)

**Development-only, proof-model pilot; no real provider receipts.** R16 showed
that neither the default `local` disk nor an `s3` configuration establishes
an authenticated write-time VersionId. R17 introduces a **fourth**
additive, separately gated schema migration
`2026_10_14_140000_create_private_file_generation_evidence.php`:
`private_file_generation_evidence` with a unique/restrict-delete FK to
`private_file_upload_intents.id`, copied path HMAC, bucket identity HMAC,
fixed provider `s3`, allowlisted owning table kind and primary key,
encrypted VersionId, explicit key epoch and
`evidence_origin=synthetic_fixture`. No raw key, path, bucket, user account,
or VersionId is stored in plaintext. The historical upload-intent ID,
not the rotatable path HMAC, anchors the relational ownership identity.

`PrivateFileGenerationEvidenceService::recordSyntheticCandidate()` is
**only callable in disposable `APP_ENV=testing`**, after the R11/R13
flags plus independent default-OFF
`CANOVIA_PRIVATE_FILE_GENERATION_EVIDENCE_ENABLED` are explicitly enabled.
Requires default s3 driver/config in an isolated fixture, a committed
upload intent, authenticated decryption of its path, matching HMAC, and
a locked, existing Inbox / Study Recall / Career owner row whose private
path exactly equals the intent path. The operation inserts one
synthetic row inside a DB transaction and uses a unique intent FK to
reject duplicate or overwritten evidence. No Storage adapter, network
request, AWS SDK, bucket inspection, upload, or deletion is invoked.
Invalid/missing/null VersionIds, external owner kinds, mismatched paths,
nested transactions, unavailable schema and production/disabled modes
fail closed with fixed messages.

`inspect(intentId)` is read-only. It checks evidence origin, configured
`CANOVIA_PRIVATE_FILE_GENERATION_KEY_EPOCH`, bucket digest, journal
status, path HMAC, authenticated ciphertext and current owner reference.
It returns only allowlisted categories such as
`legacy_or_missing_evidence`, `key_epoch_mismatch`,
`bucket_identity_mismatch`, `owner_reference_changed`,
`identity_unverifiable` or
`synthetic_binding_not_provider_verified`. Every outcome has
`generation_verified=false` and `delete_authorized=false`.
The operator-facing
`canovia:private-file-generation-evidence-health --json` returns only
redacted aggregate synthetic counts, disabled by default and hard-blocked
in production. It never calls `inspect` on an account-selected ID.

**Key rotation and historical compatibility:** `key_epoch` is a
non-secret version label, *not* a keyring or key-derivation scheme.
Changing the epoch fails closed before trusting old rows. Changing
`APP_KEY` even without changing the label invalidates existing
authenticated ciphertext and/or keyed HMAC checks; no automatic
re-encryption, key retrieval, recovery, or cleanup is permitted.
The unique journal FK is stable independently of HMAC rotation, but
the current path-fence and journal digests remain tied to `APP_KEY`.
Old/legacy uploads without an intent or evidence are reported missing,
never inferred as proof and never backfilled here. Physical providers,
historic orphan detection, retention policies and immutable generation
conditional deletion all remain separately gated.

**R17 cannot establish true provider provenance.** A synthetic VersionId
is an opaque fixture input, not the signed/successful return from
`PutObject`. Even a perfectly matching durable DB owner binding is not
proof that a bucket was versioned or bytes have not changed. R17 neither
modifies production upload/delete paths nor enables any cleanup.
Provider return-code capture, bucket/versioning/IAM verification,
signed write response binding, all-writer participation, race experiments,
and owner-reviewed release remain required. G3 remains OPEN.

## 13. G3-R18 — Synthetic PutObject receipt handoff (2026-10-14)

**Test-only contract, NOT a live S3 adapter or authenticated provider response.**
R17 persistently bound a synthetic opaque VersionId to an existing,
committed upload intent and matching Inbox/Recall/Career owner.
R18 adds `PrivateFileWriteReceiptHandoffService::handoffSynthetic`
as a strict, disposable-fixture handoff to that **same synthetic evidence
writer**, with no new migration or change to the upload/delete paths.

The entry point requires `APP_ENV=testing` plus independent
`CANOVIA_PRIVATE_FILE_WRITE_RECEIPT_HANDOFF_ENABLED=false`,
R17 evidence, R13 journal and R11 fence flags. No app production path can
call it successfully. It accepts a **fixed allowlist of fixture fields**
(origin, operation, status_code, intent_id, owner_kind, owner_id,
disk, bucket, key, version_id, request_id); rejects missing/extra
fields and unknown or falsely claimed origins; requires exact
`synthetic_adapter_v1`, `PutObject`, integer HTTP 200 and nonempty,
non-null opaque VersionId. It does NOT accept ETag as a fallback for
VersionId. The fixture-supplied bucket/key and owner/intent context must
match application config and the authenticated decrypted, committed
prewrite journal; the R17 writer then **independently** verifies the
locked owner path, bucket/path digest and unique intent before
persisting `evidence_origin=synthetic_fixture`.

Every return shape has **false**
`provider_receipt_authenticated`, `generation_verified`, and
`delete_authorized`, including the only successful status
`synthetic_handoff_recorded_not_attested`. Failures expose fixed
redacted status labels, never keys, bucket names, request IDs, raw
VersionIds, SQL messages or plaintext paths. A duplicate receipt
cannot replace an already committed synthetic row. Test receipts do
not cause storage operations, SDK calls, live credential reads,
network requests or physical deletion.

**Trust model:** a fixture's `origin=synthetic_adapter_v1` is an
ordinary untrusted string, NOT cryptographically authenticated provider
provenance. The optional context match proves only compatibility with
a currently decryptable DB journal and owner, not that S3 stored bytes,
bucket versioning is enabled, a response was signed, or the response
came from the same request that persisted bytes. The handoff remains
test-only; no production caller, real provider SDK, object generation
identity, or conditional-delete entitlement exists. A real future
adapter needs a reviewed data flow binding one upload request ID,
bucket/key, authenticated VersionId from its write completion,
provider versioning policy, owner transaction, and crash-safe durable
receipt recovery, without promoting caller-supplied JSON to authority.

PG16 and MySQL8.0/8.4 test suites exercise safe fixture handoff,
missing/mismatched/error response rejection, no ETag fallback,
uncommitted journal, owner changes, immutable duplicate record,
default OFF/production deny, redacted outputs and no Storage I/O.
Four G3 additive schema migrations remain **not approved for live
Aiven execution**. G3 remains OPEN.

## 14. G3-R19 — Durable synthetic crash-boundary matrix (2026-10-14)

**Isolated DB fixture only; never a real storage write.** R18 validates
synthetic PutObject-shaped responses after the upload-intent journal is
`committed`, but did not reproduce worker death at the durable
boundaries. R19 adds `PrivateFileSyntheticCrashMatrixService`, gated by
`CANOVIA_PRIVATE_FILE_SYNTHETIC_CRASH_MATRIX_ENABLED=false` in addition
to R11 path-fence, R13 journal, R17 evidence and R18 handoff opt-ins.
It is hard-locked to `APP_ENV=testing`, an outermost DB boundary and
an isolated fixture with S3-style configuration. It never instantiates
an SDK, performs Storage::put, reads any object, makes network calls,
schedules work or invokes delete.

The simulator steps through *separate durable DB commits*:
1. `prepare` commits the prewrite journal row, with status `prepared`.
2. A **synthetic** write acknowledgement is represented by a test
   checkpoint only, **not actual bytes or provider success**.
3. A synthetic Inbox owner reference is independently committed.
4. The journal transitions `prepared` → `committed`.
5. The R18 synthetic response handoff persists R17 synthetic evidence,
   only after its own journal-and-owner path validation.

Deterministic halt points stop after steps 1, 2, 3 or 4; the final
case completes all five. Importantly, halt after steps 1 and 2 leaves
**identical durable DB state**: prepared journal with no owner. There
is no way to determine from this ledger whether storage actually
received bytes. An owner committed while journal remains prepared, and
a committed journal without evidence, are also **not** proof of a
successful object write or safe replay.

A separate read-only `review(intentId)` revalidates journal ciphertext
and HMAC, checks for current Inbox references and uses R17 evidence
identity/epoch validation for synthetic receipt. It emits only fixed
status labels such as `prepared_no_owner_ambiguous`,
`prepared_with_owner_ambiguous`,
`committed_owner_without_evidence`, and
`synthetic_evidence_present_unattested`. Missing owner, multiple
references, corrupted ciphertext, mismatched path identity or
invalid R17 evidence all fail closed with inconclusive categories.
It never queries provider object bytes or attempts a replay.

Every simulated step and read-only review returns
`object_write_verified=false`, `generation_verified=false`,
`delete_authorized=false`, `retry_authorized=false` and
`storage_mutations=0`. Replaying the same key after a simulated crash
is rejected by R13's permanently unique HMAC digest: historical key
reuse/ABA is not automatically authorized. No production
`PrivateFileUploadService` or cleanup worker path is changed.

**R19 is NOT a full crash-safety guarantee.** PHPUnit's injected
checkpoints are deterministic *returns*, not operating-system process
kills; they cannot establish durability under arbitrary power loss,
filesystem flush or real S3 write success. Real write receipt
persistence between provider response and DB commit remains absent.
An untracked object may still exist after physical write/crash.
Future phases need dedicated, crash-safe outbox/staging plus a
provider-specific immutable generation record, external-writer
coordination, independent process crash/restart and ABA tests before
any operator-managed reclamation. Four existing G3 migrations remain
unapproved for live Aiven. **G3 OPEN.**

## 15. G3-R20 — Durable prewrite attempt envelope; acknowledgement gap remains (2026-10-14)

R19 demonstrated that a `prepared` upload intent alone cannot distinguish
a crash *before* a provider request from a crash *after* the provider
accepted bytes but *before* recording the write result. Neither Laravel
DB transactions nor generic object storage supports an atomic commit
across both systems. **It is impossible for code in a crashed process
to durably record an acknowledgement it never persisted.** R20 solves
only the narrower *request-correlation preparation* gap, **not** the
unrecoverable provider-result gap.

A new, fifth **additive, production-unapproved** migration
`2026_10_14_150000_create_private_file_write_attempts.php` creates
`private_file_write_attempts`, with a unique, restrict-delete FK
to the existing immutable `private_file_upload_intents.id`,
matching path and bucket keyed HMACs, a keyed digest of a 48-character
random synthetic request token, explicit key epoch, fixed status,
and nullable *encrypted synthetic* VersionId. There are no plaintext
private paths, raw request tokens, bucket names, or VersionIds. The
row is a lifetime claim; never delete and recreate to authorize a
retry of an ambiguous path.

`PrivateFileWriteAttemptService::reserveSynthetic(intentId)` requires:
- `APP_ENV=testing` (production and every non-test environment blocked)
- independently default-OFF
  `CANOVIA_PRIVATE_FILE_WRITE_ATTEMPT_ENABLED=false`, plus R11/R13
  opt-in and synthetic S3 driver config in disposable fixture
- a **previously committed** R13 `prepared` upload intent, an
  outermost DB boundary, decryptable private path and matching
  fingerprint, and the new schema
- an atomically inserted unique attempt row committed **before any
  hypothetical object write**, returning the request token to test
  code only; the raw token never persists.

An independent `recordSyntheticAck(attemptId, token, VersionId)`
allows one status transition `reserved`→`synthetic_ack_recorded`,
only while the intent remains `prepared`, with HMAC token, epoch,
bucket, journal path and disk checks under DB locks. It encrypts an
opaque synthetic VersionId without ever querying a provider or
establishing that PutObject actually ran. An invalid/null VersionId,
token mismatch, duplicate ack, changed bucket/APP_KEY/epoch, wrong
intent or corrupt ciphertext fails closed and cannot overwrite a
prior acknowledgement. It neither settles the R13 journal nor
creates/authorizes an R17 owner or generation evidence row.

`review(intentId)` and
`canovia:private-file-write-attempt-health --json` are redacted,
SELECT-only, test-environment gated observations. Before any ack
commit, `reserved_outcome_unknown` explicitly represents **both**
"never called provider" and "provider may have written bytes".
After simulated ack commit,
`synthetic_ack_durable_not_attested` means only that untrusted
fixture metadata is saved; no true object generation is verified.
Every result forces `provider_attested=false`,
`retry_authorized=false`, `delete_authorized=false`; no
list/read/put/delete, SDK, external API, credential lookup,
scheduler or automatic replay occurs.

**Scope boundary:** R20 does not connect to real S3/local upload code,
does not persist actual S3 success responses, does not solve real
process-death races, has no guaranteed provider idempotency key
contract and cannot resolve the ambiguity without later provider-side
generation-aware reconciliation. The request token HMAC and encrypted
VersionId depend on the current APP_KEY; epoch mismatch prevents
silent reuse but a safe re-encryption/keyring migration remains
unimplemented. All five added G3 schemas require independent release
and Aiven operator approval. G3 remains OPEN.

## 16. G3-R21 — Synthetic provider observation classification (2026-10-14)

R20 persists one write-ahead attempt, but its `reserved` state does not
establish whether an external provider ever received the request. R21 adds
`PrivateFileSyntheticProviderProbeAdapter` and
`PrivateFileProviderReconciliationService` to classify a **supplied, untrusted
fixture observation**, never to contact S3 or another storage provider.
No sixth migration is needed; the existing R13 journal and R20 attempt
record are read with no mutations.

The new `CANOVIA_PRIVATE_FILE_PROVIDER_RECONCILIATION_ENABLED` flag is
independently OFF by default. Classification requires `APP_ENV=testing`,
R11/R13/R20 enabled, and a disposable S3-shaped configuration.
The synthetic payload is strictly whitelisted: fixed origin; one R20
attempt ID, transient request token, disk, bucket, key, outcome, nullable
VersionId, and observed Unix time. Unexpected authority claims or extra
fields fail closed. The token is re-HMACed with the R20 domain, bucket and
path compared to the durable HMAC/encrypted journal, and APP_KEY epoch,
journal status, record status, ciphertext and schema are checked.
Observations older than five minutes, future observations and observations
pre-dating reservation are rejected. Clock freshness does **not** prove a
linearizable provider snapshot.

Labels include `present_without_durable_ack`,
`version_match_unattested`, `version_mismatch_unattested`,
`present_generation_unknown`, `absent_not_replay_safe`,
`provider_outcome_unknown`, `transport_outcome_unknown`, and
fixed redacted invalid/unverifiable states. For **every** outcome,
`provider_attested=false`, `generation_verified=false`,
`retry_authorized=false`, `delete_authorized=false`.
A matching synthetic VersionId does not authenticate a PutObject,
exclude external writers or ABA, or establish safe conditional deletion.
Object absence cannot authorize retry: delayed writes, multiple versions,
delete markers, consistency limits and races remain possible.

The fixture adapter operates in memory and accepts no SDK response as
authenticated provenance. No live provider I/O, list/head/get/put/delete,
new CLI, scheduler, storage mutations, migrations, UI or automatic cleanup
changes. Local remains the production default and is explicitly unsupported
for this synthetic S3 generation probe. CI exercises the isolated suite on
PostgreSQL 16 and MySQL 8.0/8.4, including corrupt/rotated bindings, denial
gates, receipt mismatch, expired samples, network faults and ABA negative
cases. These synthetic tests do not validate real process death, provider
authenticity or production schema compatibility. Five earlier G3 migrations
remain unapproved for Aiven; **G3 OPEN**.

## 17. G3-R22 — Synthetic multi-observation generation race matrix (2026-10-14)

R21 compares one untrusted provider-shaped sample to a persisted R20
attempt. **R22 does not treat multiple matching HEAD-like samples as a
linearizable history.** The added
`PrivateFileSyntheticGenerationRaceService::assess(intentId, observations,
claims)` consumes only two to four ordered R21 observations (within the
R21 five-minute, post-reservation window), and rechecks the independently
gated R21 HMAC/key epoch, encrypted path, bucket and attempt token for
**each** sample. No live SDK/provider or Storage operations occur.

Strict fixture-only claims contain `versioning_mode`
(enabled/suspended/disabled/unknown), `writer_coordination`
(claimed_exclusive/external_possible/unknown), and
`conditional_generation` (claimed_supported/unsupported/unknown).
Unknown keys, authority fields, type errors, invalid labels and reordered
or equal timestamps are rejected. **Every claim is untrusted, including
`claimed_exclusive` and `claimed_supported`.**

Fixed, redacted classifications include
`aba_pattern_observed_untrusted` for A→B→A;
`generation_transition_untrusted`, `presence_transition_untrusted`,
`absence_not_replay_safe`, `generation_unknown`,
`versioning_unverified_or_unsupported`,
`external_writer_unexcluded`, `conditional_generation_unavailable`,
`observation_gap_unresolved`, `observation_binding_unverifiable`,
and `equal_snapshots_aba_not_excluded` for A→A (even if supposedly
exclusive, enabled and conditionally writable). Claims about Versioning
do not establish actual bucket status; an S3 null Version ID, suspended
versioning or delete marker cannot be treated as immutable proof.
A synthetic A→B→A is **not** proof of who wrote B.

All statuses force `provider_attested`, `generation_verified`,
`writer_exclusivity_verified`, `race_free`, `retry_authorized`,
`delete_authorized`, `network_requests` and `storage_mutations`
to false. New `CANOVIA_PRIVATE_FILE_SYNTHETIC_RACE_MATRIX_ENABLED`
is independent, default OFF, `APP_ENV=testing` and R11/R13/R20/R21
gates required; S3-shaped disposable fixture only. Local production
default remains unsupported for immutable-version claims. No new
migration, owner/cleanup mutation, CLI, cron, real provider calls or UI.

Dedicated PostgreSQL 16 / MySQL 8.0/8.4 CI exercises A→A, A→B→A,
presence/absence, null/unknown generations, writer/versioning/conditional
claims, malformed and cross-attempt samples, timing gaps, production/OFF,
APP_KEY/epoch/tampered encryption and read-only invariants. This does
**not** test real provider consistency, external-writer isolation,
power loss, authenticated receipt, generation-bound conditional
deletion or a keyring. Five prior G3 additive migrations remain
unapproved for Aiven; **G3 OPEN**.

## 18. G3-R23 — Synthetic write-time provenance correlation (2026-10-14)

R23 adds `PrivateFileSyntheticWriteProvenanceService` to compare three
fixture-supplied artifacts: a durable R20 write attempt, a strictly
shaped synthetic PutObject receipt, and a later R21 observation.
It reuses R21's validation of request-token HMAC, encrypted journal path,
bucket, APP_KEY epoch and durable synthetic version evidence.

The synthetic receipt contains only fixed operation/origin/status,
attempt/token/disk/bucket/key, version and request identifiers, and
receive timestamp. Strict capability-claim fields cover versioning,
response binding, external writers and conditional operations.
Unexpected fields, spoofed origins, mismatched attempts, keys, tokens,
versions, stale times, missing acknowledgements, key-epoch rotation
and damaged ciphertext are denied with redacted labels.

`synthetic_chain_consistent_unattested` is an INTERNAL fixture
consistency result only. It is not cryptographic authentication of an
external provider response, proof of an immutable generation, exclusion
of ABA/external writers, or permission to retry or delete. Every result
fixes provider_attested, write_receipt_authenticated,
generation_verified, external_writers_excluded, retry_authorized,
delete_authorized, network_requests and storage_mutations to false.

`CANOVIA_PRIVATE_FILE_SYNTHETIC_WRITE_PROVENANCE_ENABLED` is
independently default OFF and allowed only in testing with R11/R13/R20/R21
gates. Local remains the live default. No migrations, provider API calls,
new scheduler, UI changes or production rollout. PostgreSQL 16 and
MySQL 8.0/8.4 CI cover successful fixture correlation and negative
security/corruption cases. Five existing G3 migrations remain
production-unapproved. **G3 OPEN.**

## 19. G3-R24 — Synthetic provider attestation trust boundary (2026-10-14)

R23 validates that fixture-supplied write/attempt/HEAD statements are
internally consistent, NOT that a provider issued them. R24 introduces
`PrivateFileProviderAttestationBoundaryService::classify` to make the
promotion boundary explicit. It invokes R23 and checks a further
**caller-supplied, strictly shaped synthetic provenance claim** against
R23's attempt ID, transient token, bucket, key, version ID and request
ID, plus a bounded received/issued timeline.

The allowed evidence categories are `application_hmac`,
`sdk_response_headers`, `tls_transport_metadata`, and
`claimed_provider_signature`. They produce, respectively,
`application_hmac_not_provider_proof`,
`sdk_headers_not_provider_attestation`,
`tls_transport_not_write_attestation`, and
`claimed_signature_without_trusted_verifier`. Even a claimed AWS
issuer and signed response are untrusted inputs; the service has
**no independent provider verifier**, signer allowlist, AWS SDK,
certificate validation, credential handling or provider I/O.
An APP_KEY HMAC binds local DB content, not a provider statement;
successful TLS proves neither durable PutObject nor an immutable
generation; a claimed signature cannot authenticate itself.

A forged identity, wrong request/key/attempt/bucket/VersionId, stale
or post-receipt claim, invalid origin, extra authorization fields,
missing R20 ack, R21/R23 mismatch, APP_KEY rotation or ciphertext
damage yields redacted negative classification. A local match may set
`local_binding_consistent=true`; ALL outputs fix
`independent_provider_verifier_available`,
`provider_identity_verified`, `write_response_authenticated`,
`generation_write_bound`, `external_writers_excluded`,
`retry_authorized`, `delete_authorized`, `network_requests`
and `storage_mutations` to false. No caller property can flip them.

The new `CANOVIA_PRIVATE_FILE_PROVIDER_ATTESTATION_BOUNDARY_ENABLED`
flag is independently OFF and confined to `APP_ENV=testing`, R11/
R13/R20/R21/R23 gates, and disposable S3-shaped configuration.
Production remains Local by default; R24 does not register a real
writer, CLI, scheduler, retry or delete. No schema or migration change.

Disposable PG16 and MySQL8.0/8.4 CI check four fake evidence kinds,
spoofed verified flags, cross-attempt request binding, time skew,
missing ack/transport errors/mismatched generation, credential epoch
and ciphertext tampering, default-OFF/production/Local refusal and
absence of DB/storage side effects.

A future live verifier needs provider-bound request provenance,
a reviewed and independently authenticated trust root/transport
identity, anti-replay continuity, write-time generation binding,
bucket versioning/IAM evidence and writer fencing, plus physical
crash tests and generation-safe operations. **R24 does not create
these guarantees. Five previously added G3 production migrations
remain unapproved; G3 OPEN.**

## 20. G3-R25 — Independent verifier contract and deny-only evidence admission (2026-10-14)

R24 separates local HMAC/SDK/TLS/synthetic provider claims from real
provider attestation. R25 adds a **future verifier interface**:
`App\\Contracts\\PrivateFileIndependentProviderVerifier::verify(array)`.
The **only concrete wired verifier** is the final
`PrivateFileUnavailableProviderVerifier`, which always returns
`independent_verifier_unavailable` with authenticity and
write-generation binding false. This does not install an SDK, trust
root, real provider signing policy, production verifier or allow-path.

`PrivateFileProviderEvidenceAdmissionService::review` performs strict,
read-only admission *preflight* on a synthetic candidate. It requires
a previously valid R24 local chain and exact scoped attempt ID, request
ID, version ID, bucket and key. It rejects unknown/extra fields, fake
verified flags, wrong types, stale/future evidence and cross-attempt
data. Claim-only fields are `trust_root`, `write_binding`,
`anti_replay`, `bucket_versioning`, `writer_isolation` and
`generation_preconditions`. Each accepts only bounded claimed,
missing or unknown labels; suspended/disabled/unknown versioning is
explicitly unready. All fields are **caller-controlled assertions**,
not independently checked capabilities.

Missing conditions are `independent_requirements_unmet`; even when
every field says claimed, the sole verifier returns
`independent_verifier_unavailable`. A local match can set
`local_binding_consistent=true`, but no result sets independent
verifier availability, provider authentication, write-bound generation,
writer exclusivity, retry, deletion, network requests or storage
mutation to true. A fixed, redacted `missing_independent_checks`
list always names seven prerequisites: identity and trust root,
response authenticity, request/generation binding, anti-replay,
versioning/IAM, external-writer fence and generation-conditional
operations. It does not disclose private paths, keys or versions.

`CANOVIA_PRIVATE_FILE_PROVIDER_EVIDENCE_ADMISSION_ENABLED` defaults
OFF and the service requires `APP_ENV=testing` plus R11/R13/R20/R21/
R23/R24 test gates and disposable S3-shaped driver. The default Local
production topology remains unchanged. There is no new migration,
command, job, SDK/provider call, reattempt or deletion, and no
user-facing or production write path wired to the contract.

PostgreSQL 16 and MySQL 8.0/8.4 tests cover all-claimed denial,
missing/stale/invalid fields, authorization spoofing, wrong
attempt/request/version/key/bucket, R24 broken chain, missing durable
ack, corruption/key rotation, OFF/production/Local denial and
DB/Storage no-mutation. These synthetic checks cannot prove actual
provider identity, immutable generation, IAM/versioning state, live
fencing, key rotation or real crash recovery. Five existing G3 schema
migrations remain unapproved for production Aiven. **G3 OPEN.**

## 21. G3-R26 — Synthetic trust-root epoch, expiry and replay denial (2026-10-14)

R25 introduced a future independently authenticated provider verifier interface,
but wired only a final verifier that always refuses all candidates. R26
adds `PrivateFileTrustReplayPreflightService::inspect`, a **testing-only
negative classifier** for an additional strictly shaped, untrusted claim
about provider issuer, claimed trust root, trust-root epoch, attempt,
request, object version, nonce, sequence, issued-at and expires-at.
No provider signature, trust anchor, authenticated timestamp or
persistent replay ledger is introduced.

R26 requires a maximum claimed TTL of 60 seconds, no expired/future
window and exact attempt/request/VersionId correlation to the R25
synthetic response. A caller-supplied preceding observation is required
for comparison, but **is not independent, persisted or authoritative**.
Missing history is `replay_history_unavailable`; duplicate nonce,
non-increasing sequence or timestamp, changed root/epoch/issuer/attempt,
and invalid/extra fields yield redacted denial states. A clean-looking
history remains `untrusted_history_not_replay_proof` after R25's
local-chain denial is independently rechecked.

Before resolution, R26 also checks the container for bindings that
attempt to replace the R25 service, concrete unavailable verifier,
or verifier interface, returning `verifier_override_detected`.
This detects accidental/fixture substitution; it **cannot secure a
compromised PHP application/container**. No positive provider verifier
or authorization path is registered.

Every result forces `trusted_root_verified=false`,
`replay_protection_verified=false`,
`independent_verifier_available=false`,
`provider_authenticated=false`,
`generation_write_bound=false`,
`retry_authorized=false`, `delete_authorized=false`,
`network_requests=false` and `storage_mutations=false`.
Only `local_binding_consistent` may be true, meaning R25's
untrusted local chain is consistent, not that provider evidence
was authenticated. Inputs' key/nonce, bucket or VersionId are
never returned. No provider SDK, Storage, DB write, worker,
cleanup scheduling or migration is added.

New `CANOVIA_PRIVATE_FILE_TRUST_REPLAY_PREFLIGHT_ENABLED`
defaults OFF and requires `APP_ENV=testing`, all relevant R11/R13/
R20/R21/R23/R24/R25 gates and a disposable S3-shaped filesystem config;
Local remains the production default. CI exercises dedicated R26
regressions alongside previous G3 suites on PostgreSQL 16 and MySQL
8.0/8.4. Real provider trust-root validation, durable anti-replay,
external-writer fencing, power-loss recovery and atomic conditional
operations remain unverified. Five existing G3 migrations are
unapproved for Aiven production. **G3 OPEN.**

## 22. G3-R27 — Pinned offline provider-evidence inventory and operator handoff (2026-10-14)

R27 moves beyond synthetic denial samples to a **non-authorizing,
offline handoff** for real provider assessment. New
`scripts/ops/g3_provider_evidence_inventory.py` accepts a strictly
typed JSON manifest referencing an exact local Git commit and eleven
mandatory independent evidence requirements (R25's seven trust
requirements plus recovery, reconciliation, key rotation and
release-operator approval). The tool uses only local `git cat-file`,
`ls-tree` and `show` to check ordinary Markdown blob existence,
size and SHA-256 in the pinned commit. It has no network, provider
SDK, database, secret, mutation or migration access.

An absent report or missing/invalid reference is `BLOCK`.
Malformed manifest, unexpected proof-of-authorization attributes,
duplicate JSON keys or requirements, wrong owner-role labels,
symbolic link blobs, missing commit or path traversal fail closed.
Even eleven hash-matching files produce only
`MANUAL_REVIEW_ONLY` and script exit code **2**. Every report has
`provider_verified=false`, `generation_verified=false`,
`replay_protection_verified=false`, `writer_fencing_verified=false`,
`crash_recovery_verified=false`, `release_authorized=false`,
`retry_authorized=false`, `delete_authorized=false` and
`production_modified=false`. No file contents, cloud identifiers,
private object paths, nonces, keys or credentials are printed.

This checks **version-controlled report integrity, not independent
provider truth**. Plausible Markdown and matching SHA-256 can be
self-created, so security/storage/release reviewers must independently
authenticate original records. The separate
`G3_PROVIDER_EVIDENCE_HANDOFF_R27_2026_10_14.md` defines responsible
roles, proof requirements, safe usage and owner-release gates. CI
runs disposable local Git tests in the existing Platform offline
preflight workflow. Existing G3 R11–R26 production pathways remain
unchanged; no runtime flag or migration is added.

Production Aiven migration approvals (five previous additions),
live object/provider evidence, durable retry/replay state,
crash/ABA proof, manual operator review and owner release approval
remain absent. **G3 OPEN.**

## 23. G3-R28 — Offline provider evidence collection-plan readiness (2026-10-14)

After R27's Git-pinned *document integrity* inventory, R28 introduces
`scripts/ops/g3_provider_collection_plan.py` for an even earlier stage:
a strictly shaped, offline, **unapproved collection plan**. The script
uses only the local Git object database to verify a pinned commit,
cross-checks exactly eleven R27 evidence requirements and their
security/storage/platform/release owner roles, fixes the acquisition
source class and isolated disposable/offline review target per gate,
and requires two exact negative-test scenarios per requirement.

The plan must explicitly declare `execution_mode=offline_plan_only`,
`environment=disposable_only`, `authorization=not_granted`,
`capture_state=not_started`, `review_state=unverified`, separate owner
approval required, and restricted external custody of originals with
only redacted candidate Git summaries. Incorrect scope, forged approval
fields, wrong source method, missing scenarios, duplicate entries,
invalid or nonlocal pinned commit and unexpected metadata fail closed.
A fully correct plan is **`PLAN_REVIEW_ONLY` with exit code 2**, no
authorization, provider authenticity, collection, retry, deletion or
production modification. No live S3/Render/Aiven contact, SDK, secrets,
provider verifier, schema change or file mutation exists here.

`G3_PROVIDER_COLLECTION_PLAN_R28_2026_10_14.md` lists the intended
evidence source, reviewer, custody and negative cases for each gate,
along with the explicit human authorization and later R27 inventory
handoff. CI executes isolated local-Git regression fixtures within
Platform Release Preflight; no application production logic is wired.
An operator still needs separate disposable-scope approval before any
real provider access, followed by independently verified evidence,
crash recovery, versioning/IAM and writer/generation fencing review.
Five production-unapproved G3 migrations remain. **G3 OPEN.**

## 24. G3-R29 — Offline disposable approval request (2026-10-14)

R29 adds `g3_disposable_approval_packet.py`: an offline R28
collection-plan wrapper with immutable candidate SHA, isolated
synthetic scope, proposed budget and time caps, temporary identity,
restricted evidence custody, immutable proposed action list, explicit
stop triggers and manual abort/quarantine instructions. A complete
packet is `OWNER_DECISION_REQUIRED` with exit code 2, not consent.
All provider, cost, conditional deletion, release and migration
authorizations are false; no real provider or DB I/O is performed.

Dedicated local Git fixture tests cover forged approvals, oversized
requests, scope substitutions and removed stop/abort controls in
Platform Offline Preflight CI. See the R29 approval-packet document
for candidate limits and separate owner decisions. Five prior G3
migrations remain unapproved. **G3 OPEN.**

## 25. G3-R30 — Offline owner-checklist and hypothetical operation fence (2026-10-14)

R30 introduces `scripts/ops/g3_owner_scope_review.py`, which
requires R29's complete but **unapproved** owner packet and binds
a review to its immutable candidate SHA and locally computed
canonical-JSON SHA-256. Eleven fixed checkpoints remain
`pending_out_of_band`; untrusted approvals/signatures are rejected.
A proposed sequence of disposable synthetic inspect/PUT/HEAD/process
restart actions must match narrow targets and respect cumulative
proposed R29 time/cost/object/request limits. Conditional generation
delete, even for disposable objects, is not in R30's allowed list.

A fully valid review yields only `OWNER_CHECKLIST_PENDING` and
process exit 2. No authenticated consent, provider connection,
real-world quota enforcement, IAM boundary, provider generation
assurance, retry/delete or release permission exists. The caller
supplies the proposed operations and digest, so matching them proves
neither scope compliance in a live account nor a real owner's
approval. See `G3_OWNER_SCOPE_CHECKLIST_R30_2026_10_14.md`
for checkpoint ownership and the future runtime interception gaps.

R30 adds only an offline validator, disposable local-Git tests
within Platform Release Preflight and documentation. No migration,
cloud/DB/Storage calls, production or conditional delete activation.
Five prior G3 migrations remain unapproved. **G3 OPEN.**

## 26. G3-R31 — Pinned exit-decision audit and external proof hold (2026-10-14)

R31 compares a pinned Git commit's five additive migration sources,
default-OFF provider test flags, the R25 unavailable verifier, R23–R26
PG/MySQL CI references, R27–R30 offline validators, and the G3 OPEN
decision documents. It emits only redacted local source states:
`SOURCE_PRESENT_UNATTESTED` or `MISSING_OR_DRIFTED`.
All local matches still produce `HOLD_EXTERNAL_PROOF_REQUIRED`,
exit code 2; any drift produces `HOLD_LOCAL_CONTRACT_DRIFT`,
exit code 2. No output grants provider identity proof, replay safety,
writer fencing, safe delete, recovery, retry, owner approval,
migration approval, release or G3 exit.

The static checks cannot prove complete semantics, actual runtime
configuration, cloud behavior or independent original evidence.
CI executes the checker on the exact PR checkout and isolated
mutation tests. See `G3_EXIT_DECISION_R31_2026_10_14.md` for
remaining real-provider and operator gates and the explicit
owner choice: keep G3 on HOLD or authorize a tightly scoped
disposable-only pilot separately. No additional synthetic contract
iterations should be interpreted as reducing these external gaps.
No new migration, provider/network call or production mutation.
**G3 OPEN.**

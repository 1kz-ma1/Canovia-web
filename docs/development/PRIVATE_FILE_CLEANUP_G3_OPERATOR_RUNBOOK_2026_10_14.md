# G3 Private File Cleanup — Operator Review & Incident Runbook (2026-10-14)

**State: DEVELOPMENT SPECIFICATION ONLY — production not approved.**
Source: `PrivateFileLifecycleService` (outbox deletion) and
`PrivateFileCleanupHealthService` (read-only aggregate monitoring).

## 1. Operational contract

- `main` changes do **not** authorize deployment, production DB migration,
  worker activation, Render/Aiven changes, a cron entry, or a storage inventory.
- Existing `private_file_cleanup_entries` migration must be separately
  reviewed/applied and verified on the intended release environment **before**
  releasing code that registers new file-deletion intents.
- **Never change APP_KEY without an approved decrypt/re-encrypt process.**
  The outbox stores encrypted private storage keys.
- Storage `disk` configuration and object persistence across Render deploys
  must be verified in the planned production topology. A local ephemeral disk
  is NOT a reliable archival or orphan-recovery guarantee.
- `DB::afterCommit` is an opportunistic fast path. A crash-safe intent is in
  the DB, but no recurring retry worker is enabled in this release.

## 2. Safe, read-only checks

From an explicitly authorized environment with the matching configured DB,
run ONLY after release/operator approval:

```bash
php artisan canovia:private-file-cleanup-health --json --strict
php artisan canovia:retry-private-file-cleanup --dry-run
```

The **health** command performs aggregate DB queries only, never decrypts
paths, calls Storage, scans keys, or changes outbox rows. Its JSON keys are
stable and contain only statuses, counts and **allowlisted** reason codes.
The retry `--dry-run` command is count-only; it does not retry deletions.

Health exit codes:

| Code | Meaning | Operator action |
|---|---|---|
| 0 | Healthy (or attention if `--strict` is omitted) | Record aggregate result; review trends |
| 2 | Attention with `--strict` | Follow the quarantine / stale-job review below |
| 1 | Unavailable schema or DB | **Stop**. Verify migration, connection and readiness; do not assume zero pending files |

The health report measures:

| Metric | Condition | Why |
|---|---|---|
| `pending` | Pending entries | Work awaiting next retry |
| `pending_due` | Retry date at or before current time | Actionable by a separately approved retry |
| `pending_stale_24h` | Created ≥24 hours ago | A deletion may be stranded even during exponential backoff |
| `pending_high_retry_5` | Five or more previous attempts | Repeated storage/authorization failures |
| `pending_without_retry_date` | Pending with no next retry date | Malformed/stuck row; not runnable by normal retry |
| `quarantined` | Fail-closed entries | An operator must investigate; **do not auto-delete** |
| `unknown_status` | Unrecognized lifecycle status | Potential migration or data corruption |
| `quarantined_reasons` | Known fixed codes; all unknown reasons grouped as `other` | No raw DB text, paths or account IDs printed |

**Alert rule:** `attention` if any stale/high-retry/no-retry-date,
quarantine or unknown-state metric is nonzero. A recently due pending job
alone is routine (reported but not escalated). `unavailable` is always
a failing state.

## 3. Quarantine classification — no destructive override

| Safe reason | Meaning | Next steps |
|---|---|---|
| `source_unavailable` | Original private bytes could not be signed at enqueue | Verify storage outage, storage inventory and provenance via approved private tooling; never infer a hash |
| `file_bytes_changed` | Actual storage key no longer contains signed bytes | Assume key reuse or external writer until proven otherwise; **do not delete** |
| `invalid_encrypted_path` | Key cannot be decrypted | Verify historical APP_KEY availability; do not log ciphertext |
| `unsafe_cleanup_entry` | Unknown hash/path or invalid key | Review migration and key prefix validation; do not try direct cleanup |
| `other` | Unexpected code, aggregated | Escalate for private operator-side investigation without displaying the raw string in reports |

Never bulk-requeue quarantined rows or edit `expected_sha256` to force
a match. Never copy paths/ciphertext/file names/user IDs into tickets,
ChatGPT, CI logs or monitoring exports. Quarantine is a protective record.

## 4. Operator-approved manual retry (not scheduled)

A dedicated operator may, after confirming access, migration, release SHA
and storage configuration, **explicitly** run:

```bash
php artisan canovia:retry-private-file-cleanup --limit=50
```

This processes at most 50 due entries, each under its outbox row lock.
Before deletion it rechecks known DB references and the exact registered
file SHA-256. A changed file is quarantined, not deleted. Temporary failure
retains a pending entry with exponential retry-after. The CLI outputs
aggregate counts only. No scheduled cron/job has been created or enabled.

Review before each batch if `attention` persists; halt if schema is
`unavailable`, a different storage disk is configured, or an unexpected
increase in quarantine is detected.

## 5. Orphan inventory policy (not implemented)

The outbox can reconcile **only paths recorded when deletion occurred**.
It does **not** discover files orphaned before migration, recover past keys,
or prove ownership based on filename/prefix.

Do **not** run `Storage::allFiles` or direct object-store listing/deletion
against production merely to estimate orphan counts. A future inventory
requires explicit policy and owner approval for bounded prefix scopes,
read-only credentials, pagination/rate limits, path redaction, timestamp
retention, and a reference-consistency/recheck protocol. Inventory
candidates must be human-reviewed; neither the health check nor a
storage prefix alone authorizes deletion.

## 6. Remaining unresolved risk

The current source-reference check and object-store unlink are not a single
atomic operation with independent storage writers. A concurrent new DB
reference created outside participating locks, a same-byte key replacement,
or object-store versioning semantics can invalidate ownership assumptions.
Monitoring does not close these races. Keep **G3 OPEN** until release
verification and an approved storage-writer coordination design.

**No production deploy, Aiven schema mutation, Render cron, production
deletion, branch promotion, OAuth/MCP or billing change is authorized by
this document.**

## 7. Destructive preflight: disk drift and reference-schema failures (G3-R8)

Before unlink, the worker verifies that the entry's sealed disk is the
currently configured default private disk. A mismatch is quarantined with
the **fixed** reason `storage_disk_mismatch`. This is a stop signal, not
permission to switch disks or forcibly retry: verify the original storage
topology and the release's disk configuration privately. Changing defaults
while old entries remain pending requires a reviewed migration/rotation plan.

All three live-reference sources are mandatory for destructive checks:
`inbox_items.storage_path`, `study_recall_sources.storage_path`, and
`career_captures.screenshot_path`. If a required table/column is absent,
or the reference query fails, the worker schedules non-destructive backoff
with the fixed pending reason `reference_check_unavailable`. It never treats
an unqueryable source as an empty table. Do not manually shorten backoff or
execute unverified storage deletes. The read-only health command likewise
treats missing outbox columns as `unavailable`, not zero outstanding work.

These guards **do not** serialize a new reference inserted after the final
successful check, prove the origin of same-byte replacements, or recover
legacy orphan files. G3 remains OPEN. Neither guard authorizes live retry,
production schema execution, scheduler activation or a storage inventory.

## 8. Post-checksum live-reference fence (G3-R9)

The cleanup worker now queries **all three** live reference sources twice:
once before storage I/O, and again after confirming the stored SHA-256 but
immediately before `Storage::delete`. If a new reference is visible to the
transaction's second DB read, the file is retained and the obsolete outbox
intent is removed. If the second query is unavailable, deletion stops and
the pending row gets `reference_check_unavailable` retry backoff.

This is a narrow defense-in-depth improvement, **not a global lock**.
**Database isolation matters:** PostgreSQL's default READ COMMITTED can see
a newly committed reference at the second statement. On MySQL's default
REPEATABLE READ, ordinary non-locking SELECTs may reuse the transaction's
initial snapshot and miss a separately committed reference entirely.
No MySQL cross-session guarantee or additional locking is claimed.
There remains a check→unlink window even after the second query; an
uncoordinated process may insert a DB reference immediately afterward,
or change the object without a conditional versioned delete. Never
describe SHA-256 + two checks as atomic consistency.

Before any production activation, decide and test a provider-compatible
writer coordination/immutable-key protocol covering **all** Inbox,
Study Recall, Career and external upload/update paths. If a storage
provider supports conditional delete by immutable version/generation,
review its semantics separately; Laravel's generic `Storage::delete`
does not supply those semantics. Never enable automatic retries or
production inventory solely based on this guard.

## 9. MySQL current-read protection and uncoordinated writer boundary (G3-R10)

**Implemented in development:** The cleanup worker continues to hold an
outbox row lock. For MySQL/MariaDB it now checks every required live-reference
table using `SELECT id ... FOR UPDATE` instead of the previous snapshot-based
`EXISTS`. Both the initial and final lookup thus use InnoDB **current
reads**, not an earlier repeatable-read snapshot. A separately committed
reference that exists when the locking lookup runs can be detected even
if an earlier ordinary SELECT established a transaction snapshot.

**Scope of acceptance:** A dedicated *disposable* MySQL 8.0/8.4 regression
creates a stale REPEATABLE READ snapshot, commits an Inbox reference on an
independent PDO connection, proves ordinary `EXISTS` still misses it, then
asserts the new service lookup sees it. This is **not** production Aiven
verification, nor proof that concurrent writes during unlink are serialized.

**Residual caution:** Without an index on these long path columns,
InnoDB's locking reads may scan ranges and hold broad next-key/gap locks
until the cleanup transaction ends. Before any production rollout, measure
latency, deadlocks and write throughput with representative high-cardinality
Inbox/Recall/Career data and choose a bounded/indexed coordination key.
Storage read and unlink remain non-transactional. The worker's double
lookup is not an object-store version check. If a lock times out, deletion
must remain fail-closed; do not bypass locking or force a file delete.

**Next required cross-writer contract (NOT implemented or approved):**
Use a stable, private, indexed (disk + path) identity that does not reveal
storage keys in logs, coordinate *all* native file writers and the cleanup
worker on that identity, and prevent new same-key references while physical
unlink is in progress. Review transaction and lock ordering, crash/rollback,
encrypted-key rotation, bounded lock duration, storage-provider conditional
deletion/versioning, and migration/retention impacts before coding. Writers
include Inbox uploads, Study Scope uploads, Recall uploads, Inbox→Recall copy,
Career captures where physical storage is used, future imports and external
direct-SQL/object-store actors. No partial writer rollout may be presented
as eliminating G3 races. Never deploy this change as an independent
authorization for production cleanup.

## 10. Opt-in path-identity fence pilot (G3-R11)

The additive `private_file_path_fences` table and
`PrivateFilePathFenceService` establish a keyed HMAC disk/path identity
and a persistent, unique InnoDB/PostgreSQL row-lock protocol.
The flag `CANOVIA_PRIVATE_FILE_PATH_FENCE_ENABLED` is **OFF by default**.
The only pilot participants are the durable cleanup worker and
Inbox→Recall copy of a newly generated UUID destination file.
Never enable this flag in production under this development milestone.
Missing table/invalid key/transaction conditions fail closed; the
cleanup worker retains bytes and retries using fixed code
`path_fence_unavailable`.

The lock row is held through storage I/O and DB reference mutation within
the owning DB transaction. Rows must not be deleted to reclaim space
while the protocol is live: delete/reinsert creates a new lock identity.
Do not change APP_KEY without a coordinated digest and encrypted-Outbox
rotation. Monitoring/logs must contain only fixed reason codes, never raw
file paths or the HMAC secret.

**Coverage is incomplete:** ordinary Inbox/Study Scope/Recall uploads,
Career path integrations and external SQL/storage writers do not yet
participate. The former MySQL gap/next-key locking query risks and
same-byte storage-version race remain; a path-lock pilot cannot fix them.
Review the current, versioned
`G3_PRIVATE_FILE_PATH_FENCE_IMPLEMENTATION_SPEC_2026_10_14.md`
before any further changes. G3 remains OPEN.

## 11. Opt-in upload writer coordination (G3-R12)

The same disabled-by-default `CANOVIA_PRIVATE_FILE_PATH_FENCE_ENABLED`
now guards native Inbox upload, Study Scope capture, Recall single and
Resource-linked upload, and multi-file Recall batch. Each storage key
is preflighted under its path fence so a pre-existing object cannot be
overwritten. Newly claimed files and DB references share a transaction;
failure cleanup runs before releasing locks and never deletes keys
that pre-existed the attempt. Fixed warning reason codes, not paths or
usernames, describe failed rollback cleanup.

**Do not enable in production:** a process crash between physical write
and commit still produces an orphan, an uncoordinated actor can bypass
the path lock, and Career/external object writers remain outside the
contract. Neither a successful upload nor this pilot authorizes
automatic Outbox retries, broad object scans, extra production DDL,
or production deployment. New code is not a recovery inventory.

## 12. G3-R13 opt-in prewrite upload intent recovery (NO automatic deletion)

The third additive G3 migration
`private_file_upload_intents` records one encrypted path + keyed
digest per planned private object write. The feature flag
`CANOVIA_PRIVATE_FILE_UPLOAD_JOURNAL_ENABLED` is **OFF by default**,
separate from the equally disabled path-fence flag. Production Aiven
migration, flag enablement, Render deploy, CLI execution against
production data and destructive recovery require **separate owner and
operator approval**.

In isolated, disposable development/staging environments, run
`php artisan canovia:private-upload-intent-health --json` for
redacted aggregate reporting only, optionally `--strict` for
attention-status exit 2. The command does not decrypt private
paths, list storage keys, run cleanup, repair DB references, or
trigger a scheduler. It is read-only.

- `prepared`: durable prewrite claim that may represent an active
  request, a crash, an aborted request or bytes that were never written.
- `prepared_stale_24h`: candidates for human investigation, not
  automatically orphaned files.
- `committed`: upload DB transaction returned and settlement succeeded.
- `needs_review`: upload failed, and byte cleanup was only best effort;
  an operator must establish object and reference state.
- `unknown_status` or `schema=unavailable`: fail closed; do not infer
  there are no pending writes.

Do not purge intent rows or rotate APP_KEY independently: the keyed
digest also prevents ABA reuse, and the ciphertext needs matching
decryption keys. Investigate all native and external writer paths and
provider object-generation semantics before defining a safe reaper.
No retention job or automatic delete is installed under R13.

## 13. G3-R14 aggregate upload-intent reconciliation preview

The explicitly gated
`CANOVIA_PRIVATE_FILE_UPLOAD_RECONCILE_PREVIEW_ENABLED` switch is
**OFF by default**, and this observer refuses production runtime
even if all flags are set. Only in an isolated, disposable, consenting
development fixture with R11/R13 gates enabled may an operator run:

`php artisan canovia:private-upload-reconcile-preview --json --limit=10`

Limit must be 1–20; output contains *only* fixed aggregate observation
categories. Eligible records are older-than-24-hours prepared intents
and all needs-review intents; fresh/prepared and committed rows are
excluded. It compares decrypted path fingerprint, fence row presence,
references across Inbox/Recall/Career, and targeted object existence.
It does NOT print paths/IDs/owners, list object storage, download bytes,
write DB state, lock a fence, or delete an object.

Interpret all four reference/object combinations as **time-of-check
observations only**. Do not label object-without-reference "orphan".
Missing schema, unreadable ciphertext, rotated key, DB/storage errors
or identity mismatch are non-destructive blockers. A missing fence row
may mean a writer crashed before its lock was created, not an absent
or corrupt object. Do not enable this command in production, add a
cron/queue job, build an automated delete path or extract secret paths
for ad hoc manual deletion.

Follow the full acceptance and limitations in the R14 §9
implementation specification. Three production G3 migrations and
complete writer coverage remain unapproved. G3 stays OPEN.

## 14. G3-R15 weak-metadata consistency pilot

Optional `CANOVIA_PRIVATE_FILE_UPLOAD_CONSISTENCY_PROBE_ENABLED`
is **OFF by default**, requires the R11/R13/R14 development-only opt-ins,
and cannot bypass the production deny. It uses the existing
`canovia:private-upload-reconcile-preview --json --limit=10` command.
It does not create an additional operator command, endpoint, timer, or
destructive recovery path.

For the R14-selected encrypted intents, R15 makes two separate observations
of the source reference booleans, an already-existing fence row, and targeted
object metadata (exists, size, last modified). Only aggregate fixed keys are
returned: `consistency_probe` and
`consistency.weak_pair_unchanged/change_observed/unverifiable/generation_verified`.
The last field is **always 0**: this release verifies no immutable storage
generation. If the pair changes or storage metadata cannot be read, the R14
category is `unverifiable`; never infer absence or deletion eligibility.

**Do not use an unchanged weak pair to reclaim bytes.** Identical
size/mtime cannot detect all replacements or object-generation ABA.
Provider-specific generation tokens, conditional version-bound deletion,
coordinated every-writer fencing, external writer isolation, and explicit
operator signoff are still separate, unsatisfied safety gates. Production
Aiven/Render/live database operations remain out of scope.

## 15. G3-R16 offline provider-generation readiness contract

`CANOVIA_PRIVATE_FILE_GENERATION_CONTRACT_ENABLED` is OFF by default,
and this developer-only inventory refuses production execution.
In a disposable local fixture only, optionally run:

`php artisan canovia:private-file-generation-contract --json`

The command reads solely the configured filesystem driver and prints
redacted **capability gaps**. A local driver lacks provider-generated
immutable object version IDs. An S3 driver only demonstrates a possible
adapter configuration: it does not connect to a bucket, verify
Versioning status, inspect credentials, capture upload VersionIds,
exercise If-Match, check policy or authorize deletion.

Never interpret matching ETags, equal file bytes/size/mtime, or two
synthetic equal VersionIds as evidence that an object may be reclaimed.
Version-specific delete and current-key conditional delete have
different semantics. `null` VersionId can be reused when versioning
is not enabled or is suspended. The operator must not turn on G3
physical deletion, configure automatic retry/cron, provision S3 or
alter Aiven/Render based on this static report. Provider and
generation-aware deletion require a later, owner-reviewed release gate.

## 16. G3-R17 synthetic ownership-to-generation binding (NO LIVE USE)

A fourth independent migration adds
`private_file_generation_evidence`. It is **not approved for live
Aiven execution** and is unrelated to any production deployment. The
separate `CANOVIA_PRIVATE_FILE_GENERATION_EVIDENCE_ENABLED` flag remains
OFF by default. The fixture-writing API additionally hard-requires
`APP_ENV=testing` and refuses production even if the flag is set.

In a disposable test database only, a committed upload intent may be
associated with exactly one matching Inbox / Recall / Career owner and
an **encrypted synthetic** VersionId. This establishes a *database
identity model*, not proof that an S3 provider returned or retained that
VersionId. A renamed/deleted owner, corrupted ciphertext, HMAC/key
change, bucket change, epoch change, or legacy intent without evidence
must result in a non-authoritative/blocked status.

`php artisan canovia:private-file-generation-evidence-health --json`
returns only aggregate status and synthetic row counts. It does not
decrypt tokens for display, inspect private files, enumerate provider
objects, invoke a scheduler, or mutate DB/Storage. Neither its output nor
a successful synthetic inspection grants object deletion. Do not
enable a real provider capture, migrate live schemas, rotate `APP_KEY`,
or delete old path-fence/journal/evidence rows without a separately
reviewed migration and recovery design. Four independent G3 schema
gates now require operator approval; G3 remains OPEN.

## 17. G3-R18 synthetic receipt contract (not production or deletion-ready)

`CANOVIA_PRIVATE_FILE_WRITE_RECEIPT_HANDOFF_ENABLED` is **OFF
by default**; the receiving API also demands a disposable
`APP_ENV=testing`, existing R11/R13/R17 opt-ins, and the synthetic
R17 data model. This is an automated contract fixture, not a new
operator CLI, real S3 adapter, active upload integration or release
procedure. No storage object is written or removed, and no provider
credentials or SDK calls are involved.

The fixed-field simulated `PutObject` response must match the
committed journal and owner; the resulting record always has
`evidence_origin=synthetic_fixture`. Do not interpret the plain-text
origin label, VersionId-shaped token, success HTTP code, request ID,
successful encrypted record or matching ETag as provider attestation.
The output always denies generation proof and deletion approval.
Never enable this path for live traffic or change cleanup worker
behavior based on these records. Four separate G3 migration approvals,
object-generation retention, key rotation, provider IAM/versioning
review and complete writer fencing remain unresolved.

## 18. G3-R19 synthetic crash-boundary acceptance only

The extra `CANOVIA_PRIVATE_FILE_SYNTHETIC_CRASH_MATRIX_ENABLED`
flag is OFF and the driver refuses any runtime outside
`APP_ENV=testing`. It makes **no physical storage write** and
does not register a scheduled or operator-facing recovery job.
Its simulated "write acknowledgement" is never provider proof.

Disposable PG16 and MySQL8.0/8.4 tests create synthetic journal and
owner rows at independent transaction boundaries, and halt
deterministically after prepare, synthetic write acknowledgement,
owner commit, journal settlement or synthetic handoff.
`review(intentId)` returns redacted status only. A prepared row without
an owner is ambiguous **both before and after synthetic write ack**;
it is neither "never written" nor "orphan proven." Committed owners
without settled journals and committed journals without evidence must
also be reviewed, not automatically retried.

Never turn any R19 state into real upload retry, object scan,
Storage::delete, CLI cleanup or production enablement. The tests
demonstrate *which states remain unsafe*, not that real process
crash durability has been established. Four G3 production schema
migrations and full provider/source-lane rollout gates remain open.

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
immediately before `Storage::delete`. If any reference appears while the
storage checksum is read, the file is retained and the obsolete outbox
intent is removed. If the second query is unavailable, deletion stops and
the pending row gets `reference_check_unavailable` retry backoff.

This is a narrow defense-in-depth improvement, **not a global lock**.
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

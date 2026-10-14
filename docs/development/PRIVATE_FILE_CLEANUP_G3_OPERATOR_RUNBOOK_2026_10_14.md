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

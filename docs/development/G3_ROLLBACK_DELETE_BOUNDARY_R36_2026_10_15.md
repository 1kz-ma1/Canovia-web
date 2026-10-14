# G3-R36 — Private File Rollback Deletion / Legacy Writer Audit

**2026-10-15 | Platform | G3 OPEN | Development-main CI only**

## Starting point and finding

R35 made the *durable cleanup outbox* quarantine external/unrecognized
storage providers instead of performing a path-only delete. R36 audited
the remaining first-party direct rollback calls by searching for
`Storage::delete(` and `Storage::disk(` in the application tree. This
audit is a source search with a bounded scope, not a static whole-program
proof against aliases/dynamic adapters.

| Caller | Prior behavior | R36 behavior |
| --- | --- | --- |
| `PrivateFileUploadService::storeAndPersist` | Delete every claimed key on failed DB owner persistence | Local: original rollback delete. Non-local: do **not** delete a path or version; persist intent as `needs_review` |
| `StudyScopeCaptureController::store` legacy | Upload then unlink on persistence failure | Require configured local driver before writing; local rollback retained |
| `StudyRecallCandidateController::extractFromResource` legacy | Upload then unlink on owner failure | Require local driver before writing; local rollback retained |
| `StudyRecallCandidateController::extractBatch` legacy | Batch upload, then delete path array on failure | Require local driver before any batch write; local rollback retained |
| `InboxRecallMaterialTransferGuard::copy` | Copy while locking DB and best-effort delete after failed transaction | Require local driver before writing a copied private file; no remote path delete |

The additional legacy `StudyRecallCandidateController::extract`
(non-Resource) single upload is also restricted before write.

New `PrivateFileStorageBoundaryService` checks the configured
`filesystems.default` **and** `filesystems.disks.<disk>.driver`.
Only `local` is permitted for non-journaled legacy copies/writes or
path-only rollback deletion. Missing, aliased remote or unknown drivers
fail closed. When declining a rollback deletion, logs contain only a
fixed reason `provider_generation_delete_unverified`, no user path,
bytes, exception details or identifiers.

For opted-in `PrivateFileUploadService`, non-local writes may still
be exercised in isolated R34-style runs **only when the durable
prewrite upload intent is enabled**. On failure the object is retained
and the durable journal is moved to `needs_review` by the existing
outer error path. A journal settlement failure leaves `prepared`;
neither state is deletion authority. Missing journal rejects external
upload before storage access. Non-local success still depends on all
other independent provider safety gates, so this is **not** permission
to turn on S3 in production.

## Evidence and remaining risk

- CI target: disposable PostgreSQL 16 and MySQL 8.0/8.4,
  `PrivateFileRollbackBoundaryR36Test`, existing G3 private file and
  capture/recall suites; R34 cross-layer S3-compatible trial rerun when
  uploader or boundary changes.
- Synthetic driver tests prove zero remote adapter calls for denied
  operations and durable `needs_review` with preserved bytes after
  owner rejection. They do **not** attest an AWS generation.
- No production migration, Render deploy, AWS account, IAM or Aiven
  access, S3 DeleteObject, autonomous reconciliation, new SDK, deletion
  override, or billing changes.
- Remaining gap: programmatic unindexed/indirect Storage delete calls,
  provider-specific true generation-safe deletion, external competing
  writers, authorization and writer inventory, and physical host crash
  / production device E2E require separate review.
- R27–R31 remain the authoritative owner/security approval framework.
  No owner GO for even a disposable live AWS test has been established.

**G3 OPEN; provider_attested=false;
delete_authorized=false; production_migrations_approved=false.**

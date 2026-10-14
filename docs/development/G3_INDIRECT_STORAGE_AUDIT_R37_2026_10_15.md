# G3-R37 — Indirect Storage Boundary Audit and No-Provider-I/O Hold

**2026-10-15 | Platform | G3 OPEN | no provider approval**

## Audit finding and bounded change

R35 quarantined S3/nonlocal deletion only *inside* the durable cleanup
worker; the cleanup registration/enqueue phase still called
`Storage::disk(...)->exists/get` to hash remote bytes. Even for entries
that could never be safely removed, this could initiate provider I/O.

R36 guarded the Study Scope and Recall legacy upload routes, but the
normal InboxController feature-OFF upload branch still invoked
`UploadedFile::storeAs` on the configured default disk **without a
prewrite intent journal or external-driver gate**.

R37 closes these two first-party gaps:

1. **Inbox legacy upload**: call the existing
   `PrivateFileStorageBoundaryService::requireLocalLegacyWriter` before
   `UploadedFile::storeAs`. This denies configured S3 aliases and
   undefined/unknown drivers before provider resolution. Text-only
   Inbox intake does not need storage and remains usable.
   The coordinated upload route and existing local behavior stay unchanged.
2. **Durable cleanup enrollment**: when the configured default driver
   is not `local`, register only an *encrypted, quarantined intent*
   with `expected_sha256=null`, stable reason
   `provider_generation_delete_unverified`, no retry and no
   HEAD/GET/DELETE. Respect the enclosing owner DB transaction:
   rollback removes that intent. Local disk still checks existence and
   content SHA-256, preserving the existing outbox and after-commit
   semantics.

The R35 worker's own nonlocal quarantine remains independent
defense-in-depth against historical pending entries.

## Observed code-path inventory

Bounded search of `app/` at base
`992dc5779ae693edfd023f3ff79e3d4db8328942`:

| Operation family | First-party paths | R37 disposition |
| --- | --- | --- |
| Explicit `Storage::delete` facade and `->delete` on disks | PrivateFileStorageBoundaryService, PrivateFileLifecycleService, PrivateFileUploadService | Guarded in R35–R36: local-only path deletion or external quarantine |
| Upload `UploadedFile::storeAs` | InboxController, StudyScopeCaptureController, StudyRecallCandidateController, PrivateFileUploadService | Inbox feature-OFF now gated; study feature-OFF already gated R36; coordinated external requires journal R36 |
| `Storage::put` copy | InboxRecallMaterialTransferGuard | Local-only copied-file writer since R36 |
| Storage `get` / `exists` / `response` | Inbox/Study/Recall/Career controllers, AI commit fences, preview & reconciliation | Mostly read-only; review source-version consistency separately. R37 removes unnecessary external reads only from cleanup enrollment |
| Durable cleanup enrollment | PrivateFileLifecycleService::deleteAfterCommit | Now no remote adapter operations, even if upload journal/fence flags differ |

This is a **bounded source search**, not complete static proof of dynamic
facades/third-party storage APIs. Other direct local-only file writers,
unknown providers and HTTP source-read races remain explicit gaps.

## Verification/authority limits

Dedicated `PrivateFileIndirectStorageHoldR37Test` checks:
remote/aliased/unconfigured Inbox upload denial without `Storage::disk`,
text-only Inbox allowance, local legacy Inbox success, remote cleanup
enrollment without adapter operations, durable encrypted quarantines,
and transaction rollback. CI should run tests against disposable PG16
and MySQL 8.0/8.4 alongside R36 and R34 existing regressions.

No new migration, provider SDK, cloud credentials, AWS/Aiven/Render
resource change, CI secret, billable resource, real user file, S3
DeleteObject, production promotion, or generation safety claim.

Open technical/owner gates: provider-origin attribution, verified
generation identity under external writes/ABA, conditional deletion
semantics, trust and expiry, all indirect/custom disk writers and
app source-read races. R27–R31 remain the provider review and
approval framework. No approval to run against a real AWS account.

**G3 OPEN; provider_attested=false; delete_authorized=false;
production_migrations_approved=false; owner_provider_go=false.**

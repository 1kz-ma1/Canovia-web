# G3-R40 — External Provider Evidence: Operator GO / NO-GO Checkpoint

**2026-10-15 | Platform | G3 OPEN | Current decision: HOLD**

**Approval and next action:** [Issue #681](https://github.com/1kz-ma1/Canovia-web/issues/681). This document records the gap and decision boundary. It does **not** grant provider access, budget, use of an identity, conditional deletion, or a migration/deployment.

## Why R40 does not add another synthetic classifier

R31 explicitly determined that plausible offline contracts cannot substitute for independently authenticated provider evidence. R32–R34 produced real localhost disposable S3-compatible HTTP/process-restart and MySQL crash experiments; they established useful system behavior, not AWS provenance or authorization. R35–R39 then addressed concrete app vulnerabilities: external path deletion, rollback and legacy writes, remote outbox registration, AI input-byte mismatch and stale pre-provider authorization.

At R40, another check of self-authored manifests would not advance the missing trust root. Therefore R40 adds **no** new migration, storage worker, SDK, synthetic provider verifier, runtime capability flag or live cloud call. It is a checkpoint for one independently approved non-destructive evidence phase.

## Engineering evidence and unproven claims

| Area | Already observed / implemented | Missing, independently reviewable evidence |
| --- | --- | --- |
| Versioned HTTP object history | R33/R34 isolated S3-compatible server, restart, 3 object generations, zero delete markers | Authentic approved AWS bucket versioning/IAM, retained VersionId history and provider-original responses |
| MySQL + S3-compatible process failures | R34 prewrite, midtransaction, postcommit and success, with SIGKILL and fresh process verification | Approved real-provider observations of acknowledged/ambiguous PUT, original caller identity and external writer races |
| Durable cleanup | R35 local-only worker deletion; R37 external outbox enqueue without provider I/O | Provider-generation-aware ownership, safe deletes under noncooperating writers and a separately approved destructive protocol |
| Upload rollback | R36 remote ambiguous objects retained, durable intent marked for review | Provider-side writer identity, object key/generation attribution and remediation procedure |
| AI source consistency | R38 exact Inbox input-byte seal, R39 fresh DB/owner/editor/entitlement pre-provider admission | Atomicity across independent provider writers; post-admission revocation timing; immutable provider version references |
| Audit / approval | R27–R31 offline artifact/checklist/audit all pending; see original records | Authenticated owner GO, independent security/storage reviewer, enforceable budget/TTL/scope and restricted evidence custody |
| Live rollout | Only integration to development `main` | Explicit Aiven migration operator approval, production release SHA, Render/iPhone/Web acceptance |

## Actual AWS semantics; do not substitute one identifier for another

**Current-version conditional delete** `DeleteObject` with `If-Match: <ETag>` checks the *current* object version; `412` means the ETag did not match, and concurrent operations can produce `409`. An `If-Match: *` is merely an existence test, not owner or version continuity. Matching the same content after A→B→A may not distinguish generations. Relevant AWS specification: https://docs.aws.amazon.com/AmazonS3/latest/userguide/conditional-deletes.html

**Version-specific delete** with `?versionId=` targets the named version, ordinarily permanently removing that version in a versioned general-purpose bucket; unqualified deletion commonly adds a delete marker instead. These are **destructive, distinct operations** and are not approved for Phase 1. AWS API: https://docs.aws.amazon.com/AmazonS3/latest/API/API_DeleteObject.html

**Version-aware read** uses a `VersionId` returned by write/read and `HeadObject` version metadata where supported. This can assist correlation but does not make application data atomic with the bucket, prove write-time cryptographic authenticity, or exclude an external writer. AWS `HeadObject`: https://docs.aws.amazon.com/AmazonS3/latest/API/API_HeadObject.html

**Bucket policy** can enforce presence of `If-Match` for conditional delete. That is *not* necessarily an immutable version-ID comparison or substitute for writer identity. No policy changes are authorized. AWS: https://docs.aws.amazon.com/AmazonS3/latest/userguide/conditional-delete-enforce.html

## Phase 1 — NOT authorized until explicit independently verified GO

After the product owner and security/storage operator approve the **exact disposable target and action scope** out of band:

1. Verify a separate, empty, newly owned test account/bucket; region, bucket versioning, identity and IAM permissions **independently of the application**. Retain originals securely, not in Git or ChatGPT.
2. Establish an expiring least-privilege principal, independent account/billing control, enforceable request/object/time/cost caps, operator stop authority and independently tested credential revocation. R29's proposed amounts are **not actual authorization**.
3. Only then perform non-destructive synthetic **PUT, GET, HEAD, ListObjectVersions** against that exact new bucket. Include same-body A→B→A with distinguishable VersionIds and a controlled process restart. Collect original request identity, protocol/response metadata, object correlation and negative evidence cases; never claim a self-reported ETag/HMAC is independent attestation.
4. Review the original evidence out of band with security/storage; publish only carefully redacted findings. Missing/ambiguous trust, unexpected writer, versioning suspension, policy drift or budget limit means **ABORT / HOLD** and revoke the trial identity.
5. Deletion, `DeleteObject`, `DeleteObjects`, object-version deletion, lifecycle rules, IAM/policy mutation, existing buckets, customer files, production DB migrations and Render are **not part of Phase 1**. A separate Phase 2 owner + security authorization is necessary before *testing* any deletion.

For the detailed 11-category collection plan, owner checklist, negative scenarios and custody, reuse [R27](G3_PROVIDER_EVIDENCE_HANDOFF_R27_2026_10_14.md), [R28](G3_PROVIDER_COLLECTION_PLAN_R28_2026_10_14.md), [R29](G3_DISPOSABLE_APPROVAL_PACKET_R29_2026_10_14.md), [R30](G3_OWNER_SCOPE_CHECKLIST_R30_2026_10_14.md) and [R31](G3_EXIT_DECISION_R31_2026_10_14.md). Do not create a second apparent approval source.

## R40 acceptance and stop condition

- The operator decision remains independently **pending**, expressed as [Issue #681](https://github.com/1kz-ma1/Canovia-web/issues/681); absence of separate verifiable approval means default HOLD.
- No external SDK, IAM/console/cloud resources, GitHub secrets, new expenditure, storage deletion, Aiven DDL, Render deploy or production branch changes.
- Source-level retrospective does not equate to real-cloud verification. Do not mark G3 closed or enable auto-cleanup because this documentation or offline CI passed.
- Stop iterative surrogate evidence work here. The next meaningful step is owner/security approval and live authorized, *non-destructive* evidence collection, or a separate product priority while G3 remains parked.

**G3 OPEN. provider_attested=false; delete_authorized=false; production_migrations_approved=false; owner_provider_go=false.**

# G3-R35 — External Provider Deletion Hold and Evidence-Ready Next Decision

**2026-10-15 | Platform | G3 OPEN | Not a provider trial / not an owner GO**

## Why this stage exists

R33 measured real S3-compatible object generations across server SIGKILL.
R34 measured real disposable Laravel process crash states, MySQL
rollback/commit, and retained S3-compatible versions after a storage restart.
That is **not** AWS S3, external writer fencing, independent write-time
attestation, or authorization to delete a particular object generation.

R31 intentionally stopped adding synthetic verdict layers to compensate
for missing external evidence. R35 adds exactly one tangible application
safety control, and records what can and cannot be approved next. It
does **not** introduce a fake provider verifier or another synthetic
proof classifier.

## Concrete application risk and R35 patch

Before R35, \`PrivateFileLifecycleService::processOne\` compared a
stored SHA-256 with bytes read from \`Storage::disk(...)->get(path)\`, then
performed a second database reference check and finally
\`Storage::disk(...)->delete(path)\`. On an external object store, another
writer could replace the path between the check and delete; a same-body
ABA write could also pass the SHA check without preserving the
original object generation. A database fence only coordinates
participating application writers. It cannot fence arbitrary external
clients.

**New hard gate:** after validating the cleanup entry's configured
disk identity, read \`filesystems.disks.<disk>.driver\`. Only the
existing \`local\` driver may execute the legacy path-delete branch.
Any non-local, unconfigured or unknown driver is
\`quarantined\` with the fixed reason
\`provider_generation_delete_unverified\`, *before*
opening the storage adapter, consulting provider bytes, acquiring
optional path fences, or deleting the path. No retry is queued.
There is no config/environment bypass or S3 allow flag.

This is deliberately narrower than a claim of full G3 safety:
**R35 protects only the durable cleanup worker**. In particular
\`PrivateFileUploadService\`'s rollback cleanup and other direct
Storage calls are **not** covered by this hold. Therefore do not
change the production default disk to S3 or enable the coordinated
file pilot on external storage on the strength of R35. A separate
audit and approved implementation will be necessary.

Feature tests cover \`s3\`, an aliased S3 disk, a missing adapter,
zero storage calls, no automatic retries and continued local cleanup
on disposable PostgreSQL/MySQL. No migration or additional dependency.

## Official AWS capability boundary (research, not a live test)

Official references, checked 2026-10-15:

- Conditional deletes: https://docs.aws.amazon.com/AmazonS3/latest/userguide/conditional-deletes.html
- DeleteObject API: https://docs.aws.amazon.com/AmazonS3/latest/API/API_DeleteObject.html
- PutObject API: https://docs.aws.amazon.com/AmazonS3/latest/API/API_PutObject.html
- Bucket-policy conditional-delete enforcement:
  https://docs.aws.amazon.com/AmazonS3/latest/userguide/conditional-delete-enforce.html

AWS documents \`If-Match: <ETag>\` and \`If-Match: *\` for
conditional deletes of the **current object version**. An ETag match
is NOT an immutable generation match, and a same-body A->B->A pattern
must not be accepted as version continuity. An unqualified delete in
a versioned bucket normally creates a delete marker. Deletion with
\`versionId\` targets a particular version for permanent deletion,
with separate permissions and lifecycle implications. These mechanisms
have different semantics; **neither is automatically a complete
end-to-end Canovia reference/owner fence**.

PutObject's \`x-amz-version-id\`, request IDs and SDK success are
valuable correlating data but do not by themselves establish
independently signed provider-origin proof or durable app-to-provider
atomicity. AWS documentation is not an observed approval, live IAM
verification, or a real negative race test.

## G3 capability acceptance and outstanding proof

| Gate | Observed evidence | Additional evidence necessary |
| --- | --- | --- |
| Local path-fence / durable outbox | G3 implementation + DB suites | Real operator load, all writer coverage, full lock order |
| S3-compatible generation history | R32/R33 real disposable HTTP | Authentic AWS bucket/versioning/IAM and external writer semantics |
| Laravel / DB / S3-compatible crash | R34 PR #661 / exact-head CI | Real authorized provider plus realistic outage/ACK ambiguity |
| Arbitrary external object-store path deletion | R35 unconditional quarantine in cleanup worker | Reviewed immutable generation/owner provenance, safe write fencing and audited operation model |
| Independent write-time provider attribution | No trusted proof | A separately approved attestation approach; do not invent one from headers |
| Conditional version-safe deletion | NOT authorized | Storage/security capability review, *separate destructive-test approval*, negative ABA/race tests |
| Production migrations & rollout | Five migration files in Git | Separate Aiven operator audit, backup/recovery proof, release/device gates |

R34's exact post-evidence test head:
\`372f5ed8a8c75ee8c335d90b2e027e4a97ce47ab\`,
GitHub Actions run \`38590328725\` (success), followed by
development-main merge \`cfa3ee04a2b7306557088fe0ed83e5d44016430a\`
(PR #661). Not production, and not real AWS S3.

## Next external-provider experiment: HOLD until separate owner approval

The already existing R27 inventory, R28 collection plan, R29 approval
request, R30 owner checklist and R31 exit audit remain the canonical
authorization framework. Do not duplicate them or regard a successful
offline checker as approval.

**First separately authorized phase (non-destructive only):**
independently authenticate the human operator and identify an entirely
separate throwaway cloud account, region, bucket and expiring principal
outside Git/chat; confirm budget, time, request/object ceilings,
secure evidence custody and revocation/abort procedure. Only after
independent owner/security sign-off and scope enforcement may the
operator inspect versioning/IAM, PutObject synthetic bytes, and
GET/HEAD/list their own synthetic versions. Record exact request/body,
response correlation, IAM denies, supported API behavior, access
auditing and immutable provider-identity evidence in restricted
custody. R29's proposed limits are **not** approved spending.

**Second phase (not authorized by first phase):** independent review
of conditional-delete/versionId semantics, external concurrent
writers, ABA and rollback/retention risk; explicit separate
destructive test approval before *any* DeleteObject, DeleteObjects,
DeleteObjectVersion or lifecycle operations, even in disposable AWS.
If provider-origin generation integrity cannot be independently
verified, the correct outcome is **HOLD / manual quarantine**, not
a relaxed trust classifier.

**No cloud resource is created or modified by R35.** No GitHub secret,
live AWS credentials, Render, Aiven, CI-hosted external provider,
external billing, operator GO, production migration, release
promotion, auto cleanup or retry is granted.

**G3 OPEN. provider_attested=false; delete_authorized=false;
production_migrations_approved=false; owner_provider_go=false.**

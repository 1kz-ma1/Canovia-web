# G3-R29 — Disposable Provider Approval Request Packet

**2026-10-14 | OWNER DECISION REQUIRED | G3 OPEN**

R27 inventories independently reviewable, pinned evidence references.
R28 specifies an unapproved collection plan and eleven negative-test
categories. R29 turns that proposal into a **bounded owner-decision
package**, NOT an authorization, provider verifier or runner.

## Approval questions for the owner (out of band)

A human owner must explicitly identify the disposable test account,
region, new throwaway bucket, temporary principal, permitted actions,
cost ceiling, time window, cleanup/credential-revocation procedure,
data retention and stop authority **outside the repository**.
The fields in the offline packet are deliberately *placeholder
classes*, never real account IDs, bucket names, signatures, personal
information, tokens or instructions for accessing existing resources.

Proposed caps in the sample schema: $10 USD, 60 minutes, 5 synthetic
objects, 100 requests. These are **not an approved budget** and are
bounded by validator safety maxima of $25, 120 minutes, 10 objects,
200 requests. The owner may request a lower limit. Raising these
engineering safety caps requires a separately reviewed change and
does not itself confer spending authority.

## Fixed proposed action scope

The packet lists only inspection of a brand-new test bucket,
Put/HEAD of disposable synthetic objects, controlled restart of a
disposable test process, and a separate second-phase *proposal* to
assess generation-conditional deletion **only if** a provider
capability review and explicit additional owner/security approval
permit it. These labels are not executable commands. Do not use
them to access production, customer data, staging, Aiven, Render,
existing buckets, paid infrastructure or shared IAM. Never assume
S3 VersionId/ETag/request ID provides cryptographic write attestation
or that a provider supports safe conditional deletion.

Required stop triggers: unidentified root/issuer, missing approval,
budget/time threshold, unexpected account/bucket/principal, missing
versioning/conditional-operation proof, writer/generation mismatch,
exposed credentials or failed recovery evidence.

The abort sequence must call for revoking the temporary identity,
stopping tests, quarantining disposable objects **without blind
deletion**, preserving original evidence in restricted custody and
reopening manual security/owner review. None of these steps is
performed by the checker.

## Offline contract

```text
python3 scripts/ops/g3_disposable_approval_packet.py --repo . --packet /private/location/r29-request.json
```

The packet schema is
`canovia.g3.disposable_approval_packet.v1`. It embeds a full
**R28 collection plan** at the same immutable candidate SHA.
Generate a proposed skeleton locally by calling
`scripts.ops.g3_disposable_approval_packet.template(sha, r28_plan)`.
The validator rejects missing/foreign commits, mismatched R28 scope,
unknown fields, duplicate JSON keys, unexpected actions, improper
custody, relaxation of stop conditions, false owner/security
attestation, oversized budgets and live-provider/staging/production
requests. It prints a fixed redacted result.

A completely valid packet produces `OWNER_DECISION_REQUIRED`
and process exit code 2. Invalid/malformed payloads produce
`INVALID`; unavailable pinned Git history yields `BLOCK`.
All verdicts retain:
`owner_approval_verified=false`,
`security_review_verified=false`,
`provider_access_authorized=false`,
`real_provider_test_executed=false`,
`budget_authorized=false`,
`conditional_delete_authorized=false`,
`production_migration_authorized=false`,
`release_authorized=false`,
`cleanup_authorized=false` and `production_modified=false`.

The checker performs only local Git object checks through R28,
without networking, secrets, reads or mutations in live services.
A human's later approval **must not be inserted into this
untrusted offline input to bypass the checker**. Separate
operator procedures and explicit owner permission are needed
before any future disposable provider operation.

## Proof remains outstanding

No real AWS/S3 provider trust root, response signature verifier,
versioning/IAM guarantee, durable anti-replay protection, external
writer fencing, ABA/generation-conditional safe delete, physical
restart test, independently verified evidence, or operator sign-off
is established by R29. Five previously added G3 schema migrations
remain **unapproved for production Aiven**. No production branch,
Render, S3, IAM, billing, DB, customer data, retry or deletion is
changed. **G3 OPEN.**

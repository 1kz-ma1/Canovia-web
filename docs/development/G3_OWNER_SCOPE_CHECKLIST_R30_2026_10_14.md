# G3-R30 — Owner Checklist and Hypothetical Scope-Drift Preflight

**2026-10-14 | G3 OPEN | OWNER APPROVAL NOT VERIFIED | No provider access**

R27 defined an eleven-requirement evidence inventory, R28 defined
a proposed collection plan, and R29 assembled an unapproved disposable
trial request. R30 adds an **owner-facing checklist** and an offline
model of how a future operation boundary should reject proposed
out-of-scope activity. It does not establish a real runtime
enforcement point or authenticate owner/security sign-off.

## Decision checklist (all require independent human confirmation)

| Checkpoint | Reviewing role | Independent question |
| --- | --- | --- |
| `owner_identity_out_of_band` | Owner | Has the actual product owner been authenticated through an independent channel? |
| `isolated_account_and_region` | Owner | Is the separate non-user account and region confirmed and controlled? |
| `new_bucket_no_user_data` | Storage | Is the bucket newly created, empty and exclusive to disposable synthetic objects? |
| `temporary_principal_least_privilege` | Security | Is an expiring scoped identity configured with auditable permissions and prompt revocation? |
| `budget_and_expiry` | Owner | Are the *actual approved* spend/time/request limits and stop authority documented? |
| `operations_and_second_phase_fence` | Storage | Are only independently authorized test actions allowed, with destructive generation tests separately approved? |
| `secrets_and_evidence_custody` | Security | Are original cloud evidence and secrets restricted outside Git, with reviewed redactions? |
| `stop_and_quarantine_procedure` | Platform | Is abort, credential revocation, object quarantine and safe rollback independently rehearsed? |
| `generation_and_writer_safety` | Storage | Are VersionId/ETag, concurrent writer, ABA and conditional operation semantics explicitly reviewed rather than assumed? |
| `independent_security_signoff` | Security | Is the security review signed through a channel independent of the candidate manifest? |
| `explicit_owner_go` | Owner | Has the owner separately approved a specific versioned disposable experiment? |

Every entry in R30's checker is **`pending_out_of_band`**. It rejects
a manifest claiming an approval, signature, completed review, identity,
or a newly added permissive checkpoint. The real human decision
does not get converted to a self-declared JSON Boolean.

## Proposed operation boundary and limits

R30 can examine **hypothetical** numbered operations, restricted to:

- Inspect configuration of a disposable test bucket
- PUT a synthetic disposable object
- HEAD a synthetic disposable object
- Restart a disposable *test process*, not a production worker

R29 also mentions a *later proposal* to test conditional-generation
deletion. R30 **never** includes that action in its allowed
operation simulation: it requires a separate security/capability
assessment and separate owner authorization. Production objects,
shared IAM, customer data, uploads/deletes in existing buckets,
production deployments and Aiven DDL are always out of scope.

The checker compares a set of non-credentialed, *caller-estimated*
per-action request count, new-object count, cost (integer cents),
and elapsed time against R29's proposed caps. It rejects nonmonotonic
time, exceeding cumulative caps, target/data mismatches, inserted
operations, unexpected action fields, production references and
attempted execution phases. The source inputs are untrusted, so
passing these comparisons **does not establish real-time metering,
actual spending limits or live capability enforcement**.

A future actual run would require an independently authenticated
approval source, a credential-scoped cloud gateway, tamper-resistant
usage accounting, an atomic stop/fence, immutable generation controls
and a separate reviewed release. R30 implements **none** of that.

## Local syntax-only usage

```text
python3 scripts/ops/g3_owner_scope_review.py --repo . \
  --packet /private/location/r29-request.json \
  --review /private/location/r30-hypothetical-review.json
```

The R30 review schema is `canovia.g3.owner_scope_review.v1`. A
candidate can be prepared locally with
`scripts.ops.g3_owner_scope_review.sample_review(r29_packet)`.
It binds to the R29 packet's candidate SHA and canonical JSON
SHA-256. **The caller controls both packet and digest**, so their
equality is only a local consistency check and is not a signature,
authenticated consent or trust anchor.

An internally consistent package and review returns
`OWNER_CHECKLIST_PENDING`, **exit code 2**. Mismatches, unsafe
scope and missing evidence produce `INVALID`/`BLOCK`. Every result
prints only fixed checklist identifiers and redacted states, and
fixes all owner/security verification, external scope enforcement,
provider access, real spending, conditional deletion, retry/delete,
production migration/release and production mutation flags to false.

The script uses R29/R28's read-only local Git checks. No SDK,
network calls, provider credentials, DB/Storage I/O, IAM edits,
new migration, background worker, scheduler or real operation
interception occurs.

## Acceptance status

R30's isolated local-Git tests in Platform Offline Preflight verify
valid pending review, forged approvals, removed checkpoints,
duplicate entries, conditional deletion rejection, cross-account/
data/phase spoofing, cumulative limits, packet digest/commit drift
and no local Git mutation.

The five prior G3 migrations remain **unapproved** for production
Aiven. Real provider authenticity, versioning/IAM, durable replay,
external writers, generation-safe deletion and crash tests are not
established. **G3 OPEN.**

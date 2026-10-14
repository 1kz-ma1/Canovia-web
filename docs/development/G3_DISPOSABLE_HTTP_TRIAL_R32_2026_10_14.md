# G3-R32 — Disposable S3-Compatible HTTP Trial

**2026-10-14 | G3 OPEN | No AWS S3 account accessed | No destructive operation**

## Scope and isolation

R31's external-proof HOLD remains valid: there is no approved,
connected, separately provisioned AWS account/bucket/principal in
this workflow. The owner requested an isolated practical trial.
This R32 implementation runs a **real HTTP client/server exchange**
against a *short-lived Moto S3-compatible emulator*, not Amazon S3
or an independently administered storage provider. A GitHub-hosted
temporary runner starts the emulator on `127.0.0.1:5000` and
terminates it when the job ends. Objects and version history exist
only inside that runner.

The test uses Python 3.12, `boto3==1.40.70`, and
`moto[server]==5.2.2` in
`.github/workflows/g3-disposable-s3-http-trial.yml`.
Credentials are **fixed dummy strings**, explicitly passed to boto3,
never GitHub/cloud secrets. The test refuses to run unless
`CI=true`, the isolated environment gate is enabled, dummy
credentials match, no AWS session token/profile is set, and the
endpoint is exactly `http://127.0.0.1:5000`.
The workflow has `contents: read` permission only and no
AWS/Render/Aiven secret bindings. No production Render/Aiven/Canovia
session, identity or existing object is touched.

## HTTP experiments and acceptance

The isolated script `tests/ci/test_g3_ephemeral_s3_http.py` tests
the following, with actual boto3 API calls over localhost HTTP:

| Experiment | Acceptance invariant | What it does NOT establish |
| --- | --- | --- |
| Versioned A→B→A writes | Three distinct version IDs; previous payloads remain readable | Actual AWS provider signed receipt, durable cross-process recovery |
| Four concurrent writers | Four distinct versions; latest HEAD is one of the writes | Serialized intent/DB transaction safety or writer exclusion |
| Ambiguous response | Discarding the client-visible successful PUT response then repeating PUT yields another version | Real network drop; retry safety under a hard crash |
| Client reconnect | A new client reads the previous version | Server/storage power-loss durability |
| No versioning | Unversioned PUT has no usable version ID | Real account versioning/IAM |
| Metadata boundary | Request ID/Version ID exist but are NOT provider authentication | Write-time independent trust root |
| Endpoint/credential gate | Any non-loopback endpoint, different credentials/profile/token or missing CI gate causes local refusal | Live IAM trust boundary or network egress firewall |

No `DeleteObject` call, object teardown operation, conditional delete,
provider credential use, external request, S3 bucket policy or IAM
mutation exists in this suite. The process/container lifecycle
discards only runner-local emulator state at job completion.

## Evidence classification

Test outputs are **HTTP emulator behavior**, not provider attestation,
not real AWS S3 versioning/concurrency evidence, not full Canovia
Laravel lifecycle integration, and not authorized automatic cleanup.
Moto implements an S3 API approximation; differences with AWS
S3—including request authenticity, policies, concurrency, error
conditions and conditional mutation semantics—remain outside this
test's claim.

For a full G3 exit, a **new** owner-approved test in a disposable
AWS account would need authenticated independent provider evidence,
scoped identity/region/bucket and cost limits, reviewed writes/HEAD/
GetObject with real provider semantics, external writer and restart
fault injection, durable DB reconciliation, distinct authorization
for conditional deletion, and isolated post-test audit. No such
approval details or credentials are recorded in this repository.

**Do not close G3 based on R32. Five prior G3 migrations remain
production-unapproved. No production deploy or schema change.**

## Actual isolated run observed (R32 first trial)

- Workflow: [G3 Disposable S3-Compatible HTTP Trial](https://github.com/1kz-ma1/Canovia-web/actions/runs/38571152324)
- Tested source HEAD: `3dbffcded260e945475146c23e3879d0eaa29495`
- Outcome: **success** (all seven unittest scenarios in the
  ephemeral HTTP trial job completed successfully)
- Platform preflight, migration recovery and development prelaunch
  also reported success for this exact source SHA.
- This is an **observed GitHub Actions run**, not a live AWS run.
  The record does not verify IAM, independent provider response
  authenticity, server power-loss durability, nor safe conditional
  deletion; no AWS keys or live buckets were provided.

A future revision to the test source must produce a new exact-HEAD
successful run before the same conclusions are attributed to it.
**G3 OPEN; no production schema or release approval.**

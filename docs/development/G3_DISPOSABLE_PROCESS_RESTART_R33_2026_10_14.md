# G3-R33 — Disposable S3-Compatible Server Process SIGKILL and Persistent Generations

**2026-10-14 | Test-only scope | G3 OPEN | NOT AWS S3 provider attestation**

R32 demonstrated localhost HTTP generation behavior using Moto but not a
storage process crash/restart. R33 runs a **separate** S3-compatible
LocalStack server in an ephemeral GitHub Actions Ubuntu runner,
with a runner-local, temporarily mounted disk path. The process receives
SIGKILL and is started again using its **same mounted directory**.
Tests then independently re-read each previously acknowledged version.

The official image is versioned as `localstack/localstack:2026.08.3`.
It is run with `PERSISTENCE=1` and a dedicated runner-local mount at
`/var/lib/localstack`; this is **emulator snapshot persistence**, not
physical-disk fsync durability or a real AWS S3 service. No LocalStack or AWS endpoint
is publicly exposed: Docker binds port 4566 to `127.0.0.1` only.

## Actual phases and expected evidence

1. Runtime isolation refuses execution outside CI, with any AWS profile,
   session/web-identity/container credentials, altered dummy credentials
   or a non-exact localhost endpoint.
2. Start LocalStack against only a temporary runner-local `/data` mount.
   Create a randomized disposable bucket and enable versioning.
3. Perform **nine actual HTTP PUTs**: three A→B→A replacement generations,
   four concurrent writers to one key, and two identical replayed
   writes whose first application ACK is intentionally discarded.
   Read back each version, verify cryptographic content digests and
   check that there are no delete markers.
4. Kill the real LocalStack container with `SIGKILL`. Ensure its TCP port
   is unavailable; no S3 delete operation is issued.
5. Restart the *same container* with the *same disk*. Re-query
   versioning configuration, validate nine historical version IDs,
   content digests and missing delete markers through a fresh client,
   and perform one new PUT/GET after restart.
6. Remove only the temporary runner-local container on completion,
   leaving no external bucket, account or billable storage.

This is stronger than an application-client reconnect and tests real
service-process interruption, but **does not simulate physical host
power loss** or provide a durable-fsync guarantee. No DB transactions,
Canovia live queue, outbox replay, external AWS credential/IAM policy,
provider-signed write response, untrusted concurrent external accounts,
network partition or authentic AWS S3 operation is tested.

## Trust and safety boundary

- All operations use `http://127.0.0.1:4566` and fixed fake LocalStack
  credentials. No GitHub secret, AWS account, production Render,
  Aiven, real Canovia storage bucket or customer object is accessed.
- There is no S3 `DeleteObject`, no lifecycle cleanup call, no
  conditional-generation deletion, no bucket policy or IAM mutation.
- A discarded application-visible ACK is **not** a physical response
  packet loss. Distinct version IDs do not prove a trusted
  provider-origin attestation or ABA-safe deletion.
- No broader external access is authorized by this workflow.
- GitHub Actions job has `contents:read` only, and the workflow
  is run on R33-specific code/CI/document changes.

The acceptance record must distinguish **actual CI results for the
precise tested SHA** from unexecuted expectations. Even if all tests
pass, keep `provider_attested=false`, `delete_authorized=false`,
`production_migrations_approved=false`, and **G3 OPEN**.

Five previous G3 production Aiven migrations remain unapproved.
Future independent AWS verification needs a specifically approved
separate account/bucket, verified identity/IAM and request semantics,
operator log review, an isolated crash/DB reconciliation experiment
and separate authorization for any destructive generation experiment.

## First CI execution and changed test server

The initial PR #654 run `38575404147` could not start an older MinIO
image from Quay: the container registry returned an authorization error.
**No storage test was executed in that run.** The workflow was changed
to the official version-named LocalStack image, and subsequent results
must be evaluated against their own exact tested commit and CI logs.

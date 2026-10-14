# G3-R33 — Disposable S3-Compatible Server Process SIGKILL and Persistent Generations

**2026-10-14 | Test-only scope | G3 OPEN | NOT AWS S3 provider attestation**

R32 demonstrated localhost HTTP generation behavior using Moto but not a
storage process crash/restart. R33 runs a **separate** S3-compatible
MinIO server in an ephemeral GitHub Actions Ubuntu runner,
with a runner-local, temporarily mounted disk path. The process receives
SIGKILL and is started again using its **same mounted directory**.
Tests then independently re-read each previously acknowledged version.

The official MinIO Quay container was withdrawn or restricted and could
not be pulled in this CI. The isolated runner therefore uses an archived
historical MinIO Community build mirrored by a **third party**:
`dappros/minio:RELEASE.2025-09-07T16-13-09Z`. Its claimed
upstream origin does **not** establish independently verified artifact
provenance. The CI records the downloaded image manifest digest,
restricts Docker privileges, mounts only runner-local synthetic data,
passes only dummy credentials, and exposes port 9000 on localhost.
The experimentally observed and pinned mirror image manifest is
`sha256:624bad2fe79a8613aa3c5c9b765ebcab365080b8445cf1b05c357cc7753725f0`.
Digest pinning prevents accidental retagging but does not certify the
mirror's upstream build provenance or make it safe for production.
**This mirror is unsuitable as a production trust root.** The
test provides compatibility evidence only, never independent
provider attestation.

## Actual phases and expected evidence

1. Runtime isolation refuses execution outside CI, with any AWS profile,
   session/web-identity/container credentials, altered dummy credentials
   or a non-exact localhost endpoint.
2. Start MinIO against only a temporary runner-local `/data` mount.
   Create a randomized disposable bucket and enable versioning.
3. Perform **nine actual HTTP PUTs**: three A→B→A replacement generations,
   four concurrent writers to one key, and two identical replayed
   writes whose first application ACK is intentionally discarded.
   Read back each version, verify cryptographic content digests and
   check that there are no delete markers.
4. Kill the real MinIO container with `SIGKILL`. Ensure its TCP port
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

- All operations use `http://127.0.0.1:9000` and fixed fake MinIO
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
**No storage test was executed in that run.** A subsequent attempt with the official LocalStack image
`localstack/localstack:2026.08.3` was blocked by its required
license activation (`License activation failed`). No license
bypass was attempted. R33 then moved to a restricted historical
community mirror. Each attempt must be reported under its own
exact tested SHA; failed startup does not establish data behavior.


## First successful measured trial (pre-digest-pin HEAD)

- **GitHub Actions run:** [38577430323](https://github.com/1kz-ma1/Canovia-web/actions/runs/38577430323)
- **Observed feature HEAD:** `d276c6a66038a86ee970dbdbc91650c14a8af1ac`.
- **CI job:** `G3 isolated MinIO mirror SIGKILL and version persistence`, **SUCCESS**.
- Actual `PREPARE PASS`: 3 A→B→A + 4 concurrent + 2 same-body
  attempts produced **9 distinct versioned HTTP PUTs**, then re-read.
- Actual `OUTAGE PASS`: container was SIGKILLed and the localhost
  TCP endpoint refused connections.
- Actual `RESTART PASS`: the same runner-local volume retained **all
  nine historical object versions** with SHA-256 content verification;
  a tenth post-restart PUT/GET was successful.
- No S3 delete API was issued. All provider-authenticated, safe-delete
  and G3-release verdicts remain **false**.

The first MinIO mirror startup in run `38576997198` failed solely
because the container's unprivileged process could not write its
runner-local mount. The test runner's *dedicated disposable directory*
was made writable; no existing filesystem or production permission
was changed. This successful run predates pinning the observed
container image digest. A new exact-HEAD CI run after digest pinning
is required for final acceptance.

This test is **process-level container SIGKILL/restart**, not a
host power-loss durability test, actual AWS S3 provider validation,
real Laravel upload/outbox atomic recovery, externally fenced writes,
or a provider-authenticated generation-safe delete experiment.

**G3 OPEN. Five existing additive migrations remain production-unapproved.**

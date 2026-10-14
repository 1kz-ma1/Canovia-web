# G3-R27 Provider Evidence Handoff — Offline Inventory Only (2026-10-14)

**State: G3 OPEN. Not an operational GO.**

R11–R26 established application-local consistency, synthetic provider
observations, and deny-only cryptographic/provider trust boundaries.
This document assigns ownership to the outstanding proof requirements
without claiming that any proof has already been obtained.

## Evidence inventory contract

Offline script:
`python3 scripts/ops/g3_provider_evidence_inventory.py --repo <local-repository> --manifest <local-json-file>`

Use a full local Git checkout with the intended immutable candidate
commit. The script only invokes **local Git commands** to inspect blobs.
It never contacts S3/Render/Aiven/GitHub, reads live credentials, runs
migrations, mutates objects or releases a cleanup queue.

The JSON manifest uses exactly:

- `schema`: `canovia.g3.provider_evidence_inventory.v1`
- `candidate_sha`: immutable 40-character lowercase Git commit SHA
- `provider`: `s3_candidate` (a label, not attestation)
- `environment`: `disposable` (not authorization to use staging/production)
- `requirements`: exactly the eleven entries below. Each entry has
  `id`, `owner_role`, `review_state`, `evidence`.

`review_state` may be only `missing` with `evidence=null`, or
`candidate` with `evidence={"path":"docs/development/...md","sha256":"<64 lowercase hex>"}`.
Each requirement must reference a **different Markdown path**; repeating
one evidence file across multiple independent requirements is invalid.
The evidence Markdown must be an ordinary nonempty blob in the pinned
Git commit (not a symbolic link), no larger than 256 KiB. The script
checks the exact byte SHA-256; it does **not** inspect the substance or
authenticity of that Markdown. Manifest is limited to 64 KiB. Extra
fields, repeated JSON keys or duplicate requirements are rejected.

The script returns only **fixed requirement names and verdict labels**,
not sensitive paths, bucket names, request tokens, cloud IDs, object
keys, signatures or document contents. It always exits with code 2:
even if all eleven references match, the result is
`MANUAL_REVIEW_ONLY`, *never* release authorization. Missing/mismatched
documents return `BLOCK`; invalid/malformed input returns `INVALID`.

## Owner-role and independent acceptance work

| Required ID | Role | What independent evidence must establish |
| --- | --- | --- |
| `provider_identity_and_trust_root` | security | Verified provider endpoint/identity and reviewed trust chain; not APP_KEY HMAC |
| `response_authenticity` | security | Independent authenticity and write-time response provenance, not an SDK header alone |
| `request_and_generation_binding` | storage | Exact request/body/durable ack bound to immutable provider generation |
| `anti_replay_continuity` | security | Durable replay/nonce/epoch state across restarts and competing writers |
| `bucket_versioning_and_iam` | storage | Actual scoped bucket versioning behavior and IAM permissions from provider |
| `external_writer_fencing` | storage | Accounted-for concurrent writers, ABA, lease/fence loss |
| `generation_conditional_operations` | storage | Real generation-safe conditional operations with safety invariants |
| `crash_restart_recovery_test` | platform | Kill/restart/power-loss test across DB/provider consistency boundaries |
| `cross_database_cleanup_reconciliation` | platform | Proven safe outbox recovery, commit gaps, quarantine and no unsafe file deletion |
| `key_rotation_and_revocation_test` | security | Trust root and APP_KEY epoch rotation, revocation, stale evidence rejection |
| `owner_go_and_rollout_plan` | release | Separate explicit owner approval of pinned release, migration and rollback plan |

Owner-role strings are **routing labels only**; neither the manifest
nor the script verifies who supplied the file or whether an actual
security/storage/release reviewer signed off.

## Handoff / acceptance sequence

1. Capture redacted real provider observations **only in an explicitly
   authorized disposable environment**; preserve access restrictions
   and do not commit secrets/private user data.
2. Independently verify the facts and write narrow, sanitized evidence
   reports into the candidate commit. Record original observations in
   approved secure channels; do not publish bucket keys or credentials.
3. Create the manifest referencing the exact pinned commit and the
   SHA-256 of each sanitized document. Run the offline inventory check
   and review its redacted output; the result remains non-authorizing.
4. Review actual signed-off evidence **outside** this script; separately
   check provider authenticity/versioning/IAM, concurrency,
   crash-recovery, Aiven schema, rollback, and release/device gates.
5. Require explicit owner authorization for production branch changes,
   provider connections, live database migration or release. A complete
   manifest does not override those controls.

## Explicit limitations

No runtime verifier is registered; R25's
`PrivateFileUnavailableProviderVerifier` remains deny-only.
R26's synthetic replay history is not persisted or trusted.
R27 verifies only **Git object immutability and evidence-reference
integrity**; an attacker can create a plausible Markdown document
and a matching hash. It does NOT mean real cloud attestation,
safe delete, recovery completion, verified operator signatures, or
live service readiness.

Five earlier G3 migrations remain **unapproved** for production Aiven.
No deploy, DB migration, physical S3 call, scheduler, retry, delete
or G3 closure is authorized here. **G3 OPEN.**

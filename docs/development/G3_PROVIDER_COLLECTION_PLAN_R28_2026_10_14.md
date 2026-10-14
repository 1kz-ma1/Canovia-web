# G3-R28 — Provider Evidence Collection Plan and Independent Review Matrix

**2026-10-14 | G3 OPEN | Planning only; no provider access or release approval.**

R27 checks SHA-256 and Git-pinned *documents*, not the truth of the
assertions inside those documents. R28 adds a separate **offline plan**
validation contract before anyone is authorized to collect real evidence.

## Workflow boundaries

1. **Plan (R28):** select exact immutable candidate commit; enumerate the
   eleven R27 requirements, proposed isolated environment, intended
   source class, two mandatory failure scenarios, custody and reviewer role.
   Validate *offline*, without provider credentials.
2. **Authorize separately:** request explicit owner approval for a specific
   disposable cloud account/bucket, principal, region, spend limit, retention
   window, requested reads/writes and revocation. R28's output is **not**
   that approval; do not infer approval from a user role or Git commit.
3. **Collect only when authorized:** a human operator uses a temporary,
   scoped identity on an isolated non-user account. No production Aiven,
   existing customer bucket, Render Live, real user objects or shared IAM.
   Any destructive scenario must use disposable objects and independent
   rollback, with impact review and operation logging.
4. **Preserve originals outside Git:** restrict access to cloud response
   records, raw logs, credentials, request IDs, object keys, internal URLs,
   secret tokens and identifiable user data. Record their provenance via
   an approved secure evidence process, then prepare **redacted** per-gate
   Markdown findings for Git.
5. **Independent review:** security/storage/platform/release owners assess
   *actual* provider identity, versioning/IAM, generation semantics, writer
   fences, restart/replay behavior, negative cases and safe failure modes.
   Review the upstream originals independently; matching local hashes are
   not authenticity or approval.
6. **Inventory (R27):** pin eleven separate sanitized evidence documents
   to an immutable commit and check exact SHA-256. Even all matched reports
   remain `MANUAL_REVIEW_ONLY`. Live pilot, migration, rollout, release and
   deletion each require new explicit operator/owner approval.

## Offline CLI contract

```text
python3 scripts/ops/g3_provider_collection_plan.py --repo . --plan /private/location/collection-plan.json
```

Do not place a plan containing sensitive operational details in the repo.
Input schema `canovia.g3.provider_collection_plan.v1` requires:
`candidate_sha` (40-character lowercase commit), `environment` =
`disposable_only`, `execution_mode` = `offline_plan_only`,
`authorization` = `not_granted`, and exactly eleven `requirements`
matching the typed fields supplied by
`scripts.ops.g3_provider_collection_plan.template(candidate_sha)`.

Each entry fixes `id`, `owner_role`, `collection_target`,
`source_class`, `negative_cases` (exact two cases, order-sensitive),
`original_custody=restricted_external_only`,
`summary_custody=redacted_git_candidate`,
`capture_state=not_started`, `review_state=unverified` and
`separate_owner_gate=required`. An entry cannot claim that the
operator already collected, verified or received consent.
Unexpected fields, duplicate JSON keys/IDs, missing cases, unknown
roles, production/staging targets or wrong commit evidence fail closed.

Even a valid plan results in `PLAN_REVIEW_ONLY` and exit code 2.
All flags for `real_provider_authorized`, `collection_performed`,
`independent_evidence_verified`, `provider_authenticated`,
`release_authorized`, `retry_authorized`, `delete_authorized`
and `production_modified` stay false. Output reports only fixed
requirement identifiers and status labels. No cloud calls or file writes.

## Collection and negative-test matrix

| Requirement | Reviewer | Target, intended source | Required failure scenarios |
| --- | --- | --- | --- |
| Provider identity / trust root | Security | Disposable provider, independent identity/policy review | Unknown root; rotated root |
| Response authenticity | Security | Disposable provider, write response trace | Spoofed header; substituted response |
| Attempt and generation binding | Storage | Disposable provider, write-attempt/generation correlation | Mismatched attempt; changed version |
| Replay continuity | Security | Disposable provider, persistent replay state trace | Duplicate nonce; reordered attempt |
| Versioning and IAM | Storage | Disposable provider, bucket setting/IAM review | Suspended versioning; reduced permissions |
| External writer fencing | Storage | Disposable provider, controlled concurrent writer traces | Unfenced writer; ABA reappearance |
| Generation-conditional operations | Storage | Disposable provider, precondition semantics trace | Stale generation; unsupported condition |
| Crash/restart recovery | Platform | Isolated process, restart/fault matrix | After DB commit; before write ACK |
| DB/provider cleanup reconciliation | Platform | Isolated process, recovery/outbox trace | Interrupted outbox; quarantine boundary |
| Rotation and revocation | Security | Isolated process, rotated/revoked key matrix | Revoked key; changed epoch |
| Owner GO and rollout plan | Release | Offline review of pinned release boundary | Missing approval; stale release SHA |

Important S3 nuance: **SDK headers, TLS session properties,
request IDs and version IDs must not be represented as independently
signed write-time evidence.** Provider-specific generation-conditional
delete support, writer isolation and authenticated provenance remain
unproven until a reviewed provider-capability study demonstrates safe
semantics. Unsupported capabilities are a **stop condition**, not a
reason to weaken the verifier.

## Acceptance and unresolved gates

R28 validates **planning completeness** only; it does not validate
cloud permissions, origin, operator identity or successful negative
tests. R25's unavailable verifier stays unchanged. R26 replay history
remains untrusted. No new DB migration, background worker, provider
SDK, live test, automatic retry or deletion is introduced.

Five prior G3 migrations remain unapproved for production Aiven.
Keep Render production branch/configuration unchanged, and require
separate owner GO and qualified manual security/storage review for any
future real-provider action. **G3 OPEN.**

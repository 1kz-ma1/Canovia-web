# G3-R43 — Inbox Plan Resource Atomic Human Confirmation

**2026-10-15 | G3 OPEN | Source consistency only; real AWS trial HOLD**

## Observed defect

Following R42's URL-hostname correction, the human `plan_resource` path
still created a `PlanResource` and optional collaboration activity
*outside* an Inbox row-lock transaction, then separately marked the
route-loaded Inbox model as `processed`. Replaying the same POST could
create duplicate resources; concurrent Inbox correction or Editor
downgrade after the controller's initial authorization could also
commit a resource against stale source data or role-cache state.

R43 is **a concrete local DB correctness fix**, not another simulation
of unavailable external S3 evidence.

## Narrow implementation

Only `InboxRoutingService::toResource` is changed:

1. Short transaction locks target **Plan**, then original **Inbox**,
   matching R41's Plan-first ordering. Linked membership is locked
   by the existing Companion commit guard.
2. Reject deleted, archived or already-processed Inbox and compare the
   request snapshot's original source fields against the live row.
   A URL correction, ownership transfer, metadata mutation, plan
   retarget or other listed field change fails before any side effect.
3. Independently confirm live Inbox owner/actor-token and target
   Plan Owner/Editor role in this transaction, bypassing the
   request-scoped PlanOwnership role cache. A guest owner still uses
   the present owner cookie and cannot act on another user's Inbox.
4. R42's HTTPS + official hostname and userinfo rejection is repeated
   against the **live URL**.
5. Insert Plan Resource, Plan Activity (where collaborative), and
   mark the locked Inbox `processed` **inside one transaction**.
   A failed operation rolls back all rows. Follow-up requests observe
   `processed` and refuse another resource.
6. The generic route dispatcher does not update this Inbox again
   outside the verified transaction.

This touches neither Recall's two-phase copy/AI/final-commit path nor
Future Memo, Career, Task Evidence or Execution Request routing. It does
not attempt to guarantee those unrelated paths are atomic.

## Regression gates

Existing Inbox feature suite adds:
- replayed `plan_resource` POST rejects and resource count stays 1;
- original route-bound Inbox URL corrected in the database before
  commit: no resource and Inbox remains unprocessed;
- previously editable shared Plan downgraded to Viewer: no resource;
- Inbox relinked to a different Plan after route snapshot: no resource.

The whole suite runs in Inbox PHP/SQLite and disposable MySQL 8.0/8.4
alongside existing private file tests. PostgreSQL 16 source race suite
and common Workspace/Prelaunch/Migration/Preflight run on final HEAD.

This gate enforces **same-Inbox at-most-once registration under
participating SQL row locks**, not universal URL deduplication across
distinct Inbox items or external providers. SQL deadlocks in other
paths, token revocation after commit and all AWS real-provider
provenance remain separate.

No Aiven migration, Render or production deployment, AWS, IAM, cloud
billing, secrets, storage read/delete or external provider calls.
See [R40](G3_PROVIDER_AUTHORIZATION_DECISION_R40_2026_10_15.md)
and [Issue #681](https://github.com/1kz-ma1/Canovia-web/issues/681).

**G3 OPEN. provider_attested=false; delete_authorized=false;
production_migrations_approved=false; owner_provider_go=false.**

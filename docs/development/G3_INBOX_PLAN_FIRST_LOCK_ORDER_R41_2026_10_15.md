# G3-R41 — Plan-first Inbox AI Lock-Order Correction

**2026-10-15 | Platform | G3 OPEN | Provider trial still HOLD (Issue #681)**

## Concrete defect, not a synthetic proof layer

R39 added real, locked source and authorization revalidation before
sending Inbox source material to an AI provider. It unintentionally
introduced an **Inbox → Plan** lock acquisition sequence both in
\`assertInputMatches()\` and in \`commit()\`.

The existing \`InboxRecallMaterialTransferGuard::lockCurrent()\`
takes **Plan → Task → Inbox → Member**, and explicitly documents that
order. Concurrent operations acquiring these shared rows in opposite
orders can form a database deadlock. Deadlock victims get rolled back
and require retries; repeated user-facing failures are avoidable.

## R41 correction

A shared private \`lockSourceGraph()\` helper locks:

1. Original linked **Plan** row first (only if original Inbox snapshot
   already references a Plan).
2. Exact live **Inbox** row second.
3. Compare the live Inbox \`plan_id\` with the original reference.
   A retarget during the interval is **rejected**; it is never accepted
   by silently taking a newly referenced Plan lock out of order.
4. Existing owner/token, Plan Editor membership/authorization epoch,
   current purpose entitlement and source signature checks remain in
   their respective pre-provider and post-provider transactions.

Unlinked Inbox items retain an Inbox-only lock path. Linked items
follow the same Plan-before-Inbox ordering as Recall transfer and its
related cleanup workflows. The database transaction is closed before
the external AI call, never held across provider latency.

## Regression evidence

\`InboxAiSuggestionCommitFenceG3Test\` now includes a SQL lock-order
assertion on real MySQL or PostgreSQL connections. For an owned linked
file it observes **Plan, Inbox, Plan, Inbox** as the two path orders
(pre-provider and final commit), recording actual \`FOR UPDATE\` SQL.
The SQLite variant skips only this real-SQL-lock assertion because it
does not compile \`FOR UPDATE\`; the existing Inbox feature suite
continues to run. A separate source-retarget case verifies that
changing a linked Inbox from one owned Plan to another in the
pre-provider interval issues **no AI request**, regardless of unchanged
file bytes.

CI should pass the whole Inbox suite in disposable MySQL 8.0/8.4 and
PostgreSQL 16 in addition to existing regular workflows. SQL order
checks limit this particular inversion risk; they are not a complete
formal proof of freedom from deadlocks across every route or DB
schedule.

## Explicit R40/G3 limitations

This stage fixes a real application lock-order defect. It **does not**
create a substitute AWS evidence checker, fake provider verifier,
new storage capability flag, migration, SDK or production change.
It cannot establish that an external provider's write VersionId is
authentically attributable to Canovia, nor authorize object deletion.

See [R40 provider checkpoint](G3_PROVIDER_AUTHORIZATION_DECISION_R40_2026_10_15.md)
and [Issue #681](https://github.com/1kz-ma1/Canovia-web/issues/681)
for the separate human-approved non-destructive trial requirement.

**G3 OPEN. provider_attested=false; delete_authorized=false;
production_migrations_approved=false; owner_provider_go=false.**

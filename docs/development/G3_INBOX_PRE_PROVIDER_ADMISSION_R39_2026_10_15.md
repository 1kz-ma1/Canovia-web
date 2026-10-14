# G3-R39 — Inbox Pre-Provider Live Source and Authorization Admission

**2026-10-15 | Platform | G3 OPEN | no AWS/production action**

## Concrete finding

R38 bound the SHA-256 of an Inbox file's *actual AI input bytes* to the
earlier signature. However, R38's pre-provider check re-evaluated the
signature using the original, route-loaded \`InboxItem\`, not an independently
locked live row. A correction, transfer or editor revocation completed
after the file was signed and before AI submission could still cause
Canovia to send private attachment bytes to the AI provider, only
rejecting the response after its cost and disclosure occurred.

**R39 blocks already-committed ownership/authorization changes before
AI submission**, not just at persistence.

## Implementation boundary

\`InboxAiSuggestionCommitFence::assertInputMatches\` now runs a short DB
transaction and:

1. Locks the **live** InboxItem by ID; refuses deleted/processed item.
2. Confirms authenticated identity still owns the InboxItem or retains
   its exact actor token (using the current Request).
3. Locks the current Plan when linked and verifies live Editor/Owner
   permission through \`CompanionMembershipCommitGuard::canAccess\`
   including the existing member authorization epoch. Do not accept
   a prior model snapshot as new authorization.
4. Rechecks current native-AI entitlement/purpose; fail closed if revoked
   before submission.
5. Recomputes the existing full signature using the **exact bytes hash
   already encoded** for the pending AI request and the **live** Inbox
   row. An expected original signature mismatch refuses before provider.

All locks are released **before** \`NativeAiGateway::generateStructured\`.
The existing post-provider durability check is preserved and remains
necessary. No private source bytes, paths or provider credentials are
introduced into logs.

## Evidence / risk

Regression scenarios in the existing Inbox G3 feature suite:

- File bytes unchanged but source note corrected between signing and
  provider admission: no provider call, no new AI run or candidate.
- Inbox owner changed in the same window: no provider call.
- Shared Editor downgraded to Viewer after signing: no provider call.
- Premium entitlement expired after signing: no provider call.
- R38 A->B content mismatch and missing file remain blocked.
- Unchanged text/image and post-provider source/epoch checks retain
  existing behavior.

The existing suite executes in the Inbox CI, disposable MySQL 8.0/8.4
and PostgreSQL 16 workflow. This is **application DB-source admission**,
not an atomic transaction across an external file provider. A revoke or
write AFTER this brief pre-provider transaction can still race an AI
request; the commit fence will refuse the result, but this stage does
not guarantee zero external disclosures under *all* schedules.

Uncoordinated writer ABA A->B->A with matching content, immutable
S3 VersionId validation, provider-signed write-time provenance,
conditional safe deletion, real-IAM/bucket/region tests, and actual
production deployment are **not** proven or approved. R31/R27-R30
out-of-band owner review remains authoritative.

No database migration, third-party SDK, new feature flag, external
provider I/O, cloud secrets, Aiven or Render modifications, billable
trial or production release.

**G3 OPEN. provider_attested=false; delete_authorized=false;
production_migrations_approved=false; owner_provider_go=false.**

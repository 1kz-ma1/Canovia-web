# Pre-Launch — manually curated public Canovia roadmap (2026-10-15)

**Status:** development implementation, **not** public availability or production release approval. Scope: Canovia Future `GET /feedback` only. No new service, migrations, admin UI, queue, webhook, GitHub read, event polling, AI, costs, or deployment changes.

## Product contract

Visitors should understand three different claims:

1. **現在開発中** (`in_development`): we are working on the experience; it may not be accessible yet.
2. **検証中** (`ready`, `rolling_out`): an implementation is being tested or gradually evaluated; it is *not* a statement that everyone can use it.
3. **公開済み** (`released`): deliberately curated **after** the owner confirms the actual live deployment, relevant access level/entitlement and real client acceptance.

Additionally, **これから** (`planned`) expresses direction without a delivery or timing promise. Existing `roadmap_features` candidate cards still retain their full `RoadmapFeatureStatus` lifecycle, Support votes/cancellation, and feedback. The curated public development stories are **not** a new `RoadmapFeature` table, Support tally, Plan, Task, deployment indicator, or product entitlement.

## Editing a public card — only when development state changes

Source of truth: **`config/canovia_public_roadmap.php`**, reviewed in a dedicated GitHub PR. Each item has:

- `key`: stable lowercase public identifier (not a private repo/Plan/Task ID)
- `title`, `description`: user-facing capability or value, no private details or promises
- `area`: human-readable general category
- `status`: `planned`, `in_development`, `ready`, `rolling_out` or `released`
- `updated_on`: owner-maintained `YYYY-MM-DD` (not an inferred Git timestamp)
- `is_published`: explicit boolean `true` before any visitor may see the card; `false` to withhold

Edit the appropriate card, update the calendar date, and submit a reviewed PR. For an entry changing to `rolling_out` or `released`, **also** record:

```php
'status' => 'released',
'owner_release_verified' => true,
'release_verified_on' => 'YYYY-MM-DD', // date of actual owner-accepted Live/Web/iPhone or relevant client smoke
'access_scope' => 'public',           // or 'limited' if restricted to accepted invitees
```

Without **all three fields**, a staged rollout or released entry is *not rendered*. Only set these fields after the actual environment, deployment SHA, required user eligibility and acceptance are independently checked and explicitly accepted by the owner. Passing CI, merging main, creating PR, preparing Render, or an old device smoke is not acceptance. The guard requires affirmative editorial evidence fields but cannot independently prove their truth. No automatic promotion to `released` occurs.

The initial snapshot intentionally contains **no released cards**, because the first public release/physical device acceptance is still owner-gated. Empty published sections present an honest explanatory state. The initial illustrative development, first-use verification, and future external-integration cards do not promise deployment dates or that GitHub activity auto-sync is available in Pre-Launch. Before an actual public promotion, re-review every visible card for product accuracy and audience.

**Safety:** no sensitive architecture, unreleased partner terms, private links, user Plan/Task/history, credentials, migration details, vulnerabilities or security controls in public copy. Feature descriptions are Blade-escaped; service projects only whitelisted public fields and skips unknown/invalid/duplicate/unpublished entries. The original `roadmap_features` Support system remains the sole voter feature source.

## Verification

- New `PublicRoadmapProjectionService` reads versioned config locally and maps the manually selected status into 3 release phases + upcoming. No external reads, DB writes or queue actions.
- `CanoviaFutureV407Test` exercises anonymous GET, status change by explicit config edit, existing free Support persistence, suppression of hidden/unknown/unapproved released entries, scoped `limited` release indicator, and escaped public output.
- Existing Canovia Future V40.7 Support/add/remove/hidden-feature tests and Workspace/Release CI must remain green.
- Post-deploy user-visible acceptance is separate: guest `/feedback` shows the correct copy, Support and feedback still work, no internal data is exposed, released labels match the actual live state on Web/iPhone for eligible users. **A merge into main is not production availability.**

## Explicitly deferred

Automated GitHub/PR/CI/deployment status import, `RoadmapFeature` admin editor, Queue Worker, polling/scheduled changes, automatic Support thresholds, financial support/payment, feature entitlement changes, roadmaps for individual Plans, and full marketing-site IA. Production branch promotion and Render/Aiven changes remain separately owner-authorized under Issue #488 and Draft PR #513.

Related: [Pre-Launch IA](PRELAUNCH_PUBLIC_APP_FUTURE_IA_SPEC_2026_10_13.md), [V40.7 Canovia Future](../V40.7_CANOVIA_FUTURE_FOUNDATION.md), [current development workflow](../CANOVIA_DEVELOPMENT_WORKFLOW.md).

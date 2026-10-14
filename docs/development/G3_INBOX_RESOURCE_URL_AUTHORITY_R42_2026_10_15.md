# G3-R42 — Inbox Plan Resource URL Authority Validation

**2026-10-15 | Platform | G3 OPEN | External provider trial remains HOLD**

## Root cause

`InboxRoutingService::toResource()` previously used `str_contains()` on a URL host to label a Plan Resource as GitHub, Google Drive or OneDrive. As a result, unrelated hosts such as `github.com.attacker.example` or `my-sharepoint.example` could be stored as a trusted-looking service Resource even though the URL did not belong to that provider.

This is **provider labeling / user-facing link trust**, not a proven SSRF, token disclosure, or an AWS generation issue. Ingesting a URL does **not** fetch it. Do not claim that validating the host also verifies a user's access to the linked provider.

## R42 bounded fix

- Parse the URL authority once. Require `https` and reject embedded URL `user`/`pass` components before provider recognition.
- Identify hosts only when **equal to** the service's known domain or ending with **dot + domain**:
  - GitHub: `github.com`, subdomains (e.g. `gist.github.com`).
  - Google Drive / Docs: `drive.google.com`, `docs.google.com` and subdomains.
  - OneDrive / SharePoint: `onedrive.live.com`, `onedrive.com`, `1drv.ms`, `sharepoint.com` and subdomains such as `tenant-my.sharepoint.com`.
- Reject lookalike suffixes, unrelated sites containing "onedrive" or "sharepoint", userinfo-bearing URLs, and plain HTTP without modifying or resolving the original link.
- Preserve existing resource writes and other Inbox destinations. No HTTP request to the URL, external provider login, backend fetch, or migrated schema.

These are known **hostname recognition boundaries**, not an exhaustive allowlist of every Microsoft national-cloud URL or guarantee that all links under a provider-operated subdomain are safe to click. Unsupported official endpoints are intentionally left as Inbox items until explicitly reviewed rather than silently mislabeled.

## Regressions

`InboxIntelligenceGuideV4113Test` now asserts:
- 7 official HTTPS links store their expected provider and original URL;
- 10 misleading or untrusted authorities fail validation, do not insert PlanResource and leave the Inbox unprocessed;
- pre-existing manual Inbox routing and AI suggestion flows retain their tests.

Run the updated Inbox CI, the disposable MySQL 8.0/8.4 matrix, and PostgreSQL source-race regression on the exact PR HEAD. The changes touch no live AWS, Render, Aiven, provider IAM, database schema, cloud billing, external credentials or production release.

**G3 OPEN; R40 / Issue #681 authorization still pending. provider_attested=false; delete_authorized=false; production_migrations_approved=false; owner_provider_go=false.**

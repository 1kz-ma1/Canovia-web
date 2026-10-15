# Integration publication boundary — implementation candidate

Authority: integration master PR #769 and [Release Level contract](../CANOVIA_RELEASE_LEVEL_SPEC.md). This code change does not modify any hosted environment, entitlement grant, OAuth setting or public roadmap status.

## Contract delta

- Absent/invalid public level fails closed to L0. Hosted L4 public configuration also resolves to L0; internal preview belongs to the configured Super Admin session. Explicit L0–L3 remain operator-selected configurations and are not release approvals.
- Existing local/test L4 fixtures remain available explicitly. `phpunit.xml` now selects the historical full development catalog; dedicated production/absent-config tests verify the safe default independently.
- Existing `FeatureAccessService` checks Release Level **before** entitlement and admin entitlement shortcuts. This closes the previously unenforced `feature_minimum` map for shared HTTP, UI and worker access decisions. Feature-specific kill switches, resource ownership and OAuth/Plan grants remain separate mandatory checks.
- Existing L3 user grants and revocation are reused. A stored regular-user L4 override is ignored without deleting the value or data. Mass assignment cannot change `release_level_override`; the existing authorized admin service uses `forceFill`. Even an incorrectly raised maximum override cannot assign L4.
- A downgrade retains Plans, saved Career preference and other data; regrant restores access. Release exposure never makes another actor's Plan readable.

## Evidence

Targeted publication/session/MCP checks: **29 tests / 236 assertions, passed**. Existing baseline failures elsewhere are not reclassified by this result. The new cases cover production invalid/absent/internal configuration, guest/normal/beta entitlement without publication, stored override, assignment protection, grant/revoke/direct POST, admin preview isolation and foreign-Plan denial.

Existing real Chromium journeys passed on separate private SQLite databases with L1:

- IA-2d: all three welcome choices, optional diagnosis/skip and confirmed Plan creation.
- Development V6.9: guest Plan claim, user-confirmed follow-through, Evidence versus progress, logout/login restoration. The initial Task remains an explicitly synthetic fixture.
- Learning V6.21: first answer draft, 204/419 feedback, reload and owner-confirmed assessment. The strict existing synthetic fixture path is retained.

`scripts/ci/integration_browser_regression.py` constructs a provider-free child environment, owns and stops each server process group, and refuses a live Vite hot marker. An optional Playwright executable path permits the installed cloud Chromium; GitHub CI uses pinned Playwright 1.56.1 and its bundled Chromium. Browser contexts abort non-loopback HTTP; no application response or assertion is replaced. Local browser evidence uses system Chromium and is not Safari/iPhone evidence.

Reproduce from the existing activated development environment, with Playwright available and compiled assets:

```bash
php artisan test tests/Feature/IntegrationExposureBoundaryTest.php \
  tests/Feature/ReleaseLevelFoundationV5820Test.php tests/Feature/FeatureAccessV406Test.php \
  tests/Feature/P0DatabaseSessionLoginRegressionTest.php \
  tests/Feature/McpSyntheticOAuthConsentReadLifecycleTest.php --colors=never
python3 scripts/ci/integration_browser_regression.py \
  --browser-executable /usr/bin/chromium --artifacts /tmp/canovia-integration-browser
```

`.github/workflows/integration-exposure.yml` registers these checks. Local passes are not remote CI results; exact-head CI and main integration remain pending until GitHub API access is restored.

## Release / rollback

Before deployment, review actual effective public configuration and allowlisted users at a fixed candidate SHA. Do not turn global L4 back on to recover a hidden feature. Use the existing Super Admin preview or reviewed L3 tester grant. Roll back exposure with the existing level/grant controls; no schema or data deletion is part of this change. A code rollback to older publication behavior could reopen internal surfaces and needs a separate security review. Actual hosted configuration changes, deployment, paid features and native device acceptance remain owner-gated.

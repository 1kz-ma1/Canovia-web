# Platform — Durable Session Login Regression, test-only integration
> Status: CI acceptance pending · 2026-10-15 · Platform lane
> Historical source: Draft PR #446. This is a narrower replacement for the tests only.
> Source of truth: current main / AGENTS.md, Release Issue #488.

## Objective
Retain useful isolated session-recovery regression evidence without adopting an unverified production reverse-proxy configuration. The reported iPhone login incident in Issue #418 was resolved by correcting the configured DB name, not by proving a proxy bug.

## Implemented in this PR
- Synthetic previously registered account and session-table-backed Laravel Feature tests: login, durable cookie reuse, invalid login feedback, logout and login again; cookie policy checks are confined to the configured test environment.
- Disposable loopback HTTP and persistent SQLite test: authentication/session across PHP process restart with an explicitly allowlisted test fixture DB, existing user and real CSRF forms.
- Dedicated path-scoped GitHub Actions CI with no external provider, payment, production DB, real credentials or production Render operations. New tests do not mutate existing production tables; only temporary CI data is created.

## Explicitly excluded
- Original PR #446 `bootstrap/app.php` addition of `CANOVIA_TRUSTED_PROXY_IPS`. This mechanism was not established as the incident fix and is **not** adopted.
- Original `p0_https_cookie_origin_e2e.py` HTTPS reverse-proxy test that assumes the above runtime configuration. Do not falsely claim its security acceptance.
- Real iPhone Safari / PWA / WKWebView evidence, production HTTPS proxy identity, real Render/Aiven session acceptance, backup/restoration, or any production deployment.

## Acceptance and release
Run this PR's exact-head isolated `p0-session-login`, Workspace Resume, Migration Recovery and any other applicable CI; review the full diff, ensure no runtime/config/proxy/DDL changes, confirm mergeability and review obligations before squash merging **main only**. Test results never authorize promotion of release candidate #513, Render deployment, Aiven writes or production login claims. The operational release gate remains Issue #488.

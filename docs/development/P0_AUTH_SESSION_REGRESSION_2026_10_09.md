# P0 Existing-Account Login / Session Recovery Probe (2026-10-09)

Status: **proposed isolated CI test, not a production authentication fix, deployment or device E2E.**
Owner: Platform/Auth. Tracking: [P0 Issue #418](https://github.com/1kz-ma1/Canovia-web/issues/418).
Related P0 MySQL recovery: [Draft PR #443](https://github.com/1kz-ma1/Canovia-web/pull/443), kept independent.

## Code and observed incident facts

AuthController uses Laravel Auth::attempt, regenerates the session on success, and redirects to the intended page. Login Blade exposes invalid-credentials error; logout invalidates the session. config/session.php supports database/Redis sessions and configurable secure/host-only/Lax cookies. Existing FirstRunUxV4123Test covers established-user login and incorrect-password error, but mostly uses test **array** sessions, which do not prove durable production storage.

The original production observation was POST /login 302 followed by GET /login 200 on iPhone. **This sequence is ambiguous**: wrong user DB, incorrect credentials, missing session cookie, host/cookie/proxy mismatch or session-store failure all remain possible. The status codes alone are not a root-cause diagnosis.

## Added bounded verification

- tests/Feature/P0DatabaseSessionLoginRegressionTest.php switches to Laravel **database** sessions in the temporary SQLite test DB, with fictional pre-existing users. It tests valid/invalid login, session-table persistence, forgetting the in-memory auth guard before subsequent requests, supplying the encrypted response session cookie, viewing a protected account page, logout and re-login.
- It separately checks the test session cookie's Secure, HttpOnly, SameSite Lax, host-only and path properties.
- .github/workflows/p0-database-session-login.yml runs the new test and existing first-run/auth failure regressions.
- No new logging of passwords, emails, cookies, session IDs, user details or secrets. No production runtime authentication, CSRF, token, user-data or cookie configuration changes.

## Still required before P0 acceptance

1. Privately confirm production Render effective database driver/name, session driver, cookie scope, secure settings, canonical host and proxy trust. Do not disclose secrets or private environment values in GitHub/chat.
2. Correlate only **sanitized** login/session rejection categories by exact deployed SHA; never log or post raw email, IP, request body, password, cookies or tokens.
3. Complete existing-account device E2E on Safari, installed PWA, and WKWebView as available: login, protected navigation, reload, full close/reopen, logout/relogin and different browser. Check correct host and distinguish first-run redirect from lost authentication.
4. If production uses Redis sessions, confirm backend connectivity and session TTL separately. This CI test uses SQLite-backed database sessions; it does **not** test production Redis persistence, real account password, domain migration, actual browser cookie jar or cross-process redeploy.
5. Maintain the P0 release hold from Issue #418. Keep this PR Draft and unmerged until actual Aiven schema/backup and login/PWA acceptance. Main merges can auto-deploy production even when only tests/docs are changed.

A CI pass is **simulated auth coverage**, not a claim of a resolved production login problem.

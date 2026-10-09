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

## Real HTTP boundary test with persistent SQLite (2026-10-09)

The original feature tests exercised Laravel's in-process HTTP kernel and
manually reset its cached guard. We now also run a **real loopback HTTP**
smoke against a genuine PHP web server and **file-backed SQLite database
sessions** in GitHub Actions, with no access to production Render/Aiven.

- `scripts/ci/p0_http_auth_seed.php` inserts a **synthetic existing account**
  after checking `APP_ENV=testing`, the explicit disposable opt-in, DB
  connection `sqlite`, path **exactly**
  `database/p0_auth_http_ci.sqlite`, and `SESSION_DRIVER=database`.
  It refuses any other environment and never writes production accounts.
- `scripts/ci/p0_real_http_session_e2e.py` uses Python's genuine HTTP cookie
  jar and actual GET/POST requests to a bound `127.0.0.1:18789` PHP
  process. The test verifies: unauthorized account redirects to login;
  incorrect password redirects and displays the flashed browser-facing
  validation error; correct password establishes a session DB row and
  reaches the protected account; the *same cookie jar* reaches the protected
  account after stopping and restarting PHP; logout revokes account access;
  and a fresh valid login succeeds again.
- The workflow builds Vite assets for real login/account templates, creates
  only a local SQLite test DB and runs the migration stack in GitHub Actions.
  The server logs, synthetic credentials, response bodies, session cookie
  values, tokens and user identifiers are **not printed** to CI output.

**Scope limitations:** The isolated HTTP smoke uses an unencrypted loopback
HTTP origin, so it explicitly sets `SESSION_SECURE_COOKIE=false` **only
inside the GitHub Actions test step**. The separate PHP feature regression
continues to verify Secure, HttpOnly, SameSite Lax and host-only attributes.
HTTP localhost behavior is **not** a guarantee of Safari/PWA/WKWebView
cookie persistence, HTTPS reverse proxy forwarding, Redis connectivity,
real production Aiven selection, or an actual existing customer's login.

The test user, SQLite DB, fake cookie jar and server are temporary CI-only
fixtures. There is no production auth/controller/session config change.
As required by Issue #418, this PR remains Draft/unmerged even with green
CI until the actual production DB, backup and real-device gates are met.

## Local TLS proxy, Secure host-only Cookie isolation (2026-10-09)

The previous HTTP E2E deliberately disabled Secure cookies on a localhost HTTP
origin, which is not how the deployed browser receives HTTPS cookies. A second,
isolated CI probe `scripts/ci/p0_https_cookie_origin_e2e.py` now generates an
**ephemeral, locally trusted self-signed TLS certificate** for localhost and
127.0.0.1; runs a pinned, loopback-only HTTPS reverse proxy to the same
disposable PHP + SQLite session app; and verifies:

- Correct sign-in at `https://localhost:18790` succeeds with
  `SESSION_SECURE_COOKIE=true`; real browser-style cookie storage and an
  authenticated `/account` response establish that the Secure cookie is
  returned over HTTPS.
- The successful login redirect is also checked for the **same HTTPS scheme
  and exact localhost origin**; a downgrade redirect to HTTP would otherwise
  silently discard Secure cookies and could recreate a login loop.
- The returned session cookie is explicitly **Secure**, **HttpOnly**,
  **SameSite Lax**, and host-only (no configured `SESSION_DOMAIN`).
- A request to the *different origin host* `https://127.0.0.1:18790`
  on the identical backend must **not** be authenticated from the
  localhost-scoped session cookie. This is a synthetic host-boundary
  demonstration only, not a real legacy Canovia domain migration.
- The same HTTPS cookie jar remains authenticated after fully restarting
  the PHP server process, with the persisted disposable database sessions.

The proxy is **not** a general HTTP proxy, and allows only a fixed
127.0.0.1 backend. The certificate/key remain in a temporary CI directory,
not committed or exposed. No credentials, cookies, session IDs or response
bodies are printed. Original HTTP/invalid-password/flash/logout/relogin CI
continues to run independently and is not replaced.

**Scope limitations:** CI TLS termination is a self-signed local proxy; it
does not prove Render's actual proxy trust rules, redirect scheme generation
in all production configurations, login against live Aiven/MySQL/Redis, Safari
or installed iOS PWA storage isolation, or WKWebView. Real HTTPS/PWA E2E and
read-only production environment/schema evidence are still blocked release
gates. No new public host, secret, prod service, auth runtime behavior, paid
resource or deployment has been introduced.

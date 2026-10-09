#!/usr/bin/env python3
"""Real HTTP / persistent SQLite session / PHP-process-restart smoke test.

Runs exclusively against a disposable SQLite and the loopback PHP server.
No live Render requests, browser credentials, private records or secrets.
"""

from html.parser import HTMLParser
import http.cookiejar
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


ROOT = Path(__file__).resolve().parents[2]
HOST = "127.0.0.1"
PORT = 18789
BASE = f"http://{HOST}:{PORT}"
EMAIL = "p0-http-ci@example.com"
PASSWORD = "synthetic-http-ci-password"


class AuthFormTokenParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.hidden_csrf = None
        self.meta_csrf = None

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        if tag == "input" and attributes.get("name") == "_token":
            self.hidden_csrf = attributes.get("value")
        if tag == "meta" and attributes.get("name") == "csrf-token":
            self.meta_csrf = attributes.get("content")


def ensure(condition: bool, label: str):
    if not condition:
        raise RuntimeError("P0 disposable HTTP verification failed: " + label)


class PreventRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


def request(opener, method, path, data=None):
    payload = urllib.parse.urlencode(data).encode("utf-8") if data else None
    req = urllib.request.Request(
        BASE + path, data=payload, method=method,
        headers={"Content-Type": "application/x-www-form-urlencoded",
                 "User-Agent": "Canovia-P0-Disposable-HTTP-CI"},
    )
    try:
        res = opener.open(req, timeout=12)
    except urllib.error.HTTPError as err:
        res = err
    with res:
        status = res.status
        headers = dict(res.headers.items())
        body = res.read(2 * 1024 * 1024).decode("utf-8", errors="replace")
    return status, headers, body


def token(body, *, on_account=False):
    parser = AuthFormTokenParser()
    parser.feed(body)
    value = parser.meta_csrf if on_account else parser.hidden_csrf
    ensure(isinstance(value, str) and 20 <= len(value) <= 256,
           "anti-CSRF token present")
    return value


def redirect_to_login(response):
    status, headers, _ = response
    ensure(status == 302, "protected route redirected")
    location = headers.get("Location", "")
    ensure(urllib.parse.urlparse(location).path == "/login", "protected route targets login")


def serve():
    # Exact executable is PHP's built-in loopback HTTP server. The direct
    # process can be terminated without leaving artisan serve child workers.
    return subprocess.Popen(
        ["php", "-S", f"{HOST}:{PORT}", "-t", "public", "public/index.php"],
        cwd=ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        env=os.environ.copy(),
    )


def wait_ready(opener, process):
    for _ in range(70):
        ensure(process.poll() is None, "local server remains alive")
        try:
            status, _, _ = request(opener, "GET", "/up")
            if status == 200:
                return
        except (urllib.error.URLError, TimeoutError, ConnectionError):
            pass
        time.sleep(0.15)
    raise RuntimeError("P0 disposable HTTP verification failed: local server startup")


def rows_in_db():
    db = ROOT / "database/p0_auth_http_ci.sqlite"
    with sqlite3.connect(f"file:{db}?mode=ro", uri=True) as connection:
        return connection.execute(
            "SELECT count(*) FROM sessions WHERE user_id IS NOT NULL"
        ).fetchone()[0]


def main():
    ensure(os.environ.get("CANOVIA_P0_DISPOSABLE_HTTP_CI") == "1", "CI opt-in")
    ensure(os.environ.get("APP_ENV") == "testing", "test environment only")
    ensure(os.environ.get("DB_CONNECTION") == "sqlite", "SQLite only")
    ensure(os.environ.get("SESSION_DRIVER") == "database", "database sessions only")
    ensure(os.environ.get("SESSION_SECURE_COOKIE") == "false", "loopback HTTP cookie only")
    ensure(os.environ.get("APP_URL") == BASE, "fixed loopback origin")
    ensure(os.environ.get("DB_DATABASE") == str(ROOT / "database/p0_auth_http_ci.sqlite"),
           "isolated database path only")

    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(jar), PreventRedirect()
    )
    proc = serve()
    try:
        wait_ready(opener, proc)
        redirect_to_login(request(opener, "GET", "/account"))

        status, _, body = request(opener, "GET", "/login")
        ensure(status == 200, "initial login form")
        csrf = token(body)

        # Invalid credentials must flash a visible error on the real redirected
        # login response, without creating an authenticated session.
        bad = request(opener, "POST", "/login", {
            "_token": csrf, "email": EMAIL,
            "password": "intentionally-wrong", "remember": "0",
        })
        redirect_to_login(bad)
        status, _, body = request(opener, "GET", "/login")
        ensure(status == 200 and "data-auth-login-error" in body,
               "real HTTP flashed login error")
        ensure("intentionally-wrong" not in body, "wrong password not echoed")

        status, _, body = request(opener, "GET", "/login")
        csrf = token(body)
        good = request(opener, "POST", "/login", {
            "_token": csrf, "email": EMAIL, "password": PASSWORD, "remember": "0",
        })
        ensure(good[0] == 302, "successful login redirect")
        ensure(urllib.parse.urlparse(good[1].get("Location", "")).path != "/login",
               "successful login not redirected back to login")
        status, _, body = request(opener, "GET", "/account")
        ensure(status == 200, "protected account after correct login")
        ensure(rows_in_db() > 0, "authenticated database session persisted")

        # Restart a real PHP process; the cookie jar and SQLite file persist.
        proc.terminate()
        proc.wait(timeout=10)
        proc = serve()
        wait_ready(opener, proc)
        status, _, body = request(opener, "GET", "/account")
        ensure(status == 200, "account session survives PHP process restart")

        csrf = token(body, on_account=True)
        status, _, _ = request(opener, "POST", "/logout", {"_token": csrf})
        ensure(status == 302, "valid logout redirects")
        redirect_to_login(request(opener, "GET", "/account"))

        # Re-login on the *same* real HTTP cookie jar after invalidation.
        status, _, body = request(opener, "GET", "/login")
        ensure(status == 200, "post-logout login form")
        csrf = token(body)
        status, headers, _ = request(opener, "POST", "/login", {
            "_token": csrf, "email": EMAIL, "password": PASSWORD, "remember": "1",
        })
        ensure(status == 302, "remembered re-login redirects")
        ensure(urllib.parse.urlparse(headers.get("Location", "")).path != "/login",
               "re-login redirects out of login")
        status, _, _ = request(opener, "GET", "/account")
        ensure(status == 200, "protected account after re-login")

        # Do not expose synthetic credentials, session IDs, cookies, URLs,
        # tokens or raw responses through CI logs.
        print("p0_loopback_http_database_session_restart_logout_relogin: pass")
        return 0
    finally:
        if proc.poll() is None:
            proc.terminate()
            try:
                proc.wait(timeout=10)
            except subprocess.TimeoutExpired:
                proc.kill()
                proc.wait(timeout=5)


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (RuntimeError, OSError, sqlite3.Error, urllib.error.URLError,
            subprocess.TimeoutExpired):
        # A fixed diagnostic only: do not log PHP body/token/cookie values.
        print("p0_loopback_http_database_session_restart_logout_relogin: fail")
        sys.exit(1)

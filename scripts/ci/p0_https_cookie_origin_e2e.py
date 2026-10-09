#!/usr/bin/env python3
"""Isolated HTTPS reverse-proxy auth + Secure host-only Cookie regression.

ONLY connects to the synthetic local PHP+SQLite HTTP fixture. Creates a
one-shot localhost TLS key inside a temporary directory, never in GitHub.
No remote hosts, no logs of credentials, cookies, tokens or request bodies.
"""

import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import http.cookiejar
import os
from pathlib import Path
import socket
import sqlite3
import ssl
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

# Share existing audited synthetic auth request and CSRF parsing helpers.
import p0_real_http_session_e2e as http_test

ROOT = Path(__file__).resolve().parents[2]
HTTPS_PORT = 18790
HTTP_PORT = http_test.PORT
BASE = f"https://localhost:{HTTPS_PORT}"
ALTERNATE_HOST = f"https://127.0.0.1:{HTTPS_PORT}"
COOKIE_NAME = "pace-keeper-session"


def ensure(value: bool, tag: str):
    if not value:
        raise RuntimeError("P0 HTTPS boundary check refused: " + tag)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


class QuietLoopbackProxy(BaseHTTPRequestHandler):
    """Forwards all app paths ONLY to pinned loopback PHP, not a general proxy."""

    def log_message(self, format, *args):
        # Never log raw paths, cookies, credentials or request fragments.
        return

    def do_GET(self):
        self.forward()

    def do_POST(self):
        self.forward()

    def forward(self):
        if self.path.startswith(("http://", "https://", "//")):
            self.send_error(400)
            return
        size = self.headers.get("Content-Length", "0")
        if not size.isdigit() or int(size) > 65536:
            self.send_error(413)
            return
        body = self.rfile.read(int(size))
        headers = {
            key: value for key, value in self.headers.items()
            if key.lower() not in ("host", "connection", "content-length",
                                   "transfer-encoding", "accept-encoding")
        }
        headers["Host"] = f"localhost:{HTTPS_PORT}"
        headers["X-Forwarded-Proto"] = "https"
        headers["X-Forwarded-Host"] = f"localhost:{HTTPS_PORT}"
        try:
            upstream = http.client.HTTPConnection("127.0.0.1", HTTP_PORT, timeout=10)
            upstream.request(self.command, self.path, body=body, headers=headers)
            res = upstream.getresponse()
            result = res.read(2 * 1024 * 1024)
            self.send_response_only(res.status)
            for name, value in res.getheaders():
                if name.lower() in ("transfer-encoding", "connection", "content-length"):
                    continue
                self.send_header(name, value)
            self.send_header("Content-Length", str(len(result)))
            self.end_headers()
            self.wfile.write(result)
            upstream.close()
        except (OSError, http.client.HTTPException):
            # Fixed status only, never echo connection/request/headers.
            self.send_error(502)


def request(opener, method, url, data=None):
    payload = urllib.parse.urlencode(data).encode("utf-8") if data else None
    req = urllib.request.Request(
        url, data=payload, method=method,
        headers={"User-Agent": "Canovia-P0-Disposable-HTTPS-CI",
                 "Content-Type": "application/x-www-form-urlencoded"},
    )
    try:
        response = opener.open(req, timeout=12)
    except urllib.error.HTTPError as err:
        response = err
    with response:
        return (response.status,
                dict(response.headers.items()),
                response.read(2 * 1024 * 1024).decode("utf-8", "replace"))


def csrf(html):
    return http_test.token(html)


def start_php():
    return subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{HTTP_PORT}", "-t", "public", "public/index.php"],
        cwd=ROOT,
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        env=os.environ.copy(),
    )


def cert_pair(tmp: Path):
    cert, key = tmp / "localhost.crt", tmp / "localhost.key"
    subprocess.run(
        ["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes",
         "-keyout", str(key), "-out", str(cert), "-days", "1",
         "-subj", "/CN=localhost",
         "-addext", "subjectAltName=DNS:localhost,IP:127.0.0.1"],
        check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    return cert, key


def main():
    ensure(os.getenv("CANOVIA_P0_DISPOSABLE_HTTP_CI") == "1", "test opt-in")
    ensure(os.getenv("APP_ENV") == "testing", "test app environment")
    ensure(os.getenv("DB_CONNECTION") == "sqlite", "test SQLite")
    ensure(os.getenv("SESSION_DRIVER") == "database", "durable DB session")
    ensure(os.getenv("SESSION_SECURE_COOKIE") == "true", "HTTPS-only cookie")
    ensure(os.getenv("SESSION_DOMAIN", "") == "", "host-only cookie")
    ensure(os.getenv("APP_URL") == BASE, "fixed loopback HTTPS origin")
    ensure(os.getenv("DB_DATABASE") ==
           str(ROOT / "database/p0_auth_http_ci.sqlite"),
           "exact disposable SQLite file")
    ensure((ROOT / "database/p0_auth_http_ci.sqlite").is_file(),
           "pre-seeded throwaway SQLite")

    php = None
    proxy = None
    with tempfile.TemporaryDirectory(prefix="canovia-p0-https-") as directory:
        cert, key = cert_pair(Path(directory))
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(certfile=str(cert), keyfile=str(key))
        proxy = ThreadingHTTPServer(("127.0.0.1", HTTPS_PORT), QuietLoopbackProxy)
        proxy.socket = context.wrap_socket(proxy.socket, server_side=True)
        threading.Thread(target=proxy.serve_forever, daemon=True).start()
        client_ssl = ssl.create_default_context(cafile=str(cert))
        opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
            urllib.request.HTTPSHandler(context=client_ssl),
            NoRedirect(),
        )
        jar = next(h for h in opener.handlers
                   if isinstance(h, urllib.request.HTTPCookieProcessor)).cookiejar

        php = start_php()
        try:
            for _ in range(80):
                ensure(php.poll() is None, "PHP loopback process alive")
                try:
                    if request(opener, "GET", BASE + "/up")[0] == 200:
                        break
                except (urllib.error.URLError, TimeoutError, ConnectionError):
                    pass
                time.sleep(.15)
            else:
                ensure(False, "HTTPS proxy startup")

            # Authentication via actual HTTPS with Secure, HttpOnly and
            # host-only session Cookie; no CSRF bypass.
            status, _, html = request(opener, "GET", BASE + "/login")
            ensure(status == 200, "HTTPS login response")
            csrf_token = csrf(html)
            status, headers, _ = request(opener, "POST", BASE + "/login", {
                "_token": csrf_token, "email": http_test.EMAIL,
                "password": http_test.PASSWORD, "remember": "0",
            })
            ensure(status == 302, "HTTPS login redirects")
            ensure(urllib.parse.urlsplit(headers.get("Location", "")).path != "/login",
                   "login did not send back to login")
            ensure(request(opener, "GET", BASE + "/account")[0] == 200,
                   "authenticated account on exact HTTPS origin")

            session_cookies = [cookie for cookie in jar if cookie.name == COOKIE_NAME]
            ensure(len(session_cookies) == 1, "exactly one session cookie")
            ensure(session_cookies[0].secure, "Secure attribute")
            ensure(not session_cookies[0].domain_specified,
                   "cookie remains host-only")
            ensure(session_cookies[0].domain in ("localhost", "localhost.local"),
                   "cookie scoped to localhost")
            ensure(session_cookies[0].get_nonstandard_attr("HttpOnly") is not None
                   or "HttpOnly" in session_cookies[0]._rest,
                   "HttpOnly attribute")
            ensure((session_cookies[0]._rest.get("SameSite", "").lower() == "lax"),
                   "SameSite Lax attribute")

            # The same cookie jar must not authenticate on a different host,
            # even though it routes to the very same PHP server/database.
            other_status, other_headers, _ = request(
                opener, "GET", ALTERNATE_HOST + "/account",
            )
            ensure(other_status == 302
                   and urllib.parse.urlsplit(other_headers.get("Location", "")).path == "/login",
                   "alternate origin has no authenticated session")

            php.terminate()
            php.wait(timeout=10)
            php = start_php()
            for _ in range(80):
                try:
                    if request(opener, "GET", BASE + "/up")[0] == 200:
                        break
                except (urllib.error.URLError, TimeoutError, ConnectionError):
                    pass
                time.sleep(.15)
            else:
                ensure(False, "restarted PHP readiness")

            ensure(request(opener, "GET", BASE + "/account")[0] == 200,
                   "Secure Cookie and session persist across PHP restart")
            print("p0_https_secure_cookie_host_isolation_restart: pass")
            return 0
        finally:
            if php is not None and php.poll() is None:
                php.terminate()
                try:
                    php.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    php.kill()
                    php.wait(timeout=5)
            if proxy is not None:
                proxy.shutdown()
                proxy.server_close()


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (RuntimeError, OSError, sqlite3.Error, urllib.error.URLError,
            subprocess.TimeoutExpired, ssl.SSLError, ValueError) as exc:
        # Our fixed labels are generated only by ensure() with constants.
        # Never reflect arbitrary proxy, cookie, cert, user or request values.
        msg = str(exc)
        prefix = "P0 HTTPS boundary check refused: "
        if isinstance(exc, RuntimeError) and msg.startswith(prefix):
            tag = msg[len(prefix):]
            # Restrict output to the fixed internal label alphabet.
            if tag and len(tag) <= 80 and all(
                c.isalnum() or c in " -_" for c in tag
            ):
                print("p0_https_secure_cookie_host_isolation_restart: fail " + tag)
            else:
                print("p0_https_secure_cookie_host_isolation_restart: fail check")
        else:
            print("p0_https_secure_cookie_host_isolation_restart: fail transport")
        sys.exit(1)

#!/usr/bin/env python3
"""Actual synthetic human OAuth Authorization Code + PKCE on disposable Keycloak.

GitHub Actions only. No production/staging data, no browser passwords, tokens,
subjects, cookies, codes, callback query strings or claims are ever logged.
The two RP clients are synthetic; neither is the actual ChatGPT client.
"""

import base64
from copy import deepcopy
import hashlib
from html.parser import HTMLParser
from http.cookiejar import CookieJar, DefaultCookiePolicy
import json
import os
from pathlib import Path
import secrets
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

from keycloak_mcp_protocol_smoke import (
    KEYCLOAK_IMAGE, MCP_RESOURCE, MCP_SCOPE, REALM, Gap, new_realm,
    request_json, require,
)

CONTAINER = "canovia-mcp-keycloak-user-pkce-ci"
ORIGIN = "http://127.0.0.1:18081"
ISSUER = f"{ORIGIN}/realms/{REALM}"
BASE = f"{ISSUER}/protocol/openid-connect"
RESOURCE_CLIENT_ID = MCP_RESOURCE
CALLBACK_LINK = "https://canovia.invalid/ci/callback"
CALLBACK_AGENT = "https://chatgpt.invalid/ci/callback"
RP_LINK = "canovia-ci-mcp-client"
RP_AGENT = "canovia-ci-agent-test-client"


class CallbackRedirect(Exception):
    def __init__(self, url: str):
        self.url = url


class StrictRedirect(urllib.request.HTTPRedirectHandler):
    """Follow only loopback; capture pre-approved inert callback without GET."""

    def __init__(self, allowed_callback: str):
        super().__init__()
        self.allowed_callback = allowed_callback

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        candidate = urllib.parse.urlsplit(newurl)
        base = urllib.parse.urlsplit(self.allowed_callback)
        if (candidate.scheme, candidate.netloc, candidate.path) == (
            base.scheme, base.netloc, base.path
        ) and not candidate.fragment and not candidate.username and not candidate.password:
            raise CallbackRedirect(newurl)
        if (candidate.scheme, candidate.netloc) != ("http", "127.0.0.1:18081"):
            raise Gap("unexpected_external_redirect")
        return super().redirect_request(req, fp, code, msg, headers, newurl)


class LoginForm(HTMLParser):
    def __init__(self):
        super().__init__()
        self.action = None
        self.in_form = False

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        if tag == "form" and attributes.get("id") == "kc-form-login":
            self.in_form = True
            self.action = attributes.get("action")

    def handle_endtag(self, tag):
        if tag == "form":
            self.in_form = False


def synthetic_realm(link_secret: str, agent_secret: str,
                    resource_secret: str, user_password: str) -> dict:
    realm = new_realm(link_secret, resource_secret)
    # The username/password belong to a throwaway realm ONLY; no grant,
    # subject mapping or Canovia User is created.
    realm["users"] = [{
        "username": "canovia-disposable-human",
        "enabled": True,
        "emailVerified": True,
        "credentials": [{"type": "password", "value": user_password,
                         "temporary": False}],
    }]
    link = realm["clients"][1]
    link["serviceAccountsEnabled"] = False
    link["redirectUris"] = [CALLBACK_LINK]
    link["consentRequired"] = False
    agent = deepcopy(link)
    agent["clientId"] = RP_AGENT
    agent["secret"] = agent_secret
    agent["redirectUris"] = [CALLBACK_AGENT]
    realm["clients"].append(agent)
    return realm


def authorize(username: str, password: str, client_id: str, redirect: str,
              resource: str = MCP_RESOURCE) -> tuple[str, str]:
    # Every login gets a fresh cookie jar, state, nonce and PKCE verifier.
    verifier = secrets.token_urlsafe(54)
    challenge = base64.urlsafe_b64encode(
        hashlib.sha256(verifier.encode("ascii")).digest()
    ).rstrip(b"=").decode("ascii")
    state = secrets.token_urlsafe(30)
    params = urllib.parse.urlencode({
        "client_id": client_id,
        "response_type": "code",
        "response_mode": "query",
        "redirect_uri": redirect,
        "scope": f"openid {MCP_SCOPE}",
        "state": state,
        "nonce": secrets.token_urlsafe(20),
        "resource": resource,
        "code_challenge": challenge,
        "code_challenge_method": "S256",
    })
    # Browsers treat loopback as a potentially trustworthy context and
    # can send Keycloak's Secure auth-session cookie while testing HTTP
    # localhost. urllib's default CookieJar does not do this. Allow it
    # ONLY in this localhost-only, no-proxy, throwaway CI opener.
    cookie_jar = CookieJar(policy=DefaultCookiePolicy(
        secure_protocols=("https", "http")
    ))
    opener = urllib.request.build_opener(
        urllib.request.ProxyHandler({}),
        urllib.request.HTTPCookieProcessor(cookie_jar),
        StrictRedirect(redirect),
    )
    try:
        with opener.open(f"{BASE}/auth?{params}", timeout=9) as response:
            html = response.read(98305)
            require(response.status == 200, "auth_page_http_failure")
    except CallbackRedirect:
        raise Gap("authorization_skipped_user_login") from None
    except (urllib.error.URLError, TimeoutError):
        raise Gap("auth_page_unavailable") from None
    require(len(html) <= 98304, "auth_page_too_large")
    # Diagnostic contains names/booleans only, never cookie values.
    require(any(c.name.startswith("AUTH_SESSION_ID") for c in cookie_jar),
            "no_auth_session_cookie_after_auth_page")
    form = LoginForm()
    form.feed(html.decode("utf8", errors="replace"))
    require(isinstance(form.action, str), "login_form_missing")
    action = urllib.parse.urljoin(ORIGIN, form.action)
    parsed = urllib.parse.urlsplit(action)
    require(parsed.scheme == "http" and parsed.netloc == "127.0.0.1:18081"
            and parsed.path.startswith(f"/realms/{REALM}/login-actions/authenticate"),
            "login_action_not_trusted")
    body = urllib.parse.urlencode({
        "username": username, "password": password,
        "credentialId": "",
    }).encode("ascii")
    try:
        with opener.open(urllib.request.Request(
            action, data=body, headers={
                "Content-Type": "application/x-www-form-urlencoded",
            }, method="POST"
        ), timeout=9) as response:
            # A 200 may be login failure or a required interactive action.
            require(response.status != 200, "login_did_not_redirect")
            raise Gap("login_missing_callback")
    except CallbackRedirect as exc:
        callback = urllib.parse.urlsplit(exc.url)
        data = urllib.parse.parse_qs(callback.query, strict_parsing=True)
        require(data.get("state") == [state], "callback_state_invalid")
        require(data.get("iss") == [ISSUER], "callback_issuer_invalid")
        code = data.get("code")
        require(code is not None and len(code) == 1
                and isinstance(code[0], str) and len(code[0]) >= 16,
                "authorization_code_missing")
        require("error" not in data, "authorization_error_redirect")
        return code[0], verifier
    except urllib.error.HTTPError as exc:
        # Do not leak error bodies or cookies: record only the HTTP status.
        raise Gap("login_http_status_" + str(exc.code)) from None
    except (urllib.error.URLError, TimeoutError):
        raise Gap("login_request_unavailable") from None


def redeem(client_id: str, client_secret: str, redirect: str, code: str,
           verifier: str, resource: str = MCP_RESOURCE) -> tuple[int, dict]:
    return request_json(f"{BASE}/token", payload={
        "grant_type": "authorization_code",
        "code": code,
        "redirect_uri": redirect,
        "code_verifier": verifier,
        "resource": resource,
    }, basic=(client_id, client_secret), http_error_ok=True)


def introspect(access_token: str, secret: str) -> dict:
    status, claims = request_json(f"{BASE}/token/introspect", payload={
        "token": access_token,
        "token_type_hint": "access_token",
    }, basic=(RESOURCE_CLIENT_ID, secret), http_error_ok=True)
    require(status == 200, "person_token_introspection_http_error")
    require(claims.get("active") is True, "person_token_not_active")
    require(claims.get("iss") == ISSUER, "person_token_issuer_mismatch")
    require(claims.get("aud") in (MCP_RESOURCE, [MCP_RESOURCE]),
            "person_token_audience_not_exact")
    require(isinstance(claims.get("sub"), str)
            and 0 < len(claims["sub"]) <= 255,
            "person_token_subject_invalid")
    require(isinstance(claims.get("scope"), str)
            and MCP_SCOPE in claims["scope"].split(" "),
            "person_token_read_scope_missing")
    require(isinstance(claims.get("exp"), int)
            and time.time() < claims["exp"] < time.time() + 3600,
            "person_token_expired_or_excessive")
    require(claims.get("token_type") == "Bearer", "person_token_type_invalid")
    return claims


def main() -> None:
    require(os.getenv("GITHUB_ACTIONS") == "true"
            or os.getenv("CANOVIA_MCP_LOCAL_LAB") == "true",
            "disposable_lab_only")
    password = secrets.token_urlsafe(36)
    link_secret = secrets.token_urlsafe(36)
    agent_secret = secrets.token_urlsafe(36)
    resource_secret = secrets.token_urlsafe(36)
    admin_password = secrets.token_urlsafe(36)
    username = "canovia-disposable-human"
    with tempfile.TemporaryDirectory(prefix="canovia-pkce-ephemeral-") as temp:
        directory = Path(temp)
        file = directory / f"{REALM}-realm.json"
        file.write_text(json.dumps(synthetic_realm(
            link_secret, agent_secret, resource_secret, password
        )), encoding="utf8")
        os.chmod(directory, 0o755)
        os.chmod(file, 0o644)
        subprocess.run([
            "docker", "run", "-d", "--name", CONTAINER,
            "--memory", "2g", "-p", "127.0.0.1:18081:8080",
            "-e", "KC_BOOTSTRAP_ADMIN_USERNAME=ci-admin",
            "-e", f"KC_BOOTSTRAP_ADMIN_PASSWORD={admin_password}",
            "-v", f"{directory}:/opt/keycloak/data/import:ro",
            KEYCLOAK_IMAGE, "start-dev",
            "--features=resource-indicators,cimd", "--import-realm",
        ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True)
        passed = False
        try:
            for _ in range(65):
                try:
                    status, meta = request_json(
                        f"{ISSUER}/.well-known/openid-configuration")
                    if status == 200 and meta.get("issuer") == ISSUER:
                        break
                except Gap:
                    state = subprocess.run(
                        ["docker", "inspect", "--format", "{{.State.Running}}",
                         CONTAINER], capture_output=True, text=True, check=False)
                    require(state.returncode == 0
                            and state.stdout.strip() == "true",
                            "keycloak_container_exited")
                    time.sleep(2)
            else:
                raise Gap("keycloak_discovery_unready")

            print("synthetic_keycloak_user_realm_ready: pass")
            code, verifier = authorize(
                username, password, RP_LINK, CALLBACK_LINK)
            require(isinstance(code, str), "person_link_code_missing")
            print("authorization_code_browser_login_and_rfc9207: pass")

            # A wrong verifier MUST fail before a token can be issued.
            status, error = redeem(
                RP_LINK, link_secret, CALLBACK_LINK, code,
                secrets.token_urlsafe(56))
            require(status == 400 and error.get("error") == "invalid_grant",
                    "invalid_pkce_verifier_not_rejected")
            print("wrong_pkce_verifier_rejected: pass")

            # A new authorization code is required after the failed exchange.
            code, verifier = authorize(
                username, password, RP_LINK, CALLBACK_LINK)
            status, response = redeem(
                RP_LINK, link_secret, CALLBACK_LINK, code, verifier)
            require(status == 200
                    and isinstance(response.get("access_token"), str),
                    "person_link_code_exchange_failed")
            link_claims = introspect(response["access_token"], resource_secret)
            require(link_claims.get("client_id") == RP_LINK,
                    "link_client_claim_mismatch")
            print("user_link_client_resource_bound_introspection: pass")

            # Reauthenticate the SAME synthetic human through an independently
            # registered client (ChatGPT-like, not a real ChatGPT client).
            code, verifier = authorize(
                username, password, RP_AGENT, CALLBACK_AGENT)
            status, response = redeem(
                RP_AGENT, agent_secret, CALLBACK_AGENT, code, verifier)
            require(status == 200
                    and isinstance(response.get("access_token"), str),
                    "person_agent_code_exchange_failed")
            agent_claims = introspect(response["access_token"], resource_secret)
            require(agent_claims.get("client_id") == RP_AGENT,
                    "agent_client_claim_mismatch")
            require(agent_claims["sub"] == link_claims["sub"],
                    "cross_client_subject_mismatch")
            print("same_user_separate_client_issuer_subject: pass")

            code, verifier = authorize(
                username, password, RP_AGENT, CALLBACK_AGENT)
            status, error = redeem(
                RP_AGENT, agent_secret, CALLBACK_AGENT, code, verifier,
                resource="https://foreign.example.invalid/api/mcp")
            require(status == 400 and error.get("error") == "invalid_target",
                    "authorization_token_resource_mismatch_not_rejected")
            print("resource_changed_between_auth_and_token_denied: pass")

            print("disposable_pkce_user_oauth: passed")
            print("production_authorized: false")
            print("actual_chatgpt_oauth: not_tested")
            print("canovia_consent_and_plan_read: not_tested")
            passed = True
        finally:
            if not passed:
                diagnostic = subprocess.run(
                    ["docker", "logs", "--tail", "100", CONTAINER],
                    stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                    text=True, check=False,
                )
                filtered = [
                    line for line in (diagnostic.stdout
                                      + diagnostic.stderr).splitlines()
                    if any(word in line.lower() for word in
                           ("error", "failed", "exception", "unknown"))
                ]
                for line in filtered[-5:]:
                    for secret in (password, link_secret, agent_secret,
                                   resource_secret, admin_password):
                        line = line.replace(secret, "[REDACTED]")
                    # Only in the disposable CI realm; no production data.
                    if 'LOGIN_ERROR' in line:
                        import re
                        found = re.search(r'error="([a-z_]+)"', line)
                        print("ephemeral_keycloak_login_error: "
                              + (found.group(1) if found else "unspecified"))
                    else:
                        # Suppress full identity/cookie/session log records.
                        print("ephemeral_keycloak_startup_error: present")
            subprocess.run(["docker", "rm", "-f", CONTAINER],
                           stdout=subprocess.DEVNULL,
                           stderr=subprocess.DEVNULL, check=False)


if __name__ == "__main__":
    try:
        main()
    except (Gap, subprocess.CalledProcessError) as exc:
        code = str(exc) if isinstance(exc, Gap) else "disposable_container_failure"
        print("outcome: blocked (" + code + ")")
        raise SystemExit(1)

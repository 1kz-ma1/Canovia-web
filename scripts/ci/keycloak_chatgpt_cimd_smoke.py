#!/usr/bin/env python3
"""Probe real public ChatGPT CIMD against disposable, pinned Keycloak.

Only a CI/explicit local Docker lab. Makes one bounded public HTTPS metadata
GET; never logs its body. Keycloak may fetch the same trusted document. No
ChatGPT login, callback delivery, tokens, Plan data or Render changes.
"""

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
    KEYCLOAK_IMAGE, MCP_RESOURCE, REALM, Gap, request_json, require,
)
from keycloak_user_pkce_smoke import synthetic_realm


CONTAINER = "canovia-mcp-keycloak-cimd-ci"
ORIGIN = "http://127.0.0.1:18083"
ISSUER = f"{ORIGIN}/realms/{REALM}"
CLIENT_ID = "https://chatgpt.com/oauth/client.json"
CALLBACK = "https://chatgpt.com/connector_platform_oauth_redirect"
PROFILE = "canovia-ci-trusted-chatgpt-cimd"
POLICY = "canovia-ci-cimd-domain-only"


class DenyRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def public_metadata() -> dict:
    # Exact, non-configurable allowlisted URL; no redirect or credential.
    opener = urllib.request.build_opener(DenyRedirect())
    try:
        with opener.open(urllib.request.Request(
            CLIENT_ID, headers={"Accept": "application/json"},
        ), timeout=8) as response:
            require(response.status == 200, "chatgpt_metadata_http_not_200")
            require("json" in response.headers.get("Content-Type", "").lower(),
                    "chatgpt_metadata_content_type_invalid")
            raw = response.read(5001)
    except (urllib.error.HTTPError, urllib.error.URLError, TimeoutError):
        raise Gap("chatgpt_metadata_fetch_unavailable") from None
    require(len(raw) <= 5000, "chatgpt_metadata_exceeds_keycloak_limit")
    try:
        data = json.loads(raw.decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        raise Gap("chatgpt_metadata_not_json") from None
    require(isinstance(data, dict) and data.get("client_id") == CLIENT_ID,
            "chatgpt_metadata_client_id_mismatch")
    require(CALLBACK in data.get("redirect_uris", []),
            "chatgpt_stable_callback_missing")
    methods = data.get("token_endpoint_auth_methods_supported")
    require(isinstance(methods, list)
            and "private_key_jwt" in methods and "none" in methods,
            "chatgpt_metadata_auth_method_list_changed")
    # No assumption that Keycloak understands the plural choices field.
    # The legacy singular field is a compatibility bridge, not a promise.
    return data


def local_admin_token(password: str) -> str:
    status, data = request_json(
        f"{ORIGIN}/realms/master/protocol/openid-connect/token",
        payload={"grant_type": "password", "client_id": "admin-cli",
                 "username": "ci-admin", "password": password},
        http_error_ok=True,
    )
    require(status == 200 and isinstance(data.get("access_token"), str),
            "disposable_admin_login_failed")
    return data["access_token"]


def admin_json(token: str, suffix: str, *, payload=None) -> tuple[int, dict]:
    require(suffix in ("profiles", "policies"), "unknown_admin_path")
    url = f"{ORIGIN}/admin/realms/{REALM}/client-policies/{suffix}"
    headers = {"Accept": "application/json", "Authorization": "Bearer " + token}
    body = None
    method = "GET"
    if payload is not None:
        headers["Content-Type"] = "application/json"
        body = json.dumps(payload).encode("utf-8")
        method = "PUT"
    req = urllib.request.Request(url, data=body, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=7) as response:
            status, raw = response.status, response.read(16385)
    except urllib.error.HTTPError as exc:
        status, raw = exc.code, b""
    except (urllib.error.URLError, TimeoutError):
        raise Gap("admin_policy_loopback_unavailable") from None
    require(len(raw) <= 16384, "admin_policy_response_too_large")
    if not raw:
        return status, {}
    try:
        data = json.loads(raw.decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        raise Gap("admin_policy_invalid_json") from None
    require(isinstance(data, dict), "admin_policy_invalid_shape")
    return status, data


def configure_cimd_policy(token: str) -> None:
    # Real Keycloak admin REST configuration, disposable realm only. Lock both
    # the URI condition and executor to the exact HTTPS domain, require
    # matching redirect host, and pin the ONLY permitted MCP audience.
    profile = {
        "name": PROFILE,
        "description": "Disposable GitHub Actions ChatGPT-domain CIMD probe",
        "executors": [{
            "executor": "client-id-metadata-document",
            "configuration": {
                "cimd-allow-http-scheme": False,
                "cimd-allow-permitted-domains": ["chatgpt.com"],
                "cimd-restrict-same-domain": True,
                "cimd-resource-indicator-allow-list": [MCP_RESOURCE],
                "only-allow-confidential-client": True,
            },
        }],
    }
    policy = {
        "name": POLICY,
        "description": "HTTPS ChatGPT client ID only",
        "enabled": True,
        "conditions": [{
            "condition": "client-id-uri",
            "configuration": {
                "client-id-uri-scheme": ["https"],
                "client-id-uri-allow-permitted-domains": ["chatgpt.com"],
            },
        }],
        "profiles": [PROFILE],
    }
    status, profiles = admin_json(token, "profiles")
    require(status == 200 and isinstance(profiles.get("profiles"), list),
            "client_profiles_get_failed")
    status, _ = admin_json(token, "profiles", payload={
        "profiles": profiles["profiles"] + [profile],
    })
    require(status in (200, 204), "client_profiles_configuration_rejected")
    status, policies = admin_json(token, "policies")
    require(status == 200 and isinstance(policies.get("policies"), list),
            "client_policies_get_failed")
    status, _ = admin_json(token, "policies", payload={
        "policies": policies["policies"] + [policy],
    })
    require(status in (200, 204), "client_policies_configuration_rejected")
    status, actual = admin_json(token, "policies")
    require(status == 200 and any(
        p.get("name") == POLICY and p.get("enabled") is True
        for p in actual.get("policies", [])
    ), "client_policy_not_enabled")


def auth_response(client_id: str, resource: str) -> tuple[int, bool]:
    # Capture real Keycloak response, never submit credentials or follow any
    # redirects, notably not the production ChatGPT callback URI.
    params = urllib.parse.urlencode({
        "client_id": client_id,
        "response_type": "code",
        "redirect_uri": CALLBACK,
        "scope": "openid",
        "state": secrets.token_urlsafe(24),
        "code_challenge": "q" * 43,
        "code_challenge_method": "S256",
        "resource": resource,
    })
    opener = urllib.request.build_opener(
        urllib.request.ProxyHandler({}), DenyRedirect()
    )
    try:
        with opener.open(f"{ISSUER}/protocol/openid-connect/auth?{params}",
                         timeout=12) as response:
            code, raw = response.status, response.read(98305)
    except urllib.error.HTTPError as exc:
        code, raw = exc.code, exc.read(98305)
    except (urllib.error.URLError, TimeoutError):
        raise Gap("cimd_authorize_loopback_failed") from None
    require(len(raw) <= 98304, "cimd_auth_response_too_large")
    return code, b"kc-form-login" in raw


def main() -> None:
    require(os.environ.get("GITHUB_ACTIONS") == "true"
            or os.environ.get("CANOVIA_MCP_LOCAL_LAB") == "true",
            "disposable_lab_only")
    public_metadata()
    print("live_chatgpt_cimd_document_shape: pass")
    admin_secret = secrets.token_urlsafe(36)
    resource_secret = secrets.token_urlsafe(36)
    with tempfile.TemporaryDirectory(prefix="canovia-keycloak-cimd-") as temp:
        directory = Path(temp)
        realm = synthetic_realm(
            secrets.token_urlsafe(36), secrets.token_urlsafe(36),
            resource_secret, secrets.token_urlsafe(36), secrets.token_urlsafe(36),
        )
        realm_path = directory / f"{REALM}-realm.json"
        realm_path.write_text(json.dumps(realm), encoding="utf8")
        os.chmod(directory, 0o755)
        os.chmod(realm_path, 0o644)
        subprocess.run([
            "docker", "run", "-d", "--name", CONTAINER,
            "--memory", "2g", "-p", "127.0.0.1:18083:8080",
            "-e", "KC_BOOTSTRAP_ADMIN_USERNAME=ci-admin",
            "-e", f"KC_BOOTSTRAP_ADMIN_PASSWORD={admin_secret}",
            "-v", f"{directory}:/opt/keycloak/data/import:ro",
            KEYCLOAK_IMAGE, "start-dev",
            "--features=resource-indicators,cimd", "--import-realm",
        ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True)
        try:
            for _ in range(90):
                try:
                    code, data = request_json(
                        f"{ISSUER}/.well-known/openid-configuration"
                    )
                    if code == 200 and data.get("issuer") == ISSUER:
                        break
                except Gap:
                    process = subprocess.run([
                        "docker", "inspect", "--format", "{{.State.Running}}",
                        CONTAINER,
                    ], capture_output=True, text=True, check=False)
                    require(process.returncode == 0
                            and process.stdout.strip() == "true",
                            "cimd_disposable_container_exited")
                    time.sleep(2)
            else:
                raise Gap("cimd_realm_unready")
            token = local_admin_token(admin_secret)
            configure_cimd_policy(token)
            print("keycloak_cimd_trusted_policy_configured: pass")
            status, login = auth_response(CLIENT_ID, MCP_RESOURCE)
            require(status == 200 and login, "chatgpt_cimd_authorization_not_accepted")
            print("real_keycloak_chatgpt_cimd_initial_authorization: pass")
            # A bad resource MUST NOT reach the browser login screen.
            status, login = auth_response(
                CLIENT_ID, "https://wrong.example.invalid/api/mcp"
            )
            require(not login and status >= 400,
                    "untrusted_resource_not_rejected")
            print("cimd_wrong_resource_denied: pass")
            # No alternate origin may bypass the URL policy.
            status, login = auth_response(
                "https://untrusted.example.invalid/oauth/client.json",
                MCP_RESOURCE,
            )
            require(not login and status >= 400,
                    "untrusted_cimd_domain_not_rejected")
            print("cimd_untrusted_client_domain_denied: pass")
            print("real_chatgpt_code_exchange: not_tested")
            print("read_scope_token_introspection: not_tested_in_this_slice")
            print("production_authorized: false")
        finally:
            subprocess.run(["docker", "rm", "-f", CONTAINER],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                           check=False)


if __name__ == "__main__":
    try:
        main()
    except (Gap, subprocess.CalledProcessError) as exc:
        category = str(exc) if isinstance(exc, Gap) else "container_failure"
        print("outcome: blocked (" + category + ")")
        raise SystemExit(1)

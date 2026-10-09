#!/usr/bin/env python3
"""Disposable localhost-only Keycloak OpenID DCR probe for ChatGPT-style PKCE.

This is NOT a real ChatGPT client and creates no persistent IdP / app data.
No codes, credentials, tokens, state, subjects or response bodies are logged.
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
    KEYCLOAK_IMAGE, MCP_RESOURCE, MCP_SCOPE, REALM,
    Gap, request_json, require,
)
import keycloak_user_pkce_smoke as pkce


PORT = 18082
ORIGIN = f"http://127.0.0.1:{PORT}"
ISSUER = f"{ORIGIN}/realms/{REALM}"
BASE = f"{ISSUER}/protocol/openid-connect"
CONTAINER = "canovia-mcp-keycloak-dcr-ci"
CALLBACK = "https://chatgpt.invalid/disposable-dcr-callback"


def register_client(registration_endpoint: str,
                    initial_access_token: str | None = None) -> tuple[int, dict]:
    parsed = urllib.parse.urlsplit(registration_endpoint)
    require(parsed.scheme == "http"
            and parsed.netloc == f"127.0.0.1:{PORT}"
            and parsed.path == f"/realms/{REALM}/clients-registrations/openid-connect",
            "untrusted_registration_endpoint")
    # OIDC Dynamic Client Registration, comparable to a ChatGPT-like
    # PUBLIC client with an explicit consented callback; no service grant.
    payload = {
        "client_name": "Canovia Disposable Public MCP CI",
        "redirect_uris": [CALLBACK],
        "grant_types": ["authorization_code"],
        "response_types": ["code"],
        "token_endpoint_auth_method": "none",
        "scope": "openid " + MCP_SCOPE,
    }
    body = json.dumps(payload).encode("utf-8")
    headers = {"Accept": "application/json",
               "Content-Type": "application/json"}
    if initial_access_token is not None:
        headers["Authorization"] = "Bearer " + initial_access_token
    request = urllib.request.Request(
        registration_endpoint, data=body, method="POST", headers=headers,
    )
    try:
        with urllib.request.urlopen(request, timeout=8) as resp:
            status, raw = resp.status, resp.read(16385)
    except urllib.error.HTTPError as exc:
        status, raw = exc.code, exc.read(16385)
    except (urllib.error.URLError, TimeoutError):
        raise Gap("registration_network_error") from None
    require(len(raw) <= 16384, "registration_response_too_large")
    try:
        result = json.loads(raw.decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        raise Gap("registration_invalid_json") from None
    require(isinstance(result, dict), "registration_not_object")
    return status, result


def create_one_use_registration_token(admin_secret: str) -> str:
    # Private disposable admin token stays only in the in-memory CI process.
    status, admin = request_json(
        f"{ORIGIN}/realms/master/protocol/openid-connect/token",
        payload={
            "grant_type": "password", "client_id": "admin-cli",
            "username": "ci-admin", "password": admin_secret,
        }, http_error_ok=True,
    )
    require(status == 200 and isinstance(admin.get("access_token"), str),
            "local_disposable_admin_auth_failed")
    temporary_admin_bearer = admin["access_token"]
    data = json.dumps({"count": 1, "expiration": 120}).encode("utf-8")
    req = urllib.request.Request(
        f"{ORIGIN}/admin/realms/{REALM}/clients-initial-access",
        data=data, method="POST",
        headers={
            "Accept": "application/json",
            "Content-Type": "application/json",
            "Authorization": "Bearer " + temporary_admin_bearer,
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=8) as resp:
            status, data = resp.status, resp.read(16385)
    except urllib.error.HTTPError as exc:
        raise Gap("initial_access_token_creation_http_"
                  + str(exc.code)) from None
    except (urllib.error.URLError, TimeoutError):
        raise Gap("local_admin_network_error") from None
    require(status == 201 and len(data) <= 16384,
            "initial_access_token_creation_failed")
    try:
        decoded = json.loads(data.decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        raise Gap("initial_access_token_response_invalid") from None
    result = decoded.get("token")
    require(isinstance(result, str) and len(result) >= 20,
            "initial_access_token_missing")
    return result


def redeem_public(client_id: str, code: str, verifier: str) -> tuple[int, dict]:
    return request_json(
        f"{BASE}/token",
        payload={
            "grant_type": "authorization_code",
            "client_id": client_id,
            "code": code,
            "redirect_uri": CALLBACK,
            "code_verifier": verifier,
            "resource": MCP_RESOURCE,
        },
        http_error_ok=True,
    )


def main():
    require(os.environ.get("GITHUB_ACTIONS") == "true"
            or os.environ.get("CANOVIA_MCP_LOCAL_LAB") == "true",
            "requires_disposable_runner")
    admin_secret = secrets.token_urlsafe(32)
    resource_secret = secrets.token_urlsafe(36)
    password = secrets.token_urlsafe(36)
    other_password = secrets.token_urlsafe(36)
    link_secret = secrets.token_urlsafe(36)
    agent_secret = secrets.token_urlsafe(36)
    with tempfile.TemporaryDirectory(prefix="canovia-dcr-disposable-") as tmp:
        directory = Path(tmp)
        path = directory / f"{REALM}-realm.json"
        realm = pkce.synthetic_realm(
            link_secret, agent_secret, resource_secret, password, other_password
        )
        path.write_text(json.dumps(realm), encoding="utf-8")
        os.chmod(directory, 0o755)
        os.chmod(path, 0o644)
        subprocess.run([
            "docker", "run", "-d", "--name", CONTAINER,
            "--memory", "2g",
            "-p", f"127.0.0.1:{PORT}:8080",
            "-e", "KC_BOOTSTRAP_ADMIN_USERNAME=ci-admin",
            "-e", f"KC_BOOTSTRAP_ADMIN_PASSWORD={admin_secret}",
            "-v", f"{directory}:/opt/keycloak/data/import:ro",
            KEYCLOAK_IMAGE,
            "start-dev", "--features=resource-indicators,cimd",
            "--import-realm",
        ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True)
        success = False
        try:
            discovery = None
            for _ in range(85):
                try:
                    status, result = request_json(
                        f"{ISSUER}/.well-known/openid-configuration")
                    if status == 200 and result.get("issuer") == ISSUER:
                        discovery = result
                        break
                except Gap:
                    check = subprocess.run(
                        ["docker", "inspect", "--format", "{{.State.Running}}",
                         CONTAINER], capture_output=True, text=True,
                        check=False)
                    require(check.returncode == 0
                            and check.stdout.strip() == "true",
                            "disposable_keycloak_exited")
                    time.sleep(2)
            require(discovery is not None, "realm_metadata_not_ready")
            registration_endpoint = discovery.get("registration_endpoint")
            require(isinstance(registration_endpoint, str),
                    "dcr_not_advertised")
            print("real_keycloak_dcr_endpoint_discovery: pass")

            anonymous_status, _ = register_client(registration_endpoint)
            require(anonymous_status == 403,
                    "disposable_anonymous_dcr_not_denied")
            print("keycloak_anonymous_dcr_rejected_by_default: pass")

            # Keycloak's supported DCR test path uses a one-use, short-lived
            # *admin-created* Initial Access Token. ChatGPT cannot supply
            # one: this demonstrates RFC7591, not plug-and-play ChatGPT DCR.
            initial_access = create_one_use_registration_token(admin_secret)
            status, registered = register_client(registration_endpoint,
                                                 initial_access)
            require(status == 201, "iat_dcr_registration_http_" + str(status))
            client_id = registered.get("client_id")
            require(isinstance(client_id, str) and 8 <= len(client_id) <= 191,
                    "dcr_client_id_missing")
            require(registered.get("token_endpoint_auth_method") == "none",
                    "dcr_public_auth_method_not_kept")
            require(CALLBACK in registered.get("redirect_uris", []),
                    "dcr_callback_not_registered")
            require(not registered.get("client_secret"),
                    "dcr_public_client_returned_secret")
            print("real_keycloak_one_use_iat_dcr_registration: pass")

            # Reuse proven browser login + PKCE code, with a separate
            # localhost port and off-host redirect *capture only*.
            pkce.ORIGIN = ORIGIN
            pkce.ISSUER = ISSUER
            pkce.BASE = BASE
            code, verifier = pkce.authorize(
                "canovia-disposable-human", password,
                client_id, CALLBACK,
            )
            status, token = redeem_public(client_id, code, verifier)
            require(status == 200
                    and isinstance(token.get("access_token"), str),
                    "dcr_public_pkce_code_exchange_failed")
            print("real_keycloak_dcr_public_pkce_code_exchange: pass")

            state, principal = request_json(f"{BASE}/token/introspect",
                payload={"token": token["access_token"],
                         "token_type_hint": "access_token"},
                basic=(MCP_RESOURCE, resource_secret), http_error_ok=True)
            require(state == 200 and principal.get("active") is True,
                    "dcr_introspection_not_active")
            require(principal.get("client_id") == client_id,
                    "dcr_caller_client_mismatch")
            require(principal.get("iss") == ISSUER,
                    "dcr_token_issuer_mismatch")
            require(principal.get("aud") in (MCP_RESOURCE, [MCP_RESOURCE]),
                    "dcr_exact_resource_audience_missing")
            require(isinstance(principal.get("sub"), str)
                    and bool(principal["sub"]),
                    "dcr_immutable_subject_not_in_introspection")
            require(MCP_SCOPE in str(principal.get("scope", "")).split(),
                    "dcr_read_scope_missing")
            print("real_keycloak_dcr_resource_subject_introspection: pass")
            print("disposable_credentialed_dcr: passed")
            print("actual_chatgpt_client: not_tested")
            print("anonymous_chatgpt_dcr_supported: false")
            print("production_authorized: false")
            success = True
        finally:
            if not success:
                output = subprocess.run(
                    ["docker", "logs", "--tail", "50", CONTAINER],
                    capture_output=True, text=True, check=False,
                )
                lines = (output.stdout + output.stderr).splitlines()
                # Failure codes only. Do not print client metadata, tokens,
                # authorization session entries, passwords or response bodies.
                if any("error" in v.lower() for v in lines):
                    print("disposable_keycloak_dcr_error_log: present")
            subprocess.run(["docker", "rm", "-f", CONTAINER],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                           check=False)


if __name__ == "__main__":
    try:
        main()
    except (Gap, subprocess.CalledProcessError) as exc:
        code = str(exc) if isinstance(exc, Gap) else "disposable_container_error"
        print("outcome: blocked (" + code + ")")
        raise SystemExit(1)

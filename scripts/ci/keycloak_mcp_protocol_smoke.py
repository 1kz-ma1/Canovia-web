#!/usr/bin/env python3
"""Disposable Keycloak-to-MCP OAuth contract probe; no production connections.

Runs only inside GitHub Actions (or an explicitly invoked local disposable
Docker host). All accounts/clients/passwords are random and synthetic.
Prints named acceptance codes only; never prints JWTs, credentials, subjects,
client secrets or raw server responses. The Keycloak test realm is discarded.
"""

import base64
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

KEYCLOAK_IMAGE = "quay.io/keycloak/keycloak:26.8.0"
CONTAINER = "canovia-mcp-keycloak-disposable-ci"
REALM = "canovia-mcp-protocol-lab"
MCP_RESOURCE = "https://canovia-mcp-staging.onrender.com/api/mcp"
MCP_SCOPE = "canovia.development.read"
ORIGIN = "http://127.0.0.1:18080"
ISSUER = f"{ORIGIN}/realms/{REALM}"
BASE = f"{ISSUER}/protocol/openid-connect"


class Gap(Exception):
    """Protocol incompatibility: never include raw provider output."""


def require(condition: bool, stage: str) -> None:
    if not condition:
        raise Gap(stage)


def request_json(url: str, *, payload: dict | None = None,
                 basic: tuple[str, str] | None = None,
                 http_error_ok: bool = False) -> tuple[int, dict]:
    headers = {"Accept": "application/json"}
    body = None
    if payload is not None:
        body = urllib.parse.urlencode(payload).encode("ascii")
        headers["Content-Type"] = "application/x-www-form-urlencoded"
    if basic is not None:
        encoded = base64.b64encode(f"{basic[0]}:{basic[1]}".encode()).decode("ascii")
        headers["Authorization"] = f"Basic {encoded}"
    req = urllib.request.Request(url, data=body, headers=headers,
                                 method="POST" if body is not None else "GET")
    try:
        with urllib.request.urlopen(req, timeout=5) as r:
            code = r.status
            raw = r.read(16385)
    except urllib.error.HTTPError as exc:
        if not http_error_ok:
            raise Gap("provider_http_error") from None
        code = exc.code
        raw = exc.read(16385)
    except (OSError, TimeoutError):
        raise Gap("provider_network_error") from None
    require(len(raw) <= 16384, "provider_response_too_large")
    try:
        result = json.loads(raw.decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        raise Gap("provider_invalid_json") from None
    require(isinstance(result, dict), "provider_invalid_shape")
    return code, result


def new_realm(secret: str) -> dict:
    # A confidential RP (not ChatGPT), a separate resource client, and
    # a synthetic optional read scope. No user accounts or passwords.
    return {
        "realm": REALM,
        "enabled": True,
        "accessTokenLifespan": 900,
        "registrationAllowed": False,
        "resetPasswordAllowed": False,
        "clientScopes": [{
            "name": MCP_SCOPE,
            "protocol": "openid-connect",
            "attributes": {
                "include.in.token.scope": "true",
                "display.on.consent.screen": "false"
            }
        }],
        "clients": [
            {
                "clientId": "canovia-ci-mcp-resource",
                "enabled": True,
                "protocol": "openid-connect",
                "publicClient": False,
                "standardFlowEnabled": False,
                "directAccessGrantsEnabled": False,
                "serviceAccountsEnabled": False,
                "attributes": {"resource_url": MCP_RESOURCE}
            },
            {
                "clientId": "canovia-ci-mcp-client",
                "enabled": True,
                "protocol": "openid-connect",
                "secret": secret,
                "publicClient": False,
                "standardFlowEnabled": True,
                "serviceAccountsEnabled": True,
                "directAccessGrantsEnabled": False,
                "optionalClientScopes": [MCP_SCOPE],
                "redirectUris": ["https://canovia.invalid/ci/callback"],
                "protocolMappers": [{
                    "name": "canovia-disposable-resource-audience",
                    "protocol": "openid-connect",
                    "protocolMapper": "oidc-audience-mapper",
                    "consentRequired": False,
                    "config": {
                        "included.client.audience": "canovia-ci-mcp-resource",
                        "id.token.claim": "false",
                        "access.token.claim": "true"
                    }
                }]
            }
        ]
    }


def run_lab() -> None:
    if os.environ.get("GITHUB_ACTIONS") != "true" and os.environ.get("CANOVIA_MCP_LOCAL_LAB") != "true":
        raise Gap("requires_ephemeral_ci_or_explicit_local_lab")
    rp_secret = secrets.token_urlsafe(36)
    admin_password = secrets.token_urlsafe(36)

    with tempfile.TemporaryDirectory(prefix="canovia-keycloak-ci-") as tmp:
        home = Path(tmp)
        path = home / "canovia-mcp-realm.json"
        path.write_text(json.dumps(new_realm(rp_secret)), encoding="utf8")
        # The Keycloak image runs with an unprivileged UID: its bind-mounted
        # directory and ephemeral file must be readable by that UID. All
        # credentials here are random CI-only values on an isolated runner.
        os.chmod(home, 0o755)
        os.chmod(path, 0o644)

        subprocess.run(["docker", "run", "-d", "--name", CONTAINER,
                        "--memory", "2g",
                        "-p", "127.0.0.1:18080:8080",
                        "-e", "KC_BOOTSTRAP_ADMIN_USERNAME=ci-admin",
                        "-e", f"KC_BOOTSTRAP_ADMIN_PASSWORD={admin_password}",
                        "-v", f"{home}:/opt/keycloak/data/import:ro",
                        KEYCLOAK_IMAGE,
                        "start-dev", "--features=resource-indicators,cimd",
                        "--import-realm"],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        print("keycloak_disposable_container: started")
        try:
            discovery = None
            for _ in range(110):
                try:
                    code, metadata = request_json(
                        f"{ISSUER}/.well-known/openid-configuration")
                    if code == 200:
                        discovery = metadata
                        break
                except Gap:
                    status = subprocess.run(
                        ["docker", "inspect", "--format", "{{.State.Running}}",
                         CONTAINER],
                        capture_output=True, text=True, check=False,
                    )
                    if status.returncode != 0 or status.stdout.strip() != "true":
                        raise Gap("disposable_container_exited_before_discovery")
                    time.sleep(2)
            require(discovery is not None, "realm_discovery_not_ready")
            require(discovery.get("issuer") == ISSUER, "issuer_mismatch")
            require(discovery.get("authorization_endpoint") == f"{BASE}/auth",
                    "authorization_endpoint_mismatch")
            require(discovery.get("token_endpoint") == f"{BASE}/token",
                    "token_endpoint_mismatch")
            require(discovery.get("introspection_endpoint") == f"{BASE}/token/introspect",
                    "introspection_endpoint_mismatch")
            require("S256" in discovery.get("code_challenge_methods_supported", []),
                    "pkce_s256_not_advertised")
            require(discovery.get("authorization_response_iss_parameter_supported") is True,
                    "rfc9207_issuer_not_advertised")
            print("oauth_metadata_issuer_pkce_introspection: pass")

            # This validates an actual provider-issued non-user service token.
            # It does not prove browser login, consent or ChatGPT connectivity.
            client = ("canovia-ci-mcp-client", rp_secret)
            status, token_result = request_json(f"{BASE}/token",
                payload={
                    "grant_type": "client_credentials",
                    "scope": MCP_SCOPE,
                    "resource": MCP_RESOURCE,
                }, basic=client, http_error_ok=True)
            require(status == 200, "resource_bound_token_issuance_failed")
            token = token_result.get("access_token")
            require(isinstance(token, str) and len(token) >= 16,
                    "access_token_missing")

            status, inspect = request_json(f"{BASE}/token/introspect",
                payload={"token": token, "token_type_hint": "access_token"},
                basic=client, http_error_ok=True)
            require(status == 200, "introspection_http_failed")
            require(inspect.get("active") is True, "introspection_not_active")
            require(inspect.get("client_id") == client[0], "client_id_claim_mismatch")
            require(inspect.get("iss") == ISSUER, "issuer_claim_mismatch")
            require(isinstance(inspect.get("sub"), str) and bool(inspect["sub"]),
                    "subject_claim_missing")
            aud = inspect.get("aud")
            require(aud == MCP_RESOURCE or aud == [MCP_RESOURCE],
                    "exact_single_resource_audience_mismatch")
            scopes = inspect.get("scope")
            require(isinstance(scopes, str) and MCP_SCOPE in scopes.split(" "),
                    "read_scope_missing")
            require(isinstance(inspect.get("exp"), int)
                    and inspect["exp"] > time.time()
                    and inspect["exp"] <= time.time() + 3600,
                    "token_expiry_out_of_bounds")
            require(inspect.get("token_type", "").lower() == "bearer",
                    "bearer_claim_missing")
            print("real_token_rfc8707_audience_and_rfc7662_introspection: pass")

            status, invalid = request_json(f"{BASE}/token",
                payload={
                    "grant_type": "client_credentials",
                    "scope": MCP_SCOPE,
                    "resource": "https://wrong.example.invalid/api/mcp",
                }, basic=client, http_error_ok=True)
            require(status == 400 and invalid.get("error") == "invalid_target",
                    "foreign_resource_not_rejected")
            print("foreign_resource_invalid_target: pass")

            status, revoked = request_json(f"{BASE}/token/introspect",
                payload={"token": "synthetic-invalid-token-ci-not-a-real-bearer"},
                basic=client, http_error_ok=True)
            require(status == 200 and revoked.get("active") is False,
                    "invalid_token_not_rejected")
            print("introspection_invalid_token: pass")

            print("outcome: protocol_lab_passed")
            print("production_authorized: false")
            print("chatgpt_oauth_end_to_end: not_tested")
        finally:
            subprocess.run(["docker", "rm", "-f", CONTAINER],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                           check=False)


if __name__ == "__main__":
    try:
        run_lab()
    except (Gap, subprocess.CalledProcessError) as exc:
        code = str(exc) if isinstance(exc, Gap) else "disposable_container_error"
        # Safe named codes only; never show raw provider data or credentials.
        print(f"outcome: blocked ({code})")
        raise SystemExit(1)

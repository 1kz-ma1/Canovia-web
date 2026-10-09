#!/usr/bin/env python3
"""Real disposable Keycloak private_key_jwt + PKCE acceptance, synthetic ONLY.

Generated private keys and human accounts belong to this one isolated CI run.
This is not signed by ChatGPT and does not grant access to Canovia. All
callbacks terminate at reserved .invalid hosts; never follow them.
"""

import base64
from copy import deepcopy
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
    KEYCLOAK_IMAGE, MCP_RESOURCE, MCP_SCOPE, REALM, Gap,
    request_json, require,
)
import keycloak_user_pkce_smoke as pkce


# Reuse the proven browser harness's strictly pinned 18081 loopback origin.
# The preceding CI step has removed its own separate Keycloak container.
CONTAINER = "canovia-mcp-keycloak-static-jwt-ci"
ORIGIN = "http://127.0.0.1:18081"
ISSUER = f"{ORIGIN}/realms/{REALM}"
BASE = f"{ISSUER}/protocol/openid-connect"
CLIENT_ID = "canovia-ci-synthetic-private-jwt"
CALLBACK = "https://chatgpt.invalid/ci/callback"
USERNAME = "canovia-disposable-human"
FOREIGN_RESOURCE = "https://wrong.example.invalid/api/mcp"
ASSERTION_TYPE = "urn:ietf:params:oauth:client-assertion-type:jwt-bearer"


def b64url(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode("ascii")


def new_key_and_certificate(directory: Path, stem: str) -> tuple[Path, str]:
    """One-off RSA key for the CI fake RP; private PEM never gets mounted."""
    private_key = directory / f"{stem}-private.pem"
    certificate = directory / f"{stem}-cert.pem"
    subprocess.run([
        "openssl", "req", "-x509", "-newkey", "rsa:2048",
        "-sha256", "-nodes", "-days", "1", "-subj", "/CN=disposable-ci",
        "-keyout", str(private_key), "-out", str(certificate),
    ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True)
    os.chmod(private_key, 0o600)
    result = subprocess.run([
        "openssl", "x509", "-in", str(certificate), "-outform", "DER",
    ], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=True)
    require(256 <= len(result.stdout) <= 4096, "synthetic_certificate_invalid")
    return private_key, base64.b64encode(result.stdout).decode("ascii")


def assertion(key: Path, client_id: str = CLIENT_ID) -> str:
    issued = int(time.time())
    header = b64url(json.dumps(
        {"alg": "RS256", "typ": "JWT"}, separators=(",", ":")
    ).encode("ascii"))
    claims = b64url(json.dumps({
        "iss": client_id,
        "sub": client_id,
        "aud": f"{BASE}/token",
        "iat": issued,
        "exp": issued + 90,
        "jti": secrets.token_urlsafe(32),
    }, separators=(",", ":")).encode("ascii"))
    signing_input = f"{header}.{claims}"
    signed = subprocess.run([
        "openssl", "dgst", "-sha256", "-sign", str(key),
    ], input=signing_input.encode("ascii"), stdout=subprocess.PIPE,
       stderr=subprocess.DEVNULL, check=True)
    require(len(signed.stdout) >= 256, "synthetic_assertion_sign_failed")
    return signing_input + "." + b64url(signed.stdout)


def token_exchange(key: Path, code: str, verifier: str,
                   resource: str = MCP_RESOURCE) -> tuple[int, dict]:
    return request_json(f"{BASE}/token", payload={
        "grant_type": "authorization_code",
        "client_id": CLIENT_ID,
        "client_assertion_type": ASSERTION_TYPE,
        "client_assertion": assertion(key),
        "code": code,
        "redirect_uri": CALLBACK,
        "code_verifier": verifier,
        "resource": resource,
    }, http_error_ok=True)


def revoke(key: Path, access_token: str) -> None:
    payload = urllib.parse.urlencode({
        "token": access_token,
        "token_type_hint": "access_token",
        "client_id": CLIENT_ID,
        "client_assertion_type": ASSERTION_TYPE,
        "client_assertion": assertion(key),
    }).encode("ascii")
    request = urllib.request.Request(f"{BASE}/revoke", method="POST",
        data=payload, headers={
            "Accept": "application/json",
            "Content-Type": "application/x-www-form-urlencoded",
        })
    try:
        with urllib.request.urlopen(request, timeout=8) as response:
            require(response.status == 200, "synthetic_jwt_revocation_http_failure")
            require(len(response.read(4097)) <= 4096,
                    "synthetic_jwt_revocation_response_too_large")
    except (urllib.error.HTTPError, urllib.error.URLError, TimeoutError):
        raise Gap("synthetic_jwt_revocation_rejected") from None


def raw_introspection(access_token: str, resource_secret: str) -> dict:
    code, data = request_json(f"{BASE}/token/introspect", payload={
        "token": access_token, "token_type_hint": "access_token",
    }, basic=(MCP_RESOURCE, resource_secret), http_error_ok=True)
    require(code == 200, "synthetic_introspection_http_failure")
    return data


def run() -> None:
    require(os.environ.get("GITHUB_ACTIONS") == "true"
            or os.environ.get("CANOVIA_MCP_LOCAL_LAB") == "true",
            "synthetic_disposable_runner_only")
    password = secrets.token_urlsafe(36)
    second_password = secrets.token_urlsafe(36)
    resource_secret = secrets.token_urlsafe(36)
    admin_password = secrets.token_urlsafe(36)

    with tempfile.TemporaryDirectory(prefix="canovia-static-jwt-") as temp:
        root = Path(temp)
        private_key, certificate = new_key_and_certificate(root, "allowed")
        wrong_key, _ = new_key_and_certificate(root, "forged")
        # Mount only the public synthetic realm, NOT either private key.
        imports = root / "import"
        imports.mkdir()
        realm = pkce.synthetic_realm(
            secrets.token_urlsafe(36), secrets.token_urlsafe(36),
            resource_secret, password, second_password,
        )
        synthetic = deepcopy(realm["clients"][-1])
        synthetic["clientId"] = CLIENT_ID
        synthetic.pop("secret", None)
        synthetic["clientAuthenticatorType"] = "client-jwt"
        synthetic["publicClient"] = False
        synthetic["standardFlowEnabled"] = True
        synthetic["serviceAccountsEnabled"] = False
        synthetic["consentRequired"] = False
        synthetic["redirectUris"] = [CALLBACK]
        synthetic["attributes"] = {
            "use.jwks.url": "false",
            "jwt.credential.certificate": certificate,
            "pkce.code.challenge.method": "S256",
        }
        realm["clients"].append(synthetic)
        file = imports / f"{REALM}-realm.json"
        file.write_text(json.dumps(realm), encoding="utf8")
        os.chmod(imports, 0o755)
        os.chmod(file, 0o644)
        subprocess.run([
            "docker", "run", "-d", "--name", CONTAINER, "--memory", "2g",
            "-p", "127.0.0.1:18081:8080",
            "-e", "KC_BOOTSTRAP_ADMIN_USERNAME=ci-admin",
            "-e", f"KC_BOOTSTRAP_ADMIN_PASSWORD={admin_password}",
            "-v", f"{imports}:/opt/keycloak/data/import:ro",
            KEYCLOAK_IMAGE, "start-dev",
            "--features=resource-indicators,cimd", "--import-realm",
        ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True)
        try:
            for _ in range(90):
                try:
                    status, metadata = request_json(
                        f"{ISSUER}/.well-known/openid-configuration")
                    if status == 200 and metadata.get("issuer") == ISSUER:
                        break
                except Gap:
                    pass
                check = subprocess.run([
                    "docker", "inspect", "--format", "{{.State.Running}}",
                    CONTAINER,
                ], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                   text=True, check=False)
                require(check.returncode == 0 and check.stdout.strip() == "true",
                        "synthetic_jwt_keycloak_exited")
                time.sleep(2)
            else:
                raise Gap("synthetic_jwt_keycloak_unready")

            # pkce.authorize() has verified loopback-only redirect handling,
            # callback state+iss, an actual browser login and S256 verifier.
            pkce.ORIGIN, pkce.ISSUER, pkce.BASE = ORIGIN, ISSUER, BASE
            code, verifier = pkce.authorize(USERNAME, password, CLIENT_ID, CALLBACK)
            status, valid = token_exchange(private_key, code, verifier)
            require(status == 200 and isinstance(valid.get("access_token"), str),
                    "synthetic_private_key_jwt_token_exchange_failed")
            print("synthetic_private_key_jwt_user_pkce_exchange: pass")
            access_token = valid["access_token"]
            claims = raw_introspection(access_token, resource_secret)
            require(claims.get("active") is True
                    and claims.get("iss") == ISSUER
                    and claims.get("client_id") == CLIENT_ID
                    and isinstance(claims.get("sub"), str)
                    and 0 < len(claims["sub"]) <= 255
                    and claims.get("aud") in (MCP_RESOURCE, [MCP_RESOURCE])
                    and isinstance(claims.get("scope"), str)
                    and MCP_SCOPE in claims["scope"].split()
                    and isinstance(claims.get("exp"), int)
                    and time.time() < claims["exp"] <= time.time() + 3600
                    and claims.get("token_type") == "Bearer",
                    "synthetic_private_key_jwt_strict_claims_invalid")
            print("synthetic_private_key_jwt_exact_resource_claims: pass")

            code, verifier = pkce.authorize(USERNAME, password, CLIENT_ID, CALLBACK)
            status, wrong = token_exchange(
                private_key, code, verifier, FOREIGN_RESOURCE)
            require(status == 400 and wrong.get("error") == "invalid_target"
                    and not wrong.get("access_token"),
                    "synthetic_wrong_resource_token_not_denied")
            print("synthetic_private_key_jwt_wrong_resource_denied: pass")

            code, verifier = pkce.authorize(USERNAME, password, CLIENT_ID, CALLBACK)
            status, forged = token_exchange(wrong_key, code, verifier)
            require(status in (400, 401) and forged.get("error") == "invalid_client"
                    and not forged.get("access_token"),
                    "synthetic_private_key_jwt_wrong_signer_not_denied")
            print("synthetic_private_key_jwt_untrusted_signer_denied: pass")

            code, verifier = pkce.authorize(USERNAME, password, CLIENT_ID, CALLBACK)
            status, invalid_pkce = token_exchange(
                private_key, code, verifier + "tamper")
            require(status == 400 and invalid_pkce.get("error") == "invalid_grant"
                    and not invalid_pkce.get("access_token"),
                    "synthetic_private_key_jwt_wrong_pkce_not_denied")
            print("synthetic_private_key_jwt_wrong_pkce_denied: pass")

            revoke(private_key, access_token)
            revoked = raw_introspection(access_token, resource_secret)
            require(revoked.get("active") is False,
                    "synthetic_private_key_jwt_revocation_not_enforced")
            print("synthetic_private_key_jwt_revocation: pass")
            print("real_chatgpt_assertion: not_tested")
            print("real_chatgpt_client_callback: not_tested")
            print("canovia_plan_consent: not_tested_in_this_lab")
            print("production_authorized: false")
        finally:
            subprocess.run(["docker", "rm", "-f", CONTAINER],
                           stdout=subprocess.DEVNULL,
                           stderr=subprocess.DEVNULL, check=False)


if __name__ == "__main__":
    try:
        run()
    except (Gap, subprocess.CalledProcessError) as error:
        kind = str(error) if isinstance(error, Gap) else "synthetic_container_or_key_error"
        print("outcome: blocked (" + kind + ")")
        raise SystemExit(1)

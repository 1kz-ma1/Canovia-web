#!/usr/bin/env python3
"""Public-only compatibility characterization of ChatGPT CIMD vs Auth0 Free.

No credentials, management APIs, OAuth exchange, user data, MCP server traffic,
registrations, environment flags or staging deployments. Metadata cannot prove
a real ChatGPT OAuth client enrollment or token issuance.
"""

import argparse
import json
import sys
from pathlib import Path
from urllib.request import Request, build_opener, HTTPRedirectHandler

AUTH0_DOMAIN = "canovia-mcp-dev.jp.auth0.com"
AUTH0_ISSUER = f"https://{AUTH0_DOMAIN}/"
AUTH0_METADATA = AUTH0_ISSUER + ".well-known/openid-configuration"
CHATGPT_CIMD = "https://chatgpt.com/oauth/client.json"
CHATGPT_CALLBACK = "https://chatgpt.com/connector_platform_oauth_redirect"
CHATGPT_JWKS = "https://chatgpt.com/oauth/jwks.json"


class RejectRedirects(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def public_json(url, limit):
    """Only call with one of the three fixed, server-authored public URLs."""
    if url not in (AUTH0_METADATA, CHATGPT_CIMD, CHATGPT_JWKS):
        raise ValueError("forbidden_public_url")
    request = Request(url, headers={"Accept": "application/json",
                                    "User-Agent": "Canovia-CIMD-ReadOnly-Preflight/1"})
    with build_opener(RejectRedirects()).open(request, timeout=6) as response:
        if (response.status != 200 or
                "application/json" not in response.headers.get("Content-Type", "").lower()):
            raise ValueError("invalid_public_json_response")
        payload = response.read(limit + 1)
    if len(payload) > limit:
        raise ValueError("public_json_too_large")
    return json.loads(payload)


def assess(client, jwks, issuer):
    checks = {
        "chatgpt_client_json": "blocked",
        "client_id_exact": "blocked",
        "stable_callback": "blocked",
        "pkce_authorization_code": "blocked",
        "free_tier_public_none_method": "not_advertised",
        "private_key_jwt_method": "not_advertised",
        "jwks_uri_pinned": "blocked",
        "public_rsa_jwks": "blocked",
        "auth0_issuer_exact": "blocked",
        "auth0_pkce_s256": "blocked",
        "auth0_rfc9207": "blocked",
        "auth0_public_none_method": "not_advertised",
        "auth0_cimd_discovery": "not_advertised",
        "auth0_client_import": "requires_real_tenant_registration",
        "auth0_client_grants": "requires_real_tenant_review",
        "actual_jwt_audience_scope_and_client": "requires_real_token_validation",
        "cimd_register_endpoint_compatibility": "requires_real_tenant_test",
    }

    if isinstance(client, dict):
        checks["chatgpt_client_json"] = "pass"
        checks["client_id_exact"] = "pass" if client.get("client_id") == CHATGPT_CIMD else "blocked"
        checks["stable_callback"] = ("pass" if isinstance(client.get("redirect_uris"), list)
                                     and CHATGPT_CALLBACK in client["redirect_uris"] else "blocked")
        checks["pkce_authorization_code"] = ("pass"
            if isinstance(client.get("grant_types"), list)
            and "authorization_code" in client["grant_types"]
            and isinstance(client.get("response_types"), list)
            and "code" in client["response_types"]
            else "blocked")
        methods = client.get("token_endpoint_auth_methods_supported")
        if not isinstance(methods, list):
            methods = []
        legacy = client.get("token_endpoint_auth_method")
        if isinstance(legacy, str):
            methods.append(legacy)
        if "none" in methods:
            checks["free_tier_public_none_method"] = "pass"
        if "private_key_jwt" in methods:
            checks["private_key_jwt_method"] = "pass"
        checks["jwks_uri_pinned"] = "pass" if client.get("jwks_uri") == CHATGPT_JWKS else "blocked"

    if isinstance(jwks, dict):
        keys = jwks.get("keys")
        if (isinstance(keys, list) and 1 <= len(keys) <= 8
            and all(isinstance(k, dict) and k.get("kty") == "RSA" and
                    k.get("use") == "sig" and k.get("alg") == "RS256" and
                    isinstance(k.get("kid"), str) and k.get("kid") and
                    isinstance(k.get("n"), str) and len(k["n"]) >= 340 and
                    isinstance(k.get("e"), str) and
                    all(x not in k for x in ("d", "p", "q", "dp", "dq", "qi"))
                    for k in keys)):
            checks["public_rsa_jwks"] = "pass"

    if isinstance(issuer, dict):
        checks["auth0_issuer_exact"] = ("pass" if issuer.get("issuer") == AUTH0_ISSUER else "blocked")
        checks["auth0_pkce_s256"] = ("pass" if isinstance(issuer.get("code_challenge_methods_supported"), list)
                                   and "S256" in issuer["code_challenge_methods_supported"] else "blocked")
        checks["auth0_rfc9207"] = ("pass" if issuer.get("authorization_response_iss_parameter_supported") is True
                                   else "blocked")
        methods = issuer.get("token_endpoint_auth_methods_supported")
        if isinstance(methods, list) and "none" in methods:
            checks["auth0_public_none_method"] = "pass"
        if issuer.get("client_id_metadata_document_supported") is True:
            checks["auth0_cimd_discovery"] = "pass"

    public_compatible = all(checks[k] == "pass" for k in (
        "chatgpt_client_json", "client_id_exact", "stable_callback",
        "pkce_authorization_code", "free_tier_public_none_method",
        "jwks_uri_pinned", "public_rsa_jwks", "auth0_issuer_exact",
        "auth0_pkce_s256", "auth0_rfc9207", "auth0_public_none_method"))

    return {
        "schema": "canovia.mcp.chatgpt_auth0_public_cimd_preflight.v1",
        "checks": checks,
        "public_metadata_compatible_with_free_candidate": public_compatible,
        "auth0_cimd_registration_proven": False,
        "actual_chatgpt_login_proven": False,
        "staging_activation_authorized": False,
        "production_authorized": False,
        "next_step": ("enable_tenant_cimd_toggle_and_preview_import"
                      if checks["auth0_cimd_discovery"] != "pass"
                      else "preview_exact_client_in_auth0_dashboard"),
    }


def read_fixture(filename, limit):
    contents = Path(filename).read_bytes()
    if len(contents) > limit:
        raise ValueError("fixture_too_large")
    return json.loads(contents)


def main(argv=None):
    parser = argparse.ArgumentParser(description="Check only public ChatGPT/Auth0 CIMD shape")
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--live", action="store_true", help="GET only pinned public URLs, no credentials")
    group.add_argument("--fixture-client", help="Offline ChatGPT CIMD JSON fixture")
    parser.add_argument("--fixture-jwks", help="Offline ChatGPT public JWKS JSON fixture")
    parser.add_argument("--fixture-auth0", help="Offline Auth0 public metadata fixture")
    args = parser.parse_args(argv)
    try:
        if args.live:
            if args.fixture_jwks or args.fixture_auth0:
                raise ValueError("unexpected_fixture_options")
            client = public_json(CHATGPT_CIMD, 5120)
            jwks = public_json(CHATGPT_JWKS, 12288)
            issuer = public_json(AUTH0_METADATA, 12288)
        else:
            if not args.fixture_jwks or not args.fixture_auth0:
                raise ValueError("all_fixture_options_required")
            client = read_fixture(args.fixture_client, 5120)
            jwks = read_fixture(args.fixture_jwks, 12288)
            issuer = read_fixture(args.fixture_auth0, 12288)
        result = assess(client, jwks, issuer)
    except (ValueError, OSError, TypeError, UnicodeError, json.JSONDecodeError):
        result = assess(None, None, None)
    print(json.dumps(result, sort_keys=True, separators=(",", ":")))
    return 0 if result["public_metadata_compatible_with_free_candidate"] else 1


if __name__ == "__main__":
    sys.exit(main())

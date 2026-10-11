#!/usr/bin/env python3
"""Read-only, secretless Auth0 OAuth discovery characterization for Canovia MCP.

This is a diagnostic, NOT a JWT verifier or a permission to enable MCP.
Run using a synthetic JSON file, or an explicitly chosen public Auth0 tenant.
No bearer credentials, users, Canovia Plans, tokens or stage routes are queried.
"""

import argparse
import json
import re
import sys
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import Request, build_opener, HTTPRedirectHandler

MAX_METADATA_BYTES = 12288
TENANT_RE = re.compile(r"\A(?=.{5,253}\Z)[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\.auth0\.com\Z", re.ASCII)


class BlockRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def origin_url(url, issuer):
    if not isinstance(url, str) or len(url) > 512:
        return False
    try:
        p = urlsplit(url)
        i = urlsplit(issuer)
        return (p.scheme == "https" and p.hostname == i.hostname and
                p.port in (None, 443) and p.username is None and p.password is None and
                not p.query and not p.fragment and p.path not in ("", "/"))
    except ValueError:
        return False


def assess(metadata, issuer):
    """Evaluate public discovery metadata only; never evaluate raw JWTs."""
    checks = {
        "discovery_object": "blocked",
        "issuer_exact": "blocked",
        "authorization_endpoint": "blocked",
        "token_endpoint": "blocked",
        "jwks_uri": "blocked",
        "pkce_s256": "not_advertised",
        "callback_issuer_rfc9207": "not_advertised",
        "chatgpt_auth_method": "not_advertised",
        "cimd_discovery": "not_advertised",
        "resource_parameter_profile": "requires_tenant_review",
        "actual_chatgpt_client_id": "requires_real_registration",
        "real_token_signature_and_claims": "requires_real_token_validation",
        "single_exact_audience": "requires_real_token_validation",
    }
    if not isinstance(metadata, dict):
        return report(checks)
    checks["discovery_object"] = "pass"
    checks["issuer_exact"] = "pass" if metadata.get("issuer") == issuer else "blocked"
    for k in ("authorization_endpoint", "token_endpoint", "jwks_uri"):
        checks[k] = "pass" if origin_url(metadata.get(k), issuer) else "blocked"
    methods = metadata.get("code_challenge_methods_supported")
    if isinstance(methods, list) and "S256" in methods:
        checks["pkce_s256"] = "pass"
    if metadata.get("authorization_response_iss_parameter_supported") is True:
        checks["callback_issuer_rfc9207"] = "pass"
    methods = metadata.get("token_endpoint_auth_methods_supported")
    if isinstance(methods, list) and any(m in ("none", "private_key_jwt") for m in methods):
        checks["chatgpt_auth_method"] = "pass"
    if metadata.get("client_id_metadata_document_supported") is True:
        checks["cimd_discovery"] = "pass"
    return report(checks)


def report(checks):
    # No data from metadata or account identifiers in stdout.
    metadata_gate = all(checks[k] == "pass" for k in (
        "discovery_object", "issuer_exact", "authorization_endpoint", "token_endpoint",
        "jwks_uri", "pkce_s256", "callback_issuer_rfc9207", "chatgpt_auth_method"))
    return {
        "schema": "canovia.mcp.auth0_public_discovery_characterization.v1",
        "checks": checks,
        "metadata_gate_passed": metadata_gate,
        "stage_mcp_activation_authorized": False,
        "production_authorized": False,
        "real_chatgpt_oauth_verified": False,
        "real_token_verified": False,
    }


def read_fixture(path):
    data = Path(path).read_bytes()
    if len(data) > MAX_METADATA_BYTES:
        raise ValueError("metadata_too_large")
    return json.loads(data)


def fetch_metadata(tenant_domain):
    if not isinstance(tenant_domain, str) or not TENANT_RE.fullmatch(tenant_domain):
        raise ValueError("invalid_auth0_tenant_domain")
    url = f"https://{tenant_domain}/.well-known/openid-configuration"
    # Do not follow redirects to another host or transmit any credentials.
    opener = build_opener(BlockRedirect())
    request = Request(url, headers={"Accept": "application/json", "User-Agent": "Canovia-MCP-ReadOnly-Characterization/1"})
    with opener.open(request, timeout=5) as res:
        if res.status != 200 or "application/json" not in res.headers.get("Content-Type", "").lower():
            raise ValueError("unexpected_discovery_response")
        data = res.read(MAX_METADATA_BYTES + 1)
    if len(data) > MAX_METADATA_BYTES:
        raise ValueError("metadata_too_large")
    return json.loads(data), f"https://{tenant_domain}/"


def main(argv=None):
    parser = argparse.ArgumentParser(description="Metadata-only compatibility check; never authorizes staging activation")
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument("--tenant-domain", help="Public *.auth0.com domain only; never an admin URL")
    source.add_argument("--fixture", help="Synthetic/local public discovery JSON for offline characterization")
    parser.add_argument("--expected-issuer", help="Required only with --fixture; exact HTTPS issuer")
    args = parser.parse_args(argv)
    try:
        if args.tenant_domain:
            if args.expected_issuer is not None:
                raise ValueError("unexpected_issuer_override")
            metadata, issuer = fetch_metadata(args.tenant_domain)
        else:
            if not isinstance(args.expected_issuer, str) or not args.expected_issuer.startswith("https://"):
                raise ValueError("expected_issuer_required")
            issuer = args.expected_issuer
            metadata = read_fixture(args.fixture)
        result = assess(metadata, issuer)
    except (ValueError, OSError, TypeError, UnicodeError, HTTPError, URLError):
        result = report({"discovery_object": "blocked"})
    print(json.dumps(result, ensure_ascii=True, separators=(",", ":"), sort_keys=True))
    return 0 if result["metadata_gate_passed"] else 1


if __name__ == "__main__":
    sys.exit(main())

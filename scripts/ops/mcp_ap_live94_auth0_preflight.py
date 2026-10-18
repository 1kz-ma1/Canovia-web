#!/usr/bin/env python3
"""Secretless, environment-only PRE-DEPLOY shape check for one-owner AP MCP.

No outbound requests, Laravel application bootstrap, DB reads, tokens or writes.
Run privately with the effective exported production environment (not a .env
file path). Never post env values, IDs, secrets, or rendered config to CI/chat.

A successful run ALWAYS requires independent owner GO and runtime acceptance.
"""
from __future__ import annotations

import ipaddress
import json
import os
import re
import sys
from urllib.parse import urlsplit

FLAGS = (
    "CANOVIA_MCP_DISCOVERY_ENABLED",
    "CANOVIA_MCP_TOOLS_ENABLED",
    "CANOVIA_MCP_AP_PILOT_ENABLED",
    "CANOVIA_MCP_STUDY_READ_ENABLED",
    "CANOVIA_MCP_AUTH0_JWT_VERIFICATION_ENABLED",
    "CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED",
    "CANOVIA_MCP_ACCOUNT_LINK_ENABLED",
    "CANOVIA_MCP_ACCOUNT_LINK_GITHUB_ONLY_ENABLED",
    "CANOVIA_MCP_PLAN_CONSENT_ENABLED",
    "CANOVIA_MCP_DELEGATED_POLICY_ENABLED",
)

def safe_host(url: str, *, auth0: bool = False, trailing_slash: bool = False) -> bool:
    if not isinstance(url, str) or not url or len(url) > 512 or url != url.strip():
        return False
    try:
        p = urlsplit(url)
        if p.scheme != "https" or not p.netloc or p.username or p.password:
            return False
        if p.query or p.fragment or (p.port is not None and p.port != 443):
            return False
        host = p.hostname
        if not host or not re.fullmatch(r"[a-z0-9.-]+", host):
            return False
        if host == "localhost" or host.endswith(".localhost"):
            return False
        if any(not label or len(label) > 63 or label.startswith("-")
               or label.endswith("-") for label in host.split(".")):
            return False
        try:
            ipaddress.ip_address(host)
            return False
        except ValueError:
            pass
        if auth0 and not re.fullmatch(
            r"[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.auth0\.com", host
        ):
            return False
        expected_path = "/" if trailing_slash else ""
        # Runtime JWT verifier pins an exact canonical lower-case issuer
        # and disallows even explicit :443. Enforce the same form offline.
        return p.path == expected_path and url == "https://" + host + expected_path
    except (ValueError, TypeError):
        return False


def evaluate(env: dict[str, str]) -> dict:
    """Return fixed codes and booleans ONLY, never actual env values."""
    blocked: list[str] = []
    def require(ok: bool, code: str) -> None:
        if not ok:
            blocked.append(code)

    require(env.get("APP_ENV") == "production"
            and env.get("APP_DEBUG", "").lower() == "false",
            "PRODUCTION_MODE_NOT_CONFIRMED")
    require(env.get("DB_CONNECTION") == "mysql", "PRODUCTION_MYSQL_NOT_CONFIRMED")
    # Laravel DB_URL may supersede the individual mysql settings; no predeploy
    # shape-only success when a separate, uninspected URL could retarget data.
    require(not env.get("DB_URL") and not env.get("DATABASE_URL"),
            "DATABASE_URL_PRECEDENCE_NEEDS_SEPARATE_AUDIT")

    app_url = env.get("APP_URL", "")
    issuer = env.get("CANOVIA_MCP_OAUTH_ISSUER", "")
    resource = env.get("CANOVIA_MCP_RESOURCE_URL", "")
    require(safe_host(app_url), "APP_ORIGIN_UNTRUSTED")
    require(safe_host(issuer, auth0=True, trailing_slash=True),
            "AUTH0_ISSUER_UNTRUSTED")
    require(bool(app_url) and resource == app_url + "/api/mcp",
            "MCP_RESOURCE_ORIGIN_MISMATCH")

    targets = (
        ("CANOVIA_MCP_ACCOUNT_LINK_METADATA_URL",
         issuer + ".well-known/openid-configuration"),
        ("CANOVIA_MCP_ACCOUNT_LINK_AUTHORIZATION_ENDPOINT", issuer + "authorize"),
        ("CANOVIA_MCP_ACCOUNT_LINK_TOKEN_ENDPOINT", issuer + "oauth/token"),
        ("CANOVIA_MCP_ACCOUNT_LINK_REDIRECT_URI",
         app_url + "/account/mcp/link/callback"),
    )
    for key, target in targets:
        require(bool(target) and env.get(key) == target,
                "MCP_OWNED_ENDPOINT_MISMATCH")

    client = env.get("CANOVIA_MCP_ALLOWED_CLIENT_ID", "")
    link_client = env.get("CANOVIA_MCP_ACCOUNT_LINK_CLIENT_ID", "")
    require(bool(re.fullmatch(r"tpc_[a-zA-Z0-9_-]{4,187}", client)),
            "CHATGPT_CIMD_INTERNAL_CLIENT_UNVERIFIED")
    require(bool(re.fullmatch(r"[a-zA-Z0-9_-]{4,191}", link_client))
            and not link_client.startswith("tpc_") and link_client != client,
            "CONFIDENTIAL_LINK_CLIENT_NOT_DISTINCT")
    app_key = env.get("APP_KEY", "")
    link_secret = env.get("CANOVIA_MCP_ACCOUNT_LINK_CLIENT_SECRET", "")
    fingerprint_key = env.get("CANOVIA_MCP_IDENTITY_FINGERPRINT_KEY", "")
    require(len(link_secret) >= 16 and link_secret != app_key,
            "LINK_SECRET_MISSING_OR_REUSED")
    require(len(fingerprint_key) >= 32
            and fingerprint_key not in {app_key, link_secret},
            "IDENTITY_HMAC_KEY_MISSING_OR_REUSED")

    owner_id = env.get("CANOVIA_MCP_AP_PILOT_OWNER_ID", "")
    require(bool(re.fullmatch(r"[1-9][0-9]{0,15}", owner_id)),
            "EXACT_OWNER_ALLOWLIST_MISSING")
    require(env.get("CANOVIA_MCP_TOKEN_VERIFICATION_DRIVER") == "auth0_jwt",
            "JWT_TRUST_DRIVER_NOT_SELECTED")
    # Require OFF for first deploy. A separately approved enablement flow
    # requires fresh acceptance; this check is NOT an enablement tool.
    require(all(env.get(flag, "false").lower() in {"false", "0"}
                for flag in FLAGS), "MCP_PREDEPLOY_OFF_CONTRACT_VIOLATED")

    # No HTTP, filesystem reads, subprocesses, DB, Laravel, raw claims or IDs.
    return {
        "result": "BLOCK" if blocked else "REVIEW_REQUIRED",
        "codes": sorted(set(blocked)) if blocked else ["OFFLINE_SHAPE_ONLY_NOT_GO"],
        "phase": "predeploy_all_mcp_flags_off",
        "credentials_or_identifiers_disclosed": False,
        "network_or_database_access_performed": False,
        "production_release_authorized": False,
        "auth0_real_token_verified": False,
        "real_aiven_ledger_verified": False,
        "restorable_backup_verified": False,
        "github_required_checks_verified": False,
    }


if __name__ == "__main__":
    verdict = evaluate(dict(os.environ))
    print(json.dumps(verdict, ensure_ascii=False, sort_keys=True))
    sys.exit(0 if verdict["result"] == "REVIEW_REQUIRED" else 2)

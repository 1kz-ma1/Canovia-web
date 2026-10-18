#!/usr/bin/env python3
"""Offline fail-closed source inventory for the AP owner Live94 backport.

This proves a *bounded source delta* only. Never authorizes a production merge,
deploy, OAuth enablement, real-user read, or a database migration.
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys

LIVE94 = "15abdc9de6f24b5250fb255c579d1aa55facaf1a"
ALLOWLIST = frozenset({
    ".github/workflows/ap-mcp-live94-jwt.yml",
    ".github/workflows/ap-mcp-live94-mysql.yml",
    ".github/workflows/ap-mcp-live94-source-gate.yml",
    "app/Http/Controllers/McpOAuthAccountLinkController.php",
    "app/Http/Controllers/McpOAuthPlanConsentController.php",
    "app/Http/Controllers/McpReadOnlyResourceController.php",
    "app/Services/McpAuth0JwtTokenVerifier.php",
    "app/Services/McpDelegatedPlanAccessPolicy.php",
    "app/Services/McpExplicitPlanConsentService.php",
    "app/Services/McpExternalTokenVerifier.php",
    "app/Services/McpJwtClaimConstraints.php",
    "app/Services/McpOAuthAccountLinkConfiguration.php",
    "app/Services/McpOAuthAccountLinkProvider.php",
    "app/Services/McpReadOnlyStudyContextTool.php",
    "app/Services/McpStudyPilotAccessPolicy.php",
    "composer.json",
    "composer.lock",
    "config/canovia_mcp.php",
    "docs/development/MCP_AP_LIVE94_OWNER_READ_ACCEPTANCE_2026_10_18.md",
    "docs/development/MCP_AP_LIVE94_SOURCE_GATE_2026_10_18.md",
    "resources/views/auth/account.blade.php",
    "resources/views/auth/partials/mcp-ap-plan-consent.blade.php",
    "resources/views/auth/partials/mcp-plan-consent-confirmation.blade.php",
    "scripts/ci/mcp_ap_live94_source_gate.py",
    "scripts/ops/mcp_ap_live94_auth0_preflight.py",
    "tests/ci/test_mcp_ap_live94_auth0_preflight.py",
    "tests/ci/test_mcp_ap_live94_source_gate.py",
    "tests/Feature/McpApLive94OAuthLifecycleTest.php",
    "tests/Feature/McpApLive94OAuthWiringTest.php",
    "tests/Feature/McpApLive94StudyOverviewTest.php",
    "tests/Feature/McpAuth0JwtTokenVerifierTest.php",
})

FLAGS = (
    "'discovery_enabled' => env('CANOVIA_MCP_DISCOVERY_ENABLED', false)",
    "'tools_enabled' => env('CANOVIA_MCP_TOOLS_ENABLED', false)",
    "'ap_pilot_enabled' => env('CANOVIA_MCP_AP_PILOT_ENABLED', false)",
    "'study_read_enabled' => env('CANOVIA_MCP_STUDY_READ_ENABLED', false)",
    "'auth0_jwt_verification_enabled' => env('CANOVIA_MCP_AUTH0_JWT_VERIFICATION_ENABLED', false)",
    "'account_link_enabled' => env('CANOVIA_MCP_ACCOUNT_LINK_ENABLED', false)",
    "'account_link_github_only_enabled' => env('CANOVIA_MCP_ACCOUNT_LINK_GITHUB_ONLY_ENABLED', false)",
    "'plan_consent_enabled' => env('CANOVIA_MCP_PLAN_CONSENT_ENABLED', false)",
    "'delegated_policy_enabled' => env('CANOVIA_MCP_DELEGATED_POLICY_ENABLED', false)",
)


def git(*args: str) -> str:
    return subprocess.run(
        ["git", *args], check=True, text=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    ).stdout.strip()


def evaluate(head: str, target_base: str, baseline: str = LIVE94) -> dict:
    def blocked(reason: str) -> dict:
        return {
            "result": "BLOCK",
            "code": reason,
            "production_release_authorized": False,
        }

    if not all(re.fullmatch(r"[a-fA-F0-9]{40}", x) for x in [head, target_base, baseline]):
        return blocked("INVALID_PIN")
    if baseline != LIVE94:
        return blocked("UNREVIEWED_LIVE_BASELINE")

    try:
        target_sha = git("rev-parse", "--verify", target_base + "^{commit}")
        base_sha = git("rev-parse", "--verify", baseline + "^{commit}")
        git("rev-parse", "--verify", head + "^{commit}")
        if target_sha != base_sha:
            return blocked("PRODUCTION_BASE_MOVED")
        if git("merge-base", base_sha, head) != base_sha:
            return blocked("HEAD_NOT_DESCENDED_FROM_LIVE94")

        migration_prefix = "database/migrations/"
        old_names = git("ls-tree", "-r", "--name-only", base_sha, "--", migration_prefix).splitlines()
        new_names = git("ls-tree", "-r", "--name-only", head, "--", migration_prefix).splitlines()
        old_migrations = [n for n in old_names if n.endswith(".php")]
        new_migrations = [n for n in new_names if n.endswith(".php")]
        if len(old_migrations) != 94 or len(new_migrations) != 94:
            return blocked("MIGRATION_COUNT_CHANGED")
        if git("diff", "--name-only", base_sha, head, "--", migration_prefix):
            return blocked("MIGRATION_SOURCE_CHANGED")

        changed = git("diff", "--name-only", base_sha, head, "--").splitlines()
        unexpected = sorted(set(changed) - ALLOWLIST)
        if unexpected:
            # No extra code paths may silently join a restricted backport.
            return {
                **blocked("OUTSIDE_REVIEWED_ALLOWLIST"),
                "unexpected_paths": unexpected,
            }
        if not changed:
            return blocked("EMPTY_CANDIDATE")

        startup_delta = git("diff", "--name-only", base_sha, head, "--", "docker/", "Dockerfile", "routes/")
        if startup_delta:
            return blocked("STARTUP_OR_ROUTE_SOURCE_CHANGED")
        config = git("show", head + ":config/canovia_mcp.php")
        if any(flag not in config for flag in FLAGS):
            return blocked("MCP_OFF_BY_DEFAULT_CONTRACT_CHANGED")
        if git("diff", "--name-only", base_sha, head, "--", "composer.json", "composer.lock") == "composer.json":
            return blocked("PACKAGE_LOCK_MISSING")

        return {
            "result": "REVIEW_REQUIRED",
            "code": "LIVE94_SOURCE_BOUNDED_NOT_RELEASE",
            "baseline_sha": base_sha,
            "candidate_sha": head,
            "migration_sources": len(new_migrations),
            "changed_path_count": len(changed),
            "allowed_delta_only": True,
            "production_release_authorized": False,
            "real_db_verified": False,
            "auth0_owner_verified": False,
            "backup_restore_verified": False,
        }
    except (subprocess.CalledProcessError, OSError, ValueError):
        return blocked("SOURCE_EVIDENCE_UNAVAILABLE")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--head", required=True)
    parser.add_argument("--target-base", required=True)
    args = parser.parse_args()
    result = evaluate(args.head, args.target_base)
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0 if result["result"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())

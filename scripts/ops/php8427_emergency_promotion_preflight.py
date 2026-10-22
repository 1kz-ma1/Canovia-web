#!/usr/bin/env python3
"""Read-only, fail-closed source preflight for the PHP 8.4.27 Live hotfix.

This is deliberately NOT a release authorization tool. Never query a real DB,
modify a Git ref, invoke Render, or output credentials from this script.
"""
from __future__ import annotations

import json
import subprocess
import sys

LIVE_SHA = "15abdc9de6f24b5250fb255c579d1aa55facaf1a"
CANDIDATE_SHA = "0ac198acf3c087652d0a164c55602fb60922a8d0"
EXPECTED_CHANGED_PATHS = frozenset(
    {
        ".github/workflows/php-8427-live-no-ddl-candidate.yml",
        "Dockerfile",
        "docker/render-start.sh",
        "docs/development/PHP_SECURITY_LIVE_NO_DDL_CANDIDATE_2026_10_22.md",
        "scripts/ci/php_8427_no_ddl_live_boot_test.sh",
    }
)
EXPECTED_RUNTIME_BLOBS = {
    "Dockerfile": "03ead1ab2823f1345ffb237a68fb26a75b656edd",
    "docker/render-start.sh": "97b9de037130e15989dc411db368e48c35580110",
}
EXPECTED_PROOF_BLOBS = {
    ".github/workflows/php-8427-live-no-ddl-candidate.yml": "54bcf5469c66d5d4351ad57a826091deff40c7a8",
    "scripts/ci/php_8427_no_ddl_live_boot_test.sh": "992997fcb8ca20993e5ece9d7164c6dd822d399f",
    "docs/development/PHP_SECURITY_LIVE_NO_DDL_CANDIDATE_2026_10_22.md": "212076e860b9c8d5f6da8ee5754e270dd9db1c18",
}


def git(*args: str) -> str:
    # No shell invocation; only local object reads. Git fetch is an operator /
    # GitHub Actions checkout responsibility and never occurs in this script.
    result = subprocess.run(
        ["git", *args], check=True, capture_output=True, text=True
    )
    return result.stdout.strip()


def validate_source(
    live_sha: str,
    candidate_sha: str,
    changed_paths: set[str] | frozenset[str],
    blobs: dict[str, str],
    live_start: str,
    candidate_start: str,
    candidate_dockerfile: str,
) -> list[str]:
    reasons: list[str] = []
    if live_sha != LIVE_SHA:
        reasons.append("LIVE_SHA_CHANGED")
    if candidate_sha != CANDIDATE_SHA:
        reasons.append("CANDIDATE_SHA_CHANGED")
    if set(changed_paths) != EXPECTED_CHANGED_PATHS:
        reasons.append("CHANGED_PATH_SET_MISMATCH")
    for path, expected in {**EXPECTED_RUNTIME_BLOBS, **EXPECTED_PROOF_BLOBS}.items():
        if blobs.get(path) != expected:
            reasons.append("BLOB_MISMATCH:" + path)
    if "php artisan migrate --force" not in live_start:
        reasons.append("LIVE_STARTUP_BASELINE_UNEXPECTED")
    if "php artisan migrate --force" in candidate_start:
        reasons.append("CANDIDATE_AUTO_MIGRATION_FOUND")
    for required in (
        'APP_ENV:-}" != "production"',
        "CANOVIA_STARTUP_MIGRATIONS:-check",
        "CANOVIA_APPROVED_MIGRATION_SHA:-",
        "exit 42",
    ):
        if required not in candidate_start:
            reasons.append("CANDIDATE_STARTUP_GUARD_MISSING")
            break
    if "FROM php:8.4.27-fpm-bookworm AS app" not in candidate_dockerfile:
        reasons.append("PATCHED_PHP_IMAGE_NOT_PINNED")
    if "PHP_VERSION_ID < 80427 || PHP_VERSION_ID >= 80500" not in candidate_dockerfile:
        reasons.append("PHP_VERSION_FLOOR_MISSING")
    return reasons


def offline_decision(source_reasons: list[str], policy_present_in_production: bool) -> dict[str, object]:
    """Even perfect source evidence cannot authorize a production release."""
    reasons = list(source_reasons)
    if not policy_present_in_production:
        reasons.append("PRODUCTION_REQUIRED_PROMOTION_WORKFLOW_ABSENT")
    reasons.extend(
        (
            "REQUIRED_PRODUCTION_CHECKS_NOT_VERIFIED_FOR_THIS_PR",
            "CURRENT_AIVEN_PHYSICAL_SCHEMA_AND_LEDGER_NOT_ATTESTED",
            "BACKUP_AND_RESTORATION_NOT_ATTESTED",
            "OWNER_PRODUCTION_GO_NOT_GRANTED",
            "RENDER_RUNTIME_PATCH_NOT_DEPLOYED",
        )
    )
    return {
        "verdict": "NO_GO",
        "source_integrity": "PASS" if not source_reasons else "BLOCK",
        "candidate": CANDIDATE_SHA,
        "live_baseline": LIVE_SHA,
        "reason_codes": reasons,
        "real_database_queries": 0,
        "mutations": 0,
    }


def main() -> int:
    try:
        live = git("rev-parse", "refs/remotes/origin/production")
        cand = git("rev-parse", "refs/remotes/origin/php8427-no-ddl")
        git("merge-base", "--is-ancestor", LIVE_SHA, CANDIDATE_SHA)
        changed = set(git("diff", "--name-only", LIVE_SHA, CANDIDATE_SHA).splitlines())
        blobs = {
            name: git("rev-parse", f"{CANDIDATE_SHA}:{name}")
            for name in EXPECTED_CHANGED_PATHS
        }
        live_start = git("show", f"{LIVE_SHA}:docker/render-start.sh")
        cand_start = git("show", f"{CANDIDATE_SHA}:docker/render-start.sh")
        dockerfile = git("show", f"{CANDIDATE_SHA}:Dockerfile")
        try:
            git("cat-file", "-e", f"{live}:.github/workflows/production-promotion-policy.yml")
            policy_present = True
        except subprocess.CalledProcessError:
            policy_present = False
        problems = validate_source(
            live, cand, changed, blobs, live_start, cand_start, dockerfile
        )
        result = offline_decision(problems, policy_present)
    except subprocess.CalledProcessError:
        result = offline_decision(["GIT_SOURCE_UNAVAILABLE_OR_NOT_ANCESTOR"], False)
    print(json.dumps(result, ensure_ascii=False, indent=2))
    # Source mismatch fails CI; expected governance HOLD passes as an accurate
    # offline diagnosis only. A green job is explicitly NOT production GO.
    return 0 if result["source_integrity"] == "PASS" else 1


if __name__ == "__main__":
    sys.exit(main())

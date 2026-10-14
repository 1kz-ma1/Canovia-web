#!/usr/bin/env python3
"""Offline, read-only Git topology preflight for a pinned Canovia release.

No network, GitHub, Render, Aiven or credential access. An output of
REVIEW_REQUIRED is never production release authorization.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import subprocess
import sys

SHA = re.compile(r"[0-9a-f]{40}\Z")
BRANCH = re.compile(r"release/[A-Za-z0-9][A-Za-z0-9._/-]*\Z")
MANUAL_GATES = (
    "actual_render_live_sha_operator_verified",
    "required_ci_for_exact_release_sha_verified",
    "aiven_live_schema_and_ledger_operator_audited",
    "fresh_encrypted_backup_and_offsite_copy_verified",
    "isolated_restore_or_explicit_recovery_risk_accepted",
    "production_env_and_startup_migrations_reviewed",
    "independent_staging_and_web_ios_smoke_verified",
    "explicit_owner_production_go_recorded",
)


class EvidenceUnavailable(Exception):
    """Untrusted or missing local Git evidence; details must not be printed."""


def git(repo: Path, *args: str) -> str:
    try:
        result = subprocess.run(
            ["git", "-C", str(repo), *args],
            capture_output=True, text=True, check=False, timeout=20,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise EvidenceUnavailable() from exc
    if result.returncode != 0:
        raise EvidenceUnavailable()
    return result.stdout.strip()


def ancestor(repo: Path, earlier: str, later: str) -> bool:
    try:
        result = subprocess.run(
            ["git", "-C", str(repo), "merge-base", "--is-ancestor", earlier, later],
            capture_output=True, text=True, check=False, timeout=20,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise EvidenceUnavailable() from exc
    if result.returncode not in (0, 1):
        raise EvidenceUnavailable()
    return result.returncode == 0


def verdict(result: str, checks: dict[str, str], changes: list[str] | None = None,
            migrations: list[str] | None = None) -> dict:
    return {
        "result": result,
        "checks": checks,
        "changed_path_count": len(changes or []),
        "migration_files": migrations or [],
        "manual_gates": {key: "NOT_VERIFIED" for key in MANUAL_GATES},
        "release_authorized": False,
        "production_modified": False,
        "note": "Local Git evidence only; never a production GO or a live-service attestation.",
    }


def evaluate(repo: Path, release_sha: str, live_sha: str, release_branch: str) -> dict:
    checks: dict[str, str] = {}
    if not SHA.fullmatch(release_sha) or not SHA.fullmatch(live_sha):
        return verdict("BLOCK", {"sha_input": "INVALID"})
    if (not BRANCH.fullmatch(release_branch) or ".." in release_branch
            or "//" in release_branch or release_branch.endswith("/")):
        return verdict("BLOCK", {"release_branch": "INVALID"})
    try:
        main = git(repo, "rev-parse", "--verify", "refs/remotes/origin/main^{commit}")
        prod = git(repo, "rev-parse", "--verify", "refs/remotes/origin/production^{commit}")
        pinned = git(repo, "rev-parse", "--verify", f"refs/remotes/origin/{release_branch}^{{commit}}")
        git(repo, "cat-file", "-e", f"{release_sha}^{{commit}}")
        checks["release_branch_exact_sha"] = "PASS" if pinned == release_sha else "BLOCK"
        checks["operator_supplied_live_matches_production"] = "PASS" if prod == live_sha else "BLOCK"
        checks["candidate_is_ancestor_of_main"] = "PASS" if ancestor(repo, release_sha, main) else "BLOCK"
        checks["production_is_ancestor_of_candidate"] = "PASS" if ancestor(repo, prod, release_sha) else "BLOCK"
        checks["candidate_differs_from_production"] = "PASS" if prod != release_sha else "BLOCK"
        if "BLOCK" in checks.values():
            return verdict("BLOCK", checks)
        changed = git(repo, "diff", "--name-only", "--no-renames", prod, release_sha, "--").splitlines()
        migration_names = [path for path in changed if path.startswith("database/migrations/")]
        return verdict("REVIEW_REQUIRED", checks, changed, migration_names)
    except EvidenceUnavailable:
        checks["git_evidence"] = "UNAVAILABLE"
        return verdict("BLOCK", checks)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", type=Path, default=Path("."), help="Local full-history checkout with origin refs")
    parser.add_argument("--release-sha", required=True, help="40-character immutable release commit")
    parser.add_argument("--live-sha", required=True, help="Operator-observed Render Live SHA (not independently verified)")
    parser.add_argument("--release-branch", required=True, help="release/* branch without refs/remotes/origin prefix")
    args = parser.parse_args()
    result = evaluate(args.repo, args.release_sha, args.live_sha, args.release_branch)
    print(json.dumps(result, ensure_ascii=True, sort_keys=True))
    return 0 if result["result"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())

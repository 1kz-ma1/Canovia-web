#!/usr/bin/env python3
"""Zero-network source integrity gate BEFORE any fixed Aiven metadata read.

Checks the exact two Git HEAD pins, tracked migration files, and *clean*
source/SQL/validator files. No credentials, DB connections, imports of app
configuration, or user data. Emit only hardcoded verdicts, never source paths.
"""
from __future__ import annotations

import argparse
from pathlib import Path
import subprocess
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.ci.p0_mysql_full_ledger_offline_check import expected_migration_names

LIVE_SHA = "15abdc9de6f24b5250fb255c579d1aa55facaf1a"
CANDIDATE_SHA = "6d7dbcfedd35f33ba2827ed8c7c3d98ca30fc3d6"
AUDIT_FILES = (
    "scripts/ops/prelaunch_pinned_mysql19_collect.sh",
    "scripts/ci/prelaunch_pinned_mysql19_local_source_gate.py",
    "scripts/ci/prelaunch_pinned_mysql19_offline_gate.py",
    "scripts/ci/p0_mysql_metadata_grants_guard.py",
    "scripts/ci/p0_mysql_full_ledger_offline_check.py",
    "scripts/sql/p0_mysql_readonly_full_migration_ledger.sql",
    "scripts/sql/prelaunch_pinned_mysql19_select_only_snapshot.sql",
)


class SourceRejected(Exception):
    """No source paths or untrusted Git output are sent to the caller."""


def run_git(root: Path, *args: str) -> str:
    try:
        completed = subprocess.run(
            ["git", "-C", str(root), *args],
            capture_output=True, text=True, check=False, timeout=15,
        )
    except (subprocess.TimeoutExpired, OSError):
        raise SourceRejected() from None
    if completed.returncode != 0 or len(completed.stdout) > 262144:
        raise SourceRejected()
    return completed.stdout.strip()


def checked_repo(root: Path) -> Path:
    if not root.is_dir() or root.is_symlink():
        raise SourceRejected()
    try:
        resolved = root.resolve(strict=True)
        top = Path(run_git(root, "rev-parse", "--show-toplevel")).resolve(strict=True)
    except (OSError, RuntimeError):
        raise SourceRejected() from None
    if resolved != top:
        raise SourceRejected()
    return root


def checked_audit_tools(root: Path) -> None:
    root = checked_repo(root)
    for relative in AUDIT_FILES:
        path = root / relative
        if not path.is_file() or path.is_symlink():
            raise SourceRejected()
    tracked = run_git(root, "ls-files", "--error-unmatch", "--", *AUDIT_FILES).splitlines()
    if set(tracked) != set(AUDIT_FILES):
        raise SourceRejected()
    if run_git(root, "status", "--porcelain=v1", "--untracked-files=all", "--", *AUDIT_FILES):
        raise SourceRejected()


def checked_pinned_migrations(root: Path, expected_sha: str) -> set[str]:
    root = checked_repo(root)
    if run_git(root, "rev-parse", "--verify", "HEAD") != expected_sha:
        raise SourceRejected()
    if run_git(root, "status", "--porcelain=v1", "--untracked-files=all",
               "--", "database/migrations"):
        raise SourceRejected()
    try:
        names = expected_migration_names(root / "database" / "migrations")
        tracked = run_git(root, "ls-files", "--", "database/migrations").splitlines()
    except (OSError, ValueError, TypeError):
        raise SourceRejected() from None
    php_names = {Path(n).stem for n in tracked if n.endswith(".php")}
    if php_names != names:
        raise SourceRejected()
    return names


def verify_sources(audit: Path, live: Path, candidate: Path) -> str:
    try:
        checked_audit_tools(audit)
        old = checked_pinned_migrations(live, LIVE_SHA)
        new = checked_pinned_migrations(candidate, CANDIDATE_SHA)
        if len(old) != 94 or len(new) != 113 or not old < new or len(new - old) != 19:
            raise SourceRejected()
    except (SourceRejected, OSError, ValueError, TypeError):
        return "BLOCK_AUDIT_OR_PINNED_SOURCE_UNVERIFIED"
    return "OFFLINE_SOURCE_INTEGRITY_CONFIRMED_NOT_AIVEN_READY"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--audit-root", required=True)
    parser.add_argument("--live-root", required=True)
    parser.add_argument("--candidate-root", required=True)
    args = parser.parse_args()
    verdict = verify_sources(Path(args.audit_root), Path(args.live_root),
                             Path(args.candidate_root))
    print("PINNED_MYSQL19_LOCAL_SOURCE: " + verdict)
    print("PRODUCTION_DATABASE_QUERIED: false")
    print("PRODUCTION_RELEASE_AUTHORIZED: false")
    return 2 if verdict.startswith("BLOCK") else 0


if __name__ == "__main__":
    sys.exit(main())

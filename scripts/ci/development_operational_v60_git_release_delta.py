#!/usr/bin/env python3
"""Read-only, fail-closed Git audit of a pinned release candidate against two SHA baselines.

Never calls GitHub/Render/Aiven, writes refs, connects to DB, or issues release GO.
"production_branch" is a Git source ref, not proof of the Render Live deploy.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
SHA = re.compile(r"[a-f0-9]{40}\Z")
REF = re.compile(r"release/20[0-9]{2}-[01][0-9]-[0-3][0-9]-([a-f0-9]{7,12})\Z")
MIGRATION = re.compile(r"database/migrations/[a-zA-Z0-9_]+\.php\Z")
MAX_BYTES = 4_000_000
MAX_CHANGED_PATHS = 15000
AREAS = {
    "database_migrations": ("database/migrations/",),
    "deployment_and_ci": (".github/workflows/", "docker/", "Dockerfile", "render.yaml", "scripts/ops/"),
    "security_and_identity": ("app/Http/Middleware/", "app/Policies/", "config/auth", "routes/auth",
                              "app/Services/Mcp", "app/Services/Oauth", "app/Services/Auth"),
    "web_and_mobile_surface": ("resources/views/", "resources/js/", "resources/css/", "public/",
                               "ios/", "mobile/"),
    "tests_and_audit": ("tests/", "scripts/ci/"),
}


class IncompleteSnapshot(Exception):
    pass


def git(root: Path, *args: str, binary: bool = False) -> str | bytes:
    try:
        p = subprocess.run(["git", "--no-pager", *args], cwd=root,
                           check=True, capture_output=True, timeout=20)
    except (OSError, subprocess.SubprocessError) as exc:
        raise IncompleteSnapshot() from exc
    if len(p.stdout) > MAX_BYTES:
        raise IncompleteSnapshot()
    if binary:
        return p.stdout
    try:
        return p.stdout.decode("ascii").strip()
    except UnicodeError as exc:
        raise IncompleteSnapshot() from exc


def is_ancestor(root: Path, older: str, newer: str) -> bool:
    try:
        p = subprocess.run(["git", "merge-base", "--is-ancestor", older, newer],
                           cwd=root, capture_output=True, timeout=10)
    except (OSError, subprocess.SubprocessError) as exc:
        raise IncompleteSnapshot() from exc
    if p.returncode not in (0, 1):
        raise IncompleteSnapshot()
    return p.returncode == 0


def changed_paths(root: Path, old: str, new: str) -> list[str]:
    raw = git(root, "diff", "--no-ext-diff", "--no-renames", "--name-only", "-z",
              old, new, binary=True)
    if raw and not raw.endswith(b"\0"):
        raise IncompleteSnapshot()
    try:
        paths = [p.decode("utf-8") for p in raw.split(b"\0") if p]
    except UnicodeError as exc:
        raise IncompleteSnapshot() from exc
    if len(paths) > MAX_CHANGED_PATHS or len(paths) != len(set(paths)):
        raise IncompleteSnapshot()
    if any("\x00" in p or "\n" in p or "\r" in p or p.startswith("/") or ".." in Path(p).parts
           for p in paths):
        raise IncompleteSnapshot()
    return paths


def summary(paths: list[str]) -> dict:
    groups = {area: 0 for area in AREAS}
    groups["other_source"] = 0
    for path in paths:
        matched = False
        for area, prefixes in AREAS.items():
            if any(path.startswith(prefix) for prefix in prefixes):
                groups[area] += 1
                matched = True
                break
        if not matched:
            groups["other_source"] += 1
    migration_paths = sorted(p for p in paths if p.startswith("database/migrations/"))
    # Do not echo untrusted/unusual paths into a shared audit report.
    if len(migration_paths) > 256 or any(not MIGRATION.fullmatch(p) for p in migration_paths):
        raise IncompleteSnapshot()
    return {
        "changed_paths": len(paths),
        "risk_area_path_counts": groups,
        "migration_paths": migration_paths,
    }


def inspect(root: Path, baseline: str, candidate: str,
            main: str, release_ref: str) -> dict:
    if not all(isinstance(x, str) and SHA.fullmatch(x)
               for x in (baseline, candidate, main)):
        raise IncompleteSnapshot()
    match = REF.fullmatch(release_ref) if isinstance(release_ref, str) else None
    if match is None or not candidate.startswith(match.group(1)):
        raise IncompleteSnapshot()
    if git(root, "rev-parse", "HEAD") != candidate:
        raise IncompleteSnapshot()
    if git(root, "show-ref", "--verify", "--hash", "refs/heads/" + release_ref) != candidate:
        raise IncompleteSnapshot()
    for sha in (baseline, candidate, main):
        if git(root, "cat-file", "-t", sha) != "commit":
            raise IncompleteSnapshot()
    if not is_ancestor(root, baseline, candidate) or not is_ancestor(root, candidate, main):
        raise IncompleteSnapshot()

    candidate_paths = changed_paths(root, baseline, candidate)
    gap_paths = changed_paths(root, candidate, main)
    candidate_commits = int(git(root, "rev-list", "--count", baseline + ".." + candidate))
    gap_commits = int(git(root, "rev-list", "--count", candidate + ".." + main))
    if not candidate_paths or candidate_commits < 1 or gap_commits < 0:
        raise IncompleteSnapshot()
    return {
        "version": "development-operational-v6.0",
        "production_branch_sha": baseline,
        "candidate_sha": candidate,
        "main_snapshot_sha": main,
        "release_ref": release_ref,
        "provenance": "LOCAL_GIT_OBJECTS_ONLY_NOT_RENDER_OR_AIVEN",
        "production_branch_is_render_live_verified": False,
        "main_snapshot_is_current_remotely_verified": False,
        "candidate_commits_from_production_branch": candidate_commits,
        "candidate_delta": summary(candidate_paths),
        "commits_after_candidate_in_main_snapshot": gap_commits,
        "not_in_candidate_delta": summary(gap_paths),
        "candidate_equal_to_main_snapshot": candidate == main,
        "result": "BLOCK",
        "reason": ("NEWER_MAIN_NOT_IN_PINNED_CANDIDATE" if gap_commits
                   else "INDEPENDENT_PRODUCTION_EVIDENCE_REQUIRED"),
        "release_authorized": False,
        "production_database_modified": False,
        "backup_restore_verified": False,
        "deployed_sha_verified": False,
        "device_acceptance_verified": False,
    }


def blocked() -> dict:
    # Never reflect untrusted paths, refs, local hostnames, Git error output or tokens.
    return {
        "version": "development-operational-v6.0",
        "result": "BLOCK",
        "reason": "GIT_CANDIDATE_DELTA_UNVERIFIABLE",
        "release_authorized": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--production-branch-sha", required=True)
    parser.add_argument("--candidate-sha", required=True)
    parser.add_argument("--main-snapshot-sha", required=True)
    parser.add_argument("--release-ref", required=True)
    parser.add_argument("--git-repo", default=str(ROOT), help="Local audited Git clone (offline, no fetch)")
    args = parser.parse_args()
    try:
        result = inspect(Path(args.git_repo), args.production_branch_sha, args.candidate_sha,
                         args.main_snapshot_sha, args.release_ref)
        code = 0  # Report generation success ONLY. Report always BLOCK.
    except (IncompleteSnapshot, ValueError, OverflowError):
        result = blocked()
        code = 2
    print(json.dumps(result, sort_keys=True, ensure_ascii=False))
    return code


if __name__ == "__main__":
    sys.exit(main())

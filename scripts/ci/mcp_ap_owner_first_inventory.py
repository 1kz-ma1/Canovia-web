#!/usr/bin/env python3
"""Offline, secretless source inventory for the owner-only AP MCP early pilot.

Reads EXACT git objects; never invokes Laravel, MySQL, Render or a network client.
This is a feasibility screen, not a migration audit or release authorization.
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
from pathlib import Path

LIVE = "15abdc9de6f24b5250fb255c579d1aa55facaf1a"
REVIEWED_MAIN = "4e3a6e97949602409ea337290c4d4f2052fda998"

# Exact implementation hotspots found by comparing the old live and reviewed
# main. More transitive dependencies may exist: this tool never claims that
# this list is an exhaustive runnable backport.
HOTSPOTS = {
    "auth0_jwt_verifier": "app/Services/McpAuth0JwtTokenVerifier.php",
    "auth0_jwt_claims": "app/Services/McpJwtClaimConstraints.php",
    "study_context": "app/Services/McpReadOnlyStudyContextTool.php",
    "study_owner_fence": "app/Services/McpStudyPilotAccessPolicy.php",
    "account_link_committer": "app/Services/McpOAuthAccountLinkCommitter.php",
    "delegated_policy": "app/Services/McpDelegatedPlanAccessPolicy.php",
    "explicit_consent": "app/Services/McpExplicitPlanConsentService.php",
    "oauth_provider": "app/Services/McpOAuthAccountLinkProvider.php",
    "mcp_controller": "app/Http/Controllers/McpReadOnlyResourceController.php",
}
SCHEMA_DEPENDENCIES = {
    "new_active_identity_column": "active_identity_fingerprint",
    "new_result_proposal_table": "mcp_study_result_proposals",
}
SHA40 = re.compile(r"^[0-9a-f]{40}$")


def git(*args: str, cwd: str | None = None) -> str:
    result = subprocess.run(
        ["git", *args], cwd=cwd, capture_output=True, text=True,
        timeout=20, check=False,
    )
    if result.returncode:
        raise RuntimeError("Pinned local git objects unavailable or unreadable")
    return result.stdout


def files_at(ref: str, cwd: str | None = None) -> set[str]:
    return set(git("ls-tree", "-r", "--name-only", ref, cwd=cwd).splitlines())


def source_at(ref: str, path: str, paths: set[str], cwd: str | None = None) -> str:
    return git("show", f"{ref}:{path}", cwd=cwd) if path in paths else ""


def source_inventory(
    live_paths: set[str],
    main_paths: set[str],
    live_sources: dict[str, str],
    main_sources: dict[str, str],
) -> dict:
    old_migrations = {p for p in live_paths if p.startswith("database/migrations/") and p.endswith(".php")}
    new_migrations = {p for p in main_paths if p.startswith("database/migrations/") and p.endswith(".php")}
    hotspot_status = {}
    for key, path in HOTSPOTS.items():
        old = live_sources.get(path, "")
        new = main_sources.get(path, "")
        hotspot_status[key] = {
            "present_in_live": path in live_paths,
            "present_in_reviewed_main": path in main_paths,
            "content_changed": old != new,
        }
    schema_sources = {
        "account_link_committer": HOTSPOTS["account_link_committer"],
        "delegated_policy": HOTSPOTS["delegated_policy"],
        "explicit_consent": HOTSPOTS["explicit_consent"],
    }
    incompatible_schema_refs = {
        key: [
            label for label, symbol in SCHEMA_DEPENDENCIES.items()
            if symbol in main_sources.get(path, "")
        ]
        for key, path in schema_sources.items()
    }
    legacy_refs = {
        key: [
            label for label, symbol in SCHEMA_DEPENDENCIES.items()
            if symbol in live_sources.get(path, "")
        ]
        for key, path in schema_sources.items()
    }
    return {
        "mode": "OFFLINE_GIT_SOURCE_ONLY",
        "live_source_migrations": len(old_migrations),
        "reviewed_main_source_migrations": len(new_migrations),
        "added_source_migrations": len(new_migrations - old_migrations),
        "removed_source_migrations": len(old_migrations - new_migrations),
        "hotspots": hotspot_status,
        "main_direct_schema_refs": incompatible_schema_refs,
        "live_direct_schema_refs": legacy_refs,
        "assessed_backport_implementation_complete": False,
        "candidate_zero_ddl_verified": False,
        "aiven_queried": False,
        "production_release_authorized": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--live", default=LIVE, help="Exact 40-character historical Render Live SHA")
    parser.add_argument("--main", default=REVIEWED_MAIN, help="Exact 40-character reviewed main SHA")
    parser.add_argument("--repo", default=".", help="Local Canovia-web Git checkout")
    args = parser.parse_args()

    if not SHA40.fullmatch(args.live) or not SHA40.fullmatch(args.main):
        parser.error("Only full commit SHA identifiers are accepted")

    try:
        cwd = str(Path(args.repo).resolve())
        for ref in (args.live, args.main):
            if git("rev-parse", "--verify", f"{ref}^{{commit}}", cwd=cwd).strip() != ref:
                raise RuntimeError("Pinned local git commit differs from requested SHA")
        live = files_at(args.live, cwd)
        reviewed = files_at(args.main, cwd)
        live_sources = {
            path: source_at(args.live, path, live, cwd)
            for path in HOTSPOTS.values()
        }
        new_sources = {
            path: source_at(args.main, path, reviewed, cwd)
            for path in HOTSPOTS.values()
        }
        report = source_inventory(live, reviewed, live_sources, new_sources)
    except (RuntimeError, subprocess.TimeoutExpired):
        print(json.dumps({"status": "BLOCK_LOCAL_SOURCE_UNAVAILABLE",
                          "aiven_queried": False, "production_release_authorized": False}))
        return 2

    expected = (
        report["live_source_migrations"] == 94
        and report["reviewed_main_source_migrations"] == 114
        and report["added_source_migrations"] == 20
        and report["removed_source_migrations"] == 0
    )
    report.update({
        "live_sha": args.live,
        "reviewed_main_sha": args.main,
        "status": "BACKPORT_FEASIBILITY_REQUIRES_IMPLEMENTATION" if expected
                  else "BLOCK_SOURCE_MIGRATION_DRIFT",
    })
    print(json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2))
    return 0 if expected else 2


if __name__ == "__main__":
    raise SystemExit(main())

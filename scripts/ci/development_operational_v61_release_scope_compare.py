#!/usr/bin/env python3
"""V6.1: compare a real pinned release candidate against a *hypothetical* main release.

Pure local Git inspection. No branch creation, DB/API access, production promotion,
evidence upgrade, owner approval or automated selection of a preferred candidate.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys

from scripts.ci.development_operational_v60_git_release_delta import (
    ROOT, IncompleteSnapshot, SHA, changed_paths, git, inspect, is_ancestor, summary,
)


GATES = (
    "Owner selects exact scope and independently observes actual Render Live SHA",
    "Review every unshipped commit and changed migration against exact Live baseline",
    "Independently verify Aiven production DB identity, grants and physical migration ledger",
    "Confirm backup recovery path and isolated restoration feasibility",
    "Rehearse backward/forward app and DB schema compatibility",
    "Confirm protected promotion PR, required CI and owner-only manual deployment",
    "Perform actual Web, iOS WKWebView, login, Learning and Development acceptance",
)


def option(label: str, baseline: str, head: str, paths: list[str],
           commit_count: int, pinned: bool) -> dict:
    if commit_count < 1:
        raise IncompleteSnapshot()
    return {
        "id": label,
        "source_branch_sha": head,
        "is_existing_pinned_release": pinned,
        "new_release_branch_created": False,
        "commits_since_production_branch": commit_count,
        "git_delta": summary(paths),
        "release_authorized": False,
        "independent_prod_sha_verified": False,
        "db_migration_verified": False,
        "backup_restore_verified": False,
        "device_acceptance_verified": False,
        "required_review_gates": [{"requirement": gate, "state": "UNVERIFIED"}
                                  for gate in GATES],
    }


def compare(root: Path, production: str, existing: str, main: str,
            release_ref: str) -> dict:
    # V6.0 enforces the existing release ref, exact checkout, validated source
    # objects and baseline->release->main ancestry. It does not claim Render Live.
    existing_git = inspect(root, production, existing, main, release_ref)
    if not all(isinstance(x, str) and SHA.fullmatch(x)
               for x in (production, existing, main)):
        raise IncompleteSnapshot()
    if not is_ancestor(root, production, main):
        raise IncompleteSnapshot()

    old_paths = changed_paths(root, production, existing)
    new_paths = changed_paths(root, production, main)
    later_paths = changed_paths(root, existing, main)
    existing_count = existing_git["candidate_commits_from_production_branch"]
    later_count = existing_git["commits_after_candidate_in_main_snapshot"]
    fresh_count = int(git(root, "rev-list", "--count", production + ".." + main))

    if (fresh_count != existing_count + later_count
        or old_paths != changed_paths(root, production, existing)
        or existing_count < 1):
        raise IncompleteSnapshot()

    return {
        "version": "development-operational-v6.1",
        "provenance": "LOCAL_GIT_FULL_DELTA_NOT_INDEPENDENT_RENDER_AIVEN_EVIDENCE",
        "production_branch_sha": production,
        "existing_release_ref": release_ref,
        "existing_candidate_sha": existing,
        "main_snapshot_sha": main,
        "result": "BLOCK",
        "reason": "OWNER_SCOPE_SELECTION_AND_INDEPENDENT_RELEASE_EVIDENCE_REQUIRED",
        "release_authorized": False,
        "production_branch_is_render_live_verified": False,
        "main_snapshot_is_current_remotely_verified": False,
        "owner_scope_selected": False,
        "options": [
            option("existing_pinned_release", production, existing,
                   old_paths, existing_count, True),
            option("hypothetical_main_candidate", production, main,
                   new_paths, fresh_count, False),
        ],
        "deferred_if_existing_published": {
            "commits_in_main_after_existing_release": later_count,
            "git_delta": summary(later_paths),
            "migration_review_required": True,
            "all_paths_included_in_this_comparison": True,
        },
        "comparison_caveats": [
            "Diff path counts are not additive across two ranges; compare each exact pair.",
            "Changed migration source files are not proof that database migration is pending.",
            "Existing draft candidate and hypothetical newer scope both remain NO-GO.",
            "Newer main is a snapshot, not a pinned release ref or authorized release.",
        ],
    }


def blocked() -> dict:
    return {
        "version": "development-operational-v6.1",
        "result": "BLOCK",
        "reason": "RELEASE_OPTIONS_UNVERIFIABLE",
        "release_authorized": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--production-branch-sha", required=True)
    parser.add_argument("--existing-candidate-sha", required=True)
    parser.add_argument("--main-snapshot-sha", required=True)
    parser.add_argument("--release-ref", required=True)
    parser.add_argument("--git-repo", default=str(ROOT),
                        help="Existing trusted local Git clone; no fetch or writes")
    args = parser.parse_args()
    try:
        report = compare(
            Path(args.git_repo), args.production_branch_sha,
            args.existing_candidate_sha, args.main_snapshot_sha, args.release_ref,
        )
        code = 0  # Collection completed; report itself is always BLOCK.
    except (IncompleteSnapshot, ValueError, OverflowError):
        report = blocked()
        code = 2
    print(json.dumps(report, ensure_ascii=False, sort_keys=True))
    return code


if __name__ == "__main__":
    sys.exit(main())

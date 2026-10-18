#!/usr/bin/env python3
"""Read-only review of the AP MCP Live94 release-policy gap.

DOES NOT make a release possible, alter required checks, change refs or grant GO.
All inputs are immutable, full commit SHA references from a pinned review.
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from typing import Callable

LIVE = "15abdc9de6f24b5250fb255c579d1aa55facaf1a"
MAIN = "4e3a6e97949602409ea337290c4d4f2052fda998"
PILOT = "389533459dc2d45d9c1318ebea27506e8ebfdb6a"
PILOT_BRANCH = "feature/ap-mcp-live94-auth0-20261018"

REQUIRED_CHECK_WORKFLOWS = {
    "Production promotion contract": ".github/workflows/production-promotion-policy.yml",
    "workspace-resume": ".github/workflows/v56-12-workspace-resume.yml",
    "migration-recovery": ".github/workflows/v56-8-migration-recovery.yml",
    "P0 schema integrity (MySQL 8.0)": ".github/workflows/p0-disposable-mysql-schema.yml",
    "P0 schema integrity (MySQL 8.4)": ".github/workflows/p0-disposable-mysql-schema.yml",
}
SOURCE_GATE_PATH = "scripts/ci/mcp_ap_live94_source_gate.py"


def triggers_production(workflow: str, event: str) -> bool:
    """Check only the exact top-level event's branches selector.

    An unrelated production path, comment or other event is not a trigger.
    This is a narrow review parser, not a substitute for GitHub's YAML engine.
    """
    in_event = False
    in_branches = False
    in_on = False
    for line in workflow.splitlines():
        if line == "on:":
            in_on = True
            continue
        if not in_on:
            continue
        if line and not line.startswith((" ", "#")):
            break  # end of the top-level on: block
        if line.startswith("  ") and not line.startswith("    ") and line.endswith(":"):
            in_event = line == "  " + event + ":"
            in_branches = False
            continue
        if not in_event:
            continue
        if line.startswith("    ") and not line.startswith("      "):
            in_branches = False
            if line.startswith("    branches:"):
                spec = line[len("    branches:"):].strip()
                if spec.startswith("[") and spec.endswith("]"):
                    values = [part.strip() for part in spec[1:-1].split(",")]
                    if "production" in values:
                        return True
                elif not spec:
                    in_branches = True
            continue
        if in_branches and line == "      - production":
            return True
    return False

def git(*args: str) -> str:
    return subprocess.run(
        ["git", *args], check=True, text=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    ).stdout.strip()


def inspect(
    main: str,
    production: str,
    pilot: str,
    pilot_branch: str,
    runner: Callable[..., str] = git,
) -> dict:
    """Return evidence or BLOCK, never release authorization."""
    def blocked(code: str) -> dict:
        return {"result": "BLOCK", "code": code,
                "production_release_authorized": False}

    if not all(re.fullmatch(r"[a-f0-9]{40}", v)
               for v in [main, production, pilot]):
        return blocked("INVALID_SHA")
    if main != MAIN or pilot != PILOT:
        return blocked("REVIEW_PINS_DRIFTED")
    if production != LIVE:
        return blocked("PRODUCTION_MOVED")
    if pilot_branch != PILOT_BRANCH:
        return blocked("UNREVIEWED_PILOT_BRANCH")

    def present(commit: str, path: str) -> bool:
        try:
            runner("cat-file", "-e", f"{commit}:{path}")
            return True
        except subprocess.CalledProcessError:
            return False

    try:
        for sha in (main, production, pilot):
            runner("rev-parse", "--verify", sha + "^{commit}")
        runner("merge-base", "--is-ancestor", production, pilot)

        baseline_names = runner(
            "ls-tree", "-r", "--name-only", production,
            "--", "database/migrations",
        ).splitlines()
        pilot_names = runner(
            "ls-tree", "-r", "--name-only", pilot,
            "--", "database/migrations",
        ).splitlines()
        baseline_migrations = [p for p in baseline_names if p.endswith(".php")]
        pilot_migrations = [p for p in pilot_names if p.endswith(".php")]
        if len(baseline_migrations) != 94 or len(pilot_migrations) != 94:
            return blocked("EXPECTED_94_MIGRATIONS_NOT_FOUND")
        if runner("diff", "--name-only", production, pilot,
                  "--", "database/migrations"):
            return blocked("MIGRATION_SOURCE_CHANGED")

        main_policy_path = REQUIRED_CHECK_WORKFLOWS["Production promotion contract"]
        if not all(present(main, file) for file in set(REQUIRED_CHECK_WORKFLOWS.values())):
            return blocked("MAIN_REQUIRED_WORKFLOW_MISSING")
        main_policy = runner("show", f"{main}:{main_policy_path}")
        if ("production_release_branch_required" not in main_policy
                or 'git merge-base --is-ancestor "$RELEASE_SHA" refs/remotes/origin/main'
                not in main_policy
                or "production_release_parent_not_in_main" not in main_policy):
            return blocked("MAIN_PROMOTION_POLICY_CONTRACT_CHANGED")
        if not present(pilot, SOURCE_GATE_PATH):
            return blocked("PILOT_SOURCE_GATE_MISSING")

        # GitHub required status contexts are external Ruleset configuration,
        # NOT established by workflow-file presence or this offline review.
        missing = sorted(name for name, path in REQUIRED_CHECK_WORKFLOWS.items()
                         if not present(pilot, path))
        not_runnable_pr = []
        not_runnable_push = []
        for name, path in REQUIRED_CHECK_WORKFLOWS.items():
            candidate_workflow = runner("show", f"{pilot}:{path}") if present(pilot, path) else ""
            if not triggers_production(candidate_workflow, "pull_request"):
                not_runnable_pr.append(name)
            if not triggers_production(candidate_workflow, "push"):
                not_runnable_push.append(name)
        try:
            runner("merge-base", "--is-ancestor", pilot, main)
            main_ancestry = True
        except subprocess.CalledProcessError:
            main_ancestry = False

        return {
            "result": "REVIEW_REQUIRED",
            "code": "EXCEPTION_BOOTSTRAP_REQUIRES_OWNER_REVIEW",
            "review_baseline_sha": production,
            "review_main_sha": main,
            "review_candidate_sha": pilot,
            "candidate_migration_sources": 94,
            "main_policy_requires_main_ancestor": True,
            "candidate_is_main_ancestor": main_ancestry,
            "candidate_has_release_branch_name": pilot_branch.startswith("release/"),
            "required_check_workflow_files_missing_on_candidate": missing,
            "required_check_contexts_not_runnable_on_production_pr": sorted(not_runnable_pr),
            "required_check_contexts_not_runnable_on_production_push": sorted(not_runnable_push),
            "github_required_status_checks_verified": False,
            "real_aiven_schema_verified": False,
            "independent_backup_restore_verified": False,
            "actual_auth0_chatgpt_identity_verified": False,
            "production_release_authorized": False,
        }
    except (subprocess.CalledProcessError, OSError, ValueError):
        return blocked("SOURCE_EVIDENCE_UNAVAILABLE")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--main-sha", required=True)
    parser.add_argument("--production-sha", required=True)
    parser.add_argument("--pilot-sha", required=True)
    parser.add_argument("--pilot-branch", required=True)
    options = parser.parse_args()
    verdict = inspect(options.main_sha, options.production_sha,
                      options.pilot_sha, options.pilot_branch)
    print(json.dumps(verdict, ensure_ascii=False, sort_keys=True))
    return 0 if verdict["result"] == "REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    sys.exit(main())

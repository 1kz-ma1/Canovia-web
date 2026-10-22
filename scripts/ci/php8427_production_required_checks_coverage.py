#!/usr/bin/env python3
"""Git-tree-only coverage audit of protected production PR/push CI.

Deliberately diagnoses missing checks. Never creates a status, deploys,
inspects secrets, connects to Aiven, or returns a production GO.
"""
from __future__ import annotations

import json
import re
import subprocess
import sys

LIVE_SHA = "15abdc9de6f24b5250fb255c579d1aa55facaf1a"
CANDIDATE_SHA = "0ac198acf3c087652d0a164c55602fb60922a8d0"
REQUIRED_CONTEXTS = (
    "Production promotion contract",
    "workspace-resume",
    "migration-recovery",
    "P0 schema integrity (MySQL 8.0)",
    "P0 schema integrity (MySQL 8.4)",
)
WORKFLOW_SOURCES = {
    ".github/workflows/production-promotion-policy.yml": "contract",
    ".github/workflows/v56-12-workspace-resume.yml": "workspace",
    ".github/workflows/v56-8-migration-recovery.yml": "recovery",
    ".github/workflows/p0-disposable-mysql-schema.yml": "mysql",
}


def get_top_block(content: str, name: str) -> str:
    """Extract a simple root YAML mapping block, rejecting inline aliases."""
    if not isinstance(content, str):
        return ""
    lines = content.splitlines()
    starts = [i for i, line in enumerate(lines) if line == f"{name}:"]
    if len(starts) != 1:
        return ""
    start = starts[0] + 1
    end = len(lines)
    for i in range(start, len(lines)):
        line = lines[i]
        if line and not line[0].isspace() and not line.startswith("#"):
            end = i
            break
    return "\n".join(lines[start:end])


def event_scope(content: str, event: str) -> tuple[bool, str]:
    block = get_top_block(content, "on")
    if not block:
        return False, "MISSING_TOP_LEVEL_ON_MAPPING"
    starts = [
        i for i, line in enumerate(block.splitlines())
        if re.fullmatch(rf"  {re.escape(event)}:(?:\s*#.*)?", line)
    ]
    if len(starts) != 1:
        return False, f"{event.upper()}_NOT_EXPLICIT"
    lines = block.splitlines()
    first = starts[0] + 1
    last = len(lines)
    for i in range(first, len(lines)):
        if re.match(r"^  [A-Za-z_][\w-]*:", lines[i]):
            last = i
            break
    section = "\n".join(lines[first:last])
    # A path-scoped/conditional required job could be absent altogether.
    if re.search(r"(?m)^\s{4}(?:paths|paths-ignore|branches-ignore|types):", section):
        return False, f"{event.upper()}_RESTRICTED_EVENT"
    if not re.search(r"(?m)^    branches:\s*\[\s*['\"]?production['\"]?\s*\]\s*$", section):
        return False, f"{event.upper()}_NOT_PRODUCTION_ONLY"
    return True, "PASS"


def job_block(content: str, job: str) -> str:
    jobs = get_top_block(content, "jobs")
    if not jobs:
        return ""
    lines = jobs.splitlines()
    starts = [i for i, line in enumerate(lines) if line == f"  {job}:"]
    if len(starts) != 1:
        return ""
    start = starts[0] + 1
    end = len(lines)
    for i in range(start, len(lines)):
        if re.match(r"^  [A-Za-z_][\w-]*:", lines[i]):
            end = i
            break
    return "\n".join(lines[start:end])


def check_job(content: str, kind: str) -> list[str]:
    problems: list[str] = []
    jobs = {
        "contract": "promotion-contract",
        "workspace": "workspace-resume",
        "recovery": "migration-recovery",
        "mysql": "mysql-p0-schema-integrity",
    }
    job = jobs[kind]
    block = job_block(content, job)
    if not block:
        return [f"REQUIRED_JOB_MISSING:{kind}"]
    if re.search(r"(?m)^    if\s*:", block):
        problems.append(f"CONDITIONAL_REQUIRED_JOB:{kind}")
    if kind == "contract":
        if not re.search(r"(?m)^    name: ['\"]?Production promotion contract['\"]?\s*$", block):
            problems.append("CONTRACT_CHECK_NAME_MISMATCH")
        # Existence of the standard main-ancestry gate is not enough for a
        # pinned Live-based emergency backport. A later separately approved
        # emergency exception must be exact-SHA and human-gated.
        if "15abdc9de6f24b5250fb255c579d1aa55facaf1a" not in block:
            problems.append("EXACT_LIVE_EMERGENCY_PARENT_UNPROVEN")
        if "0ac198acf3c087652d0a164c55602fb60922a8d0" not in block:
            problems.append("EXACT_EMERGENCY_SOURCE_UNPROVEN")
    if kind == "mysql":
        if "P0 schema integrity (MySQL " not in block:
            problems.append("MYSQL_REQUIRED_CHECK_NAME_MISSING")
        if not re.search(r"mysql_series:\s*\[\s*['\"]8\.0['\"]\s*,\s*['\"]8\.4['\"]\s*\]", block):
            problems.append("DISPOSABLE_MYSQL_TWO_ENGINES_MISSING")
        if "services:" not in block or "CANOVIA_P0_DISPOSABLE_MYSQL_CI" not in block:
            problems.append("ISOLATED_MYSQL_GUARD_MISSING")
    return problems


def assess(workflows: dict[str, str | None]) -> dict[str, object]:
    blockers: list[str] = []
    for path, kind in WORKFLOW_SOURCES.items():
        source = workflows.get(path)
        if not source:
            blockers.append(f"MISSING_WORKFLOW:{kind}")
            continue
        for event in ("pull_request", "push"):
            ok, code = event_scope(source, event)
            if not ok:
                blockers.append(f"{kind}:{code}")
        blockers.extend(check_job(source, kind))
    # A source-only analysis can *never* prove that an actual PR and merge SHA
    # produced the expected GitHub-App-owned checks.
    return {
        "verdict": "NO_GO",
        "source_coverage": "PASS" if not blockers else "BLOCK",
        "live_baseline": LIVE_SHA,
        "reviewed_runtime_candidate": CANDIDATE_SHA,
        "expected_checks": list(REQUIRED_CONTEXTS),
        "blockers": blockers,
        "unverified": [
            "REAL_PRODUCTION_PR_CHECK_CONTEXTS_AND_APP_IDS",
            "PROTECTED_POST_MERGE_SHA_CHECKS",
            "CURRENT_AIVEN_PHYSICAL_SCHEMA_AND_LEDGER",
            "RESTORABLE_BACKUP_AND_RECOVERY",
            "EXPLICIT_OWNER_PRODUCTION_APPROVAL",
            "RENDER_PATCH_DEPLOYMENT_AND_DEVICE_SMOKE",
        ],
        "live_database_queries": 0,
        "mutations": 0,
    }


def git_read(*args: str) -> str:
    return subprocess.run(
        ["git", *args], check=True, capture_output=True, text=True
    ).stdout.strip()


def main() -> int:
    try:
        production_sha = git_read("rev-parse", "refs/remotes/origin/production")
        if production_sha != LIVE_SHA:
            raise ValueError("PRODUCTION_REF_DRIFT")
        files: dict[str, str | None] = {}
        for path in WORKFLOW_SOURCES:
            try:
                files[path] = git_read("show", f"{production_sha}:{path}")
            except subprocess.CalledProcessError:
                files[path] = None
        result = assess(files)
    except (ValueError, subprocess.CalledProcessError):
        result = assess({})
        result["blockers"].append("PRODUCTION_REF_OR_GIT_UNAVAILABLE")
        result["source_coverage"] = "BLOCK"
    print(json.dumps(result, ensure_ascii=False, indent=2))
    # The diagnosis is successful when it proves NO-GO on a pinned source.
    # This exit code must NOT be treated as release permission.
    return 0 if result["verdict"] == "NO_GO" and result["live_baseline"] == LIVE_SHA else 1


if __name__ == "__main__":
    sys.exit(main())

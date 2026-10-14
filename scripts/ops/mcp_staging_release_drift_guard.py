#!/usr/bin/env python3
"""Offline, read-only MCP Staging deployment-drift preflight.

NEVER calls Render, writes environment variables, deploys or authorizes an
operation. Takes only a manually sanitized metadata snapshot (no secrets).
The connected Render environment write unexpectedly launched an API deploy
of moving main on 2026-10-14, despite Auto Deploy OFF.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re

WORKSPACE_ID = "tea-d4vb3f6mcj7s73djkrqg"
SERVICE_ID = "srv-db43l4nlk1mc73emseig"
SERVICE_NAME = "canovia-mcp-staging"
REPO = "https://github.com/1kz-ma1/Canovia-web"
LIVE_APPROVED_SHA = "9cc3f361941facc23bc81958c6aa81134a75c046"
BASE_SHA = "8a831448eef528b786053ba5506517300bc001c7"
REQUIRED_CHECKS = frozenset(("provider-routing", "auth0-sealed-config", "isolated-staging-boot"))
SHA = re.compile(r"[0-9a-f]{40}\Z")
MAX_BYTES = 32768
MODES = ("audit", "render-connector-env-write", "dashboard-save-only", "exact-sha-deploy-review")


class SnapshotInvalid(ValueError):
    pass


def read_snapshot(path: Path) -> dict:
    try:
        if path.is_symlink() or not path.is_file() or path.stat().st_size > MAX_BYTES:
            raise SnapshotInvalid()
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError, UnicodeError) as exc:
        raise SnapshotInvalid() from exc
    if not isinstance(data, dict):
        raise SnapshotInvalid()
    return data


def assess(snapshot: dict, *, expected_sha: str = LIVE_APPROVED_SHA, mode: str = "audit") -> dict:
    # This is NOT a deployment approval engine; never return permission to write.
    if mode not in MODES or not isinstance(expected_sha, str) or not SHA.fullmatch(expected_sha):
        raise SnapshotInvalid()

    failed: set[str] = set()
    service = snapshot.get("service")
    deploys = snapshot.get("deploys")
    candidate = snapshot.get("candidate")
    checks = snapshot.get("checks")

    if not isinstance(service, dict):
        failed.add("SERVICE_METADATA_MISSING")
    else:
        expected_fields = {
            "id": SERVICE_ID, "ownerId": WORKSPACE_ID, "name": SERVICE_NAME,
            "repo": REPO, "branch": "main", "autoDeploy": "no",
            "autoDeployTrigger": "off",
        }
        if any(service.get(k) != v for k, v in expected_fields.items()):
            failed.add("SERVICE_OR_AUTO_DEPLOY_DRIFT")

    if not isinstance(deploys, list) or not deploys or len(deploys) > 100:
        failed.add("DEPLOY_HISTORY_INCOMPLETE")
    else:
        # Input ordering must match Render list_deploys: newest first. The
        # caller is responsible for obtaining a fresh, complete-enough read.
        current = deploys[0]
        if not isinstance(current, dict):
            failed.add("LATEST_DEPLOY_INVALID")
        else:
            sha = current.get("commit")
            if isinstance(sha, dict):
                sha = sha.get("id")
            if (sha != expected_sha or current.get("status") != "live"
                    or current.get("trigger") not in ("manual", "rollback")):
                failed.add("UNAPPROVED_LIVE_OR_IN_PROGRESS")
        if any(not isinstance(d, dict) or d.get("status") in (
            "build_in_progress", "update_in_progress", "pre_deploy_in_progress",
            "pending", "queued",
        ) for d in deploys):
            failed.add("PENDING_DEPLOY_OR_UNKNOWN_ROW")

    if not isinstance(candidate, dict) or (
        candidate.get("head_sha") != expected_sha
        or candidate.get("base_sha") != BASE_SHA
        or candidate.get("draft") is not True
        or candidate.get("merged") is not False
    ):
        failed.add("PINNED_CANDIDATE_DRIFT")

    if not isinstance(checks, list) or len(checks) > 30:
        failed.add("REQUIRED_CI_MISSING")
    else:
        passed: set[str] = set()
        for item in checks:
            if not isinstance(item, dict):
                failed.add("CI_RESULT_INVALID")
                continue
            if (item.get("name") in REQUIRED_CHECKS
                    and item.get("status") == "completed"
                    and item.get("conclusion") == "success"):
                passed.add(item["name"])
        if passed != REQUIRED_CHECKS:
            failed.add("REQUIRED_CI_MISSING")

    # Rollback uses the old deploy's env, but DOES NOT restore saved service
    # env config. No supported connector read currently attests the stored flag.
    # Never assume rollback removed the stored opt-in flag.
    if snapshot.get("stored_github_only_flag") not in ("verified_false", "verified_true"):
        failed.add("SAVED_FLAG_NOT_INDEPENDENTLY_VERIFIED")
    # Even a human-reported flag value is not machine proof of active OAuth.
    # No "success" mode is permitted for changing a Render setting.

    if mode == "render-connector-env-write":
        failed.add("CONNECTOR_WRITE_PROHIBITED_AFTER_API_DEPLOY_INCIDENT")
    if mode == "exact-sha-deploy-review":
        failed.add("EXPLICIT_OWNER_COMMIT_DEPLOY_APPROVAL_REQUIRED")
    if mode == "dashboard-save-only":
        failed.add("DASHBOARD_SAVE_ONLY_AND_EXACT_SHA_RECHECK_REQUIRED")

    # No input fields are echoed. In particular, do not print env values,
    # URLs, identity subjects, bearer tokens or response bodies.
    return {
        "schema": "canovia.mcp.staging.release_drift_guard.v1",
        "snapshot_precheck_passed": not any(k for k in failed if k not in (
            "SAVED_FLAG_NOT_INDEPENDENTLY_VERIFIED",
            "CONNECTOR_WRITE_PROHIBITED_AFTER_API_DEPLOY_INCIDENT",
            "EXPLICIT_OWNER_COMMIT_DEPLOY_APPROVAL_REQUIRED",
            "DASHBOARD_SAVE_ONLY_AND_EXACT_SHA_RECHECK_REQUIRED",
        )),
        "decision": "BLOCK" if failed else "REVIEW_ONLY",
        "codes": sorted(failed),
        "write_or_deploy_authorized": False,
        "environment_change_executed": False,
        "production_authorized": False,
    }


def main() -> int:
    p = argparse.ArgumentParser(description="MCP stage read-only deployment-drift review; never a deploy")
    p.add_argument("--snapshot", type=Path, required=True)
    p.add_argument("--expected-sha", default=LIVE_APPROVED_SHA)
    p.add_argument("--mode", choices=MODES, default="audit")
    a = p.parse_args()
    try:
        result = assess(read_snapshot(a.snapshot), expected_sha=a.expected_sha, mode=a.mode)
    except SnapshotInvalid:
        result = {
            "schema": "canovia.mcp.staging.release_drift_guard.v1",
            "snapshot_precheck_passed": False,
            "decision": "BLOCK", "codes": ["UNTRUSTED_SNAPSHOT"],
            "write_or_deploy_authorized": False, "environment_change_executed": False,
            "production_authorized": False,
        }
    print(json.dumps(result, sort_keys=True))
    # Exit 0 means only metadata precheck, NOT mutation permission.
    return 0 if result["snapshot_precheck_passed"] and a.mode == "audit" else 1


if __name__ == "__main__":
    raise SystemExit(main())
